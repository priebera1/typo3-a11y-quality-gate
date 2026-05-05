<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Export;

use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;

final class PdfReportBuilder
{
    public function __construct(
        private readonly IssueExporter $issueExporter,
        private readonly PdfGenerator $pdfGenerator,
        private readonly PdfTemplateRenderer $pdfTemplateRenderer,
    ) {
    }

    public function buildOverviewPdf(
        string $siteIdentifier,
        string $status = 'open',
        string $severity = 'all',
        ?ServerRequestInterface $request = null,
    ): string {
        $issues = $this->issueExporter->getFilteredIssues(
            siteIdentifier: $siteIdentifier,
            pageUid: null,
            status: $status,
            severity: $severity,
        );

        $totals = $this->buildTotalsFromIssues($issues);
        $topRules = $this->buildTopRules($issues);
        $topPages = $this->buildTopPages($issues);

        $html = $this->pdfTemplateRenderer->render(
            templateName: 'Export/LocalOverviewPdf',
            variables: [
                'title' => 'Accessibility report',
                'subtitle' => $siteIdentifier !== '' ? $siteIdentifier : 'All sites',
                'generatedAt' => date('Y-m-d H:i'),
                'siteIdentifier' => $siteIdentifier !== '' ? $siteIdentifier : 'All sites',
                'status' => $status,
                'severity' => $severity,
                'totals' => $totals,
                'topPages' => $topPages,
                'topRules' => $topRules,
            ],
            request: $request,
        );

        return $this->pdfGenerator->render(
            html: $html,
            title: 'AQG Overview Report',
        );
    }

    public function buildPagePdf(
        string $siteIdentifier,
        int $pageUid,
        string $status = 'open',
        string $severity = 'all',
        ?ServerRequestInterface $request = null,
    ): string {
        $pageRecord = $pageUid > 0
            ? (BackendUtility::getRecord('pages', $pageUid, 'uid,title,slug') ?: [])
            : [];

        $pageTitle = trim((string)($pageRecord['title'] ?? ''));
        $pagePath = trim((string)($pageRecord['slug'] ?? ''));

        $issues = $this->issueExporter->getFilteredIssues(
            siteIdentifier: $siteIdentifier,
            pageUid: $pageUid,
            status: $status,
            severity: $severity,
        );

        $totals = $this->buildTotalsFromIssues($issues);
        $preparedIssues = $this->preparePageIssues($issues);

        $html = $this->pdfTemplateRenderer->render(
            templateName: 'Export/LocalPagePdf',
            variables: [
                'title' => $pageTitle !== '' ? $pageTitle : ('Page ' . $pageUid),
                'subtitle' => $pagePath !== '' ? $pagePath : ('Page UID ' . $pageUid),
                'generatedAt' => date('Y-m-d H:i'),
                'siteIdentifier' => $siteIdentifier,
                'pageUid' => $pageUid,
                'pageTitle' => $pageTitle,
                'pagePath' => $pagePath,
                'status' => $status,
                'severity' => $severity,
                'totals' => $totals,
                'issues' => $preparedIssues,
            ],
            request: $request,
        );

        return $this->pdfGenerator->render(
            html: $html,
            title: 'AQG Page Report',
        );
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array{critical:int,warning:int,info:int,total:int}
     */
    private function buildTotalsFromIssues(array $issues): array
    {
        $totals = [
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
            'total' => 0,
        ];

        foreach ($issues as $issue) {
            $severity = Severity::fromInt((int)($issue['severity'] ?? 0));

            $key = match ($severity) {
                Severity::Critical => 'critical',
                Severity::Warning => 'warning',
                Severity::Info => 'info',
            };

            $totals[$key]++;
            $totals['total']++;
        }

        return $totals;
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, array{ruleId:string,count:int}>
     */
    private function buildTopRules(array $issues): array
    {
        $rules = [];

        foreach ($issues as $issue) {
            $ruleId = trim((string)($issue['rule_id'] ?? ''));
            if ($ruleId === '') {
                continue;
            }

            $rules[$ruleId] = ($rules[$ruleId] ?? 0) + 1;
        }

        arsort($rules);

        $result = [];
        foreach (array_slice($rules, 0, 10, true) as $ruleId => $count) {
            $result[] = [
                'ruleId' => $ruleId,
                'count' => $count,
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, array{pageUid:int,pageTitle:string,critical:int,warning:int,info:int,total:int}>
     */
    private function buildTopPages(array $issues): array
    {
        $pages = [];

        foreach ($issues as $issue) {
            $pageUid = (int)($issue['page_uid'] ?? 0);
            $pageKey = (string)$pageUid;

            if (!isset($pages[$pageKey])) {
                $pages[$pageKey] = [
                    'pageUid' => $pageUid,
                    'pageTitle' => (string)($issue['page_title'] ?? ''),
                    'critical' => 0,
                    'warning' => 0,
                    'info' => 0,
                    'total' => 0,
                ];
            }

            $severity = Severity::fromInt((int)($issue['severity'] ?? 0));
            $severityKey = match ($severity) {
                Severity::Critical => 'critical',
                Severity::Warning => 'warning',
                Severity::Info => 'info',
            };

            $pages[$pageKey][$severityKey]++;
            $pages[$pageKey]['total']++;
        }

        $pages = array_values($pages);

        usort(
            $pages,
            static fn(array $a, array $b): int =>
                [$b['total'], $b['critical'], $b['warning'], $a['pageTitle']]
                <=>
                [$a['total'], $a['critical'], $a['warning'], $b['pageTitle']]
        );

        return array_slice($pages, 0, 10);
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, array<string, mixed>>
     */
    private function preparePageIssues(array $issues): array
    {
        $prepared = [];

        foreach ($issues as $issue) {
            $severityEnum = Severity::fromInt((int)($issue['severity'] ?? 0));
            $statusEnum = IssueStatus::fromInt((int)($issue['status'] ?? 0));

            $prepared[] = [
                'message' => (string)($issue['message'] ?? ''),
                'hint' => (string)($issue['hint'] ?? ''),
                'rule_id' => (string)($issue['rule_id'] ?? ''),
                'source_table' => (string)($issue['source_table'] ?? ''),
                'source_uid' => (int)($issue['source_uid'] ?? 0),
                'source_field' => (string)($issue['source_field'] ?? ''),
                'context_path' => (string)($issue['context_path'] ?? ''),
                'context_snippet' => (string)($issue['context_snippet'] ?? ''),
                'severityLabel' => $severityEnum->label(),
                'statusLabel' => $statusEnum->label(),
                'severityKey' => match ($severityEnum) {
                    Severity::Critical => 'critical',
                    Severity::Warning => 'warning',
                    Severity::Info => 'info',
                },
                'statusKey' => match ($statusEnum) {
                    IssueStatus::Open => 'open',
                    IssueStatus::Resolved => 'resolved',
                    IssueStatus::Ignored => 'ignored',
                },
            ];
        }

        return $prepared;
    }
}