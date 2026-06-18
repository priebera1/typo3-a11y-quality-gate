<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Export;

use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Psr\Http\Message\ServerRequestInterface;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class PdfReportBuilder
{
    public function __construct(
        private readonly IssueExporter $issueExporter,
        private readonly PdfGenerator $pdfGenerator,
        private readonly PdfTemplateRenderer $pdfTemplateRenderer,
        private readonly SiteResolutionService $siteResolutionService,
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
        $pageCounts = $this->buildPageCountsBySeverity($issues);
        $topRules = $this->buildTopRules($issues);
        $siteLabel = $this->resolveSiteUrlLabel($siteIdentifier, null, $request);
        $topPages = $this->buildTopPages($issues, $siteLabel);
        $affectedPagesCount = $pageCounts['total'];

        $html = $this->pdfTemplateRenderer->render(
            templateName: 'Export/LocalOverviewPdf',
            variables: [
                'title' => 'Accessibility report',
                'subtitle' => $siteLabel,
                'generatedAt' => $this->formatPdfDate(),
                'pageAlias' => 'Page {PAGENO} of {nbpg}',
                'siteIdentifier' => $siteIdentifier !== '' ? $siteIdentifier : 'All sites',
                'siteLabel' => $siteLabel,
                'scopeLabel' => $affectedPagesCount > 0
                    ? 'Local content scan · ' . $affectedPagesCount . ' affected ' . $this->pluralize('page', $affectedPagesCount)
                    : 'Local content scan',
                'status' => $status,
                'severity' => $severity,
                'statusLabel' => $this->normalizeFilterLabel($status),
                'severityLabel' => $this->normalizeFilterLabel($severity),
                'totals' => $totals,
                'criticalFoot' => $totals['critical'] > 0
                    ? 'across ' . $pageCounts['critical'] . ' ' . $this->pluralize('page', $pageCounts['critical'])
                    : 'no open critical',
                'warningFoot' => $totals['warning'] > 0
                    ? 'across ' . $pageCounts['warning'] . ' ' . $this->pluralize('page', $pageCounts['warning'])
                    : 'no open warnings',
                'infoFoot' => $totals['info'] > 0
                    ? 'across ' . $pageCounts['info'] . ' ' . $this->pluralize('page', $pageCounts['info'])
                    : 'no info findings',
                'needsReviewFoot' => $totals['needs_review'] > 0
                    ? 'across ' . $pageCounts['needs_review'] . ' ' . $this->pluralize('page', $pageCounts['needs_review'])
                    : 'no review items',
                'totalFoot' => $totals['total'] > 0
                    ? 'across ' . $affectedPagesCount . ' ' . $this->pluralize('page', $affectedPagesCount)
                    : 'no matching issues',
                'affectedPagesCount' => $affectedPagesCount,
                'topPagesShown' => count($topPages),
                'topRulesShown' => count($topRules),
                'topPages' => $topPages,
                'topRules' => $topRules,
            ],
            request: $request,
        );

        return $this->pdfGenerator->render(
            html: $html,
            title: 'AQG Overview Report',
            css: $this->readLocalPdfCss(),
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
        $siteLabel = $this->resolveSiteUrlLabel($siteIdentifier, $pageUid, $request);
        $pagePathLabel = $this->buildPageUrlLabel($siteLabel, $pagePath, $pageUid);
        $generatedAt = $this->formatPdfDate();
        $issuesShown = count($preparedIssues);

        $html = $this->pdfTemplateRenderer->render(
            templateName: 'Export/LocalPagePdf',
            variables: [
                'title' => $pageTitle !== '' ? $pageTitle : ('Page ' . $pageUid),
                'subtitle' => $pagePathLabel,
                'generatedAt' => $generatedAt,
                'pageAlias' => 'Page {PAGENO} of {nbpg}',
                'siteIdentifier' => $siteIdentifier,
                'siteLabel' => $siteLabel,
                'pageUid' => $pageUid,
                'pageTitle' => $pageTitle,
                'pagePath' => $pagePath,
                'pagePathLabel' => $pagePathLabel,
                'status' => $status,
                'severity' => $severity,
                'statusLabel' => $this->normalizeFilterLabel($status),
                'severityLabel' => $this->normalizeFilterLabel($severity),
                'lastScanLabel' => $generatedAt,
                'totals' => $totals,
                'criticalFoot' => $totals['critical'] > 0 ? $totals['critical'] . ' on this page' : 'none matching',
                'warningFoot' => $totals['warning'] > 0 ? $totals['warning'] . ' on this page' : 'none matching',
                'infoFoot' => $totals['info'] > 0 ? $totals['info'] . ' on this page' : 'none matching',
                'needsReviewFoot' => $totals['needs_review'] > 0 ? $totals['needs_review'] . ' on this page' : 'none matching',
                'totalFoot' => $totals['total'] > 0 ? $totals['total'] . ' issues on this page' : 'filtered to 0',
                'totalCardFoot' => $totals['total'] > 0 ? 'matching filters' : 'no matching issues',
                'criticalZeroClass' => $totals['critical'] === 0 ? ' is-zero' : '',
                'warningZeroClass' => $totals['warning'] === 0 ? ' is-zero' : '',
                'infoZeroClass' => $totals['info'] === 0 ? ' is-zero' : '',
                'needsReviewZeroClass' => $totals['needs_review'] === 0 ? ' is-zero' : '',
                'totalZeroClass' => $totals['total'] === 0 ? ' is-zero' : '',
                'issues' => $preparedIssues,
                'hasIssues' => $preparedIssues !== [],
                'issuesShownLabel' => $issuesShown > 0 ? 'showing ' . $issuesShown . ' of ' . $totals['total'] : '0 matching issues',
            ],
            request: $request,
        );

        return $this->pdfGenerator->render(
            html: $html,
            title: 'AQG Page Report',
            css: $this->readLocalPdfCss(),
        );
    }

    private function readLocalPdfCss(): string
    {
        $path = GeneralUtility::getFileAbsFileName(
            'EXT:a11y_quality_gate/Resources/Public/Css/Pdf/local.css'
        );

        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return '';
        }

        $css = file_get_contents($path);

        return is_string($css) ? $css : '';
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array{critical:int,warning:int,info:int,needs_review:int,total:int}
     */
    private function buildTotalsFromIssues(array $issues): array
    {
        $totals = [
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
            'needs_review' => 0,
            'total' => 0,
        ];

        foreach ($issues as $issue) {
            $severity = Severity::fromInt((int)($issue['severity'] ?? 0));

            $key = match ($severity) {
                Severity::Critical => 'critical',
                Severity::Warning => 'warning',
                Severity::Info => 'info',
                Severity::NeedsReview => 'needs_review',
            };

            $totals[$key]++;
            $totals['total']++;
        }

        return $totals;
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array{critical:int,warning:int,info:int,needs_review:int,total:int}
     */
    private function buildPageCountsBySeverity(array $issues): array
    {
        $pages = [
            'critical' => [],
            'warning' => [],
            'info' => [],
            'needs_review' => [],
            'total' => [],
        ];

        foreach ($issues as $issue) {
            $pageUid = (int)($issue['page_uid'] ?? 0);
            $pageKey = (string)$pageUid;
            $severity = Severity::fromInt((int)($issue['severity'] ?? 0));
            $severityKey = match ($severity) {
                Severity::Critical => 'critical',
                Severity::Warning => 'warning',
                Severity::Info => 'info',
                Severity::NeedsReview => 'needs_review',
            };

            $pages[$severityKey][$pageKey] = true;
            $pages['total'][$pageKey] = true;
        }

        return [
            'critical' => count($pages['critical']),
            'warning' => count($pages['warning']),
            'info' => count($pages['info']),
            'needs_review' => count($pages['needs_review']),
            'total' => count($pages['total']),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, array{ruleId:string,title:string,count:int,tone:string}>
     */
    private function buildTopRules(array $issues): array
    {
        $rules = [];

        foreach ($issues as $issue) {
            $ruleId = trim((string)($issue['rule_id'] ?? ''));
            if ($ruleId === '') {
                continue;
            }

            $severity = Severity::fromInt((int)($issue['severity'] ?? 0));
            $tone = match ($severity) {
                Severity::Critical => 'critical',
                Severity::Warning => 'warning',
                Severity::Info => 'info',
                Severity::NeedsReview => 'needs_review',
            };

            if (!isset($rules[$ruleId])) {
                $rules[$ruleId] = [
                    'ruleId' => $ruleId,
                    'title' => $this->humanizeRuleId($ruleId),
                    'count' => 0,
                    'tone' => $tone,
                    'weight' => $this->severityWeight($tone),
                ];
            }

            $rules[$ruleId]['count']++;
            if ($this->severityWeight($tone) > (int)$rules[$ruleId]['weight']) {
                $rules[$ruleId]['tone'] = $tone;
                $rules[$ruleId]['weight'] = $this->severityWeight($tone);
            }
        }

        uasort(
            $rules,
            static fn(array $a, array $b): int => [$b['count'], $b['weight'], $a['ruleId']] <=> [$a['count'], $a['weight'], $b['ruleId']]
        );

        return array_map(
            static fn(array $rule): array => [
                'ruleId' => (string)$rule['ruleId'],
                'title' => (string)$rule['title'],
                'count' => (int)$rule['count'],
                'tone' => (string)$rule['tone'],
            ],
            array_slice(array_values($rules), 0, 10)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, array{pageUid:int,pageTitle:string,pageSub:string,critical:int,warning:int,info:int,needs_review:int,total:int,criticalTone:string,warningTone:string,infoTone:string,needsReviewTone:string}>
     */
    private function buildTopPages(array $issues, string $siteLabel): array
    {
        $pages = [];

        foreach ($issues as $issue) {
            $pageUid = (int)($issue['page_uid'] ?? 0);
            $pageKey = (string)$pageUid;

            if (!isset($pages[$pageKey])) {
                $pageTitle = trim((string)($issue['page_title'] ?? ''));
                $pages[$pageKey] = [
                    'pageUid' => $pageUid,
                    'pageTitle' => $pageTitle !== '' ? $pageTitle : 'Page ' . $pageUid,
                    'pageSub' => $this->buildOverviewPageSub($pageUid, $siteLabel),
                    'critical' => 0,
                    'warning' => 0,
                    'info' => 0,
                    'needs_review' => 0,
                    'total' => 0,
                ];
            }

            $severity = Severity::fromInt((int)($issue['severity'] ?? 0));
            $severityKey = match ($severity) {
                Severity::Critical => 'critical',
                Severity::Warning => 'warning',
                Severity::Info => 'info',
                Severity::NeedsReview => 'needs_review',
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

        $pages = array_slice($pages, 0, 10);

        foreach ($pages as &$page) {
            $page['criticalTone'] = (int)$page['critical'] > 0 ? 'critical' : 'zero';
            $page['warningTone'] = (int)$page['warning'] > 0 ? 'warning' : 'zero';
            $page['infoTone'] = (int)$page['info'] > 0 ? 'info' : 'zero';
            $page['needsReviewTone'] = (int)($page['needs_review'] ?? 0) > 0 ? 'info' : 'zero';
        }
        unset($page);

        return $pages;
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, array<string, mixed>>
     */
    private function preparePageIssues(array $issues): array
    {
        $prepared = [];
        $index = 1;

        foreach ($issues as $issue) {
            $severityEnum = Severity::fromInt((int)($issue['severity'] ?? 0));
            $statusEnum = IssueStatus::fromInt((int)($issue['status'] ?? 0));
            $severityKey = match ($severityEnum) {
                Severity::Critical => 'critical',
                Severity::Warning => 'warning',
                Severity::Info => 'info',
                Severity::NeedsReview => 'needs_review',
            };
            $statusKey = match ($statusEnum) {
                IssueStatus::Open => 'open',
                IssueStatus::Resolved => 'ok',
                IssueStatus::Ignored => 'none',
            };
            $ruleId = (string)($issue['rule_id'] ?? '');

            $prepared[] = [
                'index' => str_pad((string)$index, 2, '0', STR_PAD_LEFT),
                'message' => (string)($issue['message'] ?? ''),
                'hint' => (string)($issue['hint'] ?? ''),
                'rule_id' => $ruleId,
                'ruleTitle' => $this->humanizeRuleId($ruleId),
                'source_table' => (string)($issue['source_table'] ?? ''),
                'source_uid' => (int)($issue['source_uid'] ?? 0),
                'source_field' => (string)($issue['source_field'] ?? ''),
                'context_path' => (string)($issue['context_path'] ?? ''),
                'context_snippet' => (string)($issue['context_snippet'] ?? ''),
                'severityLabel' => strtoupper($severityEnum->label()),
                'statusLabel' => strtoupper($statusEnum->label()),
                'severityKey' => $severityKey,
                'statusKey' => $statusKey,
            ];
            $index++;
        }

        return $prepared;
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

    private function buildPageUrlLabel(string $siteLabel, string $pagePath, int $pageUid): string
    {
        $pagePath = trim($pagePath);
        if ($pagePath === '') {
            return 'uid:' . $pageUid;
        }

        if (preg_match('#^https?://#i', $pagePath) === 1) {
            return $pagePath;
        }

        if (preg_match('#^https?://#i', $siteLabel) !== 1) {
            return $pagePath;
        }

        return rtrim($siteLabel, '/') . '/' . ltrim($pagePath, '/');
    }

    private function buildOverviewPageSub(int $pageUid, string $siteLabel): string
    {
        if ($pageUid <= 0) {
            return 'Page UID not available';
        }

        $pageRecord = BackendUtility::getRecord('pages', $pageUid, 'uid,slug') ?: [];
        $slug = trim((string)($pageRecord['slug'] ?? ''));

        return $slug !== '' ? $this->buildPageUrlLabel($siteLabel, $slug, $pageUid) : 'uid:' . $pageUid;
    }

    private function formatPdfDate(?int $timestamp = null): string
    {
        return date('d M Y · H:i T', $timestamp ?? time());
    }

    private function normalizeFilterLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'all';
        }

        return match (strtolower($value)) {
            'all' => 'All',
            'needs_review' => 'Needs review',
            default => ucfirst(strtolower(str_replace('_', ' ', $value))),
        };
    }

    private function pluralize(string $singular, int $count): string
    {
        return $count === 1 ? $singular : $singular . 's';
    }

    private function humanizeRuleId(string $ruleId): string
    {
        $ruleId = preg_replace('/^(rte|structured|remote|axe)\./', '', $ruleId) ?? $ruleId;
        $ruleId = str_replace(['_', '-'], ' ', $ruleId);
        $ruleId = trim($ruleId);

        return $ruleId !== '' ? ucfirst($ruleId) : 'Accessibility rule';
    }

    private function severityWeight(string $tone): int
    {
        return match ($tone) {
            'critical' => 30,
            'warning' => 20,
            'needs_review' => 15,
            'info' => 10,
            default => 0,
        };
    }
}
