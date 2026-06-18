<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Dto;

final class CrawlerSummaryResponseDto
{
    /**
     * @param array<int, array<string, mixed>> $topPages
     * @param array<int, array<string, mixed>> $failedPages
     * @param array<int, array<string, mixed>> $topRules
     * @param array<int, array<string, mixed>> $countsByStatus
     * @param array<string, mixed> $score
     * @param array<string, mixed> $contrast
     * @param array<int, array<string, mixed>> $contrastDetails
     * @param array<string, mixed> $keyboardSummary
     * @param array<string, mixed> $structureSummary
     * @param array<string, mixed> $remediationSummary
     * @param array<string, mixed> $componentSummary
     * @param array<int, array<string, mixed>> $wcagSummary
     * @param array<int, array<string, mixed>> $priorityFixes
     * @param array<string, mixed> $reportSummary
     * @param array<int, array<string, mixed>> $manualReviewChecklist
     * @param array<string, mixed> $reportingGroups
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $jobId,
        public readonly string $siteId,
        public readonly string $startUrl,
        public readonly ?string $sitemapUrl,
        public readonly string $sourceType,
        public readonly string $status,
        public readonly string $contractVersion,
        public readonly array $features,
        public readonly int $pagesScanned,
        public readonly int $pagesFailed,
        public readonly int $issuesTotal,
        public readonly int $issuesNew,
        public readonly int $issuesResolved,
        public readonly array $topPages,
        public readonly array $failedPages,
        public readonly array $topRules,
        public readonly array $countsByStatus,
        public readonly array $score,
        public readonly array $contrast,
        public readonly array $contrastDetails,
        public readonly array $keyboardSummary,
        public readonly array $structureSummary,
        public readonly array $remediationSummary,
        public readonly array $componentSummary,
        public readonly array $wcagSummary,
        public readonly array $priorityFixes,
        public readonly array $reportSummary,
        public readonly array $manualReviewChecklist,
        public readonly array $reportingGroups,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];

        $sitemapUrl = isset($payload['sitemapUrl']) ? trim((string)$payload['sitemapUrl']) : (isset($payload['sitemap_url']) ? trim((string)$payload['sitemap_url']) : null);
        if ($sitemapUrl === '') {
            $sitemapUrl = null;
        }

        $reportSummary = is_array($payload['reportSummary'] ?? null)
            ? $payload['reportSummary']
            : (is_array($payload['report_summary'] ?? null) ? $payload['report_summary'] : []);
        $contrast = is_array($payload['contrast'] ?? null)
            ? $payload['contrast']
            : (is_array($reportSummary['contrast'] ?? null) ? $reportSummary['contrast'] : []);
        $wcagSummary = is_array($payload['wcagSummary'] ?? null)
            ? $payload['wcagSummary']
            : (is_array($payload['wcag_summary'] ?? null)
                ? $payload['wcag_summary']
                : (is_array($reportSummary['wcagSummary'] ?? null)
                    ? $reportSummary['wcagSummary']
                    : (is_array($reportSummary['wcag_summary'] ?? null) ? $reportSummary['wcag_summary'] : [])));
        $priorityFixes = is_array($payload['priorityFixes'] ?? null)
            ? $payload['priorityFixes']
            : (is_array($payload['priority_fixes'] ?? null)
                ? $payload['priority_fixes']
                : (is_array($reportSummary['priorityFixes'] ?? null)
                    ? $reportSummary['priorityFixes']
                    : (is_array($reportSummary['priority_fixes'] ?? null) ? $reportSummary['priority_fixes'] : [])));
        $manualReviewChecklist = is_array($payload['manualReviewChecklist'] ?? null)
            ? $payload['manualReviewChecklist']
            : (is_array($payload['manual_review_checklist'] ?? null)
                ? $payload['manual_review_checklist']
                : (is_array($reportSummary['manualReviewChecklist'] ?? null)
                    ? $reportSummary['manualReviewChecklist']
                    : (is_array($reportSummary['manual_review_checklist'] ?? null) ? $reportSummary['manual_review_checklist'] : [])));
        $reportingGroups = is_array($payload['reportingGroups'] ?? null)
            ? $payload['reportingGroups']
            : (is_array($payload['reporting_groups'] ?? null)
                ? $payload['reporting_groups']
                : (is_array($reportSummary['reportingGroups'] ?? null)
                    ? $reportSummary['reportingGroups']
                    : (is_array($reportSummary['reporting_groups'] ?? null) ? $reportSummary['reporting_groups'] : [])));
        $score = is_array($payload['score'] ?? null)
            ? $payload['score']
            : (is_array($payload['aqgScore'] ?? null)
                ? $payload['aqgScore']
                : (is_array($reportSummary['score'] ?? null) ? $reportSummary['score'] : []));
        $keyboardSummary = is_array($payload['keyboardSummary'] ?? null)
            ? $payload['keyboardSummary']
            : (is_array($payload['keyboard_summary'] ?? null)
                ? $payload['keyboard_summary']
                : (is_array($reportSummary['keyboardSummary'] ?? null)
                    ? $reportSummary['keyboardSummary']
                    : (is_array($reportSummary['keyboard_summary'] ?? null) ? $reportSummary['keyboard_summary'] : [])));
        $structureSummary = is_array($payload['structureSummary'] ?? null)
            ? $payload['structureSummary']
            : (is_array($payload['structure_summary'] ?? null)
                ? $payload['structure_summary']
                : (is_array($reportSummary['structureSummary'] ?? null)
                    ? $reportSummary['structureSummary']
                    : (is_array($reportSummary['structure_summary'] ?? null) ? $reportSummary['structure_summary'] : [])));
        $contrastDetails = is_array($payload['contrastDetails'] ?? null)
            ? $payload['contrastDetails']
            : (is_array($payload['contrast_details'] ?? null)
                ? $payload['contrast_details']
                : (is_array($contrast['contrastDetails'] ?? null)
                    ? $contrast['contrastDetails']
                    : (is_array($contrast['contrast_details'] ?? null) ? $contrast['contrast_details'] : [])));
        if ($contrastDetails !== [] && !array_is_list($contrastDetails)) {
            $contrastDetails = [$contrastDetails];
        }
        $remediationSummary = is_array($payload['remediationSummary'] ?? null)
            ? $payload['remediationSummary']
            : (is_array($payload['remediation_summary'] ?? null)
                ? $payload['remediation_summary']
                : (is_array($reportSummary['remediationSummary'] ?? null)
                    ? $reportSummary['remediationSummary']
                    : (is_array($reportSummary['remediation_summary'] ?? null) ? $reportSummary['remediation_summary'] : [])));
        $componentSummary = is_array($payload['componentSummary'] ?? null)
            ? $payload['componentSummary']
            : (is_array($payload['component_summary'] ?? null)
                ? $payload['component_summary']
                : (is_array($reportSummary['componentSummary'] ?? null)
                    ? $reportSummary['componentSummary']
                    : (is_array($reportSummary['component_summary'] ?? null) ? $reportSummary['component_summary'] : [])));

        return new self(
            success: isset($payload['jobId']) || isset($payload['job_id']),
            jobId: isset($payload['jobId']) ? (string)$payload['jobId'] : (isset($payload['job_id']) ? (string)$payload['job_id'] : null),
            siteId: (string)($payload['siteId'] ?? $payload['site_id'] ?? ''),
            startUrl: (string)($payload['startUrl'] ?? $payload['start_url'] ?? ''),
            sitemapUrl: $sitemapUrl,
            sourceType: (string)($payload['sourceType'] ?? $payload['source_type'] ?? 'crawl'),
            status: (string)($payload['status'] ?? ''),
            contractVersion: trim((string)($payload['contractVersion'] ?? $payload['contract_version'] ?? '')),
            features: is_array($payload['features'] ?? null) ? $payload['features'] : [],
            pagesScanned: (int)($payload['pagesScanned'] ?? $payload['pages_scanned'] ?? 0),
            pagesFailed: (int)($payload['pagesFailed'] ?? $payload['pages_failed'] ?? 0),
            issuesTotal: (int)($payload['issuesTotal'] ?? $payload['issues_total'] ?? ($contrast['issuesTotal'] ?? $contrast['issues_total'] ?? 0)),
            issuesNew: (int)($payload['issuesNew'] ?? $payload['issues_new'] ?? 0),
            issuesResolved: (int)($payload['issuesResolved'] ?? $payload['issues_resolved'] ?? 0),
            topPages: is_array($payload['topPages'] ?? null) ? array_values($payload['topPages']) : (is_array($payload['top_pages'] ?? null) ? array_values($payload['top_pages']) : (is_array($reportSummary['topPages'] ?? null) ? array_values($reportSummary['topPages']) : (is_array($reportSummary['top_pages'] ?? null) ? array_values($reportSummary['top_pages']) : []))),
            failedPages: is_array($payload['failedPages'] ?? null) ? array_values($payload['failedPages']) : (is_array($payload['failed_pages'] ?? null) ? array_values($payload['failed_pages']) : (is_array($reportSummary['failedPages'] ?? null) ? array_values($reportSummary['failedPages']) : (is_array($reportSummary['failed_pages'] ?? null) ? array_values($reportSummary['failed_pages']) : []))),
            topRules: is_array($payload['topRules'] ?? null) ? array_values($payload['topRules']) : (is_array($payload['top_rules'] ?? null) ? array_values($payload['top_rules']) : (is_array($reportSummary['topRules'] ?? null) ? array_values($reportSummary['topRules']) : (is_array($reportSummary['top_rules'] ?? null) ? array_values($reportSummary['top_rules']) : []))),
            countsByStatus: is_array($payload['countsByStatus'] ?? null) ? array_values($payload['countsByStatus']) : (is_array($payload['counts_by_status'] ?? null) ? array_values($payload['counts_by_status']) : (is_array($reportSummary['countsByStatus'] ?? null) ? array_values($reportSummary['countsByStatus']) : (is_array($reportSummary['counts_by_status'] ?? null) ? array_values($reportSummary['counts_by_status']) : []))),
            score: $score,
            contrast: $contrast,
            contrastDetails: array_values($contrastDetails),
            keyboardSummary: $keyboardSummary,
            structureSummary: $structureSummary,
            remediationSummary: $remediationSummary,
            componentSummary: $componentSummary,
            wcagSummary: array_values($wcagSummary),
            priorityFixes: array_values($priorityFixes),
            reportSummary: $reportSummary,
            manualReviewChecklist: array_values($manualReviewChecklist),
            reportingGroups: $reportingGroups,
            startedAt: isset($payload['startedAt']) ? (string)$payload['startedAt'] : (isset($payload['started_at']) ? (string)$payload['started_at'] : null),
            finishedAt: isset($payload['finishedAt']) ? (string)$payload['finishedAt'] : (isset($payload['finished_at']) ? (string)$payload['finished_at'] : null),
            errorCode: isset($error['code']) ? (string)$error['code'] : null,
            errorMessage: isset($error['message']) ? (string)$error['message'] : null,
        );
    }
}
