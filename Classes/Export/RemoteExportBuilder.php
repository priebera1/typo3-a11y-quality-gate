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

        if (!is_array($scan) || !isset($scan['uid'])) {
            $html = $this->pdfTemplateRenderer->render(
                templateName: 'Export/RemoteOverviewPdf',
                variables: [
                    'title' => 'Remote accessibility report',
                    'subtitle' => $siteIdentifier !== '' ? $siteIdentifier : 'Remote scan',
                    'generatedAt' => date('Y-m-d H:i'),
                    'hasScan' => false,
                    'siteIdentifier' => $siteIdentifier,
                    'scan' => null,
                    'pages' => [],
                    'failedPages' => [],
                    'topRules' => [],
                ],
                request: $request,
            );

            return $this->pdfGenerator->render(
                html: $html,
                title: 'AQG Remote Overview Report',
            );
        }

        $pages = $this->remoteScanRepository->findPagesForScan((int)$scan['uid']);
        $failedPages = $this->remoteScanRepository->findFailedPagesForScan((int)$scan['uid']);

        $html = $this->pdfTemplateRenderer->render(
            templateName: 'Export/RemoteOverviewPdf',
            variables: [
                'title' => 'Remote accessibility report',
                'subtitle' => $siteIdentifier,
                'generatedAt' => date('Y-m-d H:i'),
                'hasScan' => true,
                'siteIdentifier' => $siteIdentifier,
                'scan' => $scan,
                'pages' => array_slice($pages, 0, 10),
                'failedPages' => array_slice($failedPages, 0, 10),
                'topRules' => $this->buildOverviewTopRules($pages),
            ],
            request: $request,
        );

        return $this->pdfGenerator->render(
            html: $html,
            title: 'AQG Remote Overview Report',
        );
    }

    public function buildPagePdf(int $remotePageUid, ?ServerRequestInterface $request = null): string
    {
        $remotePage = $this->remoteScanRepository->findPageByUid($remotePageUid);

        if (!is_array($remotePage)) {
            $html = $this->pdfTemplateRenderer->render(
                templateName: 'Export/RemotePagePdf',
                variables: [
                    'title' => 'Remote page report',
                    'subtitle' => 'Remote page',
                    'generatedAt' => date('Y-m-d H:i'),
                    'hasPage' => false,
                    'remotePage' => null,
                    'issues' => [],
                    'screenshotAvailable' => false,
                    'screenshotPlaceholder' => '',
                ],
                request: $request,
            );

            return $this->pdfGenerator->render(
                html: $html,
                title: 'AQG Remote Page Report',
            );
        }

        $screenshot = $this->buildScreenshotBlock($remotePageUid);

        $html = $this->pdfTemplateRenderer->render(
            templateName: 'Export/RemotePagePdf',
            variables: [
                'title' => (string)($remotePage['title'] ?? 'Remote page report'),
                'subtitle' => (string)($remotePage['url'] ?? ''),
                'generatedAt' => date('Y-m-d H:i'),
                'hasPage' => true,
                'remotePage' => $remotePage,
                'issues' => $this->preparePageIssueRows($remotePageUid),
                'screenshotAvailable' => $screenshot['html'] !== '',
                'screenshotPlaceholder' => $screenshot['html'],
            ],
            request: $request,
        );

        return $this->pdfGenerator->render(
            html: $html,
            title: 'AQG Remote Page Report',
            imageVars: $screenshot['imageVars'],
        );
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, array{ruleId:string,count:int}>
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

                $topRules[$ruleId] = ($topRules[$ruleId] ?? 0) + (int)($issue['nodes_count'] ?? 1);
            }
        }

        arsort($topRules);

        $rows = [];
        foreach (array_slice($topRules, 0, 10, true) as $ruleId => $count) {
            $rows[] = [
                'ruleId' => (string)$ruleId,
                'count' => (int)$count,
            ];
        }

        return $rows;
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

        foreach ($issues as $issue) {
            $issueUid = (int)($issue['uid'] ?? 0);
            $nodes = $issueUid > 0
                ? $this->remoteIssueNodeRepository->findByRemoteIssue($issueUid)
                : [];

            $rows[] = [
                'rule_id' => (string)($issue['rule_id'] ?? ''),
                'impact' => (string)($issue['impact'] ?? ''),
                'help' => (string)($issue['help'] ?? ''),
                'help_url' => (string)($issue['help_url'] ?? ''),
                'nodes_count' => (int)($issue['nodes_count'] ?? 0),
                'nodes' => $nodes,
            ];
        }

        return $rows;
    }

    /**
     * @return array{html:string,imageVars:array<string,string>}
     */
    private function buildScreenshotBlock(int $remotePageUid): array
    {
        if ($remotePageUid <= 0) {
            return [
                'html' => '',
                'imageVars' => [],
            ];
        }

        try {
            $image = $this->remoteScreenshotService->fetchScreenshotByRemotePageUid($remotePageUid);
        } catch (\Throwable) {
            return [
                'html' => '',
                'imageVars' => [],
            ];
        }

        if (!is_array($image)) {
            return [
                'html' => '',
                'imageVars' => [],
            ];
        }

        $content = $image['content'] ?? null;
        if (!is_string($content) || $content === '') {
            return [
                'html' => '',
                'imageVars' => [],
            ];
        }

        return [
            'html' => '<h2>Screenshot preview</h2>'
                . '<div class="screenshot-block">'
                . '<img src="var:remote-page-screenshot" alt="Remote page screenshot" class="screenshot-image" />'
                . '</div>',
            'imageVars' => [
                'remote-page-screenshot' => $content,
            ],
        ];
    }
}