<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Export;

use GuzzleHttp\Utils;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;

final class IssueExporter
{
    public function __construct(
        private readonly IssueRepository $issueRepository,
    ) {
    }

    public function toCsv(string $siteIdentifier, ?int $pageUid = null, bool $onlyOpen = true): string
    {
        $issues = $this->issueRepository->findForExport($siteIdentifier, $pageUid, $onlyOpen);

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
                $issue['page_uid'],
                $issue['page_title'] ?? '',
                $issue['rule_id'],
                Severity::fromInt((int)$issue['severity'])->label(),
                IssueStatus::fromInt((int)$issue['status'])->label(),
                $issue['message'],
                $issue['hint'] ?? '',
                $issue['source_table'],
                $issue['source_uid'],
                $issue['source_field'],
                $issue['source_lang_uid'],
                $issue['context_path'],
                $issue['context_snippet'] ?? '',
                $issue['crdate'] ? date('Y-m-d H:i:s', (int)$issue['crdate']) : '',
                $issue['tstamp'] ? date('Y-m-d H:i:s', (int)$issue['tstamp']) : '',
                $issue['ignored_by'] ?: '',
                $issue['ignored_reason'] ?? '',
            ], ';');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv ?: '';
    }

    public function toJson(string $siteIdentifier, ?int $pageUid = null, bool $onlyOpen = true): string
    {
        $issues = $this->issueRepository->findForExport($siteIdentifier, $pageUid, $onlyOpen);

        $data = array_map(static function (array $issue): array {
            return [
                'pageUid' => (int)$issue['page_uid'],
                'pageTitle' => $issue['page_title'] ?? '',
                'ruleId' => $issue['rule_id'],
                'severity' => Severity::fromInt((int)$issue['severity'])->label(),
                'severityCode' => (int)$issue['severity'],
                'status' => IssueStatus::fromInt((int)$issue['status'])->label(),
                'message' => $issue['message'],
                'hint' => $issue['hint'] ?? '',
                'sourceTable' => $issue['source_table'],
                'sourceUid' => (int)$issue['source_uid'],
                'sourceField' => $issue['source_field'],
                'language' => (int)$issue['source_lang_uid'],
                'contextPath' => $issue['context_path'],
                'contextSnippet' => $issue['context_snippet'] ?? '',
                'firstSeen' => $issue['crdate'] ? date('c', (int)$issue['crdate']) : null,
                'lastSeen' => $issue['tstamp'] ? date('c', (int)$issue['tstamp']) : null,
                'ignoredBy' => $issue['ignored_by'] ? (int)$issue['ignored_by'] : null,
                'ignoredReason' => $issue['ignored_reason'] ?: null,
            ];
        }, $issues);

        return Utils::jsonEncode([
            'siteIdentifier' => $siteIdentifier,
            'exportedAt' => date('c'),
            'filter' => $onlyOpen ? 'open' : 'all',
            'totalIssues' => count($data),
            'issues' => $data,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
