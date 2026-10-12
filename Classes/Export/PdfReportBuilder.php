<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Export;

use Priebera\A11yQualityGate\Service\IssueLocationService;
use Priebera\A11yQualityGate\Service\LocalIssueGuidanceService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Utility\BackendTimeUtility;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * The Content scan PDFs in the shared AQG print system (1.9.9, audit F2/F3):
 *
 * - Site report: page 1 answers "how bad is it and where do I start" (verdict, severity row, three rules to start
 *   with, the five pages with most issues); the complete pages and rules tables follow as the reference.
 * - Page report: grouped by rule like the Content detail view, fix written once, occurrences as numbered rows.
 *
 * PDFs stay English by design; rule titles and fixes come from the English rule guidance.
 */
final class PdfReportBuilder
{
    private const START_HERE_RULES = 3;
    private const SUMMARY_PAGES = 5;

    /** @var array<string, array<string, string>> */
    private array $guidanceByRule = [];

    public function __construct(
        private readonly IssueExporter $issueExporter,
        private readonly PdfGenerator $pdfGenerator,
        private readonly PdfTemplateRenderer $pdfTemplateRenderer,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly ?LocalIssueGuidanceService $localIssueGuidanceService = null,
        private readonly ?IssueLocationService $issueLocationService = null,
    ) {
    }

    public function buildOverviewPdf(
        string $siteIdentifier,
        string $status = 'open',
        string $severity = 'all',
        ?ServerRequestInterface $request = null,
    ): string {
        $issues = array_values($this->issueExporter->getFilteredIssues(
            siteIdentifier: $siteIdentifier,
            pageUid: null,
            status: $status,
            severity: $severity,
        ));

        $totals = LocalReportModel::totals($issues);
        $pageCounts = LocalReportModel::pageCounts($issues);
        $rules = LocalReportModel::rules($issues, $this->guidance());
        $pages = $this->withPagePaths(LocalReportModel::pages($issues));
        $siteLabel = $this->resolveSiteUrlLabel($siteIdentifier, null, $request);
        $startHere = array_slice($rules, 0, self::START_HERE_RULES);
        $startHereCount = array_sum(array_column($startHere, 'count'));

        $html = $this->pdfTemplateRenderer->render(
            templateName: 'Export/LocalOverviewPdf',
            variables: [
                'siteLabel' => $siteLabel,
                'siteHost' => $this->hostOf($siteLabel),
                'generatedAt' => $this->formatPdfDate(),
                'filterLabel' => $this->filterLabel($status, $severity),
                'verdict' => $this->verdict($totals, $pageCounts['total'], $status, false),
                'startHereNote' => $this->startHereNote(count($startHere), $startHereCount, $totals['total']),
                'severityRow' => LocalReportModel::severityRow($totals, $pageCounts),
                'startHere' => $startHere,
                'summaryPages' => array_slice($pages, 0, self::SUMMARY_PAGES),
                'morePagesLabel' => count($pages) > self::SUMMARY_PAGES || count($rules) > self::START_HERE_RULES
                    ? sprintf('All %d %s and all %d %s: page 2.', count($pages), count($pages) === 1 ? 'page' : 'pages', count($rules), count($rules) === 1 ? 'rule' : 'rules')
                    : '',
                'pages' => $pages,
                'pagesCount' => count($pages),
                'rules' => $rules,
                'rulesCount' => count($rules),
                'hasIssues' => $issues !== [],
            ],
            request: $request,
        );

        return $this->pdfGenerator->render(html: $html, title: 'AQG Content scan report');
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

        $issues = array_values($this->issueExporter->getFilteredIssues(
            siteIdentifier: $siteIdentifier,
            pageUid: $pageUid,
            status: $status,
            severity: $severity,
        ));

        $totals = LocalReportModel::totals($issues);
        $siteLabel = $this->resolveSiteUrlLabel($siteIdentifier, $pageUid, $request);
        $groups = LocalReportModel::ruleGroups($issues, $this->guidance(), $this->location());

        $html = $this->pdfTemplateRenderer->render(
            templateName: 'Export/LocalPagePdf',
            variables: [
                'pageTitle' => $pageTitle !== '' ? $pageTitle : 'Page ' . $pageUid,
                'pageUrl' => $this->buildPageUrlLabel($siteLabel, $pagePath, $pageUid),
                'pageUid' => $pageUid,
                'generatedAt' => $this->formatPdfDate(),
                'filterLabel' => $this->filterLabel($status, $severity),
                'verdict' => $this->verdict($totals, 1, $status, true),
                'severityRow' => LocalReportModel::severityRow($totals, null),
                'groups' => $groups,
                'groupsCount' => count($groups),
                'hasIssues' => $issues !== [],
            ],
            request: $request,
        );

        return $this->pdfGenerator->render(html: $html, title: 'AQG Content scan page report');
    }

    /**
     * @return \Closure(array<string, mixed>): array<string, string>
     */
    private function guidance(): \Closure
    {
        return function (array $issue): array {
            $ruleId = (string)($issue['rule_id'] ?? '');
            if (!isset($this->guidanceByRule[$ruleId])) {
                $presented = $this->localIssueGuidanceService?->present($issue, 'en') ?? [];
                $this->guidanceByRule[$ruleId] = [
                    // The translation-backed plain title ("Images without alternative text"), not the stored message.
                    'title' => trim((string)($presented['localizedTitle'] ?? '')),
                    'howToFix' => trim((string)($presented['howToFix'] ?? '')),
                    'whyItMatters' => trim((string)($presented['whyItMatters'] ?? '')),
                ];
            }

            return $this->guidanceByRule[$ruleId];
        };
    }

    /**
     * Where an occurrence is, in the words of the Content detail view: record title, content type and column, and a
     * technical line with field, table and uid.
     *
     * @return \Closure(array<string, mixed>): array{title: string, context: string, technical: string}
     */
    private function location(): \Closure
    {
        return function (array $issue): array {
            $table = trim((string)($issue['source_table'] ?? ''));
            $uid = (int)($issue['source_uid'] ?? 0);
            $field = trim((string)($issue['source_field'] ?? ''));
            $described = $this->issueLocationService?->describe($table, $uid, $field) ?? [];
            $fieldLabel = $field === '__rendered_html'
                ? 'Rendered page'
                : trim((string)($described['fieldLabel'] ?? $field));
            $title = trim((string)($described['title'] ?? ''));

            return [
                'title' => $title !== '' ? $title : trim($table . ' ' . ($uid > 0 ? (string)$uid : '')),
                'context' => trim((string)($described['context'] ?? '')),
                'technical' => implode(' · ', array_filter([
                    $fieldLabel,
                    $table !== '' && $uid > 0 ? $table . ' ' . $uid : '',
                    trim((string)($issue['context_path'] ?? '')),
                ], static fn (string $part): bool => $part !== '')),
            ];
        };
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    private function withPagePaths(array $pages): array
    {
        foreach ($pages as &$page) {
            $record = (int)$page['pageUid'] > 0
                ? (BackendUtility::getRecord('pages', (int)$page['pageUid'], 'slug') ?: [])
                : [];
            $slug = trim((string)($record['slug'] ?? ''));
            $page['path'] = $slug !== '' ? $slug : 'Page UID ' . $page['pageUid'];
        }
        unset($page);

        return $pages;
    }

    private function startHereNote(int $rules, int $issues, int $total): string
    {
        if ($rules === 0 || $total === 0) {
            return '';
        }

        return $rules === 1
            ? sprintf('This rule accounts for %d of %d issues.', $issues, $total)
            : sprintf('These %d rules account for %d of %d issues.', $rules, $issues, $total);
    }

    /**
     * @param array{critical: int, warning: int, info: int, needs_review: int, total: int} $totals
     */
    private function verdict(array $totals, int $pages, string $status, bool $onePage): string
    {
        $noun = match (strtolower(trim($status))) {
            'open', '' => ['open issue', 'open issues'],
            'ignored' => ['ignored issue', 'ignored issues'],
            'resolved' => ['resolved issue', 'resolved issues'],
            default => ['issue', 'issues'],
        };
        $total = (int)$totals['total'];
        if ($total === 0) {
            return 'No ' . $noun[1] . ' match this report.';
        }

        $sentence = $total . ' ' . ($total === 1 ? $noun[0] : $noun[1])
            . ($onePage ? ' on this page' : ' on ' . $pages . ' ' . ($pages === 1 ? 'page' : 'pages'));
        $critical = (int)$totals['critical'];

        return $sentence . ($critical > 0 ? '; ' . $critical . ' ' . ($critical === 1 ? 'is' : 'are') . ' critical.' : '.');
    }

    private function filterLabel(string $status, string $severity): string
    {
        $label = static fn (string $value): string => match (strtolower(trim($value))) {
            '', 'all' => 'All',
            'needs_review' => 'Needs review',
            default => ucfirst(strtolower(str_replace('_', ' ', trim($value)))),
        };

        return 'Status ' . $label($status) . ' · Severity ' . $label($severity);
    }

    private function resolveSiteUrlLabel(string $siteIdentifier, ?int $pageUid, ?ServerRequestInterface $request): string
    {
        $site = null;

        if ($request !== null) {
            $site = $this->siteResolutionService->resolveSiteForBackendRequest($request, $pageUid ?? 0);
        }

        if ($site === null && $siteIdentifier !== '') {
            $site = $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier);
        }

        if ($site !== null) {
            $base = rtrim((string)$site->getBase(), '/');
            if ($base !== '') {
                return $base;
            }
        }

        return $siteIdentifier !== '' ? $siteIdentifier : 'All sites';
    }

    private function hostOf(string $siteLabel): string
    {
        $host = (string)parse_url($siteLabel, PHP_URL_HOST);

        return $host !== '' ? $host : $siteLabel;
    }

    private function buildPageUrlLabel(string $siteLabel, string $pagePath, int $pageUid): string
    {
        $pagePath = trim($pagePath);
        if ($pagePath === '') {
            return 'Page UID ' . $pageUid;
        }

        if (preg_match('#^https?://#i', $pagePath) === 1 || preg_match('#^https?://#i', $siteLabel) !== 1) {
            return $pagePath;
        }

        return rtrim($siteLabel, '/') . '/' . ltrim($pagePath, '/');
    }

    private function formatPdfDate(?int $timestamp = null): string
    {
        return BackendTimeUtility::formatDateTime($timestamp ?? time(), 'j M Y, H:i T');
    }
}
