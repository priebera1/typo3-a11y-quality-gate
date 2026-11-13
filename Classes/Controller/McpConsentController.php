<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Mcp\Http\McpGatewayClient;
use Priebera\A11yQualityGate\Mcp\McpAuditRepository;
use Priebera\A11yQualityGate\Mcp\McpConsentLedger;
use Priebera\A11yQualityGate\Mcp\McpConsentRefusal;
use Priebera\A11yQualityGate\Mcp\McpScopes;
use Priebera\A11yQualityGate\Mcp\McpSettingsViewBuilder;
use Priebera\A11yQualityGate\Mcp\McpSiteSettingsRepository;
use Priebera\A11yQualityGate\Mcp\McpUserContext;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendJavaScriptModuleService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * OAuth consent for agents (atlas 13 · C) and "My connected agents" (atlas 13 · H).
 *
 * The consent page is an AQG module route behind TYPO3's own login (MFA and password policies unchanged). It shows who
 * asks (the client name is self-declared and shown as such), as whom, for which sites, for how long, and what the
 * agent cannot do. The grant is computed here, never taken from the form as is: requested ∩ grantable ∩ what this
 * user may hold (Scan now for scans), and only sites with agent access on that the user can see. The decision goes to
 * the Gateway over the connector credential; the browser then returns to the agent with a one-time code.
 */
#[AsController]
final class McpConsentController extends AbstractBackendModuleController
{
    private const DURATIONS = [7, 30, 90];
    /** Preselected and fallback lifetime (AQG 3.0.0 public hardening, Fable P1-5): longer access is an explicit choice. */
    private const DEFAULT_DURATION = 7;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        IconFactory $iconFactory,
        BackendContextService $backendContextService,
        SiteResolutionService $siteResolutionService,
        RequestParameterService $requestParameterService,
        private readonly AccessControlService $accessControlService,
        private readonly ScopeAccessService $scopeAccessService,
        private readonly McpGatewayClient $gatewayClient,
        private readonly McpSiteSettingsRepository $siteSettingsRepository,
        private readonly McpAuditRepository $auditRepository,
        private readonly McpConsentLedger $consentLedger,
        private readonly McpSettingsViewBuilder $settingsViewBuilder,
        private readonly SiteFinder $siteFinder,
        private readonly BackendJavaScriptModuleService $backendJavaScriptModuleService,
        private readonly PageRenderer $pageRenderer,
        private readonly ?McpConsentRefusal $consentRefusal = null,
    ) {
        parent::__construct($moduleTemplateFactory, $uriBuilder, $iconFactory, $backendContextService, $siteResolutionService, $requestParameterService);
    }

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        $requestRef = (string)($request->getQueryParams()['request'] ?? '');
        $view = ['state' => 'consent', 'requestRef' => $requestRef];
        $user = $this->backendContextService->getBackendUser();

        if (!self::isRequestRef($requestRef)) {
            $view['state'] = 'expired';
        } elseif (!$user instanceof BackendUserAuthentication || !McpUserContext::mayConnectAgents($user)) {
            $view['state'] = 'notAllowed';
            // U-07: "Tell the agent" answers the request like Deny (POST, route token), so the agent stops waiting.
            if ($user instanceof BackendUserAuthentication && $this->gatewayClient->isConnected()) {
                $view['decideUrl'] = $this->buildRouteUrl('web_a11y.mcpConsentDecide');
            }
        } elseif (!$this->gatewayClient->isConnected()) {
            $view['state'] = 'notAvailable';
        } else {
            try {
                $described = $this->gatewayClient->describeAuthorizationRequest($requestRef);
            } catch (ApiRequestFailedException) {
                $described = ['status' => 'expired'];
            }
            if (($described['status'] ?? '') !== 'pending') {
                $view['state'] = 'expired';
            } else {
                $view += $this->buildConsent($user, $described);
            }
        }

        return $this->render($request, 'Mcp/Consent', $view);
    }

    public function decideAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $requestRef = (string)($body['request'] ?? '');
        $user = $this->backendContextService->getBackendUser();
        if (!self::isRequestRef($requestRef) || !$user instanceof BackendUserAuthentication) {
            return $this->render($request, 'Mcp/Consent', ['state' => 'notAllowed']);
        }
        if (!McpUserContext::mayConnectAgents($user)) {
            // U-07: refused like Deny, whatever the form sent; the browser returns to the agent with the OAuth error.
            $redirect = $this->consentRefusal?->refuse($requestRef, $this->auditContext($user, ''), 'not_allowed') ?? '';
            if (!in_array(strtolower((string)parse_url($redirect, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                return $this->render($request, 'Mcp/Consent', ['state' => 'notAllowed']);
            }
            $this->backendJavaScriptModuleService->loadVersionedModule($this->pageRenderer, '@priebera/a11y-quality-gate/backend/mcp-consent.js');

            return $this->render($request, 'Mcp/Consent', [
                'state' => 'notAllowed',
                'continueUrl' => $redirect,
                'continueHost' => (string)parse_url($redirect, PHP_URL_HOST),
            ]);
        }

        try {
            $described = $this->gatewayClient->describeAuthorizationRequest($requestRef);
            if (($described['status'] ?? '') !== 'pending') {
                return $this->render($request, 'Mcp/Consent', ['state' => 'expired']);
            }
            $context = $this->auditContext($user, (string)($described['clientName'] ?? ''));

            if (($body['decision'] ?? '') !== 'allow') {
                $outcome = $this->gatewayClient->decideAuthorizationRequest(['request' => $requestRef, 'decision' => 'deny']);
                    $this->auditRepository->record('consent', 'denied', 'backend', $context + ['reason' => 'user_denied']);
                    return $this->redirectToAgent((string)($outcome['redirect'] ?? ''), $request, 'denied', (string)($described['redirectHost'] ?? ''));
                }

                $consent = $this->buildConsent($user, $described);
                $submittedScopes = is_array($body['scopes'] ?? null) ? array_map('strval', array_values($body['scopes'])) : [];
                $submittedSites = is_array($body['sites'] ?? null) ? array_map('strval', array_values($body['sites'])) : [];
                // Narrowing only: what the page offered as grantable, intersected with what was ticked.
                $scopes = array_values(array_intersect($consent['grantableScopes'], $submittedScopes));
                $sites = array_values(array_intersect(array_column($consent['sites'], 'identifier'), $submittedSites));
                // A missing or unknown duration is the shortest, 7 days (public hardening: the preselected lifetime too).
                $duration = (int)($body['duration'] ?? self::DEFAULT_DURATION);
                $duration = in_array($duration, self::DURATIONS, true) ? $duration : self::DEFAULT_DURATION;
                if ($scopes === [] || $sites === []) {
                    $this->addFlashMessage($this->translate('mcp.consent.nothingSelected'), ContextualFeedbackSeverity::WARNING);
                    return $this->render($request, 'Mcp/Consent', ['state' => 'consent', 'requestRef' => $requestRef] + $consent);
                }

                // Recorded before the Gateway mints the token (clone safety N2): the connector runs the token's calls only
                // for a consent this installation's database holds for this user.
                if (!$this->consentLedger->record($requestRef, (int)$user->user['uid'], $scopes, $sites)) {
                    // Another submission of this request decides it (or already did); this one changes nothing.
                    return $this->render($request, 'Mcp/Consent', ['state' => 'alreadyAnswered']);
                }
                try {
                    $outcome = $this->gatewayClient->decideAuthorizationRequest([
                    'request' => $requestRef,
                    'decision' => 'allow',
                    'beUserUid' => (int)$user->user['uid'],
                    'beUserName' => $this->displayName($user),
                    'scopes' => $scopes,
                    'siteIdentifiers' => $sites,
                    'durationDays' => $duration,
                    // The connection this page showed as "already connected" (computed here, never taken from the form).
                    'replaceTokenId' => is_array($consent['existing']) && ($consent['existing']['id'] ?? '') !== '' ? $consent['existing']['id'] : null,
                ]);
            } catch (ApiRequestFailedException $exception) {
                // Not confirmed: no token may ever run on this record. A token minted anyway (an answer lost after the
                // Gateway decided) is refused as consent_unknown and revoked; the user connects again.
                $this->consentLedger->withdraw($requestRef, (int)$user->user['uid']);
                throw $exception;
            }
            $this->auditRepository->record('consent', 'token_issued', 'backend', $context + [
                'siteIdentifier' => implode(',', $sites),
                'target' => implode(' ', $scopes) . ' · ' . $duration . ' days',
            ]);

            return $this->redirectToAgent((string)($outcome['redirect'] ?? ''), $request, 'allowed', (string)($described['redirectHost'] ?? ''));
        } catch (ApiRequestFailedException $exception) {
            return $this->render($request, 'Mcp/Consent', ['state' => $exception->httpStatus === 410 ? 'expired' : 'gatewayError']);
        }
    }

    /** "My connected agents": the signed-in user's own tokens, with revoke. */
    public function myAgentsAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->backendContextService->getBackendUser();
        $view = ['state' => 'list', 'tokens' => []];
        if (!$user instanceof BackendUserAuthentication || !McpUserContext::mayConnectAgents($user)) {
            $view['state'] = 'notAllowed';
        } elseif (!$this->gatewayClient->isConnected()) {
            $view['state'] = 'notAvailable';
        } else {
            try {
                $view['tokens'] = $this->settingsViewBuilder->describeTokens($this->gatewayClient->listTokens((int)$user->user['uid']));
            } catch (ApiRequestFailedException) {
                $view['state'] = 'gatewayError';
            }
        }
        $view['revokeUrl'] = $this->buildRouteUrl('web_a11y.mcpMyAgentsRevoke');

        return $this->render($request, 'Mcp/MyAgents', $view);
    }

    public function revokeMineAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->backendContextService->getBackendUser();
        $tokenId = (string)((is_array($request->getParsedBody()) ? $request->getParsedBody() : [])['tokenId'] ?? '');
        if ($user instanceof BackendUserAuthentication && preg_match('/^[0-9a-f-]{36}$/i', $tokenId) === 1) {
            try {
                // reason "user": the Gateway only revokes a token of this be_users uid.
                $this->gatewayClient->revokeToken($tokenId, 'user', $this->displayName($user), (int)$user->user['uid']);
                // The user revoked their own agent: not an administrator's change (final customer acceptance, MCP polish).
                $this->auditRepository->record('token_revoked', 'user_change', 'backend', $this->auditContext($user, '') + ['target' => 'token ' . substr($tokenId, 0, 8)]);
                $this->addFlashMessage($this->translate('settings.mcp.flash.revoked'));
            } catch (ApiRequestFailedException) {
                $this->addFlashMessage($this->translate('settings.mcp.flash.revokeFailed'), ContextualFeedbackSeverity::ERROR);
            }
        }

        return new RedirectResponse($this->buildRouteUrl('web_a11y.mcpMyAgents'), 303);
    }

    /**
     * @param array<string, mixed> $described
     * @return array<string, mixed>
     */
    private function buildConsent(BackendUserAuthentication $user, array $described): array
    {
        $sites = [];
        $siteCapabilities = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $capabilities = $this->siteSettingsRepository->effectiveCapabilities($site->getIdentifier());
            if ($capabilities === [] || !McpScopes::isGatewaySiteIdentifier($site->getIdentifier())) {
                continue;
            }
            if (!$this->userCanSeeSite($user, $site)) {
                // Neither named nor counted (Sol R08): the page must not reveal how many sites this user cannot see.
                continue;
            }
            $siteCapabilities = array_merge($siteCapabilities, $capabilities);
            $sites[] = ['identifier' => $site->getIdentifier(), 'base' => (string)$site->getBase(), 'preselected' => false];
        }

        $requested = McpScopes::normalize(array_map('strval', is_array($described['requestedScopes'] ?? null) ? $described['requestedScopes'] : []));
        $scopes = [];
        $grantable = [];
        foreach ($requested as $scope) {
            $reason = '';
            if (!in_array($scope, $siteCapabilities, true)) {
                $reason = 'siteOff';
            } elseif ($scope === McpScopes::SCANS_RUN_PAGE && !$this->accessControlService->canShowScanNow($user)) {
                $reason = 'noScanPermission';
            }
            if ($reason === '') {
                $grantable[] = $scope;
            }
            $scopes[] = [
                'scope' => $scope,
                'title' => McpScopes::title($scope),
                'description' => McpScopes::description($scope),
                'risk' => McpScopes::riskOf($scope),
                'grantable' => $reason === '',
                // Only reading is ticked in advance: scans and content:read need a deliberate tick (P1-5).
                'preselected' => $reason === '' && !McpScopes::requiresExplicitConsent($scope),
                'explicit' => McpScopes::requiresExplicitConsent($scope),
                'explicitScan' => $scope === McpScopes::SCANS_RUN_PAGE,
                'reason' => $reason,
                'fieldId' => 'aqg-mcp-consent-' . McpScopes::labelKey($scope),
            ];
        }

        $existing = [];
        try {
            foreach ($this->gatewayClient->listTokens((int)$user->user['uid']) as $token) {
                // The same client registration only: names are self-declared, so a look-alike client must never be
                // presented as (and allowed to replace) the user's existing connection.
                if (in_array($token['status'] ?? '', ['active', 'expiring_soon'], true)
                    && ($token['clientId'] ?? '') !== '' && ($token['clientId'] ?? '') === ($described['clientId'] ?? '')) {
                    $existing = $this->settingsViewBuilder->describeTokens([$token])[0];
                    break;
                }
            }
        } catch (ApiRequestFailedException) {
        }

        // Sites are approved explicitly (P1-5): only a single listed site is ticked in advance — there is nothing to
        // choose — never every site of a multi-site installation.
        if (count($sites) === 1) {
            $sites[0]['preselected'] = true;
        }

        return [
            'clientName' => (string)($described['clientName'] ?? ''),
            'redirectHost' => (string)($described['redirectHost'] ?? ''),
            'redirectsToLoopback' => (bool)($described['redirectsToLoopback'] ?? false),
            'userName' => $this->displayName($user),
            'scopes' => $scopes,
            'grantableScopes' => $grantable,
            'sites' => $sites,
            'existing' => $existing,
            'decideUrl' => $this->buildRouteUrl('web_a11y.mcpConsentDecide'),
            'myAgentsUrl' => $this->buildRouteUrl('web_a11y.mcpMyAgents'),
        ];
    }

    private function userCanSeeSite(BackendUserAuthentication $user, Site $site): bool
    {
        if ($this->scopeAccessService->canReadSite($site)) {
            return true;
        }
        foreach ($user->getWebmounts() as $mountUid) {
            // Same rule as the tool calls (McpToolExecutor::userCanSeeSite), so consent offers no site every call refuses.
            if ((int)$mountUid > 0 && $this->scopeAccessService->isPageInSite((int)$mountUid, $site) && $this->scopeAccessService->canReadPage((int)$mountUid)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Gateway hands back the agent's registered redirect URI with the code (or the error). The consent runs inside
     * the backend frame (TYPO3 re-routes top-level module requests into it), so the result page moves the whole window
     * to the agent (backend/mcp-consent.js) and offers the same link to click. Only http(s) targets.
     *
     * Since the consent browser binding (AQG 3.0.0, Fable G-3) the link goes to the Gateway's /mcp/oauth/continue, which
     * forwards to the agent only in the browser that started the connection; the button still names where the user ends
     * up — the agent's redirect host the Gateway described — not the Gateway in between.
     */
    private function redirectToAgent(string $url, ServerRequestInterface $request, string $state, string $agentHost = ''): ResponseInterface
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $this->render($request, 'Mcp/Consent', ['state' => 'gatewayError']);
        }
        $this->backendJavaScriptModuleService->loadVersionedModule($this->pageRenderer, '@priebera/a11y-quality-gate/backend/mcp-consent.js');

        return $this->render($request, 'Mcp/Consent', [
            'state' => $state === 'allowed' ? 'allowed' : 'denied',
            'continueUrl' => $url,
            'continueHost' => $agentHost !== '' ? $agentHost : (string)parse_url($url, PHP_URL_HOST),
        ]);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(ServerRequestInterface $request, string $template, array $variables): ResponseInterface
    {
        $moduleTemplate = $this->createModuleTemplate($request);
        $moduleTemplate->setTitle($this->translate('mcp.consent.pageTitle'));
        $moduleTemplate->assignMultiple($variables + ['durations' => self::DURATIONS]);

        return $moduleTemplate->renderResponse($template);
    }

    private static function isRequestRef(string $value): bool
    {
        return preg_match('/^aqgr_[A-Za-z0-9_-]{43}$/', $value) === 1;
    }

    private function displayName(BackendUserAuthentication $user): string
    {
        return trim((string)($user->user['realName'] ?? '')) ?: (string)($user->user['username'] ?? '');
    }

    /**
     * @return array{beUserUid: int, beUserName: string, clientName: string}
     */
    private function auditContext(BackendUserAuthentication $user, string $clientName): array
    {
        return ['beUserUid' => (int)$user->user['uid'], 'beUserName' => $this->displayName($user), 'clientName' => $clientName];
    }
}
