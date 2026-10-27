<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Ai\Service\AiSettingsUiStateBuilder;
use Priebera\A11yQualityGate\Configuration\PublicLinkProvider;
use Priebera\A11yQualityGate\Domain\Repository\FieldConfigRepository;
use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;
use Priebera\A11yQualityGate\Export\PdfGenerator;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResult;
use Priebera\A11yQualityGate\Pro\Service\ProLicenceDomainService;
use Priebera\A11yQualityGate\Pro\Service\ProLicenceService;
use Priebera\A11yQualityGate\Pro\Service\ProSiteFingerprintService;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanAccessSettingsService;
use Priebera\A11yQualityGate\Pro\ViewModel\LicenceGuidance;
use Priebera\A11yQualityGate\FreePreview\FreePreviewActivationService;
use Priebera\A11yQualityGate\FreePreview\FreePreviewStatement;
use Priebera\A11yQualityGate\FreePreview\FreeTermsAcceptance;
use Priebera\A11yQualityGate\Service\LocalHistoryRetentionService;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\AccessibilityStatementService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendJavaScriptModuleService;
use Priebera\A11yQualityGate\Service\BackendLanguageService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\FieldConfigurationBootstrapService;
use Priebera\A11yQualityGate\Rule\RuleRegistry;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SecretEncryptionService;
use Priebera\A11yQualityGate\Service\ScannerAccessTokenService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Service\TcaFieldDiscoveryService;
use Priebera\A11yQualityGate\UpdateNotice\UpdateNoticeService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;

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
        'remote_access',
        'ai',
        'statement',
        'history',
    ];

    /**
     * @var list<string>
     */
    private const ADMIN_ONLY_RULESET_FIELDS = [
        'scanner_token',
        'http_auth_user',
        'http_auth_pass',
        'excluded_patterns',
        'cookie_accept_selectors',
        'crawl_priority_urls',
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
        private readonly AccessibilityStatementService $accessibilityStatementService,
        private readonly PdfGenerator $pdfGenerator,
        private readonly RulesetRepository $rulesetRepository,
        private readonly RuleRegistry $ruleRegistry,
        private readonly RuleConfigurationService $ruleConfigurationService,
        private readonly SiteFinder $siteFinder,
        private readonly ExtensionContextService $extensionContextService,
        private readonly ProStatusResolverService $proStatusResolverService,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ProCacheManager $proCacheManager,
        private readonly ProLicenceService $proLicenceService,
        private readonly ProSiteFingerprintService $proSiteFingerprintService,
        private readonly SecretEncryptionService $secretEncryptionService,
        private readonly ScannerAccessTokenService $scannerAccessTokenService,
        private readonly PublicLinkProvider $publicLinkProvider,
        private readonly AiSettingsUiStateBuilder $aiSettingsUiStateBuilder,
        private readonly RequestFactory $requestFactory,
        private readonly CacheManager $cacheManager,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly FieldConfigurationBootstrapService $fieldConfigurationBootstrapService,
        private readonly RuleMetadataPresentationService $ruleMetadataPresentationService,
        private readonly ScopeAccessService $scopeAccessService,
        private readonly ?ProLicenceDomainService $proLicenceDomainService = null,
        private readonly ?UpdateNoticeService $updateNoticeService = null,
        private readonly ?RemoteScanAccessSettingsService $remoteScanAccessSettingsService = null,
        private readonly ?LocalHistoryRetentionService $localHistoryRetentionService = null,
        private readonly ?FreePreviewActivationService $freePreviewActivationService = null,
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
        $this->backendJavaScriptModuleService->loadVersionedModule($this->pageRenderer, '@priebera/a11y-quality-gate/backend/settings-quality-gate.js');
        $this->backendJavaScriptModuleService->loadVersionedModule($this->pageRenderer, '@priebera/a11y-quality-gate/backend/settings-remote-access.js');
        $this->backendJavaScriptModuleService->loadVersionedModule($this->pageRenderer, '@priebera/a11y-quality-gate/backend/settings-statement.js');
        $this->backendJavaScriptModuleService->loadVersionedModule($this->pageRenderer, '@priebera/a11y-quality-gate/backend/settings-licence-domains.js');
        $this->backendJavaScriptModuleService->loadVersionedModule($this->pageRenderer, '@priebera/a11y-quality-gate/backend/settings-ai.js');
        $updateNotice = $this->updateNoticeService?->buildForCurrentUser();
        if ($updateNotice !== null) {
            $this->backendJavaScriptModuleService->loadVersionedModule($this->pageRenderer, '@priebera/a11y-quality-gate/backend/update-notice.js');
        }

        $this->fieldConfigurationBootstrapService->initializeIfUnconfigured();
        $fieldGroups = $this->withReadableFieldLabels($this->fieldConfigRepository->findGroupedForSettings());
        $returnParameters = $this->getA11yModuleReturnParameters($request);

        $saveUrl = $this->buildRouteUrl('web_a11y.settingsSave');
        $saveExtConfUrl = $this->buildRouteUrl('web_a11y.settingsSaveExtConf');
        $refreshUrl = $this->buildRouteUrl('web_a11y.settingsRefresh');
        $overviewUrl = $this->buildRouteUrl('web_a11y', $returnParameters);

        $this->configureDocHeader($moduleTemplate, $overviewUrl);

        $currentSiteIdentifier = $site?->getIdentifier() ?? '';
        $selectedRulesetSite = trim((string)($request->getQueryParams()['rulesetSite'] ?? $currentSiteIdentifier));
        $activeTab = $this->resolveActiveTab((string)($request->getQueryParams()['tab'] ?? 'licence'));
        $isAdmin = $this->accessControlService->canManageAdminOnlySettings($this->backendContextService->getBackendUser());
        if (in_array($activeTab, ['remote_access', 'ai', 'history'], true) && !$isAdmin) {
            $activeTab = 'licence';
        }

        $proStatus = $this->resolveLicenceStatus(
            $selectedRulesetSite !== '' ? $selectedRulesetSite : $currentSiteIdentifier
        );
        $remoteAccessAvailable = (bool)($proStatus->valid ?? false) && (bool)($proStatus->hasCrawler ?? false);

        $qualityGateRuleset = $this->rulesetRepository->findOrCreateDefault();
        $rulesManagement = $this->buildRulesManagement($qualityGateRuleset);
        $dictionarySettings = $this->ruleConfigurationService->getDictionarySettingsFromRuleset($qualityGateRuleset);
        $renderedCheckSettings = $this->ruleConfigurationService->getRenderedCheckSettingsFromRuleset($qualityGateRuleset);
        $remoteAccessRuleset = $selectedRulesetSite !== ''
            ? ($this->rulesetRepository->findBySiteIdentifier($selectedRulesetSite) ?? $qualityGateRuleset)
            : $qualityGateRuleset;
        $remoteAccessExcludedPatternsText = $this->jsonListToTextarea((string)($remoteAccessRuleset['excluded_patterns'] ?? '[]'));
        $remoteAccessCookieAcceptSelectorsText = $this->jsonListToTextarea((string)($remoteAccessRuleset['cookie_accept_selectors'] ?? '[]'));
        $remoteAccessPriorityUrlsText = $this->jsonListToTextarea((string)($remoteAccessRuleset['crawl_priority_urls'] ?? '[]'));
        $remoteAccessScannerToken = trim((string)($remoteAccessRuleset['scanner_token'] ?? ''));
        // Same answer as the Overview and the scans: the selected site's own token, else the default token.
        $remoteAccessHasScannerToken = $remoteAccessScannerToken !== ''
            || ($this->remoteScanAccessSettingsService?->hasScannerTokenForSite($selectedRulesetSite) ?? false);
        $remoteAccessHasHttpAuthPassword = trim((string)($remoteAccessRuleset['http_auth_pass'] ?? '')) !== '';

        $siteRulesets = $this->enrichRulesetsWithSiteLabels(
            $this->rulesetRepository->findSiteSpecificRulesets()
        );
        $siteOptions = $this->buildSiteOptions();
        $siteOptionsWithoutDefault = array_values(array_filter(
            $siteOptions,
            static fn (array $siteOption): bool => (string)($siteOption['identifier'] ?? '') !== ''
        ));
        // Generating a statement needs read access to the site (buildAccessibilityStatementFromRequest()), so only
        // those sites are offered; any other choice would end in "Access denied".
        $statementSiteOptions = $this->filterReadableSiteOptions($siteOptionsWithoutDefault);
        $statementDefaultSiteIdentifier = $this->resolveDefaultStatementSiteIdentifier($statementSiteOptions, $currentSiteIdentifier);
        $statementProStatus = $statementDefaultSiteIdentifier !== ''
            ? $this->proStatusResolverService->resolveForSiteIdentifier($statementDefaultSiteIdentifier)
            : $proStatus;
        if (!$this->hasStatementGeneratorCapability($statementProStatus)) {
            $capableStatementSiteIdentifier = $this->resolveFirstStatementCapableSiteIdentifier($statementSiteOptions);
            if ($capableStatementSiteIdentifier !== '') {
                $statementDefaultSiteIdentifier = $capableStatementSiteIdentifier;
                $statementProStatus = $this->proStatusResolverService->resolveForSiteIdentifier($statementDefaultSiteIdentifier);
            }
        }
        $statementCapable = $this->hasStatementGeneratorCapability($statementProStatus);
        $statementGeneratorAvailable = $statementCapable && $statementSiteOptions !== [];
        $usedSiteIdentifiers = array_map(
            static fn (array $ruleset): string => (string)($ruleset['site_identifier'] ?? ''),
            $siteRulesets
        );
        $availableSiteOptions = array_values(array_filter(
            $siteOptionsWithoutDefault,
            static fn (array $siteOption): bool => !in_array((string)($siteOption['identifier'] ?? ''), $usedSiteIdentifiers, true)
        ));

        $licenceViewData = $this->buildLicenceViewData(
            $this->getExtensionConfigurationString('licenceKey'),
            $isAdmin,
        );
        $showProHints = $this->ruleConfigurationService->getShowProHintsFromRuleset(
            $qualityGateRuleset,
        ) ?? $this->getExtensionConfigurationBool('showProHints', true);
        $publicLinks = $this->publicLinkProvider->getBackendLinks();
        $settingsTabUrls = $this->buildSettingsTabUrls($request, $selectedRulesetSite);
        $licenceGuidance = $licenceViewData['hasLicenceKey'] && !(bool)($proStatus->valid ?? false)
            ? LicenceGuidance::forReason((string)($proStatus->reason ?? ''))->toView(
                [
                    LicenceGuidance::ACTION_RETRY => $settingsTabUrls['licence'] ?? '',
                    LicenceGuidance::ACTION_PRICING => $publicLinks[PublicLinkProvider::PRICING],
                    LicenceGuidance::ACTION_PORTAL => $publicLinks[PublicLinkProvider::PORTAL],
                    LicenceGuidance::ACTION_SUPPORT => $publicLinks[PublicLinkProvider::SUPPORT],
                ],
                fn (string $key): string => $this->translate($key),
            )
            : null;
        $aiSiteIdentifier = $selectedRulesetSite !== '' ? $selectedRulesetSite : $currentSiteIdentifier;
        $aiConfigurationStatus = ($isAdmin && $aiSiteIdentifier !== '')
            ? $this->aiSettingsUiStateBuilder->build($aiSiteIdentifier)
            : $this->aiSettingsUiStateBuilder->build('');

        $moduleTemplate->assignMultiple([
            'fieldGroups' => $fieldGroups,
            'saveUrl' => $saveUrl,
            'saveExtConfUrl' => $saveExtConfUrl,
            'refreshUrl' => $refreshUrl,
            'overviewUrl' => $overviewUrl,
            'returnParameters' => $returnParameters,
            'proStatus' => $proStatus,
            'remoteAccessAvailable' => $remoteAccessAvailable,
            'qualityGateRuleset' => $qualityGateRuleset,
            'rulesManagement' => $rulesManagement,
            'dictionarySettings' => $dictionarySettings,
            'renderedCheckSettings' => $renderedCheckSettings,
            'remoteAccessRuleset' => $remoteAccessRuleset,
            'remoteAccessExcludedPatternsText' => $remoteAccessExcludedPatternsText,
            'remoteAccessCookieAcceptSelectorsText' => $remoteAccessCookieAcceptSelectorsText,
            'remoteAccessPriorityUrlsText' => $remoteAccessPriorityUrlsText,
            'remoteAccessScannerToken' => '',
            'remoteAccessHasScannerToken' => $remoteAccessHasScannerToken,
            'remoteAccessHasHttpAuthPassword' => $remoteAccessHasHttpAuthPassword,
            'remoteAccessSaveUrl' => $saveUrl,
            'remoteAccessRegenerateTokenUrl' => $this->buildRouteUrl('ajax_a11y_regenerate_scanner_token'),
            'remoteAccessTestHttpAuthUrl' => $this->buildRouteUrl('ajax_a11y_test_http_auth'),
            'statementGenerateUrl' => $this->buildRouteUrl('ajax_a11y_statement_generate'),
            'statementPdfUrl' => $this->buildRouteUrl('ajax_a11y_statement_pdf'),
            'licenceDomainsUrl' => $this->buildRouteUrl('ajax_a11y_licence_domains'),
            'licenceDomainsUpdateUrl' => $this->buildRouteUrl('ajax_a11y_licence_domains_update'),
            'statementGeneratorAvailable' => $statementGeneratorAvailable,
            'statementNoReadableSite' => $statementCapable && $statementSiteOptions === [],
            'statementSiteOptions' => $statementSiteOptions,
            'statementDefaultSiteIdentifier' => $statementDefaultSiteIdentifier,
            'statementProStatus' => $statementProStatus,
            'remoteAccessSiteUrl' => $this->resolveRemoteAccessSiteUrl($site),
            'backendUserDisplayName' => $this->resolveBackendUserDisplayName(),
            'siteRulesets' => $siteRulesets,
            'siteOverrideCount' => count($siteRulesets),
            'siteOptions' => $siteOptions,
            'siteOptionsWithoutDefault' => $siteOptionsWithoutDefault,
            'availableSiteOptions' => $availableSiteOptions,
            'settingsLastSavedLabel' => $this->formatSettingsLastSavedLabel($qualityGateRuleset),
            'selectedRulesetSite' => $selectedRulesetSite,
            'currentSiteIdentifier' => $currentSiteIdentifier,
            'currentPageUid' => $pageUid,
            'activeTab' => $activeTab,
            'isAdmin' => $isAdmin,
            'hasLicenceKey' => $licenceViewData['hasLicenceKey'],
            'maskedLicenceKey' => $licenceViewData['maskedLicenceKey'],
            'licenceKeyEnding' => $licenceViewData['licenceKeyEnding'],
            'licenceKeyFingerprint' => $licenceViewData['licenceKeyFingerprint'],
            'licenceGuidance' => $licenceGuidance,
            'showProHints' => $showProHints,
            'productUrl' => $publicLinks[PublicLinkProvider::PRODUCT],
            'documentationUrl' => $publicLinks[PublicLinkProvider::DOCUMENTATION],
            'pricingUrl' => $publicLinks[PublicLinkProvider::PRICING],
            'trialUrl' => $publicLinks[PublicLinkProvider::TRIAL],
            'supportUrl' => $publicLinks[PublicLinkProvider::SUPPORT],
            'portalUrl' => $publicLinks[PublicLinkProvider::PORTAL],
            'settingsTabUrls' => $settingsTabUrls,
            'settingsTabCurrent' => $this->buildSettingsTabCurrentStates($activeTab),
            'aiConfigurationStatus' => $aiConfigurationStatus,
            'aiSiteIdentifier' => $aiSiteIdentifier,
            'aiSettingsSaveUrl' => $this->buildRouteUrl('ajax_a11y_ai_settings_save'),
            'aiSettingsRefreshModelsUrl' => $this->buildRouteUrl('ajax_a11y_ai_settings_refresh_models'),
            'aiSettingsSelectModelUrl' => $this->buildRouteUrl('ajax_a11y_ai_settings_select_model'),
            'aiSettingsTestUrl' => $this->buildRouteUrl('ajax_a11y_ai_settings_test'),
            'aiSettingsLinkTextToggleUrl' => $this->buildRouteUrl('ajax_a11y_ai_settings_link_text_toggle'),
            'updateNotice' => $updateNotice,
            'historyRetention' => $this->buildHistoryRetentionView($activeTab, $isAdmin),
            'freePreviewActivation' => $this->buildFreePreviewActivationView($activeTab, $isAdmin, $site, (bool)($proStatus->valid ?? false)),
        ]);

        return $moduleTemplate->renderResponse('Settings/Index');
    }

    /**
     * @param array<string, mixed>|null $ruleset
     */
    private function formatSettingsLastSavedLabel(?array $ruleset): string
    {
        $timestamp = (int)($ruleset['tstamp'] ?? 0);
        if ($timestamp <= 0) {
            return '';
        }

        return date('d.m.Y · H:i', $timestamp);
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

        return $this->buildSettingsPostResponse($request, $redirectParameters);
    }

    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->denyIfSettingsHidden($request);
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $body = $this->parseRequestBody($request);
        $isAdmin = $this->accessControlService->canManageAdminOnlySettings($this->backendContextService->getBackendUser());
        if (!$isAdmin && (
            (string)($body['qualityGateFormSubmitted'] ?? '') === '1'
            || (string)($body['remoteScanAccessFormSubmitted'] ?? '') === '1'
        )) {
            return $this->buildAdminOnlySettingsDeniedResponse($request, (string)($body['tab'] ?? 'fields'));
        }
        if (!$isAdmin) {
            $body = $this->removeAdminOnlyRulesetFields($body);
        }

        $selectedRulesetSite = trim((string)($body['rulesetSite'] ?? ''));
        $activeTab = $this->resolveActiveTab((string)($body['tab'] ?? 'fields'));
        if (in_array($activeTab, ['remote_access', 'ai'], true) && !$isAdmin) {
            $activeTab = 'licence';
        }

        if ((string)($body['fieldsFormSubmitted'] ?? '') === '1') {
            $enabledFields = is_array($body['enabledFields'] ?? null) ? $body['enabledFields'] : [];
            $this->fieldConfigRepository->saveEnabledState($enabledFields);
        }

        if ((string)($body['rulesManagementFormSubmitted'] ?? '') === '1') {
            $this->saveRuleManagementState($body);
        }

        if ((string)($body['qualityGateFormSubmitted'] ?? '') === '1') {
            $qualityGateData = is_array($body['qualityGate'] ?? null) ? $body['qualityGate'] : [];
            $globalData = is_array($qualityGateData['global'] ?? null) ? $qualityGateData['global'] : $qualityGateData;
            $resetToDefaults = (string)($qualityGateData['reset_defaults'] ?? '0') === '1';
            $isGlobal = (string)($qualityGateData['is_global'] ?? '1') === '1';

            if ($resetToDefaults) {
                $isGlobal = true;
                $globalData = [
                    'publish_mode' => 0,
                    'threshold_critical' => 0,
                    'threshold_warning' => -1,
                ];
            }

            $publishMode = max(0, min(2, (int)($globalData['publish_mode'] ?? 1)));
            $thresholdCritical = max(0, (int)($globalData['threshold_critical'] ?? 0));
            $thresholdWarning = max(-1, (int)($globalData['threshold_warning'] ?? -1));

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
                siteIdentifier: '',
                publishMode: $publishMode,
                thresholdCritical: $thresholdCritical,
                thresholdWarning: $thresholdWarning,
                isGlobal: $isGlobal,
            );

            if ($resetToDefaults) {
                $this->rulesetRepository->deleteSiteSpecificExcept([]);
            } elseif (!$isGlobal) {
                $submittedSites = is_array($qualityGateData['sites'] ?? null) ? $qualityGateData['sites'] : [];
                $siteIdentifiersToKeep = [];

                foreach ($submittedSites as $siteIdentifier => $siteData) {
                    $siteIdentifier = trim((string)$siteIdentifier);
                    if ($siteIdentifier === '' || !is_array($siteData)) {
                        continue;
                    }

                    $sitePublishMode = max(0, min(2, (int)($siteData['publish_mode'] ?? $publishMode)));
                    if ($sitePublishMode === 2 && !$proStatus->valid) {
                        $sitePublishMode = 1;
                    }

                    $this->rulesetRepository->saveForSiteOrDefault(
                        siteIdentifier: $siteIdentifier,
                        publishMode: $sitePublishMode,
                        thresholdCritical: max(0, (int)($siteData['threshold_critical'] ?? $thresholdCritical)),
                        thresholdWarning: max(-1, (int)($siteData['threshold_warning'] ?? $thresholdWarning)),
                        isGlobal: false,
                    );
                    $siteIdentifiersToKeep[] = $siteIdentifier;
                }

                $this->rulesetRepository->deleteSiteSpecificExcept($siteIdentifiersToKeep);
            }
        }

        if ((string)($body['remoteScanAccessFormSubmitted'] ?? '') === '1' && $isAdmin && $this->hasRemoteScanAccessCapability($selectedRulesetSite, $request)) {
            $remoteAccessData = is_array($body['remoteScanAccess'] ?? null) ? $body['remoteScanAccess'] : [];
            $httpAuthPass = trim((string)($remoteAccessData['http_auth_pass'] ?? ''));
            $clearHttpAuthPassword = (string)($remoteAccessData['clear_http_auth_password'] ?? '') === '1';
            $encryptedHttpAuthPass = null;
            if ($clearHttpAuthPassword) {
                $encryptedHttpAuthPass = '';
            } elseif ($httpAuthPass !== '') {
                $encryptedHttpAuthPass = $this->secretEncryptionService->encrypt($httpAuthPass);
            }
            $existingRemoteRuleset = $selectedRulesetSite !== ''
                ? ($this->rulesetRepository->findBySiteIdentifier($selectedRulesetSite) ?? $this->rulesetRepository->findOrCreateDefault())
                : $this->rulesetRepository->findOrCreateDefault();

            $this->rulesetRepository->saveRemoteScanAccessForSiteOrDefault(
                siteIdentifier: $selectedRulesetSite,
                scannerToken: trim((string)($existingRemoteRuleset['scanner_token'] ?? '')),
                httpAuthUser: trim((string)($remoteAccessData['http_auth_user'] ?? '')),
                encryptedHttpAuthPass: $encryptedHttpAuthPass,
                excludedPatterns: $this->normalizeJsonListSetting($remoteAccessData['excluded_patterns'] ?? '[]'),
                cookieAcceptSelectors: $this->normalizeJsonListSetting($remoteAccessData['cookie_accept_selectors'] ?? '[]'),
                crawlPriorityUrls: $this->normalizeJsonListSetting($remoteAccessData['crawl_priority_urls'] ?? '[]'),
            );
        }

        $redirectParameters = $this->getA11yModuleReturnParameters($request);

        if ($selectedRulesetSite !== '') {
            $redirectParameters['rulesetSite'] = $selectedRulesetSite;
        }

        $redirectParameters['tab'] = $activeTab;

        return $this->buildSettingsPostResponse($request, $redirectParameters, $this->translate('settings.flash.saved'));
    }

    public function saveExtConfAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->denyIfSettingsHidden($request);
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $body = $this->parseRequestBody($request);
        $activeTab = $this->resolveActiveTab((string)($body['tab'] ?? 'licence'));

        $backendUser = $this->backendContextService->getBackendUser();
        if (!$this->accessControlService->canManageAdminOnlySettings($backendUser)) {
            return $this->buildAdminOnlySettingsDeniedResponse($request, $activeTab);
        }

        try {
            $configuration = $this->extensionConfiguration->get('a11y_quality_gate');
            $configuration = is_array($configuration) ? $configuration : [];
        } catch (\Throwable) {
            $configuration = [];
        }

        $showProHints = true;
        if ($activeTab === 'licence') {
            $showProHints = $this->submittedBoolean($body['showProHints'] ?? null);
            $configuration['licenceKey'] = $this->resolveSubmittedLicenceKey($body, (string)($configuration['licenceKey'] ?? ''));
            $configuration['showProHints'] = $showProHints ? '1' : '0';
        }

        if ($activeTab === 'rules') {
            if ((string)($body['rulesManagementFormSubmitted'] ?? '') === '1') {
                $this->saveRuleManagementState($body);
            }
        }

        if ($activeTab === 'licence') {
            // Persist UI-only state in the AQG ruleset first, so the toggle survives even
            // when LocalConfiguration writes are restricted on a staging/live system.
            $this->saveShowProHintsState($showProHints);
            $configurationPersisted = $this->persistExtensionConfiguration($configuration);
            $this->proCacheManager->flushAll();
        }

        $redirectParameters = $this->getA11yModuleReturnParameters($request);
        $redirectParameters['tab'] = $activeTab;

        $rulesetSite = trim((string)($body['rulesetSite'] ?? ''));
        if ($rulesetSite !== '') {
            $redirectParameters['rulesetSite'] = $rulesetSite;
        }

        if (($configurationPersisted ?? true) === false) {
            // Never report success for a write that did not happen.
            $this->addFlashMessage(
                $this->translate('settings.flash.configurationNotWritable'),
                ContextualFeedbackSeverity::ERROR
            );

            return new RedirectResponse(
                $this->buildRouteUrl('web_a11y.settings', $redirectParameters),
                303
            );
        }

        return $this->buildSettingsPostResponse($request, $redirectParameters, $this->translate('settings.flash.saved'));
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

        if (!$this->accessControlService->canManageAdminOnlySettings($backendUser)) {
            return new JsonResponse([
                'valid' => false,
                'reason' => 'admin_only_settings_required',
                'reasonLabel' => $this->translateWithFallback('settings.adminOnly.licence', 'Only administrators can validate AQG licence keys.'),
            ], 403);
        }

        $body = $this->parseRequestBody($request);
        $licenceKey = trim((string)($body['licenceKey'] ?? ''));
        if ($licenceKey === '' && (string)($body['useSavedKey'] ?? '') === '1') {
            // "Revalidate": the page no longer holds the saved key, so the server checks the stored one.
            $licenceKey = $this->getExtensionConfigurationString('licenceKey');
        }

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

        if ($result->valid || !$result->isTransientFailure()) {
            // A re-validation request is often followed by returning to the overview without saving the
            // form again. A definitive answer — valid or rejected — replaces every cached licence state and
            // token, so neither a stale invalid nor a stale positive state survives it. An outage or rate
            // limit says nothing about the key and keeps the last known good state.
            $this->proCacheManager->flushAll();
        }

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

    /**
     * The licence's domains as the AQG service holds them for this installation (detected sites versus activated
     * domains). Administrators only, like the licence key itself.
     */
    public function licenceDomainsAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyLicenceDomainManagement();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $result = $this->proLicenceDomainService?->list(
            $this->resolveValidationDomain($this->resolveSiteForPage($request, $this->requestParameterService->getPageUidOrZero($request))),
            $this->extensionContextService->getExtensionVersion(),
        ) ?? ['success' => false, 'code' => 'unavailable', 'message' => $this->translate('settings.licence.domains.error.failed')];

        return new JsonResponse($result, ($result['success'] ?? false) ? 200 : 409);
    }

    /**
     * Activates or deactivates domains. The AQG service decides: it activates only hosts this installation reports
     * and enforces the plan's domain limit and the activation lock, for bulk requests too.
     */
    public function updateLicenceDomainsAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyLicenceDomainManagement();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $body = $this->parseRequestBody($request);
        $domains = $body['domains'] ?? [];
        $result = $this->proLicenceDomainService?->change(
            (string)($body['action'] ?? ''),
            is_array($domains) ? array_values($domains) : [],
            (string)($body['all'] ?? '') === '1' || ($body['all'] ?? false) === true,
            $this->resolveValidationDomain($this->resolveSiteForPage($request, $this->requestParameterService->getPageUidOrZero($request))),
            $this->extensionContextService->getExtensionVersion(),
        ) ?? ['success' => false, 'code' => 'unavailable', 'message' => $this->translate('settings.licence.domains.error.failed')];

        return new JsonResponse($result, ($result['success'] ?? false) ? 200 : 409);
    }

    private function denyLicenceDomainManagement(): ?ResponseInterface
    {
        $backendUser = $this->backendContextService->getBackendUser();
        if (!$this->accessControlService->canShowSettings($backendUser)) {
            return new JsonResponse(['success' => false, 'code' => 'access_denied', 'message' => $this->translate('settings.accessDenied')], 403);
        }
        if (!$this->accessControlService->canManageAdminOnlySettings($backendUser)) {
            return new JsonResponse([
                'success' => false,
                'code' => 'admin_only_settings_required',
                'message' => $this->translateWithFallback('settings.adminOnly.licence', 'Only administrators can validate AQG licence keys.'),
            ], 403);
        }

        return null;
    }

    public function generateAccessibilityStatementAction(ServerRequestInterface $request): ResponseInterface
    {
        $result = $this->buildAccessibilityStatementFromRequest($request);
        if (!($result['success'] ?? false)) {
            return $this->buildStatementFailureResponse($result, 'settings.statement.error.unavailable');
        }

        return new JsonResponse([
            'success' => true,
            'statement' => $result['statement'],
        ]);
    }

    public function generateAccessibilityStatementPdfAction(ServerRequestInterface $request): ResponseInterface
    {
        $result = $this->buildAccessibilityStatementFromRequest($request);
        if (!($result['success'] ?? false)) {
            return $this->buildStatementFailureResponse($result, 'settings.statement.error.pdfUnavailable');
        }
        if (!PdfGenerator::isAvailable()) {
            return $this->buildStatementFailureResponse(['code' => 'pdf_library_missing', 'statusCode' => 503], 'export.pdf.libraryMissing');
        }

        $statement = is_array($result['statement'] ?? null) ? $result['statement'] : [];
        $title = $this->translate('settings.statement.pdf.title');
        try {
            $pdf = $this->pdfGenerator->render(
                $this->accessibilityStatementService->buildPdfHtml($statement),
                $title,
                [],
                $this->accessibilityStatementService->buildPdfCss(),
                (string)($statement['language'] ?? 'en'),
            );
        } catch (\Throwable $exception) {
            $this->logStatementPdfFailure($exception);
            return $this->buildStatementFailureResponse(
                ['code' => 'pdf_generation_failed', 'statusCode' => 500],
                'settings.statement.error.pdfUnavailable'
            );
        }

        $stream = $this->streamFactory->createStream($pdf);

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="accessibility-statement-draft.pdf"')
            ->withHeader('Content-Length', (string)mb_strlen($pdf, '8bit'))
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withBody($stream);
    }

    /**
     * Failures keep their real HTTP status, so a rate limit (429 + Retry-After) or an upstream
     * outage (502/503) is never a 200 that only the response body contradicts.
     *
     * @param array<string, mixed> $result
     */
    private function buildStatementFailureResponse(array $result, string $fallbackMessageKey): ResponseInterface
    {
        $status = (int)($result['statusCode'] ?? 503);
        if ($status < 400 || $status > 599) {
            $status = 503;
        }
        $retryAfter = isset($result['retryAfter']) ? max(1, (int)$result['retryAfter']) : null;
        $message = trim((string)($result['message'] ?? ''));

        $payload = [
            'success' => false,
            'code' => (string)($result['code'] ?? 'statement_unavailable'),
            'message' => $message !== '' ? $message : $this->translate($fallbackMessageKey),
        ];
        if ($retryAfter !== null) {
            $payload['retryAfter'] = $retryAfter;
        }

        $response = new JsonResponse($payload, $status);

        return $retryAfter !== null ? $response->withHeader('Retry-After', (string)$retryAfter) : $response;
    }

    /**
     * @return array{success:false,code:string,message:string,statusCode:int}
     */
    private function statementRequestError(string $code, int $statusCode, string $messageKey): array
    {
        return [
            'success' => false,
            'code' => $code,
            'message' => $this->translate($messageKey),
            'statusCode' => $statusCode,
        ];
    }

    private function logStatementPdfFailure(\Throwable $exception): void
    {
        try {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(__CLASS__)
                ->warning('AQG accessibility statement PDF rendering failed', [
                    'exceptionClass' => $exception::class,
                    'exceptionMessage' => $exception->getMessage(),
                ]);
        } catch (\Throwable) {
            // Logging must never replace the bounded response with a raw error.
        }
    }

    /**
     * @return array{success:bool,message?:string,statusCode?:int,code?:string,retryAfter?:int|null,statement?:array<string,mixed>}
     */
    private function buildAccessibilityStatementFromRequest(ServerRequestInterface $request): array
    {
        $backendUser = $this->backendContextService->getBackendUser();
        if (!$this->accessControlService->canShowSettings($backendUser)) {
            return $this->statementRequestError('access_denied', 403, 'settings.statement.error.accessDenied');
        }

        $body = $this->parseRequestBody($request);
        $siteIdentifier = trim((string)($body['siteId'] ?? $body['siteIdentifier'] ?? ''));
        $scope = trim((string)($body['scope'] ?? 'latest_site'));
        $startUrl = trim((string)($body['startUrl'] ?? ''));
        $jobId = trim((string)($body['jobId'] ?? ''));
        $language = strtolower(trim((string)($body['language'] ?? 'en')));
        if (!in_array($language, ['en', 'de'], true)) {
            return $this->statementRequestError('unsupported_language', 400, 'settings.statement.error.unsupportedLanguage');
        }

        if (!in_array($scope, ['latest_site', 'latest_page', 'specific_job'], true)) {
            return $this->statementRequestError('invalid_scope', 400, 'statement.error.invalidScanType');
        }

        if ($siteIdentifier === '') {
            return $this->statementRequestError('site_required', 400, 'settings.statement.error.chooseSite');
        }

        $site = $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier);
        if (!$site instanceof Site) {
            return $this->statementRequestError('site_not_resolved', 400, 'settings.statement.error.siteNotResolved');
        }

        // A statement summarises the site's scan results, so it needs the same access as the site's Overview.
        if (!$this->scopeAccessService->canReadSite($site)) {
            return $this->statementRequestError('access_denied', 403, 'settings.statement.error.accessDenied');
        }

        $proStatus = $this->proStatusResolverService->resolveForSiteIdentifier($siteIdentifier);
        if (!$this->hasStatementGeneratorCapability($proStatus)) {
            return $this->statementRequestError('pro_required', 403, 'settings.statement.error.proOnly');
        }

        $draftOptions = is_array($body['draftOptions'] ?? null) ? $body['draftOptions'] : [];
        foreach ([
            'conformityStatus',
            'organisation',
            'organization',
            'contactEmail',
            'phone',
            'postalAddress',
            'address',
            'responseNote',
            'enforcementProcedure',
            'customEnforcementText',
            'statusConfirmed',
            'conformityStatusConfirmed',
        ] as $key) {
            if (array_key_exists($key, $body) && !array_key_exists($key, $draftOptions)) {
                $draftOptions[$key] = $body[$key];
            }
        }

        // Rejected before any API call: invalid input must not produce a plausible-looking statement.
        $draftError = $this->accessibilityStatementService->validateDraftOptions($draftOptions);
        if ($draftError !== null) {
            return [
                'success' => false,
                'code' => $draftError['code'],
                'message' => $draftError['message'],
                'statusCode' => 400,
            ];
        }

        $siteBase = (string)$site->getBase();
        if ($scope === 'specific_job') {
            if ($jobId === '') {
                return $this->statementRequestError('job_id_required', 400, 'settings.statement.error.enterJobId');
            }
            if (!$this->accessibilityStatementService->isValidJobId($jobId)) {
                return $this->statementRequestError('invalid_job_id', 400, 'statement.error.validJobId');
            }

            $statement = $this->accessibilityStatementService->loadByJobId($siteBase, $jobId, $language, $draftOptions, $siteIdentifier);
        } elseif ($scope === 'latest_page') {
            if ($startUrl === '') {
                return $this->statementRequestError('page_url_required', 400, 'settings.statement.error.enterPageUrl');
            }
            if (!$this->accessibilityStatementService->isValidPageUrl($startUrl)) {
                return $this->statementRequestError('invalid_page_url', 400, 'settings.statement.error.invalidPageUrl');
            }

            $statement = $this->accessibilityStatementService->loadLatest(
                siteBase: $siteBase,
                siteId: $siteIdentifier,
                sourceType: 'single_page',
                startUrl: $startUrl,
                language: $language,
                draftOptions: $draftOptions,
            );
        } else {
            $statement = $this->accessibilityStatementService->loadLatestSiteScan($siteBase, $siteIdentifier, $language, $draftOptions);
        }

        if (!($statement['available'] ?? false)) {
            $error = is_array($statement['error'] ?? null) ? $statement['error'] : [];

            return [
                'success' => false,
                'code' => (string)($error['code'] ?? 'statement_unavailable'),
                'message' => (string)($statement['message'] ?? $this->translate('settings.statement.error.unavailable')),
                'statusCode' => (int)($error['httpStatus'] ?? 503),
                'retryAfter' => isset($error['retryAfter']) ? (int)$error['retryAfter'] : null,
            ];
        }

        return [
            'success' => true,
            'statement' => $statement,
        ];
    }

    public function regenerateScannerTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->backendContextService->getBackendUser();
        if (!$this->accessControlService->canManageAdminOnlySettings($backendUser)) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translateWithFallback('settings.adminOnly.token', 'Only administrators can regenerate the scanner token.'),
            ], 403);
        }

        $body = $this->parseRequestBody($request);
        $rulesetSite = trim((string)($body['rulesetSite'] ?? ''));
        if (!$this->hasRemoteScanAccessCapability($rulesetSite, $request)) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translateWithFallback('settings.remoteAccess.lockedMessage', 'Remote scan access settings need a valid Trial, PRO or Agency licence. Add a licence key or start a trial to configure them.'),
            ], 403);
        }

        $token = $this->scannerAccessTokenService->generateAndSaveTokenForSiteOrDefault($rulesetSite);
        if ($rulesetSite === '') {
            $this->syncScannerTokenToConfiguredSites($token);
        }

        return new JsonResponse([
            'success' => true,
            'token' => $token,
            'maskedToken' => $this->maskSecret($token),
            'message' => $this->translateWithFallback('settings.remoteAccess.token.regeneratedMessage', 'Scanner token regenerated.'),
        ]);
    }

    public function testHttpAuthAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->backendContextService->getBackendUser();
        if (!$this->accessControlService->canManageAdminOnlySettings($backendUser)) {
            return new JsonResponse([
                'success' => false,
                'ok' => false,
                'message' => $this->translateWithFallback('settings.adminOnly.httpAuth', 'Only administrators can test HTTP Basic Auth.'),
            ], 403);
        }

        $body = $this->parseRequestBody($request);
        $rulesetSite = trim((string)($body['rulesetSite'] ?? ''));
        if (!$this->hasRemoteScanAccessCapability($rulesetSite, $request)) {
            return new JsonResponse([
                'success' => false,
                'ok' => false,
                'status' => 403,
                'message' => $this->translateWithFallback('settings.remoteAccess.lockedMessage', 'Remote scan access settings need a valid Trial, PRO or Agency licence. Add a licence key or start a trial to configure them.'),
            ], 403);
        }

        if (!$this->consumeHttpAuthTestQuota($request)) {
            return new JsonResponse([
                'success' => false,
                'ok' => false,
                'status' => 429,
                'message' => $this->translateWithFallback('settings.remoteAccess.httpAuth.rateLimited', 'Too many test requests. Please wait a minute and try again.'),
            ], 429);
        }

        $username = trim((string)($body['username'] ?? ''));
        $password = trim((string)($body['password'] ?? ''));

        $site = $rulesetSite !== ''
            ? $this->siteResolutionService->resolveSiteByIdentifier($rulesetSite)
            : $this->resolveSiteForPage($request, $this->requestParameterService->getPageUidOrZero($request));
        $siteUrl = $this->resolveSafeHttpAuthTestUrl($site);

        if ($password === '') {
            $siteRuleset = $rulesetSite !== '' ? $this->rulesetRepository->findBySiteIdentifier($rulesetSite) : null;
            $defaultRuleset = $this->rulesetRepository->findDefault();
            $encryptedPassword = trim((string)($siteRuleset['http_auth_pass'] ?? ''));
            if ($encryptedPassword === '') {
                $encryptedPassword = trim((string)($defaultRuleset['http_auth_pass'] ?? ''));
            }
            $password = $this->secretEncryptionService->decrypt($encryptedPassword);
        }

        $siteHost = $site instanceof Site ? trim((string)(parse_url((string)$site->getBase(), PHP_URL_HOST) ?? '')) : '';
        if ($siteUrl === '' && $siteHost !== '' && $this->isLocalOrPrivateHost($siteHost) && $username !== '' && $password !== '') {
            // A staging site on a private address (VPN, internal DNS, DDEV) was answered with "select a site and
            // enter username and password" although both were given.
            return new JsonResponse([
                'success' => false,
                'ok' => false,
                'status' => 0,
                'code' => 'private_site',
                'message' => $this->translateWithFallback('settings.remoteAccess.httpAuth.privateSite', 'The site address is local or private (for example DDEV, localhost or a VPN host), so this server does not test it: the AQG crawler only reaches public sites.'),
            ], 400);
        }

        if ($siteUrl === '' || $username === '' || $password === '') {
            return new JsonResponse([
                'success' => false,
                'ok' => false,
                'status' => 0,
                'message' => $this->translateWithFallback('settings.remoteAccess.httpAuth.missingInput', 'Select a configured site and enter username and password before testing.'),
            ], 400);
        }

        try {
            $response = $this->requestFactory->request($siteUrl, 'HEAD', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
                    'User-Agent' => 'AQG remote access test',
                ],
                'timeout' => 8,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);

            $status = $response->getStatusCode();
            $ok = $status >= 200 && $status < 400;

            return new JsonResponse([
                'success' => true,
                'ok' => $ok,
                'status' => $status,
                'message' => $ok
                    ? $this->translateWithFallback('settings.remoteAccess.httpAuth.okDetail', 'Connection OK — the crawler reached the frontend with these credentials.')
                    : ($status === 401 || $status === 403
                        ? $this->translateWithFallback('settings.remoteAccess.httpAuth.authFailed', 'Authentication failed — the username or password is wrong.')
                        : $this->translateWithFallback('settings.remoteAccess.httpAuth.failedHint', 'Connection failed. Please check the credentials or the frontend protection.')),
            ]);
        } catch (\Throwable) {
            return new JsonResponse([
                'success' => true,
                'ok' => false,
                'status' => 0,
                'message' => $this->translateWithFallback('settings.remoteAccess.httpAuth.failedHint', 'Connection failed. Please check the credentials or the frontend protection.'),
            ]);
        }
    }

    private function configureDocHeader(ModuleTemplate $moduleTemplate, string $overviewUrl): void
    {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $overviewUrl = trim($overviewUrl);

        $this->setModuleTitle(
            $moduleTemplate,
            'module.title',
            'settings.title'
        );

        if ($overviewUrl === '') {
            return;
        }

        $overviewTitle = trim($this->translate('settings.backToOverview'));
        if ($overviewTitle === '' || $overviewTitle === 'settings.backToOverview' || str_starts_with($overviewTitle, 'LLL:')) {
            $overviewTitle = 'Back to overview';
        }

        $overviewButton = $buttonBar->makeLinkButton()
            ->setHref($overviewUrl)
            ->setTitle($overviewTitle)
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

    private function buildRulesManagement(?array $ruleset): array
    {
        $disabledRuleIds = is_array($ruleset)
            ? $this->ruleConfigurationService->getDisabledRuleIdsFromRuleset($ruleset)
            : [];
        $disabledLookup = array_fill_keys($disabledRuleIds, true);
        $groups = [];
        $allCount = 0;
        $enabledCount = 0;
        $disabledCount = 0;

        $backendLanguageCode = $this->getBackendLanguageCode();

        foreach ($this->ruleRegistry->getAll() as $rule) {
            $ruleId = $rule->getRuleId();
            $enabled = !isset($disabledLookup[$ruleId]);
            $groupKey = $this->resolveRuleGroupKey($ruleId, get_class($rule));
            $group = $this->buildRuleGroupMeta($groupKey);
            [$ruleLabel, $ruleDescription] = $this->resolveRuleCopy($rule->getRuleId(), $rule->getMessage(), $rule->getHint(), $backendLanguageCode);
            $severityName = strtolower($rule->getDefaultSeverity()->name);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = $group + [
                    'rules' => [],
                    'enabledCount' => 0,
                    'disabledCount' => 0,
                    'totalCount' => 0,
                ];
            }

            $groups[$groupKey]['rules'][] = [
                'id' => $ruleId,
                'inputId' => 'aqg-rule-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $ruleId),
                'label' => $ruleLabel,
                'description' => $ruleDescription,
                'severity' => $severityName,
                'severityLabel' => $this->translateWithFallback(
                    'severity.' . lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $severityName)))),
                    ucfirst($severityName)
                ),
                'category' => $group['category'],
                'enabled' => $enabled,
                'searchText' => mb_strtolower(implode(' ', [$ruleId, $ruleLabel, $ruleDescription, $rule->getMessage(), $rule->getHint(), $group['category']])),
            ];

            $groups[$groupKey]['totalCount']++;
            $allCount++;

            if ($enabled) {
                $groups[$groupKey]['enabledCount']++;
                $enabledCount++;
            } else {
                $groups[$groupKey]['disabledCount']++;
                $disabledCount++;
            }
        }

        $order = ['rte', 'structured', 'media', 'remote', 'other'];
        uksort($groups, static fn (string $a, string $b): int => array_search($a, $order, true) <=> array_search($b, $order, true));

        return [
            'groups' => array_values($groups),
            'counts' => [
                'all' => $allCount,
                'enabled' => $enabledCount,
                'disabled' => $disabledCount,
            ],
        ];
    }

    private function saveRuleManagementState(array $body): void
    {
        $allRuleIds = $this->ruleRegistry->getAllIds();
        $enabledRuleIds = is_array($body['enabledRules'] ?? null)
            ? array_values(array_unique(array_map(static fn (mixed $ruleId): string => trim((string)$ruleId), $body['enabledRules'])))
            : [];
        $enabledLookup = array_fill_keys($enabledRuleIds, true);
        $disabledRuleIds = array_values(array_filter(
            $allRuleIds,
            static fn (string $ruleId): bool => !isset($enabledLookup[$ruleId])
        ));
        $ruleset = $this->rulesetRepository->findOrCreateDefault();
        $currentRulesJson = is_array($ruleset) ? (string)($ruleset['rules_json'] ?? '') : '';
        $rulesJson = $this->ruleConfigurationService->encodeRulesJsonWithDisabledRules(
            $currentRulesJson,
            $disabledRuleIds
        );

        $dictionarySettings = is_array($body['dictionarySettings'] ?? null) ? $body['dictionarySettings'] : [];
        $rulesJson = $this->ruleConfigurationService->encodeRulesJsonWithDictionarySettings(
            $rulesJson,
            $dictionarySettings
        );

        $renderedCheckSettings = is_array($body['renderedCheckSettings'] ?? null) ? $body['renderedCheckSettings'] : [];
        $renderedCheckSettings = [
            'enabled' => $this->submittedBoolean($renderedCheckSettings['enabled'] ?? null),
            'allowPrivateHosts' => $this->submittedBoolean($renderedCheckSettings['allowPrivateHosts'] ?? null),
        ];
        $rulesJson = $this->ruleConfigurationService->encodeRulesJsonWithRenderedCheckSettings(
            $rulesJson,
            $renderedCheckSettings
        );

        $this->rulesetRepository->saveRulesJsonForDefault($rulesJson);
    }

    private function resolveRuleGroupKey(string $ruleId, string $className): string
    {
        if (str_starts_with($ruleId, 'rte.') || str_contains($className, '\\Rule\\Rte\\')) {
            return 'rte';
        }

        if (str_starts_with($ruleId, 'structured.') || str_contains($className, '\\Rule\\Structured\\')) {
            return 'structured';
        }

        if (str_starts_with($ruleId, 'media.')) {
            return 'media';
        }

        if (str_starts_with($ruleId, 'remote.')) {
            return 'remote';
        }

        return 'other';
    }

    private function buildRuleGroupMeta(string $groupKey): array
    {
        $defaults = match ($groupKey) {
            'rte' => ['rte', 'RTE content rules', 'Rich-text editor checks · run on every save', 'RTE'],
            'structured' => ['structured', 'Structured field rules', 'Records, fields and file references · run on every save', 'Structured'],
            'media' => ['media', 'Media and embed rules', 'Images, video, audio and embeds · run on every save', 'Media'],
            'remote' => ['remote', 'Remote / frontend rules', 'Frontend crawler checks · run during remote scans', 'Remote'],
            default => ['other', 'Other rules', 'Additional project checks', 'Other'],
        };
        [$key, $title, $subtitle, $category] = $defaults;
        $prefix = 'settings.rules.group.' . $key;

        return [
            'key' => $key,
            'title' => $this->translateWithFallback($prefix . '.title', $title),
            'subtitle' => $this->translateWithFallback($prefix . '.subtitle', $subtitle),
            'category' => $this->translateWithFallback($prefix . '.category', $category),
        ];
    }

    /**
     * Rule names in the Rules tab: English keeps the rule's own message and hint; other backend
     * languages use the translated plain-language title and fix from the rule presentation when one exists.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveRuleCopy(string $ruleId, string $message, string $hint, string $language): array
    {
        $label = $this->buildRuleLabel($message);
        if ($language === 'en') {
            return [$label, $hint];
        }

        $presentation = $this->ruleMetadataPresentationService->present(['rule_id' => $ruleId, 'help' => $message], $language);
        $title = trim((string)($presentation['localizedTitle'] ?? ''));
        $fix = trim((string)($presentation['localizedHowToFix'] ?? ''));

        return [
            $title !== '' ? $this->buildRuleLabel($title) : $label,
            $fix !== '' ? $fix : $hint,
        ];
    }

    private function buildRuleLabel(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return $this->translateWithFallback('settings.rules.defaultLabel', 'Accessibility rule');
        }

        return rtrim($message, '.');
    }

    /**
     * @param list<array<string, mixed>> $siteOptions
     */
    private function resolveDefaultStatementSiteIdentifier(array $siteOptions, string $currentSiteIdentifier): string
    {
        $currentSiteIdentifier = trim($currentSiteIdentifier);
        if ($currentSiteIdentifier !== '') {
            foreach ($siteOptions as $siteOption) {
                $identifier = trim((string)($siteOption['identifier'] ?? ''));
                if ($identifier !== '' && $identifier === $currentSiteIdentifier) {
                    return $identifier;
                }
            }
        }

        foreach ($siteOptions as $siteOption) {
            $identifier = trim((string)($siteOption['identifier'] ?? ''));
            if ($identifier !== '') {
                return $identifier;
            }
        }

        return '';
    }

    /**
     * @param list<array<string, mixed>> $siteOptions
     * @return list<array<string, mixed>>
     */
    private function filterReadableSiteOptions(array $siteOptions): array
    {
        return array_values(array_filter(
            $siteOptions,
            fn (array $siteOption): bool => $this->scopeAccessService->canReadSiteIdentifier((string)($siteOption['identifier'] ?? ''))
        ));
    }

    /**
     * @param list<array<string, mixed>> $siteOptions
     */
    private function resolveFirstStatementCapableSiteIdentifier(array $siteOptions): string
    {
        foreach ($siteOptions as $siteOption) {
            $identifier = trim((string)($siteOption['identifier'] ?? ''));
            if ($identifier === '') {
                continue;
            }

            if ($this->hasStatementGeneratorCapability($this->proStatusResolverService->resolveForSiteIdentifier($identifier))) {
                return $identifier;
            }
        }

        return '';
    }

    private function hasStatementGeneratorCapability(mixed $proStatus): bool
    {
        if (!is_object($proStatus)) {
            return false;
        }

        if (!(bool)($proStatus->valid ?? false)) {
            return false;
        }

        if ((bool)($proStatus->isTrial ?? false)) {
            return false;
        }

        // The Statement Generator consumes completed remote/frontend scan data.
        // Reuse the already established PRO/Agency remote crawler capability so
        // Agency licences with non-canonical plan labels (for example billing
        // interval suffixes) do not get hidden by an overly strict plan-name check.
        if ((bool)($proStatus->hasCrawler ?? false)) {
            return true;
        }

        $plan = strtolower(trim((string)($proStatus->plan ?? '')));
        if ($plan !== '' && preg_match('/(^|[_\-])(pro|agency|enterprise)($|[_\-])/', $plan) === 1) {
            return true;
        }

        if ((bool)($proStatus->hasExportPdf ?? false) || (bool)($proStatus->hasMultiSite ?? false)) {
            return true;
        }

        $features = is_array($proStatus->features ?? null) ? $proStatus->features : [];
        $features = array_map(static fn (mixed $feature): string => strtolower(trim((string)$feature)), $features);

        return array_intersect($features, [
            'accessibility_statement',
            'accessibility_statement_generator',
            'statement_generator',
            'crawler',
            'remote_crawler',
            'frontend_crawler',
            'export_pdf',
            'multi_site',
        ]) !== [];
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
     * @param list<array<string, mixed>> $rulesets
     * @return list<array<string, mixed>>
     */
    private function enrichRulesetsWithSiteLabels(array $rulesets): array
    {
        $siteLabels = [];
        foreach ($this->buildSiteOptions() as $siteOption) {
            $identifier = (string)($siteOption['identifier'] ?? '');
            if ($identifier !== '') {
                $siteLabels[$identifier] = (string)($siteOption['label'] ?? $identifier);
            }
        }

        foreach ($rulesets as &$ruleset) {
            $identifier = (string)($ruleset['site_identifier'] ?? '');
            $ruleset['site_label'] = $siteLabels[$identifier] ?? $identifier;
        }
        unset($ruleset);

        return $rulesets;
    }

    /**
     * The settings sections are pages linked from a navigation list: the current one carries aria-current="page".
     * Precomputed strings, because an inline Fluid ternary in an ARIA attribute resolved wrongly on TYPO3 14.
     *
     * @return array<string, string>
     */
    private function buildSettingsTabCurrentStates(string $activeTab): array
    {
        $states = [];
        foreach (self::AVAILABLE_TABS as $tab) {
            $states[$tab] = $tab === $activeTab ? 'page' : 'false';
        }

        return $states;
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
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function removeAdminOnlyRulesetFields(array $body): array
    {
        foreach (self::ADMIN_ONLY_RULESET_FIELDS as $field) {
            unset($body[$field]);

            if (isset($body['qualityGate']) && is_array($body['qualityGate'])) {
                unset($body['qualityGate'][$field]);
            }

            if (isset($body['remoteScanAccess']) && is_array($body['remoteScanAccess'])) {
                unset($body['remoteScanAccess'][$field]);
            }
        }

        return $body;
    }

    private function normalizeJsonListSetting(mixed $value): string
    {
        if (is_array($value)) {
            $items = array_values(array_filter(array_map(
                static fn (mixed $item): string => trim((string)$item),
                $value
            ), static fn (string $item): bool => $item !== ''));

            return json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        }

        $rawValue = trim((string)$value);
        if ($rawValue === '') {
            return '[]';
        }

        try {
            $decoded = json_decode($rawValue, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $lines = preg_split('/\R/', $rawValue) ?: [];
            $items = array_values(array_filter(array_map('trim', $lines), static fn (string $item): bool => $item !== ''));

            return json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        }

        if (!is_array($decoded)) {
            return '[]';
        }

        $items = array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string)$item),
            $decoded
        ), static fn (string $item): bool => $item !== ''));

        return json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    private function maskSecret(string $secret): string
    {
        $secret = trim($secret);
        if ($secret === '') {
            return '';
        }

        return substr($secret, 0, 8) . str_repeat('•', 24);
    }

    private function jsonListToTextarea(string $json): string
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return '';
        }

        if (!is_array($decoded)) {
            return '';
        }

        $items = array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string)$item),
            $decoded
        ), static fn (string $item): bool => $item !== ''));

        return implode("\n", $items);
    }

    private function resolveBackendUserDisplayName(): string
    {
        $backendUser = $this->backendContextService->getBackendUser();
        if ($backendUser === null) {
            return '';
        }

        $user = is_array($backendUser->user ?? null) ? $backendUser->user : [];
        $realName = trim((string)($user['realName'] ?? ''));
        if ($realName !== '') {
            return $realName;
        }

        return trim((string)($user['username'] ?? ''));
    }

    private function consumeHttpAuthTestQuota(ServerRequestInterface $request): bool
    {
        $serverParams = $request->getServerParams();
        $ip = (string)($serverParams['REMOTE_ADDR'] ?? 'unknown');
        $cacheKey = 'remote_auth_test_' . sha1($ip . '_' . date('YmdHi'));

        try {
            $cache = $this->cacheManager->getCache(ProConstants::CACHE_IDENTIFIER);
            $count = (int)($cache->get($cacheKey) ?: 0);
            if ($count >= 5) {
                return false;
            }
            $cache->set($cacheKey, $count + 1, [], 70);
        } catch (\Throwable) {
            return true;
        }

        return true;
    }

    /**
     * Settings › Scan history (administrators): the period for the frontend scan history stored in this installation,
     * and the review of what it would delete now (LocalHistoryRetentionService — nothing is deleted on its own).
     *
     * @return array<string, mixed>|null
     */
    private function buildHistoryRetentionView(string $activeTab, bool $isAdmin): ?array
    {
        if ($activeTab !== 'history' || !$isAdmin || $this->localHistoryRetentionService === null) {
            return null;
        }
        $setting = $this->localHistoryRetentionService->getSetting();
        $preview = $this->localHistoryRetentionService->preview($setting);
        // Exact UTC instants, as the command prints them and as the deletion compares them: "before 2025-10-20 18:40:12
        // UTC" is the boundary, not the whole day.
        $formatUtc = static fn (int $timestamp): string => LocalHistoryRetentionService::formatUtc($timestamp);

        $bySite = [];
        foreach ($preview['bySite'] as $row) {
            $bySite[] = $row + ['languageLabel' => $this->historyLanguageLabel($row['site'], $row['language'])];
        }

        return [
            'setting' => $setting,
            'options' => array_keys(LocalHistoryRetentionService::OPTIONS),
            'saveUrl' => $this->buildRouteUrl('web_a11y.settingsHistoryRetentionSave'),
            'pruneUrl' => $this->buildRouteUrl('web_a11y.settingsHistoryRetentionPrune'),
            'scans' => $preview['scans'],
            'pages' => $preview['pages'],
            'occurrences' => $preview['occurrences'],
            'oldest' => $formatUtc($preview['oldest']),
            'newest' => $formatUtc($preview['newest']),
            'cutoff' => $preview['cutoff'] !== null ? $formatUtc($preview['cutoff']) : '',
            'reviewedCutoff' => (int)($preview['cutoff'] ?? 0),
            'reviewToken' => $preview['reviewToken'],
            'olderTotal' => $preview['olderTotal'],
            'kept' => array_sum($preview['protected']),
            'protected' => $preview['protected'],
            'bySite' => $bySite,
            'multipleGroups' => count($bySite) > 1,
        ];
    }

    /**
     * Settings › Licence › FREE Remote Preview: per domain (with the readable sites using it) whether FREE is activated
     * (the AQG service's confirmation of the accepted terms, with its UTC acceptance time), and for administrators the
     * activation with the published documents. Only administrators load the published terms (one cached request); editors see the status only.
     *
     * @return array<string, mixed>|null
     */
    private function buildFreePreviewActivationView(string $activeTab, bool $isAdmin, ?Site $site, bool $paidLicenceActive): ?array
    {
        if ($activeTab !== 'licence' || $this->freePreviewActivationService === null) {
            return null;
        }
        $language = FreePreviewStatement::languageFor($this->getBackendLanguageCode());
        $termsResult = $isAdmin ? $this->freePreviewActivationService->getTerms($language) : ['terms' => null, 'error' => ''];
        $terms = $termsResult['terms'];

        $sites = [];
        try {
            $allSites = $this->siteFinder->getAllSites();
        } catch (\Throwable) {
            $allSites = [];
        }
        $selected = $this->resolveLicenceSite($site)?->getIdentifier() ?? '';
        // The AQG service binds an acceptance to installation + domain, so the store keeps one per normalised domain:
        // sites sharing a domain (`/en/`, `www.…/de/`) share one activation and get one row. Only sites the user may
        // read on the site root are listed, so module access alone shows no other site's status.
        $domainGroups = [];
        foreach ($allSites as $candidate) {
            $base = (string)$candidate->getBase();
            $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase($base);
            if ($domain === '' || !$this->scopeAccessService->canReadSite($candidate)) {
                continue;
            }
            $domainGroups[$domain] ??= ['base' => $base, 'identifier' => $candidate->getIdentifier(), 'siteIdentifiers' => []];
            $domainGroups[$domain]['siteIdentifiers'][] = $candidate->getIdentifier();
            if ($candidate->getIdentifier() === $selected) {
                $domainGroups[$domain]['base'] = $base;
                $domainGroups[$domain]['identifier'] = $selected;
            }
        }
        foreach ($domainGroups as $group) {
            sort($group['siteIdentifiers'], SORT_STRING);
            $base = $group['base'];
            $status = $this->freePreviewActivationService->status($base, $terms);
            $acceptance = $status['acceptance'];
            $sites[] = [
                // The site the actions name; the service resolves it to the shared domain.
                'identifier' => $group['identifier'],
                'siteIdentifiers' => $group['siteIdentifiers'],
                'siteLabel' => implode(', ', $group['siteIdentifiers']),
                'base' => $base,
                'domain' => $status['domain'],
                'domainDisplay' => $this->extensionContextService->getDisplayDomain($status['domain']),
                'state' => $status['state'],
                'stateLabel' => $this->translateWithFallback('settings.freePreview.state.' . $status['state'], $status['state']),
                'stateTone' => match ($status['state']) {
                    FreePreviewActivationService::STATE_ACTIVE => 'ok',
                    FreePreviewActivationService::STATE_NOT_ACTIVATED, FreePreviewActivationService::STATE_DISABLED => 'neutral',
                    default => 'warn',
                },
                'notPublic' => $this->freePreviewActivationService->isClearlyNotPublic($base),
                'selected' => in_array($selected, $group['siteIdentifiers'], true),
                'canEnable' => $status['state'] === FreePreviewActivationService::STATE_DISABLED,
                'canDisable' => $status['state'] === FreePreviewActivationService::STATE_ACTIVE,
                'acceptance' => $acceptance instanceof FreeTermsAcceptance ? [
                    'id' => $acceptance->acceptanceId,
                    'termsVersion' => $acceptance->termsVersion,
                    'documents' => $acceptance->documents,
                    'acceptedAt' => $acceptance->acceptedAt,
                    'acceptedAtUtc' => LocalHistoryRetentionService::formatUtc($acceptance->acceptedAtTimestamp),
                    'validUntilUtc' => LocalHistoryRetentionService::formatUtc($acceptance->validUntilTimestamp),
                    'organisation' => $acceptance->organisationName,
                    'acceptedBy' => $acceptance->acceptedByName,
                    'statusCheckedAt' => $acceptance->statusCheckedAt,
                ] : null,
            ];
        }

        $documents = [];
        foreach ($terms?->documentsFor($language) ?? [] as $document) {
            $documents[] = $document + [
                'label' => $document['title'] !== ''
                    ? $document['title']
                    : $this->translateWithFallback('settings.freePreview.document.' . $document['type'], $document['type']),
            ];
        }
        $backendUser = $this->backendContextService->getBackendUser();

        return [
            'canManage' => $isAdmin,
            'sites' => $sites,
            'hasSites' => $sites !== [],
            'multipleSites' => count($sites) > 1,
            'paidLicenceActive' => $paidLicenceActive,
            'termsAvailable' => $terms !== null && $terms->acceptsStatement($language),
            'termsError' => $terms === null ? ($termsResult['error'] !== '' ? $termsResult['error'] : 'not_published') : ($terms->acceptsStatement($language) ? '' : 'statement_mismatch'),
            'termsVersion' => $terms?->version ?? '',
            'documents' => $documents,
            'statement' => FreePreviewStatement::text($language),
            'statementLanguage' => $language,
            'defaultName' => trim((string)($backendUser?->user['realName'] ?? '')),
            'defaultEmail' => trim((string)($backendUser?->user['email'] ?? '')),
            'acceptUrl' => $this->buildRouteUrl('web_a11y.settingsFreePreviewAccept'),
            'toggleUrl' => $this->buildRouteUrl('web_a11y.settingsFreePreviewToggle'),
            'refreshUrl' => $this->buildRouteUrl('web_a11y.settingsFreePreviewRefresh'),
        ];
    }

    /** Records the administrator's acceptance on the AQG service; FREE is active only with its confirmation. */
    public function acceptFreePreviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyAdminOnly($request, 'licence');
        if ($denied !== null) {
            return $denied;
        }
        $body = $this->parseRequestBody($request);
        $site = $this->findSiteByIdentifier((string)($body['site'] ?? ''));
        $code = 'invalid_site';
        if ($site !== null && $this->freePreviewActivationService !== null) {
            $result = $this->freePreviewActivationService->accept(
                (string)$site->getBase(),
                $site->getIdentifier(),
                [
                    'organisation' => (string)($body['organisation'] ?? ''),
                    'name' => (string)($body['acceptedByName'] ?? ''),
                    'email' => (string)($body['acceptedByEmail'] ?? ''),
                    'authorityConfirmed' => (string)($body['acceptTerms'] ?? '') === '1',
                    'termsVersion' => (string)($body['termsVersion'] ?? ''),
                    'language' => $this->getBackendLanguageCode(),
                    'userUid' => $this->getBackendUserUid(),
                ],
                $this->extensionContextService->getExtensionVersion(),
            );
            $code = $result['code'];
        }

        return $this->freePreviewRedirect($request, $code, $code === 'accepted');
    }

    /** Switches FREE scans on or off for a site; the recorded acceptance is never changed. */
    public function toggleFreePreviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyAdminOnly($request, 'licence');
        if ($denied !== null) {
            return $denied;
        }
        $body = $this->parseRequestBody($request);
        $site = $this->findSiteByIdentifier((string)($body['site'] ?? ''));
        $code = $site !== null && $this->freePreviewActivationService !== null
            ? $this->freePreviewActivationService->setEnabled(
                (string)$site->getBase(),
                (string)($body['enabled'] ?? '') === '1',
                $this->freePreviewActivationService->getTerms(FreePreviewStatement::languageFor($this->getBackendLanguageCode()))['terms'],
            )
            : 'invalid_site';

        return $this->freePreviewRedirect($request, $code, in_array($code, ['enabled', 'disabled'], true));
    }

    /** Asks the AQG service for the current state of the site's recorded acceptance. */
    public function refreshFreePreviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyAdminOnly($request, 'licence');
        if ($denied !== null) {
            return $denied;
        }
        $body = $this->parseRequestBody($request);
        $site = $this->findSiteByIdentifier((string)($body['site'] ?? ''));
        $code = $site !== null && $this->freePreviewActivationService !== null
            ? 'status_' . $this->freePreviewActivationService->refresh((string)$site->getBase(), $site->getIdentifier())
            : 'invalid_site';

        return $this->freePreviewRedirect($request, $code, $code === 'status_' . FreeTermsAcceptance::STATUS_VALID);
    }

    private function freePreviewRedirect(ServerRequestInterface $request, string $code, bool $success): ResponseInterface
    {
        $redirectParameters = $this->getA11yModuleReturnParameters($request);
        $redirectParameters['tab'] = 'licence';
        $this->addFlashMessage(
            $this->translateWithFallback('settings.freePreview.flash.' . $code, $this->translateWithFallback('settings.freePreview.flash.rejected', 'Free Remote Preview was not activated.')),
            $success ? ContextualFeedbackSeverity::OK : ContextualFeedbackSeverity::WARNING
        );

        return new RedirectResponse($this->buildRouteUrl('web_a11y.settings', $redirectParameters) . '#aqg-free-preview-activation', 303);
    }

    private function findSiteByIdentifier(string $identifier): ?Site
    {
        try {
            return trim($identifier) !== '' ? $this->siteFinder->getSiteByIdentifier(trim($identifier)) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Settings access, then administrator rights (`canManageAdminOnlySettings`), checked on the server. */
    private function denyAdminOnly(ServerRequestInterface $request, string $tab): ?ResponseInterface
    {
        $accessResponse = $this->denyIfSettingsHidden($request);
        if ($accessResponse !== null) {
            return $accessResponse;
        }
        if (!$this->accessControlService->canManageAdminOnlySettings($this->backendContextService->getBackendUser())) {
            return $this->buildAdminOnlySettingsDeniedResponse($request, $tab);
        }

        return null;
    }

    private function historyLanguageLabel(string $siteIdentifier, int $languageUid): string
    {
        if ($languageUid < 0) {
            return $this->translateWithFallback('settings.history.review.allLanguages', 'All languages');
        }
        try {
            return $this->siteFinder->getSiteByIdentifier($siteIdentifier)->getLanguageById($languageUid)->getTitle()
                . ' (' . $languageUid . ')';
        } catch (\Throwable) {
            return (string)$languageUid;
        }
    }

    public function saveHistoryRetentionAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyHistoryRetention($request);
        if ($denied !== null) {
            return $denied;
        }
        $body = $this->parseRequestBody($request);
        $saved = $this->localHistoryRetentionService?->saveSetting((string)($body['localHistoryRetention'] ?? '')) ?? false;
        $redirectParameters = $this->getA11yModuleReturnParameters($request);
        $redirectParameters['tab'] = 'history';
        if (!$saved) {
            $this->addFlashMessage($this->translate('settings.history.flash.invalid'), ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($this->buildRouteUrl('web_a11y.settings', $redirectParameters), 303);
        }

        return $this->buildSettingsPostResponse($request, $redirectParameters, $this->translate('settings.history.flash.saved'));
    }

    /** Deletes the reviewed scans — only with the confirmation and only while the reviewed number is still current. */
    public function pruneHistoryRetentionAction(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->denyHistoryRetention($request);
        if ($denied !== null) {
            return $denied;
        }
        $body = $this->parseRequestBody($request);
        $redirectParameters = $this->getA11yModuleReturnParameters($request);
        $redirectParameters['tab'] = 'history';
        $service = $this->localHistoryRetentionService;
        if ($service === null || (string)($body['confirmDeletion'] ?? '') !== '1') {
            $this->addFlashMessage($this->translate('settings.history.flash.confirmRequired'), ContextualFeedbackSeverity::WARNING);
            return new RedirectResponse($this->buildRouteUrl('web_a11y.settings', $redirectParameters), 303);
        }
        $deleted = $service->prune($service->getSetting(), (int)($body['reviewedCutoff'] ?? 0), (string)($body['reviewToken'] ?? ''));
        if ($deleted === null) {
            $this->addFlashMessage($this->translate('settings.history.flash.reviewOutdated'), ContextualFeedbackSeverity::WARNING);
            return new RedirectResponse($this->buildRouteUrl('web_a11y.settings', $redirectParameters), 303);
        }

        return $this->buildSettingsPostResponse(
            $request,
            $redirectParameters,
            sprintf($this->translate('settings.history.flash.deleted'), $deleted)
        );
    }

    private function denyHistoryRetention(ServerRequestInterface $request): ?ResponseInterface
    {
        return $this->denyAdminOnly($request, 'history');
    }

    /**
     * A normal form post returns to the settings tab with an error notification; only script requests get JSON.
     */
    private function buildAdminOnlySettingsDeniedResponse(ServerRequestInterface $request, string $tab): ResponseInterface
    {
        $message = $this->translateWithFallback(
            'settings.adminOnly.save',
            'Only administrators can change this configuration. Your changes were not saved.'
        );

        $accept = strtolower($request->getHeaderLine('accept'));
        $requestedWith = strtolower($request->getHeaderLine('x-requested-with'));
        if (str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest') {
            return new JsonResponse([
                'success' => false,
                'code' => 'admin_only_settings_required',
                'message' => $message,
            ], 403);
        }

        $this->addFlashMessage($message, ContextualFeedbackSeverity::ERROR);
        $redirectParameters = $this->getA11yModuleReturnParameters($request);
        $redirectParameters['tab'] = $this->resolveActiveTab($tab);

        return new RedirectResponse($this->buildRouteUrl('web_a11y.settings', $redirectParameters), 303);
    }

    private function buildSettingsPostResponse(
        ServerRequestInterface $request,
        array $redirectParameters,
        ?string $flashMessage = null,
    ): ResponseInterface {
        if ($flashMessage !== null && $flashMessage !== '') {
            $this->addFlashMessage($flashMessage);
        }

        return new RedirectResponse(
            $this->buildRouteUrl('web_a11y.settings', $redirectParameters),
            303
        );
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

        $contentType = strtolower($request->getHeaderLine('content-type'));
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($rawBody, $decodedFormBody);

            return is_array($decodedFormBody) ? $decodedFormBody : [];
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Field labels stored by an earlier discovery can be unresolved label references (TYPO3 14 translation domains such
     * as `frontend.db.tt_content:bodytext` were stored raw). Resolve them for display; fall back to the field name.
     *
     * @param array<string, array<string, mixed>> $fieldGroups
     * @return array<string, array<string, mixed>>
     */
    private function withReadableFieldLabels(array $fieldGroups): array
    {
        $languageService = GeneralUtility::makeInstance(BackendLanguageService::class);
        foreach ($fieldGroups as $table => $group) {
            foreach (is_array($group['fields'] ?? null) ? $group['fields'] : [] as $index => $field) {
                $label = trim((string)($field['field_label'] ?? ''));
                if ($label !== '' && !BackendLanguageService::isLabelReference($label)) {
                    continue;
                }
                $translated = $label !== '' ? trim($languageService->translateRawLabel($label)) : '';
                $fieldGroups[$table]['fields'][$index]['field_label'] = $translated !== '' && $translated !== $label
                    ? $translated
                    : (string)($field['field_name'] ?? $label);
            }
        }

        return $fieldGroups;
    }

    private function resolveActiveTab(string $tab): string
    {
        $tab = trim($tab);

        return in_array($tab, self::AVAILABLE_TABS, true) ? $tab : 'licence';
    }

    /**
     * @param array<string, mixed> $configuration
     */
    /**
     * @param array<string, mixed> $configuration
     * @return bool TRUE when the value reached persistent storage.
     */
    private function persistExtensionConfiguration(array $configuration): bool
    {
        try {
            $this->extensionConfiguration->set('a11y_quality_gate', $configuration);
        } catch (\Throwable $exception) {
            // TYPO3 throws when config/system/settings.php is read-only, which is common on
            // hardened staging and live systems. A write failure must surface as a bounded error,
            // never as an uncaught exception and an "Oops, an error occurred!" page.
            $this->logExtensionConfigurationWriteFailure($exception);

            return false;
        }

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate'] = $configuration;

        return true;
    }

    private function logExtensionConfigurationWriteFailure(\Throwable $exception): void
    {
        try {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(__CLASS__)
                ->error('AQG could not persist its extension configuration.', [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
        } catch (\Throwable) {
            // Logging must never mask the original failure.
        }
    }

    private function saveShowProHintsState(bool $showProHints): void
    {
        $ruleset = $this->rulesetRepository->findOrCreateDefault();
        $currentRulesJson = is_array($ruleset) ? (string)($ruleset['rules_json'] ?? '') : '';
        $rulesJson = $this->ruleConfigurationService->encodeRulesJsonWithShowProHints(
            $currentRulesJson,
            $showProHints,
        );

        $this->rulesetRepository->saveRulesJsonForDefault($rulesJson);
    }

    private function submittedBoolean(mixed $value): bool
    {
        if (is_array($value)) {
            $value = end($value);
        }

        $normalized = strtolower(trim((string)$value));

        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
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

    private function resolveSafeHttpAuthTestUrl(?Site $site): string
    {
        if (!$site instanceof Site) {
            return '';
        }

        $siteUrl = trim((string)$site->getBase());
        $parts = parse_url($siteUrl);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = trim((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || $this->isLocalOrPrivateHost($host)) {
            return '';
        }

        return $siteUrl;
    }

    private function isLocalOrPrivateHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $records = dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
            $ips = [];
            foreach ($records as $record) {
                $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
                if ($ip !== '') {
                    $ips[] = $ip;
                }
            }
        }

        if ($ips === []) {
            return true;
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
        }

        return false;
    }

    private function resolveRemoteAccessSiteUrl(?Site $site): string
    {
        if ($site instanceof Site) {
            return (string)$site->getBase();
        }

        try {
            foreach ($this->siteFinder->getAllSites() as $candidate) {
                if ($candidate instanceof Site) {
                    return (string)$candidate->getBase();
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function resolveValidationDomain(?Site $site): string
    {
        $site = $this->resolveLicenceSite($site);

        return $site instanceof Site
            ? $this->extensionContextService->getNormalizedDomainFromSiteBase((string)$site->getBase())
            : '';
    }

    /**
     * The site a licence is validated and displayed for: the request's own site, otherwise the first
     * configured site with a host. Validate (Ajax, which carries no page context) and the Settings
     * status must resolve the same site, or a key Validate has just accepted renders as INACTIVE.
     */
    private function resolveLicenceSite(?Site $site): ?Site
    {
        if ($site instanceof Site) {
            return $site;
        }

        try {
            foreach ($this->siteFinder->getAllSites() as $candidate) {
                if (
                    $candidate instanceof Site
                    && $this->extensionContextService->getNormalizedDomainFromSiteBase((string)$candidate->getBase()) !== ''
                ) {
                    return $candidate;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Settings opened without a page or site (e.g. from the Overview's "Select a page" state) has no
     * site identifier. Resolving an empty one validated the persisted key for an empty domain, which
     * the API always rejects, so a valid saved key rendered as INACTIVE until a page was selected.
     */
    private function resolveLicenceStatus(string $siteIdentifier): object
    {
        if ($siteIdentifier !== '') {
            return $this->proStatusResolverService->resolveForSiteIdentifier($siteIdentifier);
        }

        return $this->proStatusResolverService->resolveForSite($this->resolveLicenceSite(null));
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
            'domain_not_activated' => $this->translate('settings.licence.validation.reason.domain_not_activated'),
            'domain_not_detected' => $this->translate('settings.licence.validation.reason.domain_not_detected'),
            'project_mismatch', 'licence_project_mismatch' => $this->translate('settings.licence.validation.reason.licence_project_mismatch'),
            'project_limit_reached' => $this->translate('settings.licence.validation.reason.project_limit_reached'),
            'project_removed', 'licence_project_removed' => $this->translate('settings.licence.validation.reason.project_removed'),
            'rate_limited' => $this->translate('settings.licence.validation.reason.rate_limited'),
            'trial_expired' => $this->translate('settings.licence.validation.reason.trial_expired'),
            'trial_domain_mismatch' => $this->translate('settings.licence.validation.reason.trial_domain_mismatch'),
            'trial_project_mismatch' => $this->translate('settings.licence.validation.reason.trial_project_mismatch'),
            'trial_revoked' => $this->translate('settings.licence.validation.reason.trial_revoked'),
            'trial_not_verified' => $this->translate('settings.licence.validation.reason.trial_not_verified'),
            'api_unreachable' => $this->translate('settings.licence.validation.reason.api_unreachable'),
            default => $this->translate('settings.licence.validation.invalidFallback'),
        };
    }


    private function syncScannerTokenToConfiguredSites(string $token): void
    {
        $token = trim($token);
        if ($token === '') {
            return;
        }

        foreach ($this->siteResolutionService->getAllSites() as $site) {
            try {
                $siteIdentifier = trim($site->getIdentifier());
            } catch (\Throwable) {
                continue;
            }

            if ($siteIdentifier === '') {
                continue;
            }

            $this->rulesetRepository->saveScannerTokenForSiteOrDefault($siteIdentifier, $token);
        }
    }

    private function hasRemoteScanAccessCapability(string $siteIdentifier, ServerRequestInterface $request): bool
    {
        if ($siteIdentifier === '') {
            $pageUid = $this->requestParameterService->getPageUidOrZero($request);
            $site = $this->resolveSiteForPage($request, $pageUid);
            $siteIdentifier = $site?->getIdentifier() ?? '';
        }

        if ($siteIdentifier !== '') {
            $proStatus = $this->proStatusResolverService->resolveForSiteIdentifier($siteIdentifier);

            return (bool)($proStatus->valid ?? false) && (bool)($proStatus->hasCrawler ?? false);
        }

        // AJAX requests such as scanner-token generation may not carry a page id.
        // In that case, accept any configured site that has the remote crawler capability.
        return $this->proStatusResolverService->hasCrawlerForAnySite();
    }

    /**
     * The saved key never reaches the page: administrators see its prefix and last characters, which identify
     * the key without disclosing it in screen shares or support screenshots, and a short SHA-256 fingerprint
     * lets scripts and tests recognise which key is stored.
     *
     * @return array{hasLicenceKey:bool,maskedLicenceKey:string,licenceKeyEnding:string,licenceKeyFingerprint:string}
     */
    private function buildLicenceViewData(string $storedLicenceKey, bool $isAdmin): array
    {
        $storedLicenceKey = trim($storedLicenceKey);
        $shown = $isAdmin && $storedLicenceKey !== '';
        $ending = $shown && mb_strlen($storedLicenceKey) > 12 ? mb_substr($storedLicenceKey, -4) : '';
        $prefix = $shown && preg_match('/^aqg_[a-z]+_/', $storedLicenceKey, $matches) === 1 ? $matches[0] : '';

        return [
            'hasLicenceKey' => $storedLicenceKey !== '',
            'maskedLicenceKey' => $shown ? $prefix . str_repeat('•', 8) . $ending : '',
            'licenceKeyEnding' => $ending,
            'licenceKeyFingerprint' => $shown ? substr(hash('sha256', $storedLicenceKey), 0, 16) : '',
        ];
    }

    /**
     * The licence form starts with an empty key field: a typed key replaces the saved one, "Remove the saved
     * key" clears it, and an empty field keeps it.
     *
     * @param array<string, mixed> $body
     */
    private function resolveSubmittedLicenceKey(array $body, string $storedLicenceKey): string
    {
        if ((string)($body['licenceKeyRemove'] ?? '') === '1') {
            return '';
        }

        $submitted = trim((string)($body['licenceKey'] ?? ''));

        return $submitted !== '' ? $submitted : trim($storedLicenceKey);
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
