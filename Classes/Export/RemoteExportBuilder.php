<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Export;

use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueNodeRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScreenshotService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\RemoteReportingSummaryService;
use Psr\Http\Message\ServerRequestInterface;

final class RemoteExportBuilder
{
    private const PDF_SCREENSHOT_MAX_BYTES = 2097152;
    private const PDF_SCREENSHOT_MAX_WIDTH = 1400;
    private const PDF_SCREENSHOT_JPEG_QUALITY = 70;
    private const PDF_MAX_ISSUE_GROUPS = 8;
    private const PDF_MAX_NODES_PER_ISSUE = 2;
    private const PDF_MAX_CONTRAST_DETAILS_PER_NODE = 1;
    private const PDF_MAX_FOREGROUND_CANDIDATES = 3;
    private const PDF_MAX_BACKGROUND_CANDIDATES = 2;
    private const PDF_MAX_FAILURE_SUMMARY_CHARS = 220;
    private const PDF_MAX_HTML_SNIPPET_CHARS = 220;
    private const PDF_MAX_GUIDANCE_CHARS = 420;
    private const PDF_MAX_NODE_REMEDIATION_SUMMARY_CHARS = 260;
    private const PDF_MAX_NODE_REMEDIATION_STEP_CHARS = 180;
    private const PDF_REPORT_DISCLAIMER = 'Automated checks do not replace a manual WCAG audit. AQG guidance is based on the rule type and scan context. Review affected elements before applying changes.';

    public function __construct(
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly RemoteIssueRepository $remoteIssueRepository,
        private readonly RemoteIssueNodeRepository $remoteIssueNodeRepository,
        private readonly RemoteScreenshotService $remoteScreenshotService,
        private readonly RemoteReportingSummaryService $remoteReportingSummaryService,
        private readonly ProCrawlerService $proCrawlerService,
        private readonly ExtensionContextService $extensionContextService,
        private readonly PdfGenerator $pdfGenerator,
        private readonly PdfTemplateRenderer $pdfTemplateRenderer,
    ) {
    }

    public function buildOverviewCsv(
        string $siteIdentifier,
        int $remoteScanUid = 0,
        ?int $pageUid = null,
        int $languageUid = -1,
    ): string {
        $scan = $this->resolveOverviewExportScan($siteIdentifier, $remoteScanUid, $pageUid, $languageUid);
        if (!is_array($scan) || !isset($scan['uid'])) {
            return $this->buildRemoteIssueCsvHeaderOnly();
        }

        return $this->buildRemoteScanIssueCsv($scan);
    }

    private function buildRemoteIssueCsvHeaderOnly(): string
    {
        $output = fopen('php://memory', 'r+b');
        if ($output === false) {
            return '';
        }

        $this->writeRemoteIssueCsvHeader($output);
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv ?: '';
    }

    /**
     * @param array<string, mixed> $scan
     */
    private function buildRemoteScanIssueCsv(array $scan): string
    {
        $remoteScanUid = (int)($scan['uid'] ?? 0);
        if ($remoteScanUid <= 0) {
            return $this->buildRemoteIssueCsvHeaderOnly();
        }

        $pages = $this->remoteScanRepository->findPagesForScan($remoteScanUid);
        $output = fopen('php://memory', 'r+b');
        if ($output === false) {
            return '';
        }

        $this->writeRemoteIssueCsvHeader($output);

        foreach ($pages as $page) {
            $this->writeRemotePageIssueCsvRows($output, $page);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv ?: '';
    }

    /**
     * @param resource $output
     */
    private function writeRemoteIssueCsvHeader($output): void
    {
        fputcsv($output, [
            'URL',
            'Page title',
            'Rule ID',
            'Impact',
            'Help',
            'Help URL',
            'Failure summary',
            'HTML snippet',
            'Mapped table',
            'Mapped UID',
            'Mapped CType',
            'Mapped CID',
            'Actual ratio',
            'Required ratio',
            'Foreground',
            'Background',
            'Preferred candidate',
            'Preferred candidate estimated ratio',
            'Suggested foreground candidates',
            'Suggested background candidates',
            'Candidate note',
        ], ';');
    }

    /**
     * @param resource $output
     * @param array<string, mixed> $remotePage
     */
    private function writeRemotePageIssueCsvRows($output, array $remotePage): void
    {
        $remotePageUid = (int)($remotePage['uid'] ?? 0);
        if ($remotePageUid <= 0) {
            return;
        }

        $issueRows = $this->preparePageIssueRows($remotePageUid);
        foreach ($issueRows as $row) {
            if ($row['nodes'] === []) {
                fputcsv($output, [
                    (string)($remotePage['url'] ?? ''),
                    (string)($remotePage['title'] ?? ''),
                    $row['rule_id'],
                    $row['impact'],
                    $row['help'],
                    $row['help_url'],
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                ], ';');

                continue;
            }

            foreach ($row['nodes'] as $node) {
                $contrastCsv = $this->buildContrastCsvColumns($node['contrastDetails'] ?? []);
                fputcsv($output, [
                    (string)($remotePage['url'] ?? ''),
                    (string)($remotePage['title'] ?? ''),
                    $row['rule_id'],
                    $row['impact'],
                    $row['help'],
                    $row['help_url'],
                    (string)($node['failure_summary'] ?? ''),
                    (string)($node['html_snippet'] ?? ''),
                    (string)($node['mapped_table'] ?? ''),
                    (int)($node['mapped_uid'] ?? 0),
                    (string)($node['mapped_ctype'] ?? ''),
                    (string)($node['mapped_cid'] ?? ''),
                    $contrastCsv['actualRatio'],
                    $contrastCsv['requiredRatio'],
                    $contrastCsv['foreground'],
                    $contrastCsv['background'],
                    $contrastCsv['preferredCandidate'],
                    $contrastCsv['preferredEstimatedRatio'],
                    $contrastCsv['suggestedForegroundCandidates'],
                    $contrastCsv['suggestedBackgroundCandidates'],
                    $contrastCsv['candidateNote'],
                ], ';');
            }
        }
    }

    public function buildPageCsv(int $remotePageUid): string
    {
        $remotePage = $this->remoteScanRepository->findPageByUid($remotePageUid);
        if (!is_array($remotePage)) {
            return '';
        }

        $issueRows = $this->preparePageIssueRows($remotePageUid);

        $output = fopen('php://memory', 'r+b');
        if ($output === false) {
            return '';
        }

        $this->writeRemoteIssueCsvHeader($output);

        foreach ($issueRows as $row) {
            if ($row['nodes'] === []) {
                fputcsv($output, [
                    (string)($remotePage['url'] ?? ''),
                    (string)($remotePage['title'] ?? ''),
                    $row['rule_id'],
                    $row['impact'],
                    $row['help'],
                    $row['help_url'],
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                ], ';');

                continue;
            }

            foreach ($row['nodes'] as $node) {
                $contrastCsv = $this->buildContrastCsvColumns($node['contrastDetails'] ?? []);
                fputcsv($output, [
                    (string)($remotePage['url'] ?? ''),
                    (string)($remotePage['title'] ?? ''),
                    $row['rule_id'],
                    $row['impact'],
                    $row['help'],
                    $row['help_url'],
                    (string)($node['failure_summary'] ?? ''),
                    (string)($node['html_snippet'] ?? ''),
                    (string)($node['mapped_table'] ?? ''),
                    (int)($node['mapped_uid'] ?? 0),
                    (string)($node['mapped_ctype'] ?? ''),
                    (string)($node['mapped_cid'] ?? ''),
                    $contrastCsv['actualRatio'],
                    $contrastCsv['requiredRatio'],
                    $contrastCsv['foreground'],
                    $contrastCsv['background'],
                    $contrastCsv['preferredCandidate'],
                    $contrastCsv['preferredEstimatedRatio'],
                    $contrastCsv['suggestedForegroundCandidates'],
                    $contrastCsv['suggestedBackgroundCandidates'],
                    $contrastCsv['candidateNote'],
                ], ';');
            }
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv ?: '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveOverviewExportScan(
        string $siteIdentifier,
        int $remoteScanUid = 0,
        ?int $pageUid = null,
        int $languageUid = -1,
    ): ?array {
        if ($remoteScanUid > 0) {
            $scan = $this->remoteScanRepository->findScanByUid($remoteScanUid);
            if (
                is_array($scan)
                && (string)($scan['site_identifier'] ?? '') === $siteIdentifier
                && (string)($scan['status'] ?? '') === 'completed'
            ) {
                return $scan;
            }
        }

        if ($pageUid !== null && $pageUid > 0) {
            $pageScan = $this->remoteScanRepository->findLastCompletedPageScanByPage($siteIdentifier, $pageUid, $languageUid);
            if (is_array($pageScan)) {
                return $pageScan;
            }
        }

        return $this->remoteScanRepository->findLastCompletedSiteScanBySite($siteIdentifier, $languageUid);
    }

    public function buildOverviewPdf(
        string $siteIdentifier,
        ?ServerRequestInterface $request = null,
        int $remoteScanUid = 0,
        ?int $pageUid = null,
        int $languageUid = -1,
    ): string {
        $scan = $this->resolveOverviewExportScan($siteIdentifier, $remoteScanUid, $pageUid, $languageUid);
        $generatedAt = $this->formatPdfDate();
        $siteLabel = $siteIdentifier !== '' ? $siteIdentifier : 'Remote scan';

        if (!is_array($scan) || !isset($scan['uid'])) {
            $html = $this->pdfTemplateRenderer->render(
                templateName: 'Export/RemoteOverviewPdf',
                variables: [
                    'title' => 'Remote accessibility report',
                    'subtitle' => $siteLabel . ' · no completed scan',
                    'generatedAt' => $generatedAt,
                    'pageAlias' => 'PAGE {PAGENO} of {nbpg}',
                    'hasScan' => false,
                    'siteIdentifier' => $siteIdentifier,
                    'siteLabel' => $siteLabel,
                    'scanStartedAt' => '—',
                    'scanCompletedAt' => '—',
                    'scan' => null,
                    'score' => [],
                    'hasScore' => false,
                    'pages' => [],
                    'pagesShown' => 0,
                    'pagesScanned' => 0,
                    'pagesFailed' => 0,
                    'pagesScannedFoot' => 'not scanned yet',
                    'pagesFailedFoot' => 'no failed pages',
                    'issuesTotal' => 0,
                    'runtimeLabel' => 'not available',
                    'newResolvedLabel' => '+0 / −0',
                    'failedPages' => [],
                    'failedPagesShown' => 0,
                    'topRules' => [],
                    'topRulesShown' => 0,
                    'topRulesTotal' => 0,
                    'reportSummary' => [],
                    'hasReportSummary' => false,
                    'priorityFixes' => [],
                    'priorityFixesShown' => 0,
                    'priorityFixesShownLabel' => '0 priority fixes',
                    'wcagSummary' => [],
                    'wcagSummaryShown' => 0,
                    'wcagSummaryShownLabel' => '0 criteria',
                    'manualReviewChecklist' => [],
                    'manualReviewChecklistShown' => 0,
                    'manualReviewChecklistShownLabel' => '0 manual review items',
                    'reportingGroups' => [],
                    'hasReportingGroups' => false,
                    'keyboardSummary' => [],
                    'hasKeyboardSummary' => false,
                    'structureSummary' => [],
                    'hasStructureSummary' => false,
                    'contrastDetails' => [],
                    'hasContrastDetails' => false,
                    'remediationSummary' => [],
                    'hasRemediationSummary' => false,
                    'reportingGroupsShown' => 0,
                    'reportingGroupsShownLabel' => '0 reporting groups',
                    'disclaimerText' => self::PDF_REPORT_DISCLAIMER,
                ],
                request: $request,
            );

            return $this->pdfGenerator->render(
                html: $html,
                title: 'AQG',
            );
        }

        $siteLabel = $this->resolveRootUrl(
            (string)($scan['start_url'] ?? ''),
            $siteLabel
        );
        $pages = $this->remoteScanRepository->findPagesForScan((int)$scan['uid']);
        $failedPages = $this->remoteScanRepository->findFailedPagesForScan((int)$scan['uid']);
        $topRules = $this->buildOverviewTopRules($pages);
        $preparedPages = $this->prepareOverviewPages($pages);
        $preparedFailedPages = $this->prepareFailedPages($failedPages);
        $pagesScanned = (int)($scan['pages_scanned'] ?? count($pages));
        $pagesFailed = (int)($scan['pages_failed'] ?? count($failedPages));
        $issuesTotal = (int)($scan['issues_total'] ?? 0);
        $issuesNew = (int)($scan['issues_new'] ?? 0);
        $issuesResolved = (int)($scan['issues_resolved'] ?? 0);
        $reporting = $this->preparePdfReportingSummary($scan);

        $html = $this->pdfTemplateRenderer->render(
            templateName: 'Export/RemoteOverviewPdf',
            variables: [
                'title' => 'Remote accessibility report',
                'subtitle' => $siteLabel . ' · last completed scan',
                'generatedAt' => $generatedAt,
                'pageAlias' => 'PAGE {PAGENO} of {nbpg}',
                'hasScan' => true,
                'siteIdentifier' => $siteIdentifier,
                'siteLabel' => $siteLabel,
                'scanStartedAt' => $this->formatPdfDateFromMixed($scan['started_at'] ?? null),
                'scanCompletedAt' => $this->formatPdfDateFromMixed($scan['finished_at'] ?? null),
                'scan' => $scan,
                'score' => $reporting['score'],
                'hasScore' => !empty($reporting['score']['hasValue']),
                'pages' => array_slice($preparedPages, 0, 10),
                'pagesShown' => min(count($preparedPages), 10),
                'pagesScanned' => $pagesScanned,
                'pagesFailed' => $pagesFailed,
                'pagesScannedFoot' => $pagesScanned . ' ' . $this->pluralize('URL', $pagesScanned) . ' in last scan',
                'pagesFailedFoot' => $pagesFailed > 0 ? $pagesFailed . ' skipped or timed out' : 'no failed pages',
                'issuesTotal' => $issuesTotal,
                'runtimeLabel' => $this->buildRuntimeLabel($scan),
                'newResolvedLabel' => '+' . $issuesNew . ' / −' . $issuesResolved,
                'failedPages' => array_slice($preparedFailedPages, 0, 10),
                'failedPagesShown' => min(count($preparedFailedPages), 10),
                'topRules' => $topRules,
                'topRulesShown' => count($topRules),
                'topRulesTotal' => count($topRules),
                'reportSummary' => $reporting['reportSummary'],
                'hasReportSummary' => $reporting['hasReportSummary'],
                'priorityFixes' => $reporting['priorityFixes'],
                'priorityFixesShown' => count($reporting['priorityFixes']),
                'priorityFixesShownLabel' => $this->countLabel(count($reporting['priorityFixes']), 'priority fix', 'priority fixes'),
                'wcagSummary' => $reporting['wcagSummary'],
                'wcagSummaryShown' => count($reporting['wcagSummary']),
                'wcagSummaryShownLabel' => $this->countLabel(count($reporting['wcagSummary']), 'criterion', 'criteria'),
                'manualReviewChecklist' => $reporting['manualReviewChecklist'],
                'manualReviewChecklistShown' => count($reporting['manualReviewChecklist']),
                'manualReviewChecklistShownLabel' => $this->countLabel(count($reporting['manualReviewChecklist']), 'manual review item', 'manual review items'),
                'reportingGroups' => $reporting['reportingGroups'],
                'hasReportingGroups' => $reporting['reportingGroups'] !== [],
                'keyboardSummary' => $reporting['keyboardSummary'],
                'hasKeyboardSummary' => $reporting['keyboardSummary'] !== [],
                'structureSummary' => $reporting['structureSummary'],
                'hasStructureSummary' => $reporting['structureSummary'] !== [],
                'contrastDetails' => $reporting['contrastDetails'],
                'hasContrastDetails' => $reporting['contrastDetails'] !== [],
                'remediationSummary' => $reporting['remediationSummary'],
                'hasRemediationSummary' => $reporting['remediationSummary'] !== [],
                'componentSummary' => $reporting['componentSummary'] ?? [],
                'hasComponentSummary' => !empty($reporting['componentSummary']),
                'pageRecommendation' => $reporting['pageRecommendation'],
                'hasPageRecommendation' => $reporting['pageRecommendation'] !== [],
                'reportingGroupsShown' => is_array($reporting['reportingGroups']['groups'] ?? null) ? count($reporting['reportingGroups']['groups']) : 0,
                'reportingGroupsShownLabel' => $this->countLabel(is_array($reporting['reportingGroups']['groups'] ?? null) ? count($reporting['reportingGroups']['groups']) : 0, 'reporting group', 'reporting groups'),
                'disclaimerText' => self::PDF_REPORT_DISCLAIMER,
            ],
            request: $request,
        );

        return $this->pdfGenerator->render(
            html: $html,
            title: 'AQG',
        );
    }

    public function buildPagePdf(int $remotePageUid, ?ServerRequestInterface $request = null): string
    {
        $generatedAt = $this->formatPdfDate();
        $remotePage = $this->remoteScanRepository->findPageByUid($remotePageUid);

        if (!is_array($remotePage)) {
            $html = $this->pdfTemplateRenderer->render(
                templateName: 'Export/RemotePagePdf',
                variables: [
                    'title' => 'Page not found',
                    'subtitle' => 'Remote page',
                    'generatedAt' => $generatedAt,
                    'pageAlias' => 'Page {PAGENO} of {nbpg}',
                    'hasPage' => false,
                    'remotePage' => null,
                    'siteLabel' => 'Remote scan',
                    'relativeUrl' => 'remote page',
                    'issues' => [],
                    'hasIssues' => false,
                    'issuesFoundLabel' => '0 issues',
                    'issuesShownLabel' => '0 matching issues',
                    'screenshotAvailable' => false,
                    'screenshotPlaceholder' => '',
                    'screenshotMeta' => '',
                    'hasFailure' => false,
                    'failureReason' => '',
                    'httpTone' => 'none',
                    'httpStatusLabel' => 'not available',
                    'scanCompletedAt' => '—',
                    'reportSummary' => [],
                    'hasReportSummary' => false,
                    'priorityFixes' => [],
                    'priorityFixesShown' => 0,
                    'priorityFixesShownLabel' => '0 priority fixes',
                    'wcagSummary' => [],
                    'wcagSummaryShown' => 0,
                    'wcagSummaryShownLabel' => '0 criteria',
                    'manualReviewChecklist' => [],
                    'manualReviewChecklistShown' => 0,
                    'manualReviewChecklistShownLabel' => '0 manual review items',
                    'reportingGroups' => [],
                    'hasReportingGroups' => false,
                    'keyboardSummary' => [],
                    'hasKeyboardSummary' => false,
                    'structureSummary' => [],
                    'hasStructureSummary' => false,
                    'contrastDetails' => [],
                    'hasContrastDetails' => false,
                    'remediationSummary' => [],
                    'hasRemediationSummary' => false,
                    'componentSummary' => [],
                    'hasComponentSummary' => false,
                    'pageRecommendation' => [],
                    'hasPageRecommendation' => false,
                    'reportingGroupsShown' => 0,
                    'reportingGroupsShownLabel' => '0 reporting groups',
                    'disclaimerText' => self::PDF_REPORT_DISCLAIMER,
                ],
                request: $request,
            );

            return $this->renderRemotePagePdfSafely(
                html: $html,
                screenshot: ['html' => '', 'meta' => '', 'imageVars' => []],
                remotePageUid: $remotePageUid,
                pageUrl: 'remote page',
                issueGroupCount: 0,
                occurrencesCount: 0,
            );
        }

        $allIssues = $this->preparePageIssueRows($remotePageUid);
        $issues = $this->preparePageIssueRowsForPdf($allIssues);
        $issueGroupCount = count($allIssues);
        $issueGroupsShown = count($issues);
        $occurrencesCount = array_sum(array_map(
            static fn(array $issue): int => (int)($issue['nodes_count'] ?? 0),
            $allIssues
        ));
        $httpStatus = (int)($remotePage['http_status'] ?? 0);
        $failureReason = trim((string)($remotePage['failure_reason'] ?? ''));
        $pageUrl = (string)($remotePage['url'] ?? '');
        $siteLabel = $this->resolveRootUrl(
            $pageUrl,
            (string)($remotePage['site_identifier'] ?? $remotePage['site'] ?? 'Remote scan')
        );
        $relativeUrl = $this->relativePathFromUrl($pageUrl);
        $screenshot = $this->buildScreenshotBlock($remotePageUid);
        $remoteScan = $this->remoteScanRepository->findScanByUid((int)($remotePage['remote_scan'] ?? 0));
        $reporting = $this->preparePdfPageReportingSummary($remotePage, $allIssues, $remoteScan);

        $issuesShownLabel = '0 matching issues';
        if ($issueGroupCount > 0) {
            $issuesShownLabel = 'showing ' . $issueGroupsShown . ' of ' . $issueGroupCount . ' ' . $this->pluralize('issue group', $issueGroupCount);
            if ($issueGroupCount > $issueGroupsShown) {
                $issuesShownLabel .= ' · compact PDF';
            }
        }

        $html = $this->pdfTemplateRenderer->render(
            templateName: 'Export/RemotePagePdf',
            variables: [
                'title' => (string)($remotePage['title'] ?? 'Remote page report'),
                'subtitle' => (string)($remotePage['url'] ?? ''),
                'generatedAt' => $generatedAt,
                'pageAlias' => 'Page {PAGENO} of {nbpg}',
                'hasPage' => true,
                'remotePage' => $remotePage,
                'siteLabel' => $siteLabel,
                'relativeUrl' => $relativeUrl,
                'issues' => $issues,
                'hasIssues' => $issues !== [],
                'issuesFoundLabel' => $issueGroupCount . ' ' . $this->pluralize('issue group', $issueGroupCount) . ' · ' . $occurrencesCount . ' ' . $this->pluralize('occurrence', $occurrencesCount),
                'issuesShownLabel' => $issuesShownLabel,
                'screenshotAvailable' => $screenshot['html'] !== '',
                'screenshotPlaceholder' => $screenshot['html'],
                'screenshotMeta' => $screenshot['meta'],
                'hasFailure' => $failureReason !== '' || $httpStatus >= 400,
                'failureReason' => $failureReason !== '' ? $failureReason : 'The crawler reached this URL but the HTTP status indicates a failed request.',
                'httpTone' => $this->httpTone($httpStatus),
                'httpStatusLabel' => $httpStatus > 0 ? (string)$httpStatus : 'not available',
                'scanCompletedAt' => $this->formatPdfDateFromMixed($remotePage['scan_completed_at'] ?? $remotePage['finished_at'] ?? $remotePage['tstamp'] ?? null),
                'reportSummary' => $reporting['reportSummary'],
                'hasReportSummary' => $reporting['hasReportSummary'],
                'priorityFixes' => $reporting['priorityFixes'],
                'priorityFixesShown' => count($reporting['priorityFixes']),
                'priorityFixesShownLabel' => $this->countLabel(count($reporting['priorityFixes']), 'priority fix', 'priority fixes'),
                'wcagSummary' => $reporting['wcagSummary'],
                'wcagSummaryShown' => count($reporting['wcagSummary']),
                'wcagSummaryShownLabel' => $this->countLabel(count($reporting['wcagSummary']), 'criterion', 'criteria'),
                'manualReviewChecklist' => $reporting['manualReviewChecklist'],
                'manualReviewChecklistShown' => count($reporting['manualReviewChecklist']),
                'manualReviewChecklistShownLabel' => $this->countLabel(count($reporting['manualReviewChecklist']), 'manual review item', 'manual review items'),
                'reportingGroups' => $reporting['reportingGroups'],
                'hasReportingGroups' => $reporting['reportingGroups'] !== [],
                'keyboardSummary' => $reporting['keyboardSummary'],
                'hasKeyboardSummary' => $reporting['keyboardSummary'] !== [],
                'structureSummary' => $reporting['structureSummary'],
                'hasStructureSummary' => $reporting['structureSummary'] !== [],
                'contrastDetails' => $reporting['contrastDetails'],
                'hasContrastDetails' => $reporting['contrastDetails'] !== [],
                'remediationSummary' => $reporting['remediationSummary'],
                'hasRemediationSummary' => $reporting['remediationSummary'] !== [],
                'componentSummary' => $reporting['componentSummary'] ?? [],
                'hasComponentSummary' => !empty($reporting['componentSummary']),
                'pageRecommendation' => $reporting['pageRecommendation'],
                'hasPageRecommendation' => $reporting['pageRecommendation'] !== [],
                'reportingGroupsShown' => is_array($reporting['reportingGroups']['groups'] ?? null) ? count($reporting['reportingGroups']['groups']) : 0,
                'reportingGroupsShownLabel' => $this->countLabel(is_array($reporting['reportingGroups']['groups'] ?? null) ? count($reporting['reportingGroups']['groups']) : 0, 'reporting group', 'reporting groups'),
                'disclaimerText' => self::PDF_REPORT_DISCLAIMER,
            ],
            request: $request,
        );

        return $this->renderRemotePagePdfSafely(
            html: $html,
            screenshot: $screenshot,
            remotePageUid: $remotePageUid,
            pageUrl: $pageUrl,
            issueGroupCount: $issueGroupCount,
            occurrencesCount: $occurrencesCount,
        );
    }

    /**
     * @param array{html:string,meta:string,imageVars:array<string,string>} $screenshot
     */
    private function renderRemotePagePdfSafely(
        string $html,
        array $screenshot,
        int $remotePageUid,
        string $pageUrl,
        int $issueGroupCount,
        int $occurrencesCount,
    ): string {
        try {
            return $this->pdfGenerator->render(
                html: $html,
                title: 'AQG',
                imageVars: $screenshot['imageVars'] ?? [],
            );
        } catch (\Throwable) {
            // Continue with the same report without screenshot. This handles
            // broken/unsupported image binaries while keeping the report usable.
        }

        $fallbackHtml = $html;
        if (($screenshot['html'] ?? '') !== '') {
            $fallbackHtml = str_replace(
                (string)$screenshot['html'],
                '<div class="aqgp-screenshot-placeholder"><strong>Screenshot could not be embedded.</strong><br />The page detail report was generated without the screenshot image.</div>',
                $html
            );
        }

        try {
            return $this->pdfGenerator->render(
                html: $fallbackHtml,
                title: 'AQG',
                imageVars: [],
            );
        } catch (\Throwable $secondFailure) {
            return $this->buildMinimalFallbackPdf($remotePageUid, $pageUrl, $issueGroupCount, $occurrencesCount, $secondFailure);
        }
    }

    private function buildMinimalFallbackPdf(
        int $remotePageUid,
        string $pageUrl,
        int $issueGroupCount,
        int $occurrencesCount,
        \Throwable $failure,
    ): string {
        $lines = [
            'AQG Remote Page Detail',
            'Remote page UID: ' . $remotePageUid,
            'URL: ' . ($pageUrl !== '' ? $pageUrl : 'not available'),
            'Issue groups: ' . $issueGroupCount,
            'Occurrences: ' . $occurrencesCount,
            'Detailed PDF rendering failed. This fallback PDF is not a complete accessibility report.',
            'Use the HTML detail view or CSV export for full findings and node details.',
            'Error: ' . get_class($failure) . ' - ' . $failure->getMessage(),
        ];

        return $this->buildRawTextPdf($lines);
    }

    /**
     * @param array<int, string> $lines
     */
    private function buildRawTextPdf(array $lines): string
    {
        $content = "BT\n/F1 12 Tf\n50 790 Td\n14 TL\n";
        foreach ($lines as $line) {
            $content .= '(' . $this->escapePdfText($this->truncatePdfText($line, 180)) . ") Tj\nT*\n";
        }
        $content .= "ET\n";

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
            "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1, $count = count($offsets); $i < $count; $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

        return $pdf;
    }

    private function escapePdfText(string $value): string
    {
        $value = str_replace(["\\", "(", ")", "\r", "\n"], ["\\\\", "\\(", "\\)", ' ', ' '], $value);

        return preg_replace('/[^\x20-\x7E]/', '?', $value) ?? $value;
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, array{ruleId:string,title:string,count:int,tone:string}>
     */
    private function buildOverviewTopRules(array $pages): array
    {
        $topRules = [];

        foreach ($pages as $page) {
            $pageUid = (int)($page['uid'] ?? 0);
            if ($pageUid <= 0) {
                continue;
            }

            foreach ($this->remoteIssueRepository->findByRemoteScanPage($pageUid) as $issue) {
                $ruleId = trim((string)($issue['rule_id'] ?? ''));
                if ($ruleId === '') {
                    continue;
                }

                $impact = strtolower(trim((string)($issue['impact'] ?? '')));
                $tone = match ($impact) {
                    'critical', 'serious' => 'critical',
                    'moderate' => 'warning',
                    default => 'info',
                };

                if (!isset($topRules[$ruleId])) {
                    $topRules[$ruleId] = [
                        'ruleId' => $ruleId,
                        'title' => $this->humanizeRuleId($ruleId),
                        'count' => 0,
                        'tone' => $tone,
                        'weight' => $this->severityWeight($tone),
                    ];
                }

                $topRules[$ruleId]['count'] += max(1, (int)($issue['nodes_count'] ?? 1));
                if ($this->severityWeight($tone) > (int)$topRules[$ruleId]['weight']) {
                    $topRules[$ruleId]['tone'] = $tone;
                    $topRules[$ruleId]['weight'] = $this->severityWeight($tone);
                }
            }
        }

        uasort(
            $topRules,
            static fn(array $a, array $b): int => [$b['count'], $b['weight'], $a['ruleId']] <=> [$a['count'], $a['weight'], $b['ruleId']]
        );

        return array_map(
            static fn(array $rule): array => [
                'ruleId' => (string)$rule['ruleId'],
                'title' => (string)$rule['title'],
                'count' => (int)$rule['count'],
                'tone' => (string)$rule['tone'],
            ],
            array_slice(array_values($topRules), 0, 10)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, array{title:string,url:string,httpStatus:int,httpTone:string,issuesCount:int,issueTone:string}>
     */
    private function prepareOverviewPages(array $pages): array
    {
        $prepared = [];

        foreach ($pages as $page) {
            $title = trim((string)($page['title'] ?? ''));
            $url = trim((string)($page['url'] ?? ''));
            $issuesCount = (int)($page['issues_count'] ?? 0);
            $httpStatus = (int)($page['http_status'] ?? 0);

            $prepared[] = [
                'title' => $title !== '' ? $title : ($url !== '' ? $url : 'Remote page'),
                'url' => $url !== '' ? $url : 'URL not available',
                'httpStatus' => $httpStatus,
                'httpTone' => $this->httpTone($httpStatus),
                'issuesCount' => $issuesCount,
                'issueTone' => $issuesCount > 0 ? 'warning' : 'zero',
            ];
        }

        usort(
            $prepared,
            static fn(array $a, array $b): int => [$b['issuesCount'], $a['title']] <=> [$a['issuesCount'], $b['title']]
        );

        return $prepared;
    }

    /**
     * @param array<int, array<string, mixed>> $failedPages
     * @return array<int, array{title:string,url:string,httpStatus:int,httpTone:string,failureReason:string}>
     */
    private function prepareFailedPages(array $failedPages): array
    {
        $prepared = [];

        foreach ($failedPages as $page) {
            $title = trim((string)($page['title'] ?? ''));
            $url = trim((string)($page['url'] ?? ''));
            $httpStatus = (int)($page['http_status'] ?? 0);
            $reason = trim((string)($page['failure_reason'] ?? ''));

            $prepared[] = [
                'title' => $title !== '' ? $title : ($url !== '' ? $url : 'Failed page'),
                'url' => $url !== '' ? $url : 'URL not available',
                'httpStatus' => $httpStatus,
                'httpTone' => $this->httpTone($httpStatus),
                'failureReason' => $reason !== '' ? $reason : 'No failure reason stored',
            ];
        }

        return $prepared;
    }

    /**
     * @return array<int, array{
     *   rule_id:string,
     *   impact:string,
     *   help:string,
     *   help_url:string,
     *   nodes_count:int,
     *   nodes:array<int, array<string, mixed>>
     * }>
     */
    private function preparePageIssueRows(int $remotePageUid): array
    {
        $issues = $this->remoteIssueRepository->findByRemoteScanPage($remotePageUid);
        $groups = [];

        foreach ($issues as $issue) {
            $issueUid = (int)($issue['uid'] ?? 0);
            $nodes = $issueUid > 0
                ? $this->remoteIssueNodeRepository->findByRemoteIssue($issueUid)
                : [];
            $impact = strtolower(trim((string)($issue['impact'] ?? '')));
            $impactWeight = $this->impactWeightFromImpact($impact);
            $nodesCount = max((int)($issue['nodes_count'] ?? 0), count($nodes), 1);
            $ruleId = trim((string)($issue['rule_id'] ?? ''));
            $groupKey = $ruleId !== '' ? $ruleId : 'issue-' . $issueUid;
            $title = trim((string)($issue['help'] ?? ''));
            $helpUrl = trim((string)($issue['help_url'] ?? ''));
            $guidanceWhyItMatters = $this->normalizeNullableString($issue['guidance_why_it_matters'] ?? null);
            $guidanceHowToFix = $this->normalizeNullableString($issue['guidance_how_to_fix'] ?? null);
            $whoShouldFix = $this->normalizeMachineValue($issue['who_should_fix'] ?? null);
            $fixType = $this->normalizeMachineValue($issue['fix_type'] ?? null);
            $confidence = $this->normalizeMachineValue($issue['confidence'] ?? null);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'rule_id' => $ruleId,
                    'ruleLabel' => strtoupper($ruleId),
                    'impact' => $impact,
                    'impactWeight' => $impactWeight,
                    'impactLabel' => $impact !== '' ? $impact : 'unknown',
                    'tone' => $this->toneFromImpact($impact),
                    'title' => $title !== '' ? $title : $this->humanizeRuleId($ruleId),
                    'help' => $title,
                    'help_url' => $helpUrl,
                    'help_url_display' => $this->stripSchemeFromUrl($helpUrl),
                    'nodes_count' => 0,
                    'nodes' => [],
                    'guidanceWhyItMatters' => $guidanceWhyItMatters,
                    'guidanceHowToFixRaw' => $guidanceHowToFix,
                    'whoShouldFix' => $whoShouldFix,
                    'whoShouldFixLabel' => $this->formatBadgeLabel($whoShouldFix),
                    'fixType' => $fixType,
                    'fixTypeLabel' => $this->formatBadgeLabel($fixType),
                    'confidence' => $confidence,
                    'confidenceLabel' => $this->formatBadgeLabel($confidence),
                ];
            }

            $groups[$groupKey]['nodes_count'] += $nodesCount;

            if ($impactWeight > (int)($groups[$groupKey]['impactWeight'] ?? 0)) {
                $groups[$groupKey]['impact'] = $impact;
                $groups[$groupKey]['impactWeight'] = $impactWeight;
                $groups[$groupKey]['impactLabel'] = $impact !== '' ? $impact : 'unknown';
                $groups[$groupKey]['tone'] = $this->toneFromImpact($impact);
            }

            if ((string)($groups[$groupKey]['title'] ?? '') === '' && $title !== '') {
                $groups[$groupKey]['title'] = $title;
            }
            if ((string)($groups[$groupKey]['help'] ?? '') === '' && $title !== '') {
                $groups[$groupKey]['help'] = $title;
            }
            if ((string)($groups[$groupKey]['help_url'] ?? '') === '' && $helpUrl !== '') {
                $groups[$groupKey]['help_url'] = $helpUrl;
                $groups[$groupKey]['help_url_display'] = $this->stripSchemeFromUrl($helpUrl);
            }
            if ($groups[$groupKey]['guidanceWhyItMatters'] === null && $guidanceWhyItMatters !== null) {
                $groups[$groupKey]['guidanceWhyItMatters'] = $guidanceWhyItMatters;
            }
            if ($groups[$groupKey]['guidanceHowToFixRaw'] === null && $guidanceHowToFix !== null) {
                $groups[$groupKey]['guidanceHowToFixRaw'] = $guidanceHowToFix;
            }
            foreach (['whoShouldFix', 'fixType', 'confidence'] as $key) {
                if (($groups[$groupKey][$key] ?? '') === '' && ${$key} !== '') {
                    $groups[$groupKey][$key] = ${$key};
                    $groups[$groupKey][$key . 'Label'] = $this->formatBadgeLabel(${$key});
                }
            }

            foreach ($nodes as $node) {
                $mappedTable = trim((string)($node['mapped_table'] ?? ''));
                $mappedUid = (int)($node['mapped_uid'] ?? 0);
                $mappedRecord = $mappedTable !== '' && $mappedUid > 0
                    ? $mappedTable . ' · uid:' . $mappedUid
                    : '';
                $contrastDetails = $this->decodeContrastDetails((string)($node['contrast_details_json'] ?? ''));
                $nodeRemediation = $this->decodeNodeRemediation((string)($node['node_remediation_json'] ?? ''));

                $groups[$groupKey]['nodes'][] = [
                    'index' => 0,
                    'failure_summary' => (string)($node['failure_summary'] ?? ''),
                    'html_snippet' => (string)($node['html_snippet'] ?? ''),
                    'mapped_table' => $mappedTable,
                    'mapped_uid' => $mappedUid,
                    'mapped_ctype' => (string)($node['mapped_ctype'] ?? ''),
                    'mapped_cid' => (string)($node['mapped_cid'] ?? ''),
                    'mappedRecord' => $mappedRecord,
                    'contrastDetails' => $contrastDetails,
                    'hasContrastDetails' => $contrastDetails !== [],
                    'nodeRemediation' => $nodeRemediation,
                    'hasNodeRemediation' => $nodeRemediation !== [],
                ];
            }
        }

        $rows = array_values($groups);
        usort(
            $rows,
            static fn(array $a, array $b): int => [
                (int)($b['impactWeight'] ?? 0),
                (int)($b['nodes_count'] ?? 0),
                (string)($a['rule_id'] ?? ''),
            ] <=> [
                (int)($a['impactWeight'] ?? 0),
                (int)($a['nodes_count'] ?? 0),
                (string)($b['rule_id'] ?? ''),
            ]
        );

        $index = 1;
        foreach ($rows as &$row) {
            $nodeIndex = 1;
            foreach ($row['nodes'] as &$node) {
                $node['index'] = $nodeIndex++;
            }
            unset($node);

            $guidanceHowToFix = $row['guidanceHowToFixRaw'] ?? null;
            $guidanceIsFallback = $guidanceHowToFix === null;

            $row['index'] = str_pad((string)$index, 2, '0', STR_PAD_LEFT);
            $row['nodesLabel'] = (int)$row['nodes_count'] . ' ' . $this->pluralize('occurrence', (int)$row['nodes_count']);
            $row['hasNodes'] = $row['nodes'] !== [];
            $row['guidanceHowToFix'] = $guidanceHowToFix ?? 'Review this finding in context.';
            $row['guidanceIsFallback'] = $guidanceIsFallback;
            $row['guidanceHeading'] = $guidanceIsFallback ? 'Review needed' : 'How to fix';
            $row['hasGuidance'] = true;
            unset($row['impactWeight'], $row['guidanceHowToFixRaw']);
            $index++;
        }
        unset($row);

        return $rows;
    }



    /**
     * Keep the rich HTML/CSV data intact while rendering only a compact,
     * mPDF-safe subset in the Remote Page Detail PDF.
     *
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, array<string, mixed>>
     */
    private function preparePageIssueRowsForPdf(array $issues): array
    {
        $compact = array_slice($issues, 0, self::PDF_MAX_ISSUE_GROUPS);

        foreach ($compact as &$issue) {
            $issue['guidanceWhyItMatters'] = $this->truncatePdfNullableString($issue['guidanceWhyItMatters'] ?? null, self::PDF_MAX_GUIDANCE_CHARS);
            $issue['guidanceHowToFix'] = $this->truncatePdfText((string)($issue['guidanceHowToFix'] ?? ''), self::PDF_MAX_GUIDANCE_CHARS);

            $allNodes = is_array($issue['nodes'] ?? null) ? $issue['nodes'] : [];
            $totalNodes = max((int)($issue['nodes_count'] ?? 0), count($allNodes));
            $nodes = array_slice($allNodes, 0, self::PDF_MAX_NODES_PER_ISSUE);
            $shownNodes = count($nodes);
            $issue['pdfNodesTotal'] = $totalNodes;
            $issue['pdfNodesShown'] = $shownNodes;
            $issue['pdfNodesWereTruncated'] = $totalNodes > $shownNodes;
            $issue['pdfNodesShownLabel'] = $totalNodes . ' ' . $this->pluralize('occurrence', $totalNodes) . ' total · ' . $shownNodes . ' shown in PDF';

            foreach ($nodes as &$node) {
                $node['failure_summary'] = $this->truncatePdfText((string)($node['failure_summary'] ?? ''), self::PDF_MAX_FAILURE_SUMMARY_CHARS);
                $node['html_snippet'] = $this->truncatePdfText(strip_tags((string)($node['html_snippet'] ?? '')), self::PDF_MAX_HTML_SNIPPET_CHARS);

                if (is_array($node['nodeRemediation'] ?? null)) {
                    $node['nodeRemediation'] = $this->compactNodeRemediationForPdf($node['nodeRemediation']);
                    $node['hasNodeRemediation'] = $node['nodeRemediation'] !== [];
                }

                if (is_array($node['contrastDetails'] ?? null)) {
                    $node['contrastDetails'] = $this->compactContrastDetailsForPdf($node['contrastDetails']);
                    $node['hasContrastDetails'] = $node['contrastDetails'] !== [];
                }
            }
            unset($node);

            $issue['nodes'] = $nodes;
            $issue['hasNodes'] = $nodes !== [];
        }
        unset($issue);

        return $compact;
    }

    /**
     * @param array<string, mixed> $remediation
     * @return array<string, mixed>
     */
    private function compactNodeRemediationForPdf(array $remediation): array
    {
        if ($remediation === []) {
            return [];
        }

        $remediation['summary'] = $this->truncatePdfNullableString($remediation['summary'] ?? null, self::PDF_MAX_NODE_REMEDIATION_SUMMARY_CHARS);
        $remediation['documentationHint'] = $this->truncatePdfNullableString($remediation['documentationHint'] ?? null, self::PDF_MAX_NODE_REMEDIATION_SUMMARY_CHARS);

        $steps = is_array($remediation['steps'] ?? null) ? $remediation['steps'] : [];
        $steps = array_slice($steps, 0, 3);
        $steps = array_values(array_filter(array_map(
            fn(mixed $step): string => $this->truncatePdfText((string)$step, self::PDF_MAX_NODE_REMEDIATION_STEP_CHARS),
            $steps
        ), static fn(string $step): bool => $step !== ''));
        $remediation['steps'] = $steps;
        $remediation['hasSteps'] = $steps !== [];

        return $remediation;
    }

    /**
     * @param array<int, array<string, mixed>> $contrastDetails
     * @return array<int, array<string, mixed>>
     */
    private function compactContrastDetailsForPdf(array $contrastDetails): array
    {
        $details = array_slice($contrastDetails, 0, self::PDF_MAX_CONTRAST_DETAILS_PER_NODE);

        foreach ($details as &$detail) {
            if (!is_array($detail['contrastSuggestion'] ?? null)) {
                continue;
            }

            $suggestion = $detail['contrastSuggestion'];
            // Keep the PDF wording short and stable. Full API notes/reasons remain available in HTML detail and CSV.
            $suggestion['note'] = 'Automated color suggestions must be reviewed in the brand/design context. They do not confirm WCAG, BFSG or BITV compliance.';
            $suggestion['reviewHint'] = null;

            if (is_array($suggestion['preferredCandidate'] ?? null)) {
                $suggestion['preferredCandidate'] = $this->compactContrastCandidateForPdf($suggestion['preferredCandidate']);
            }

            $foreground = is_array($suggestion['suggestedForegroundCandidateDetails'] ?? null)
                ? $suggestion['suggestedForegroundCandidateDetails']
                : [];
            $background = is_array($suggestion['suggestedBackgroundCandidateDetails'] ?? null)
                ? $suggestion['suggestedBackgroundCandidateDetails']
                : [];

            $foreground = array_map(
                fn(array $candidate): array => $this->compactContrastCandidateForPdf($candidate),
                array_slice($foreground, 0, self::PDF_MAX_FOREGROUND_CANDIDATES)
            );
            $background = array_map(
                fn(array $candidate): array => $this->compactContrastCandidateForPdf($candidate),
                array_slice($background, 0, self::PDF_MAX_BACKGROUND_CANDIDATES)
            );

            $suggestion['suggestedForegroundCandidateDetails'] = $foreground;
            $suggestion['suggestedBackgroundCandidateDetails'] = $background;
            $suggestion['suggestedForegroundCandidates'] = array_values(array_filter(array_column($foreground, 'color')));
            $suggestion['suggestedBackgroundCandidates'] = array_values(array_filter(array_column($background, 'color')));
            $suggestion['hasSuggestedForegroundCandidates'] = $foreground !== [];
            $suggestion['hasSuggestedBackgroundCandidates'] = $background !== [];

            $detail['contrastSuggestion'] = $suggestion;
            $detail['hasContrastSuggestion'] = $suggestion !== [];
        }
        unset($detail);

        return $details;
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function compactContrastCandidateForPdf(array $candidate): array
    {
        $candidate['label'] = $this->truncatePdfText((string)($candidate['label'] ?? $candidate['color'] ?? ''), 96);
        // Keep the PDF compact. Full candidate explanation/reason remains available in HTML and CSV.
        $candidate['explanation'] = '';
        $candidate['reason'] = '';

        return $candidate;
    }

    private function truncatePdfNullableString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = $this->truncatePdfText((string)$value, $maxLength);

        return $text !== '' ? $text : null;
    }

    private function truncatePdfText(string $text, int $maxLength = 300): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', strip_tags($text)));
        if ($text === '') {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length <= $maxLength) {
            return $text;
        }

        $sliceLength = max(0, $maxLength - 1);
        $truncated = function_exists('mb_substr')
            ? mb_substr($text, 0, $sliceLength, 'UTF-8')
            : substr($text, 0, $sliceLength);

        return rtrim($truncated) . '…';
    }


    /**
     * @param array<string, mixed> $remotePage
     * @param array<int, array<string, mixed>> $pageIssueRows
     * @param array<string, mixed>|null $remoteScan
     * @return array{reportSummary:array<string, mixed>,hasReportSummary:bool,priorityFixes:array<int, array<string, mixed>>,wcagSummary:array<int, array<string, mixed>>}
     */
    private function preparePdfPageReportingSummary(array $remotePage, array $pageIssueRows, ?array $remoteScan): array
    {
        if (is_array($remoteScan) && (string)($remoteScan['scan_scope'] ?? '') === 'page') {
            return $this->preparePdfReportingSummary($remoteScan);
        }

        return $this->preparePdfReportingSummaryFromPageRows($remotePage, $pageIssueRows, $remoteScan);
    }

    /**
     * @param array<string, mixed> $remotePage
     * @param array<int, array<string, mixed>> $pageIssueRows
     * @param array<string, mixed>|null $remoteScan
     * @return array{reportSummary:array<string, mixed>,hasReportSummary:bool,priorityFixes:array<int, array<string, mixed>>,wcagSummary:array<int, array<string, mixed>>}
     */
    private function preparePdfReportingSummaryFromPageRows(array $remotePage, array $pageIssueRows, ?array $remoteScan): array
    {
        $rules = [];
        $criteria = [];
        $issuesTotal = 0;
        $overallImpact = '';
        $overallImpactWeight = 0;

        foreach ($pageIssueRows as $row) {
            $ruleId = trim((string)($row['rule_id'] ?? ''));
            if ($ruleId === '') {
                continue;
            }

            $impact = strtolower(trim((string)($row['impact'] ?? '')));
            $impactWeight = $this->impactWeightFromImpact($impact);
            $nodesCount = max(1, (int)($row['nodes_count'] ?? 1));
            $issuesTotal += $nodesCount;

            if ($impactWeight > $overallImpactWeight) {
                $overallImpact = $impact;
                $overallImpactWeight = $impactWeight;
            }

            if (!isset($rules[$ruleId])) {
                $wcag = $this->resolvePdfWcagByRuleId($ruleId);
                $rules[$ruleId] = [
                    'rank' => 0,
                    'ruleId' => $ruleId,
                    'wcagCriterion' => $wcag['criterion'] ?? null,
                    'wcagLevel' => $wcag['level'] ?? null,
                    'wcagLabel' => $wcag['label'] ?? null,
                    'impact' => $impact,
                    'impactWeight' => $impactWeight,
                    'issuesTotal' => 0,
                    'affectedPagesTotal' => 1,
                    'help' => trim((string)($row['title'] ?? $row['help'] ?? '')),
                    'guidanceWhyItMatters' => $row['guidanceWhyItMatters'] ?? null,
                    'guidanceHowToFix' => (bool)($row['guidanceIsFallback'] ?? false) ? null : ($row['guidanceHowToFix'] ?? null),
                    'guidanceIsFallback' => (bool)($row['guidanceIsFallback'] ?? true),
                    'whoShouldFix' => (string)($row['whoShouldFix'] ?? ''),
                    'whoShouldFixLabel' => (string)($row['whoShouldFixLabel'] ?? ''),
                    'fixType' => (string)($row['fixType'] ?? ''),
                    'fixTypeLabel' => (string)($row['fixTypeLabel'] ?? ''),
                    'confidence' => (string)($row['confidence'] ?? ''),
                    'confidenceLabel' => (string)($row['confidenceLabel'] ?? ''),
                ];
            }

            $rules[$ruleId]['issuesTotal'] += $nodesCount;
            if ($impactWeight > (int)$rules[$ruleId]['impactWeight']) {
                $rules[$ruleId]['impact'] = $impact;
                $rules[$ruleId]['impactWeight'] = $impactWeight;
            }

            if ((string)$rules[$ruleId]['help'] === '') {
                $rules[$ruleId]['help'] = trim((string)($row['title'] ?? $row['help'] ?? ''));
            }

            foreach (['guidanceWhyItMatters', 'guidanceHowToFix', 'whoShouldFix', 'whoShouldFixLabel', 'fixType', 'fixTypeLabel', 'confidence', 'confidenceLabel'] as $key) {
                if (in_array($rules[$ruleId][$key] ?? null, [null, ''], true) && isset($row[$key]) && trim((string)$row[$key]) !== '') {
                    $rules[$ruleId][$key] = $row[$key];
                }
            }

            $wcag = $this->resolvePdfWcagByRuleId($ruleId);
            $criterion = (string)($wcag['criterion'] ?? '');
            if ($criterion === '') {
                continue;
            }

            if (!isset($criteria[$criterion])) {
                $criteria[$criterion] = [
                    'criterion' => $criterion,
                    'level' => (string)($wcag['level'] ?? ''),
                    'label' => (string)($wcag['label'] ?? ''),
                    'issuesTotal' => 0,
                    'affectedPagesTotal' => 1,
                    'topRules' => [],
                ];
            }

            $criteria[$criterion]['issuesTotal'] += $nodesCount;
            if (!isset($criteria[$criterion]['topRules'][$ruleId])) {
                $criteria[$criterion]['topRules'][$ruleId] = [
                    'ruleId' => $ruleId,
                    'impact' => $impact,
                    'impactWeight' => $impactWeight,
                    'issuesTotal' => 0,
                ];
            }
            $criteria[$criterion]['topRules'][$ruleId]['issuesTotal'] += $nodesCount;
            if ($impactWeight > (int)$criteria[$criterion]['topRules'][$ruleId]['impactWeight']) {
                $criteria[$criterion]['topRules'][$ruleId]['impact'] = $impact;
                $criteria[$criterion]['topRules'][$ruleId]['impactWeight'] = $impactWeight;
            }
        }

        $priorityRaw = array_values($rules);
        usort(
            $priorityRaw,
            static fn(array $a, array $b): int => [
                (int)$b['impactWeight'],
                (int)$b['issuesTotal'],
                (string)$a['ruleId'],
            ] <=> [
                (int)$a['impactWeight'],
                (int)$a['issuesTotal'],
                (string)$b['ruleId'],
            ]
        );

        $rank = 1;
        foreach ($priorityRaw as &$rule) {
            $rule['rank'] = $rank++;
            unset($rule['impactWeight']);
        }
        unset($rule);

        $wcagRaw = array_values($criteria);
        foreach ($wcagRaw as &$criterion) {
            $topRules = array_values($criterion['topRules']);
            usort(
                $topRules,
                static fn(array $a, array $b): int => [
                    (int)$b['issuesTotal'],
                    (int)$b['impactWeight'],
                    (string)$a['ruleId'],
                ] <=> [
                    (int)$a['issuesTotal'],
                    (int)$a['impactWeight'],
                    (string)$b['ruleId'],
                ]
            );
            $criterion['topRules'] = array_map(
                static fn(array $rule): array => [
                    'ruleId' => (string)$rule['ruleId'],
                    'impact' => (string)$rule['impact'],
                    'issuesTotal' => (int)$rule['issuesTotal'],
                ],
                array_slice($topRules, 0, 5)
            );
        }
        unset($criterion);

        usort(
            $wcagRaw,
            static fn(array $a, array $b): int => [
                (int)$b['issuesTotal'],
                (string)$a['criterion'],
            ] <=> [
                (int)$a['issuesTotal'],
                (string)$b['criterion'],
            ]
        );

        $priorityFixes = $this->preparePdfPriorityFixes($priorityRaw);
        $wcagSummary = $this->preparePdfWcagSummary($wcagRaw);
        $pageTitle = trim((string)($remotePage['title'] ?? $remotePage['url'] ?? 'this page'));
        $firstFixTitle = trim((string)($priorityFixes[0]['title'] ?? $priorityFixes[0]['ruleId'] ?? 'the highest-impact rule'));
        $syntheticScan = is_array($remoteScan) ? $remoteScan : [];
        $syntheticScan['uid'] = max(1, (int)($remoteScan['uid'] ?? $remotePage['remote_scan'] ?? $remotePage['uid'] ?? 1));
        $syntheticScan['issues_total'] = $issuesTotal;
        $syntheticScan['pages_scanned'] = $issuesTotal > 0 ? 1 : 0;

        $reportSummary = $this->preparePdfReportSummary(
            [
                'overallImpact' => $overallImpact,
                'issuesTotal' => $issuesTotal,
                'affectedPagesTotal' => $issuesTotal > 0 ? 1 : 0,
                'wcagCriteriaTotal' => count($wcagSummary),
                'priorityFixesTotal' => count($priorityFixes),
                'topRecommendation' => $priorityFixes !== []
                    ? 'Start with ' . $firstFixTitle . ' on ' . $pageTitle . '.'
                    : 'No priority accessibility fixes were detected on this page.',
                'automatedCheckNotice' => self::PDF_REPORT_DISCLAIMER,
                'manualReviewNotice' => null,
            ],
            $syntheticScan,
            $priorityFixes,
            $wcagSummary
        );

        return [
            'reportSummary' => $reportSummary,
            'hasReportSummary' => $reportSummary !== [],
            'priorityFixes' => $priorityFixes,
            'wcagSummary' => $wcagSummary,
            'manualReviewChecklist' => [],
            'reportingGroups' => [],
            'keyboardSummary' => $this->preparePdfKeyboardSummaryFromPage($remotePage),
            'structureSummary' => [],
            'contrastDetails' => $this->collectContrastDetailsFromIssueRows($pageIssueRows),
            'remediationSummary' => $this->decodeRemediationSummary((string)($remotePage['remediation_summary_json'] ?? '')),
            'componentSummary' => [],
            'pageRecommendation' => $this->decodePageRecommendation((string)($remotePage['page_recommendation_json'] ?? '')),
        ];
    }


    /**
     * @param array<string, mixed>|null $remoteScan
     * @return array{reportSummary:array<string, mixed>,hasReportSummary:bool,priorityFixes:array<int, array<string, mixed>>,wcagSummary:array<int, array<string, mixed>>}
     */
    private function preparePdfReportingSummary(?array $remoteScan): array
    {
        $fallbackSummary = $this->remoteReportingSummaryService->buildForRemoteScan(
            $remoteScan,
            $this->remoteIssueRepository
        );

        $summary = $this->resolveApiReportingSummary($remoteScan, $fallbackSummary) ?? $fallbackSummary;
        $priorityFixes = $this->preparePdfPriorityFixes(
            is_array($summary['priorityFixes'] ?? null) ? $summary['priorityFixes'] : []
        );
        $wcagSummary = $this->preparePdfWcagSummary(
            is_array($summary['wcagSummary'] ?? null) ? $summary['wcagSummary'] : []
        );
        $reportSummary = $this->preparePdfReportSummary(
            is_array($summary['reportSummary'] ?? null) ? $summary['reportSummary'] : [],
            $remoteScan,
            $priorityFixes,
            $wcagSummary
        );
        $manualReviewChecklist = $this->preparePdfManualReviewChecklist(
            is_array($summary['manualReviewChecklist'] ?? null) ? $summary['manualReviewChecklist'] : []
        );
        $reportingGroups = $this->preparePdfReportingGroups(
            is_array($summary['reportingGroups'] ?? null) ? $summary['reportingGroups'] : []
        );
        $keyboardSummary = is_array($summary['keyboardSummary'] ?? null) ? $summary['keyboardSummary'] : [];
        $structureSummary = is_array($summary['structureSummary'] ?? null) ? $summary['structureSummary'] : [];
        $contrastDetails = is_array($summary['contrastDetails'] ?? null) ? $summary['contrastDetails'] : [];
        $remediationSummary = is_array($summary['remediationSummary'] ?? null) ? $summary['remediationSummary'] : [];
        $componentSummary = is_array($summary['componentSummary'] ?? null) ? $summary['componentSummary'] : [];
        $score = is_array($summary['score'] ?? null) ? $summary['score'] : [];

        return [
            'reportSummary' => $reportSummary,
            'hasReportSummary' => $reportSummary !== [],
            'priorityFixes' => $priorityFixes,
            'wcagSummary' => $wcagSummary,
            'manualReviewChecklist' => $manualReviewChecklist,
            'reportingGroups' => $reportingGroups,
            'keyboardSummary' => $keyboardSummary,
            'structureSummary' => $structureSummary,
            'contrastDetails' => $contrastDetails,
            'remediationSummary' => $remediationSummary,
            'componentSummary' => $componentSummary,
            'score' => $score,
            'pageRecommendation' => [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>>|mixed $contrastDetails
     * @return array{actualRatio:string,requiredRatio:string,foreground:string,background:string,preferredCandidate:string,preferredEstimatedRatio:string,suggestedForegroundCandidates:string,suggestedBackgroundCandidates:string,candidateNote:string}
     */
    private function buildContrastCsvColumns(mixed $contrastDetails): array
    {
        $empty = [
            'actualRatio' => '',
            'requiredRatio' => '',
            'foreground' => '',
            'background' => '',
            'preferredCandidate' => '',
            'preferredEstimatedRatio' => '',
            'suggestedForegroundCandidates' => '',
            'suggestedBackgroundCandidates' => '',
            'candidateNote' => '',
        ];

        if (!is_array($contrastDetails) || $contrastDetails === []) {
            return $empty;
        }

        $first = null;
        foreach ($contrastDetails as $item) {
            if (is_array($item)) {
                $first = $item;
                break;
            }
        }

        if ($first === null) {
            return $empty;
        }

        $suggestion = is_array($first['contrastSuggestion'] ?? null) ? $first['contrastSuggestion'] : [];
        $preferredCandidate = is_array($suggestion['preferredCandidate'] ?? null) ? $suggestion['preferredCandidate'] : [];

        return [
            'actualRatio' => (string)($first['actualRatio'] ?? $suggestion['actualRatio'] ?? ''),
            'requiredRatio' => (string)($first['requiredRatio'] ?? $suggestion['requiredRatio'] ?? ''),
            'foreground' => (string)($first['foreground'] ?? $suggestion['currentForeground'] ?? ''),
            'background' => (string)($first['background'] ?? $suggestion['currentBackground'] ?? ''),
            'preferredCandidate' => (string)($preferredCandidate['color'] ?? ''),
            'preferredEstimatedRatio' => (string)($preferredCandidate['estimatedRatio'] ?? ''),
            'suggestedForegroundCandidates' => $this->formatCandidateDetailsForCsv($suggestion['suggestedForegroundCandidateDetails'] ?? $suggestion['suggestedForegroundCandidates'] ?? []),
            'suggestedBackgroundCandidates' => $this->formatCandidateDetailsForCsv($suggestion['suggestedBackgroundCandidateDetails'] ?? $suggestion['suggestedBackgroundCandidates'] ?? []),
            'candidateNote' => trim((string)($suggestion['note'] ?? $suggestion['reviewHint'] ?? '')),
        ];
    }

    private function formatCandidateDetailsForCsv(mixed $items): string
    {
        if (!is_array($items)) {
            return '';
        }

        $labels = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $color = $this->normalizeColorValue($item['color'] ?? $item['candidate'] ?? $item['value'] ?? $item['hex'] ?? null);
                if ($color === null) {
                    continue;
                }
                $estimatedRatio = $this->normalizeNullableString($item['estimatedRatio'] ?? $item['estimated_ratio'] ?? $item['ratio'] ?? null);
                $apiLabel = $this->normalizeNullableString($item['label'] ?? null);
                $explanation = $this->normalizeNullableString($item['explanation'] ?? $item['reason'] ?? null);
                $label = $apiLabel ?? ($estimatedRatio !== null ? $color . ' (estimated contrast ratio ' . $estimatedRatio . ')' : $color);
                if ($explanation !== null) {
                    $label .= ' — ' . $explanation;
                }
                $labels[] = $label;
                continue;
            }

            $color = $this->normalizeColorValue($item);
            if ($color !== null) {
                $labels[] = $color;
            }
        }

        return implode(', ', array_values(array_unique($labels)));
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
            'quickWinsLabel' => $quickWinsTotal > 0 ? $this->countLabel($quickWinsTotal, 'quick win') : '',
            'templateIssuesLabel' => $templateIssuesTotal > 0 ? $this->countLabel($templateIssuesTotal, 'template issue') : '',
            'contentIssuesLabel' => $contentIssuesTotal > 0 ? $this->countLabel($contentIssuesTotal, 'content issue') : '',
            'designIssuesLabel' => $designIssuesTotal > 0 ? $this->countLabel($designIssuesTotal, 'design issue') : '',
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
            'countLabel' => $count !== null ? $this->countLabel($count, 'item') : '',
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

        // Older runtime rows and some API/debug payloads can contain one
        // contrast detail object instead of a list of detail objects. Treat
        // that shape as a single item so page detail/PDF stays backwards
        // compatible and does not silently hide contrastSuggestion data.
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
            'issuesLabel' => $this->countLabel($issuesTotal, 'issue'),
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
            'currentForegroundSwatch' => $this->normalizeHexSwatchColor($currentForeground),
            'currentBackground' => $currentBackground,
            'currentBackgroundSwatch' => $this->normalizeHexSwatchColor($currentBackground),
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
                'swatchColor' => $this->normalizeHexSwatchColor($color),
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
            'swatchColor' => $this->normalizeHexSwatchColor($color),
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

    private function normalizeHexSwatchColor(?string $value): ?string
    {
        if ($value === null || !preg_match('/^#[0-9a-fA-F]{3,8}$/', $value)) {
            return null;
        }

        return strtolower($value);
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

    /**
     * @param array<string, mixed> $remotePage
     * @return array<string, mixed>
     */
    private function preparePdfKeyboardSummaryFromPage(array $remotePage): array
    {
        $json = trim((string)($remotePage['keyboard_summary_json'] ?? ''));
        if ($json === '') {
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

        return $this->normalizeKeyboardSummaryForPdf($decoded);
    }

    /**
     * @param array<string, mixed> $keyboardSummary
     * @return array<string, mixed>
     */
    private function normalizeKeyboardSummaryForPdf(array $keyboardSummary): array
    {
        if ($keyboardSummary === []) {
            return [];
        }

        $tested = (bool)($keyboardSummary['tested'] ?? false);
        $possibleKeyboardTrap = (bool)($keyboardSummary['possibleKeyboardTrap'] ?? $keyboardSummary['possible_keyboard_trap'] ?? false);
        $manualReviewRequired = (bool)($keyboardSummary['manualReviewRequired'] ?? $keyboardSummary['manual_review_required'] ?? true);
        $invisibleFocusIssuesTotal = max(0, (int)($keyboardSummary['invisibleFocusIssuesTotal'] ?? $keyboardSummary['invisible_focus_issues_total'] ?? 0));

        return [
            'available' => true,
            'tested' => $tested,
            'focusStepsTotal' => max(0, (int)($keyboardSummary['focusStepsTotal'] ?? $keyboardSummary['focus_steps_total'] ?? 0)),
            'uniqueFocusedElementsTotal' => max(0, (int)($keyboardSummary['uniqueFocusedElementsTotal'] ?? $keyboardSummary['unique_focused_elements_total'] ?? 0)),
            'possibleKeyboardTrap' => $possibleKeyboardTrap,
            'possibleKeyboardTrapLabel' => $possibleKeyboardTrap ? 'Possible keyboard trap' : 'No trap signal',
            'invisibleFocusIssuesTotal' => $invisibleFocusIssuesTotal,
            'invisibleFocusIssuesLabel' => $this->countLabel($invisibleFocusIssuesTotal, 'invisible focus issue'),
            'manualReviewRequired' => $manualReviewRequired,
            'manualReviewLabel' => $manualReviewRequired ? 'Manual review required' : 'Manual review still recommended',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $pageIssueRows
     * @return array<int, array<string, mixed>>
     */
    private function collectContrastDetailsFromIssueRows(array $pageIssueRows): array
    {
        $details = [];
        foreach ($pageIssueRows as $issueRow) {
            foreach (($issueRow['nodes'] ?? []) as $node) {
                if (!is_array($node) || !is_array($node['contrastDetails'] ?? null)) {
                    continue;
                }
                foreach ($node['contrastDetails'] as $detail) {
                    if (is_array($detail)) {
                        $details[] = $detail;
                    }
                }
            }
        }

        return array_slice($details, 0, 5);
    }

    /**
     * @param array<string, mixed>|null $remoteScan
     * @param array<string, mixed> $fallbackSummary
     * @return array<string, mixed>|null
     */
    private function resolveApiReportingSummary(?array $remoteScan, array $fallbackSummary): ?array
    {
        $jobId = trim((string)($remoteScan['job_id'] ?? ''));
        $siteBase = trim((string)($remoteScan['start_url'] ?? ''));
        if ($jobId === '' || $siteBase === '') {
            return null;
        }

        try {
            $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase($siteBase);
            if ($domain === '') {
                return null;
            }

            $summaryResult = $this->proCrawlerService->getSummary(
                domain: $domain,
                version: $this->extensionContextService->getExtensionVersion(),
                jobId: $jobId,
            );
        } catch (\Throwable) {
            return null;
        }

        return $this->remoteReportingSummaryService->buildFromApiSummaryWithFallback(
            $summaryResult->wcagSummary,
            $summaryResult->priorityFixes,
            $fallbackSummary,
            $summaryResult->reportSummary,
            $summaryResult->manualReviewChecklist,
            $summaryResult->reportingGroups,
            $summaryResult->score,
            $summaryResult->keyboardSummary,
            $summaryResult->structureSummary,
            $summaryResult->contrastDetails,
            $summaryResult->remediationSummary,
            $summaryResult->componentSummary
        );
    }

    /**
     * @param array<string, mixed> $reportSummary
     * @param array<string, mixed>|null $remoteScan
     * @param array<int, array<string, mixed>> $priorityFixes
     * @param array<int, array<string, mixed>> $wcagSummary
     * @return array<string, mixed>
     */
    private function preparePdfReportSummary(
        array $reportSummary,
        ?array $remoteScan,
        array $priorityFixes,
        array $wcagSummary,
    ): array {
        if (!is_array($remoteScan) || (int)($remoteScan['uid'] ?? 0) <= 0) {
            return [];
        }

        $scanIssuesTotal = max(0, (int)($remoteScan['issues_total'] ?? 0));
        $issuesTotal = $scanIssuesTotal > 0
            ? $scanIssuesTotal
            : max(0, (int)($reportSummary['issuesTotal'] ?? $reportSummary['issues_total'] ?? 0));
        $fallbackAffectedPagesTotal = $this->maxAffectedPagesTotal($priorityFixes, $wcagSummary);
        if ($fallbackAffectedPagesTotal <= 0) {
            $fallbackAffectedPagesTotal = max(0, (int)($remoteScan['pages_scanned'] ?? 0));
        }
        $affectedPagesTotal = max(0, (int)($reportSummary['affectedPagesTotal'] ?? $reportSummary['affected_pages_total'] ?? $fallbackAffectedPagesTotal));
        $wcagCriteriaTotal = max(0, (int)($reportSummary['wcagCriteriaTotal'] ?? $reportSummary['wcag_criteria_total'] ?? count($wcagSummary)));
        $priorityFixesTotal = max(0, (int)($reportSummary['priorityFixesTotal'] ?? $reportSummary['priority_fixes_total'] ?? count($priorityFixes)));
        $overallImpact = strtolower(trim((string)($reportSummary['overallImpact'] ?? $reportSummary['overall_impact'] ?? '')));
        if ($overallImpact === '' && isset($priorityFixes[0]['impact'])) {
            $overallImpact = strtolower(trim((string)$priorityFixes[0]['impact']));
        }
        $overallImpactLabel = $overallImpact !== '' ? $this->formatBadgeLabel($overallImpact) : 'Not rated';

        $topRecommendation = $this->normalizeNullableString($reportSummary['topRecommendation'] ?? $reportSummary['top_recommendation'] ?? null);
        if ($topRecommendation === null && $priorityFixes !== []) {
            $firstFix = $priorityFixes[0];
            $firstTitle = trim((string)($firstFix['title'] ?? $firstFix['ruleId'] ?? 'the highest-impact rule'));
            $topRecommendation = 'Start with ' . $firstTitle . ' because it has the highest impact and reach in this scan.';
        }
        if ($topRecommendation === null && $issuesTotal === 0) {
            $topRecommendation = 'No priority accessibility fixes were detected in this remote scan.';
        }

        $automatedCheckNotice = $this->normalizeNullableString($reportSummary['automatedCheckNotice'] ?? $reportSummary['automated_check_notice'] ?? null)
            ?? self::PDF_REPORT_DISCLAIMER;
        $manualReviewNotice = $this->normalizeNullableString($reportSummary['manualReviewNotice'] ?? $reportSummary['manual_review_notice'] ?? null);

        return [
            'overallImpact' => $overallImpact,
            'overallImpactLabel' => $overallImpactLabel,
            'overallImpactTone' => $this->toneFromImpact($overallImpact),
            'issuesTotal' => $issuesTotal,
            'issuesLabel' => $this->countLabel($issuesTotal, 'issue'),
            'affectedPagesTotal' => $affectedPagesTotal,
            'affectedPagesLabel' => $this->countLabel($affectedPagesTotal, 'affected page'),
            'wcagCriteriaTotal' => $wcagCriteriaTotal,
            'wcagCriteriaLabel' => $this->countLabel($wcagCriteriaTotal, 'WCAG criterion', 'WCAG criteria'),
            'priorityFixesTotal' => $priorityFixesTotal,
            'priorityFixesLabel' => $this->countLabel($priorityFixesTotal, 'priority fix', 'priority fixes'),
            'topRecommendation' => $topRecommendation,
            'automatedCheckNotice' => $automatedCheckNotice,
            'manualReviewNotice' => $manualReviewNotice,
        ];
    }


    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function preparePdfManualReviewChecklist(array $items): array
    {
        $prepared = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = $this->normalizeNullableString($item['title'] ?? null);
            if ($title === null) {
                continue;
            }

            $owner = $this->normalizeMachineValue($item['recommendedOwner'] ?? $item['recommended_owner'] ?? null);
            $status = $this->normalizeMachineValue($item['status'] ?? null);
            if ($status === '') {
                $status = 'needs_review';
            }
            $category = $this->normalizeMachineValue($item['category'] ?? null);

            $prepared[] = [
                'id' => $this->normalizeMachineValue($item['id'] ?? $title),
                'title' => $title,
                'description' => $this->normalizeNullableString($item['description'] ?? null),
                'category' => $category,
                'categoryLabel' => $this->formatBadgeLabel($category),
                'recommendedOwner' => $owner,
                'recommendedOwnerLabel' => $this->formatBadgeLabel($owner),
                'status' => $status,
                'statusLabel' => $status === 'needs_review' ? 'Needs review' : $this->formatBadgeLabel($status),
            ];
        }

        return $prepared;
    }

    /**
     * @param array<string, mixed> $reportingGroups
     * @return array<string, mixed>
     */
    private function preparePdfReportingGroups(array $reportingGroups): array
    {
        $rawGroups = is_array($reportingGroups['groups'] ?? null) ? $reportingGroups['groups'] : [];
        if ($rawGroups === [] && $this->normalizeNullableString($reportingGroups['disclaimer'] ?? null) === null) {
            return [];
        }

        $groups = [];
        foreach ($rawGroups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $title = $this->normalizeNullableString($group['title'] ?? null);
            if ($title === null) {
                continue;
            }

            $criteria = [];
            foreach ((array)($group['wcagCriteria'] ?? $group['wcag_criteria'] ?? []) as $criterion) {
                $criterion = $this->normalizeNullableString($criterion);
                if ($criterion !== null) {
                    $criteria[] = $criterion;
                }
            }

            $issuesTotal = max(0, (int)($group['automatedIssuesTotal'] ?? $group['automated_issues_total'] ?? 0));
            $manualReviewRequired = (bool)($group['manualReviewRequired'] ?? $group['manual_review_required'] ?? false);

            $groups[] = [
                'groupId' => $this->normalizeMachineValue($group['groupId'] ?? $group['group_id'] ?? null),
                'title' => $title,
                'automatedIssuesTotal' => $issuesTotal,
                'automatedIssuesLabel' => $this->countLabel($issuesTotal, 'mapped issue', 'mapped issues'),
                'wcagCriteria' => $criteria,
                'wcagCriteriaText' => $criteria !== [] ? implode(', ', $criteria) : 'None mapped',
                'manualReviewRequired' => $manualReviewRequired,
                'manualReviewLabel' => $manualReviewRequired ? 'Manual review required' : '',
            ];
        }

        $automatedIssuesTotal = max(0, (int)($reportingGroups['automatedIssuesTotal'] ?? $reportingGroups['automated_issues_total'] ?? 0));

        return [
            'standard' => $this->normalizeMachineValue($reportingGroups['standard'] ?? null),
            'standardLabel' => strtoupper($this->normalizeMachineValue($reportingGroups['standard'] ?? 'wcag')),
            'title' => 'WCAG / BFSG / BITV reporting aid',
            'disclaimer' => $this->normalizeNullableString($reportingGroups['disclaimer'] ?? null),
            'manualReviewRequired' => (bool)($reportingGroups['manualReviewRequired'] ?? $reportingGroups['manual_review_required'] ?? false),
            'manualReviewLabel' => (bool)($reportingGroups['manualReviewRequired'] ?? $reportingGroups['manual_review_required'] ?? false) ? 'Manual review required' : '',
            'automatedIssuesTotal' => $automatedIssuesTotal,
            'automatedIssuesLabel' => $this->countLabel($automatedIssuesTotal, 'WCAG-mapped automated finding', 'WCAG-mapped automated findings'),
            'groups' => $groups,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function preparePdfPriorityFixes(array $items): array
    {
        $prepared = [];

        foreach (array_slice($items, 0, 10) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $ruleId = trim((string)($item['ruleId'] ?? $item['rule_id'] ?? ''));
            if ($ruleId === '') {
                continue;
            }

            $help = trim((string)($item['help'] ?? ''));
            $impact = strtolower(trim((string)($item['impact'] ?? '')));
            $issuesTotal = max(0, (int)($item['issuesTotal'] ?? $item['issues_total'] ?? 0));
            $affectedPagesTotal = max(0, (int)($item['affectedPagesTotal'] ?? $item['affected_pages_total'] ?? 0));
            $wcagCriterion = $this->normalizeNullableString($item['wcagCriterion'] ?? $item['criterion'] ?? null);
            $wcagLevel = $this->normalizeNullableString($item['wcagLevel'] ?? $item['level'] ?? null);
            $wcagLabel = $this->normalizeNullableString($item['wcagLabel'] ?? $item['label'] ?? null);
            $guidance = is_array($item['guidance'] ?? null) ? $item['guidance'] : [];
            $guidanceHowToFix = $this->normalizeNullableString(
                $item['guidanceHowToFix']
                ?? $item['howToFix']
                ?? $item['how_to_fix']
                ?? $guidance['howToFix']
                ?? $guidance['how_to_fix']
                ?? null
            );
            $guidanceWhyItMatters = $this->normalizeNullableString(
                $item['guidanceWhyItMatters']
                ?? $item['whyItMatters']
                ?? $item['why_it_matters']
                ?? $guidance['whyItMatters']
                ?? $guidance['why_it_matters']
                ?? null
            );
            $guidanceIsFallback = (bool)($item['guidanceIsFallback'] ?? false);

            if ($guidanceHowToFix === null) {
                $guidanceHowToFix = 'Review this finding in context.';
                $guidanceIsFallback = true;
            }

            $prepared[] = [
                'rank' => max(1, (int)($item['rank'] ?? count($prepared) + 1)),
                'ruleId' => $ruleId,
                'title' => $help !== '' ? $help : $this->humanizeRuleId($ruleId),
                'help' => $help,
                'impact' => $impact,
                'impactLabel' => $impact !== '' ? $this->formatBadgeLabel($impact) : 'Not rated',
                'tone' => $this->toneFromImpact($impact),
                'wcagCriterion' => $wcagCriterion,
                'wcagLevel' => $wcagLevel,
                'wcagLabel' => $wcagLabel,
                'wcagText' => $wcagCriterion !== null ? trim('WCAG ' . $wcagCriterion . ' ' . ($wcagLevel ?? '')) : '',
                'issuesTotal' => $issuesTotal,
                'issuesLabel' => $this->countLabel($issuesTotal, 'issue'),
                'affectedPagesTotal' => $affectedPagesTotal,
                'affectedPagesLabel' => $this->countLabel($affectedPagesTotal, 'affected page'),
                'whoShouldFix' => trim((string)($item['whoShouldFix'] ?? '')),
                'whoShouldFixLabel' => trim((string)($item['whoShouldFixLabel'] ?? '')),
                'fixType' => trim((string)($item['fixType'] ?? '')),
                'fixTypeLabel' => trim((string)($item['fixTypeLabel'] ?? '')),
                'confidence' => trim((string)($item['confidence'] ?? '')),
                'confidenceLabel' => trim((string)($item['confidenceLabel'] ?? '')),
                'guidanceWhyItMatters' => $guidanceWhyItMatters,
                'guidanceHowToFix' => $guidanceHowToFix,
                'guidanceIsFallback' => $guidanceIsFallback,
                'guidanceHeading' => $guidanceIsFallback ? 'Review needed' : 'How to fix',
                'reason' => trim((string)($item['reason'] ?? '')),
            ];
        }

        return $prepared;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function preparePdfWcagSummary(array $rows): array
    {
        $prepared = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $criterion = trim((string)($row['criterion'] ?? $row['wcagCriterion'] ?? ''));
            if ($criterion === '') {
                continue;
            }

            $issuesTotal = max(0, (int)($row['issuesTotal'] ?? $row['issues_total'] ?? 0));
            $affectedPagesTotal = max(0, (int)($row['affectedPagesTotal'] ?? $row['affected_pages_total'] ?? 0));
            $topRules = [];
            foreach ((array)($row['topRules'] ?? $row['top_rules'] ?? []) as $rule) {
                if (!is_array($rule)) {
                    $ruleId = trim((string)$rule);
                    if ($ruleId !== '') {
                        $topRules[] = ['label' => $ruleId];
                    }
                    continue;
                }

                $ruleId = trim((string)($rule['ruleId'] ?? $rule['rule_id'] ?? ''));
                if ($ruleId === '') {
                    continue;
                }
                $ruleIssues = max(0, (int)($rule['issuesTotal'] ?? $rule['issues_total'] ?? 0));
                $topRules[] = [
                    'ruleId' => $ruleId,
                    'impact' => strtolower(trim((string)($rule['impact'] ?? ''))),
                    'issuesTotal' => $ruleIssues,
                    'label' => $ruleIssues > 0 ? $ruleId . ' ×' . $ruleIssues : $ruleId,
                ];
            }

            $prepared[] = [
                'criterion' => $criterion,
                'level' => strtoupper(trim((string)($row['level'] ?? ''))),
                'label' => trim((string)($row['label'] ?? '')),
                'issuesTotal' => $issuesTotal,
                'issuesLabel' => $this->countLabel($issuesTotal, 'issue'),
                'affectedPagesTotal' => $affectedPagesTotal,
                'affectedPagesLabel' => $this->countLabel($affectedPagesTotal, 'affected page'),
                'topRules' => $topRules,
            ];
        }

        return $prepared;
    }



    /**
     * @param array<int, array<string, mixed>> $priorityFixes
     * @param array<int, array<string, mixed>> $wcagSummary
     */
    private function maxAffectedPagesTotal(array $priorityFixes, array $wcagSummary): int
    {
        $max = 0;
        foreach (array_merge($priorityFixes, $wcagSummary) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $max = max($max, (int)($item['affectedPagesTotal'] ?? $item['affected_pages_total'] ?? 0));
        }

        return $max;
    }


    private function impactWeightFromImpact(string $impact): int
    {
        return match (strtolower(trim($impact))) {
            'critical' => 4,
            'serious' => 3,
            'moderate' => 2,
            'minor' => 1,
            default => 0,
        };
    }

    /**
     * @return array{criterion:string,level:string,label:string}|null
     */
    private function resolvePdfWcagByRuleId(string $ruleId): ?array
    {
        $map = [
            'image-alt' => ['1.1.1', 'A', 'Non-text Content'],
            'button-name' => ['4.1.2', 'A', 'Name, Role, Value'],
            'select-name' => ['4.1.2', 'A', 'Name, Role, Value'],
            'frame-title' => ['4.1.2', 'A', 'Name, Role, Value'],
            'link-name' => ['2.4.4', 'A', 'Link Purpose (In Context)'],
            'label' => ['1.3.1', 'A', 'Info and Relationships'],
            'marquee' => ['2.2.2', 'A', 'Pause, Stop, Hide'],
            'color-contrast' => ['1.4.3', 'AA', 'Contrast (Minimum)'],
            'document-title' => ['2.4.2', 'A', 'Page Titled'],
            'html-has-lang' => ['3.1.1', 'A', 'Language of Page'],
        ];

        $normalized = strtolower(trim($ruleId));
        if (!isset($map[$normalized])) {
            return null;
        }

        [$criterion, $level, $label] = $map[$normalized];

        return [
            'criterion' => $criterion,
            'level' => $level,
            'label' => $label,
        ];
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

    private function toneFromImpact(string $impact): string
    {
        return match (strtolower(trim($impact))) {
            'critical', 'serious' => 'critical',
            'moderate' => 'warning',
            'minor' => 'info',
            default => 'none',
        };
    }

    private function countLabel(int $count, string $singular, ?string $plural = null): string
    {
        return $count . ' ' . ($count === 1 ? $singular : ($plural ?? $singular . 's'));
    }

    private function formatPdfDate(?int $timestamp = null): string
    {
        $date = new \DateTimeImmutable('@' . (string)($timestamp ?? time()));
        return $date
            ->setTimezone(new \DateTimeZone('Europe/Bratislava'))
            ->format('d M Y · H:i T');
    }

    private function formatPdfDateFromMixed(mixed $value): string
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $timestamp = (int)$value;
            return $timestamp > 0 ? $this->formatPdfDate($timestamp) : '—';
        }

        if (is_string($value) && trim($value) !== '') {
            $timestamp = strtotime($value);
            return $timestamp !== false ? $this->formatPdfDate($timestamp) : trim($value);
        }

        return '—';
    }

    /**
     * @param array<string, mixed> $scan
     */
    private function buildRuntimeLabel(array $scan): string
    {
        $started = $this->timestampFromMixed($scan['started_at'] ?? null);
        $finished = $this->timestampFromMixed($scan['finished_at'] ?? null);

        if ($started <= 0 || $finished <= 0 || $finished < $started) {
            return 'runtime not available';
        }

        $seconds = $finished - $started;
        if ($seconds < 60) {
            return $seconds . ' sec runtime';
        }

        $minutes = (int)floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        return $minutes . ' min ' . str_pad((string)$remainingSeconds, 2, '0', STR_PAD_LEFT) . ' sec runtime';
    }

    private function timestampFromMixed(mixed $value): int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int)$value;
        }

        if (is_string($value) && trim($value) !== '') {
            $timestamp = strtotime($value);
            return $timestamp !== false ? $timestamp : 0;
        }

        return 0;
    }

    private function pluralize(string $singular, int $count): string
    {
        return $count === 1 ? $singular : $singular . 's';
    }

    private function humanizeRuleId(string $ruleId): string
    {
        $ruleId = preg_replace('/^(axe|remote|rte|structured)\./', '', $ruleId) ?? $ruleId;
        $ruleId = str_replace(['_', '-'], ' ', $ruleId);
        $ruleId = trim($ruleId);

        return $ruleId !== '' ? ucfirst($ruleId) : 'Accessibility rule';
    }

    private function severityWeight(string $tone): int
    {
        return match ($tone) {
            'critical' => 3,
            'warning' => 2,
            'info' => 1,
            default => 0,
        };
    }

    private function httpTone(int $status): string
    {
        if ($status >= 200 && $status < 300) {
            return 'ok';
        }
        if ($status >= 300 && $status < 400) {
            return '3xx';
        }
        if ($status >= 400 && $status < 500) {
            return '4xx';
        }
        if ($status >= 500) {
            return '5xx';
        }

        return 'none';
    }


    private function relativePathFromUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return 'remote page';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        $path = (string)($parts['path'] ?? '/');
        $query = (string)($parts['query'] ?? '');
        $relative = $path !== '' ? $path : '/';

        if ($query !== '') {
            $relative .= '?' . $query;
        }

        return $relative;
    }

    private function stripSchemeFromUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        return (string)preg_replace('#^https?://#i', '', $url);
    }

    private function resolveRootUrl(string $url, string $fallback): string
    {
        $url = trim($url);
        if ($url !== '') {
            $parts = parse_url($url);
            if (is_array($parts) && isset($parts['host'])) {
                $scheme = isset($parts['scheme']) && is_string($parts['scheme']) && $parts['scheme'] !== ''
                    ? $parts['scheme']
                    : 'https';
                return $scheme . '://' . $parts['host'];
            }
        }

        return trim($fallback) !== '' ? trim($fallback) : 'Remote scan';
    }


    /**
     * @return array{html:string,meta:string,imageVars:array<string,string>}
     */
    private function buildScreenshotNotIncludedBlock(): array
    {
        return [
            'html' => '<div class="aqgp-screenshot-placeholder">'
                . '<strong>Screenshot not included in export.</strong><br />'
                . 'File size exceeded limit or screenshot unavailable.'
                . '</div>',
            'meta' => 'not included',
            'imageVars' => [],
        ];
    }

    /**
     * @return array{html:string,meta:string,imageVars:array<string,string>}
     */
    private function buildScreenshotBlock(int $remotePageUid): array
    {
        if ($remotePageUid <= 0) {
            return $this->buildScreenshotNotIncludedBlock();
        }

        try {
            $image = $this->remoteScreenshotService->fetchScreenshotByRemotePageUid($remotePageUid);
        } catch (\Throwable) {
            return $this->buildScreenshotNotIncludedBlock();
        }

        if (!is_array($image)) {
            return $this->buildScreenshotNotIncludedBlock();
        }

        $content = $image['content'] ?? null;
        if (!is_string($content) || $content === '') {
            return $this->buildScreenshotNotIncludedBlock();
        }

        $optimized = $this->optimizeScreenshotForPdf($content);
        if ($optimized === null) {
            return $this->buildScreenshotNotIncludedBlock();
        }

        $varName = 'aqgRemotePageScreenshot' . $remotePageUid;

        return [
            'html' => '<div class="aqgp-screenshot">'
                . '<img src="var:' . htmlspecialchars($varName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="Remote page screenshot" class="aqgp-screenshot__image" />'
                . '</div>',
            'meta' => $optimized['meta'],
            'imageVars' => [
                $varName => $optimized['content'],
            ],
        ];
    }

    /**
     * @return array{content:string,meta:string}|null
     */
    private function optimizeScreenshotForPdf(string $content): ?array
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return null;
        }

        $imageInfo = @getimagesizefromstring($content);
        if (!is_array($imageInfo) || !isset($imageInfo[0], $imageInfo[1])) {
            return null;
        }

        $originalWidth = max(1, (int)$imageInfo[0]);
        $originalHeight = max(1, (int)$imageInfo[1]);
        $source = @imagecreatefromstring($content);
        if ($source === false) {
            return null;
        }

        $targetWidth = min($originalWidth, self::PDF_SCREENSHOT_MAX_WIDTH);
        $targetHeight = (int)max(1, round($originalHeight * ($targetWidth / $originalWidth)));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($target === false) {
            imagedestroy($source);
            return null;
        }

        $white = imagecolorallocate($target, 255, 255, 255);
        if ($white !== false) {
            imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $white);
        }

        $resampled = imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $originalWidth,
            $originalHeight
        );
        imagedestroy($source);

        if (!$resampled) {
            imagedestroy($target);
            return null;
        }

        ob_start();
        $encoded = imagejpeg($target, null, self::PDF_SCREENSHOT_JPEG_QUALITY);
        $jpeg = ob_get_clean();
        imagedestroy($target);

        if (!$encoded || !is_string($jpeg) || $jpeg === '' || strlen($jpeg) > self::PDF_SCREENSHOT_MAX_BYTES) {
            return null;
        }

        return [
            'content' => $jpeg,
            'meta' => $targetWidth . ' × ' . $targetHeight . ' · optimized JPEG',
        ];
    }

}
