<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\OverviewController;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Frontend scan history rows that only the local database knows (the AQG API lists the scans of the current
 * installation identity only) showed 0 critical, 0 serious and 0 moderate occurrences whatever the scan found. They
 * now report the stored findings, as the Overview does, and "—" when nothing was stored.
 */
final class OverviewHistoryTotalsTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function aLocalOnlyRowReportsTheStoredOccurrencesByImpact(): void
    {
        $this->insertRemoteScan(1, 1000, 1000, 9, ['critical' => 2, 'serious' => 3, 'moderate' => 1, '' => 1]);

        $row = $this->localHistory()[0];

        self::assertSame(7, $row['issuesTotal']);
        self::assertSame([2, 3, 1, 0], [$row['critical'], $row['serious'], $row['moderate'], $row['minor']]);
        self::assertArrayNotHasKey('localScan', $row);
    }

    #[Test]
    public function aRowWithoutStoredFindingsDoesNotClaimZeroImpacts(): void
    {
        $this->insertRemoteScan(1, 1000, 0, 9, []);

        $row = $this->localHistory()[0];

        self::assertSame(9, $row['issuesTotal']);
        self::assertSame(['—', '—', '—', '—'], [$row['critical'], $row['serious'], $row['moderate'], $row['minor']]);
    }

    #[Test]
    public function rowsTheApiReturnedKeepTheirFigures(): void
    {
        $apiRow = ['jobId' => 'job-1', 'issuesTotal' => 5, 'critical' => 4, 'serious' => 1, 'moderate' => 0, 'minor' => 0];

        self::assertSame([$apiRow], $this->call('withStoredHistoryTotals', [$apiRow]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function localHistory(): array
    {
        return $this->call('withStoredHistoryTotals', $this->call('buildLocalRemoteHistoryItems', 'main', ['sitemap'], 10, -1));
    }

    private function call(string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod(OverviewController::class, $method))->invoke($this->get(OverviewController::class), ...$arguments);
    }

    /**
     * @param array<string, int> $occurrencesByImpact
     */
    private function insertRemoteScan(int $uid, int $finishedAt, int $persistedAt, int $crawlerTotal, array $occurrencesByImpact): void
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', [
            'uid' => $uid,
            'site_identifier' => 'main',
            'job_id' => 'job-' . $uid,
            'source_type' => 'sitemap',
            'scan_scope' => 'site',
            'start_url' => 'https://example.org/',
            'status' => 'completed',
            'issues_total' => $crawlerTotal,
            'finished_at' => $finishedAt,
            'persisted_at' => $persistedAt,
        ]);
        foreach ($occurrencesByImpact as $impact => $occurrences) {
            $pool->getConnectionForTable('tx_a11y_remote_issue')->insert('tx_a11y_remote_issue', [
                'remote_scan' => $uid,
                'rule_id' => 'rule-' . $impact,
                'impact' => (string)$impact,
                'nodes_count' => $occurrences,
                'fingerprint' => 'fp-' . $impact,
            ]);
        }
    }
}
