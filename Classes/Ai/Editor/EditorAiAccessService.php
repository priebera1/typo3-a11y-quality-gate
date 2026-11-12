<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Editor;

use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Ai\Service\AiConfigurationResolver;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetService;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Priebera\A11yQualityGate\Service\BackendUserService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Whether editor AI is usable for a user on a site, and why not. Layers (all must allow it): PRO/Agency licence,
 * the user's group (User TSconfig options.a11y_quality_gate.ai.editor, default on; writing aids ai.writing, default
 * off; image metadata ai.images, default on; bulk image suggestions ai.bulk, default off), and the site's readiness
 * (AiReadinessPolicy through AiConfigurationResolver::resolve(): provider, key, verified model, processing
 * acknowledgement, budget). The state is what the editor UI shows; the same checks run again server-side on every
 * request, and AiUsageRecorder enforces the budget once more under a lock right before the provider request.
 */
final class EditorAiAccessService
{
    /** An AI request whose usage row could not be written was refused before sending (AiUsageRecorder::CODE_USAGE_UNAVAILABLE). */
    public const STATE_USAGE_UNAVAILABLE = 'usage_unavailable';
    public const STATE_READY = 'ready';
    public const STATE_NO_LICENCE = 'no_licence';
    public const STATE_NO_PERMISSION = 'no_permission';
    public const STATE_NOT_CONFIGURED = 'not_configured';
    public const STATE_PAUSED = 'paused';
    /** The site's hard-stop monthly budget is used up (UX Atlas 02 E "Monthly budget reached"). */
    public const STATE_BUDGET_REACHED = 'budget_reached';

    public const PAUSED_NOT_VERIFIED = 'not_verified';
    public const PAUSED_ACKNOWLEDGEMENT = 'acknowledgement';
    public const PAUSED_BUDGET_UNENFORCEABLE = 'budget_unenforceable';

    /**
     * Resolver codes that pause AI for editors: model unavailable, revalidation needed, model not verified,
     * acknowledgement missing, budget not enforceable without a price. An administrator can resolve each one.
     */
    private const PAUSED_CODES = [
        1771002004,
        1771002005,
        AiConfigurationResolver::CODE_NOT_VERIFIED,
        AiConfigurationResolver::CODE_ACKNOWLEDGEMENT_MISSING,
        AiBudgetService::CODE_BUDGET_UNENFORCEABLE,
    ];

    public const FLAG_EDITOR = 'editor';
    public const FLAG_WRITING = 'writing';
    public const FLAG_IMAGES = 'images';
    public const FLAG_BULK = 'bulk';

    /** @var array<string,int> resolve() result per site within this request */
    private array $resolveCodes = [];

    private const FLAG_DEFAULTS = [self::FLAG_EDITOR => true, self::FLAG_WRITING => false, self::FLAG_IMAGES => true, self::FLAG_BULK => false];

    public function __construct(
        private readonly AiFeatureAccessServiceInterface $featureAccessService,
        private readonly AiConfigurationResolverInterface $configurationResolver,
        private readonly AiSiteSettingsService $siteSettingsService,
        private readonly AiConfigurationRepositoryInterface $configurationRepository,
        private readonly BackendUserService $backendUserService,
    ) {}

    public function userAllows(string $flag): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        $value = $backendUser->getTSConfig()['options.']['a11y_quality_gate.']['ai.'][$flag] ?? null;
        if ($value === null || $value === '') {
            return self::FLAG_DEFAULTS[$flag] ?? false;
        }

        return (bool)(int)$value;
    }

    private function hasModuleAccess(): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();

        return $backendUser instanceof BackendUserAuthentication
            && ($backendUser->isAdmin() || $backendUser->check('modules', 'web_a11y'));
    }

    public function isAdmin(): bool
    {
        return $this->backendUserService->getBackendUser()?->isAdmin() === true;
    }

    public function state(string $siteIdentifier): string
    {
        if ($siteIdentifier === '' || !$this->featureAccessService->isAvailable($siteIdentifier)) {
            return self::STATE_NO_LICENCE;
        }
        // The editor AI routes inherit the access of the AQG module, like the live check.
        if (!$this->userAllows(self::FLAG_EDITOR) || !$this->hasModuleAccess()) {
            return self::STATE_NO_PERMISSION;
        }

        return $this->siteState($siteIdentifier);
    }

    /**
     * The readiness of the site itself (AiReadinessPolicy: licence, provider, key, verified model, acknowledgement,
     * budget), without the user's group switches. Views that offer AI per finding (Page Detail) use it, so they show
     * AI exactly when the server would accept the request.
     */
    public function siteState(string $siteIdentifier): string
    {
        if ($siteIdentifier === '' || !$this->featureAccessService->isAvailable($siteIdentifier)) {
            return self::STATE_NO_LICENCE;
        }

        $code = $this->resolveCode($siteIdentifier);

        return $code === 0 ? self::STATE_READY : self::stateForConfigurationCode($code);
    }

    /** 0 when the site resolves, otherwise the AiConfigurationException code (-1 for any other failure). */
    private function resolveCode(string $siteIdentifier): int
    {
        if (!array_key_exists($siteIdentifier, $this->resolveCodes)) {
            try {
                $this->configurationResolver->resolve($siteIdentifier);
                $this->resolveCodes[$siteIdentifier] = 0;
            } catch (AiConfigurationException $exception) {
                $this->resolveCodes[$siteIdentifier] = (int)$exception->getCode();
            } catch (\Throwable) {
                $this->resolveCodes[$siteIdentifier] = -1;
            }
        }

        return $this->resolveCodes[$siteIdentifier];
    }

    /**
     * Why AI is paused, for the editor's quiet line: not_verified (model to be tested again), acknowledgement (privacy
     * confirmation missing), budget_unenforceable (hard-stop budget without a price), or ''.
     */
    public static function pausedReasonForCode(int $code): string
    {
        return match ($code) {
            1771002004, 1771002005, AiConfigurationResolver::CODE_NOT_VERIFIED => self::PAUSED_NOT_VERIFIED,
            AiConfigurationResolver::CODE_ACKNOWLEDGEMENT_MISSING => self::PAUSED_ACKNOWLEDGEMENT,
            AiBudgetService::CODE_BUDGET_UNENFORCEABLE => self::PAUSED_BUDGET_UNENFORCEABLE,
            default => '',
        };
    }

    /**
     * The editor state for an AiConfigurationException code, also for a refusal raised during the request (a budget
     * check under the lock in AiUsageRecorder): budget_reached, paused or not_configured. Never "ready".
     */
    public static function stateForConfigurationCode(int $code): string
    {
        if ($code === AiUsageRecorder::CODE_USAGE_UNAVAILABLE) {
            // The request could not be recorded before it was sent (PI-03): nothing was sent; not a readiness problem.
            return self::STATE_USAGE_UNAVAILABLE;
        }
        if ($code === AiBudgetService::CODE_BUDGET_REACHED) {
            return self::STATE_BUDGET_REACHED;
        }

        return in_array($code, self::PAUSED_CODES, true) ? self::STATE_PAUSED : self::STATE_NOT_CONFIGURED;
    }

    /**
     * The editor-side configuration: state, provider and model names, privacy switches. No credential or cost data.
     *
     * @return array<string,mixed>
     */
    public function editorConfiguration(string $siteIdentifier, bool $isTranslation = false): array
    {
        $state = $this->state($siteIdentifier);
        $configuration = [
            'state' => $state,
            'pausedReason' => $state === self::STATE_PAUSED ? self::pausedReasonForCode($this->resolveCode($siteIdentifier)) : '',
            'isAdmin' => $this->isAdmin(),
            'provider' => '',
            'providerLabel' => '',
            'model' => '',
            'modelLabel' => '',
            'allowImages' => false,
            'allowPageTitle' => false,
            'linkText' => false,
            'writing' => false,
            'translation' => false,
        ];
        if ($state !== self::STATE_READY) {
            return $configuration;
        }

        try {
            $status = $this->configurationResolver->status($siteIdentifier);
        } catch (\Throwable) {
            $status = [];
        }
        $settings = $this->siteSettingsService->get($siteIdentifier);
        $provider = (string)($status['provider'] ?? $settings->provider);
        $model = (string)($status['selectedModelId'] ?? '');
        $writing = $this->userAllows(self::FLAG_WRITING);

        return [
            'provider' => $provider,
            'providerLabel' => AiProviderCatalog::label($provider),
            'model' => $model,
            'modelLabel' => (string)($status['selectedModelLabel'] ?? '') !== '' ? (string)$status['selectedModelLabel'] : $model,
            'allowImages' => $settings->allowImageInput,
            'allowPageTitle' => $settings->allowPageTitle,
            'linkText' => $this->configurationRepository->isLinkTextSuggestionsEnabled($siteIdentifier),
            'writing' => $writing,
            'translation' => $writing && $isTranslation,
        ] + $configuration;
    }

    /** Throws the safe editor error for anything but "ready". */
    public function assertReady(string $siteIdentifier, string $flag = self::FLAG_EDITOR): void
    {
        $state = $this->state($siteIdentifier);
        if ($state !== self::STATE_READY) {
            throw new EditorAiException($state, 'Editor AI is not available: ' . $state, $state === self::STATE_NO_PERMISSION || $state === self::STATE_NO_LICENCE ? 403 : 422);
        }
        if ($flag !== self::FLAG_EDITOR && !$this->userAllows($flag)) {
            throw new EditorAiException(self::STATE_NO_PERMISSION, 'This AI action is not enabled for your user group.', 403);
        }
    }
}
