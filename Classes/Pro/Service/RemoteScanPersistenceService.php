<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueNodeRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Service\DateTimeService;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class RemoteScanPersistenceService
{
    public function __construct(
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly RemoteIssueRepository $remoteIssueRepository,
        private readonly RemoteIssueNodeRepository $remoteIssueNodeRepository,
        private readonly DateTimeService $dateTimeService,
    ) {
    }

    public function persistResults(
        string $siteIdentifier,
        string $jobId,
        RemoteScanSourceType $sourceType,
        string $startUrl,
        ?string $sitemapUrl,
        array $resultsData,
    ): int {
        $logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);

        $remoteScanUid = $this->remoteScanRepository->upsertScan(
            siteIdentifier: $siteIdentifier,
            jobId: $jobId,
            sourceType: $sourceType,
            startUrl: $startUrl,
            sitemapUrl: $sitemapUrl,
            status: (string)($resultsData['status'] ?? 'unknown'),
            pagesScanned: (int)($resultsData['pagesScanned'] ?? 0),
            pagesFailed: (int)($resultsData['pagesFailed'] ?? 0),
            issuesTotal: (int)($resultsData['issuesTotal'] ?? 0),
            issuesNew: (int)($resultsData['issuesNew'] ?? 0),
            issuesResolved: (int)($resultsData['issuesResolved'] ?? 0),
            startedAt: $this->dateTimeService->toTimestampOrZero($resultsData['startedAt'] ?? null),
            finishedAt: $this->dateTimeService->toTimestampOrZero($resultsData['finishedAt'] ?? null),
            pagesTotal: (int)($resultsData['pagesTotal'] ?? 0),
            scanScope: $sourceType === RemoteScanSourceType::SinglePage ? 'page' : 'site',
            pageUid: (int)($resultsData['pageUid'] ?? 0),
            lastSyncedAt: time(),
            persistedAt: time(),
            syncError: '',
            languageUid: (int)($resultsData['languageUid'] ?? $resultsData['languageId'] ?? -1),
        );

        $existingIssueUids = $this->remoteIssueRepository->findUidsByRemoteScan($remoteScanUid);
        $this->remoteIssueNodeRepository->deleteByRemoteIssueUids($existingIssueUids);
        $this->remoteIssueRepository->deleteByRemoteScan($remoteScanUid);
        $this->remoteScanRepository->deletePagesByRemoteScan($remoteScanUid);

        $pages = is_array($resultsData['pages'] ?? null) ? $resultsData['pages'] : [];

        $firstPage = isset($pages[0]) && is_array($pages[0]) ? $pages[0] : null;
        $logger->info('AQG remote persist: raw results overview', [
            'jobId' => $jobId,
            'remoteScanUid' => $remoteScanUid,
            'pagesCount' => count($pages),
            'firstPageKeys' => is_array($firstPage) ? array_keys($firstPage) : [],
            'firstPageIssuesCount' => is_array($firstPage['issues'] ?? null) ? count($firstPage['issues']) : 0,
            'firstPageViolationsCount' => is_array($firstPage['violations'] ?? null) ? count($firstPage['violations']) : 0,
            'firstPageAxeViolationsCount' => is_array($firstPage['axeViolations'] ?? null) ? count($firstPage['axeViolations']) : 0,
        ]);

        $normalizedPages = array_map(
            fn (array $page): array => $this->normalizePagePayload($page),
            array_filter($pages, 'is_array')
        );

        $pageUidByUrl = $this->remoteScanRepository->saveScanPages(
            remoteScanUid: $remoteScanUid,
            sourceType: $sourceType,
            pages: $normalizedPages,
        );

        $savedIssues = 0;
        $savedNodes = 0;

        foreach ($normalizedPages as $page) {
            $url = trim((string)($page['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $remoteScanPageUid = (int)($pageUidByUrl[$url] ?? 0);
            if ($remoteScanPageUid <= 0) {
                continue;
            }

            $issues = is_array($page['issues'] ?? null) ? $page['issues'] : [];

            foreach ($issues as $issue) {
                if (!is_array($issue)) {
                    continue;
                }

                $remoteIssueUid = $this->remoteIssueRepository->saveIssue(
                    remoteScanUid: $remoteScanUid,
                    remoteScanPageUid: $remoteScanPageUid,
                    issue: $issue,
                );
                $savedIssues++;

                $nodes = is_array($issue['nodes'] ?? null) ? $issue['nodes'] : [];

                foreach ($nodes as $node) {
                    if (!is_array($node)) {
                        continue;
                    }

                    $this->remoteIssueNodeRepository->saveNode(
                        remoteIssueUid: $remoteIssueUid,
                        node: $node,
                    );
                    $savedNodes++;
                }
            }
        }

        $logger->info('AQG remote persist: save result', [
            'jobId' => $jobId,
            'remoteScanUid' => $remoteScanUid,
            'summaryIssuesTotal' => (int)($resultsData['issuesTotal'] ?? 0),
            'savedIssues' => $savedIssues,
            'savedNodes' => $savedNodes,
        ]);

        return $remoteScanUid;
    }

    public function recoverCompletedScan(
        string $siteIdentifier,
        string $jobId,
        RemoteScanSourceType $sourceType,
        string $startUrl,
        ?string $sitemapUrl,
        int $pageUid = 0,
        callable $summaryFetcher = null,
        callable $resultsFetcher = null,
    ): int {
        if ($summaryFetcher === null || $resultsFetcher === null) {
            throw new \InvalidArgumentException('Missing summary/results fetchers for recovery.');
        }

        $summaryResult = $summaryFetcher();
        $resultsResult = $resultsFetcher();

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
            'contrast' => property_exists($summaryResult, 'contrast') ? $summaryResult->contrast : [],
            'contrastDetails' => property_exists($summaryResult, 'contrastDetails') ? $summaryResult->contrastDetails : [],
            'score' => property_exists($summaryResult, 'score') ? $summaryResult->score : [],
            'keyboardSummary' => property_exists($summaryResult, 'keyboardSummary') ? $summaryResult->keyboardSummary : [],
            'structureSummary' => property_exists($summaryResult, 'structureSummary') ? $summaryResult->structureSummary : [],
            'remediationSummary' => property_exists($summaryResult, 'remediationSummary') ? $summaryResult->remediationSummary : [],
            'componentSummary' => property_exists($summaryResult, 'componentSummary') ? $summaryResult->componentSummary : [],
            'wcagSummary' => $summaryResult->wcagSummary,
            'priorityFixes' => $summaryResult->priorityFixes,
            'reportSummary' => property_exists($summaryResult, 'reportSummary') ? $summaryResult->reportSummary : [],
            'manualReviewChecklist' => property_exists($summaryResult, 'manualReviewChecklist') ? $summaryResult->manualReviewChecklist : [],
            'reportingGroups' => property_exists($summaryResult, 'reportingGroups') ? $summaryResult->reportingGroups : [],
            'pageUid' => $pageUid,
            'pages' => $resultsResult->pages,
            'languageUid' => -1,
        ];

        return $this->persistResults(
            siteIdentifier: $siteIdentifier,
            jobId: $jobId,
            sourceType: $sourceType,
            startUrl: $startUrl,
            sitemapUrl: $sitemapUrl,
            resultsData: $resultsPayload,
        );
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function normalizePagePayload(array $page): array
    {
        $resolvedUrl = trim((string)(
            $page['url']
            ?? $page['finalUrl']
            ?? $page['requestedUrl']
            ?? ''
        ));

        $issues = [];
        if (is_array($page['issues'] ?? null) && $page['issues'] !== []) {
            $issues = $page['issues'];
        } elseif (is_array($page['violations'] ?? null) && $page['violations'] !== []) {
            $issues = $page['violations'];
        } elseif (is_array($page['axeViolations'] ?? null) && $page['axeViolations'] !== []) {
            $issues = $page['axeViolations'];
        }

        $httpStatus = (int)($page['httpStatus'] ?? $page['http_status'] ?? 0);
        $failureReason = trim((string)($page['failureReason'] ?? $page['failure_reason'] ?? ''));
        $isFailed = $failureReason !== '' || $httpStatus >= 400;
        $keyboardSummary = is_array($page['keyboardSummary'] ?? null)
            ? $page['keyboardSummary']
            : (is_array($page['keyboard_summary'] ?? null) ? $page['keyboard_summary'] : []);
        $remediationSummary = is_array($page['remediationSummary'] ?? null)
            ? $page['remediationSummary']
            : (is_array($page['remediation_summary'] ?? null)
                ? $page['remediation_summary']
                : (is_array($page['remediation'] ?? null) ? $page['remediation'] : []));
        $pageRecommendation = is_array($page['pageRecommendation'] ?? null)
            ? $page['pageRecommendation']
            : (is_array($page['page_recommendation'] ?? null)
                ? $page['page_recommendation']
                : (is_array($page['recommendation'] ?? null) ? $page['recommendation'] : []));

        $page['url'] = $resolvedUrl;
        $page['issues'] = $issues;
        $page['keyboardSummary'] = $keyboardSummary;
        $page['remediationSummary'] = $remediationSummary;
        $page['pageRecommendation'] = $pageRecommendation;
        $page['isFailed'] = $isFailed ? 1 : 0;
        $page['failureReason'] = $failureReason;
        $page['issuesCount'] = isset($page['issuesCount'])
            ? (int)$page['issuesCount']
            : count($issues);

        return $page;
    }
}
