<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Service\ScanResultSummaryService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * D-1 (AQG 3.0 production acceptance, 2026-11-13): the scan notification and the toolbar compared the stored
 * fingerprints of two whole scans. With a 4-page budgeted scan as the baseline of a 19-page scan every finding on the
 * 15 pages the baseline never visited counted as new. The change is now counted on the pages both scans loaded.
 */
final class FrontendScanComparisonScopeTest extends AbstractFunctionalTestCase
{
    private ScanResultSummaryService $subject;
    private int $nextPageUid = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(ScanResultSummaryService::class);
    }

    #[Test]
    public function theProductionFourVersusNineteenPageCaseCountsOnlyTheSharedPages(): void
    {
        // Baseline (7cdac2cf): 4 pages, 79 findings. Later scan (300a4dea): the same 79 on those 4 pages, one genuinely
        // new finding on one of them, and 297 findings on 15 pages the baseline never visited.
        $baseline = $this->findingsOnPages(4, 79);
        $this->storeScan(1, 1000, $baseline);
        $later = $this->findingsOnPages(4, 79);
        $later['https://a.test/page-1'][] = 'genuinely-new';
        $later += $this->findingsOnPages(15, 297, 5);
        $current = $this->storeScan(2, 2000, $later);

        $comparison = $this->subject->frontendComparison($current);

        self::assertSame([
            'available' => true,
            'new' => 1,
            'resolved' => 0,
            'partial' => true,
            'comparedPages' => 4,
            'pagesOnlyInCurrent' => 15,
            'pagesOnlyInPrevious' => 0,
        ], $comparison);
    }

    #[Test]
    public function aGenuineRegressionOnTheSamePagesIsCountedAsBefore(): void
    {
        $this->storeScan(1, 1000, $this->findingsOnPages(4, 20));
        $later = $this->findingsOnPages(4, 20);
        $later['https://a.test/page-2'] = array_merge($later['https://a.test/page-2'], ['n1', 'n2', 'n3']);
        unset($later['https://a.test/page-3'][0]);
        $current = $this->storeScan(2, 2000, $later);

        self::assertSame(['available' => true, 'new' => 3, 'resolved' => 1], $this->subject->frontendComparison($current));
    }

    #[Test]
    public function aPageThatFailedInTheLaterScanIsNeitherResolvedNorCompared(): void
    {
        $this->storeScan(1, 1000, $this->findingsOnPages(3, 9));
        $later = $this->findingsOnPages(3, 9);
        $later['https://a.test/page-3'] = [];
        $current = $this->storeScan(2, 2000, $later, ['https://a.test/page-3']);

        $comparison = $this->subject->frontendComparison($current);

        self::assertSame(0, $comparison['resolved']);
        self::assertTrue($comparison['partial']);
        self::assertSame(2, $comparison['comparedPages']);
        self::assertSame(1, $comparison['pagesOnlyInPrevious']);
    }

    #[Test]
    public function scansWithoutASharedPageClaimNoChange(): void
    {
        $this->storeScan(1, 1000, ['https://a.test/x' => ['a']]);
        $current = $this->storeScan(2, 2000, ['https://a.test/y' => ['b']]);

        self::assertSame(['available' => false, 'reason' => 'different_pages'], $this->subject->frontendComparison($current));
    }

    /**
     * @return array<string, list<string>> page URL => fingerprints, $total findings spread over $pages pages
     */
    private function findingsOnPages(int $pages, int $total, int $firstPage = 1): array
    {
        $result = [];
        for ($i = 0; $i < $total; $i++) {
            $page = $firstPage + ($i % $pages);
            $result['https://a.test/page-' . $page][] = 'f' . $page . '-' . $i;
        }

        return $result;
    }

    /**
     * @param array<string, list<string>> $pages page URL => fingerprints
     * @param list<string> $failedPages
     * @return array<string, mixed>
     */
    private function storeScan(int $uid, int $finishedAt, array $pages, array $failedPages = []): array
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $row = [
            'uid' => $uid,
            'site_identifier' => 'main',
            'job_id' => 'job-' . $uid,
            'source_type' => 'sitemap',
            'scan_scope' => 'site',
            'language_uid' => 0,
            'start_url' => 'https://a.test/',
            'status' => 'completed',
            'is_free_preview' => 0,
            'pages_scanned' => count($pages) - count($failedPages),
            'pages_total' => count($pages),
            'issues_total' => array_sum(array_map('count', $pages)),
            'finished_at' => $finishedAt,
            'persisted_at' => $finishedAt,
        ];
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', $row);
        foreach ($pages as $url => $fingerprints) {
            $pageUid = $this->nextPageUid++;
            $pool->getConnectionForTable('tx_a11y_remote_scan_page')->insert('tx_a11y_remote_scan_page', [
                'uid' => $pageUid, 'remote_scan' => $uid, 'url' => $url, 'http_status' => in_array($url, $failedPages, true) ? 500 : 200,
                'is_failed' => in_array($url, $failedPages, true) ? 1 : 0,
            ]);
            foreach ($fingerprints as $fingerprint) {
                $pool->getConnectionForTable('tx_a11y_remote_issue')->insert('tx_a11y_remote_issue', [
                    'remote_scan' => $uid, 'remote_scan_page' => $pageUid, 'rule_id' => 'image-alt', 'nodes_count' => 1, 'fingerprint' => $fingerprint,
                ]);
            }
        }

        return $row;
    }
}
