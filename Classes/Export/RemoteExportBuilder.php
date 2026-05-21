<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Export;

use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueNodeRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Service\RemoteScreenshotService;
use Psr\Http\Message\ServerRequestInterface;

final class RemoteExportBuilder
{
    private const PDF_SCREENSHOT_MAX_BYTES = 20971520;

    public function __construct(
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly RemoteIssueRepository $remoteIssueRepository,
        private readonly RemoteIssueNodeRepository $remoteIssueNodeRepository,
        private readonly RemoteScreenshotService $remoteScreenshotService,
        private readonly PdfGenerator $pdfGenerator,
        private readonly PdfTemplateRenderer $pdfTemplateRenderer,
    ) {
    }

    public function buildOverviewCsv(string $siteIdentifier): string
    {
        $scan = $this->remoteScanRepository->findLastCompletedSiteScanBySite($siteIdentifier);
        if (!is_array($scan) || !isset($scan['uid'])) {
            return '';
        }

        $pages = $this->remoteScanRepository->findPagesForScan((int)$scan['uid']);
        $failedPages = $this->remoteScanRepository->findFailedPagesForScan((int)$scan['uid']);

        $output = fopen('php://memory', 'r+b');
        if ($output === false) {
            return '';
        }

        fputcsv($output, [
            'URL',
            'Page title',
            'HTTP status',
            'Issues',
            'Failed',
            'Failure reason',
        ], ';');

        foreach ($pages as $page) {
            fputcsv($output, [
                (string)($page['url'] ?? ''),
                (string)($page['title'] ?? ''),
                (int)($page['http_status'] ?? 0),
                (int)($page['issues_count'] ?? 0),
                0,
                '',
            ], ';');
        }

        foreach ($failedPages as $page) {
            fputcsv($output, [
                (string)($page['url'] ?? ''),
                (string)($page['title'] ?? ''),
                (int)($page['http_status'] ?? 0),
                (int)($page['issues_count'] ?? 0),
                1,
                (string)($page['failure_reason'] ?? ''),
            ], ';');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv ?: '';
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
        ], ';');

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
                ], ';');

                continue;
            }

            foreach ($row['nodes'] as $node) {
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
                ], ';');
            }
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv ?: '';
    }

    public function buildOverviewPdf(string $siteIdentifier, ?ServerRequestInterface $request = null): string
    {
        $scan = $this->remoteScanRepository->findLastCompletedSiteScanBySite($siteIdentifier);
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
                ],
                request: $request,
            );

            return $this->pdfGenerator->render(
                html: $html,
                title: 'AQG',
            );
        }

        $issues = $this->preparePageIssueRows($remotePageUid);
        $issueCount = count($issues);
        $nodesCount = array_sum(array_map(static fn(array $issue): int => (int)$issue['nodes_count'], $issues));
        $httpStatus = (int)($remotePage['http_status'] ?? 0);
        $failureReason = trim((string)($remotePage['failure_reason'] ?? ''));
        $pageUrl = (string)($remotePage['url'] ?? '');
        $siteLabel = $this->resolveRootUrl(
            $pageUrl,
            (string)($remotePage['site_identifier'] ?? $remotePage['site'] ?? 'Remote scan')
        );
        $relativeUrl = $this->relativePathFromUrl($pageUrl);
        $screenshot = $this->buildScreenshotBlock($remotePageUid);

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
                'issuesFoundLabel' => $issueCount . ' ' . $this->pluralize('rule', $issueCount) . ' · ' . $nodesCount . ' ' . $this->pluralize('node', $nodesCount),
                'issuesShownLabel' => $issueCount > 0 ? 'showing ' . $issueCount . ' of ' . $issueCount . ' rules' : '0 matching issues',
                'screenshotAvailable' => $screenshot['html'] !== '',
                'screenshotPlaceholder' => $screenshot['html'],
                'screenshotMeta' => $screenshot['meta'],
                'hasFailure' => $failureReason !== '' || $httpStatus >= 400,
                'failureReason' => $failureReason !== '' ? $failureReason : 'The crawler reached this URL but the HTTP status indicates a failed request.',
                'httpTone' => $this->httpTone($httpStatus),
                'httpStatusLabel' => $httpStatus > 0 ? (string)$httpStatus : 'not available',
                'scanCompletedAt' => $this->formatPdfDateFromMixed($remotePage['scan_completed_at'] ?? $remotePage['finished_at'] ?? $remotePage['tstamp'] ?? null),
            ],
            request: $request,
        );

        return $this->pdfGenerator->render(
            html: $html,
            title: 'AQG',
            imageVars: $screenshot['imageVars'],
        );
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
        $rows = [];
        $index = 1;

        foreach ($issues as $issue) {
            $issueUid = (int)($issue['uid'] ?? 0);
            $nodes = $issueUid > 0
                ? $this->remoteIssueNodeRepository->findByRemoteIssue($issueUid)
                : [];
            $impact = strtolower(trim((string)($issue['impact'] ?? '')));
            $tone = match ($impact) {
                'critical', 'serious' => 'critical',
                'moderate' => 'warning',
                default => 'info',
            };
            $nodesCount = max((int)($issue['nodes_count'] ?? 0), count($nodes));
            $preparedNodes = [];
            $nodeIndex = 1;

            foreach ($nodes as $node) {
                $mappedTable = trim((string)($node['mapped_table'] ?? ''));
                $mappedUid = (int)($node['mapped_uid'] ?? 0);
                $mappedRecord = $mappedTable !== '' && $mappedUid > 0
                    ? $mappedTable . ' · uid:' . $mappedUid
                    : '';

                $preparedNodes[] = [
                    'index' => $nodeIndex,
                    'failure_summary' => (string)($node['failure_summary'] ?? ''),
                    'html_snippet' => (string)($node['html_snippet'] ?? ''),
                    'mapped_table' => $mappedTable,
                    'mapped_uid' => $mappedUid,
                    'mapped_ctype' => (string)($node['mapped_ctype'] ?? ''),
                    'mapped_cid' => (string)($node['mapped_cid'] ?? ''),
                    'mappedRecord' => $mappedRecord,
                ];
                $nodeIndex++;
            }

            $ruleId = (string)($issue['rule_id'] ?? '');
            $title = trim((string)($issue['help'] ?? ''));
            $helpUrl = trim((string)($issue['help_url'] ?? ''));

            $rows[] = [
                'index' => str_pad((string)$index, 2, '0', STR_PAD_LEFT),
                'rule_id' => $ruleId,
                'ruleLabel' => strtoupper($ruleId),
                'impact' => $impact,
                'impactLabel' => $impact !== '' ? $impact : 'unknown',
                'tone' => $tone,
                'title' => $title !== '' ? $title : $this->humanizeRuleId($ruleId),
                'help' => $title,
                'help_url' => $helpUrl,
                'help_url_display' => $this->stripSchemeFromUrl($helpUrl),
                'nodes_count' => $nodesCount,
                'nodesLabel' => $nodesCount . ' ' . $this->pluralize('occurrence', $nodesCount),
                'nodes' => $preparedNodes,
                'hasNodes' => $preparedNodes !== [],
            ];
            $index++;
        }

        return $rows;
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

        if (strlen($content) > self::PDF_SCREENSHOT_MAX_BYTES) {
            return $this->buildScreenshotNotIncludedBlock();
        }

        $meta = 'captured screenshot';
        $imageInfo = @getimagesizefromstring($content);
        if (!is_array($imageInfo) || !isset($imageInfo[0], $imageInfo[1])) {
            return $this->buildScreenshotNotIncludedBlock();
        }

        $meta = (int)$imageInfo[0] . ' × ' . (int)$imageInfo[1];

        return [
            'html' => '<div class="aqgp-screenshot">'
                . '<img src="var:remote-page-screenshot" alt="Remote page screenshot" class="aqgp-screenshot__image" />'
                . '</div>',
            'meta' => $meta,
            'imageVars' => [
                'remote-page-screenshot' => $content,
            ],
        ];
    }
}
