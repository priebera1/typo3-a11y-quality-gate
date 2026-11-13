<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Mcp\McpHostKeyUnavailable;
use Priebera\A11yQualityGate\Mcp\Http\McpGatewayClient;
use Priebera\A11yQualityGate\Mcp\McpAuditRepository;
use Priebera\A11yQualityGate\Mcp\McpConnectionStore;
use Priebera\A11yQualityGate\Mcp\McpPairingService;
use Priebera\A11yQualityGate\Mcp\McpScopes;
use Priebera\A11yQualityGate\Mcp\McpSettingsViewBuilder;
use Priebera\A11yQualityGate\Mcp\McpSiteSettingsRepository;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Settings › Agent access (MCP), administrator actions (atlas 13 · A, B, D, G). Every action re-checks the
 * administrator (canManageAdminOnlySettings) and, where it reaches the Gateway, the PRO/Agency licence gate.
 */
#[AsController]
final class McpSettingsController extends AbstractBackendModuleController
{
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        IconFactory $iconFactory,
        BackendContextService $backendContextService,
        SiteResolutionService $siteResolutionService,
        RequestParameterService $requestParameterService,
        private readonly AccessControlService $accessControlService,
        private readonly McpConnectionStore $connectionStore,
        private readonly McpPairingService $pairingService,
        private readonly McpGatewayClient $gatewayClient,
        private readonly McpSiteSettingsRepository $siteSettingsRepository,
        private readonly McpAuditRepository $auditRepository,
        private readonly ProStatusResolverService $proStatusResolverService,
        private readonly ExtensionContextService $extensionContextService,
        private readonly SiteFinder $siteFinder,
        private readonly McpSettingsViewBuilder $viewBuilder,
    ) {
        parent::__construct($moduleTemplateFactory, $uriBuilder, $iconFactory, $backendContextService, $siteResolutionService, $requestParameterService);
    }

    public function connectAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyNonAdmin($request);
        if ($denied !== null) {
            return $denied;
        }
        $site = $this->resolveLicenceSite($request);
        if (!$site instanceof Site || !self::isEligible($this->proStatusResolverService->resolveForSite($site))) {
            return $this->back($request, $this->translate('settings.mcp.flash.notEligible'), ContextualFeedbackSeverity::ERROR);
        }
        try {
            // A live connection is never replaced by starting another pairing: its secret would be lost while the Gateway
            // keeps it active (it ends only when a new pairing is activated). Disconnect first.
            if (!$this->pairingService->start($site, $this->actorName())) {
                return $this->back($request, $this->translate('settings.mcp.flash.alreadyConnected'), ContextualFeedbackSeverity::WARNING);
            }
        } catch (ApiRequestFailedException $exception) {
            return $this->back($request, $this->gatewayFailureMessage($exception), ContextualFeedbackSeverity::ERROR);
        } catch (McpHostKeyUnavailable) {
            return $this->back($request, $this->translate('settings.mcp.flash.hostKeyUnavailable'), ContextualFeedbackSeverity::ERROR);
        }
        $this->auditRepository->record('pairing_started', 'admin_change', 'backend', $this->actorContext());

        return $this->back($request);
    }

    /** Polled by the tab while a pairing code is shown. */
    public function pairingStatusAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->accessControlService->canManageAdminOnlySettings($this->backendContextService->getBackendUser())) {
            return new JsonResponse(['success' => false, 'code' => 'admin_only_settings_required'], 403);
        }
        $wasPairing = $this->connectionStore->get()['status'] === 'pairing';
        try {
            $status = $this->pairingService->poll();
        } catch (ApiRequestFailedException $exception) {
            return new JsonResponse(['success' => false, 'code' => $exception->apiErrorCode ?: 'gateway_unreachable'], 502);
        }
        // Only a poll that found the pairing open records it, no longer every poll answered "connected" from the stored
        // state. Two tabs polling the same moment can still both record it (rare; the entry is informational).
        if ($status === 'connected' && $wasPairing) {
            $this->auditRepository->record('installation_paired', 'admin_change', 'backend', $this->actorContext());
        }

        return new JsonResponse(['success' => true, 'status' => $status]);
    }

    /**
     * Polled by the tab while a fresh connection waits for its connector ("starting"): answers the state the tab would
     * render, so it reloads exactly once, into Connected (or into the state that replaced "starting"), without the
     * administrator reloading by hand. A pending activation is completed here too, as the pairing poll would.
     * Administrators only, like the tab; no token, secret or Gateway detail is returned.
     */
    public function connectionStateAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->accessControlService->canManageAdminOnlySettings($this->backendContextService->getBackendUser())) {
            return new JsonResponse(['success' => false, 'code' => 'admin_only_settings_required'], 403);
        }
        if ($this->connectionStore->get()['status'] === 'connected') {
            $this->pairingService->ensureActivated();
        }
        // Only the connection's progress is asked here; the licence gate is the page's own (it re-checks it on the reload
        // this answer triggers). Deciding it again here from another site resolution could disagree with the page and
        // make it reload over and over.
        return new JsonResponse([
            'success' => true,
            'state' => $this->viewBuilder->resolveState(true)['state'],
        ]);
    }

    public function cancelPairingAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyNonAdmin($request);
        if ($denied !== null) {
            return $denied;
        }
        if ($this->pairingService->cancel() === 'unconfirmed') {
            return $this->back($request, $this->translate('settings.mcp.flash.cancelUnconfirmed'), ContextualFeedbackSeverity::WARNING);
        }

        return $this->back($request);
    }

    public function saveSitesAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyNonAdmin($request);
        if ($denied !== null) {
            return $denied;
        }
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $enabled = is_array($body['enabled'] ?? null) ? $body['enabled'] : [];
        $capabilities = is_array($body['capabilities'] ?? null) ? $body['capabilities'] : [];
        $actor = $this->backendContextService->getBackendUserUid();
        foreach ($this->siteFinder->getAllSites() as $site) {
            $identifier = $site->getIdentifier();
            $before = $this->siteSettingsRepository->find($identifier);
            $siteCapabilities = is_array($capabilities[$identifier] ?? null)
                ? McpScopes::normalize(array_map('strval', array_values($capabilities[$identifier])))
                : [];
            $isEnabled = (string)($enabled[$identifier] ?? '') === '1';
            if ($before['enabled'] === $isEnabled && $before['capabilities'] === $siteCapabilities) {
                continue;
            }
            $this->siteSettingsRepository->save($identifier, $isEnabled, $siteCapabilities, $actor);
            $this->auditRepository->record('site_capabilities_changed', 'admin_change', 'backend', $this->actorContext() + [
                'siteIdentifier' => $identifier,
                'target' => ($isEnabled ? 'on' : 'off') . ($siteCapabilities !== [] ? ': ' . implode(' ', $siteCapabilities) : ''),
            ]);
        }

        return $this->back($request, $this->translate('settings.mcp.flash.sitesSaved'));
    }

    public function revokeAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyNonAdmin($request);
        if ($denied !== null) {
            return $denied;
        }
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $tokenId = (string)($body['tokenId'] ?? '');
        if (preg_match('/^[0-9a-f-]{36}$/i', $tokenId) !== 1) {
            return $this->back($request, $this->translate('settings.mcp.flash.revokeFailed'), ContextualFeedbackSeverity::ERROR);
        }
        try {
            $this->gatewayClient->revokeToken($tokenId, 'admin', $this->actorName());
        } catch (ApiRequestFailedException $exception) {
            return $this->back($request, $exception->httpStatus === 404
                ? $this->translate('settings.mcp.flash.revokeFailed')
                : $this->gatewayFailureMessage($exception), ContextualFeedbackSeverity::ERROR);
        }
        $this->auditRepository->record('token_revoked', 'admin_change', 'backend', $this->actorContext() + ['target' => 'token ' . substr($tokenId, 0, 8)]);

        return $this->back($request, $this->translate('settings.mcp.flash.revoked'));
    }

    /** Emergency disconnect (atlas 13 · G): typed confirmation, tokens revoked at the Gateway first, then unpaired. */
    public function disconnectAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyNonAdmin($request);
        if ($denied !== null) {
            return $denied;
        }
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        if (trim((string)($body['confirmWord'] ?? '')) !== $this->translate('settings.mcp.disconnect.word')) {
            return $this->back($request, $this->translate('settings.mcp.flash.disconnectConfirm'), ContextualFeedbackSeverity::WARNING);
        }
        // The credential this request disconnects (A5): only that stored connection is ended locally afterwards.
        $credential = $this->connectionStore->currentCredential();
        if ($credential === null) {
            return $this->back($request);
        }
        $revoked = 0;
        try {
            $revoked = (int)($this->gatewayClient->disconnect($this->actorName(), $credential['secret'])['revokedTokens'] ?? 0);
        } catch (ApiRequestFailedException $exception) {
            // Only the Gateway's own "unknown or disconnected installation" means it is already unpaired. Any other
            // answer (a proxy's 403, a rate limit, an outage) keeps the secret, so the disconnect can be repeated.
            if ($exception->httpStatus !== 401 || $exception->apiErrorCode !== 'connector_unauthorized') {
                return $this->back($request, $this->gatewayFailureMessage($exception), ContextualFeedbackSeverity::ERROR);
            }
        }
        $this->connectionStore->markDisconnectedIfCurrent($credential['credentialId'], $this->actorName());
        $this->auditRepository->record('installation_disconnected', 'admin_change', 'backend', $this->actorContext() + ['target' => $revoked . ' tokens']);

        return $this->back($request, sprintf($this->translate('settings.mcp.flash.disconnected'), $revoked));
    }

    /** PRO and Agency, not Trial and not Free (atlas 13 · A licence gate). */
    public static function isEligible(object $proStatus): bool
    {
        $plan = strtolower((string)($proStatus->plan ?? ''));

        return (bool)($proStatus->valid ?? false)
            && !(bool)($proStatus->isTrial ?? false)
            && in_array($plan, ['pro', 'agency', 'enterprise'], true);
    }

    private function denyNonAdmin(ServerRequestInterface $request): ?ResponseInterface
    {
        if ($this->accessControlService->canManageAdminOnlySettings($this->backendContextService->getBackendUser())) {
            return null;
        }

        return $this->back($request, $this->translateWithFallback(
            'settings.adminOnly.save',
            'Only administrators can change this configuration. Your changes were not saved.'
        ), ContextualFeedbackSeverity::ERROR);
    }

    private function back(ServerRequestInterface $request, string $message = '', ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::OK): ResponseInterface
    {
        if ($message !== '') {
            $this->addFlashMessage($message, $severity);
        }
        $parameters = $this->getA11yModuleReturnParameters($request);
        $parameters['tab'] = 'mcp';

        return new RedirectResponse($this->buildRouteUrl('web_a11y.settings', $parameters), 303);
    }

    /**
     * The site whose licence the tab showed as eligible (SettingsController: the selected site, else the request's
     * site), so Connect proves the same licence. Only without either the first configured site with a host.
     */
    private function resolveLicenceSite(ServerRequestInterface $request): ?Site
    {
        $parsedBody = $request->getParsedBody();
        $selected = trim((string)($request->getQueryParams()['rulesetSite'] ?? (is_array($parsedBody) ? ($parsedBody['rulesetSite'] ?? '') : '')));
        $site = $selected !== '' ? $this->siteResolutionService->resolveSiteByIdentifier($selected) : null;
        $site ??= $this->resolveSiteFromRequest($request);
        if ($site instanceof Site && $this->extensionContextService->getNormalizedDomainFromSiteBase((string)$site->getBase()) !== '') {
            return $site;
        }

        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($this->extensionContextService->getNormalizedDomainFromSiteBase((string)$site->getBase()) !== '') {
                return $site;
            }
        }

        return null;
    }

    private function gatewayFailureMessage(ApiRequestFailedException $exception): string
    {
        return match ($exception->apiErrorCode) {
            'plan_not_eligible' => $this->translate('settings.mcp.flash.notEligible'),
            'licence_invalid' => $this->translate('settings.mcp.flash.licenceInvalid'),
            'dpa_acceptance_required' => $this->translate('settings.mcp.flash.dpaRequired'),
            default => $this->translate('settings.mcp.flash.gatewayUnreachable'),
        };
    }

    private function actorName(): string
    {
        $user = $this->backendContextService->getBackendUser();

        return trim((string)($user?->user['realName'] ?? '')) ?: (string)($user?->user['username'] ?? '');
    }

    /**
     * @return array{beUserUid: int, beUserName: string}
     */
    private function actorContext(): array
    {
        return ['beUserUid' => $this->backendContextService->getBackendUserUid(), 'beUserName' => $this->actorName()];
    }
}
