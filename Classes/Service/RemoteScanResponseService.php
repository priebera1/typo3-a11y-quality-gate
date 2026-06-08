<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;

final class RemoteScanResponseService
{
    /**
     * @param array<string, mixed> $activeScan
     * @return array<string, mixed>
     */
    public function buildActiveScanConflictPayload(array $activeScan, string $siteIdentifier): array
    {
        return [
            'success' => false,
            'error' => 'A remote scan is already running for this site.',
            'code' => 'remote_scan_already_active',
            'jobId' => (string)($activeScan['job_id'] ?? ''),
            'status' => (string)($activeScan['status'] ?? 'queued'),
            'siteIdentifier' => $siteIdentifier,
            'scanScope' => (string)($activeScan['scan_scope'] ?? 'site'),
            'pageUid' => (int)($activeScan['page_uid'] ?? 0),
            'pagesScanned' => (int)($activeScan['pages_scanned'] ?? 0),
            'pagesTotal' => (int)($activeScan['pages_total'] ?? 0),
            'languageUid' => (int)($activeScan['language_uid'] ?? -1),
        ];
    }

    /**
     * @param array<string, mixed>|null $existingScan
     * @return array<string, mixed>
     */
    public function buildResultsPayload(
        object $summaryResult,
        object $resultsResult,
        ?array $existingScan = null,
        int $pageUid = 0,
    ): array {
        return [
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
            'wcagSummary' => property_exists($summaryResult, 'wcagSummary') ? $summaryResult->wcagSummary : [],
            'priorityFixes' => property_exists($summaryResult, 'priorityFixes') ? $summaryResult->priorityFixes : [],
            'reportSummary' => property_exists($summaryResult, 'reportSummary') ? $summaryResult->reportSummary : [],
            'manualReviewChecklist' => property_exists($summaryResult, 'manualReviewChecklist') ? $summaryResult->manualReviewChecklist : [],
            'reportingGroups' => property_exists($summaryResult, 'reportingGroups') ? $summaryResult->reportingGroups : [],
            'pageUid' => $existingScan !== null
                ? (int)($existingScan['page_uid'] ?? 0)
                : $pageUid,
            'pages' => $resultsResult->pages,
            'languageUid' => $existingScan !== null
                ? (int)($existingScan['language_uid'] ?? -1)
                : -1,
        ];
    }

    public function resolveSourceType(string $sourceType): RemoteScanSourceType
    {
        return RemoteScanSourceType::tryFrom($sourceType)
            ?? RemoteScanSourceType::Crawl;
    }
}
