<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Service\RemoteResultAuthorization;
use Priebera\A11yQualityGate\Service\RemoteResultAuthorizationService;
use Priebera\A11yQualityGate\Service\RemoteResultsTooLargeException;
use Priebera\A11yQualityGate\Configuration\PublicLinkProvider;
use Priebera\A11yQualityGate\Domain\Repository\FieldConfigRepository;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\FreePreview\FreeRemotePreviewService;
use Priebera\A11yQualityGate\FreePreview\FreeSubmitIntentService;
use Priebera\A11yQualityGate\FreePreview\PublicSiteAddressClassifier;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanAccessSettingsService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanErrorPresenter;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanPersistenceService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanRecoveryService;
use Priebera\A11yQualityGate\Pro\Service\RemoteOccurrenceTotalsService;
use Priebera\A11yQualityGate\Pro\Service\TrialUsageService;
use Priebera\A11yQualityGate\Scan\RenderedCoverage;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendJavaScriptModuleService;
use Priebera\A11yQualityGate\Service\ExportUrlBuilderService;
use Priebera\A11yQualityGate\Pro\ViewModel\LicenceGuidance;
use Priebera\A11yQualityGate\Pro\ViewModel\RemoteEmptyState;
use Priebera\A11yQualityGate\Pro\ViewModel\RemoteOccurrenceTotals;
use Priebera\A11yQualityGate\Pro\ViewModel\RemoteScanSignal;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\FieldConfigurationBootstrapService;
use Priebera\A11yQualityGate\Service\FrontendPageUrlService;
use Priebera\A11yQualityGate\Service\LocalOverviewScanSelection;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\RemoteReportingSummaryService;
use Priebera\A11yQualityGate\Service\RemoteScanHistoryService;
use Priebera\A11yQualityGate\Service\RemoteScanPairingService;
use Priebera\A11yQualityGate\Service\ScanPermissionService;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Service\SiteLanguageService;
use Priebera\A11yQualityGate\UpdateNotice\UpdateNoticeService;
use Priebera\A11yQualityGate\Utility\BackendTimeUtility;
use Priebera\A11yQualityGate\Utility\PaginationUtility;
use Priebera\A11yQualityGate\Utility\RuleAnchorUtility;
use Priebera\A11yQualityGate\Utility\ScanUrlUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsController]
final class OverviewController extends AbstractBackendModuleController
{
    /** Rows per page of Overview › Frontend scan history › all stored scans. */
    private const STORED_HISTORY_PAGE_SIZE = 25;

    private const LOCAL_PER_PAGE = 25;
    private const REMOTE_PER_PAGE = 25;
    private const REMOTE_FAILED_PER_PAGE = 25;
    private const MAX_VISIBLE_PAGINATION_ITEMS = 5;

    private const API_SUMMARY_TTL = 300;
    private const API_SUMMARY_FAILURE_TTL = 60;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        IconFactory $iconFactory,
        BackendContextService $backendContextService,
        SiteResolutionService $siteResolutionService,
        RequestParameterService $requestParameterService,
        private readonly ScanRepository $scanRepository,
        private readonly IssueRepository $issueRepository,
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly RulesetRepository $rulesetRepository,
        private readonly PageRenderer $pageRenderer,
        private readonly AccessControlService $accessControlService,
        private readonly ScanStatusService $scanStatusService,
        private readonly SiteLanguageService $siteLanguageService,
        private readonly FieldConfigRepository $fieldConfigRepository,
        private readonly ProCrawlerService $proCrawlerService,
        private readonly RemoteScanPersistenceService $remoteScanPersistenceService,
        private readonly RemoteIssueRepository $remoteIssueRepository,
        private readonly RemoteReportingSummaryService $remoteReportingSummaryService,
        private readonly RemoteScanHistoryService $remoteScanHistoryService,
        private readonly ExtensionContextService $extensionContextService,
        private readonly BackendJavaScriptModuleService $backendJavaScriptModuleService,
        private readonly ProStatusResolverService $proStatusResolverService,
        private readonly ExportUrlBuilderService $exportUrlBuilderService,
        private readonly FrontendPageUrlService $frontendPageUrlService,
        private readonly FreeRemotePreviewService $freeRemotePreviewService,
        private readonly FreeSubmitIntentService $freeSubmitIntentService,
        private readonly PublicLinkProvider $publicLinkProvider,
        private readonly FieldConfigurationBootstrapService $fieldConfigurationBootstrapService,
        private readonly ScopeAccessService $scopeAccessService,
        private readonly RemoteScanErrorPresenter $remoteScanErrorPresenter,
        private readonly RemoteScanPairingService $remoteScanPairingService,
        private readonly ?UpdateNoticeService $updateNoticeService = null,
        private readonly ?PublicSiteAddressClassifier $publicSiteAddressClassifier = null,
        private readonly ?ProSettings $proSettings = null,
        private readonly ?RemoteScanRecoveryService $remoteScanRecoveryService = null,
        private readonly ?ScanPermissionService $scanPermissionService = null,
        private readonly ?RemoteScanAccessSettingsService $remoteScanAccessSettingsService = null,
        private readonly ?TrialUsageService $trialUsageService = null,
        private readonly ?RemoteOccurrenceTotalsService $remoteOccurrenceTotalsService = null,
        private readonly ?\Priebera\A11yQualityGate\Domain\Repository\Contract\RemediationItemRepositoryInterface $remediationItems = null,
        private readonly ?\Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService $remediationAccess = null,
        private readonly ?\Priebera\A11yQualityGate\Remediation\Workflow\RemediationOwnerRuleValidator $remediationOwnerRules = null,
        private readonly ?\TYPO3\CMS\Core\Cache\CacheManager $cacheManager = null,
        private readonly ?RemoteResultAuthorizationService $remoteResultAuthorizationService = null,
        private readonly ?\Priebera\A11yQualityGate\Pro\Service\RemoteScanTreeCoverageService $treeCoverageService = null,
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
        $moduleTemplate = $this->createModuleTemplate($request);
        $currentPageUid = $this->requestParameterService->getPageUidOrZero($request);
        $site = $this->resolveSiteForPage($request, $currentPageUid);

        $this->backendJavaScriptModuleService->loadBackendModule(
            $this->pageRenderer,
            $site
        );
        $updateNotice = $this->updateNoticeService?->buildForCurrentUser();
        if ($updateNotice !== null) {
            $this->backendJavaScriptModuleService->loadVersionedModule($this->pageRenderer, '@priebera/a11y-quality-gate/backend/update-notice.js');
        }
        // Every Overview state renders the notice, the empty states included.
        $moduleTemplate->assign('updateNotice', $updateNotice);

        $siteIdentifier = $site?->getIdentifier() ?? '';
        $siteBase = $site !== null ? (string)$site->getBase() : '';
        $returnParameters = $this->getA11yModuleReturnParameters($request);
        $queryParams = $request->getQueryParams();
        $selectedRemoteScanUid = max(0, (int)($queryParams['remoteScanUid'] ?? 0));

        if ($currentPageUid <= 0 || $site === null) {
            $this->configureDocHeader(
                $moduleTemplate,
                $returnParameters
            );

            $pageSiteContext = $this->siteResolutionService->describePageSiteContext($currentPageUid);
            $emptyState = match (true) {
                $currentPageUid <= 0 => [
                    'title' => $this->translateWithFallback('overview.emptyState.selectPage.title', 'Select a page to start'),
                    'body' => $this->translateWithFallback('overview.emptyState.selectPage.body', 'Choose a page in the TYPO3 page tree to open accessibility results for that context.'),
                    'hint' => $this->translateWithFallback('overview.emptyState.selectPage.hint', 'Site roots show the full overview. Content pages show page-specific issues and scan actions.'),
                    'stepsTitle' => $this->translateWithFallback('overview.emptyState.selectPage.stepsTitle', 'How AQG works'),
                    'steps' => $this->buildFirstUseSteps(),
                ],
                // A page is selected, so "select a page" would be wrong: it lacks a Site Configuration in its rootline.
                $pageSiteContext === SiteResolutionService::PAGE_CONTEXT_OUTSIDE_SITE => [
                    'title' => $this->translateWithFallback('overview.emptyState.pageOutsideSite.title', 'This page is not part of a TYPO3 Site'),
                    'body' => $this->translateWithFallback('overview.emptyState.pageOutsideSite.body', 'AQG works per TYPO3 Site: the Site Configuration tells it the site\'s base URL and languages. Neither the selected page nor any page above it has a Site Configuration.'),
                    'hint' => $this->translateWithFallback('overview.emptyState.pageOutsideSite.hint', 'Create a Site Configuration for the root page of this page tree in Site Management › Sites, or select a page inside an existing site.'),
                ],
                default => [
                    'title' => $this->translateWithFallback('overview.emptyState.noSite.title', 'No site context selected'),
                    'body' => $this->translateWithFallback('overview.emptyState.noSite.body', 'AQG needs a valid site context before it can show content scans, frontend scan results and page-level issues.'),
                    'hint' => $this->translateWithFallback('overview.emptyState.noSite.hint', 'Tip: Select a page inside a configured TYPO3 site root.'),
                ],
            };

            $moduleTemplate->assignMultiple([
                'siteIdentifier' => $siteIdentifier,
                'currentPageUid' => $currentPageUid,
                'currentLanguageUid' => 0,
                'hasPageContext' => false,
                'emptyState' => $emptyState,
                'hasLanguageOptions' => false,
                'languageOptionCount' => 0,
                'availableLanguageCount' => 0,
            ]);

            return $moduleTemplate->renderResponse('Overview/Index');
        }

        // The Overview lists results of the whole site, so it needs read access to the site root; a
        // page that the user may read but whose site root is outside their mounts has its own Page Detail.
        if (!$this->scopeAccessService->canReadSite($site)) {
            $this->configureDocHeader($moduleTemplate, $returnParameters);
            $pageIsReadable = $this->scopeAccessService->canReadPage($currentPageUid)
                && $this->scopeAccessService->isPageInSite($currentPageUid, $site);

            $moduleTemplate->assignMultiple([
                'siteIdentifier' => '',
                'currentPageUid' => $currentPageUid,
                'currentLanguageUid' => 0,
                'hasPageContext' => false,
                'emptyState' => [
                    'title' => $this->translateWithFallback('overview.emptyState.siteAccess.title', 'Site results need access to the site root'),
                    'body' => $pageIsReadable
                        ? $this->translateWithFallback('overview.emptyState.siteAccess.body', 'The Overview lists results for the whole site. Your access covers this page, so open its findings instead.')
                        : $this->translateWithFallback('overview.emptyState.noAccess.body', 'You do not have access to this page.'),
                    'actionUrl' => $pageIsReadable
                        ? $this->buildRouteUrl('web_a11y.pageDetail', ['id' => $currentPageUid, 'pageUid' => $currentPageUid])
                        : '',
                    'actionLabel' => $this->translateWithFallback('overview.emptyState.siteAccess.action', 'Open page findings'),
                ],
                'hasLanguageOptions' => false,
                'languageOptionCount' => 0,
                'availableLanguageCount' => 0,
            ]);

            return $moduleTemplate->renderResponse('Overview/Index');
        }
        // Site root access opens the Overview, but each listed page, count and total still needs show access to
        // that page: pages the user may not show are left out of the list, its pagination and the totals.
        $readablePagesClause = $this->scopeAccessService->readablePagesClause();

        $localQuery = trim((string)($queryParams['localQuery'] ?? ''));
        $remoteQuery = trim((string)($queryParams['remoteQuery'] ?? ''));
        $remoteFailedQuery = trim((string)($queryParams['remoteFailedQuery'] ?? ''));
        $remoteRule = $this->requestParameterService->getRuleId($request, 'remoteRule');

        $availableLanguages = $site !== null ? $this->siteLanguageService->getLanguagesForSiteObject($site) : [];
        if ($site !== null && $currentPageUid > 0) {
            $availableLanguages = $this->siteLanguageService->filterLanguagesAvailableForPage($currentPageUid, $availableLanguages);
        }
        if ($availableLanguages !== [] && !$this->hasExplicitLanguageParameter($request)) {
            $this->backendContextService->getBackendUser()?->setAndSaveSessionData(
                'tx_a11y_quality_gate_language',
                0
            );

            $redirectParameters = $this->getA11yModuleReturnParameters($request);
            unset($redirectParameters['languageUid']);
            $redirectParameters['language'] = 0;

            return new RedirectResponse(
                $this->buildRouteUrl('web_a11y', $redirectParameters),
                303
            );
        }

        $currentLanguageUid = $this->resolveCurrentLanguageUid($request, $availableLanguages);
        $languageUrlParameters = $this->buildLanguageUrlParameters($request);
        $languageOptions = $this->buildOverviewLanguageOptions($request, $this->issueRepository, $siteIdentifier, $availableLanguages, $currentLanguageUid, $readablePagesClause);
        $currentLanguageOption = $this->resolveCurrentLanguageOption($languageOptions);

        $currentLocalPage = max(1, (int)($queryParams['localPage'] ?? 1));
        $currentRemotePage = max(1, (int)($queryParams['remotePage'] ?? 1));
        $currentRemoteFailedPage = max(1, (int)($queryParams['remoteFailedPage'] ?? 1));

        if ($siteIdentifier !== '' && $siteBase !== '') {
            $this->recoverPendingRemoteScanPersistence($siteIdentifier, $siteBase);
            // NEW-12: a scan TYPO3 gave up on (status unreadable for too long) is checked once more while it is recent.
            $locallyFailed = $this->remoteScanRepository->findLocallyFailedScanBySite($siteIdentifier, RemoteScanRecoveryService::LOCAL_FAILURE_RECHECK_WINDOW);
            if (is_array($locallyFailed) && $this->remoteScanRecoveryService instanceof RemoteScanRecoveryService) {
                $this->remoteScanRecoveryService->recoverScanIfNeeded($locallyFailed, $siteBase);
            }
        }

        $siteRootPid = $site !== null ? (int)$site->getRootPageId() : 0;
        $isPageContext = $currentPageUid > 0 && $siteRootPid > 0 && $currentPageUid !== $siteRootPid;

        $proStatus = $this->proStatusResolverService->resolveForSite($site);
        $isFreePreview = !(bool)($proStatus->valid ?? false) || !(bool)($proStatus->hasCrawler ?? false);

        $currentPageIssueCounts = [
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
            'needs_review' => 0,
        ];

        if ($isPageContext && $siteIdentifier !== '') {
            $currentPageIssueCounts = $this->issueRepository->countOpenBySeverity(
                $currentPageUid,
                $siteIdentifier,
                $currentLanguageUid,
                '',
                true,
            );
        }

        $lastSubtreeScan = $siteIdentifier !== ''
            ? $this->scanRepository->findLastCompletedSubtreeScan($siteIdentifier, $currentLanguageUid)
            : null;
        $lastPageScan = $siteIdentifier !== '' && $isPageContext
            ? $this->scanRepository->findLastCompletedPageScan($siteIdentifier, $currentPageUid, $currentLanguageUid)
            : null;
        // The status describes the site scan (or, on a page, the newer of it and the page's own scan); without either it
        // falls back to the site's newest content scan of any scope, so it never says "No scan on record" above
        // open issues that page scans found. The scope is named in the status.
        $localScanSelection = LocalOverviewScanSelection::select(
            $lastSubtreeScan,
            $lastPageScan,
            $siteIdentifier !== '' && $lastSubtreeScan === null && $lastPageScan === null
                ? $this->scanRepository->findLastCompletedScan($siteIdentifier, $currentLanguageUid)
                : null,
            $isPageContext
        );
        $lastScan = $localScanSelection->scan;
        $lastScanScopeLabel = $this->buildLocalScanScopeLabel($localScanSelection);

        $lastScan = $this->withFormattedScanTimestamps($lastScan);
        $lastScan = self::withoutScanWideCounts($lastScan, $readablePagesClause);

        $currentPageDetailUrl = '';
        $currentPageUrl = '';

        if ($currentPageUid > 0 && $siteIdentifier !== '') {
            $currentPageDetailUrl = $this->buildRouteUrl('web_a11y.pageDetail', [
                'id' => $currentPageUid,
                'pageUid' => $currentPageUid,
                'site' => $siteIdentifier,
                'language' => $currentLanguageUid,
            ]);

            if ($site !== null) {
                $currentPageUrl = $isFreePreview
                    ? $this->frontendPageUrlService->resolvePublicForPage($site, $currentPageUid, $currentLanguageUid)
                    : $this->frontendPageUrlService->resolveForPage($site, $currentPageUid, $currentLanguageUid);
            }
        }

        $remoteIsPageContext = $isPageContext || ($isFreePreview && $currentPageUid > 0);
        // First: a job that finished at the AQG service while nobody watched is read back and stored here. Selecting the
        // displayed result before that showed the previous result for the scan that had just completed.
        $activeRemoteScan = $siteIdentifier !== ''
            ? $this->resolveOverviewActiveRemoteScan($siteIdentifier, $remoteIsPageContext, $currentPageUid, $currentLanguageUid)
            : null;
        $currentPageIsSiteRoot = $currentPageUid > 0 && $currentPageUid === $siteRootPid;
        $remoteScan = $siteIdentifier !== ''
            ? $this->resolveOverviewRemoteScan($siteIdentifier, $remoteIsPageContext, $currentPageUid, $currentLanguageUid, $currentPageUrl, $selectedRemoteScanUid, $isFreePreview, $currentPageIsSiteRoot)
            : null;
        // Sol R03: a scan the user may not read (a page scan of a page deleted, moved or refused since) is not shown.
        if (is_array($remoteScan) && !$this->scopeAccessService->canReadRemoteScan($remoteScan)) {
            $remoteScan = null;
        }
        $remoteScan = $this->withFormattedScanTimestamps($remoteScan);
        $this->logRemoteReportingDebug('AQG remote reporting selected overview scan', [
            'isPageContext' => $isPageContext,
            'currentPageUid' => $currentPageUid,
            'currentLanguageUid' => $currentLanguageUid,
            'currentPageUrl' => $currentPageUrl,
            'remoteScan' => $this->buildRemoteScanDebugContext(is_array($remoteScan) ? $remoteScan : null),
        ]);

        $latestRemotePageScan = null;
        if ($siteIdentifier !== '') {
            if ($remoteIsPageContext && $currentPageUid > 0) {
                $latestRemotePageScan = is_array($remoteScan) && (string)($remoteScan['scan_scope'] ?? '') === 'page'
                    ? $remoteScan
                    : $this->remoteScanRepository->findLastCompletedPageScanByPageOrUrl(
                        $siteIdentifier,
                        $currentPageUid,
                        $currentLanguageUid,
                        $currentPageUrl,
                        $isFreePreview
                    );
            } else {
                $latestRemotePageScan = $this->remoteScanRepository->findLastCompletedPageScanBySite($siteIdentifier, $currentLanguageUid, $isFreePreview);
            }
        }
        $latestRemotePageScan = $this->withFormattedScanTimestamps($latestRemotePageScan);


        $pageStats = [];
        $totalLocalPages = 0;
        $localPagination = PaginationUtility::buildPagination(0, $currentLocalPage, self::LOCAL_PER_PAGE);
        $localPaginationItems = [];

        if ($siteIdentifier !== '') {
            $totalLocalPages = $this->issueRepository->countOpenPageStatsForSite($siteIdentifier, $localQuery, $currentLanguageUid, $readablePagesClause);

            $localPagination = PaginationUtility::buildPagination(
                $totalLocalPages,
                $currentLocalPage,
                self::LOCAL_PER_PAGE
            );

            $pageStats = $this->issueRepository->findOpenPageStatsForSitePaginated(
                $siteIdentifier,
                self::LOCAL_PER_PAGE,
                $localPagination['offset'],
                $localQuery,
                $currentLanguageUid,
                $readablePagesClause
            );

            $localPagination['pageUrls'] = [];
            for ($page = 1; $page <= $localPagination['totalPages']; $page++) {
                $localPagination['pageUrls'][$page] = $this->buildOverviewPaginationUrl(
                    $request,
                    $siteIdentifier,
                    $page,
                    $currentRemotePage,
                    $currentRemoteFailedPage,
                    $localQuery,
                    $remoteQuery,
                    $remoteFailedQuery
                );
            }

            $localPagination['previousUrl'] = $localPagination['hasPrevious']
                ? $this->buildOverviewPaginationUrl(
                    $request,
                    $siteIdentifier,
                    $localPagination['currentPage'] - 1,
                    $currentRemotePage,
                    $currentRemoteFailedPage,
                    $localQuery,
                    $remoteQuery,
                    $remoteFailedQuery
                )
                : null;

            $localPagination['nextUrl'] = $localPagination['hasNext']
                ? $this->buildOverviewPaginationUrl(
                    $request,
                    $siteIdentifier,
                    $localPagination['currentPage'] + 1,
                    $currentRemotePage,
                    $currentRemoteFailedPage,
                    $localQuery,
                    $remoteQuery,
                    $remoteFailedQuery
                )
                : null;

            $localPaginationItems = PaginationUtility::buildPaginationItems(
                $localPagination['currentPage'],
                $localPagination['totalPages'],
                $localPagination['pageUrls'],
                self::MAX_VISIBLE_PAGINATION_ITEMS
            );
        }

        $remotePages = [];
        $remoteFailedPages = [];
        $totalRemotePages = 0;
        $totalRemoteFailedPages = 0;
        $remotePagesArePageScanFallback = false;
        $remotePagesTitle = $this->translateWithFallback('overview.remote.pages.title', 'Affected pages');
        $remotePagesSubtitle = $this->translateWithFallback('overview.remote.pages.subtitle', 'Pages with the most issue types come first. Open a page to review its findings.');
        $remotePagesEmptyTitle = $this->translateWithFallback('overview.remote.pages.emptyTitle', 'No completed site frontend scan yet');
        $remotePagesEmptyBody = $this->translateWithFallback('overview.remote.pages.emptyBody', 'Run a site scan to list the affected pages of this site.');
        $remotePagesCountLabel = $this->translateWithFallback('overview.proSummary.pages', 'pages');

        $remotePagination = PaginationUtility::buildPagination(0, $currentRemotePage, self::REMOTE_PER_PAGE);
        $remoteFailedPagination = PaginationUtility::buildPagination(0, $currentRemoteFailedPage, self::REMOTE_FAILED_PER_PAGE);
        $remotePaginationItems = [];
        $remoteFailedPaginationItems = [];

        if (is_array($remoteScan) && isset($remoteScan['uid'])) {
            $remoteScanUid = (int)$remoteScan['uid'];
            // Sol R05: for users other than administrators, lists, counts and pagination come only from the result
            // pages and findings they may read (RemoteResultAuthorizationService), never from the raw scan.
            $remoteRestriction = $this->remoteRestrictionFor($remoteScan);

            if ($remoteRestriction !== null) {
                $visibleRemotePages = $this->restrictRemotePages(
                    $this->remoteScanRepository->findPagesForScan($remoteScanUid, $remoteQuery, $remoteRule),
                    $remoteRestriction,
                    $remoteRule
                );
                if ($remoteQuery !== '' && $visibleRemotePages === []) {
                    $visibleRemotePages = $this->filterRemoteOverviewPages(
                        $this->enrichRemoteOverviewPagesForQuery(
                            $this->restrictRemotePages($this->remoteScanRepository->findPagesForScan($remoteScanUid, '', $remoteRule), $remoteRestriction, $remoteRule),
                            $site,
                            $currentLanguageUid,
                            $remoteQuery
                        ),
                        $remoteQuery,
                        false
                    );
                }
                $totalRemotePages = count($visibleRemotePages);
                $remotePagination = PaginationUtility::buildPagination($totalRemotePages, $currentRemotePage, self::REMOTE_PER_PAGE);
                $remotePages = $this->withRemoteRowData($this->enrichRemoteOverviewPages(
                    array_slice($visibleRemotePages, $remotePagination['offset'], self::REMOTE_PER_PAGE),
                    $site,
                    $currentLanguageUid
                ), $isFreePreview);

                $visibleFailedPages = $this->restrictRemotePages($this->remoteScanRepository->findFailedPagesForScan($remoteScanUid, $remoteFailedQuery), $remoteRestriction, '');
                if ($remoteFailedQuery !== '' && $visibleFailedPages === []) {
                    $visibleFailedPages = $this->filterRemoteOverviewPages(
                        $this->enrichRemoteOverviewPagesForQuery(
                            $this->restrictRemotePages($this->remoteScanRepository->findFailedPagesForScan($remoteScanUid), $remoteRestriction, ''),
                            $site,
                            $currentLanguageUid,
                            $remoteFailedQuery
                        ),
                        $remoteFailedQuery,
                        true
                    );
                }
                $totalRemoteFailedPages = count($visibleFailedPages);
                $remoteFailedPagination = PaginationUtility::buildPagination($totalRemoteFailedPages, $currentRemoteFailedPage, self::REMOTE_FAILED_PER_PAGE);
                $remoteFailedPages = $this->enrichRemoteOverviewPages(
                    array_slice($visibleFailedPages, $remoteFailedPagination['offset'], self::REMOTE_FAILED_PER_PAGE),
                    $site,
                    $currentLanguageUid
                );
            } else {
                $totalRemotePages = $this->remoteScanRepository->countPagesForScan($remoteScanUid, false, $remoteQuery, $remoteRule);
                $totalRemoteFailedPages = $this->remoteScanRepository->countPagesForScan($remoteScanUid, true, $remoteFailedQuery);

                $remotePagination = PaginationUtility::buildPagination(
                    $totalRemotePages,
                    $currentRemotePage,
                    self::REMOTE_PER_PAGE
                );

                $remoteFailedPagination = PaginationUtility::buildPagination(
                    $totalRemoteFailedPages,
                    $currentRemoteFailedPage,
                    self::REMOTE_FAILED_PER_PAGE
                );

                $remotePages = $this->remoteScanRepository->findPagesForScanPaginated(
                    $remoteScanUid,
                    self::REMOTE_PER_PAGE,
                    $remotePagination['offset'],
                    $remoteQuery,
                    $remoteRule
                );
                $remotePages = $this->enrichRemoteOverviewPages($remotePages, $site, $currentLanguageUid);

                if ($remoteQuery !== '' && $totalRemotePages === 0) {
                    $filteredRemotePages = $this->filterRemoteOverviewPages(
                        $this->enrichRemoteOverviewPagesForQuery(
                            $this->remoteScanRepository->findPagesForScan($remoteScanUid, '', $remoteRule),
                            $site,
                            $currentLanguageUid,
                            $remoteQuery
                        ),
                        $remoteQuery,
                        false
                    );
                    $totalRemotePages = count($filteredRemotePages);
                    $remotePagination = PaginationUtility::buildPagination(
                        $totalRemotePages,
                        $currentRemotePage,
                        self::REMOTE_PER_PAGE
                    );
                    $remotePages = $this->enrichRemoteOverviewPages(
                        array_slice($filteredRemotePages, $remotePagination['offset'], self::REMOTE_PER_PAGE),
                        $site,
                        $currentLanguageUid
                    );
                }
                $remotePages = $this->withRemoteRowData($remotePages, $isFreePreview);

                $remoteFailedPages = $this->remoteScanRepository->findFailedPagesForScanPaginated(
                    $remoteScanUid,
                    self::REMOTE_FAILED_PER_PAGE,
                    $remoteFailedPagination['offset'],
                    $remoteFailedQuery
                );
                $remoteFailedPages = $this->enrichRemoteOverviewPages($remoteFailedPages, $site, $currentLanguageUid);

                if ($remoteFailedQuery !== '' && $totalRemoteFailedPages === 0) {
                    $filteredRemoteFailedPages = $this->filterRemoteOverviewPages(
                        $this->enrichRemoteOverviewPagesForQuery(
                            $this->remoteScanRepository->findFailedPagesForScan($remoteScanUid),
                            $site,
                            $currentLanguageUid,
                            $remoteFailedQuery
                        ),
                        $remoteFailedQuery,
                        true
                    );
                    $totalRemoteFailedPages = count($filteredRemoteFailedPages);
                    $remoteFailedPagination = PaginationUtility::buildPagination(
                        $totalRemoteFailedPages,
                        $currentRemoteFailedPage,
                        self::REMOTE_FAILED_PER_PAGE
                    );
                    $remoteFailedPages = $this->enrichRemoteOverviewPages(
                        array_slice($filteredRemoteFailedPages, $remoteFailedPagination['offset'], self::REMOTE_FAILED_PER_PAGE),
                        $site,
                        $currentLanguageUid
                    );
                }
            }

            $remotePagination['pageUrls'] = [];
            for ($page = 1; $page <= $remotePagination['totalPages']; $page++) {
                $remotePagination['pageUrls'][$page] = $this->buildOverviewPaginationUrl(
                    $request,
                    $siteIdentifier,
                    $localPagination['currentPage'],
                    $page,
                    $remoteFailedPagination['currentPage'],
                    $localQuery,
                    $remoteQuery,
                    $remoteFailedQuery
                );
            }

            $remotePagination['previousUrl'] = $remotePagination['hasPrevious']
                ? $this->buildOverviewPaginationUrl(
                    $request,
                    $siteIdentifier,
                    $localPagination['currentPage'],
                    $remotePagination['currentPage'] - 1,
                    $remoteFailedPagination['currentPage'],
                    $localQuery,
                    $remoteQuery,
                    $remoteFailedQuery
                )
                : null;

            $remotePagination['nextUrl'] = $remotePagination['hasNext']
                ? $this->buildOverviewPaginationUrl(
                    $request,
                    $siteIdentifier,
                    $localPagination['currentPage'],
                    $remotePagination['currentPage'] + 1,
                    $remoteFailedPagination['currentPage'],
                    $localQuery,
                    $remoteQuery,
                    $remoteFailedQuery
                )
                : null;

            $remotePaginationItems = PaginationUtility::buildPaginationItems(
                $remotePagination['currentPage'],
                $remotePagination['totalPages'],
                $remotePagination['pageUrls'],
                self::MAX_VISIBLE_PAGINATION_ITEMS
            );

            $remoteFailedPagination['pageUrls'] = [];
            for ($page = 1; $page <= $remoteFailedPagination['totalPages']; $page++) {
                $remoteFailedPagination['pageUrls'][$page] = $this->buildOverviewPaginationUrl(
                    $request,
                    $siteIdentifier,
                    $localPagination['currentPage'],
                    $remotePagination['currentPage'],
                    $page,
                    $localQuery,
                    $remoteQuery,
                    $remoteFailedQuery
                );
            }

            $remoteFailedPagination['previousUrl'] = $remoteFailedPagination['hasPrevious']
                ? $this->buildOverviewPaginationUrl(
                    $request,
                    $siteIdentifier,
                    $localPagination['currentPage'],
                    $remotePagination['currentPage'],
                    $remoteFailedPagination['currentPage'] - 1,
                    $localQuery,
                    $remoteQuery,
                    $remoteFailedQuery
                )
                : null;

            $remoteFailedPagination['nextUrl'] = $remoteFailedPagination['hasNext']
                ? $this->buildOverviewPaginationUrl(
                    $request,
                    $siteIdentifier,
                    $localPagination['currentPage'],
                    $remotePagination['currentPage'],
                    $remoteFailedPagination['currentPage'] + 1,
                    $localQuery,
                    $remoteQuery,
                    $remoteFailedQuery
                )
                : null;

            $remoteFailedPaginationItems = PaginationUtility::buildPaginationItems(
                $remoteFailedPagination['currentPage'],
                $remoteFailedPagination['totalPages'],
                $remoteFailedPagination['pageUrls'],
                self::MAX_VISIBLE_PAGINATION_ITEMS
            );
        }


        // Pages listed for a rule open with that rule expanded, so the rule context survives the click.
        $remotePages = array_map(
            fn(array $page): array => $page + [
                    'detailUrl' => $this->buildRouteUrl('web_a11y.remotePageDetail', array_filter([
                        'remotePageUid' => (int)$page['uid'],
                        'site' => $siteIdentifier,
                        'language' => $currentLanguageUid,
                        'rule' => $remoteRule,
                    ], static fn (mixed $value): bool => $value !== '')) . ($remoteRule !== '' ? '#' . RuleAnchorUtility::anchorId($remoteRule) : ''),
                    'urlPath' => ScanUrlUtility::displayPath((string)($page['url'] ?? '')),
                ],
            $remotePages
        );

        $remoteFailedPages = array_map(
            fn(array $page): array => $page + [
                    'detailUrl' => $this->buildRouteUrl('web_a11y.remotePageDetail', [
                        'remotePageUid' => (int)$page['uid'],
                        'site' => $siteIdentifier,
                    ]),
                    'urlPath' => ScanUrlUtility::displayPath((string)($page['url'] ?? '')),
                ],
            $remoteFailedPages
        );

        $pageStats = array_map(
            fn(array $stat): array => $stat + [
                    'canScan' => $this->scanPermissionService !== null
                        ? $this->scanPermissionService->canScanPage((int)($stat['pageUid'] ?? 0))
                        : $this->accessControlService->canShowScanNow(),
                    'isFolder' => (int)($stat['pageDoktype'] ?? 0) === 254,
                    'detailUrl' => $this->buildRouteUrl('web_a11y.pageDetail', [
                        'pageUid' => $stat['pageUid'],
                        'id' => $stat['pageUid'],
                        'site' => $siteIdentifier,
                        'language' => $currentLanguageUid,
                    ]),
                ],
            $pageStats
        );

        $totalCounts = $siteIdentifier !== ''
            ? $this->issueRepository->countOpenTotalsForSite($siteIdentifier, $currentLanguageUid, $readablePagesClause)
            : [
                'critical' => 0,
                'warning' => 0,
                'info' => 0,
                'needs_review' => 0,
                'total' => 0,
            ];
        $localNewCounts = $siteIdentifier !== '' && is_array($lastScan)
            ? $this->issueRepository->countNewOpenTotalsForScan($siteIdentifier, (int)($lastScan['uid'] ?? 0), $currentLanguageUid, $readablePagesClause)
            : [
                'critical' => 0,
                'warning' => 0,
                'info' => 0,
                'needs_review' => 0,
                'total' => 0,
            ];
        $localScanDelta = [
            'new' => $readablePagesClause !== '' ? (int)$localNewCounts['total'] : (int)($lastScan['issues_new'] ?? 0),
            'resolved' => (int)($lastScan['issues_resolved'] ?? 0),
            'ignored' => (int)($lastScan['issues_ignored'] ?? 0),
        ];

        $scanStatus = $this->scanStatusService->getStatus();
        $isRelevantLocalScanRunning = $this->isRelevantLocalScanRunning($scanStatus, $siteRootPid, $currentPageUid);
        $freePreview = $isFreePreview
            ? $this->freeRemotePreviewService->getEntitlementStatus(
                rtrim($siteBase, '/') . '/',
                $siteIdentifier,
                $this->extensionContextService->getExtensionVersion(),
                (string)($queryParams['aqgFreeRefresh'] ?? '') === '1',
            )
            : [
                'isFree' => false,
                'entitlement' => $this->resolvePaidEntitlement($proStatus),
                'state' => strtoupper($this->resolvePaidEntitlement($proStatus)),
                'remoteCrawlerVisible' => true,
            ];
        if ($isFreePreview && is_array($activeRemoteScan)) {
            $freePreview['state'] = 'REMOTE_SCAN_ACTIVE';
        }
        $freePreview['hasTodayResult'] = $isFreePreview
            && $this->isTodayFreePreviewResult(is_array($remoteScan) ? $remoteScan : null);
        // Before activation (Settings › Licence › FREE Remote Preview) administrators get the way there; editors read
        // that an administrator activates it.
        $freePreview['activationUrl'] = $isFreePreview
            && (string)($freePreview['state'] ?? '') === 'ACTIVATION_REQUIRED'
            && $this->accessControlService->canManageAdminOnlySettings($this->backendContextService->getBackendUser())
                ? $this->buildRouteUrl('web_a11y.settings', ['tab' => 'licence', 'site' => $siteIdentifier]) . '#aqg-free-preview-activation'
                : '';
        $freePreview['trialUrl'] = $this->publicLinkProvider->getBackendUrl(PublicLinkProvider::TRIAL);
        if (trim((string)($freePreview['upgradeUrl'] ?? '')) === '') {
            $freePreview['upgradeUrl'] = $this->publicLinkProvider->getBackendUrl(PublicLinkProvider::PRICING);
        }
        $freeSubmitIntent = $isFreePreview && $currentPageUid > 0 && $currentPageUrl !== ''
            ? $this->freeSubmitIntentService->create($siteIdentifier, $currentPageUid)
            : '';
        // A local, development or internal site address (DDEV, localhost, .test, private IP …) can never be scanned
        // by the AQG crawler: say so up front instead of offering a scan that the crawler would refuse.
        $nonPublicSiteHost = $isFreePreview ? $this->findNonPublicSiteHost($siteBase) : '';
        $freePreview['siteAddressLocal'] = $nonPublicSiteHost !== '';
        $freePreview['siteHost'] = $nonPublicSiteHost;
        $freePreview['submitCapable'] = (bool)($freePreview['available'] ?? false)
            && $freeSubmitIntent !== ''
            && $nonPublicSiteHost === '';
        $remoteUiAvailable = $isFreePreview || $this->shouldShowRemoteOverviewPanel(
            $proStatus,
            is_array($remoteScan) ? $remoteScan : null,
            is_array($latestRemotePageScan) ? $latestRemotePageScan : null,
            is_array($activeRemoteScan) ? $activeRemoteScan : null,
            $totalRemotePages,
            $totalRemoteFailedPages
        );

        // The Content Scan exports cover the language the panel shows.
        $exportLocalCsvUrl = $this->exportUrlBuilderService->buildOverviewCsvUrl($siteIdentifier, false, null, $currentLanguageUid);
        $exportLocalPdfUrl = $this->exportUrlBuilderService->buildOverviewPdfUrl($siteIdentifier, false, null, $currentLanguageUid);
        // F12-R1 (F11-02): while the scan's results are not stored there is no report to export; the links are not offered.
        $remoteScanUidForExport = is_array($remoteScan) && RemoteScanSignal::unsavedResultState($remoteScan) === null
            ? (int)($remoteScan['uid'] ?? 0)
            : 0;
        $exportRemoteCsvUrl = !$isFreePreview && $remoteScanUidForExport > 0
            ? $this->exportUrlBuilderService->buildOverviewCsvUrl(
                $siteIdentifier,
                true,
                $currentPageUid,
                $currentLanguageUid,
                $remoteScanUidForExport
            )
            : '';
        $exportRemotePdfUrl = !$isFreePreview && $remoteScanUidForExport > 0
            ? $this->exportUrlBuilderService->buildOverviewPdfUrl(
                $siteIdentifier,
                true,
                $currentPageUid,
                $currentLanguageUid,
                $remoteScanUidForExport
            )
            : '';

        $this->configureDocHeader(
            $moduleTemplate,
            $returnParameters
        );

        $backendUser = $this->backendContextService->getBackendUser();
        // Scan controls follow the endpoints' rule: the TSconfig flag and edit access to the page or site root.
        $canScanAll = $this->scanPermissionService !== null
            ? $this->scanPermissionService->canScanSite($siteRootPid)
            : $this->accessControlService->canShowScanAll($backendUser);
        $canScanNow = $this->scanPermissionService !== null
            ? $this->scanPermissionService->canScanPage($currentPageUid)
            : $this->accessControlService->canShowScanNow($backendUser);
        $canManageRemoteAccessSettings = $this->accessControlService->canManageAdminOnlySettings($backendUser);
        $showRemoteScannerTokenNotice = !$isFreePreview
            && $canManageRemoteAccessSettings
            && $siteIdentifier !== ''
            && !$this->hasEffectiveScannerToken($siteIdentifier);
        $remoteScanAccessSettingsUrl = $this->buildRouteUrl(
            'web_a11y.settings',
            array_replace($returnParameters, [
                'tab' => 'remote_access',
                'rulesetSite' => $siteIdentifier,
            ])
        );

        $fieldsInitializedCount = $this->fieldConfigurationBootstrapService->initializeIfUnconfigured();
        $hasEnabledFields = $this->fieldConfigRepository->hasEnabledFields();
        // F-04: fields a TCA change added after the first discovery are scanned only after "Refresh fields".
        $fieldsAwaitingRefresh = $this->accessControlService->canManageAdminOnlySettings($backendUser)
            ? $this->fieldConfigurationBootstrapService->countFieldsAwaitingRefresh()
            : 0;
        $hasFieldConfiguration = $hasEnabledFields || $this->fieldConfigRepository->hasAnyConfiguration();
        $canShowSettings = $this->accessControlService->canShowSettings($backendUser);
        $licenceNotice = $canShowSettings
            ? $this->buildLicenceNotice($proStatus, $this->buildRouteUrl('web_a11y', $returnParameters), $returnParameters)
            : null;
        // First use: no content scan on record yet. The guide names the next steps, the Free Remote Preview only
        // for installations without a licence.
        $gettingStarted = $lastScan === null && $totalLocalPages === 0
            ? [
                'isFree' => $isFreePreview,
                'siteAddressLocal' => $nonPublicSiteHost !== '',
                'siteHost' => $nonPublicSiteHost,
            ]
            : null;
        // After the first content scan, point once to the browser-based check — until this site has a Free result.
        $showFreePreviewNextStep = $isFreePreview
            && $lastScan !== null
            && $licenceNotice === null
            && $canScanNow
            && (bool)$freePreview['submitCapable']
            && (string)($freePreview['state'] ?? '') === 'FREE_AVAILABLE'
            && !is_array($remoteScan)
            && $this->remoteScanRepository->findLastCompletedPageScanBySite($siteIdentifier, -1, true) === null;
        // Sol R05: the crawler's site-wide reporting summary (score, priority fixes, keyboard/structure summaries) and its
        // counters are built over every page; a restricted user gets the counters of what they may read and no summary.
        // F11-02: a completed crawl whose results are not stored yet (or could not be stored) has no saved findings. The
        // crawler's own summary (priority fixes, WCAG summary, reporting groups, score, signals) and every count built
        // from it are not shown as results until persistence or recovery has stored them; crawl progress and the
        // storage state stay visible (RemoteScanSignal::TONE_PENDING / TONE_NOT_STORED).
        $remoteResultsUnsaved = is_array($remoteScan) && RemoteScanSignal::unsavedResultState($remoteScan) !== null;
        $restrictedRemoteScan = null;
        if (is_array($remoteScan) && $this->isRemoteViewRestricted()) {
            $restriction = $this->overviewRemoteRestriction ?? $this->remoteRestrictionFor($remoteScan) ?? new RemoteResultAuthorization(false, [], [], []);
            $restrictedRemoteScan = $this->restrictedRemoteScanCounters($remoteScan, $restriction, $totalRemoteFailedPages);
        }
        $remoteReportingSummary = $this->remoteReportingSummaryForView(
            is_array($remoteScan) ? $remoteScan : null,
            $restrictedRemoteScan !== null,
            $siteBase
        );
        $remoteScanHasIssues = is_array($remoteScan) && !$remoteResultsUnsaved && (int)(($restrictedRemoteScan ?? $remoteScan)['issues_total'] ?? 0) > 0;
        $remoteScore = $remoteReportingSummary['score'] ?? [];
        $remoteKeyboardSummary = is_array($remoteReportingSummary['keyboardSummary'] ?? null) ? $remoteReportingSummary['keyboardSummary'] : [];
        $remoteStructureSummary = is_array($remoteReportingSummary['structureSummary'] ?? null) ? $remoteReportingSummary['structureSummary'] : [];
        $remoteContrastDetails = is_array($remoteReportingSummary['contrastDetails'] ?? null) ? $remoteReportingSummary['contrastDetails'] : [];
        $remoteRemediationSummary = is_array($remoteReportingSummary['remediationSummary'] ?? null) ? $remoteReportingSummary['remediationSummary'] : [];
        $remoteComponentSummary = is_array($remoteReportingSummary['componentSummary'] ?? null) ? $remoteReportingSummary['componentSummary'] : [];
        $remoteShowKeyboardSummary = !empty($remoteKeyboardSummary['tested']);
        $remoteHasAdditionalAutomatedSignals = $remoteStructureSummary !== [] || $remoteShowKeyboardSummary || $remoteContrastDetails !== [] || $remoteRemediationSummary !== [] || $remoteComponentSummary !== [];
        $remoteHasPriorityFixes = !empty($remoteReportingSummary['priorityFixesVisible']);
        $priorityFixOwners = $this->priorityFixOwnerTags(
            $siteIdentifier,
            $siteRootPid,
            is_array($remoteReportingSummary['priorityFixes'] ?? null) ? $remoteReportingSummary['priorityFixes'] : [],
            $isFreePreview || (is_array($remoteScan) && (bool)($remoteScan['is_free_preview'] ?? false))
        );
        foreach (['priorityFixes', 'priorityFixesVisible'] as $fixListKey) {
            $remoteReportingSummary[$fixListKey] = array_map(
                fn (array $fix): array => $fix + [
                    'affectedPagesUrl' => (string)($fix['ruleId'] ?? '') !== ''
                        ? $this->buildRemoteRuleFilterUrl($request, $siteIdentifier, (string)$fix['ruleId'])
                        : '',
                ] + ($priorityFixOwners[(string)($fix['ruleId'] ?? '')] ?? self::NO_PRIORITY_FIX_OWNER),
                is_array($remoteReportingSummary[$fixListKey] ?? null) ? $remoteReportingSummary[$fixListKey] : []
            );
        }
        $remoteRuleFilter = $remoteRule !== ''
            ? [
                'ruleId' => $remoteRule,
                'label' => $this->resolveRuleFilterLabel($remoteRule, $remoteReportingSummary['priorityFixes']),
                'clearUrl' => $this->buildRemoteRuleFilterUrl($request, $siteIdentifier, ''),
            ]
            : [];
        if ($remoteRuleFilter !== []) {
            $remotePagesSubtitle = sprintf(
                $this->translateWithFallback('overview.remote.pages.ruleSubtitle', 'Pages on which “%s” was found in this scan.'),
                $remoteRuleFilter['label']
            );
            $remotePagesEmptyTitle = $this->translateWithFallback('overview.remote.pages.ruleEmptyTitle', 'No page with this rule in this scan');
            $remotePagesEmptyBody = $this->translateWithFallback('overview.remote.pages.ruleEmptyBody', 'Show all pages to see the other results of this scan.');
        }
        // Coverage decides before score and tone: a scan that loaded no page, or missed pages, is never "good".
        $remoteSignal = RemoteScanSignal::resolve(
            is_array($remoteScan) ? ($restrictedRemoteScan ?? $remoteScan) : null,
            is_array($remoteScore) ? $remoteScore : [],
            $remoteHasPriorityFixes,
            is_array($remoteReportingSummary['reportSummary'] ?? null) ? $remoteReportingSummary['reportSummary'] : [],
            is_array($remoteScan)
                ? ($restrictedRemoteScan !== null ? (int)$restrictedRemoteScan['pages_failed'] : $this->remoteScanRepository->countPagesForScan((int)($remoteScan['uid'] ?? 0), true))
                : 0,
            $restrictedRemoteScan !== null,
            is_array($remoteScan) ? $remoteScan : null,
        );
        $remoteResultsRestricted = $remoteSignal->tone === RemoteScanSignal::TONE_RESTRICTED;
        $remoteSignalTone = $remoteSignal->tone;
        $remoteScore = is_array($remoteScore) ? $remoteScore : [];
        if (!$remoteSignal->showScoreValue) {
            $remoteScore['hasValue'] = false;
        }
        $remoteCoverageIsComplete = $remoteSignal->coverage?->isComplete() ?? false;
        $remoteScoreTone = $remoteCoverageIsComplete ? $this->resolveRemoteScoreTone($remoteScore) : 'unavailable';
        $remoteSignalLabel = $this->resolveRemoteSignalLabel($remoteSignalTone);
        $remoteSignalIcon = $remoteSignalTone === 'good' ? 'ok' : ($remoteSignalTone === 'no-scan' ? 'empty' : 'warning');
        $remoteCoverageNote = $remoteSignal->coverage !== null && !$remoteCoverageIsComplete && $remoteSignal->coverage->expectedPages > 0
            ? sprintf(
                $remoteSignal->coverage->expectedPages === 1
                    ? $this->translateWithFallback('overview.remote.signal.coverage.single', 'Checked %1$d of 1 page. A page that could not be loaded is listed under Failed / unreachable pages.')
                    : $this->translateWithFallback('overview.remote.signal.coverage', 'Checked %1$d of %2$d pages. Pages that could not be loaded are listed under Failed / unreachable pages.'),
                $remoteSignal->coverage->loadedPages,
                $remoteSignal->coverage->expectedPages
            )
            : '';
        if ($remoteResultsRestricted) {
            // Sol QA-06: constant wording for every restricted view, so it reveals nothing about withheld results.
            $remoteCoverageNote = $this->translateWithFallback('remote.restricted.note', self::REMOTE_RESTRICTED_NOTE);
        }
        // An empty priority list or page list describes the scan shown: never an all-clear for pages that were not checked.
        $remotePriorityEmpty = match (RemoteEmptyState::priorityFixes($remoteSignal->coverage, $remoteResultsRestricted)) {
            RemoteEmptyState::PRIORITY_RESTRICTED => [
                'state' => RemoteEmptyState::PRIORITY_RESTRICTED,
                'title' => $this->translateWithFallback('overview.remote.priorityFixes.restrictedTitle', 'Priority fixes are shown to administrators'),
                'body' => $this->translateWithFallback('overview.remote.priorityFixes.restrictedBody', 'They are calculated over every page of the scan.'),
            ],
            RemoteEmptyState::PRIORITY_CLEAR => [
                'state' => RemoteEmptyState::PRIORITY_CLEAR,
                'title' => $this->translateWithFallback('overview.remote.priorityFixes.emptyClearTitle', 'No priority fixes needed'),
                'body' => $this->translateWithFallback('overview.remote.priorityFixes.emptyClearBody', 'The latest frontend scan did not find issues requiring prioritisation.'),
            ],
            RemoteEmptyState::PRIORITY_INCOMPLETE => [
                'state' => RemoteEmptyState::PRIORITY_INCOMPLETE,
                'title' => $this->translateWithFallback('overview.remote.priorityFixes.emptyIncompleteTitle', 'No priority fixes on the checked pages'),
                'body' => $this->translateWithFallback('overview.remote.priorityFixes.emptyIncompleteBody', 'Some pages could not be checked, so this is not an all-clear. They are listed under Failed / unreachable pages.'),
            ],
            default => [
                'state' => RemoteEmptyState::PRIORITY_NO_COVERAGE,
                'title' => $this->translateWithFallback('overview.remote.priorityFixes.emptyNoCoverageTitle', 'No priority list'),
                'body' => $this->translateWithFallback('overview.remote.priorityFixes.emptyNoCoverageBody', 'This scan could not check any page. Fix the failed pages below, then scan again.'),
            ],
        };
        if ($remoteSignalTone === RemoteScanSignal::TONE_PENDING || $remoteSignalTone === RemoteScanSignal::TONE_NOT_STORED) {
            // STATE-01: before the results are stored, an empty priority list says nothing about the findings.
            $notStored = $remoteSignalTone === RemoteScanSignal::TONE_NOT_STORED;
            $remotePriorityEmpty = [
                'state' => $remoteSignalTone,
                'title' => $notStored
                    ? $this->translateWithFallback('overview.remote.signal.notStored', 'Results could not be saved')
                    : $this->translateWithFallback('overview.remote.priorityFixes.pendingTitle', 'Results are being saved'),
                'body' => $notStored
                    ? $this->translateWithFallback('overview.remote.priorityFixes.notStoredBody', 'The frontend scan finished, but its results could not be stored. AQG retries when this view is opened; details are in the TYPO3 log.')
                    : $this->translateWithFallback('overview.remote.priorityFixes.pendingBody', 'The frontend scan finished. Reload this view in a moment to see its results.'),
            ];
        }
        if ($remotePages === []) {
            switch (RemoteEmptyState::affectedPages(is_array($remoteScan), $remoteSignal->coverage, $remoteRuleFilter !== [], $remoteResultsRestricted)) {
                case RemoteEmptyState::PAGES_RESTRICTED:
                    $remotePagesEmptyTitle = $this->translateWithFallback('remote.restricted.noneTitle', 'No results of this scan are available to you');
                    $remotePagesEmptyBody = $this->translateWithFallback('remote.restricted.note', self::REMOTE_RESTRICTED_NOTE);
                    break;
                case RemoteEmptyState::PAGES_NO_COVERAGE:
                    $remotePagesEmptyTitle = $this->translateWithFallback('overview.remote.pages.noCoverageTitle', 'No page was checked in this scan');
                    $remotePagesEmptyBody = $this->translateWithFallback('overview.remote.pages.noCoverageBody', 'Pages that could not be loaded are listed under Failed / unreachable pages.');
                    break;
                case RemoteEmptyState::PAGES_NOT_STORED:
                    $remotePagesEmptyTitle = $this->translateWithFallback('overview.remote.pages.notStoredTitle', 'No page results stored for this scan');
                    $remotePagesEmptyBody = $this->translateWithFallback('overview.remote.pages.notStoredBody', 'Scan again to list the checked pages.');
                    break;
            }
        }
        $totalRemotePagesIsSingle = $totalRemotePages === 1;
        $remotePagesCountLabel = $totalRemotePagesIsSingle
            ? $this->translateWithFallback('overview.proSummary.page', 'page')
            : $remotePagesCountLabel;
        $this->logRemoteHistoryDebug('AQG remote history overview context', [
            'pageUid' => $currentPageUid,
            'siteIdentifier' => $siteIdentifier,
            'siteRootPid' => $siteRootPid,
            'siteBase' => $siteBase,
            'languageUid' => $currentLanguageUid,
        ]);
        $remoteScanHistory = $isFreePreview
            ? ['available' => false, 'items' => [], 'siteScans' => [], 'pageScans' => []]
            : $this->buildRemoteHistoryForOverview($request, $siteBase, $siteIdentifier, $currentPageUid, $currentLanguageUid);
        if ($this->isRemoteViewRestricted()) {
            $remoteScanHistory = $this->restrictRemoteHistory($remoteScanHistory);
        }
        $this->logRemoteHistoryDebug('AQG remote history overview result', [
            'pageUid' => $currentPageUid,
            'siteIdentifier' => (string)($remoteScanHistory['debugSiteIdentifier'] ?? $siteIdentifier),
            'siteScansCount' => (int)($remoteScanHistory['siteScansCount'] ?? 0),
            'pageScansCount' => (int)($remoteScanHistory['pageScansCount'] ?? 0),
            'apiSiteCount' => (int)($remoteScanHistory['debugSiteApiCount'] ?? 0),
            'apiPageCount' => (int)($remoteScanHistory['debugPageApiCount'] ?? 0),
            'localSiteCount' => (int)($remoteScanHistory['debugLocalSiteCount'] ?? 0),
            'localPageCount' => (int)($remoteScanHistory['debugLocalPageCount'] ?? 0),
            'showGlobalEmpty' => (bool)($remoteScanHistory['showGlobalEmpty'] ?? true),
        ]);
        $remoteScanCompare = $isFreePreview
            ? ['available' => false, 'message' => '']
            : $this->buildRemoteScanCompare($request, $siteBase, $siteIdentifier);
        // Sol R05: the regression alert compares every page of two scans (AQG service): administrators only.
        $regressionAlert = $isFreePreview || $this->isRemoteViewRestricted() || $remoteResultsUnsaved
            ? ['available' => false, 'message' => '']
            : $this->buildOverviewRegressionAlert($request, $siteBase, $siteIdentifier, is_array($remoteScan) ? $remoteScan : null, $currentPageUid, $currentLanguageUid);

        $moduleTemplate->assignMultiple([
            'siteIdentifier' => $siteIdentifier,
            'hasPageContext' => true,
            'emptyState' => [],
            'lastScan' => $lastScan,
            'localRenderedCoverage' => $this->buildLocalRenderedCoverage(
                is_array($lastScan) ? $lastScan : null,
                !$isFreePreview,
                $siteIdentifier !== '' && $this->hasEffectiveScannerToken($siteIdentifier)
            ),
            'lastScanScopeLabel' => $lastScanScopeLabel,
            'hasLocalResults' => is_array($lastScan) || (int)($totalCounts['total'] ?? 0) > 0,
            'pageStats' => $pageStats,
            'totalLocalPages' => $totalLocalPages,
            'localPagination' => $localPagination,
            'localPaginationItems' => $localPaginationItems,
            'totalCounts' => $totalCounts,
            // STATE-01: whether a zero of the site's readable open findings is a verified clean result.
            'localEvidence' => $this->localOverviewEvidence(is_array($lastScan) ? $lastScan : null, $siteRootPid, $currentLanguageUid),
            'localNewCounts' => $localNewCounts,
            'localScanDelta' => $localScanDelta,
            'canScanAll' => $canScanAll,
            'canScanNow' => $canScanNow,
            // Cancel follows the running scan's scope; before one runs, it follows what this user may start here.
            'canCancelLocalScan' => (bool)($scanStatus['running'] ?? false) && $this->scanPermissionService instanceof ScanPermissionService
                ? $this->scanPermissionService->canCancelLocalScan($scanStatus)
                : $canScanNow || $canScanAll,
            'canCancelRemoteScan' => is_array($activeRemoteScan) && $this->scanPermissionService instanceof ScanPermissionService
                ? $this->scanPermissionService->canCancelRemoteScan($activeRemoteScan)
                : $canScanNow || $canScanAll,
            'siteRootPid' => $siteRootPid,
            'currentPageUid' => $currentPageUid,
            'isPageContext' => $isPageContext,
            'currentPageIssueCounts' => $currentPageIssueCounts,
            'currentPageHasIssues' => array_sum($currentPageIssueCounts) > 0,
            'currentPageDetailUrl' => $currentPageDetailUrl,
            'currentPageUrl' => $currentPageUrl,
            'scanStatus' => $this->scopeAccessService->restrictLocalScanStatus($scanStatus),
            'isRelevantLocalScanRunning' => $isRelevantLocalScanRunning,
            'hasScanResults' => $lastScan !== null,
            'hasEnabledFields' => $hasEnabledFields,
            'hasFieldConfiguration' => $hasFieldConfiguration,
            'fieldsAwaitingRefresh' => $fieldsAwaitingRefresh,
            'fieldsInitializedCount' => $fieldsInitializedCount,
            'gettingStarted' => $gettingStarted,
            'showFreePreviewNextStep' => $showFreePreviewNextStep,
            'canShowSettings' => $canShowSettings,
            'scanFieldsSettingsUrl' => $this->buildRouteUrl('web_a11y.settings', array_replace($returnParameters, ['tab' => 'fields'])),
            'licenceNotice' => $licenceNotice,
            'remoteScan' => $this->remoteScanForView(is_array($remoteScan) ? $remoteScan : null, $restrictedRemoteScan),
            'remoteOccurrences' => $this->buildRemoteOccurrenceView(is_array($remoteScan) ? $remoteScan : null),
            'latestRemotePageScan' => $latestRemotePageScan,
            'activeRemoteScan' => $activeRemoteScan,
            'remotePages' => $remotePages,
            'remoteFailedPages' => $remoteFailedPages,
            // A restricted (page-scoped) view gets no list: which configured URLs are missing tells about pages outside it.
            'priorityUrlsNotScanned' => is_array($restrictedRemoteScan) ? [] : $this->priorityUrlsNotScanned(is_array($remoteScan) ? $remoteScan : null, $siteIdentifier, $siteBase),
            'remotePriorityFixes' => $remoteReportingSummary['priorityFixes'],
            'remotePriorityFixesVisible' => $remoteReportingSummary['priorityFixesVisible'],
            'remotePriorityFixesMoreCount' => $remoteReportingSummary['priorityFixesMoreCount'],
            'remoteWcagSummary' => $remoteReportingSummary['wcagSummary'],
            'remoteReportSummary' => $remoteReportingSummary['reportSummary'] ?? [],
            'remoteScore' => is_array($remoteScore) ? $remoteScore : [],
            'remoteSignalTone' => $remoteSignalTone,
            'remoteSignalLabel' => $remoteSignalLabel,
            'remoteScoreTone' => $remoteScoreTone,
            'remoteSignalIcon' => $remoteSignalIcon,
            'remoteHasPriorityFixes' => $remoteHasPriorityFixes,
            'remoteHasNoPriorityFixes' => !$remoteHasPriorityFixes && $remoteCoverageIsComplete,
            'remoteCoverageNote' => $remoteCoverageNote,
            // Coverage against the TYPO3 page tree (company acceptance C-01): not shown in a restricted view.
            'remoteTreeCoverage' => !$remoteResultsRestricted && is_array($remoteScan)
                ? $this->treeCoverageService?->forScan($remoteScan, $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier))
                : null,
            'remotePriorityEmpty' => $remotePriorityEmpty,
            'remoteManualReviewChecklist' => $remoteReportingSummary['manualReviewChecklist'] ?? [],
            'remoteReportingGroups' => $remoteReportingSummary['reportingGroups'] ?? [],
            'remoteKeyboardSummary' => $remoteKeyboardSummary,
            'remoteShowKeyboardSummary' => $remoteShowKeyboardSummary,
            'remoteStructureSummary' => $remoteStructureSummary,
            'remoteContrastDetails' => $remoteContrastDetails,
            'remoteRemediationSummary' => $remoteRemediationSummary,
            'remoteComponentSummary' => $remoteComponentSummary,
            'remoteHasAdditionalAutomatedSignals' => $remoteHasAdditionalAutomatedSignals,
            'remoteScanHistory' => $remoteScanHistory,
            'remoteScanCompare' => $remoteScanCompare,
            'regressionAlert' => $regressionAlert,
            'remoteScanHasIssues' => $remoteScanHasIssues,
            'remotePagesArePageScanFallback' => $remotePagesArePageScanFallback,
            'remotePagesTitle' => $remotePagesTitle,
            'remotePagesSubtitle' => $remotePagesSubtitle,
            'remotePagesEmptyTitle' => $remotePagesEmptyTitle,
            'remotePagesEmptyBody' => $remotePagesEmptyBody,
            'remotePagesCountLabel' => $remotePagesCountLabel,
            'totalRemotePages' => $totalRemotePages,
            'totalRemotePagesIsSingle' => $totalRemotePagesIsSingle,
            'totalRemoteFailedPages' => $totalRemoteFailedPages,
            'remotePagination' => $remotePagination,
            'remotePaginationItems' => $remotePaginationItems,
            'remoteFailedPagination' => $remoteFailedPagination,
            'remoteFailedPaginationItems' => $remoteFailedPaginationItems,
            'proStatus' => $proStatus,
            'freePreview' => $freePreview,
            'trialUsage' => $this->resolveTrialUsage($proStatus, $siteBase, $siteIdentifier),
            'freeSubmitIntent' => $freeSubmitIntent,
            'remoteUiAvailable' => $remoteUiAvailable,
            'exportLocalCsvUrl' => $exportLocalCsvUrl,
            'exportLocalPdfUrl' => $exportLocalPdfUrl,
            'exportRemoteCsvUrl' => $exportRemoteCsvUrl,
            'exportRemotePdfUrl' => $exportRemotePdfUrl,
            'settingsUrl' => $this->buildRouteUrl('web_a11y.settings', $returnParameters),
            'remoteScanAccessSettingsUrl' => $remoteScanAccessSettingsUrl,
            'showRemoteScannerTokenNotice' => $showRemoteScannerTokenNotice,
            'localQuery' => $localQuery,
            'remoteQuery' => $remoteQuery,
            'remoteFailedQuery' => $remoteFailedQuery,
            'remoteRule' => $remoteRule,
            'remoteRuleFilter' => $remoteRuleFilter,
            'availableLanguages' => $availableLanguages,
            'languageOptions' => $languageOptions,
            'currentLanguageOption' => $currentLanguageOption,
            'hasLanguageOptions' => $languageOptions !== [],
            'languageOptionCount' => count($languageOptions),
            'availableLanguageCount' => count($availableLanguages),
            'currentLanguageUid' => $currentLanguageUid,
            'languageUrlParameters' => $languageUrlParameters,
        ]);

        $remediation = $this->remediationActivity($siteIdentifier ?? '', (int)($currentPageUid ?? 0));
        $moduleTemplate->assignMultiple([
            'remediationActivity' => $remediation['activity'],
            // Sub-navigation "AI fixes (n)" (Atlas 08·A): open items of this site, from the same rows; 0 hides it.
            'aiFixesCount' => $remediation['openCount'],
        ]);

        return $moduleTemplate->renderResponse('Overview/Index');
    }



    private function findNonPublicSiteHost(string $siteBase): string
    {
        // A development setup with its own AQG service decides itself which hosts its crawler may reach.
        if ($siteBase === '' || ProSettings::usesCustomServiceEndpoint()) {
            return '';
        }

        return ($this->publicSiteAddressClassifier ?? new PublicSiteAddressClassifier())->findNonPublicHost($siteBase) ?? '';
    }

    /**
     * The first screen without a selected page: what AQG checks and in which order to start.
     *
     * @return list<string>
     */
    private function buildFirstUseSteps(): array
    {
        return [
            $this->translateWithFallback('overview.firstUse.step.selectPage', 'Select a page of a TYPO3 Site in the page tree.'),
            $this->translateWithFallback('overview.firstUse.step.contentScan', 'Run a content scan. It checks headings, links, images, tables and forms in your TYPO3 content inside this installation and needs no licence key.'),
            (bool)$this->proSettings?->isConfigured()
                ? $this->translateWithFallback('overview.firstUse.step.frontendLicensed', 'Check what visitors\' browsers render with a frontend scan on the Frontend scan tab.')
                : $this->translateWithFallback('overview.firstUse.step.frontendFree', 'Then see what visitors\' browsers render: the Free Remote Preview scans a page on the AQG service without licence key, account or email. The site must be reachable from the internet.'),
        ];
    }

    /**
     * Keep existing remote scan results visible even if licence validation is
     * temporarily unavailable or a cached status is stale. Scan actions remain
     * guarded by the actual PRO status and the AJAX endpoint still performs the
     * server-side licence check.
     *
     * @param array<string, mixed>|null $remoteScan
     * @param array<string, mixed>|null $latestRemotePageScan
     * @param array<string, mixed>|null $activeRemoteScan
     */
    private function shouldShowRemoteOverviewPanel(
        object $proStatus,
        ?array $remoteScan,
        ?array $latestRemotePageScan,
        ?array $activeRemoteScan,
        int $totalRemotePages,
        int $totalRemoteFailedPages
    ): bool {
        if (($proStatus->valid ?? false) && ($proStatus->hasCrawler ?? false)) {
            return true;
        }

        return $remoteScan !== null
            || $latestRemotePageScan !== null
            || $activeRemoteScan !== null
            || $totalRemotePages > 0
            || $totalRemoteFailedPages > 0;
    }

    /**
     * A saved licence that does not validate leaves the Overview in the Free state. Say why and offer the
     * one step that resolves it; a retry simply reloads this view, which validates again.
     *
     * @param array<string, mixed> $returnParameters
     * @return array<string, mixed>|null
     */
    private function buildLicenceNotice(object $proStatus, string $retryUrl, array $returnParameters): ?array
    {
        if (!(bool)($proStatus->configured ?? false) || (bool)($proStatus->valid ?? false)) {
            return null;
        }

        $publicLinks = $this->publicLinkProvider->getBackendLinks();
        $notice = LicenceGuidance::forReason((string)($proStatus->reason ?? ''))->toView(
            [
                LicenceGuidance::ACTION_RETRY => $retryUrl,
                LicenceGuidance::ACTION_PRICING => $publicLinks[PublicLinkProvider::PRICING],
                LicenceGuidance::ACTION_PORTAL => $publicLinks[PublicLinkProvider::PORTAL],
                LicenceGuidance::ACTION_SUPPORT => $publicLinks[PublicLinkProvider::SUPPORT],
            ],
            fn (string $key): string => $this->translate($key),
        );
        $notice['primaryAction'] = $notice['actions'][0] ?? null;
        $notice['settingsUrl'] = $this->buildRouteUrl('web_a11y.settings', array_replace($returnParameters, ['tab' => 'licence']));

        return $notice;
    }

    /**
     * The Trial's frontend scan allowance as the AQG crawler enforces it, or null to show the plan limits only.
     *
     * @return array<string, mixed>|null
     */
    private function resolveTrialUsage(object $proStatus, string $siteBase, string $siteIdentifier): ?array
    {
        if (
            !(bool)($proStatus->valid ?? false)
            || !(bool)($proStatus->isTrial ?? false)
            || !$this->trialUsageService instanceof TrialUsageService
            || $siteIdentifier === ''
        ) {
            return null;
        }

        $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase($siteBase);
        if ($domain === '') {
            return null;
        }

        $usage = $this->trialUsageService->getUsage(
            $domain,
            $this->extensionContextService->getExtensionVersion(),
            rtrim($siteBase, '/') . '/',
            $siteIdentifier,
        );
        if ($usage === null) {
            return null;
        }

        $nextScanAt = $usage['nextScanAt'] !== '' ? strtotime($usage['nextScanAt']) : false;
        $usage['nextScanAtFormatted'] = $nextScanAt !== false ? BackendTimeUtility::formatDateTime($nextScanAt) : '';

        return $usage;
    }

    private function resolvePaidEntitlement(object $proStatus): string
    {
        if ((bool)($proStatus->isTrial ?? false)) {
            return 'trial';
        }

        return in_array(strtolower(trim((string)($proStatus->plan ?? ''))), ['agency', 'enterprise'], true)
            ? 'agency'
            : 'pro';
    }

    /** @param array<string, mixed>|null $remoteScan */
    private function isTodayFreePreviewResult(?array $remoteScan): bool
    {
        if (
            $remoteScan === null
            || (int)($remoteScan['is_free_preview'] ?? 0) !== 1
            || (int)($remoteScan['persisted_at'] ?? 0) <= 0
            || strtolower(trim((string)($remoteScan['status'] ?? ''))) !== 'completed'
        ) {
            return false;
        }

        $resultTimestamp = max(
            (int)($remoteScan['finished_at'] ?? 0),
            (int)($remoteScan['started_at'] ?? 0),
            (int)($remoteScan['crdate'] ?? 0),
        );
        $todayUtc = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->getTimestamp();

        return $resultTimestamp >= $todayUtc;
    }

    /**
     * @param array<string, mixed>|null $scan
     * @return array<string, mixed>|null
     */
    private function withFormattedScanTimestamps(?array $scan): ?array
    {
        if ($scan === null) {
            return null;
        }

        foreach (['started_at', 'finished_at', 'tstamp'] as $field) {
            $timestamp = (int)($scan[$field] ?? 0);
            if ($timestamp > 0) {
                $scan[$field . '_formatted'] = BackendTimeUtility::formatDateTime($timestamp);
            }
        }

        return $scan;
    }

    /**
     * The scan row the Overview view shows: the restricted counters for a restricted view (Sol R05), and without the
     * crawler's finding counters (`issues_total` / `issues_new` / `issues_resolved`: the source tab's "new" badge and
     * totals) while its results are not stored (F11-02). Crawl progress (pages, dates, status) stays.
     *
     * @param array<string, mixed>|null $remoteScan the stored (unrestricted) scan row
     * @param array<string, mixed>|null $restrictedRemoteScan
     * @return array<string, mixed>|null
     */
    /**
     * F-07: configured Priority URLs that the shown site scan did not scan. A sitemap scan visits only pages its sitemap
     * lists, a crawl only pages under its start URL, and exclusions and the page limit apply — a priority path outside
     * those is not scanned, which the Overview says instead of leaving it unexplained. Compared case-sensitively by path.
     *
     * @param array<string, mixed>|null $remoteScan the stored scan row
     * @return list<string> the configured entries, as entered (at most 10)
     */
    private function priorityUrlsNotScanned(?array $remoteScan, string $siteIdentifier, string $siteBase): array
    {
        if (!is_array($remoteScan) || (string)($remoteScan['scan_scope'] ?? '') !== 'site' || (int)($remoteScan['uid'] ?? 0) <= 0
            || $siteBase === '' || !$this->remoteScanAccessSettingsService instanceof RemoteScanAccessSettingsService
            || !in_array((string)($remoteScan['status'] ?? ''), ['completed', 'partial'], true)
            // Results not stored yet (or not stored after a sync error): its pages are unknown, nothing is "missing".
            || RemoteScanSignal::unsavedResultState($remoteScan) !== null) {
            return [];
        }
        $priorityUrls = $this->remoteScanAccessSettingsService->buildForSite($siteIdentifier)['priorityUrls'] ?? [];
        if ($priorityUrls === []) {
            return [];
        }
        $scanned = [];
        foreach ($this->remoteScanRepository->findAllPagesForScan((int)$remoteScan['uid']) as $page) {
            $scanned[ScanUrlUtility::comparable((string)($page['url'] ?? ''))] = true;
        }
        $base = rtrim((string)($remoteScan['start_url'] ?? '') !== '' ? (string)$remoteScan['start_url'] : $siteBase, '/');
        $origin = (string)preg_replace('#^(https?://[^/]+).*$#i', '$1', $base);
        $missing = [];
        foreach ($priorityUrls as $entry) {
            $entry = trim((string)$entry);
            if ($entry === '') {
                continue;
            }
            $absolute = str_starts_with($entry, '/') ? $origin . $entry : $entry;
            if (!isset($scanned[ScanUrlUtility::comparable($absolute)]) && !in_array($entry, $missing, true)) {
                $missing[] = $entry;
            }
        }

        return array_slice($missing, 0, 10);
    }

    private function remoteScanForView(?array $remoteScan, ?array $restrictedRemoteScan): ?array
    {
        $viewScan = $restrictedRemoteScan ?? $remoteScan;
        if (!is_array($remoteScan) || !is_array($viewScan) || RemoteScanSignal::unsavedResultState($remoteScan) === null) {
            return $viewScan;
        }

        return array_diff_key($viewScan, array_flip(['issues_total', 'issues_new', 'issues_resolved']));
    }

    /**
     * The reporting summary the Overview shows for a stored scan: none for a restricted view (Sol R05), and none while
     * the scan's results are not stored (F11-02) — the crawler's summary (priority fixes, WCAG summary, reporting groups,
     * score, signals) is then not fetched and nothing finding-derived is built from it. After persistence or recovery
     * the summary of the stored scan is used again.
     *
     * @param array<string, mixed>|null $remoteScan the stored (unrestricted) scan row
     * @return array<string, mixed>
     */
    private function remoteReportingSummaryForView(?array $remoteScan, bool $restrictedView, string $siteBase): array
    {
        if (is_array($remoteScan) && RemoteScanSignal::unsavedResultState($remoteScan) !== null) {
            return $this->remoteReportingSummaryService->buildForRemoteScan(null, $this->remoteIssueRepository);
        }

        return $this->resolveRemoteReportingSummary(is_array($remoteScan) && !$restrictedView ? $remoteScan : null, $siteBase);
    }

    /**
     * API /crawl/summary is the canonical source for PRO reporting when it
     * already contains wcagSummary/priorityFixes. The local builder is kept
     * as a fallback for older persisted scans and older API responses.
     *
     * @param array<string, mixed>|null $remoteScan
     * @return array{
     *   priorityFixes: array<int, array<string, mixed>>,
     *   priorityFixesVisible: array<int, array<string, mixed>>,
     *   priorityFixesMoreCount: int,
     *   wcagSummary: array<int, array<string, mixed>>
     * }
     */
    private function resolveRemoteReportingSummary(?array $remoteScan, string $siteBase): array
    {
        $apiSummary = $this->resolveRemoteReportingSummaryFromApi($remoteScan, $siteBase);
        if ($apiSummary !== null) {
            $this->logRemoteReportingDebug('AQG remote reporting summary resolved from API', [
                'remoteScan' => $this->buildRemoteScanDebugContext($remoteScan),
                'source' => 'api',
                'remotePriorityFixesCount' => count($apiSummary['priorityFixes'] ?? []),
                'remoteWcagSummaryCount' => count($apiSummary['wcagSummary'] ?? []),
            ]);
            return $apiSummary;
        }

        $fallbackSummary = $this->remoteReportingSummaryService->buildForRemoteScan(
            $remoteScan,
            $this->remoteIssueRepository
        );
        $this->logRemoteReportingDebug('AQG remote reporting summary resolved from DB fallback', [
            'remoteScan' => $this->buildRemoteScanDebugContext($remoteScan),
            'source' => 'db_fallback',
            'remotePriorityFixesCount' => count($fallbackSummary['priorityFixes'] ?? []),
            'remoteWcagSummaryCount' => count($fallbackSummary['wcagSummary'] ?? []),
        ]);

        return $fallbackSummary;
    }

    /**
     * @param array<string, mixed>|null $remoteScan
     * @return array{
     *   priorityFixes: array<int, array<string, mixed>>,
     *   priorityFixesVisible: array<int, array<string, mixed>>,
     *   priorityFixesMoreCount: int,
     *   wcagSummary: array<int, array<string, mixed>>
     * }|null
     */
    private function resolveRemoteReportingSummaryFromApi(?array $remoteScan, string $siteBase): ?array
    {
        // Every Overview request (each debounced search keystroke included) rendered this summary through a crawler
        // round trip. A completed job's summary no longer changes, so it is kept briefly per site and job; a failed
        // fetch is remembered for a minute so an unreachable crawler does not slow down every keystroke.
        $jobId = trim((string)($remoteScan['job_id'] ?? ''));
        $cacheKey = '';
        if ($jobId !== '' && strtolower(trim((string)($remoteScan['status'] ?? ''))) === 'completed') {
            // The summary carries labels in the backend language (RemoteReportingSummaryService), so it is kept per language.
            $language = str_starts_with(strtolower($this->getBackendLanguageCode()), 'de') ? 'de' : 'en';
            $cacheKey = 'overview_api_summary_' . sha1(rtrim($siteBase, '/') . '|' . $jobId . '|' . $language);
            $cached = $this->summaryCacheGet($cacheKey);
            if ($cached !== null) {
                return ($cached['summary'] ?? null) ?: null;
            }
        }

        $summary = $this->fetchRemoteReportingSummaryFromApi($remoteScan, $siteBase);
        if ($cacheKey !== '') {
            $this->summaryCacheSet($cacheKey, ['summary' => $summary], $summary === null ? self::API_SUMMARY_FAILURE_TTL : self::API_SUMMARY_TTL);
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function summaryCacheGet(string $key): ?array
    {
        if ($this->cacheManager === null) {
            return null;
        }
        try {
            $value = $this->cacheManager->getCache(\Priebera\A11yQualityGate\Pro\Configuration\ProConstants::CACHE_IDENTIFIER)->get($key);
        } catch (\Throwable) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function summaryCacheSet(string $key, array $value, int $lifetime): void
    {
        if ($this->cacheManager === null) {
            return;
        }
        try {
            $this->cacheManager->getCache(\Priebera\A11yQualityGate\Pro\Configuration\ProConstants::CACHE_IDENTIFIER)->set($key, $value, [], $lifetime);
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, mixed>|null $remoteScan
     * @return array<string, mixed>|null
     */
    private function fetchRemoteReportingSummaryFromApi(?array $remoteScan, string $siteBase): ?array
    {
        $jobId = trim((string)($remoteScan['job_id'] ?? ''));
        if ($jobId === '' || trim($siteBase) === '') {
            $this->logRemoteReportingDebug('AQG remote reporting skipped API summary fetch', [
                'remoteScan' => $this->buildRemoteScanDebugContext($remoteScan),
                'reason' => $jobId === '' ? 'missing_job_id' : 'missing_site_base',
            ]);
            return null;
        }

        $domain = '';
        try {
            $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase($siteBase);
            if ($domain === '') {
                $this->logRemoteReportingDebug('AQG remote reporting skipped API summary fetch', [
                    'remoteScan' => $this->buildRemoteScanDebugContext($remoteScan),
                    'reason' => 'missing_domain',
                ]);
                return null;
            }

            $this->logRemoteReportingDebug('AQG remote reporting requesting API summary', [
                'remoteScan' => $this->buildRemoteScanDebugContext($remoteScan),
                'apiSummaryEndpoint' => '/crawl/summary/' . $jobId,
                'jobId' => $jobId,
                'domain' => $domain,
            ]);

            $summaryResult = $this->proCrawlerService->getSummary(
                domain: $domain,
                version: $this->extensionContextService->getExtensionVersion(),
                jobId: $jobId,
            );
        } catch (\Throwable $exception) {
            $this->logRemoteReportingDebug('AQG remote reporting API summary fetch failed', [
                'remoteScan' => $this->buildRemoteScanDebugContext($remoteScan),
                'apiSummaryEndpoint' => '/crawl/summary/' . $jobId,
                'jobId' => $jobId,
                'domain' => $domain,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ], 'warning');
            return null;
        }

        $this->logRemoteReportingDebug('AQG remote reporting API summary response parsed', [
            'remoteScan' => $this->buildRemoteScanDebugContext($remoteScan),
            'apiSummaryEndpoint' => '/crawl/summary/' . $jobId,
            'jobId' => $jobId,
            'apiSummaryStatus' => 'success',
            'apiIssuesTotal' => $summaryResult->issuesTotal,
            'apiWcagSummaryCount' => count($summaryResult->wcagSummary),
            'apiPriorityFixesCount' => count($summaryResult->priorityFixes),
            'apiReportSummaryKeys' => array_keys($summaryResult->reportSummary),
        ]);

        $fallbackSummary = [];

        if ($summaryResult->wcagSummary === [] && $summaryResult->issuesTotal > 0) {
            $fallbackSummary = $this->remoteReportingSummaryService->buildForRemoteScan(
                $remoteScan,
                $this->remoteIssueRepository
            );

            $this->logRemoteReportingDebug('AQG remote reporting API summary missing WCAG breakdown; using DB WCAG fallback when available', [
                'remoteScan' => $this->buildRemoteScanDebugContext($remoteScan),
                'jobId' => $jobId,
                'apiIssuesTotal' => $summaryResult->issuesTotal,
                'apiPriorityFixesCount' => count($summaryResult->priorityFixes),
                'fallbackPriorityFixesCount' => count($fallbackSummary['priorityFixes'] ?? []),
                'fallbackWcagSummaryCount' => count($fallbackSummary['wcagSummary'] ?? []),
            ]);

            if ($summaryResult->priorityFixes === [] && ($fallbackSummary['priorityFixes'] ?? []) === [] && ($fallbackSummary['wcagSummary'] ?? []) === []) {
                return null;
            }
        }

        return $this->remoteReportingSummaryService->buildFromApiSummaryWithFallback(
            $summaryResult->wcagSummary,
            $summaryResult->priorityFixes,
            $fallbackSummary,
            $summaryResult->reportSummary,
            $summaryResult->manualReviewChecklist,
            $summaryResult->reportingGroups,
            $summaryResult->score,
            $summaryResult->keyboardSummary,
            $summaryResult->structureSummary,
            $summaryResult->contrastDetails,
            $summaryResult->remediationSummary,
            $summaryResult->componentSummary
        );
    }


    /**
     * @param array<string, mixed> $remoteScore
     */
    private function resolveRemoteScoreTone(array $remoteScore): string
    {
        if (!($remoteScore['hasValue'] ?? false)) {
            return 'unavailable';
        }

        $value = max(0, min(100, (int)($remoteScore['value'] ?? 0)));

        if ($value <= 24) {
            return 'critical';
        }
        if ($value <= 49) {
            return 'danger';
        }
        if ($value <= 74) {
            return 'warning';
        }
        if ($value <= 89) {
            return 'info';
        }

        return 'success';
    }

    /**
     * Audit C13: the Content status says when rendered checks did not run, so a scan with skipped pages never reads as
     * complete. The pages and reasons are listed (bounded, the count is not); older scans have no record and show none.
     * Pages visitors are not shown (hidden, scheduled, restricted) get one hint that says why the rendered check cannot
     * open them and which scan can.
     *
     * @param array<string, mixed>|null $scan
     * @return array{skipped: int, note: string, pages: list<array{pageUid: int, title: string, reason: string}>, more: string, notPublicHint: string}
     */
    private function buildLocalRenderedCoverage(?array $scan, bool $frontendScansAvailable, bool $hasScannerToken): array
    {
        $skipped = (int)($scan['rendered_skipped'] ?? 0);
        if ($scan === null || $skipped <= 0) {
            return ['skipped' => 0, 'note' => '', 'pages' => [], 'groups' => [], 'more' => '', 'notPublicHint' => ''];
        }

        $total = $skipped + (int)($scan['rendered_checked'] ?? 0);
        $note = $total <= 1
            ? $this->translateWithFallback('overview.local.renderedSkipped.only', 'Rendered HTML not checked')
            : sprintf(
                $this->translateWithFallback('overview.local.renderedSkipped.ofTotal', 'Rendered HTML not checked on %1$d of %2$d pages'),
                $skipped,
                $total
            );

        $translate = fn (string $key, string $fallback): string => $this->translateWithFallback($key, $fallback);
        $entries = json_decode((string)($scan['rendered_skipped_json'] ?? ''), true);
        $pages = [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            $pageUid = (int)($entry['pageUid'] ?? 0);
            // A page this user may not see is counted in "more", never named.
            if ($pageUid <= 0 || !$this->scopeAccessService->canReadPage($pageUid)) {
                continue;
            }
            $record = BackendUtility::getRecord('pages', $pageUid, 'title') ?? [];
            $pages[] = [
                'pageUid' => $pageUid,
                'title' => trim((string)($record['title'] ?? '')) !== '' ? trim((string)$record['title']) : '#' . $pageUid,
                'reason' => RenderedCoverage::describe(is_array($entry) ? $entry : [], $translate),
            ];
        }

        $more = $skipped - count($pages);
        $notPublicHint = '';
        if (RenderedCoverage::countNotPublic($scan) > 0) {
            $notPublicHint = $this->translateWithFallback(
                'overview.local.renderedSkipped.notPublicHint',
                'Visitors do not get hidden or restricted pages, and the rendered check requests each page as a visitor, without the Scanner Token. The content checks of these pages ran.'
            ) . ' ' . match (true) {
                !$frontendScansAvailable => $this->translateWithFallback(
                    'overview.local.renderedSkipped.notPublicHint.free',
                    'The Free Remote Preview checks public pages only.'
                ),
                $hasScannerToken => $this->translateWithFallback(
                    'overview.local.renderedSkipped.notPublicHint.token',
                    'A Frontend scan can check them: it sends this site\'s Scanner Token.'
                ),
                default => $this->translateWithFallback(
                    'overview.local.renderedSkipped.notPublicHint.noToken',
                    'A Frontend scan can check them once this site has a Scanner Token (Settings, Remote scan access).'
                ),
            };
        }

        return [
            'skipped' => $skipped,
            'note' => $note,
            'pages' => $pages,
            'groups' => RenderedCoverage::groupByReason($pages),
            'more' => $more > 0
                ? sprintf($this->translateWithFallback('overview.local.renderedSkipped.more', '%d more pages are not listed.'), $more)
                : '',
            'notPublicHint' => $notPublicHint,
        ];
    }

    /**
     * The scan row's resolved and ignored counts cover every page the scan processed. A user restricted by page
     * permissions (non-empty clause) gets no such count; "new" is recounted from readable pages (localNewCounts).
     *
     * @param array<string, mixed>|null $lastScan
     * @return array<string, mixed>|null
     */
    public static function withoutScanWideCounts(?array $lastScan, string $readablePagesClause): ?array
    {
        if ($lastScan === null || $readablePagesClause === '') {
            return $lastScan;
        }
        $lastScan['issues_resolved'] = 0;
        $lastScan['issues_ignored'] = 0;

        return $lastScan;
    }

    private function buildLocalScanScopeLabel(LocalOverviewScanSelection $selection): string
    {
        if ($selection->scope === LocalOverviewScanSelection::SCOPE_SITE) {
            return $this->translateWithFallback('overview.lastScan.scopeSite', 'Site scan');
        }

        if ($selection->scope !== LocalOverviewScanSelection::SCOPE_PAGE) {
            return '';
        }

        $page = $selection->scopePageUid > 0 ? BackendUtility::getRecord('pages', $selection->scopePageUid, 'title') : null;
        $title = trim((string)($page['title'] ?? ''));

        return sprintf(
            $this->translateWithFallback('overview.lastScan.scopePage', 'Page scan: %s'),
            $title !== '' ? $title : (string)$selection->scopePageUid
        );
    }

    private function resolveRemoteSignalLabel(string $remoteSignalTone): string
    {
        return match ($remoteSignalTone) {
            'critical' => $this->translateWithFallback('overview.remote.signal.critical', 'Critical'),
            'good' => $this->translateWithFallback('overview.remote.signal.good', 'Good automated signal'),
            'incomplete' => $this->translateWithFallback('overview.remote.signal.incomplete', 'Scan incomplete'),
            'no-coverage' => $this->translateWithFallback('overview.remote.signal.noCoverage', 'No page could be scanned'),
            'restricted' => $this->translateWithFallback('overview.remote.signal.restricted', 'Results limited to your access'),
            'pending' => $this->translateWithFallback('overview.remote.signal.pending', 'Results are being saved'),
            'not-stored' => $this->translateWithFallback('overview.remote.signal.notStored', 'Results could not be saved'),
            'no-scan' => $this->translateWithFallback('overview.remote.signal.noScanTitle', 'No frontend scan has been run yet'),
            default => $this->translateWithFallback('overview.remote.signal.attention', 'Needs attention'),
        };
    }

    private function recoverPendingRemoteScanPersistence(string $siteIdentifier, string $siteBase): void
    {
        $pendingScan = $this->remoteScanRepository->findUnpersistedCompletedScanBySite($siteIdentifier);
        if (!is_array($pendingScan)) {
            return;
        }

        $jobId = (string)($pendingScan['job_id'] ?? '');
        if ($jobId === '') {
            return;
        }

        try {
            $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase($siteBase);
            if ($domain === '') {
                return;
            }

            $version = $this->extensionContextService->getExtensionVersion();

            $summaryResult = $this->proCrawlerService->getSummary(
                domain: $domain,
                version: $version,
                jobId: $jobId,
            );

            $resultsResult = $this->proCrawlerService->getResults(
                domain: $domain,
                version: $version,
                jobId: $jobId,
            );

            $sourceType = RemoteScanSourceType::tryFrom((string)($pendingScan['source_type'] ?? ''))
                ?? RemoteScanSourceType::Crawl;

            $resultsPayload = [
                'status' => $summaryResult->status,
                'pagesScanned' => $summaryResult->pagesScanned,
                'pagesFailed' => $summaryResult->pagesFailed,
                'issuesTotal' => $summaryResult->issuesTotal,
                'issuesNew' => $summaryResult->issuesNew,
                'issuesResolved' => $summaryResult->issuesResolved,
                'startedAt' => $summaryResult->startedAt,
                'finishedAt' => $summaryResult->finishedAt,
                'pagesTotal' => $summaryResult->pagesScanned,
                'score' => $summaryResult->score,
                'keyboardSummary' => $summaryResult->keyboardSummary,
                'structureSummary' => $summaryResult->structureSummary,
                'contrastDetails' => $summaryResult->contrastDetails,
                'remediationSummary' => $summaryResult->remediationSummary,
                'componentSummary' => $summaryResult->componentSummary,
                'wcagSummary' => $summaryResult->wcagSummary,
                'priorityFixes' => $summaryResult->priorityFixes,
                'reportSummary' => $summaryResult->reportSummary,
                'manualReviewChecklist' => $summaryResult->manualReviewChecklist,
                'reportingGroups' => $summaryResult->reportingGroups,
                'pageUid' => (int)($pendingScan['page_uid'] ?? 0),
                'pages' => $resultsResult->pages,
            ];

            $this->remoteScanPersistenceService->persistResults(
                siteIdentifier: $siteIdentifier,
                jobId: $jobId,
                sourceType: $sourceType,
                startUrl: (string)($pendingScan['start_url'] ?? ''),
                sitemapUrl: ($pendingScan['sitemap_url'] ?? null) ?: null,
                resultsData: $resultsPayload,
            );
        } catch (\Throwable $exception) {
            $this->logRemoteReportingDebug('AQG remote scan result persistence failed', [
                'jobId' => $jobId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ], 'warning');
            $this->remoteScanRepository->markSyncError(
                $jobId,
                $this->remoteScanErrorPresenter->storedMessage($exception, 'Remote scan completed but its results could not be saved'),
            );
        }
    }

    private function hasEffectiveScannerToken(string $siteIdentifier): bool
    {
        // The token scans of this site actually send: the site's own, else the default one.
        if ($this->remoteScanAccessSettingsService !== null) {
            return $this->remoteScanAccessSettingsService->hasScannerTokenForSite($siteIdentifier);
        }

        return trim((string)($this->rulesetRepository->findDefault()['scanner_token'] ?? '')) !== '';
    }

    private function firstNonEmptyRulesetValue(?array $siteRuleset, ?array $defaultRuleset, string $field): string
    {
        $siteValue = trim((string)($siteRuleset[$field] ?? ''));
        if ($siteValue !== '') {
            return $siteValue;
        }

        return trim((string)($defaultRuleset[$field] ?? ''));
    }

    private function configureDocHeader(
        ModuleTemplate $moduleTemplate,
        array $returnParameters = []
    ): void {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        $this->setModuleTitle(
            $moduleTemplate,
            'module.title',
            'module.overview.title'
        );

        $backendUser = $this->backendContextService->getBackendUser();
        if ($this->accessControlService->canShowSettings($backendUser)) {
            $settingsButton = $buttonBar->makeLinkButton()
                ->setHref($this->buildRouteUrl('web_a11y.settings', $returnParameters))
                ->setTitle($this->translate('settings.title') ?: 'Settings')
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-cog', IconSize::SMALL));

            $buttonBar->addButton($settingsButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, array<string, mixed>>
     */
    private function enrichRemoteOverviewPages(array $pages, ?Site $site, int $languageUid): array
    {
        if (!$site instanceof Site || $pages === []) {
            return $pages;
        }

        foreach ($pages as &$page) {
            $page['remote_page_uid'] = (int)($page['uid'] ?? $page['remote_page_uid'] ?? 0);
            $page['remote_scan_uid'] = (int)($page['remote_scan_uid'] ?? $page['remote_scan'] ?? 0);

            $pageUid = (int)($page['typo3_page_uid'] ?? $page['page_uid'] ?? 0);
            if ($pageUid <= 0) {
                $pageUid = $this->frontendPageUrlService->resolvePageUidForUrl(
                    $site,
                    (string)($page['url'] ?? ''),
                    $languageUid
                );
            }

            if ($pageUid > 0) {
                $page['typo3_page_uid'] = $pageUid;
                $page['page_uid'] = $pageUid;
            }
        }
        unset($page);

        return $pages;
    }

    /**
     * The occurrence count of the shown frontend scan comes from its stored findings (the same rows as the CSV and the
     * PDF). When the crawler's run counter is higher, the difference is stated: it counted elements whose signature
     * duplicated one it had already recorded.
     *
     * @param array<string, mixed>|null $remoteScan
     * @return array{total: int, notStored: int, notStoredNote: string}
     */
    private function buildRemoteOccurrenceView(?array $remoteScan): array
    {
        // F11-02: nothing is stored yet, so "the crawler counted N more" would be the crawler's own, unsaved count.
        if ($remoteScan === null || RemoteScanSignal::unsavedResultState($remoteScan) !== null) {
            return ['total' => 0, 'notStored' => 0, 'notStoredNote' => ''];
        }
        if ($this->isRemoteViewRestricted()) {
            $restriction = $this->overviewRemoteRestriction ?? $this->remoteRestrictionFor($remoteScan) ?? new RemoteResultAuthorization(false, [], [], []);

            return ['total' => $restriction->totals()->total, 'notStored' => 0, 'notStoredNote' => ''];
        }
        if (!isset($this->remoteOccurrenceTotalsService)) {
            return ['total' => (int)($remoteScan['issues_total'] ?? 0), 'notStored' => 0, 'notStoredNote' => ''];
        }

        $totals = $this->remoteOccurrenceTotalsService->forScan($remoteScan);

        return [
            'total' => $totals->total,
            'notStored' => $totals->notStored,
            'notStoredNote' => $totals->notStored > 0
                ? sprintf(
                    $totals->notStored === 1
                        ? $this->translateWithFallback('overview.remote.status.notStored.singular', 'The crawler counted %d more occurrence: an element with the same signature was already recorded.')
                        : $this->translateWithFallback('overview.remote.status.notStored.plural', 'The crawler counted %d more occurrences: elements with the same signature were already recorded.'),
                    $totals->notStored
                )
                : '',
        ];
    }

    /**
     * The Affected pages rows use the Content table's pattern: occurrences next to issue types, and a scan action only
     * where the page scan endpoint would accept it (TSconfig flag and edit access to the mapped page). Free Remote
     * Preview keeps its single scan action in the status card.
     *
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, array<string, mixed>>
     */
    private function withRemoteRowData(array $pages, bool $isFreePreview): array
    {
        if ($pages === []) {
            return $pages;
        }

        // A restricted user's rows already carry the counts of their visible findings (restrictRemotePages()).
        $occurrences = $this->overviewRemoteRestriction !== null ? [] : $this->remoteIssueRepository->countOccurrencesByRemoteScanPage(
            array_map(static fn (array $page): int => (int)($page['uid'] ?? 0), $pages)
        );
        foreach ($pages as &$page) {
            $issueTypes = (int)($page['issues_count'] ?? 0);
            $page['occurrences'] = $this->overviewRemoteRestriction !== null
                ? (int)($page['issues_total'] ?? 0)
                : max($issueTypes, (int)($occurrences[(int)($page['uid'] ?? 0)] ?? 0));
            $mappedPageUid = (int)($page['typo3_page_uid'] ?? 0);
            $page['canScan'] = !$isFreePreview
                && $mappedPageUid > 0
                && $this->scanPermissionService !== null
                && $this->scanPermissionService->canScanPage($mappedPageUid);
        }
        unset($page);

        return $pages;
    }

    /**
     * The zero-match fallback of the frontend page search also matches the TYPO3 page ID resolved from a page URL.
     * Resolving it costs up to languages × slug variants of queries per page, for every page of the scan, while a page
     * ID is digits only: a query with any other character cannot match it, so only the IDs the filter can use are
     * resolved. The shown slice is enriched afterwards, so the rows and their links stay the same.
     *
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, array<string, mixed>>
     */
    private function enrichRemoteOverviewPagesForQuery(array $pages, ?Site $site, int $languageUid, string $query): array
    {
        $needle = trim($query);
        if ($needle !== '' && ctype_digit($needle)) {
            return $this->enrichRemoteOverviewPages($pages, $site, $languageUid);
        }

        foreach ($pages as &$page) {
            $page['remote_page_uid'] = (int)($page['uid'] ?? $page['remote_page_uid'] ?? 0);
            $page['remote_scan_uid'] = (int)($page['remote_scan_uid'] ?? $page['remote_scan'] ?? 0);
        }
        unset($page);

        return $pages;
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, array<string, mixed>>
     */
    private function filterRemoteOverviewPages(array $pages, string $query, bool $includeFailureReason): array
    {
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return $pages;
        }

        return array_values(array_filter(
            $pages,
            static function (array $page) use ($needle, $includeFailureReason): bool {
                $haystacks = [
                    (string)($page['title'] ?? ''),
                    (string)($page['url'] ?? ''),
                    (string)($page['http_status'] ?? ''),
                    (string)($page['uid'] ?? ''),
                    (string)($page['remote_page_uid'] ?? ''),
                    (string)($page['remote_scan_uid'] ?? $page['remote_scan'] ?? ''),
                    (string)($page['typo3_page_uid'] ?? $page['page_uid'] ?? ''),
                    (string)($page['external_page_id'] ?? ''),
                ];

                if ($includeFailureReason) {
                    $haystacks[] = (string)($page['failure_reason'] ?? '');
                }

                foreach ($haystacks as $haystack) {
                    if ($haystack !== '' && str_contains(mb_strtolower($haystack), $needle)) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }

    /**
     * The Overview with the frontend page list limited to one rule, or without the rule filter when $ruleId is
     * empty. Pagination restarts because the filtered list has its own pages.
     */
    private function buildRemoteRuleFilterUrl(ServerRequestInterface $request, string $siteIdentifier, string $ruleId): string
    {
        $parameters = $this->getA11yModuleReturnParameters($request);
        if ($siteIdentifier !== '') {
            $parameters['site'] = $siteIdentifier;
        }
        $parameters['language'] = $this->requestParameterService->getLanguageUid($request, 0);
        unset($parameters['languageUid'], $parameters['remotePage'], $parameters['remoteRule']);
        if ($ruleId !== '') {
            $parameters['remoteRule'] = $ruleId;
        }
        $parameters['aqgSource'] = 'remote';

        return $this->buildRouteUrl('web_a11y', $parameters) . '#a11y-remote-top-pages';
    }

    /**
     * @param array<int, array<string, mixed>> $priorityFixes
     */
    private function resolveRuleFilterLabel(string $ruleId, array $priorityFixes): string
    {
        foreach ($priorityFixes as $fix) {
            if (strcasecmp((string)($fix['ruleId'] ?? ''), $ruleId) === 0) {
                $title = trim((string)($fix['displayTitle'] ?? $fix['title'] ?? ''));
                if ($title !== '') {
                    return $title;
                }
            }
        }

        return $ruleId;
    }

    private function buildOverviewPaginationUrl(
        ServerRequestInterface $request,
        string $siteIdentifier,
        int $localPage,
        int $remotePage,
        int $remoteFailedPage,
        string $localQuery = '',
        string $remoteQuery = '',
        string $remoteFailedQuery = '',
    ): string {
        $parameters = $this->getA11yModuleReturnParameters($request);

        if ($siteIdentifier !== '') {
            $parameters['site'] = $siteIdentifier;
        }

        $languageUid = $this->requestParameterService->getLanguageUid($request, 0);
        $parameters['language'] = $languageUid;
        unset($parameters['languageUid']);

        if ($localPage > 1) {
            $parameters['localPage'] = $localPage;
        } else {
            unset($parameters['localPage']);
        }

        if ($remotePage > 1) {
            $parameters['remotePage'] = $remotePage;
        } else {
            unset($parameters['remotePage']);
        }

        if ($remoteFailedPage > 1) {
            $parameters['remoteFailedPage'] = $remoteFailedPage;
        } else {
            unset($parameters['remoteFailedPage']);
        }

        if ($localQuery !== '') {
            $parameters['localQuery'] = $localQuery;
        } else {
            unset($parameters['localQuery']);
        }

        if ($remoteQuery !== '') {
            $parameters['remoteQuery'] = $remoteQuery;
        } else {
            unset($parameters['remoteQuery']);
        }

        if ($remoteFailedQuery !== '') {
            $parameters['remoteFailedQuery'] = $remoteFailedQuery;
        } else {
            unset($parameters['remoteFailedQuery']);
        }

        $remoteRule = $this->requestParameterService->getRuleId($request, 'remoteRule');
        if ($remoteRule !== '') {
            $parameters['remoteRule'] = $remoteRule;
        } else {
            unset($parameters['remoteRule']);
        }

        return $this->buildRouteUrl('web_a11y', $parameters);
    }

    /**
     * @param array<string, mixed>|null $first
     * @param array<string, mixed>|null $second
     * @return array<string, mixed>|null
     */
    /**
     * @param array<string, mixed> $scanStatus
     */
    /**
     * STATE-01: "No open accessibility issues found" (green) only when the site's last content scan is a completed scan of
     * the whole site (from its root), which left no page out (ScanRepository::excludesPage()) and fetched every rendered page
     * it had to (pages visitors cannot open are skipped by design and reported separately), read by a user who sees every
     * result (administrators). Otherwise the zero is named for what it is.
     *
     * @param array<string, mixed>|null $lastScan
     * @return array{state: string, clean: bool, title: string}
     */
    private function localOverviewEvidence(?array $lastScan, int $siteRootPid, int $languageUid): array
    {
        $backendUser = $this->backendContextService->getBackendUser();
        $complete = ScanRepository::coversWholeSite($lastScan, $siteRootPid);
        $state = \Priebera\A11yQualityGate\Service\ResultEvidenceState::resolve(
            running: false,
            hasEvidence: is_array($lastScan),
            visibleOpen: 0,
            restricted: !($backendUser instanceof \TYPO3\CMS\Core\Authentication\BackendUserAuthentication && $backendUser->isAdmin())
                || !$this->scopeAccessService->canUseScanLanguage($languageUid),
            complete: $complete,
        );

        return [
            'state' => $state,
            'clean' => $state === \Priebera\A11yQualityGate\Service\ResultEvidenceState::CLEAN,
            'title' => $state === \Priebera\A11yQualityGate\Service\ResultEvidenceState::INCOMPLETE
                ? $this->translateWithFallback('overview.local.status.notFullyChecked', 'No open issues found, but the last scan did not check the whole site.')
                : $this->translateWithFallback('overview.local.status.restricted', 'No open issues in the pages and records you can access.'),
        ];
    }

    private function isRelevantLocalScanRunning(array $scanStatus, int $siteRootPid, int $currentPageUid): bool
    {
        if (!((bool)($scanStatus['running'] ?? false))) {
            return false;
        }

        $runningPageUid = (int)($scanStatus['pageUid'] ?? 0);
        $runningRootPid = (int)($scanStatus['rootPid'] ?? 0);

        if ($currentPageUid > 0 && $runningPageUid === $currentPageUid) {
            return true;
        }

        return $siteRootPid > 0 && $runningRootPid === $siteRootPid;
    }

    /**
     * A `remoteScanUid` request parameter selects an explicit record, so it must be re-validated
     * against the page the screen currently claims to show. Ownership (site + entitlement) alone is
     * not enough: a page-scoped scan for page A must never be rendered while page B is selected.
     *
     * @param array<string, mixed> $selectedScan
     */
    private function selectedScanBelongsToCurrentPageContext(
        array $selectedScan,
        bool $isPageContext,
        int $currentPageUid,
        string $currentPageUrl,
    ): bool {
        if ((string)($selectedScan['scan_scope'] ?? '') !== 'page') {
            // Site-wide results are not attributed to a single page.
            return true;
        }

        if (!$isPageContext || $currentPageUid <= 0) {
            // No page context to contradict, so a page-scoped result cannot be misattributed.
            return true;
        }

        $scanPageUid = (int)($selectedScan['page_uid'] ?? 0);
        if ($scanPageUid > 0) {
            return $scanPageUid === $currentPageUid;
        }

        // Legacy rows without page_uid are bound by their scanned URL instead.
        $scanStartUrl = $this->normalizeScanUrl((string)($selectedScan['start_url'] ?? ''));
        $contextUrl = $this->normalizeScanUrl($currentPageUrl);

        return $scanStartUrl !== '' && $contextUrl !== '' && $scanStartUrl === $contextUrl;
    }

    private function normalizeScanUrl(string $url): string
    {
        return ScanUrlUtility::comparable($url);
    }

    private function resolveOverviewRemoteScan(
        string $siteIdentifier,
        bool $isPageContext,
        int $currentPageUid,
        int $languageUid,
        string $currentPageUrl = '',
        int $selectedRemoteScanUid = 0,
        bool $isFreePreview = false,
        bool $isSiteRootPage = false,
    ): ?array {
        if ($selectedRemoteScanUid > 0) {
            $selectedScan = $this->remoteScanRepository->findScanByUid($selectedRemoteScanUid);
            $selectedScanScope = is_array($selectedScan) ? (string)($selectedScan['scan_scope'] ?? '') : '';
            // The site root also shows the page scans of the root page itself ("Scan this page" on the root).
            $isScanOfTheRootPage = $isSiteRootPage
                && $selectedScanScope === 'page'
                && is_array($selectedScan)
                && (int)($selectedScan['page_uid'] ?? 0) === $currentPageUid;
            $scopeMatchesContext = $isPageContext || $selectedScanScope === 'site' || $isScanOfTheRootPage;
            $selectedScanIsFreePreview = is_array($selectedScan) && (int)($selectedScan['is_free_preview'] ?? 0) === 1;
            // A scan of all languages (-1) belongs to every language view; otherwise only to its own language.
            $selectedScanLanguage = is_array($selectedScan) ? (int)($selectedScan['language_uid'] ?? -1) : -1;
            $languageMatches = $selectedScanLanguage < 0 || $languageUid < 0 || $selectedScanLanguage === $languageUid;

            if (is_array($selectedScan)
                && (string)($selectedScan['site_identifier'] ?? '') === $siteIdentifier
                && strtolower((string)($selectedScan['status'] ?? '')) === 'completed'
                && $scopeMatchesContext
                && $languageMatches
                && $selectedScanIsFreePreview === $isFreePreview
                && $this->selectedScanBelongsToCurrentPageContext(
                    $selectedScan,
                    $isPageContext,
                    $currentPageUid,
                    $currentPageUrl
                )
            ) {
                return $selectedScan;
            }
        }
        if ($isPageContext && $currentPageUid > 0) {
            $pageScan = $this->remoteScanRepository->findLastCompletedPageScanByPageOrUrl(
                $siteIdentifier,
                $currentPageUid,
                $languageUid,
                $currentPageUrl,
                $isFreePreview
            );

            if (is_array($pageScan)) {
                return $pageScan;
            }

            if ($isFreePreview) {
                return null;
            }
        }

        $siteScan = $this->remoteScanRepository->findLastCompletedSiteScanBySite($siteIdentifier, $languageUid, $isFreePreview);

        // The site root is the site context, but "Scan this page" there scans the root page itself. That result is the
        // root page's latest one when it is newer than the last site scan; otherwise it never appeared at all.
        if ($isSiteRootPage && $currentPageUid > 0) {
            $rootPageScan = $this->remoteScanRepository->findLastCompletedPageScanByPageOrUrl(
                $siteIdentifier,
                $currentPageUid,
                $languageUid,
                $currentPageUrl,
                $isFreePreview
            );
            if (is_array($rootPageScan)
                && (!is_array($siteScan) || $this->scanFinishedAt($rootPageScan) > $this->scanFinishedAt($siteScan))
            ) {
                return $rootPageScan;
            }
        }

        return $siteScan;
    }

    /** @param array<string, mixed> $scan */
    private function scanFinishedAt(array $scan): int
    {
        foreach (['finished_at', 'persisted_at', 'crdate'] as $field) {
            $value = (int)($scan[$field] ?? 0);
            if ($value > 0) {
                return $value;
            }
        }

        return 0;
    }


    /**
     * @return array<string, mixed>
     */
    private function buildRemoteHistoryForOverview(
        ServerRequestInterface $request,
        string $siteBase,
        string $siteIdentifier,
        int $currentPageUid,
        int $languageUid,
    ): array {
        $sitemapHistory = $this->remoteScanHistoryService->loadHistory(
            $siteBase,
            $siteIdentifier,
            10,
            'sitemap',
            '',
            'completed'
        );
        $crawlHistory = $this->remoteScanHistoryService->loadHistory(
            $siteBase,
            $siteIdentifier,
            10,
            'crawl',
            '',
            'completed'
        );
        $pageHistory = $this->remoteScanHistoryService->loadHistory(
            $siteBase,
            $siteIdentifier,
            10,
            'single_page',
            '',
            'completed'
        );

        $apiSiteScans = $this->mergeHistoryItemsByJobId(
            is_array($sitemapHistory['siteScans'] ?? null) ? $sitemapHistory['siteScans'] : [],
            is_array($crawlHistory['siteScans'] ?? null) ? $crawlHistory['siteScans'] : []
        );
        $apiPageScans = is_array($pageHistory['pageScans'] ?? null) ? $pageHistory['pageScans'] : [];

        // Local DB is the authoritative TYPO3-side persistence after a remote job was
        // submitted/summarized. Always merge it into the history panel instead of only
        // using it as a fallback. This prevents the Overview from showing the global
        // empty state when the API history request is stale/unavailable while completed
        // single_page scans already exist locally for the resolved site_identifier.
        $localSiteScans = $this->buildLocalRemoteHistoryItems(
            $siteIdentifier,
            ['sitemap', 'crawl', 'site'],
            10,
            -1
        );
        $localPageScans = $this->buildLocalRemoteHistoryItems(
            $siteIdentifier,
            ['single_page', 'page'],
            10,
            -1
        );

        $siteScans = $this->withStoredHistoryTotals(array_slice(
            $this->mergeHistoryItemsByJobId($apiSiteScans, $localSiteScans),
            0,
            10
        ));
        $pageScans = $this->withStoredHistoryTotals(array_slice(
            $this->mergeHistoryItemsByJobId($apiPageScans, $localPageScans),
            0,
            10
        ));

        foreach ($siteScans as &$item) {
            if (is_array($item)) {
                $item = $this->enrichRemoteHistoryItemUrls($request, $item, $siteIdentifier, $currentPageUid, $languageUid);
            }
        }
        unset($item);

        foreach ($pageScans as &$item) {
            if (is_array($item)) {
                $item = $this->enrichRemoteHistoryItemUrls($request, $item, $siteIdentifier, $currentPageUid, $languageUid);
            }
        }
        unset($item);

        $items = array_values(array_merge($siteScans, $pageScans));
        $siteScansCount = count($siteScans);
        $pageScansCount = count($pageScans);
        $hasSiteScans = $siteScansCount > 0;
        $hasPageScans = $pageScansCount > 0;
        $hasAnyRows = $siteScansCount > 0 || $pageScansCount > 0;
        $showGlobalEmpty = $siteScansCount === 0 && $pageScansCount === 0;
        $siteAvailable = (bool)($sitemapHistory['available'] ?? false) || (bool)($crawlHistory['available'] ?? false);
        $pageAvailable = (bool)($pageHistory['available'] ?? false);

        return [
            'available' => $siteAvailable || $pageAvailable || $hasAnyRows,
            'hasItems' => $hasAnyRows ? 1 : 0,
            'hasAnyRows' => $hasAnyRows ? 1 : 0,
            'showScopedHistory' => $hasAnyRows ? 1 : 0,
            'showGlobalEmpty' => $showGlobalEmpty ? 1 : 0,
            'message' => $showGlobalEmpty ? 'No remote scan history available yet.' : '',
            'items' => $items,
            'siteScans' => $siteScans,
            'pageScans' => $pageScans,
            'siteScansCount' => $siteScansCount,
            'pageScansCount' => $pageScansCount,
            'hasSiteScans' => $hasSiteScans ? 1 : 0,
            'hasPageScans' => $hasPageScans ? 1 : 0,
            'siteMessage' => $hasSiteScans ? '' : 'No site scan history available yet.',
            'pageMessage' => $hasPageScans ? '' : 'No page scan history available yet.',
            'debugSiteIdentifier' => $siteIdentifier,
            'debugSiteApiCount' => count(is_array($sitemapHistory['items'] ?? null) ? $sitemapHistory['items'] : []) + count(is_array($crawlHistory['items'] ?? null) ? $crawlHistory['items'] : []),
            'debugPageApiCount' => count(is_array($pageHistory['items'] ?? null) ? $pageHistory['items'] : []),
            'debugLocalSiteCount' => count($localSiteScans),
            'debugLocalPageCount' => count($localPageScans),
            'stored' => $this->buildStoredHistoryPage($request, $siteIdentifier, $currentPageUid, $languageUid, $siteScansCount),
        ];
    }

    /**
     * Every completed site scan stored in this installation, page by page (`historyPage`, 25 per page, newest first):
     * the list above shows only the latest ten, and the scan history may be kept for years (Settings › Scan history).
     * Rows link to the stored report and to the previous compatible scan, as in the list above.
     *
     * @return array<string, mixed>
     */
    private function buildStoredHistoryPage(
        ServerRequestInterface $request,
        string $siteIdentifier,
        int $currentPageUid,
        int $languageUid,
        int $recentShown,
    ): array {
        $sourceTypes = ['sitemap', 'crawl', 'site'];
        $total = $this->remoteScanRepository->countCompletedHistoryScansBySiteAndSourceTypes($siteIdentifier, $sourceTypes);
        $pages = max(1, (int)ceil($total / self::STORED_HISTORY_PAGE_SIZE));
        $requested = (int)($request->getQueryParams()['historyPage'] ?? 0);
        $pageUrl = function (int $page) use ($request, $siteIdentifier, $currentPageUid, $languageUid): string {
            $parameters = $this->getA11yModuleReturnParameters($request);
            $parameters['site'] = $siteIdentifier;
            $parameters['language'] = $languageUid;
            if ($currentPageUid > 0) {
                $parameters['id'] = $currentPageUid;
            }
            $parameters['historyPage'] = $page;
            // The history is on the Frontend scan tab: open that tab, or the anchor would point into a hidden panel.
            $parameters['aqgSource'] = 'remote';

            return $this->buildRouteUrl('web_a11y', $parameters) . '#aqg-evidence-history';
        };

        $result = [
            'active' => false,
            'total' => $total,
            'browseUrl' => $total > $recentShown ? $pageUrl(1) : '',
        ];
        if ($requested < 1 || $total === 0) {
            return $result;
        }

        $page = min($requested, $pages);
        $items = $this->withStoredHistoryTotals($this->buildLocalRemoteHistoryItems(
            $siteIdentifier,
            $sourceTypes,
            self::STORED_HISTORY_PAGE_SIZE,
            -1,
            ($page - 1) * self::STORED_HISTORY_PAGE_SIZE,
            true,
        ));
        foreach ($items as &$item) {
            $item = $this->enrichRemoteHistoryItemUrls($request, $item, $siteIdentifier, $currentPageUid, $languageUid);
        }
        unset($item);

        return [
            'active' => true,
            'total' => $total,
            'browseUrl' => '',
            'items' => $items,
            'page' => $page,
            'pages' => $pages,
            'first' => ($page - 1) * self::STORED_HISTORY_PAGE_SIZE + 1,
            'last' => ($page - 1) * self::STORED_HISTORY_PAGE_SIZE + count($items),
            'newerUrl' => $page > 1 ? $pageUrl($page - 1) : '',
            'olderUrl' => $page < $pages ? $pageUrl($page + 1) : '',
            'closeUrl' => $pageUrl(0),
        ];
    }

    /**
     * @param list<string> $sourceTypes
     * @return list<array<string, mixed>>
     */
    private function buildLocalRemoteHistoryItems(
        string $siteIdentifier,
        array $sourceTypes,
        int $limit,
        int $languageUid,
        int $offset = 0,
        bool $withComparablePrevious = false,
    ): array {
        $rows = $this->remoteScanRepository->findCompletedHistoryScansBySiteAndSourceTypes(
            $siteIdentifier,
            $sourceTypes,
            $limit,
            $languageUid,
            $offset
        );

        $items = [];
        foreach ($rows as $row) {
            $jobId = trim((string)($row['job_id'] ?? ''));
            if ($jobId === '') {
                continue;
            }

            $sourceType = strtolower(trim((string)($row['source_type'] ?? '')));
            $isPageScan = in_array($sourceType, ['single_page', 'page'], true);
            $finishedAt = (int)($row['finished_at'] ?? 0);
            $createdAt = (int)($row['started_at'] ?? $row['crdate'] ?? 0);
            $startUrl = trim((string)($row['start_url'] ?? ''));
            $items[] = [
                'jobId' => $jobId,
                'jobIdShort' => substr($jobId, 0, 8) . '…',
                'siteId' => (string)($row['site_identifier'] ?? $siteIdentifier),
                'status' => (string)($row['status'] ?? ''),
                'sourceType' => $sourceType,
                'sourceTypeLabel' => $isPageScan ? 'Page scan' : 'Site scan',
                'isSiteScan' => !$isPageScan,
                'isPageScan' => $isPageScan,
                'startUrl' => $startUrl,
                'normalizedStartUrl' => $this->normalizeHistoryUrl($startUrl),
                'urlLabel' => $startUrl !== '' ? $startUrl : ($isPageScan ? 'Page scan' : 'Site scan'),
                'createdAt' => $createdAt,
                'createdAtFormatted' => BackendTimeUtility::formatDateTime($createdAt, 'd.m.Y H:i'),
                'finishedAt' => $finishedAt,
                'finishedAtFormatted' => BackendTimeUtility::formatDateTime($finishedAt, 'd.m.Y H:i'),
                'pagesScanned' => max(0, (int)($row['pages_scanned'] ?? 0)),
                'issuesTotal' => max(0, (int)($row['issues_total'] ?? 0)),
                'score' => null,
                'scoreLabel' => '—',
                'overallImpact' => '',
                'critical' => 0,
                'serious' => 0,
                'moderate' => 0,
                'minor' => 0,
                'contractVersion' => '',
                'viewReportUrl' => '',
                'viewReportRemotePageUid' => 0,
                'compareFromJobId' => '',
                'compareUrl' => '',
                'compareLabel' => $this->translateWithFallback('remote.history.noComparable', 'No comparable previous scan found.'),
                'hasComparablePrevious' => false,
                'localScan' => [
                    'uid' => (int)($row['uid'] ?? 0),
                    'issues_total' => (int)($row['issues_total'] ?? 0),
                    'persisted_at' => (int)($row['persisted_at'] ?? 0),
                ],
            ];
            if ($withComparablePrevious) {
                // Only a compatible earlier scan (site, scope, source, language, start URL, Free vs paid) is offered;
                // the comparison itself checks the pair again (resolveComparePair()).
                $previous = $this->remoteScanPairingService->findPreviousCompatible($row);
                $previousJobId = is_array($previous) ? trim((string)($previous['job_id'] ?? '')) : '';
                if ($previousJobId !== '') {
                    $last = array_key_last($items);
                    $items[$last]['compareFromJobId'] = $previousJobId;
                    $items[$last]['hasComparablePrevious'] = true;
                    $items[$last]['compareLabel'] = $this->translateWithFallback('remote.history.compareWithPrevious', 'Compare with previous compatible scan');
                }
            }
        }

        return $items;
    }

    /**
     * History rows known only from the local database (the AQG API returns the scans of the current installation
     * identity only) have no score or impact figures from the API. Their occurrences and impact breakdown come from the
     * stored findings, as in the Overview; without stored findings the breakdown is unknown and shown as "—", never 0.
     * Rows the API returned keep its figures.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function withStoredHistoryTotals(array $items): array
    {
        foreach ($items as &$item) {
            $localScan = $item['localScan'] ?? null;
            unset($item['localScan']);
            if (!is_array($localScan)) {
                continue;
            }

            $totals = $this->remoteOccurrenceTotalsService?->forScan($localScan);
            foreach (RemoteOccurrenceTotals::IMPACTS as $impact) {
                $item[$impact] = $totals !== null && $totals->hasBreakdown ? $totals->byImpact[$impact] : '—';
            }
            if ($totals !== null && $totals->hasBreakdown) {
                $item['issuesTotal'] = $totals->total;
            }
        }
        unset($item);

        return $items;
    }

    private function normalizeHistoryUrl(string $url): string
    {
        $url = trim(strtolower($url));
        if ($url === '') {
            return '';
        }

        $parts = \Priebera\A11yQualityGate\Utility\UrlAuthority::parseUrl($url);
        if (!is_array($parts)) {
            return rtrim($url, '/');
        }

        $scheme = (string)($parts['scheme'] ?? '');
        $host = (string)($parts['host'] ?? '');
        $path = '/' . ltrim((string)($parts['path'] ?? '/'), '/');
        $query = isset($parts['query']) && (string)$parts['query'] !== '' ? '?' . (string)$parts['query'] : '';

        return rtrim($scheme . '://' . $host . $path, '/') . $query;
    }

    /**
     * @param array<int, mixed> ...$buckets
     * @return array<int, array<string, mixed>>
     */
    private function mergeHistoryItemsByJobId(array ...$buckets): array
    {
        $merged = [];
        foreach ($buckets as $bucket) {
            foreach ($bucket as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $jobId = trim((string)($item['jobId'] ?? ''));
                if ($jobId === '' || isset($merged[$jobId])) {
                    continue;
                }
                $merged[$jobId] = $item;
            }
        }

        $items = array_values($merged);
        usort($items, static function (array $a, array $b): int {
            $finished = (int)($b['finishedAt'] ?? 0) <=> (int)($a['finishedAt'] ?? 0);
            if ($finished !== 0) {
                return $finished;
            }

            return strcmp((string)($b['jobId'] ?? ''), (string)($a['jobId'] ?? ''));
        });

        return array_slice($items, 0, 10);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function enrichRemoteHistoryItemUrls(
        ServerRequestInterface $request,
        array $item,
        string $siteIdentifier,
        int $currentPageUid,
        int $languageUid,
    ): array {
        $jobId = trim((string)($item['jobId'] ?? ''));
        $localScan = $jobId !== '' ? $this->remoteScanRepository->findScanByJobId($jobId) : null;
        $remoteScanUid = is_array($localScan) ? (int)($localScan['uid'] ?? 0) : 0;
        $startUrl = (string)($item['startUrl'] ?? '');

        $item['viewReportUrl'] = '';
        $item['viewReportLabel'] = 'Not available in TYPO3';

        if (!empty($item['isPageScan'])) {
            $page = $jobId !== '' && $startUrl !== ''
                ? $this->remoteScanRepository->findPageForJobIdAndUrl($jobId, $startUrl, $siteIdentifier, 'single_page')
                : null;
            if (is_array($page) && (int)($page['uid'] ?? 0) > 0 && (string)($page['remote_scan_job_id'] ?? '') === $jobId) {
                $item['viewReportUrl'] = $this->buildRouteUrl('web_a11y.remotePageDetail', [
                    'remotePageUid' => (int)$page['uid'],
                    'site' => $siteIdentifier,
                    'id' => (int)($page['remote_scan_page_uid'] ?? $currentPageUid),
                    'language' => (int)($page['remote_scan_language_uid'] ?? $languageUid),
                ]);
                $item['viewReportLabel'] = 'View report';
                $item['viewReportRemotePageUid'] = (int)$page['uid'];
            }
        }

        if ($remoteScanUid > 0 && empty($item['isPageScan']) && (string)($item['viewReportUrl'] ?? '') === '') {
            $item['viewReportUrl'] = $this->buildRouteUrl('web_a11y', [
                'id' => $currentPageUid,
                'site' => $siteIdentifier,
                'language' => $languageUid,
                'remoteScanUid' => $remoteScanUid,
            ]);
            $item['viewReportLabel'] = 'View report';
        }

        if (!empty($item['hasComparablePrevious']) && (string)($item['compareFromJobId'] ?? '') !== '' && $jobId !== '') {
            $parameters = $this->getA11yModuleReturnParameters($request);
            $parameters['site'] = $siteIdentifier;
            $parameters['language'] = $languageUid;
            if ($currentPageUid > 0) {
                $parameters['id'] = $currentPageUid;
            }
            $parameters['compareFromJobId'] = (string)$item['compareFromJobId'];
            $parameters['compareToJobId'] = $jobId;
            if ($remoteScanUid > 0) {
                $parameters['remoteScanUid'] = $remoteScanUid;
            }
            $item['compareUrl'] = $this->buildRouteUrl('web_a11y', $parameters) . '#scan-comparison';
        }

        return $item;
    }

    /**
     * @param array<string, mixed>|null $remoteScan
     * @return array<string, mixed>
     */
    private function buildOverviewRegressionAlert(
        ServerRequestInterface $request,
        string $siteBase,
        string $siteIdentifier,
        ?array $remoteScan,
        int $currentPageUid,
        int $languageUid
    ): array {
        // N-14: without a stored frontend scan there is nothing to compare. Asking the AQG service anyway requested a
        // crawler token on every Overview render (also during content scans) and used up the rate limit.
        if (!is_array($remoteScan) || trim((string)($remoteScan['job_id'] ?? '')) === '') {
            return ['available' => false, 'message' => ''];
        }
        $sourceType = strtolower(trim((string)($remoteScan['source_type'] ?? 'sitemap')));
        // Audit C16: a page scan is compared as a page scan. Asking for a sitemap comparison with the page's URL
        // matched no pair and answered 404 on every render.
        if (!in_array($sourceType, ['sitemap', 'crawl', 'single_page'], true)) {
            $sourceType = 'sitemap';
        }

        // The start URL is the language's base, so the API compares scans of the same language only.
        $alert = $this->remoteScanHistoryService->loadRegressionAlert(
            $siteBase,
            $siteIdentifier,
            $sourceType,
            trim((string)($remoteScan['start_url'] ?? '')),
            $languageUid >= 0 ? $languageUid : null,
            is_array($remoteScan)
                ? trim((string)($remoteScan['job_id'] ?? '')) . '@' . (int)($remoteScan['finished_at'] ?? 0)
                : '',
        );
        $alert = $this->guardRegressionPair($alert, $siteIdentifier);

        $previousJobId = trim((string)($alert['previousJobId'] ?? ''));
        $currentJobId = trim((string)($alert['currentJobId'] ?? ''));
        $actionType = strtolower(trim((string)($alert['actionType'] ?? '')));
        $alert = $this->enrichRegressionAlertWithLocalScanRows($alert);

        if ($previousJobId === '' || $currentJobId === '' || ($actionType !== '' && $actionType !== 'compare')) {
            $alert['actionUrl'] = '';
            return $alert;
        }

        $parameters = $this->getA11yModuleReturnParameters($request);
        $parameters['site'] = $siteIdentifier;
        $parameters['language'] = $languageUid;
        if ($currentPageUid > 0) {
            $parameters['id'] = $currentPageUid;
        }
        $parameters['compareFromJobId'] = $previousJobId;
        $parameters['compareToJobId'] = $currentJobId;

        $currentLocalScan = $this->remoteScanRepository->findScanByJobId($currentJobId);
        if (is_array($currentLocalScan) && (int)($currentLocalScan['uid'] ?? 0) > 0) {
            $parameters['remoteScanUid'] = (int)$currentLocalScan['uid'];
        }

        $alert['actionUrl'] = $this->buildRouteUrl('web_a11y', $parameters) . '#scan-comparison';

        return $alert;
    }

    /**
     * The API pairs scans by its own history, which an older API or another installation using the same
     * licence and site identifier can widen. A signal is only shown for two scans stored here that are
     * compatible — same scope, type, language and start URL.
     *
     * @param array<string, mixed> $alert
     * @return array<string, mixed>
     */
    private function guardRegressionPair(array $alert, string $siteIdentifier): array
    {
        $previousJobId = trim((string)($alert['previousJobId'] ?? ''));
        $currentJobId = trim((string)($alert['currentJobId'] ?? ''));
        if ($previousJobId === '' || $currentJobId === ''
            || $this->remoteScanPairingService->resolveComparePair($siteIdentifier, $previousJobId, $currentJobId) !== null) {
            return $alert;
        }

        return $this->remoteScanHistoryService->emptyRegressionAlert(
            $this->translateWithFallback('remote.regression.error.noHistory', 'No regression signal yet: this page has no compatible frontend scans to compare.')
        );
    }

    /**
     * @param array<string, mixed> $alert
     * @return array<string, mixed>
     */
    private function enrichRegressionAlertWithLocalScanRows(array $alert): array
    {
        foreach ([
            'previous' => 'previousScan',
            'current' => 'currentScan',
        ] as $prefix => $scanKey) {
            if (!isset($alert[$scanKey]) || !is_array($alert[$scanKey])) {
                $alert[$scanKey] = [];
            }

            $jobId = trim((string)($alert[$prefix . 'JobId'] ?? $alert[$scanKey]['jobId'] ?? ''));
            if ($jobId === '') {
                continue;
            }

            $scan = $this->remoteScanRepository->findScanByJobId($jobId);
            if (!is_array($scan)) {
                continue;
            }

            $alert[$scanKey]['jobId'] = $jobId;
            $finishedAt = (int)($scan['finished_at'] ?? 0);
            if ($finishedAt > 0 && trim((string)($alert[$scanKey]['finishedAtFormatted'] ?? '')) === '') {
                $alert[$scanKey]['finishedAt'] = $finishedAt;
                $alert[$scanKey]['finishedAtFormatted'] = BackendTimeUtility::formatDateTime($finishedAt, 'd.m.Y H:i');
            }

            if ((string)($alert[$scanKey]['findingsLabel'] ?? '—') === '—') {
                $findings = max(0, (int)($scan['issues_total'] ?? 0));
                $alert[$scanKey]['findings'] = $findings;
                $alert[$scanKey]['findingsLabel'] = (string)$findings;
            }
        }

        $previousFindings = $alert['previousScan']['findings'] ?? null;
        $currentFindings = $alert['currentScan']['findings'] ?? null;
        if (($alert['comparisonRows'] ?? []) === [] && is_int($previousFindings) && is_int($currentFindings)) {
            $delta = $currentFindings - $previousFindings;
            $alert['comparisonRows'] = [[
                'label' => $this->translateWithFallback('remote.regression.findingsChange', 'Occurrences change'),
                'value' => $delta > 0 ? '+' . $delta : (string)$delta,
                'tone' => $delta > 0 ? 'warning' : ($delta < 0 ? 'positive' : 'neutral'),
            ]];
            $alert['hasComparisonRows'] = true;
        }

        $alert['hasScanComparison'] = trim((string)($alert['previousScan']['finishedAtFormatted'] ?? '')) !== ''
            || trim((string)($alert['currentScan']['finishedAtFormatted'] ?? '')) !== ''
            || (string)($alert['previousScan']['findingsLabel'] ?? '—') !== '—'
            || (string)($alert['currentScan']['findingsLabel'] ?? '—') !== '—'
            || (string)($alert['previousScan']['scoreLabel'] ?? '—') !== '—'
            || (string)($alert['currentScan']['scoreLabel'] ?? '—') !== '—'
            || ($alert['comparisonRows'] ?? []) !== [];

        return $alert;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRemoteScanCompare(ServerRequestInterface $request, string $siteBase, string $siteIdentifier): array
    {
        $queryParams = $request->getQueryParams();
        $fromJobId = trim((string)($queryParams['compareFromJobId'] ?? ''));
        $toJobId = trim((string)($queryParams['compareToJobId'] ?? ''));
        if ($fromJobId === '' || $toJobId === '') {
            return ['available' => false, 'message' => ''];
        }

        // Job IDs come from the URL: only two readable, compatible scans of this site are compared.
        // Sol R05: the AQG service compares every page of both scans; only administrators read all of them.
        if ($this->remoteResultAuthorizationService === null || !$this->remoteResultAuthorizationService->isUnrestrictedUser()) {
            return [
                'available' => false,
                'message' => $this->translateWithFallback('remote.comparison.restricted', 'The comparison covers every page of both scans, including pages and records you cannot open. Only administrators can open it.'),
            ];
        }
        $pair = $this->remoteScanPairingService->resolveComparePair($siteIdentifier, $fromJobId, $toJobId);
        if ($pair === null) {
            return [
                'available' => false,
                'message' => $this->translateWithFallback('remote.comparison.error.incompatible', 'The selected scans cannot be compared.'),
            ];
        }
        // F12-R2 (F11-02): a scan whose results are not stored has no saved findings to compare; the AQG service's
        // comparison would present crawler-only new/resolved counts as a result.
        if (RemoteScanSignal::unsavedResultState($pair['from']) !== null || RemoteScanSignal::unsavedResultState($pair['to']) !== null) {
            return [
                'available' => false,
                'message' => $this->translateWithFallback('remote.comparison.unsaved', 'The comparison is available once the results of both scans are saved.'),
            ];
        }

        $comparison = $this->remoteScanHistoryService->loadCompare($siteBase, (string)$pair['from']['job_id'], (string)$pair['to']['job_id']);
        if ($comparison['available'] ?? false) {
            $comparison['acceptanceEvidence'] = $this->buildAcceptanceEvidenceLinks($siteIdentifier, $pair);
        }

        return $comparison;
    }

    /**
     * @param array<string, mixed>|null $remoteScan
     * @return array<string, mixed>
     */
    private function buildRemoteScanDebugContext(?array $remoteScan): array
    {
        if ($remoteScan === null) {
            return [
                'uid' => 0,
                'job_id' => '',
                'scan_scope' => '',
                'source_type' => '',
                'issues_total' => 0,
                'page_uid' => 0,
                'language_uid' => -1,
            ];
        }

        return [
            'uid' => (int)($remoteScan['uid'] ?? 0),
            'job_id' => (string)($remoteScan['job_id'] ?? ''),
            'scan_scope' => (string)($remoteScan['scan_scope'] ?? ''),
            'source_type' => (string)($remoteScan['source_type'] ?? ''),
            'issues_total' => (int)($remoteScan['issues_total'] ?? 0),
            'page_uid' => (int)($remoteScan['page_uid'] ?? 0),
            'language_uid' => (int)($remoteScan['language_uid'] ?? -1),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logRemoteHistoryDebug(string $message, array $context = []): void
    {
        try {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(__CLASS__)
                ->debug($message, $context);
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logRemoteReportingDebug(string $message, array $context = [], string $level = 'debug'): void
    {
        try {
            if ($level === 'debug' && !$this->isRemoteReportingDebugEnabled()) {
                return;
            }

            $logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
            if ($level === 'warning') {
                $logger->warning($message, $context);
                return;
            }

            $logger->debug($message, $context);
        } catch (\Throwable) {
        }
    }

    private function isRemoteReportingDebugEnabled(): bool
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $queryParams = $request->getQueryParams();
            $debugValue = strtolower(trim((string)($queryParams['aqgRemoteReportingDebug'] ?? '')));

            return in_array($debugValue, ['1', 'true', 'yes'], true);
        }

        return false;
    }

    private function resolveOverviewActiveRemoteScan(
        string $siteIdentifier,
        bool $isPageContext,
        int $currentPageUid,
        int $languageUid,
    ): ?array {
        $activeScan = $this->remoteScanRepository->findLatestActiveScanBySite($siteIdentifier);
        if (!is_array($activeScan)) {
            return null;
        }

        // Read back from the AQG service before the scan is shown and restored as running, as the Page module and the
        // page detail do. A job that service no longer serves to this installation — removed, or submitted under an
        // earlier licence or Agency project — is recorded as failed instead of coming back as a scan that fails.
        $siteBase = $this->siteResolutionService->resolveSiteBaseByIdentifier($siteIdentifier);
        if ($siteBase !== '' && $this->remoteScanRecoveryService instanceof RemoteScanRecoveryService) {
            $activeScan = $this->remoteScanRecoveryService->recoverScanIfNeeded($activeScan, $siteBase) ?? $activeScan;
        }

        $status = (string)($activeScan['status'] ?? '');
        $isUnfinished = in_array($status, ['waiting', 'queued', 'active', 'running'], true)
            || ($status === 'completed' && (int)($activeScan['persisted_at'] ?? 0) <= 0);

        return $isUnfinished ? $activeScan : null;
    }

    /**
     * Acceptance evidence links for a validated pair of compatible scans: PRO/Agency, and the same edit
     * access as any remote export. The export action checks all of it again.
     *
     * @param array{from:array<string, mixed>,to:array<string, mixed>} $pair
     * @return array{pdfUrl:string,csvUrl:string}|array{}
     */
    private function buildAcceptanceEvidenceLinks(string $siteIdentifier, array $pair): array
    {
        $proStatus = $this->proStatusResolverService->resolveForSiteIdentifier($siteIdentifier);
        if (!(bool)($proStatus->valid ?? false) || (bool)($proStatus->isTrial ?? false) || !(bool)($proStatus->hasExportPdf ?? false)
            || !$this->scopeAccessService->canEditRemoteScan($pair['to'])) {
            return [];
        }

        $parameters = [
            'site' => $siteIdentifier,
            'fromJobId' => (string)$pair['from']['job_id'],
            'toJobId' => (string)$pair['to']['job_id'],
        ];

        return [
            'pdfUrl' => $this->buildRouteUrl('web_a11y.exportAcceptance', $parameters + ['format' => 'pdf']),
            'csvUrl' => $this->buildRouteUrl('web_a11y.exportAcceptance', $parameters + ['format' => 'csv']),
        ];
    }

    /**
     * Three honest counts for the Overview's AI activity line (Atlas 07·E): waiting for review (Suggested), saved this
     * week, verified by re-check — never merged. Hidden without the queue service or the PRO/Agency licence.
     * The same rows give the sub-navigation's open count (as the queue counts "open"), shown only when AI is ready.
     *
     * @return array{activity: array<string,mixed>|null, openCount: int}
     */
    /** A fix without an owner tag and without the owner selector. */
    private const NO_PRIORITY_FIX_OWNER = ['assignedOwner' => '', 'assignedOwnerLabel' => '', 'ownerEditable' => false, 'ownerCurrent' => '', 'ownerPageUid' => 0];

    /**
     * Owner tags of the Overview's "What to fix first" (Atlas 07·E, F-08): an issue type shows an owner only when one
     * is assigned to it on a page this person may open (RemediationAssignedOwnerService). AQG's suggested owner stays
     * in the closed Details. Nothing for a Free licence or a Free Remote Preview result, nor without PRO/Agency.
     *
     * The owner selector in the fix's closed Details assigns the frontend rule on the site root page (route
     * a11y_remediation_owner). It is offered only to people with edit access to the site root, and only for rules a
     * stored paid frontend scan of the site found — exactly what the route accepts (RemediationOwnerRuleValidator).
     *
     * The list holds frontend (crawler) rules; no frontend rule has an AI flow (every AI suggestion runs on a stored
     * content finding), so the list carries no AI tag.
     *
     * @param array<int,array<string,mixed>> $priorityFixes
     * @return array<string,array{assignedOwner:string,assignedOwnerLabel:string,ownerEditable:bool,ownerCurrent:string,ownerPageUid:int}>
     */
    private function priorityFixOwnerTags(string $siteIdentifier, int $siteRootPid, array $priorityFixes, bool $isFree): array
    {
        if ($isFree || $this->remediationItems === null || $this->remediationAccess === null || $siteIdentifier === ''
            || !$this->remediationAccess->hasLicence($siteIdentifier)) {
            return [];
        }
        $ruleIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $fix): string => is_array($fix) ? trim((string)($fix['ruleId'] ?? '')) : '',
            $priorityFixes
        ), static fn (string $ruleId): bool => $ruleId !== '')));
        $owners = (new \Priebera\A11yQualityGate\Remediation\Workflow\RemediationAssignedOwnerService($this->remediationItems, $this->remediationAccess))
            ->forRules($siteIdentifier, $ruleIds);
        $editable = $siteRootPid > 0 && $this->remediationOwnerRules !== null && $this->remediationAccess->canEditPage($siteRootPid)
            ? $this->remediationOwnerRules->storedFrontendRuleIds($siteIdentifier, $ruleIds)
            : [];
        $rootAssignments = $editable !== [] ? $this->remediationItems->findOwnerAssignmentsForPage($siteIdentifier, $siteRootPid) : [];

        $tags = [];
        foreach ($ruleIds as $ruleId) {
            $owner = $owners[$ruleId]['owner'] ?? '';
            $isEditable = in_array($ruleId, $editable, true);
            if ($owner === '' && !$isEditable) {
                continue;
            }
            $current = (string)($rootAssignments[$ruleId] ?? '');
            $tags[$ruleId] = [
                'assignedOwner' => $owner,
                'assignedOwnerLabel' => match ($owner) {
                    '' => '',
                    \Priebera\A11yQualityGate\Remediation\Workflow\RemediationAssignedOwnerService::VARIES => $this->translateWithFallback('overview.fixFirst.tag.ownerVaries', 'Varies by page'),
                    default => $this->translateWithFallback('remediation.owner.' . $owner, ucfirst($owner)),
                },
                'ownerEditable' => $isEditable,
                'ownerCurrent' => \Priebera\A11yQualityGate\Remediation\Workflow\RemediationOwner::isValid($current) ? $current : \Priebera\A11yQualityGate\Remediation\Workflow\RemediationOwner::UNASSIGNED,
                'ownerPageUid' => $isEditable ? $siteRootPid : 0,
            ];
        }

        return $tags;
    }

    private function remediationActivity(string $siteIdentifier, int $pageUid): array
    {
        if ($this->remediationItems === null || $this->remediationAccess === null || $siteIdentifier === ''
            || !$this->remediationAccess->hasLicence($siteIdentifier) || !$this->remediationAccess->canReadSiteIdentifier($siteIdentifier)) {
            return ['activity' => null, 'openCount' => 0];
        }
        // Counted from the rows this person may open (readable pages, own workspace), never site-wide totals, and
        // over all of them: the same scope as the queue's own counts.
        $queue = new \Priebera\A11yQualityGate\Remediation\Workflow\RemediationQueueService($this->remediationItems, $this->remediationAccess);
        $waiting = $open = 0;
        foreach ($queue->visibleCounts([$siteIdentifier]) as $status => $count) {
            $open += \Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus::isDone((string)$status) ? 0 : $count;
            $waiting += in_array((string)$status, ['pending_review', 'preparing', 'approved', 'in_editor'], true) ? $count : 0;
        }
        $savedThisWeek = $queue->visibleCounts([$siteIdentifier], ['statuses' => ['saved', 'not_verified', 'verified'], 'savedSince' => (int)strtotime('-7 days')]);
        $saved = array_sum($savedThisWeek);
        $verified = (int)($savedThisWeek['verified'] ?? 0);
        $openCount = $this->remediationAccess->aiState($siteIdentifier) === 'ready' ? $open : 0;
        if ($waiting === 0 && $saved === 0) {
            return ['activity' => null, 'openCount' => $openCount];
        }

        return [
            'activity' => [
                'waiting' => $waiting,
                'saved' => $saved,
                'verified' => $verified,
                'queueUrl' => $this->buildRouteUrl('web_a11y.remediation', array_filter(['id' => $pageUid > 0 ? $pageUid : null])),
            ],
            'openCount' => $openCount,
        ];
    }

    /**
     * What the current user may see of a stored frontend scan; null for administrators (everything). A scan too large to
     * be checked shows nothing rather than everything.
     *
     * @param array<string, mixed> $remoteScan
     */
    private function remoteRestrictionFor(array $remoteScan): ?RemoteResultAuthorization
    {
        $this->overviewRemoteRestriction = null;
        if ($this->remoteResultAuthorizationService === null) {
            return $this->overviewRemoteRestriction = new RemoteResultAuthorization(false, [], [], []);
        }
        if ($this->remoteResultAuthorizationService->isUnrestrictedUser()) {
            return null;
        }
        try {
            return $this->overviewRemoteRestriction = $this->remoteResultAuthorizationService->authorize($remoteScan);
        } catch (RemoteResultsTooLargeException) {
            return $this->overviewRemoteRestriction = new RemoteResultAuthorization(false, [], [], []);
        }
    }

    /** What the current request's user may see of the Overview's frontend scan; null = everything. */
    private ?RemoteResultAuthorization $overviewRemoteRestriction = null;

    public const REMOTE_RESTRICTED_NOTE = 'This view shows only the results your TYPO3 permissions allow. Results without a page or record you may read, including results stored before AQG recorded page ownership, are shown to administrators only.';

    /** Users other than administrators may see the frontend summary only as far as their rights reach (Sol R05). */
    private function isRemoteViewRestricted(): bool
    {
        return $this->remoteResultAuthorizationService === null || !$this->remoteResultAuthorizationService->isUnrestrictedUser();
    }

    /**
     * The stored scan as a restricted user may see its counters: pages and failures it can read, occurrences of its
     * visible findings. Crawler-wide counters would count refused pages.
     *
     * @param array<string, mixed> $remoteScan
     * @return array<string, mixed>
     */
    private function restrictedRemoteScanCounters(array $remoteScan, RemoteResultAuthorization $restriction, int $visibleFailedPages): array
    {
        $visiblePages = count($restriction->resultPages);
        $remoteScan['pages_total'] = $visiblePages;
        $remoteScan['pages_scanned'] = max(0, $visiblePages - $visibleFailedPages);
        $remoteScan['pages_failed'] = $visibleFailedPages;
        $remoteScan['issues_total'] = $restriction->totals()->total;
        $remoteScan['issues_new'] = 0;
        $remoteScan['issues_resolved'] = 0;

        return $remoteScan;
    }

    /**
     * Frontend scan history for a restricted user: only scans stored here that the user may read (a page scan of a
     * page the user cannot open is not listed, not even its URL), without crawler-wide counters.
     *
     * @param array<string, mixed> $history
     * @return array<string, mixed>
     */
    private function restrictRemoteHistory(array $history): array
    {
        foreach (['items', 'siteScans', 'pageScans'] as $key) {
            if (!is_array($history[$key] ?? null)) {
                continue;
            }
            $kept = [];
            foreach ($history[$key] as $item) {
                $jobId = is_array($item) ? trim((string)($item['jobId'] ?? '')) : '';
                $scan = $jobId !== '' ? $this->remoteScanRepository->findScanByJobId($jobId) : null;
                if (!is_array($scan) || !$this->scopeAccessService->canReadRemoteScan($scan)) {
                    continue;
                }
                foreach (['pagesScanned', 'issuesTotal', 'critical', 'serious', 'moderate', 'minor'] as $counter) {
                    if (array_key_exists($counter, $item)) {
                        $item[$counter] = '—';
                    }
                }
                $item['score'] = null;
                $item['scoreLabel'] = '—';
                $kept[] = $item;
            }
            $history[$key] = $kept;
        }
        if (is_array($history['stored'] ?? null)) {
            // Stored site scans stay listed (the site root is readable); their numbers count every page.
            array_walk_recursive($history['stored'], static function (mixed &$value, int|string $key): void {
                if (in_array($key, ['issuesTotal', 'issues_total', 'pagesScanned', 'pages_scanned', 'critical', 'serious', 'moderate', 'minor', 'unrated', 'issuesNew', 'issuesResolved', 'score', 'occurrences'], true) && (is_int($value) || is_float($value))) {
                    $value = '—';
                }
            });
        }
        $history['siteScansCount'] = count(is_array($history['siteScans'] ?? null) ? $history['siteScans'] : []);
        $history['pageScansCount'] = count(is_array($history['pageScans'] ?? null) ? $history['pageScans'] : []);

        return $history;
    }

    /**
     * The visible result pages, with their counts recomputed from the visible findings, most issue types first.
     *
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, array<string, mixed>>
     */
    private function restrictRemotePages(array $pages, RemoteResultAuthorization $restriction, string $ruleId): array
    {
        $counts = $restriction->countsByResultPage();
        $pagesWithRule = [];
        if ($ruleId !== '') {
            foreach ($restriction->findings as $finding) {
                if ($finding['ruleId'] === $ruleId) {
                    $pagesWithRule[$finding['resultPage']] = true;
                }
            }
        }
        $visible = [];
        foreach ($pages as $page) {
            $uid = (int)($page['uid'] ?? 0);
            if (!$restriction->isResultPageVisible($uid) || ($ruleId !== '' && !isset($pagesWithRule[$uid]))) {
                continue;
            }
            $page['issues_count'] = $counts[$uid]['issueTypes'] ?? 0;
            $page['issues_total'] = $counts[$uid]['occurrences'] ?? 0;
            $visible[] = $page;
        }
        usort($visible, static fn (array $a, array $b): int => [(int)$b['issues_count'], (int)$a['uid']] <=> [(int)$a['issues_count'], (int)$b['uid']]);

        return $visible;
    }
}
