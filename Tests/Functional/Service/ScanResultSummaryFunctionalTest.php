<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Service\ScanResultSummaryService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * NOT-01 / NOT-02: the completion notification reads what is open now and what was covered from the stored results.
 */
final class ScanResultSummaryFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        foreach ([[1, 0, 1], [2, 1, 0]] as [$uid, $pid, $isRoot]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => 'Page ' . $uid, 'doktype' => 1, 'is_siteroot' => $isRoot, 'deleted' => 0, 'hidden' => 0,
            ]);
        }
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://main.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->setUpBackendUser(1);

        foreach ([[2, 1, 0], [2, 1, 0], [2, 2, 0], [2, 1, 2], [1, 3, 0], [2, 1, 1]] as [$page, $severity, $status]) {
            $this->insertIssue(['site_identifier' => 'main', 'page_uid' => $page, 'severity' => $severity, 'status' => $status]);
        }
    }

    #[Test]
    public function aPageScanReportsTheOpenIssuesOfThatPageOnly(): void
    {
        $open = $this->get(ScanResultSummaryService::class)->contentOpenIssues('page', 'main', 2, 0);

        self::assertSame(3, $open['total'], 'Ignored and resolved issues are not open.');
        self::assertSame(3, $open['critical'] + $open['warning'] + $open['info'] + $open['needs_review']);
    }

    #[Test]
    public function aSiteScanReportsTheOpenIssuesOfTheSite(): void
    {
        self::assertSame(4, $this->get(ScanResultSummaryService::class)->contentOpenIssues('site', 'main', 1, 0)['total']);
    }

    #[Test]
    public function theReportOfAPageScanIsThePageReport(): void
    {
        $url = $this->get(ScanResultSummaryService::class)->contentReportUrl('page', 'main', 2, 0);

        self::assertStringContainsString('pageUid=2', $url);
        self::assertStringContainsString('site=main', $url);
    }

    #[Test]
    public function aFrontendRunThatKnewThreePagesAndLoadedOneIsNotComplete(): void
    {
        $result = $this->get(ScanResultSummaryService::class)->frontendResult([
            'uid' => 0, 'site_identifier' => 'main', 'scan_scope' => 'site', 'page_uid' => 0, 'language_uid' => 0,
            'pages_scanned' => 1, 'pages_failed' => 0, 'pages_total' => 3, 'persisted_at' => 0,
        ]);

        self::assertSame(3, $result['pagesExpected']);
        self::assertSame(1, $result['pagesLoaded']);
        self::assertSame(2, $result['pagesNotChecked']);
        self::assertArrayNotHasKey('occurrencesTotal', $result, 'Nothing stored yet: no totals are claimed.');
        self::assertStringContainsString('aqgSource=remote', (string)$result['reportUrl']);
    }

    /**
     * V-05 (2026-10 Sol verification): the site-scan notification linked to the Overview's newest result, so its action
     * opened scan B once B had finished. It names its own stored scan; the details target (failed / not checked pages)
     * is appended to that same URL by scan-notification.js.
     */
    #[Test]
    public function aSiteScanNotificationOpensItsOwnScanAlsoAfterANewerOne(): void
    {
        $scanA = $this->insertSiteScan(20, 1000);
        $scanB = $this->insertSiteScan(21, 2000);

        $urlA = (string)$this->get(ScanResultSummaryService::class)->frontendResult($scanA)['reportUrl'];
        $urlB = (string)$this->get(ScanResultSummaryService::class)->frontendResult($scanB)['reportUrl'];

        parse_str((string)parse_url($urlA, PHP_URL_QUERY), $queryA);
        self::assertSame('20', $queryA['remoteScanUid'] ?? null, 'scan A, not the newest scan');
        self::assertSame('main', $queryA['site'] ?? null);
        self::assertSame('0', $queryA['language'] ?? null);
        self::assertSame('1', $queryA['id'] ?? null);
        self::assertSame('remote', $queryA['aqgSource'] ?? null);
        self::assertStringContainsString('remoteScanUid=21', $urlB);
    }

    #[Test]
    public function aContentReportIsTheCurrentStateNotABoundSnapshot(): void
    {
        $url = $this->get(ScanResultSummaryService::class)->contentReportUrl('site', 'main', 1, 0);

        self::assertStringNotContainsString('remoteScanUid', $url);
        self::assertStringContainsString('aqgSource=local', $url);
    }

    #[Test]
    public function newAndResolvedComeFromThePreviousScanOfTheSamePage(): void
    {
        // Crawler counts said "41 new" because they compared with the scan of another page in between.
        $contactBefore = $this->insertRemoteScan(10, 'https://main.test/contact', 1000, ['a', 'b', 'c']);
        $this->insertRemoteScan(11, 'https://main.test/about', 2000, ['x']);
        $this->insertRemoteScan(12, 'https://main.test/contact', 2500, ['z'], isFreePreview: 1);
        $current = $this->insertRemoteScan(13, 'https://main.test/contact', 3000, ['a', 'b', 'd'], issuesNew: 41);

        $result = $this->get(ScanResultSummaryService::class)->frontendResult($current);

        self::assertSame(['available' => true, 'new' => 1, 'resolved' => 1], $result['comparison']);
        self::assertSame(10, (int)$contactBefore['uid']);
    }

    #[Test]
    public function aFirstScanOfAPageClaimsNoChange(): void
    {
        $this->insertRemoteScan(11, 'https://main.test/about', 2000, ['x']);
        $current = $this->insertRemoteScan(14, 'https://main.test/new-page', 3000, ['a']);

        self::assertSame(['available' => false], $this->get(ScanResultSummaryService::class)->frontendResult($current)['comparison']);
    }

    /**
     * @return array<string, mixed>
     */
    private function insertSiteScan(int $uid, int $finishedAt): array
    {
        $row = [
            'uid' => $uid, 'site_identifier' => 'main', 'job_id' => 'site-job-' . $uid, 'source_type' => 'sitemap', 'scan_scope' => 'site',
            'page_uid' => 1, 'language_uid' => 0, 'start_url' => 'https://main.test/', 'status' => 'completed', 'is_free_preview' => 0,
            'finished_at' => $finishedAt, 'persisted_at' => $finishedAt, 'crdate' => $finishedAt, 'pages_total' => 3, 'pages_scanned' => 2,
            'pages_failed' => 1, 'issues_total' => 0,
        ];
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', $row);

        return $row;
    }

    /**
     * @param list<string> $fingerprints
     * @return array<string, mixed>
     */
    private function insertRemoteScan(int $uid, string $url, int $finishedAt, array $fingerprints, int $isFreePreview = 0, int $issuesNew = 0): array
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $row = [
            'uid' => $uid, 'site_identifier' => 'main', 'job_id' => 'job-' . $uid, 'source_type' => 'single_page', 'scan_scope' => 'page',
            'page_uid' => 2, 'language_uid' => 0, 'start_url' => $url, 'status' => 'completed', 'is_free_preview' => $isFreePreview,
            'finished_at' => $finishedAt, 'persisted_at' => $finishedAt, 'crdate' => $finishedAt, 'pages_total' => 1, 'pages_scanned' => 1,
            'issues_total' => count($fingerprints), 'issues_new' => $issuesNew,
        ];
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', $row);
        $pool->getConnectionForTable('tx_a11y_remote_scan_page')->insert('tx_a11y_remote_scan_page', [
            'uid' => $uid * 10, 'remote_scan' => $uid, 'url' => $url, 'http_status' => 200, 'issues_count' => count($fingerprints),
        ]);
        foreach ($fingerprints as $fingerprint) {
            $pool->getConnectionForTable('tx_a11y_remote_issue')->insert('tx_a11y_remote_issue', [
                'remote_scan' => $uid, 'remote_scan_page' => $uid * 10, 'rule_id' => 'image-alt', 'impact' => 'critical',
                'nodes_count' => 1, 'fingerprint' => str_repeat($fingerprint, 40), 'status' => 'open',
            ]);
        }

        return $row;
    }
}
