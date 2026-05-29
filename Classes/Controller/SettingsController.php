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
use Priebera\A11yQualityGate\Rule\RuleRegistry;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
use Priebera\A11yQualityGate\Service\SecretEncryptionService;
use Priebera\A11yQualityGate\Service\ScannerAccessTokenService;
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
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
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
        private readonly RequestFactory $requestFactory,
        private readonly CacheManager $cacheManager,
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
        $this->pageRenderer->loadJavaScriptModule('@priebera/a11y-quality-gate/backend/settings-quality-gate.js');
        $this->pageRenderer->loadJavaScriptModule('@priebera/a11y-quality-gate/backend/settings-remote-access.js');

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
        $isAdmin = $this->accessControlService->canManageAdminOnlySettings($this->backendContextService->getBackendUser());
        if ($activeTab === 'remote_access' && !$isAdmin) {
            $activeTab = 'licence';
        }

        $proStatus = $this->proStatusResolverService->resolveForSiteIdentifier(
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
        $remoteAccessScannerToken = trim((string)($qualityGateRuleset['scanner_token'] ?? ''));
        $remoteAccessMaskedScannerToken = $remoteAccessScannerToken !== '' ? 'Generated token is stored securely' : '';
        $remoteAccessHasHttpAuthPassword = trim((string)($remoteAccessRuleset['http_auth_pass'] ?? '')) !== '';

        $siteRulesets = $this->enrichRulesetsWithSiteLabels(
            $this->rulesetRepository->findSiteSpecificRulesets()
        );
        $siteOptions = $this->buildSiteOptions();
        $siteOptionsWithoutDefault = array_values(array_filter(
            $siteOptions,
            static fn (array $siteOption): bool => (string)($siteOption['identifier'] ?? '') !== ''
        ));
        $usedSiteIdentifiers = array_map(
            static fn (array $ruleset): string => (string)($ruleset['site_identifier'] ?? ''),
            $siteRulesets
        );
        $availableSiteOptions = array_values(array_filter(
            $siteOptionsWithoutDefault,
            static fn (array $siteOption): bool => !in_array((string)($siteOption['identifier'] ?? ''), $usedSiteIdentifiers, true)
        ));

        $licenceKey = $this->getExtensionConfigurationString('licenceKey');
        $showProHints = $this->getExtensionConfigurationBool('showProHints', true);

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
            'remoteAccessMaskedScannerToken' => $remoteAccessMaskedScannerToken,
            'remoteAccessScannerToken' => '',
            'remoteAccessHasScannerToken' => $remoteAccessScannerToken !== '',
            'remoteAccessHasHttpAuthPassword' => $remoteAccessHasHttpAuthPassword,
            'remoteAccessSaveUrl' => $saveUrl,
            'remoteAccessRegenerateTokenUrl' => $this->buildRouteUrl('ajax_a11y_regenerate_scanner_token'),
            'remoteAccessTestHttpAuthUrl' => $this->buildRouteUrl('ajax_a11y_test_http_auth'),
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
            'licenceKey' => $licenceKey,
            'showProHints' => $showProHints,
            'pricingUrl' => 'https://typo3.priebera.sk/pricing',
            'trialUrl' => 'https://typo3.priebera.sk/trial',
            'portalUrl' => 'https://typo3.priebera.sk/portal',
            'licensingDocsUrl' => 'https://typo3.priebera.sk/docs',
            'contactUrl' => 'https://typo3.priebera.sk/contact',
            'settingsTabUrls' => $this->buildSettingsTabUrls($request, $selectedRulesetSite),
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
        $isAdmin = $this->accessControlService->canManageAdminOnlySettings($this->backendContextService->getBackendUser());
        if (!$isAdmin) {
            $body = $this->removeAdminOnlyRulesetFields($body);
        }

        $selectedRulesetSite = trim((string)($body['rulesetSite'] ?? ''));
        $activeTab = $this->resolveActiveTab((string)($body['tab'] ?? 'fields'));
        if ($activeTab === 'remote_access' && !$isAdmin) {
            $activeTab = 'licence';
        }

        if ((string)($body['fieldsFormSubmitted'] ?? '') === '1') {
            $enabledFields = is_array($body['enabledFields'] ?? null) ? $body['enabledFields'] : [];
            $this->fieldConfigRepository->saveEnabledState($enabledFields);
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
                scannerToken: $selectedRulesetSite === '' ? trim((string)($existingRemoteRuleset['scanner_token'] ?? '')) : '',
                httpAuthUser: trim((string)($remoteAccessData['http_auth_user'] ?? '')),
                encryptedHttpAuthPass: $encryptedHttpAuthPass,
                excludedPatterns: $this->normalizeJsonListSetting($remoteAccessData['excluded_patterns'] ?? '[]'),
                cookieAcceptSelectors: $this->normalizeJsonListSetting($remoteAccessData['cookie_accept_selectors'] ?? '[]'),
                crawlPriorityUrls: $this->normalizeJsonListSetting($remoteAccessData['crawl_priority_urls'] ?? '[]'),
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
            if ((string)($body['rulesManagementFormSubmitted'] ?? '') === '1') {
                $this->saveRuleManagementState($body);
            }
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

    public function regenerateScannerTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->backendContextService->getBackendUser();
        if (!$this->accessControlService->canManageAdminOnlySettings($backendUser)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Only administrators can regenerate the scanner token.',
            ], 403);
        }

        $body = $this->parseRequestBody($request);
        $rulesetSite = trim((string)($body['rulesetSite'] ?? ''));
        if (!$this->hasRemoteScanAccessCapability($rulesetSite, $request)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Remote scan access is available in AQG PRO or Trial. Add a licence key or start a trial to configure remote scanning.',
            ], 403);
        }

        $token = $this->scannerAccessTokenService->generateAndSaveDefaultToken();

        return new JsonResponse([
            'success' => true,
            'token' => $token,
            'maskedToken' => $this->maskSecret($token),
            'message' => 'Scanner token regenerated.',
        ]);
    }

    public function testHttpAuthAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->backendContextService->getBackendUser();
        if (!$this->accessControlService->canManageAdminOnlySettings($backendUser)) {
            return new JsonResponse([
                'success' => false,
                'ok' => false,
                'message' => 'Only administrators can test HTTP Basic Auth.',
            ], 403);
        }

        $body = $this->parseRequestBody($request);
        $rulesetSite = trim((string)($body['rulesetSite'] ?? ''));
        if (!$this->hasRemoteScanAccessCapability($rulesetSite, $request)) {
            return new JsonResponse([
                'success' => false,
                'ok' => false,
                'status' => 403,
                'message' => 'Remote scan access is available in AQG PRO or Trial. Add a licence key or start a trial to configure remote scanning.',
            ], 403);
        }

        if (!$this->consumeHttpAuthTestQuota($request)) {
            return new JsonResponse([
                'success' => false,
                'ok' => false,
                'status' => 429,
                'message' => 'Too many test requests. Please wait a minute and try again.',
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

        if ($siteUrl === '' || $username === '' || $password === '') {
            return new JsonResponse([
                'success' => false,
                'ok' => false,
                'status' => 0,
                'message' => 'Select a configured site and enter username and password before testing.',
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
                    ? 'Connection OK — the crawler reached the frontend with these credentials.'
                    : ($status === 401 || $status === 403
                        ? 'Authentication failed — the username or password is wrong.'
                        : 'Connection failed. Please check the credentials or the frontend protection.'),
            ]);
        } catch (\Throwable) {
            return new JsonResponse([
                'success' => true,
                'ok' => false,
                'status' => 0,
                'message' => 'Connection failed. Please check the credentials or the frontend protection.',
            ]);
        }
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

        foreach ($this->ruleRegistry->getAll() as $rule) {
            $ruleId = $rule->getRuleId();
            $enabled = !isset($disabledLookup[$ruleId]);
            $groupKey = $this->resolveRuleGroupKey($ruleId, get_class($rule));
            $group = $this->buildRuleGroupMeta($groupKey);

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
                'label' => $this->buildRuleLabel($rule->getMessage()),
                'description' => $rule->getHint(),
                'severity' => strtolower($rule->getDefaultSeverity()->name),
                'severityLabel' => ucfirst(strtolower($rule->getDefaultSeverity()->name)),
                'category' => $group['category'],
                'enabled' => $enabled,
                'searchText' => strtolower($ruleId . ' ' . $rule->getMessage() . ' ' . $rule->getHint() . ' ' . $group['category']),
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
        return match ($groupKey) {
            'rte' => [
                'key' => 'rte',
                'title' => 'RTE content rules',
                'subtitle' => 'Rich-text editor checks · run on every save',
                'category' => 'RTE',
            ],
            'structured' => [
                'key' => 'structured',
                'title' => 'Structured field rules',
                'subtitle' => 'Records, fields and file references · run on every save',
                'category' => 'Structured',
            ],
            'media' => [
                'key' => 'media',
                'title' => 'Media & embed rules',
                'subtitle' => 'Images, video, audio and embeds · run on every save',
                'category' => 'Media',
            ],
            'remote' => [
                'key' => 'remote',
                'title' => 'Remote / frontend rules',
                'subtitle' => 'Frontend crawler checks · run during remote scans',
                'category' => 'Remote',
            ],
            default => [
                'key' => 'other',
                'title' => 'Other rules',
                'subtitle' => 'Additional project checks',
                'category' => 'Other',
            ],
        };
    }

    private function buildRuleLabel(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return 'Accessibility rule';
        }

        return rtrim($message, '.');
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

    private function hasRemoteScanAccessCapability(string $siteIdentifier, ServerRequestInterface $request): bool
    {
        if ($siteIdentifier === '') {
            $pageUid = $this->requestParameterService->getPageUidOrZero($request);
            $site = $this->resolveSiteForPage($request, $pageUid);
            $siteIdentifier = $site?->getIdentifier() ?? '';
        }

        $proStatus = $this->proStatusResolverService->resolveForSiteIdentifier($siteIdentifier);

        return (bool)($proStatus->valid ?? false) && (bool)($proStatus->hasCrawler ?? false);
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
