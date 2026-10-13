<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Service\ScanResultSummaryService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * What a frontend scan changed comes from its stored findings against the previous compatible stored scan. The
 * toolbar showed the crawler's own count instead: after a site scan identical to the previous one it said
 * "396 new" while the scan notification correctly said "0 new".
 */
final class FrontendScanComparisonTest extends AbstractFunctionalTestCase
{
    private ScanResultSummaryService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(ScanResultSummaryService::class);
    }

    #[Test]
    public function anIdenticalRerunHasNoNewOrResolvedOccurrencesWhateverTheCrawlerCounted(): void
    {
        $this->insertRemoteScan(1, 1000, ['a', 'b', 'c']);
        $current = $this->insertRemoteScan(2, 2000, ['a', 'b', 'c'], ['issues_new' => 3]);

        self::assertSame(['available' => true, 'new' => 0, 'resolved' => 0], $this->subject->frontendComparison($current));
    }

    #[Test]
    public function newAndResolvedOccurrencesAreCountedByFingerprint(): void
    {
        $this->insertRemoteScan(1, 1000, ['a', 'b', 'c']);
        $current = $this->insertRemoteScan(2, 2000, ['b', 'c', 'd', 'e']);

        self::assertSame(['available' => true, 'new' => 2, 'resolved' => 1], $this->subject->frontendComparison($current));
    }

    #[Test]
    public function theFirstScanAndIncompatibleScansClaimNothing(): void
    {
        $first = $this->insertRemoteScan(1, 1000, ['a']);
        // Another language, a Free Remote Preview result and another site are no baseline.
        $this->insertRemoteScan(2, 1500, ['a'], ['language_uid' => 1]);
        $this->insertRemoteScan(3, 1600, ['a'], ['is_free_preview' => 1]);
        $this->insertRemoteScan(4, 1700, ['a'], ['site_identifier' => 'other']);
        $notPersisted = $this->insertRemoteScan(5, 1800, ['a'], ['persisted_at' => 0]);

        self::assertSame(['available' => false], $this->subject->frontendComparison($first));
        self::assertSame(['available' => false], $this->subject->frontendComparison($notPersisted));
    }

    #[Test]
    public function theBaselineIsTheNewestCompatibleEarlierScan(): void
    {
        $this->insertRemoteScan(1, 1000, ['a']);
        $this->insertRemoteScan(2, 1500, ['a', 'b']);
        $this->insertRemoteScan(3, 1600, ['z'], ['language_uid' => 1]);
        $current = $this->insertRemoteScan(4, 2000, ['a', 'b', 'c']);

        self::assertSame(['available' => true, 'new' => 1, 'resolved' => 0], $this->subject->frontendComparison($current));
    }

    /**
     * @param list<string> $fingerprints
     * @param array<string, mixed> $overrides
     * @return array<string, mixed> the stored scan row
     */
    private function insertRemoteScan(int $uid, int $finishedAt, array $fingerprints, array $overrides = []): array
    {
        $row = array_merge([
            'uid' => $uid,
            'site_identifier' => 'main',
            'job_id' => 'job-' . $uid,
            'source_type' => 'sitemap',
            'scan_scope' => 'site',
            'language_uid' => 0,
            'start_url' => 'https://example.org/',
            'status' => 'completed',
            'is_free_preview' => 0,
            'pages_scanned' => 1,
            'pages_total' => 1,
            'issues_total' => count($fingerprints),
            'finished_at' => $finishedAt,
            'persisted_at' => $finishedAt,
        ], $overrides);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', $row);
        foreach ($fingerprints as $fingerprint) {
            GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_issue')->insert('tx_a11y_remote_issue', [
                'remote_scan' => $uid,
                'rule_id' => 'image-alt',
                'nodes_count' => 1,
                'fingerprint' => $fingerprint . '-' . $row['site_identifier'],
            ]);
        }

        return $row;
    }
}
