<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Export;

use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Utility\FilterValueUtility;
use Priebera\A11yQualityGate\Utility\IssueFilterUtility;

final class IssueExporter
{
    public function __construct(
        private readonly IssueRepository $issueRepository,
    ) {
    }

    public function toCsv(
        string $siteIdentifier,
        ?int $pageUid = null,
        string $status = 'open',
        string $severity = 'all',
    ): string {
        $issues = $this->getFilteredIssues($siteIdentifier, $pageUid, $status, $severity);

        $output = fopen('php://memory', 'r+b');
        if ($output === false) {
            return '';
        }

        fputcsv($output, [
            'Page UID',
            'Page Title',
            'Rule ID',
            'Severity',
            'Status',
            'Message',
            'Hint',
            'Source Table',
            'Source UID',
            'Source Field',
            'Language',
            'Context Path',
            'Context Snippet',
            'First Seen',
            'Last Seen',
            'Ignored By',
            'Ignored Reason',
        ], ';');

        foreach ($issues as $issue) {
            fputcsv($output, [
                $issue['page_uid'] ?? 0,
                $issue['page_title'] ?? '',
                $issue['rule_id'] ?? '',
                Severity::fromInt((int)($issue['severity'] ?? 0))->label(),
                IssueStatus::fromInt((int)($issue['status'] ?? 0))->label(),
                $issue['message'] ?? '',
                $issue['hint'] ?? '',
                $issue['source_table'] ?? '',
                $issue['source_uid'] ?? '',
                $issue['source_field'] ?? '',
                $issue['source_lang_uid'] ?? '',
                $issue['context_path'] ?? '',
                $issue['context_snippet'] ?? '',
                !empty($issue['crdate']) ? date('Y-m-d H:i:s', (int)$issue['crdate']) : '',
                !empty($issue['tstamp']) ? date('Y-m-d H:i:s', (int)$issue['tstamp']) : '',
                $issue['ignored_by'] ?: '',
                $issue['ignored_reason'] ?? '',
            ], ';');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv ?: '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFilteredIssues(
        string $siteIdentifier,
        ?int $pageUid,
        string $status,
        string $severity,
    ): array {
        $normalizedStatus = FilterValueUtility::normalizeStatus($status);
        $normalizedSeverity = FilterValueUtility::normalizeSeverity($severity);

        $issues = $this->issueRepository->findForExport(
            siteIdentifier: $siteIdentifier,
            pageUid: $pageUid,
            onlyOpen: $normalizedStatus === 'open'
        );

        $issues = IssueFilterUtility::filterByStatus($issues, $normalizedStatus);
        $issues = IssueFilterUtility::filterBySeverity($issues, $normalizedSeverity);

        return $issues;
    }
}