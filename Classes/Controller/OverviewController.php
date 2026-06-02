<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Domain\Repository\FieldConfigRepository;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanPersistenceService;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendJavaScriptModuleService;
use Priebera\A11yQualityGate\Service\ExportUrlBuilderService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\FrontendPageUrlService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Service\SiteLanguageService;
use Priebera\A11yQualityGate\Utility\BackendTimeUtility;
use Priebera\A11yQualityGate\Utility\PaginationUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Http\RedirectResponse;

#[AsController]
final class OverviewController extends AbstractBackendModuleController
{
    private const LOCAL_PER_PAGE = 25;
    private const REMOTE_PER_PAGE = 25;
    private const REMOTE_FAILED_PER_PAGE = 25;
    private const MAX_VISIBLE_PAGINATION_ITEMS = 5;

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
        private readonly ExtensionContextService $extensionContextService,
        private readonly BackendJavaScriptModuleService $backendJavaScriptModuleService,
        private readonly ProStatusResolverService $proStatusResolverService,
        private readonly ExportUrlBuilderService $exportUrlBuilderService,
        private readonly FrontendPageUrlService $frontendPageUrlService,
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

        $siteIdentifier = $site?->getIdentifier() ?? '';
        $siteBase = $site !== null ? (string)$site->getBase() : '';
        $returnParameters = $this->getA11yModuleReturnParameters($request);
        $queryParams = $request->getQueryParams();

        if ($currentPageUid <= 0 || $site === null) {
            $this->configureDocHeader(
                $moduleTemplate,
                $returnParameters
            );

            $emptyState = $currentPageUid <= 0
                ? [
                    'title' => 'Select a page to start',
                    'body' => 'Choose a page in the TYPO3 page tree to open accessibility results for that context.',
                    'hint' => 'Site roots show the full overview. Content pages show page-specific issues and scan actions.',
                ]
                : [
                    'title' => 'No site context selected',
                    'body' => 'AQG needs a valid site context before it can show local scans, frontend crawler results and page-level issues.',
                    'hint' => 'Tip: Select a page inside a configured TYPO3 site root.',
                ];

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

        $localQuery = trim((string)($queryParams['localQuery'] ?? ''));
        $remoteQuery = trim((string)($queryParams['remoteQuery'] ?? ''));
        $remoteFailedQuery = trim((string)($queryParams['remoteFailedQuery'] ?? ''));

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
        $languageOptions = $this->buildOverviewLanguageOptions($request, $this->issueRepository, $siteIdentifier, $availableLanguages, $currentLanguageUid);
        $currentLanguageOption = $this->resolveCurrentLanguageOption($languageOptions);

        $currentLocalPage = max(1, (int)($queryParams['localPage'] ?? 1));
        $currentRemotePage = max(1, (int)($queryParams['remotePage'] ?? 1));
        $currentRemoteFailedPage = max(1, (int)($queryParams['remoteFailedPage'] ?? 1));

        if ($siteIdentifier !== '' && $siteBase !== '') {
            $this->recoverPendingRemoteScanPersistence($siteIdentifier, $siteBase);
        }

        $siteRootPid = $site !== null ? (int)$site->getRootPageId() : 0;
        $isPageContext = $currentPageUid > 0 && $siteRootPid > 0 && $currentPageUid !== $siteRootPid;

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
                $currentLanguageUid
            );
        }

        $lastSubtreeScan = $siteIdentifier !== ''
            ? $this->scanRepository->findLastCompletedSubtreeScan($siteIdentifier, $currentLanguageUid)
            : null;
        $lastPageScan = $siteIdentifier !== '' && $isPageContext
            ? $this->scanRepository->findLastCompletedPageScan($siteIdentifier, $currentPageUid, $currentLanguageUid)
            : null;
        $lastScan = $this->selectMostRecentScan($lastSubtreeScan, $lastPageScan);

        $lastScan = $this->withFormattedScanTimestamps($lastScan);

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
                $currentPageUrl = $this->frontendPageUrlService->resolveForPage($site, $currentPageUid, $currentLanguageUid);
            }
        }

        $remoteScan = $siteIdentifier !== ''
            ? $this->resolveOverviewRemoteScan($siteIdentifier, $isPageContext, $currentPageUid, $currentLanguageUid, $currentPageUrl)
            : null;
        $remoteScan = $this->withFormattedScanTimestamps($remoteScan);

        $latestRemotePageScan = $siteIdentifier !== ''
            ? $this->remoteScanRepository->findLastCompletedPageScanBySite($siteIdentifier, $currentLanguageUid)
            : null;
        $latestRemotePageScan = $this->withFormattedScanTimestamps($latestRemotePageScan);

        $activeRemoteScan = $siteIdentifier !== ''
            ? $this->resolveOverviewActiveRemoteScan($siteIdentifier, $isPageContext, $currentPageUid, $currentLanguageUid)
            : null;

        $pageStats = [];
        $totalLocalPages = 0;
        $localPagination = PaginationUtility::buildPagination(0, $currentLocalPage, self::LOCAL_PER_PAGE);
        $localPaginationItems = [];

        if ($siteIdentifier !== '') {
            $totalLocalPages = $this->issueRepository->countOpenPageStatsForSite($siteIdentifier, $localQuery, $currentLanguageUid);

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
                $currentLanguageUid
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

        $remotePagination = PaginationUtility::buildPagination(0, $currentRemotePage, self::REMOTE_PER_PAGE);
        $remoteFailedPagination = PaginationUtility::buildPagination(0, $currentRemoteFailedPage, self::REMOTE_FAILED_PER_PAGE);
        $remotePaginationItems = [];
        $remoteFailedPaginationItems = [];

        if (is_array($remoteScan) && isset($remoteScan['uid'])) {
            $remoteScanUid = (int)$remoteScan['uid'];

            $totalRemotePages = $this->remoteScanRepository->countPagesForScan($remoteScanUid, false, $remoteQuery);
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
                $remoteQuery
            );
            $remotePages = $this->enrichRemoteOverviewPages($remotePages, $site, $currentLanguageUid);

            if ($remoteQuery !== '' && $totalRemotePages === 0) {
                $allRemotePages = $this->enrichRemoteOverviewPages(
                    $this->remoteScanRepository->findPagesForScan($remoteScanUid),
                    $site,
                    $currentLanguageUid
                );
                $filteredRemotePages = $this->filterRemoteOverviewPages($allRemotePages, $remoteQuery, false);
                $totalRemotePages = count($filteredRemotePages);
                $remotePagination = PaginationUtility::buildPagination(
                    $totalRemotePages,
                    $currentRemotePage,
                    self::REMOTE_PER_PAGE
                );
                $remotePages = array_slice($filteredRemotePages, $remotePagination['offset'], self::REMOTE_PER_PAGE);
            }

            $remoteFailedPages = $this->remoteScanRepository->findFailedPagesForScanPaginated(
                $remoteScanUid,
                self::REMOTE_FAILED_PER_PAGE,
                $remoteFailedPagination['offset'],
                $remoteFailedQuery
            );
            $remoteFailedPages = $this->enrichRemoteOverviewPages($remoteFailedPages, $site, $currentLanguageUid);

            if ($remoteFailedQuery !== '' && $totalRemoteFailedPages === 0) {
                $allRemoteFailedPages = $this->enrichRemoteOverviewPages(
                    $this->remoteScanRepository->findFailedPagesForScan($remoteScanUid),
                    $site,
                    $currentLanguageUid
                );
                $filteredRemoteFailedPages = $this->filterRemoteOverviewPages($allRemoteFailedPages, $remoteFailedQuery, true);
                $totalRemoteFailedPages = count($filteredRemoteFailedPages);
                $remoteFailedPagination = PaginationUtility::buildPagination(
                    $totalRemoteFailedPages,
                    $currentRemoteFailedPage,
                    self::REMOTE_FAILED_PER_PAGE
                );
                $remoteFailedPages = array_slice($filteredRemoteFailedPages, $remoteFailedPagination['offset'], self::REMOTE_FAILED_PER_PAGE);
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

        if (!is_array($remoteScan) && $siteIdentifier !== '') {
            $totalRemotePages = $this->remoteScanRepository->countCompletedPageScanPagesForSite(
                $siteIdentifier,
                $currentLanguageUid,
                false,
                $remoteQuery
            );
            $totalRemoteFailedPages = $this->remoteScanRepository->countCompletedPageScanPagesForSite(
                $siteIdentifier,
                $currentLanguageUid,
                true,
                $remoteFailedQuery
            );

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

            $remotePages = $this->remoteScanRepository->findCompletedPageScanPagesForSitePaginated(
                $siteIdentifier,
                self::REMOTE_PER_PAGE,
                $remotePagination['offset'],
                $currentLanguageUid,
                false,
                $remoteQuery
            );
            $remotePages = $this->enrichRemoteOverviewPages($remotePages, $site, $currentLanguageUid);

            $remoteFailedPages = $this->remoteScanRepository->findCompletedPageScanPagesForSitePaginated(
                $siteIdentifier,
                self::REMOTE_FAILED_PER_PAGE,
                $remoteFailedPagination['offset'],
                $currentLanguageUid,
                true,
                $remoteFailedQuery
            );
            $remoteFailedPages = $this->enrichRemoteOverviewPages($remoteFailedPages, $site, $currentLanguageUid);

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

        $remotePages = array_map(
            fn(array $page): array => $page + [
                    'detailUrl' => $this->buildRouteUrl('web_a11y.remotePageDetail', [
                        'remotePageUid' => (int)$page['uid'],
                        'site' => $siteIdentifier,
                        'language' => $currentLanguageUid,
                    ]),
                ],
            $remotePages
        );

        $remoteFailedPages = array_map(
            fn(array $page): array => $page + [
                    'detailUrl' => $this->buildRouteUrl('web_a11y.remotePageDetail', [
                        'remotePageUid' => (int)$page['uid'],
                        'site' => $siteIdentifier,
                    ]),
                ],
            $remoteFailedPages
        );

        $pageStats = array_map(
            fn(array $stat): array => $stat + [
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
            ? $this->issueRepository->countOpenTotalsForSite($siteIdentifier, $currentLanguageUid)
            : [
                'critical' => 0,
                'warning' => 0,
                'info' => 0,
                'needs_review' => 0,
                'total' => 0,
            ];
        $localNewCounts = $siteIdentifier !== '' && is_array($lastScan)
            ? $this->issueRepository->countNewOpenTotalsForScan($siteIdentifier, (int)($lastScan['uid'] ?? 0), $currentLanguageUid)
            : [
                'critical' => 0,
                'warning' => 0,
                'info' => 0,
                'needs_review' => 0,
                'total' => 0,
            ];
        $localScanDelta = [
            'new' => (int)($lastScan['issues_new'] ?? 0),
            'resolved' => (int)($lastScan['issues_resolved'] ?? 0),
            'ignored' => (int)($lastScan['issues_ignored'] ?? 0),
        ];

        $scanStatus = $this->scanStatusService->getStatus();
        $isRelevantLocalScanRunning = $this->isRelevantLocalScanRunning($scanStatus, $siteRootPid, $currentPageUid);
        $proStatus = $this->proStatusResolverService->resolveForSite($site);

        $exportLocalCsvUrl = $this->exportUrlBuilderService->buildOverviewCsvUrl($siteIdentifier);
        $exportLocalPdfUrl = $this->exportUrlBuilderService->buildOverviewPdfUrl($siteIdentifier);
        $exportRemoteCsvUrl = is_array($remoteScan)
            ? $this->exportUrlBuilderService->buildOverviewCsvUrl($siteIdentifier, true)
            : '';
        $exportRemotePdfUrl = is_array($remoteScan)
            ? $this->exportUrlBuilderService->buildOverviewPdfUrl($siteIdentifier, true)
            : '';

        $this->configureDocHeader(
            $moduleTemplate,
            $returnParameters
        );

        $backendUser = $this->backendContextService->getBackendUser();
        $canScanAll = $this->accessControlService->canShowScanAll($backendUser);
        $canManageRemoteAccessSettings = $this->accessControlService->canManageAdminOnlySettings($backendUser);
        $showRemoteScannerTokenNotice = $canManageRemoteAccessSettings
            && $siteIdentifier !== ''
            && !$this->hasEffectiveScannerToken($siteIdentifier);
        $remoteScanAccessSettingsUrl = $this->buildRouteUrl(
            'web_a11y.settings',
            array_replace($returnParameters, [
                'tab' => 'remote_access',
                'rulesetSite' => $siteIdentifier,
            ])
        );

        $hasEnabledFields = $this->fieldConfigRepository->hasEnabledFields();


        $moduleTemplate->assignMultiple([
            'siteIdentifier' => $siteIdentifier,
            'hasPageContext' => true,
            'emptyState' => [],
            'lastScan' => $lastScan,
            'pageStats' => $pageStats,
            'totalLocalPages' => $totalLocalPages,
            'localPagination' => $localPagination,
            'localPaginationItems' => $localPaginationItems,
            'totalCounts' => $totalCounts,
            'localNewCounts' => $localNewCounts,
            'localScanDelta' => $localScanDelta,
            'canScanAll' => $canScanAll,
            'siteRootPid' => $siteRootPid,
            'currentPageUid' => $currentPageUid,
            'isPageContext' => $isPageContext,
            'currentPageIssueCounts' => $currentPageIssueCounts,
            'currentPageHasIssues' => array_sum($currentPageIssueCounts) > 0,
            'currentPageDetailUrl' => $currentPageDetailUrl,
            'currentPageUrl' => $currentPageUrl,
            'scanStatus' => $scanStatus,
            'isRelevantLocalScanRunning' => $isRelevantLocalScanRunning,
            'hasScanResults' => $lastScan !== null,
            'hasEnabledFields' => $hasEnabledFields,
            'remoteScan' => $remoteScan,
            'latestRemotePageScan' => $latestRemotePageScan,
            'activeRemoteScan' => $activeRemoteScan,
            'remotePages' => $remotePages,
            'remoteFailedPages' => $remoteFailedPages,
            'totalRemotePages' => $totalRemotePages,
            'totalRemoteFailedPages' => $totalRemoteFailedPages,
            'remotePagination' => $remotePagination,
            'remotePaginationItems' => $remotePaginationItems,
            'remoteFailedPagination' => $remoteFailedPagination,
            'remoteFailedPaginationItems' => $remoteFailedPaginationItems,
            'proStatus' => $proStatus,
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
            'availableLanguages' => $availableLanguages,
            'languageOptions' => $languageOptions,
            'currentLanguageOption' => $currentLanguageOption,
            'hasLanguageOptions' => $languageOptions !== [],
            'languageOptionCount' => count($languageOptions),
            'availableLanguageCount' => count($availableLanguages),
            'currentLanguageUid' => $currentLanguageUid,
            'languageUrlParameters' => $languageUrlParameters,
        ]);

        return $moduleTemplate->renderResponse('Overview/Index');
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
            $this->remoteScanRepository->markSyncError($jobId, $exception->getMessage());
        }
    }

    private function hasEffectiveScannerToken(string $siteIdentifier): bool
    {
        $defaultRuleset = $this->rulesetRepository->findDefault();

        return trim((string)($defaultRuleset['scanner_token'] ?? '')) !== '';
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
                ->setTitle($this->translate('settings.title'))
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

        return $this->buildRouteUrl('web_a11y', $parameters);
    }

    /**
     * @param array<string, mixed>|null $first
     * @param array<string, mixed>|null $second
     * @return array<string, mixed>|null
     */
    private function selectMostRecentScan(?array $first, ?array $second): ?array
    {
        if ($first === null) {
            return $second;
        }

        if ($second === null) {
            return $first;
        }

        return (int)($second['finished_at'] ?? 0) > (int)($first['finished_at'] ?? 0)
            ? $second
            : $first;
    }


    /**
     * @param array<string, mixed> $scanStatus
     */
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

    private function resolveOverviewRemoteScan(
        string $siteIdentifier,
        bool $isPageContext,
        int $currentPageUid,
        int $languageUid,
        string $currentPageUrl = '',
    ): ?array {
        // The Overview panel represents an overview, not a detail view.
        // Prefer completed site/subtree frontend scans for the main summary.
        // If no site scan exists, the page table below falls back to recent
        // single-page scans, but single-page issues are never rendered inline here.
        return $this->remoteScanRepository->findLastCompletedSiteScanBySite($siteIdentifier, $languageUid);
    }

    private function resolveOverviewActiveRemoteScan(
        string $siteIdentifier,
        bool $isPageContext,
        int $currentPageUid,
        int $languageUid,
    ): ?array {
        return $this->remoteScanRepository->findLatestActiveScanBySite($siteIdentifier);
    }
}
