<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Configuration\PublicLinkProvider;
use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueNodeRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanRecoveryService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendJavaScriptModuleService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\ExportUrlBuilderService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;
use Priebera\A11yQualityGate\Service\RemoteScanHistoryService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Utility\BackendTimeUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;

#[AsController]
final class RemotePageDetailController extends AbstractBackendModuleController
{
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        IconFactory $iconFactory,
        BackendContextService $backendContextService,
        SiteResolutionService $siteResolutionService,
        RequestParameterService $requestParameterService,
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly RemoteIssueRepository $remoteIssueRepository,
        private readonly RemoteIssueNodeRepository $remoteIssueNodeRepository,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendRecordAccessService $backendRecordAccessService,
        private readonly BackendJavaScriptModuleService $backendJavaScriptModuleService,
        private readonly RemoteScanRecoveryService $remoteScanRecoveryService,
        private readonly RemoteScanHistoryService $remoteScanHistoryService,
        private readonly ExportUrlBuilderService $exportUrlBuilderService,
        private readonly ProStatusResolverService $proStatusResolverService,
        private readonly RuleMetadataPresentationService $ruleMetadataPresentationService,
        private readonly PublicLinkProvider $publicLinkProvider,
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

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->createModuleTemplate($request);

        $remotePageUid = (int)$this->requestParameterService->getString($request, 'remotePageUid');
        $siteIdentifier = $this->requestParameterService->getSiteIdentifier($request);
        $pageUid = $this->requestParameterService->getPageUidOrZero($request);
        $languageUid = $this->requestParameterService->getLanguageUid($request, 0);

        if ($siteIdentifier === '' && $pageUid > 0) {
            $siteIdentifier = $this->siteResolutionService->resolveSiteIdentifierForPageId($pageUid);
        }

        if ($remotePageUid <= 0 && $siteIdentifier !== '' && $pageUid > 0) {
            $latestRemotePage = $this->remoteScanRepository->findLatestPageForCompletedPageScan(
                $siteIdentifier,
                $pageUid,
                $languageUid
            );

            if (is_array($latestRemotePage) && isset($latestRemotePage['uid'])) {
                return new RedirectResponse(
                    $this->buildRouteUrl('web_a11y.remotePageDetail', [
                        'remotePageUid' => (int)$latestRemotePage['uid'],
                        'site' => $siteIdentifier,
                        'id' => $pageUid,
                        'language' => $languageUid,
                    ])
                );
            }
        }

        $backUrl = $this->buildRemoteOverviewBackUrl($request, $siteIdentifier, $pageUid, $languageUid);

        if ($remotePageUid <= 0) {
            $site = $siteIdentifier !== ''
                ? $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier)
                : null;

            $this->backendJavaScriptModuleService->loadBackendModule(
                $this->pageRenderer,
                $site
            );

            $moduleTemplate->assignMultiple([
                'errorMessage' => $this->translate('module.remotePageDetail.error.missingRemotePageUid'),
                'remotePage' => null,
                'remoteScan' => null,
                'activeRemoteScan' => null,
                'issues' => [],
                'backUrl' => $backUrl,
                'remotePageDebugUrl' => '',
                'exportCsvUrl' => '',
                'exportPdfUrl' => '',
                'exportPdfAllowed' => false,
                'resolvedTypo3PageUid' => 0,
                'scanPageUid' => 0,
                'canScanRemotePage' => false,
                'usesSiteRootContext' => false,
                'resolvedSiteIdentifier' => $siteIdentifier,
                'remoteDetail' => [],
                'proStatus' => null,
            ]);

            return $moduleTemplate->renderResponse('RemotePageDetail/Show');
        }

        $remotePage = $this->remoteScanRepository->findPageByUid($remotePageUid);
        if (!is_array($remotePage)) {
            $site = $siteIdentifier !== ''
                ? $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier)
                : null;

            $this->backendJavaScriptModuleService->loadBackendModule(
                $this->pageRenderer,
                $site
            );

            $moduleTemplate->assignMultiple([
                'errorMessage' => $this->translate('module.remotePageDetail.error.remotePageNotFound'),
                'remotePage' => null,
                'remoteScan' => null,
                'activeRemoteScan' => null,
                'issues' => [],
                'backUrl' => $backUrl,
                'remotePageDebugUrl' => '',
                'exportCsvUrl' => '',
                'exportPdfUrl' => '',
                'exportPdfAllowed' => false,
                'resolvedTypo3PageUid' => 0,
                'scanPageUid' => 0,
                'canScanRemotePage' => false,
                'usesSiteRootContext' => false,
                'resolvedSiteIdentifier' => $siteIdentifier,
                'remoteDetail' => [],
                'proStatus' => null,
            ]);

            return $moduleTemplate->renderResponse('RemotePageDetail/Show');
        }

        $remoteScanUid = (int)($remotePage['remote_scan'] ?? 0);
        $remoteScan = $remoteScanUid > 0
            ? $this->remoteScanRepository->findScanByUid($remoteScanUid)
            : null;

        $resolvedSiteIdentifier = is_array($remoteScan)
            ? (string)($remoteScan['site_identifier'] ?? $siteIdentifier)
            : $siteIdentifier;

        $resolvedSite = $resolvedSiteIdentifier !== ''
            ? $this->siteResolutionService->resolveSiteByIdentifier($resolvedSiteIdentifier)
            : null;

        $this->backendJavaScriptModuleService->loadBackendModule(
            $this->pageRenderer,
            $resolvedSite
        );

        $proStatus = $this->proStatusResolverService->resolveForSiteIdentifier($resolvedSiteIdentifier);
        $isFreePreview = !(bool)($proStatus->valid ?? false) || !(bool)($proStatus->hasCrawler ?? false);

        $remotePageDebugUrl = $this->buildRemotePageDebugUrl((string)($remotePage['url'] ?? ''));

        $remoteScreenshotProxyUrl = '';
        if (!$isFreePreview && !empty($remotePage['screenshot_path']) && $remotePageUid > 0) {
            $remoteScreenshotProxyUrl = $this->buildRemoteScreenshotProxyUrl(
                $resolvedSiteIdentifier,
                $remotePageUid
            );
        }

        $issues = $this->remoteIssueRepository->findByRemoteScanPage($remotePageUid);

        $issuesWithNodes = array_map(function (array $issue) use ($resolvedSiteIdentifier, $remotePageUid, $isFreePreview): array {
            $issueUid = (int)($issue['uid'] ?? 0);
            $nodes = $issueUid > 0
                ? $this->remoteIssueNodeRepository->findByRemoteIssue($issueUid)
                : [];

            $nodes = array_map(function (array $node) use ($resolvedSiteIdentifier, $remotePageUid, $isFreePreview): array {
                $mappedTable = trim((string)($node['mapped_table'] ?? ''));
                $mappedUid = (int)($node['mapped_uid'] ?? 0);

                $node['editRecordUrl'] = '';
                $node['hasRecordMapping'] = false;
                $node['hasEditAccess'] = false;
                $node['contrastDetails'] = $this->decodeContrastDetails((string)($node['contrast_details_json'] ?? ''));
                $node['hasContrastDetails'] = $node['contrastDetails'] !== [];
                $node['nodeRemediation'] = $isFreePreview
                    ? []
                    : $this->decodeNodeRemediation((string)($node['node_remediation_json'] ?? ''));
                $node['hasNodeRemediation'] = $node['nodeRemediation'] !== [];

                if ($mappedTable !== '' && $mappedUid > 0) {
                    $node['hasRecordMapping'] = true;
                    $node['hasEditAccess'] = $this->backendRecordAccessService->canEditRecord(
                        $mappedTable,
                        $mappedUid
                    );

                    if ($node['hasEditAccess']) {
                        $node['editRecordUrl'] = $this->buildEditRecordUrl(
                            $mappedTable,
                            $mappedUid,
                            $resolvedSiteIdentifier,
                            $remotePageUid
                        );
                    }
                }

                return $node;
            }, $nodes);

            return $issue + [
                    'nodes' => $nodes,
                ];
        }, $issues);

        $siteRootPid = $resolvedSite !== null ? (int)$resolvedSite->getRootPageId() : 0;
        $resolvedTypo3PageUid = $this->resolveTypo3PageUid($remoteScan, $issuesWithNodes);
        $scanPageUid = $resolvedTypo3PageUid > 0 ? $resolvedTypo3PageUid : $siteRootPid;
        $canScanRemotePage = !$isFreePreview && $scanPageUid > 0;
        $usesSiteRootContext = $canScanRemotePage && $resolvedTypo3PageUid <= 0;

        $activeRemoteScan = null;
        if ($resolvedSiteIdentifier !== '') {
            if ($scanPageUid > 0) {
                $activeRemoteScan = $this->remoteScanRepository->findLatestRelevantActiveScan(
                    $resolvedSiteIdentifier,
                    $scanPageUid
                );
            }

            if (!is_array($activeRemoteScan)) {
                $activeRemoteScan = $this->remoteScanRepository->findLatestActiveSiteScanBySite(
                    $resolvedSiteIdentifier
                );
            }
        }

        if (is_array($activeRemoteScan) && $resolvedSite !== null) {
            $activeRemoteScan = $this->remoteScanRecoveryService->recoverScanIfNeeded(
                $activeRemoteScan,
                (string)$resolvedSite->getBase(),
            );
        }

        $pageRemediationSummary = $isFreePreview
            ? []
            : $this->decodeRemediationSummary((string)($remotePage['remediation_summary_json'] ?? ''));
        $pageRecommendation = $isFreePreview
            ? []
            : $this->decodePageRecommendation((string)($remotePage['page_recommendation_json'] ?? ''));
        $groupedIssues = $this->groupIssuesByRule($issuesWithNodes, $pageRecommendation);
        $pageSummaryIssuesCount = (int)($remotePage['issues_count'] ?? 0);
        $issueDetailsUnavailable = $groupedIssues === [] && $pageSummaryIssuesCount > 0;
        $keyboardStructureReview = $this->decodeKeyboardStructureReview((string)($remotePage['keyboard_summary_json'] ?? ''));
        $remoteDetail = $this->buildRemoteDetailViewData($remotePage, $remoteScan, $activeRemoteScan);
        $backUrl = $this->buildRemoteOverviewBackUrl(
            $request,
            $resolvedSiteIdentifier,
            (int)($remoteScan['page_uid'] ?? 0) ?: $scanPageUid,
            (int)($remoteScan['language_uid'] ?? 0)
        );

        $exportCsvUrl = $isFreePreview ? '' : $this->exportUrlBuilderService->buildRemotePageCsvUrl(
            $resolvedSiteIdentifier,
            $remotePageUid
        );

        $exportPdfUrl = $isFreePreview ? '' : $this->exportUrlBuilderService->buildRemotePagePdfUrl(
            $resolvedSiteIdentifier,
            $remotePageUid
        );

        $remotePageHistory = $isFreePreview ? ['available' => false, 'items' => []] : $this->buildRemotePageHistory(
            $request,
            $resolvedSite !== null ? (string)$resolvedSite->getBase() : '',
            $resolvedSiteIdentifier,
            (string)($remotePage['url'] ?? ''),
            is_array($remoteScan) ? (string)($remoteScan['job_id'] ?? '') : '',
            $remotePageUid,
            (int)($remoteScan['page_uid'] ?? 0) ?: $scanPageUid,
            (int)($remoteScan['language_uid'] ?? 0)
        );
        $remoteScanCompare = $isFreePreview
            ? ['available' => false, 'message' => '']
            : $this->buildRemotePageCompare($request, $resolvedSite !== null ? (string)$resolvedSite->getBase() : '', $remotePageHistory);
        $regressionAlert = $isFreePreview ? ['available' => false, 'message' => ''] : $this->buildRemotePageRegressionAlert(
            $remotePageUid,
            $scanPageUid,
            (int)($remoteScan['language_uid'] ?? 0),
            $resolvedSite !== null ? (string)$resolvedSite->getBase() : '',
            $resolvedSiteIdentifier,
            (string)($remotePage['url'] ?? '')
        );
        $remediationPlan = $isFreePreview ? ['available' => false, 'hasTasks' => false] : $this->remoteScanHistoryService->loadRemediationPlanByJobId(
            $resolvedSite !== null ? (string)$resolvedSite->getBase() : '',
            is_array($remoteScan) ? (string)($remoteScan['job_id'] ?? '') : '',
            5
        );

        $this->configureDocHeader($moduleTemplate, $backUrl, $remotePageDebugUrl);

        $moduleTemplate->assignMultiple([
            'errorMessage' => null,
            'remotePage' => $remotePage + [
                    'screenshot_proxy_url' => $remoteScreenshotProxyUrl,
                ],
            'remoteScan' => $remoteScan,
            'remoteDetail' => $remoteDetail,
            'activeRemoteScan' => $activeRemoteScan,
            'issues' => $groupedIssues,
            'issueDetailsUnavailable' => $issueDetailsUnavailable,
            'pageSummaryIssuesCount' => $pageSummaryIssuesCount,
            'pageRemediationSummary' => $pageRemediationSummary,
            'hasPageRemediationSummary' => $pageRemediationSummary !== [],
            'pageRecommendation' => $pageRecommendation,
            'hasPageRecommendation' => $pageRecommendation !== [],
            'keyboardStructureReview' => $keyboardStructureReview,
            'hasKeyboardStructureReview' => $keyboardStructureReview !== [],
            'backUrl' => $backUrl,
            'remotePageDebugUrl' => $remotePageDebugUrl,
            'resolvedSiteIdentifier' => $resolvedSiteIdentifier,
            'exportCsvUrl' => $exportCsvUrl,
            'exportPdfUrl' => $exportPdfUrl,
            'exportPdfAllowed' => $proStatus->valid && !$proStatus->isTrial && $proStatus->hasExportPdf,
            'resolvedTypo3PageUid' => $resolvedTypo3PageUid,
            'scanPageUid' => $scanPageUid,
            'canScanRemotePage' => $canScanRemotePage,
            'usesSiteRootContext' => $usesSiteRootContext,
            'proStatus' => $proStatus,
            'isFreePreview' => $isFreePreview,
            'freePreviewTrialUrl' => $isFreePreview ? $this->publicLinkProvider->getBackendUrl(PublicLinkProvider::TRIAL) : '',
            'remotePageHistory' => $remotePageHistory,
            'remoteScanCompare' => $remoteScanCompare,
            'regressionAlert' => $regressionAlert,
            'remediationPlan' => $remediationPlan,
        ]);

        return $moduleTemplate->renderResponse('RemotePageDetail/Show');
    }


    /**
     * @return array<string, mixed>
     */
    private function buildRemotePageRegressionAlert(
        int $remotePageUid,
        int $pageUid,
        int $languageUid,
        string $siteBase,
        string $siteIdentifier,
        string $pageUrl
    ): array {
        $alert = $this->remoteScanHistoryService->loadRegressionAlert(
            $siteBase,
            $siteIdentifier,
            'single_page',
            $pageUrl
        );

        return $this->enrichRegressionAlertActionUrl(
            $alert,
            'web_a11y.remotePageDetail',
            [
                'remotePageUid' => $remotePageUid,
                'site' => $siteIdentifier,
                'id' => $pageUid,
                'language' => $languageUid,
            ]
        );
    }

    /**
     * @param array<string, mixed> $alert
     * @param array<string, mixed> $routeParameters
     * @return array<string, mixed>
     */
    private function enrichRegressionAlertActionUrl(array $alert, string $routeName, array $routeParameters): array
    {
        $previousJobId = trim((string)($alert['previousJobId'] ?? ''));
        $currentJobId = trim((string)($alert['currentJobId'] ?? ''));
        $actionType = strtolower(trim((string)($alert['actionType'] ?? '')));

        $alert = $this->enrichRegressionAlertWithLocalScanRows($alert);

        if ($previousJobId === '' || $currentJobId === '' || ($actionType !== '' && $actionType !== 'compare')) {
            $alert['actionUrl'] = '';
            return $alert;
        }

        $routeParameters['compareFromJobId'] = $previousJobId;
        $routeParameters['compareToJobId'] = $currentJobId;
        $alert['actionUrl'] = $this->buildRouteUrl($routeName, $routeParameters) . '#scan-comparison';

        return $alert;
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
                'label' => 'Findings change',
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
    private function buildRemotePageHistory(
        ServerRequestInterface $request,
        string $siteBase,
        string $siteIdentifier,
        string $pageUrl,
        string $currentJobId,
        int $remotePageUid,
        int $pageUid,
        int $languageUid,
    ): array {
        $history = $this->remoteScanHistoryService->loadHistory(
            $siteBase,
            $siteIdentifier,
            10,
            'single_page',
            $pageUrl,
            'completed'
        );
        if (!($history['hasItems'] ?? false)) {
            return $history;
        }

        $normalizedCurrentUrl = $this->normalizeComparableHistoryUrl($pageUrl);
        $items = [];
        foreach (($history['pageScans'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((string)($item['normalizedStartUrl'] ?? '') === '' || (string)($item['normalizedStartUrl'] ?? '') !== $normalizedCurrentUrl) {
                continue;
            }
            $item = $this->enrichRemotePageHistoryItemUrls($request, $item, $siteIdentifier, $remotePageUid, $pageUid, $languageUid);
            if ($currentJobId !== '' && (string)($item['jobId'] ?? '') !== $currentJobId) {
                $item['compareUrl'] = '';
                $item['compareLabel'] = 'Compare is available from the current scan only.';
            }
            $items[] = $item;
        }

        if ($items === []) {
            return [
                'available' => true,
                'hasItems' => false,
                'message' => 'No previous page scans for this URL were returned yet.',
                'items' => [],
            ];
        }

        return [
            'available' => true,
            'hasItems' => true,
            'message' => '',
            'items' => array_slice($items, 0, 10),
            'currentJobId' => $currentJobId,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function enrichRemotePageHistoryItemUrls(
        ServerRequestInterface $request,
        array $item,
        string $siteIdentifier,
        int $remotePageUid,
        int $pageUid,
        int $languageUid,
    ): array {
        $item['viewReportUrl'] = '';
        $item['viewReportLabel'] = 'Not available in TYPO3';

        $jobId = trim((string)($item['jobId'] ?? ''));
        $startUrl = trim((string)($item['startUrl'] ?? ''));
        $sourceType = strtolower(trim((string)($item['sourceType'] ?? '')));
        $isSinglePageHistoryRow = in_array($sourceType, ['single_page', 'page'], true);

        $localPage = $jobId !== '' && $startUrl !== '' && $isSinglePageHistoryRow
            ? $this->remoteScanRepository->findPageForJobIdAndUrl($jobId, $startUrl, $siteIdentifier, 'single_page')
            : null;

        if (is_array($localPage) && (int)($localPage['uid'] ?? 0) > 0 && (string)($localPage['remote_scan_job_id'] ?? '') === $jobId) {
            $item['viewReportUrl'] = $this->buildRouteUrl('web_a11y.remotePageDetail', [
                'remotePageUid' => (int)$localPage['uid'],
                'site' => $siteIdentifier,
                'id' => (int)($localPage['remote_scan_page_uid'] ?? $pageUid),
                'language' => (int)($localPage['remote_scan_language_uid'] ?? $languageUid),
            ]);
            $item['viewReportLabel'] = 'View report';
            $item['viewReportRemotePageUid'] = (int)$localPage['uid'];
        }

        if (!empty($item['hasComparablePrevious']) && (string)($item['compareFromJobId'] ?? '') !== '' && $jobId !== '') {
            $parameters = [
                'remotePageUid' => $remotePageUid,
                'site' => $siteIdentifier,
                'id' => $pageUid,
                'language' => $languageUid,
                'compareFromJobId' => (string)$item['compareFromJobId'],
                'compareToJobId' => $jobId,
            ];
            $item['compareUrl'] = $this->buildRouteUrl('web_a11y.remotePageDetail', $parameters) . '#scan-comparison';
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRemotePageCompare(ServerRequestInterface $request, string $siteBase, array $remotePageHistory = []): array
    {
        $queryParams = $request->getQueryParams();
        $fromJobId = trim((string)($queryParams['compareFromJobId'] ?? ''));
        $toJobId = trim((string)($queryParams['compareToJobId'] ?? ''));
        if ($fromJobId === '' || $toJobId === '') {
            return ['available' => false, 'message' => ''];
        }

        $comparison = $this->remoteScanHistoryService->loadCompare($siteBase, $fromJobId, $toJobId);

        return $this->enrichCompareWithHistoryMeta($comparison, $remotePageHistory, $fromJobId, $toJobId);
    }

    /**
     * @param array<string, mixed> $comparison
     * @param array<string, mixed> $remotePageHistory
     * @return array<string, mixed>
     */
    private function enrichCompareWithHistoryMeta(array $comparison, array $remotePageHistory, string $fromJobId, string $toJobId): array
    {
        if (!($comparison['available'] ?? false) || empty($remotePageHistory['items']) || !is_array($remotePageHistory['items'])) {
            return $comparison;
        }

        $historyByJob = [];
        foreach ($remotePageHistory['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $jobId = trim((string)($item['jobId'] ?? ''));
            if ($jobId !== '') {
                $historyByJob[$jobId] = $item;
            }
        }

        foreach ([
            'previousScan' => $fromJobId,
            'currentScan' => $toJobId,
        ] as $targetKey => $jobId) {
            if (!isset($historyByJob[$jobId]) || !is_array($historyByJob[$jobId])) {
                continue;
            }
            if ((string)($comparison[$targetKey]['finishedAtFormatted'] ?? '') === '') {
                $comparison[$targetKey]['finishedAtFormatted'] = (string)($historyByJob[$jobId]['finishedAtFormatted'] ?? '');
            }
            if ((string)($comparison[$targetKey]['jobId'] ?? '') === '') {
                $comparison[$targetKey]['jobId'] = $jobId;
            }
        }

        $comparison['hasScanMeta'] = (string)($comparison['previousScan']['finishedAtFormatted'] ?? '') !== ''
            || (string)($comparison['currentScan']['finishedAtFormatted'] ?? '') !== '';

        return $comparison;
    }

    private function normalizeComparableHistoryUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return strtolower(rtrim($url, '/'));
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . (string)$parts['query'] : '';

        return ($scheme !== '' ? $scheme . '://' : '') . $host . $path . $query;
    }

    private function buildEditRecordUrl(
        string $table,
        int $uid,
        string $siteIdentifier,
        int $remotePageUid,
    ): string {
        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [
                $table => [
                    $uid => 'edit',
                ],
            ],
            'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute('web_a11y.remotePageDetail', [
                'site' => $siteIdentifier,
                'remotePageUid' => $remotePageUid,
            ]),
        ]);
    }

    private function buildRemotePageDebugUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        if (str_contains($url, 'aqgDebug=')) {
            return $url;
        }

        return $url . $separator . 'aqgDebug=1';
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, array<string, mixed>>
     */
    private function groupIssuesByRule(array $issues, array $pageRecommendation = []): array
    {
        $groups = [];

        foreach ($issues as $issue) {
            $ruleId = (string)($issue['rule_id'] ?? 'unknown');
            $metadata = $this->ruleMetadataPresentationService->present($issue);
            $helpUrl = trim((string)($issue['help_url'] ?? ''));
            $documentationLinkCandidates = is_array($metadata['documentationLinks'] ?? null)
                ? $metadata['documentationLinks']
                : [];
            $helpDocumentationLink = $this->createHelpDocumentationLink($helpUrl);
            if ($helpDocumentationLink !== null) {
                $documentationLinkCandidates[] = $helpDocumentationLink;
            }
            $documentationLinks = $this->filterDocumentationLinks(
                $documentationLinkCandidates,
                ''
            );

            if (!isset($groups[$ruleId])) {
                $whoShouldFix = $this->normalizeMachineValue($issue['who_should_fix'] ?? null);
                if ($whoShouldFix === '') {
                    $whoShouldFix = (string)($metadata['owner'] ?? '');
                }
                $fixType = $this->normalizeMachineValue($issue['fix_type'] ?? null);
                if ($fixType === '') {
                    $fixType = (string)($metadata['fixType'] ?? '');
                }
                $groups[$ruleId] = [
                    'rule_id' => $ruleId,
                    'impact' => (string)($issue['impact'] ?? ''),
                    'impact_tone' => $this->resolveImpactTone((string)($issue['impact'] ?? '')),
                    'help' => (string)($issue['help'] ?? ''),
                    'help_url' => $helpUrl,
                    'friendlyTitle' => (string)($metadata['title'] ?? ''),
                    'affectedUsers' => $metadata['affectedUsers'] ?? [],
                    'affectedUserItems' => $metadata['affectedUserItems'] ?? [],
                    'affectedUsersLabel' => (string)($metadata['affectedUsersLabel'] ?? ''),
                    'wcagReferences' => $metadata['wcagReferences'] ?? [],
                    'wcagPrimaryLabel' => (string)($metadata['wcagPrimaryLabel'] ?? ''),
                    'wcagCompactLabel' => (string)($metadata['wcagCompactLabel'] ?? ''),
                    'techniques' => $metadata['techniques'] ?? [],
                    'standards' => $metadata['standards'] ?? [],
                    'documentationLinks' => $documentationLinks,
                    'technicalTags' => $metadata['technicalTags'] ?? [],
                    'hasStandardsAndImpact' => false,
                    'guidanceWhyItMatters' => $this->normalizeNullableString($issue['guidance_why_it_matters'] ?? null) ?? $this->normalizeNullableString($metadata['whyItMatters'] ?? null),
                    'guidanceHowToFix' => $this->normalizeNullableString($issue['guidance_how_to_fix'] ?? null) ?? $this->normalizeNullableString($metadata['howToFix'] ?? null),
                    'guidanceHasApiText' => false,
                    'guidanceIsFallback' => false,
                    'whoShouldFix' => $whoShouldFix,
                    'whoShouldFixLabel' => $this->formatBadgeLabel($whoShouldFix),
                    'fixType' => $fixType,
                    'fixTypeLabel' => $this->formatBadgeLabel($fixType),
                    'confidence' => $this->normalizeMachineValue($issue['confidence'] ?? null),
                    'confidenceLabel' => $this->formatBadgeLabel($this->normalizeMachineValue($issue['confidence'] ?? null)),
                    'count' => 0,
                    'nodes' => [],
                    'mappedUids' => [],
                ];
            }

            if ((string)$groups[$ruleId]['help_url'] === '' && $helpUrl !== '') {
                $groups[$ruleId]['help_url'] = $helpUrl;
            }

            $groups[$ruleId]['documentationLinks'] = $this->filterDocumentationLinks(
                array_merge($groups[$ruleId]['documentationLinks'], $documentationLinks),
                ''
            );

            foreach ([
                'guidanceWhyItMatters' => 'guidance_why_it_matters',
                'guidanceHowToFix' => 'guidance_how_to_fix',
            ] as $targetKey => $sourceKey) {
                if ($groups[$ruleId][$targetKey] === null) {
                    $value = $this->normalizeNullableString($issue[$sourceKey] ?? null);
                    if ($value !== null) {
                        $groups[$ruleId][$targetKey] = $value;
                    }
                }
            }

            foreach (['whoShouldFix' => 'who_should_fix', 'fixType' => 'fix_type', 'confidence' => 'confidence'] as $targetKey => $sourceKey) {
                if ((string)$groups[$ruleId][$targetKey] === '') {
                    $value = $this->normalizeMachineValue($issue[$sourceKey] ?? null);
                    if ($value !== '') {
                        $groups[$ruleId][$targetKey] = $value;
                        $groups[$ruleId][$targetKey . 'Label'] = $this->formatBadgeLabel($value);
                    }
                }
            }

            $groups[$ruleId]['count'] += (int)($issue['nodes_count'] ?? 1);

            if (!empty($issue['nodes']) && is_array($issue['nodes'])) {
                foreach ($issue['nodes'] as $node) {
                    if (!is_array($node)) {
                        continue;
                    }

                    $groups[$ruleId]['nodes'][] = $node;

                    $mappedUid = (int)($node['mapped_uid'] ?? 0);
                    if ($mappedUid > 0) {
                        $groups[$ruleId]['mappedUids'][$mappedUid] = $mappedUid;
                    }
                }
            }
        }

        $primaryRuleId = strtolower(trim((string)($pageRecommendation['primaryRuleId'] ?? $pageRecommendation['primaryRule'] ?? '')));
        $hasPrimaryMatch = false;

        foreach ($groups as &$group) {
            $group['documentationLinks'] = $this->filterDocumentationLinks(
                $group['documentationLinks'],
                (string)$group['help_url']
            );
            $group['hasStandardsAndImpact'] = $this->hasStandardsAndImpact(
                $group,
                $group['documentationLinks']
            );
            $uids = array_values($group['mappedUids']);
            $group['highlightUids'] = $uids !== [] ? implode(',', $uids) : '';
            $group['guidanceHasApiText'] = $group['guidanceWhyItMatters'] !== null || $group['guidanceHowToFix'] !== null;
            $group['guidanceIsFallback'] = $group['guidanceHowToFix'] === null;
            $group['guidanceHowToFix'] = $group['guidanceHowToFix'] ?? 'Review this finding in context.';
            $group['primaryFixSummary'] = $this->buildPrimaryFixSummary($group['guidanceHowToFix']);
            $group['isPrimaryRecommendation'] = $primaryRuleId !== '' && strtolower((string)$group['rule_id']) === $primaryRuleId;
            $group['isDefaultOpen'] = false;
            if ($group['isPrimaryRecommendation']) {
                $hasPrimaryMatch = true;
            }
            unset($group['mappedUids']);
        }
        unset($group);

        $groups = array_values($groups);
        if ($hasPrimaryMatch) {
            usort($groups, static function (array $left, array $right): int {
                return ((int)!$left['isPrimaryRecommendation']) <=> ((int)!$right['isPrimaryRecommendation']);
            });
        }

        if ($groups !== []) {
            $groups[0]['isDefaultOpen'] = true;
            if (!$hasPrimaryMatch) {
                $groups[0]['isPrimaryRecommendation'] = true;
            }
        }

        return $groups;
    }

    /** @return array{label:string,url:string,type:string}|null */
    private function createHelpDocumentationLink(string $helpUrl): ?array
    {
        if ($helpUrl === '') {
            return null;
        }

        $urlParts = parse_url($helpUrl);
        $scheme = is_array($urlParts)
            ? strtolower((string)($urlParts['scheme'] ?? ''))
            : '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return [
            'label' => $this->translate('module.remotePageDetail.ruleDocs'),
            'url' => $helpUrl,
            'type' => 'deque',
        ];
    }

    /**
     * @param mixed $documentationLinks
     * @return array<int, array<string, mixed>>
     */
    private function filterDocumentationLinks(mixed $documentationLinks, string $helpUrl): array
    {
        if (!is_array($documentationLinks)) {
            return [];
        }

        $helpUrl = trim($helpUrl);
        $filtered = [];
        $seenUrls = [];

        foreach ($documentationLinks as $documentationLink) {
            if (!is_array($documentationLink)) {
                continue;
            }

            $url = trim((string)($documentationLink['url'] ?? ''));
            if ($url === '' || $url === $helpUrl || isset($seenUrls[$url])) {
                continue;
            }

            $seenUrls[$url] = true;
            $documentationLink['url'] = $url;
            $filtered[] = $documentationLink;
        }

        return array_values($filtered);
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<int, array<string, mixed>> $documentationLinks
     */
    private function hasStandardsAndImpact(array $metadata, array $documentationLinks): bool
    {
        if (trim((string)($metadata['wcagCompactLabel'] ?? '')) !== '') {
            return true;
        }

        foreach ([
            'affectedUsers',
            'affectedUserItems',
            'wcagReferences',
            'techniques',
            'standards',
            'technicalTags',
        ] as $key) {
            if ($this->hasMetadataValue($metadata[$key] ?? null)) {
                return true;
            }
        }

        return $documentationLinks !== [];
    }

    private function hasMetadataValue(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->hasMetadataValue($item)) {
                    return true;
                }
            }

            return false;
        }

        return is_scalar($value) && trim((string)$value) !== '';
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string)$value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeMachineValue(mixed $value): string
    {
        $normalized = $this->normalizeNullableString($value);
        if ($normalized === null) {
            return '';
        }

        $normalized = strtolower($normalized);
        $normalized = preg_replace('/[^a-z0-9_-]+/', '_', $normalized) ?? '';
        $normalized = substr(trim($normalized, '_-'), 0, 50);

        if (in_array($normalized, ['unknown', 'n_a', 'na', 'none', 'not_set', 'undefined'], true)) {
            return '';
        }

        return $normalized;
    }

    private function formatBadgeLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return ucwords(str_replace(['_', '-'], ' ', $value));
    }

    private function resolveImpactTone(string $impact): string
    {
        return match (strtolower(trim($impact))) {
            'critical' => 'critical',
            'serious', 'moderate' => 'warning',
            'minor' => 'info',
            default => 'none',
        };
    }

    /**
     * @param array<string, mixed> $remotePage
     * @param array<string, mixed>|null $remoteScan
     * @param array<string, mixed>|null $activeRemoteScan
     * @return array<string, mixed>
     */
    private function buildRemoteDetailViewData(array $remotePage, ?array $remoteScan, ?array $activeRemoteScan): array
    {
        $httpStatus = (int)($remotePage['http_status'] ?? 0);
        $failureReason = trim((string)($remotePage['failure_reason'] ?? ''));
        $pageMarkedFailed = (int)($remotePage['is_failed'] ?? 0) === 1;
        $scanStatus = strtolower(trim((string)($remoteScan['status'] ?? '')));
        $hasActiveScan = is_array($activeRemoteScan) && trim((string)($activeRemoteScan['job_id'] ?? '')) !== '';
        $isFailed = !$hasActiveScan && (
            $scanStatus === 'failed'
            || $pageMarkedFailed
            || ($remoteScan === null && ($failureReason !== '' || $httpStatus >= 400))
        );

        $finishedAt = (int)($remoteScan['finished_at'] ?? 0);
        $startedAt = (int)($remoteScan['started_at'] ?? 0);
        $activeStartedAt = (int)($activeRemoteScan['started_at'] ?? 0);
        $activePagesScanned = (int)($activeRemoteScan['pages_scanned'] ?? 0);
        $activePagesTotal = (int)($activeRemoteScan['pages_total'] ?? 0);

        return [
            'scanType' => 'frontend_http',
            'httpStatusLabel' => $httpStatus > 0 ? (string)$httpStatus : '—',
            'httpStatusTone' => $httpStatus > 0 && $httpStatus < 400 ? 'tone-ok' : ($httpStatus >= 500 ? 'tone-critical' : 'tone-warning'),
            'lastScanAt' => $finishedAt,
            'lastScanAtFormatted' => BackendTimeUtility::formatDateTime($finishedAt, 'd.m.Y · H:i'),
            'lastAttemptAt' => $finishedAt > 0 ? $finishedAt : $startedAt,
            'lastAttemptAtFormatted' => BackendTimeUtility::formatDateTime($finishedAt > 0 ? $finishedAt : $startedAt, 'd.m.Y · H:i'),
            'startedAt' => $startedAt,
            'startedAtFormatted' => BackendTimeUtility::formatDateTime($startedAt, 'd.m.Y H:i:s'),
            'activeStartedAt' => $activeStartedAt,
            'activeStartedAtFormatted' => BackendTimeUtility::formatDateTime($activeStartedAt, 'H:i'),
            'activePagesLabel' => $activePagesTotal > 0
                ? $activePagesScanned . ' / ' . $activePagesTotal
                : (string)$activePagesScanned,
            'durationLabel' => $this->formatDuration($startedAt, $finishedAt),
            'failureReason' => $failureReason,
            'isFailed' => $isFailed,
            'hasActiveScan' => $hasActiveScan,
            'hasCompletedScan' => $remoteScan !== null && $finishedAt > 0,
        ];
    }

    private function formatDuration(int $startedAt, int $finishedAt): string
    {
        if ($startedAt <= 0 || $finishedAt <= $startedAt) {
            return '—';
        }

        $seconds = $finishedAt - $startedAt;
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }

    /**
     * @param array<string, mixed>|null $remoteScan
     * @param array<int, array<string, mixed>> $issuesWithNodes
     */
    private function resolveTypo3PageUid(?array $remoteScan, array $issuesWithNodes): int
    {
        $scanPageUid = (int)($remoteScan['page_uid'] ?? 0);
        if ($scanPageUid > 0) {
            return $scanPageUid;
        }

        foreach ($issuesWithNodes as $issue) {
            $nodes = is_array($issue['nodes'] ?? null) ? $issue['nodes'] : [];

            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }

                $mappedTable = trim((string)($node['mapped_table'] ?? ''));
                $mappedUid = (int)($node['mapped_uid'] ?? 0);

                if ($mappedTable === '' || $mappedUid <= 0) {
                    continue;
                }

                if ($mappedTable === 'pages') {
                    return $mappedUid;
                }

                $record = BackendUtility::getRecord($mappedTable, $mappedUid, 'pid');
                if (is_array($record) && (int)($record['pid'] ?? 0) > 0) {
                    return (int)$record['pid'];
                }
            }
        }

        return 0;
    }


    /**
     * @return array<string, mixed>
     */
    private function decodeKeyboardStructureReview(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        return $this->normalizeKeyboardStructureReview($decoded);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeKeyboardStructureReview(array $payload): array
    {
        $keyboardDetails = is_array($payload['keyboardDetails'] ?? null)
            ? $payload['keyboardDetails']
            : (is_array($payload['keyboard_details'] ?? null) ? $payload['keyboard_details'] : []);
        $structureDetails = is_array($payload['structureDetails'] ?? null)
            ? $payload['structureDetails']
            : (is_array($payload['structure_details'] ?? null) ? $payload['structure_details'] : []);

        $keyboard = $this->normalizeKeyboardReview($payload, $keyboardDetails);
        $structure = $this->normalizeStructureReview($structureDetails);

        if ($keyboard === [] && $structure === []) {
            return [];
        }

        return [
            'title' => 'Keyboard & structure review',
            'subtitle' => 'Automated helper signals for manual review. These checks do not replace a full accessibility audit.',
            'keyboard' => $keyboard,
            'hasKeyboard' => $keyboard !== [],
            'structure' => $structure,
            'hasStructure' => $structure !== [],
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function normalizeKeyboardReview(array $summary, array $details): array
    {
        if ($summary === [] && $details === []) {
            return [];
        }

        $focusPath = $this->normalizeFocusPath($details['focusPath'] ?? $details['focus_path'] ?? []);
        $focusWarnings = $this->normalizeReviewWarnings($details['focusWarnings'] ?? $details['focus_warnings'] ?? []);
        $focusStepsTotal = max(0, (int)($summary['focusStepsTotal'] ?? $summary['focus_steps_total'] ?? count($focusPath)));
        $uniqueFocusedElementsTotal = max(0, (int)($summary['uniqueFocusedElementsTotal'] ?? $summary['unique_focused_elements_total'] ?? 0));
        $invisibleFocusIssuesTotal = max(0, (int)($summary['invisibleFocusIssuesTotal'] ?? $summary['invisible_focus_issues_total'] ?? count($focusWarnings)));
        $possibleKeyboardTrap = (bool)($summary['possibleKeyboardTrap'] ?? $summary['possible_keyboard_trap'] ?? false);
        $manualReviewRequired = (bool)($summary['manualReviewRequired'] ?? $summary['manual_review_required'] ?? true);
        $recommendation = $this->normalizeNullableString($details['recommendation'] ?? $summary['recommendation'] ?? null);

        if ($focusStepsTotal === 0 && $uniqueFocusedElementsTotal === 0 && $invisibleFocusIssuesTotal === 0 && $focusPath === [] && $focusWarnings === [] && $recommendation === null) {
            return [];
        }

        return [
            'focusStepsTotal' => $focusStepsTotal,
            'uniqueFocusedElementsTotal' => $uniqueFocusedElementsTotal,
            'invisibleFocusIssuesTotal' => $invisibleFocusIssuesTotal,
            'invisibleFocusIssuesLabel' => $invisibleFocusIssuesTotal === 1 ? '1 focus visibility warning' : $invisibleFocusIssuesTotal . ' focus visibility warnings',
            'possibleKeyboardTrap' => $possibleKeyboardTrap,
            'possibleKeyboardTrapLabel' => $possibleKeyboardTrap ? 'Possible trap signal' : 'No trap signal',
            'manualReviewRequired' => $manualReviewRequired,
            'manualReviewLabel' => $manualReviewRequired ? 'Manual review required' : 'Manual review may still be required',
            'recommendation' => $recommendation,
            'focusPath' => $focusPath,
            'hasFocusPath' => $focusPath !== [],
            'focusPathLimitLabel' => count($focusPath) >= 50 ? 'Showing first 50 focus steps.' : '',
            'focusWarnings' => $focusWarnings,
            'hasFocusWarnings' => $focusWarnings !== [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeFocusPath(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        if ($items !== [] && !array_is_list($items)) {
            $items = [$items];
        }

        $normalized = [];
        $step = 1;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $hasVisibleFocusRaw = $item['hasVisibleFocus'] ?? $item['has_visible_focus'] ?? null;
            $hasVisibleFocus = is_bool($hasVisibleFocusRaw) ? $hasVisibleFocusRaw : null;
            $warning = $this->normalizeNullableString($item['warning'] ?? $item['warningType'] ?? $item['warning_type'] ?? null);
            if ($warning === null && $hasVisibleFocus === false) {
                $warning = 'Review visible focus';
            }
            $tagName = $this->normalizeNullableString($item['tagName'] ?? $item['tag_name'] ?? $item['tag'] ?? null);
            $role = $this->normalizeNullableString($item['role'] ?? null);
            $normalized[] = [
                'step' => max(1, (int)($item['step'] ?? $item['index'] ?? $step)),
                'element' => $tagName ?? $role ?? 'element',
                'accessibleName' => $this->truncateForUi($this->normalizeNullableString($item['accessibleName'] ?? $item['accessible_name'] ?? $item['text'] ?? null), 100),
                'selector' => $this->truncateForUi($this->normalizeNullableString($item['selector'] ?? null), 160),
                'hasVisibleFocus' => $hasVisibleFocus,
                'visibleFocusLabel' => $hasVisibleFocus === true ? 'Visible focus' : ($hasVisibleFocus === false ? 'Review focus' : 'Not reported'),
                'warning' => $warning,
                'selectorReliability' => $this->normalizeMachineValue($item['selectorReliability'] ?? $item['selector_reliability'] ?? null),
                'selectorReliabilityLabel' => $this->formatBadgeLabel($this->normalizeMachineValue($item['selectorReliability'] ?? $item['selector_reliability'] ?? null)),
            ];
            $step++;
            if (count($normalized) >= 50) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function normalizeStructureReview(array $details): array
    {
        if ($details === []) {
            return [];
        }

        $headings = $this->normalizeHeadingOutline($details['headings'] ?? []);
        $landmarks = $this->normalizeLandmarks($details['landmarks'] ?? []);
        $warnings = $this->normalizeReviewWarnings($details['warnings'] ?? []);
        $likelyTemplateIssue = (bool)($details['likelyTemplateIssue'] ?? $details['likely_template_issue'] ?? false);
        $recommendation = $this->normalizeNullableString($details['recommendation'] ?? null);

        if ($headings === [] && $landmarks === [] && $warnings === [] && !$likelyTemplateIssue && $recommendation === null) {
            return [];
        }

        return [
            'headings' => $headings,
            'hasHeadings' => $headings !== [],
            'landmarks' => $landmarks,
            'hasLandmarks' => $landmarks !== [],
            'warnings' => $warnings,
            'hasWarnings' => $warnings !== [],
            'likelyTemplateIssue' => $likelyTemplateIssue,
            'likelyTemplateIssueLabel' => $likelyTemplateIssue ? 'Likely template/layout issue' : '',
            'recommendation' => $recommendation,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeHeadingOutline(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        if ($items !== [] && !array_is_list($items)) {
            $items = [$items];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rawLevel = (int)($item['level'] ?? $item['headingLevel'] ?? $item['heading_level'] ?? 0);
            if ($rawLevel < 1 || $rawLevel > 6) {
                continue;
            }
            $level = $rawLevel;
            $text = $this->normalizeNullableString($item['text'] ?? $item['name'] ?? $item['accessibleName'] ?? $item['accessible_name'] ?? null);
            $isEmpty = (bool)($item['isEmpty'] ?? $item['is_empty'] ?? ($text === null));
            $levelJump = (bool)($item['levelJump'] ?? $item['level_jump'] ?? $item['hasLevelJump'] ?? $item['has_level_jump'] ?? false);
            $warnings = $this->normalizeStringList($item['warnings'] ?? []);
            if ($isEmpty && !in_array('Empty heading', $warnings, true)) {
                $warnings[] = 'Empty heading';
            }
            if ($levelJump && !in_array('Heading level jump', $warnings, true)) {
                $warnings[] = 'Heading level jump';
            }
            $normalized[] = [
                'level' => $level,
                'levelLabel' => 'H' . $level,
                'text' => $this->truncateForUi($text ?? '[empty heading]', 140),
                'isEmpty' => $isEmpty,
                'levelJump' => $levelJump,
                'warnings' => array_slice($warnings, 0, 3),
                'hasWarnings' => $warnings !== [],
            ];
        }

        return array_slice($normalized, 0, 80);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLandmarks(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        if ($items !== [] && !array_is_list($items)) {
            $items = [$items];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $role = $this->normalizeMachineValue($item['role'] ?? $item['landmark'] ?? $item['type'] ?? '');
            if ($role === '') {
                continue;
            }
            $count = max(0, (int)($item['count'] ?? $item['total'] ?? $item['foundTotal'] ?? $item['found_total'] ?? 1));
            $isMissing = (bool)($item['missing'] ?? $item['isMissing'] ?? $item['is_missing'] ?? false) || $count === 0;
            $normalized[] = [
                'role' => $role,
                'roleLabel' => $this->formatBadgeLabel($role),
                'label' => $this->normalizeNullableString($item['label'] ?? $item['name'] ?? null),
                'count' => $count,
                'countLabel' => $isMissing ? 'missing' : ($count === 1 ? '1 found' : $count . ' found'),
                'isMissing' => $isMissing,
            ];
        }

        return array_slice($normalized, 0, 30);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeReviewWarnings(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        if ($items !== [] && !array_is_list($items)) {
            $items = [$items];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = $this->normalizeMachineValue($item['type'] ?? $item['ruleId'] ?? $item['rule_id'] ?? 'warning');
            $owner = $this->normalizeMachineValue($item['recommendedOwner'] ?? $item['recommended_owner'] ?? $item['owner'] ?? null);
            $fixType = $this->normalizeMachineValue($item['fixType'] ?? $item['fix_type'] ?? null);
            $summary = $this->normalizeNullableString($item['summary'] ?? $item['message'] ?? $item['description'] ?? null);
            $recommendation = $this->normalizeNullableString($item['recommendation'] ?? $item['suggestedAction'] ?? $item['suggested_action'] ?? null);
            $normalized[] = [
                'type' => $type,
                'typeLabel' => $this->formatBadgeLabel($type),
                'summary' => $summary,
                'recommendation' => $recommendation,
                'recommendedOwner' => $owner,
                'recommendedOwnerLabel' => $this->formatBadgeLabel($owner),
                'fixType' => $fixType,
                'fixTypeLabel' => $this->formatBadgeLabel($fixType),
            ];
        }

        return array_slice($normalized, 0, 20);
    }


    private function buildPrimaryFixSummary(?string $value): string
    {
        $value = $this->normalizeNullableString($value);
        if ($value === null) {
            return '';
        }

        $sentence = $value;
        if (preg_match('/^(.+?[.!?])(?:\s|$)/u', $value, $matches) === 1) {
            $sentence = trim((string)$matches[1]);
        }

        if (mb_strlen($sentence) <= 130) {
            return $sentence;
        }

        $short = rtrim(mb_substr($sentence, 0, 127));
        $lastSpace = mb_strrpos($short, ' ');
        if ($lastSpace !== false && $lastSpace > 70) {
            $short = rtrim(mb_substr($short, 0, $lastSpace));
        }

        return rtrim($short, ' ,;:.') . '.';
    }

    private function truncateForUi(?string $value, int $limit): string
    {
        if ($value === null) {
            return '';
        }
        if ($limit <= 0 || mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit - 1)) . '…';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePageRecommendation(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return $this->normalizePageRecommendation(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string, mixed> $recommendation
     * @return array<string, mixed>
     */
    private function normalizePageRecommendation(array $recommendation): array
    {
        if ($recommendation === []) {
            return [];
        }

        $summary = $this->normalizeNullableString($recommendation['summary'] ?? $recommendation['shortFix'] ?? $recommendation['short_fix'] ?? null);
        $primaryRuleId = $this->normalizeNullableString($recommendation['primaryRuleId'] ?? $recommendation['primary_rule_id'] ?? null);
        $primaryFixType = $this->normalizeMachineValue($recommendation['primaryFixType'] ?? $recommendation['primary_fix_type'] ?? null);
        $primaryOwner = $this->normalizeMachineValue($recommendation['primaryOwner'] ?? $recommendation['primary_owner'] ?? null);
        $quickWinsTotal = max(0, (int)($recommendation['quickWinsTotal'] ?? $recommendation['quick_wins_total'] ?? 0));
        $templateIssuesTotal = max(0, (int)($recommendation['templateIssuesTotal'] ?? $recommendation['template_issues_total'] ?? 0));
        $contentIssuesTotal = max(0, (int)($recommendation['contentIssuesTotal'] ?? $recommendation['content_issues_total'] ?? 0));
        $designIssuesTotal = max(0, (int)($recommendation['designIssuesTotal'] ?? $recommendation['design_issues_total'] ?? 0));
        $firstSteps = $this->normalizeStringList($recommendation['firstSteps'] ?? $recommendation['first_steps'] ?? []);
        $manualReviewRequired = (bool)($recommendation['manualReviewRequired'] ?? $recommendation['manual_review_required'] ?? false);

        if ($summary === null && $primaryRuleId === null && $primaryFixType === '' && $primaryOwner === '' && $quickWinsTotal === 0 && $templateIssuesTotal === 0 && $contentIssuesTotal === 0 && $designIssuesTotal === 0 && $firstSteps === [] && !$manualReviewRequired) {
            return [];
        }

        return [
            'title' => 'Start here',
            'subtitle' => 'Suggested first step based on automated findings',
            'summary' => $summary,
            'primaryRuleId' => $primaryRuleId,
            'primaryFixType' => $primaryFixType,
            'primaryFixTypeLabel' => $this->formatBadgeLabel($primaryFixType),
            'primaryOwner' => $primaryOwner,
            'primaryOwnerLabel' => $this->formatBadgeLabel($primaryOwner),
            'quickWinsTotal' => $quickWinsTotal,
            'quickWinsLabel' => $quickWinsTotal > 0 ? $quickWinsTotal . ' ' . ($quickWinsTotal === 1 ? 'quick win' : 'quick wins') : '',
            'templateIssuesTotal' => $templateIssuesTotal,
            'templateIssuesLabel' => $templateIssuesTotal > 0 ? $templateIssuesTotal . ' ' . ($templateIssuesTotal === 1 ? 'template issue' : 'template issues') : '',
            'contentIssuesTotal' => $contentIssuesTotal,
            'contentIssuesLabel' => $contentIssuesTotal > 0 ? $contentIssuesTotal . ' ' . ($contentIssuesTotal === 1 ? 'content issue' : 'content issues') : '',
            'designIssuesTotal' => $designIssuesTotal,
            'designIssuesLabel' => $designIssuesTotal > 0 ? $designIssuesTotal . ' ' . ($designIssuesTotal === 1 ? 'design issue' : 'design issues') : '',
            'firstSteps' => $firstSteps,
            'hasFirstSteps' => $firstSteps !== [],
            'manualReviewRequired' => $manualReviewRequired,
            'manualReviewLabel' => $manualReviewRequired ? 'Manual review required' : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeRemediationSummary(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return $this->normalizeRemediationSummary(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function normalizeRemediationSummary(array $summary): array
    {
        if ($summary === []) {
            return [];
        }

        $items = [];
        foreach ([
            'editorContentFixes' => ['label' => 'Editor/content fixes', 'keys' => ['editorContentFixes', 'editor_content_fixes', 'editorFixes', 'editor_fixes', 'contentFixes', 'content_fixes']],
            'developerTemplateFixes' => ['label' => 'Developer/template fixes', 'keys' => ['developerTemplateFixes', 'developer_template_fixes', 'developerFixes', 'developer_fixes', 'templateFixes', 'template_fixes']],
            'designFixes' => ['label' => 'Design fixes', 'keys' => ['designFixes', 'design_fixes']],
            'quickWins' => ['label' => 'Quick wins', 'keys' => ['quickWins', 'quick_wins']],
        ] as $key => $config) {
            $value = null;
            foreach ($config['keys'] as $sourceKey) {
                if (array_key_exists($sourceKey, $summary)) {
                    $value = $summary[$sourceKey];
                    break;
                }
            }

            $item = $this->normalizeRemediationSummaryItem((string)$key, (string)$config['label'], $value);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $recommendation = $this->normalizeNullableString($summary['recommendation'] ?? $summary['recommendedNextStep'] ?? $summary['recommended_next_step'] ?? null);
        $note = $this->normalizeNullableString($summary['note'] ?? $summary['disclaimer'] ?? null);

        if ($items === [] && $recommendation === null && $note === null) {
            return [];
        }

        return [
            'title' => 'Suggested remediation grouping',
            'items' => $items,
            'hasItems' => $items !== [],
            'recommendation' => $recommendation,
            'note' => $note,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeRemediationSummaryItem(string $key, string $label, mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $count = null;
        $description = null;
        if (is_array($value)) {
            $count = isset($value['count']) || isset($value['total']) || isset($value['issuesTotal']) || isset($value['issues_total'])
                ? max(0, (int)($value['count'] ?? $value['total'] ?? $value['issuesTotal'] ?? $value['issues_total'] ?? 0))
                : null;
            $description = $this->normalizeNullableString($value['label'] ?? $value['description'] ?? $value['note'] ?? null);
        } elseif (is_numeric($value)) {
            $count = max(0, (int)$value);
        } else {
            $description = $this->normalizeNullableString($value);
        }

        if ($count === null && $description === null) {
            return null;
        }
        if ($count === 0 && $description === null) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'countLabel' => $count !== null ? $count . ' ' . ($count === 1 ? 'item' : 'items') : '',
            'description' => $description,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeContrastDetails(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        if ($decoded !== [] && !array_is_list($decoded)) {
            $decoded = [$decoded];
        }

        $items = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalized = $this->normalizeContrastDetail($item);
            if ($normalized !== null) {
                $items[] = $normalized;
            }
        }

        return array_slice($items, 0, 5);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function normalizeContrastDetail(array $item): ?array
    {
        $suggestion = $this->normalizeContrastSuggestion($item['contrastSuggestion'] ?? $item['contrast_suggestion'] ?? []);
        $actualRatio = $this->normalizeNullableString($item['actualRatio'] ?? $item['actual_ratio'] ?? $suggestion['actualRatio'] ?? null);
        $requiredRatio = $this->normalizeNullableString($item['requiredRatio'] ?? $item['required_ratio'] ?? $suggestion['requiredRatio'] ?? null);
        $foreground = $this->normalizeColorValue($item['foreground'] ?? $item['foregroundColor'] ?? $item['foreground_color'] ?? $suggestion['currentForeground'] ?? null);
        $background = $this->normalizeColorValue($item['background'] ?? $item['backgroundColor'] ?? $item['background_color'] ?? $suggestion['currentBackground'] ?? null);
        $issuesTotal = max(0, (int)($item['issuesTotal'] ?? $item['issues_total'] ?? 0));

        if ($actualRatio === null && $requiredRatio === null && $foreground === null && $background === null && $issuesTotal === 0 && $suggestion === []) {
            return null;
        }

        return [
            'actualRatio' => $actualRatio,
            'requiredRatio' => $requiredRatio,
            'foreground' => $foreground,
            'background' => $background,
            'issuesTotal' => $issuesTotal,
            'issuesLabel' => $issuesTotal === 1 ? '1 issue' : $issuesTotal . ' issues',
            'contrastSuggestion' => $suggestion,
            'hasContrastSuggestion' => $suggestion !== [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeContrastSuggestion(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $foregroundCandidateDetails = $this->normalizeColorCandidateDetails(
            $value['suggestedForegroundCandidateDetails']
            ?? $value['suggested_foreground_candidate_details']
            ?? $value['suggestedForegroundCandidates']
            ?? $value['suggested_foreground_candidates']
            ?? []
        );
        $backgroundCandidateDetails = $this->normalizeColorCandidateDetails(
            $value['suggestedBackgroundCandidateDetails']
            ?? $value['suggested_background_candidate_details']
            ?? $value['suggestedBackgroundCandidates']
            ?? $value['suggested_background_candidates']
            ?? []
        );
        $foregroundCandidates = array_column($foregroundCandidateDetails, 'color');
        $backgroundCandidates = array_column($backgroundCandidateDetails, 'color');
        $actualRatio = $this->normalizeNullableString($value['actualRatio'] ?? $value['actual_ratio'] ?? null);
        $requiredRatio = $this->normalizeNullableString($value['requiredRatio'] ?? $value['required_ratio'] ?? null);
        $currentForeground = $this->normalizeColorValue($value['currentForeground'] ?? $value['current_foreground'] ?? null);
        $currentBackground = $this->normalizeColorValue($value['currentBackground'] ?? $value['current_background'] ?? null);
        $note = $this->normalizeNullableString($value['note'] ?? null);
        $riskLevel = $this->normalizeMachineValue($value['riskLevel'] ?? $value['risk_level'] ?? null);
        $reviewHint = $this->normalizeNullableString($value['reviewHint'] ?? $value['review_hint'] ?? null);
        $candidateType = $this->normalizeMachineValue($value['candidateType'] ?? $value['candidate_type'] ?? null);
        $preferredCandidate = $this->normalizePreferredContrastCandidate($value);

        if ($foregroundCandidates === [] && $backgroundCandidates === [] && $preferredCandidate === null && $actualRatio === null && $requiredRatio === null && $currentForeground === null && $currentBackground === null && $note === null && $riskLevel === '' && $reviewHint === null && $candidateType === '') {
            return [];
        }

        return [
            'currentForeground' => $currentForeground,
            'currentBackground' => $currentBackground,
            'actualRatio' => $actualRatio,
            'requiredRatio' => $requiredRatio,
            'preferredCandidate' => $preferredCandidate,
            'hasPreferredCandidate' => $preferredCandidate !== null,
            'riskLevel' => $riskLevel,
            'riskLevelLabel' => $this->formatBadgeLabel($riskLevel),
            'reviewHint' => $reviewHint,
            'candidateType' => $candidateType,
            'candidateTypeLabel' => $this->formatBadgeLabel($candidateType),
            'suggestedForegroundCandidates' => $foregroundCandidates,
            'suggestedBackgroundCandidates' => $backgroundCandidates,
            'suggestedForegroundCandidateDetails' => $foregroundCandidateDetails,
            'suggestedBackgroundCandidateDetails' => $backgroundCandidateDetails,
            'hasSuggestedForegroundCandidates' => $foregroundCandidates !== [],
            'hasSuggestedBackgroundCandidates' => $backgroundCandidates !== [],
            'note' => $note ?? $reviewHint ?? 'Candidate colors are generated as an automated remediation aid and must be reviewed in the brand/design context.',
        ];
    }

    /**
     * @return array<int, array{color:string,estimatedRatio:?string,label:string}>
     */
    private function normalizeColorCandidateDetails(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        if ($value !== [] && !array_is_list($value)) {
            $value = [$value];
        }

        $items = [];
        foreach ($value as $candidate) {
            $color = null;
            $estimatedRatio = null;

            $apiLabel = null;
            $explanation = null;
            if (is_array($candidate)) {
                $color = $this->normalizeColorValue($candidate['color'] ?? $candidate['candidate'] ?? $candidate['value'] ?? $candidate['hex'] ?? null);
                $estimatedRatio = $this->normalizeNullableString($candidate['estimatedRatio'] ?? $candidate['estimated_ratio'] ?? $candidate['ratio'] ?? null);
                $apiLabel = $this->normalizeNullableString($candidate['label'] ?? null);
                $explanation = $this->normalizeNullableString($candidate['explanation'] ?? $candidate['reason'] ?? null);
            } else {
                $color = $this->normalizeColorValue($candidate);
            }

            if ($color === null) {
                continue;
            }

            $label = $apiLabel ?? ($estimatedRatio !== null ? $color . ' · estimated contrast ratio ' . $estimatedRatio : $color);
            $items[$color . '|' . ($estimatedRatio ?? '') . '|' . ($label ?? '')] = [
                'color' => $color,
                'estimatedRatio' => $estimatedRatio,
                'label' => $label,
                'explanation' => $explanation,
            'reason' => $explanation,
            ];
        }

        return array_slice(array_values($items), 0, 6);
    }

    /**
     * @param array<string, mixed> $value
     * @return array{color:string,type:string,typeLabel:string,estimatedRatio:?string,label:string}|null
     */
    private function normalizePreferredContrastCandidate(array $value): ?array
    {
        $candidate = $value['preferredCandidate'] ?? $value['preferred_candidate'] ?? null;
        $estimatedRatio = $this->normalizeNullableString(
            $value['preferredCandidateEstimatedRatio']
            ?? $value['preferred_candidate_estimated_ratio']
            ?? $value['estimatedRatio']
            ?? $value['estimated_ratio']
            ?? null
        );
        $type = '';
        $color = null;

        $apiLabel = null;
        $explanation = null;
        if (is_array($candidate)) {
            $color = $this->normalizeColorValue($candidate['color'] ?? $candidate['candidate'] ?? $candidate['value'] ?? $candidate['hex'] ?? null);
            $estimatedRatio = $this->normalizeNullableString($candidate['estimatedRatio'] ?? $candidate['estimated_ratio'] ?? $candidate['ratio'] ?? null) ?? $estimatedRatio;
            $type = $this->normalizeMachineValue($candidate['type'] ?? $candidate['target'] ?? $candidate['appliesTo'] ?? $candidate['applies_to'] ?? null);
            $apiLabel = $this->normalizeNullableString($candidate['label'] ?? null);
            $explanation = $this->normalizeNullableString($candidate['explanation'] ?? $candidate['reason'] ?? null);
        } else {
            $color = $this->normalizeColorValue($candidate);
        }

        if ($color === null) {
            return null;
        }

        $typeLabel = match ($type) {
            'foreground', 'fg', 'text' => 'Foreground',
            'background', 'bg' => 'Background',
            default => 'Candidate',
        };

        return [
            'color' => $color,
            'type' => $type,
            'typeLabel' => $typeLabel,
            'estimatedRatio' => $estimatedRatio,
            'label' => $apiLabel ?? ($estimatedRatio !== null ? $color . ' · estimated contrast ratio ' . $estimatedRatio : $color),
            'explanation' => $explanation,
            'reason' => $explanation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeNodeRemediation(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return $this->normalizeNodeRemediation(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string, mixed> $remediation
     * @return array<string, mixed>
     */
    private function normalizeNodeRemediation(array $remediation): array
    {
        if ($remediation === []) {
            return [];
        }

        $summary = $this->normalizeNullableString($remediation['summary'] ?? null);
        $steps = $this->normalizeStringList($remediation['steps'] ?? []);
        $recommendedOwner = $this->normalizeMachineValue($remediation['recommendedOwner'] ?? $remediation['recommended_owner'] ?? null);
        $fixType = $this->normalizeMachineValue($remediation['fixType'] ?? $remediation['fix_type'] ?? null);
        $confidence = $this->normalizeMachineValue($remediation['confidence'] ?? null);
        $documentationHint = $this->normalizeNullableString($remediation['documentationHint'] ?? $remediation['documentation_hint'] ?? null);

        if ($summary === null && $steps === [] && $recommendedOwner === '' && $fixType === '' && $confidence === '' && $documentationHint === null) {
            return [];
        }

        return [
            'summary' => $summary,
            'steps' => $steps,
            'hasSteps' => $steps !== [],
            'recommendedOwner' => $recommendedOwner,
            'recommendedOwnerLabel' => $this->formatBadgeLabel($recommendedOwner),
            'fixType' => $fixType,
            'fixTypeLabel' => $this->formatBadgeLabel($fixType),
            'confidence' => $confidence,
            'confidenceLabel' => $this->formatBadgeLabel($confidence),
            'documentationHint' => $documentationHint,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $text = $this->normalizeNullableString($item);
            if ($text !== null) {
                $items[] = $text;
            }
        }

        return array_slice(array_values(array_unique($items)), 0, 6);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeColorCandidates(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $candidate) {
            $color = $this->normalizeColorValue($candidate);
            if ($color !== null) {
                $items[$color] = $color;
            }
        }

        return array_slice(array_values($items), 0, 6);
    }

    private function normalizeColorValue(mixed $value): ?string
    {
        $normalized = $this->normalizeNullableString($value);
        if ($normalized === null) {
            return null;
        }

        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $normalized)) {
            return substr($normalized, 0, 32);
        }

        return strtolower($normalized);
    }

    private function buildRemoteOverviewBackUrl(
        ServerRequestInterface $request,
        string $siteIdentifier,
        int $pageUid = 0,
        ?int $languageUid = null,
    ): string {
        $queryParams = $request->getQueryParams();
        $resolvedPageUid = $pageUid > 0 ? $pageUid : (int)($queryParams['id'] ?? 0);
        $resolvedLanguageUid = $languageUid;

        if ($resolvedLanguageUid === null && $this->requestParameterService->hasLanguageParameter($queryParams)) {
            $resolvedLanguageUid = $this->requestParameterService->getLanguageUidFromParameters(
                $queryParams,
                [],
                0,
                false,
            );
        }

        $parameters = [];
        if ($resolvedPageUid > 0) {
            $parameters['id'] = $resolvedPageUid;
        }
        if ($siteIdentifier !== '') {
            $parameters['site'] = $siteIdentifier;
        }
        $parameters['language'] = $resolvedLanguageUid ?? 0;

        return $this->buildRouteUrl('web_a11y', $parameters);
    }

    private function configureDocHeader(
        ModuleTemplate $moduleTemplate,
        string $backUrl,
        string $remotePageDebugUrl,
    ): void {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        $this->setModuleTitle(
            $moduleTemplate,
            'module.title',
            'module.remotePageDetail.title'
        );

        $backButton = $buttonBar->makeLinkButton()
            ->setHref($backUrl)
            ->setTitle($this->translate('settings.backToOverview') ?: 'Back to overview')
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL));

        $buttonBar->addButton($backButton, ButtonBar::BUTTON_POSITION_LEFT);

        if ($remotePageDebugUrl !== '') {
            $openFrontendButton = $buttonBar->makeLinkButton()
                ->setHref($remotePageDebugUrl)
                ->setTitle($this->translate('module.remotePageDetail.openFrontendDebug') ?: 'Open frontend')
                ->setShowLabelText(true)
                ->setDataAttributes(['open-new-tab' => '1'])
                ->setIcon($this->iconFactory->getIcon('actions-view-page', IconSize::SMALL));

            $buttonBar->addButton($openFrontendButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);
        }
    }

    private function buildRemoteScreenshotProxyUrl(
        string $siteIdentifier,
        int $remotePageUid,
    ): string {
        return (string)$this->uriBuilder->buildUriFromRoute('web_a11y.remoteScreenshot', [
            'site' => $siteIdentifier,
            'remotePageUid' => $remotePageUid,
        ]);
    }
}
