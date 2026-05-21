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
        if ($availableLanguages !== [] && !$this->hasExplicitLanguageParameter($request)) {
            $this->backendContextService->getBackendUser()?->setAndSaveSessionData(
                'tx_a11y_quality_gate_language',
                0
            );

            $redirectParameters = $this->getA11yModuleReturnParameters($request);
            unset(
                $redirectParameters['localPage'],
                $redirectParameters['remotePage'],
                $redirectParameters['remoteFailedPage'],
                $redirectParameters['languageUid']
            );
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

        $remoteScan = $siteIdentifier !== ''
            ? $this->resolveOverviewRemoteScan($siteIdentifier, $isPageContext, $currentPageUid, $currentLanguageUid)
            : null;

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

            $remoteFailedPages = $this->remoteScanRepository->findFailedPagesForScanPaginated(
                $remoteScanUid,
                self::REMOTE_FAILED_PER_PAGE,
                $remoteFailedPagination['offset'],
                $remoteFailedQuery
            );

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
                'total' => 0,
            ];
        $localNewCounts = $siteIdentifier !== '' && is_array($lastScan)
            ? $this->issueRepository->countNewOpenTotalsForScan($siteIdentifier, (int)($lastScan['uid'] ?? 0), $currentLanguageUid)
            : [
                'critical' => 0,
                'warning' => 0,
                'info' => 0,
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
        $exportRemoteCsvUrl = $this->exportUrlBuilderService->buildOverviewCsvUrl($siteIdentifier, true);
        $exportRemotePdfUrl = $this->exportUrlBuilderService->buildOverviewPdfUrl($siteIdentifier, true);

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

        $currentPageDetailUrl = '';
        $currentPageUrl = '';
        $currentRemotePageDetailUrl = '';

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

            if (is_array($remoteScan) && isset($remoteScan['uid']) && $currentPageUrl !== '') {
                $matchingRemotePage = $this->remoteScanRepository->findPageForScanByUrl(
                    (int)$remoteScan['uid'],
                    $currentPageUrl
                );

                if (is_array($matchingRemotePage) && isset($matchingRemotePage['uid'])) {
                    $currentRemotePageDetailUrl = $this->buildRouteUrl('web_a11y.remotePageDetail', [
                        'remotePageUid' => (int)$matchingRemotePage['uid'],
                        'site' => $siteIdentifier,
                        'language' => $currentLanguageUid,
                    ]);
                }
            }
        }

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
            'currentRemotePageDetailUrl' => $currentRemotePageDetailUrl,
            'scanStatus' => $scanStatus,
            'isRelevantLocalScanRunning' => $isRelevantLocalScanRunning,
            'hasScanResults' => $lastScan !== null,
            'hasEnabledFields' => $hasEnabledFields,
            'remoteScan' => $remoteScan,
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

        $languageUid = (int)($request->getQueryParams()['language'] ?? $request->getQueryParams()['languageUid'] ?? 0);
        if ($languageUid !== 0) {
            $parameters['language'] = $languageUid;
        } else {
            unset($parameters['language'], $parameters['languageUid']);
        }

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
    ): ?array {
        if ($isPageContext && $currentPageUid > 0) {
            $pageScan = $this->remoteScanRepository->findLastCompletedRelevantScan(
                $siteIdentifier,
                $currentPageUid,
                $languageUid
            );
            if (is_array($pageScan)) {
                return $pageScan;
            }
        }

        $siteScan = $this->remoteScanRepository->findLastCompletedSiteScanBySite($siteIdentifier, $languageUid);
        if (is_array($siteScan)) {
            return $siteScan;
        }

        return $this->remoteScanRepository->findLastCompletedScanBySite($siteIdentifier, $languageUid);
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
