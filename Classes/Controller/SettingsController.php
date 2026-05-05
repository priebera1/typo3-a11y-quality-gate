<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Domain\Repository\FieldConfigRepository;
use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResult;
use Priebera\A11yQualityGate\Pro\Service\ProLicenceService;
use Priebera\A11yQualityGate\Pro\Service\ProSiteFingerprintService;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendJavaScriptModuleService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Service\TcaFieldDiscoveryService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

#[AsController]
final class SettingsController extends AbstractBackendModuleController
{
    /**
     * @var list<string>
     */
    private const AVAILABLE_TABS = [
        'licence',
        'fields',
        'gate',
        'rules',
    ];

    /**
     * @var list<string>
     */
    private const NON_DESCRIPTIVE_DEFAULTS = [
        'click here',
        'here',
        'read more',
        'more',
        'learn more',
        'continue',
        'continue reading',
        'details',
        'link',
        'this link',
        'this page',
        'download',
        'more info',
        'more information',
        'see more',
        'view more',
    ];

    /**
     * @var list<string>
     */
    private const WINDOW_HINT_DEFAULTS = [
        'opens in new window',
    ];

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        IconFactory $iconFactory,
        BackendContextService $backendContextService,
        SiteResolutionService $siteResolutionService,
        RequestParameterService $requestParameterService,
        private readonly FieldConfigRepository $fieldConfigRepository,
        private readonly TcaFieldDiscoveryService $tcaFieldDiscoveryService,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendJavaScriptModuleService $backendJavaScriptModuleService,
        private readonly AccessControlService $accessControlService,
        private readonly RulesetRepository $rulesetRepository,
        private readonly SiteFinder $siteFinder,
        private readonly ExtensionContextService $extensionContextService,
        private readonly ProStatusResolverService $proStatusResolverService,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ProCacheManager $proCacheManager,
        private readonly ProLicenceService $proLicenceService,
        private readonly ProSiteFingerprintService $proSiteFingerprintService,
    ) {
        parent::__construct(
            $moduleTemplateFactory,
            $uriBuilder,
            $iconFactory,
            $backendContextService,
            $siteResolutionService,
            $requestParameterService
        );
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->denyIfSettingsHidden($request);
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $moduleTemplate = $this->createModuleTemplate($request);
        $pageUid = $this->requestParameterService->getPageUidOrZero($request);
        $site = $this->resolveSiteForPage($request, $pageUid);

        $this->backendJavaScriptModuleService->loadBackendModule(
            $this->pageRenderer,
            $site
        );

        $fieldGroups = $this->fieldConfigRepository->findGroupedForSettings();
        $returnParameters = $this->getA11yModuleReturnParameters($request);

        $saveUrl = $this->buildRouteUrl('web_a11y.settingsSave');
        $saveExtConfUrl = $this->buildRouteUrl('web_a11y.settingsSaveExtConf');
        $refreshUrl = $this->buildRouteUrl('web_a11y.settingsRefresh');
        $overviewUrl = $this->buildRouteUrl('web_a11y', $returnParameters);

        $this->configureDocHeader($moduleTemplate, $overviewUrl);

        $currentSiteIdentifier = $site?->getIdentifier() ?? '';
        $selectedRulesetSite = trim((string)($request->getQueryParams()['rulesetSite'] ?? $currentSiteIdentifier));
        $activeTab = $this->resolveActiveTab((string)($request->getQueryParams()['tab'] ?? 'licence'));

        $proStatus = $this->proStatusResolverService->resolveForSiteIdentifier(
            $selectedRulesetSite !== '' ? $selectedRulesetSite : $currentSiteIdentifier
        );

        $qualityGateRuleset = $this->rulesetRepository->findForSiteOrDefault($selectedRulesetSite);
        if ($qualityGateRuleset === null) {
            $qualityGateRuleset = $this->rulesetRepository->findOrCreateDefault();
        }

        $licenceKey = $this->getExtensionConfigurationString('licenceKey');
        $showProHints = $this->getExtensionConfigurationBool('showProHints', true);
        $nonDescriptiveLinkPhrases = $this->getExtensionConfigurationString('nonDescriptiveLinkPhrases');
        $linkNewWindowHintPhrases = $this->getExtensionConfigurationString('linkNewWindowHintPhrases');

        $moduleTemplate->assignMultiple([
            'fieldGroups' => $fieldGroups,
            'saveUrl' => $saveUrl,
            'saveExtConfUrl' => $saveExtConfUrl,
            'refreshUrl' => $refreshUrl,
            'overviewUrl' => $overviewUrl,
            'returnParameters' => $returnParameters,
            'proStatus' => $proStatus,
            'qualityGateRuleset' => $qualityGateRuleset,
            'selectedRulesetSite' => $selectedRulesetSite,
            'currentSiteIdentifier' => $currentSiteIdentifier,
            'siteOptions' => $this->buildSiteOptions(),
            'currentPageUid' => $pageUid,
            'activeTab' => $activeTab,
            'licenceKey' => $licenceKey,
            'showProHints' => $showProHints,
            'nonDescriptiveLinkPhrases' => $nonDescriptiveLinkPhrases,
            'linkNewWindowHintPhrases' => $linkNewWindowHintPhrases,
            'nonDescriptiveDefaultsText' => implode(', ', self::NON_DESCRIPTIVE_DEFAULTS),
            'windowHintDefaultsText' => implode(', ', self::WINDOW_HINT_DEFAULTS),
            'pricingUrl' => 'https://typo3.priebera.sk/pricing',
            'settingsTabUrls' => $this->buildSettingsTabUrls($request, $selectedRulesetSite),
        ]);

        return $moduleTemplate->renderResponse('Settings/Index');
    }

    public function refreshAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->denyIfSettingsHidden($request);
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $discoveredFields = $this->tcaFieldDiscoveryService->discover();
        $this->fieldConfigRepository->refreshFromDiscovery($discoveredFields);

        $this->addFlashMessage(
            $this->translate('settings.flash.discoveryRefreshed')
        );

        $body = $this->parseRequestBody($request);
        $redirectParameters = $this->getA11yModuleReturnParameters($request);
        $rulesetSite = trim((string)($body['rulesetSite'] ?? $request->getQueryParams()['rulesetSite'] ?? ''));
        $tab = $this->resolveActiveTab((string)($body['tab'] ?? $request->getQueryParams()['tab'] ?? 'fields'));

        if ($rulesetSite !== '') {
            $redirectParameters['rulesetSite'] = $rulesetSite;
        }

        $redirectParameters['tab'] = $tab;

        return new RedirectResponse(
            $this->buildRouteUrl('web_a11y.settings', $redirectParameters),
            302
        );
    }

    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->denyIfSettingsHidden($request);
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $body = $this->parseRequestBody($request);
        $selectedRulesetSite = trim((string)($body['rulesetSite'] ?? ''));
        $activeTab = $this->resolveActiveTab((string)($body['tab'] ?? 'fields'));

        if ((string)($body['fieldsFormSubmitted'] ?? '') === '1') {
            $enabledFields = is_array($body['enabledFields'] ?? null) ? $body['enabledFields'] : [];
            $this->fieldConfigRepository->saveEnabledState($enabledFields);
        }

        if ((string)($body['qualityGateFormSubmitted'] ?? '') === '1') {
            $qualityGateData = is_array($body['qualityGate'] ?? null) ? $body['qualityGate'] : [];

            $publishMode = max(0, min(2, (int)($qualityGateData['publish_mode'] ?? 1)));
            $thresholdCritical = max(0, (int)($qualityGateData['threshold_critical'] ?? 0));
            $thresholdWarning = max(-1, (int)($qualityGateData['threshold_warning'] ?? -1));

            $pageUid = $this->requestParameterService->getPageUidOrZero($request);
            $site = $this->resolveSiteForPage($request, $pageUid);
            $currentSiteIdentifier = $site?->getIdentifier() ?? '';

            $proStatus = $this->proStatusResolverService->resolveForSiteIdentifier(
                $selectedRulesetSite !== '' ? $selectedRulesetSite : $currentSiteIdentifier
            );

            if ($publishMode === 2 && !$proStatus->valid) {
                $publishMode = 1;
            }

            $this->rulesetRepository->saveForSiteOrDefault(
                siteIdentifier: $selectedRulesetSite,
                publishMode: $publishMode,
                thresholdCritical: $thresholdCritical,
                thresholdWarning: $thresholdWarning,
            );
        }

        $this->addFlashMessage(
            $this->translate('settings.flash.saved')
        );

        $redirectParameters = $this->getA11yModuleReturnParameters($request);

        if ($selectedRulesetSite !== '') {
            $redirectParameters['rulesetSite'] = $selectedRulesetSite;
        }

        $redirectParameters['tab'] = $activeTab;

        return new RedirectResponse(
            $this->buildRouteUrl('web_a11y.settings', $redirectParameters),
            302
        );
    }

    public function saveExtConfAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->denyIfSettingsHidden($request);
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $body = $this->parseRequestBody($request);
        $activeTab = $this->resolveActiveTab((string)($body['tab'] ?? 'licence'));

        try {
            $configuration = $this->extensionConfiguration->get('a11y_quality_gate');
            $configuration = is_array($configuration) ? $configuration : [];
        } catch (\Throwable) {
            $configuration = [];
        }

        if ($activeTab === 'licence') {
            $configuration['licenceKey'] = trim((string)($body['licenceKey'] ?? ''));
            $configuration['showProHints'] = ((string)($body['showProHints'] ?? '') === '1') ? '1' : '0';
        }

        if ($activeTab === 'rules') {
            $configuration['nonDescriptiveLinkPhrases'] = trim((string)($body['nonDescriptiveLinkPhrases'] ?? ''));
            $configuration['linkNewWindowHintPhrases'] = trim((string)($body['linkNewWindowHintPhrases'] ?? ''));
        }

        $this->extensionConfiguration->set('a11y_quality_gate', $configuration);

        if ($activeTab === 'licence') {
            $this->proCacheManager->flushAll();
        }

        $this->addFlashMessage(
            $this->translate('settings.flash.saved')
        );

        $redirectParameters = $this->getA11yModuleReturnParameters($request);
        $redirectParameters['tab'] = $activeTab;

        $rulesetSite = trim((string)($body['rulesetSite'] ?? ''));
        if ($rulesetSite !== '') {
            $redirectParameters['rulesetSite'] = $rulesetSite;
        }

        return new RedirectResponse(
            $this->buildRouteUrl('web_a11y.settings', $redirectParameters),
            302
        );
    }

    public function validateLicenceAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->backendContextService->getBackendUser();
        if (!$this->accessControlService->canShowSettings($backendUser)) {
            return new JsonResponse([
                'valid' => false,
                'reason' => 'access_denied',
                'reasonLabel' => $this->translate('settings.accessDenied'),
            ], 403);
        }

        $body = $this->parseRequestBody($request);
        $licenceKey = trim((string)($body['licenceKey'] ?? ''));

        if ($licenceKey === '') {
            return new JsonResponse([
                'valid' => false,
                'reason' => 'empty_key',
                'reasonLabel' => $this->translate('settings.licence.validation.emptyKey'),
            ]);
        }

        $pageUid = $this->requestParameterService->getPageUidOrZero($request);
        $site = $this->resolveSiteForPage($request, $pageUid);
        $domain = $this->resolveValidationDomain($site);

        $isTrial = $this->looksLikeTrialKey($licenceKey);
        $allSites = $this->proSiteFingerprintService->collectValidationSites(
            $domain,
            $isTrial
        );

        $result = $this->proLicenceService->validateKeyDirect(
            $licenceKey,
            $domain,
            $this->extensionContextService->getExtensionVersion(),
            $allSites,
        );

        $isTrial = $result->isTrial || $isTrial;
        $plan = $isTrial ? 'trial' : ($result->plan !== '' ? $result->plan : null);

        $reasonLabel = $result->valid
            ? ($isTrial
                ? $this->translate('settings.licence.validation.trialValid')
                : $this->translate('settings.licence.validation.valid'))
            : $this->buildValidationReasonLabel($result);

        return new JsonResponse([
            'valid' => $result->valid,
            'plan' => $plan,
            'expiresAt' => $result->expiresAt,
            'trialExpiresAt' => $result->trialExpiresAt,
            'trialStartedAt' => $result->trialStartedAt,
            'isTrial' => $isTrial,
            'domain' => $domain,
            'reason' => $result->reason,
            'reasonLabel' => $reasonLabel,
        ]);
    }

    private function configureDocHeader(ModuleTemplate $moduleTemplate, string $overviewUrl): void
    {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        $this->setModuleTitle(
            $moduleTemplate,
            'module.title',
            'settings.title'
        );

        $overviewButton = $buttonBar->makeLinkButton()
            ->setHref($overviewUrl)
            ->setTitle($this->translate('settings.backToOverview'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL));

        $buttonBar->addButton($overviewButton, ButtonBar::BUTTON_POSITION_LEFT, 1);
    }

    private function denyIfSettingsHidden(ServerRequestInterface $request): ?ResponseInterface
    {
        $backendUser = $this->backendContextService->getBackendUser();

        if ($this->accessControlService->canShowSettings($backendUser)) {
            return null;
        }

        $this->addFlashMessage(
            $this->translate('settings.accessDenied'),
            ContextualFeedbackSeverity::WARNING
        );

        return new RedirectResponse(
            $this->buildRouteUrl('web_a11y', $this->getA11yModuleReturnParameters($request)),
            302
        );
    }

    /**
     * @return array<int, array{identifier:string,label:string}>
     */
    private function buildSiteOptions(): array
    {
        $options = [
            [
                'identifier' => '',
                'label' => $this->translate('settings.siteScope.allSites'),
            ],
        ];

        try {
            $sites = $this->siteFinder->getAllSites();

            foreach ($sites as $site) {
                if (!$site instanceof Site) {
                    continue;
                }

                $identifier = trim($site->getIdentifier());
                if ($identifier === '') {
                    continue;
                }

                $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase((string)$site->getBase());

                $options[] = [
                    'identifier' => $identifier,
                    'label' => $domain !== '' ? sprintf('%s (%s)', $identifier, $domain) : $identifier,
                ];
            }
        } catch (\Throwable) {
            return $options;
        }

        usort(
            $options,
            static function (array $a, array $b): int {
                if ($a['identifier'] === '') {
                    return -1;
                }

                if ($b['identifier'] === '') {
                    return 1;
                }

                return strcasecmp($a['label'], $b['label']);
            }
        );

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function buildSettingsTabUrls(ServerRequestInterface $request, string $selectedRulesetSite): array
    {
        $baseParameters = $this->getA11yModuleReturnParameters($request);

        if ($selectedRulesetSite !== '') {
            $baseParameters['rulesetSite'] = $selectedRulesetSite;
        }

        $urls = [];

        foreach (self::AVAILABLE_TABS as $tab) {
            $parameters = $baseParameters;
            $parameters['tab'] = $tab;

            $urls[$tab] = $this->buildRouteUrl('web_a11y.settings', $parameters);
        }

        return $urls;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRequestBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body)) {
            return $body;
        }

        $rawBody = (string)$request->getBody();
        if ($rawBody === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function resolveActiveTab(string $tab): string
    {
        $tab = trim($tab);

        return in_array($tab, self::AVAILABLE_TABS, true) ? $tab : 'licence';
    }

    private function getExtensionConfigurationString(string $key): string
    {
        try {
            return trim((string)$this->extensionConfiguration->get('a11y_quality_gate', $key));
        } catch (\Throwable) {
            return '';
        }
    }

    private function getExtensionConfigurationBool(string $key, bool $default): bool
    {
        try {
            $rawValue = $this->extensionConfiguration->get('a11y_quality_gate', $key);
        } catch (\Throwable) {
            return $default;
        }

        return filter_var($rawValue, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function resolveValidationDomain(?Site $site): string
    {
        if ($site instanceof Site) {
            return $this->extensionContextService->getNormalizedDomainFromSiteBase((string)$site->getBase());
        }

        try {
            foreach ($this->siteFinder->getAllSites() as $candidate) {
                if (!$candidate instanceof Site) {
                    continue;
                }

                $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase((string)$candidate->getBase());
                if ($domain !== '') {
                    return $domain;
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function buildValidationReasonLabel(LicenceValidationResult $result): ?string
    {
        if ($result->valid) {
            return $result->isTrial
                ? $this->translate('settings.licence.validation.trialValid')
                : $this->translate('settings.licence.validation.valid');
        }

        return match ($result->reason) {
            'invalid_key' => $this->translate('settings.licence.validation.reason.invalid_key'),
            'expired' => $this->translate('settings.licence.validation.reason.expired'),
            'inactive' => $this->translate('settings.licence.validation.reason.inactive'),
            'domain_mismatch' => $this->translate('settings.licence.validation.reason.domain_mismatch'),
            'domain_limit_reached' => $this->translate('settings.licence.validation.reason.domain_limit_reached'),
            'project_mismatch', 'licence_project_mismatch' => $this->translate('settings.licence.validation.reason.licence_project_mismatch'),
            'trial_expired' => $this->translate('settings.licence.validation.reason.trial_expired'),
            'trial_domain_mismatch' => $this->translate('settings.licence.validation.reason.trial_domain_mismatch'),
            'trial_project_mismatch' => $this->translate('settings.licence.validation.reason.trial_project_mismatch'),
            'trial_revoked' => $this->translate('settings.licence.validation.reason.trial_revoked'),
            'trial_not_verified' => $this->translate('settings.licence.validation.reason.trial_not_verified'),
            'api_unreachable' => $this->translate('settings.licence.validation.reason.api_unreachable'),
            default => $this->translate('settings.licence.validation.invalidFallback'),
        };
    }

    private function looksLikeTrialKey(string $licenceKey): bool
    {
        return $licenceKey !== ''
            && (
                str_starts_with($licenceKey, 'aqg_trial_')
                || str_starts_with($licenceKey, 'aqg_test_')
            );
    }
}