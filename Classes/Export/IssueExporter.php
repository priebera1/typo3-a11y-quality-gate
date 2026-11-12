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
        int $languageUid = -1,
        string $readablePagesClause = '',
    ): string {
        $issues = $this->getFilteredIssues($siteIdentifier, $pageUid, $status, $severity, $languageUid, $readablePagesClause);

        $output = fopen('php://memory', 'r+b');
        if ($output === false) {
            return '';
        }

        fputcsv($output, CsvValueSanitizer::sanitizeRow([
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
        ]), ';', '"', '\\');

        foreach ($issues as $issue) {
            fputcsv($output, CsvValueSanitizer::sanitizeRow([
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
                ...self::ignoreColumns($issue),
            ]), ';', '"', '\\');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv ?: '';
    }

    /**
     * @param int $languageUid the content language of the findings; -1 exports every language
     * @return array<int, array<string, mixed>>
     */
    public function getFilteredIssues(
        string $siteIdentifier,
        ?int $pageUid,
        string $status,
        string $severity,
        int $languageUid = -1,
        string $readablePagesClause = '',
    ): array {
        $normalizedStatus = FilterValueUtility::normalizeStatus($status);
        $normalizedSeverity = FilterValueUtility::normalizeSeverity($severity);

        $issues = $this->issueRepository->findForExport(
            siteIdentifier: $siteIdentifier,
            pageUid: $pageUid,
            onlyOpen: $normalizedStatus === 'open',
            languageUid: $languageUid,
            readablePagesClause: $readablePagesClause,
        );

        $issues = IssueFilterUtility::filterByStatus($issues, $normalizedStatus);
        $issues = IssueFilterUtility::filterBySeverity($issues, $normalizedSeverity);

        return $issues;
    }

    /**
     * Who ignored a finding and why, only while it is ignored: a finding whose ignore expired or was lifted is open
     * again, and its old ignore data would read as current. The person is named (display name, else user name) — a bare
     * backend user uid means nothing to the reader of a report (product acceptance).
     *
     * @param array<string, mixed> $issue
     * @return array{0:string, 1:string}
     */
    private static function ignoreColumns(array $issue): array
    {
        if ((int)($issue['status'] ?? 0) !== IssueStatus::Ignored->value) {
            return ['', ''];
        }
        $by = trim((string)($issue['ignored_by_name'] ?? ''));
        if ($by === '') {
            $by = trim((string)($issue['ignored_by_username'] ?? ''));
        }
        if ($by === '' && (int)($issue['ignored_by'] ?? 0) > 0) {
            $by = 'Backend user #' . (int)$issue['ignored_by'];
        }

        return [$by, (string)($issue['ignored_reason'] ?? '')];
    }
}
