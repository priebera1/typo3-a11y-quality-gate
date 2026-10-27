<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Service\LocalHistoryRetentionService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The local frontend scan history in a real TYPO3 database: kept by default, a period only offers scans for review,
 * and a confirmation deletes exactly the reviewed scans with their pages, findings and occurrences — never the
 * comparison baseline, a Verify fix record's scans, content scans or anything of another scan.
 */
final class LocalHistoryRetentionFunctionalTest extends AbstractFunctionalTestCase
{
    private function insertHistoryScan(int $uid, int $daysAgo, string $status = 'completed', string $jobId = ''): void
    {
        $pool = $this->get(ConnectionPool::class);
        $finished = time() - $daysAgo * 86400;
        $pool->getConnectionForTable(Tables::REMOTE_SCAN)->insert(Tables::REMOTE_SCAN, [
            'uid' => $uid, 'site_identifier' => 'main', 'job_id' => $jobId !== '' ? $jobId : 'job-' . $uid, 'source_type' => 'sitemap',
            'scan_scope' => 'site', 'language_uid' => 0, 'start_url' => 'https://example.org/', 'status' => $status,
            'finished_at' => $finished, 'crdate' => $finished,
        ]);
        $pool->getConnectionForTable(Tables::REMOTE_SCAN_PAGE)->insert(Tables::REMOTE_SCAN_PAGE, ['uid' => $uid * 10, 'remote_scan' => $uid, 'url' => 'https://example.org/']);
        $pool->getConnectionForTable(Tables::REMOTE_ISSUE)->insert(Tables::REMOTE_ISSUE, ['uid' => $uid * 10, 'remote_scan' => $uid, 'remote_scan_page' => $uid * 10, 'rule_id' => 'image-alt', 'nodes_count' => 2]);
        $pool->getConnectionForTable(Tables::REMOTE_ISSUE_NODE)->insert(Tables::REMOTE_ISSUE_NODE, ['remote_issue' => $uid * 10, 'html_snippet' => '<img>']);
    }

    private function rowCount(string $table): int
    {
        return (int)$this->get(ConnectionPool::class)->getConnectionForTable($table)->count('*', $table, []);
    }

    #[Test]
    public function aConfirmedReviewDeletesExactlyTheReviewedScans(): void
    {
        $this->insertHistoryScan(1, 900);
        $this->insertHistoryScan(2, 800);
        $this->insertHistoryScan(3, 700);   // referenced by Verify fix
        $this->insertHistoryScan(4, 30);    // newest completed: the baseline
        $this->get(ConnectionPool::class)->getConnectionForTable(Tables::FIX_VERIFICATION)->insert(Tables::FIX_VERIFICATION, ['baseline_scan' => 3, 'verification_scan' => 0]);

        $service = new LocalHistoryRetentionService($this->get(ConnectionPool::class), GeneralUtility::makeInstance(Registry::class));
        self::assertSame('keep', $service->getSetting(), 'kept by default');
        self::assertSame(0, $service->preview()['scans']);
        self::assertNull($service->prune('keep', time(), 'x'), 'keep deletes nothing');

        self::assertTrue($service->saveSetting('1y'));
        self::assertFalse($service->saveSetting('10d'), 'only the offered periods');
        $preview = $service->preview();
        self::assertSame(2, $preview['scans']);
        self::assertSame(2, $preview['pages']);
        self::assertSame(4, $preview['occurrences']);
        self::assertSame(4, $this->rowCount(Tables::REMOTE_SCAN), 'saving and previewing delete nothing');

        self::assertNull($service->prune('1y', (int)$preview['cutoff'], 'not-the-reviewed-selection'), 'another selection deletes nothing');
        self::assertNull($service->prune('1y', time(), $preview['reviewToken']), 'a cutoff later than the period allows deletes nothing');
        self::assertSame(4, $this->rowCount(Tables::REMOTE_SCAN));

        // A scan that becomes protected after the review changes the selection: nothing is deleted.
        $this->get(ConnectionPool::class)->getConnectionForTable(Tables::FIX_VERIFICATION)->insert(Tables::FIX_VERIFICATION, ['baseline_scan' => 2, 'verification_scan' => 0]);
        self::assertNull($service->prune('1y', (int)$preview['cutoff'], $preview['reviewToken']));
        self::assertSame(4, $this->rowCount(Tables::REMOTE_SCAN));
        $this->get(ConnectionPool::class)->getConnectionForTable(Tables::FIX_VERIFICATION)->delete(Tables::FIX_VERIFICATION, ['baseline_scan' => 2]);

        self::assertSame(2, $service->prune('1y', (int)$preview['cutoff'], $preview['reviewToken']));
        self::assertSame(2, $this->rowCount(Tables::REMOTE_SCAN));
        self::assertSame(2, $this->rowCount(Tables::REMOTE_SCAN_PAGE));
        self::assertSame(2, $this->rowCount(Tables::REMOTE_ISSUE));
        self::assertSame(2, $this->rowCount(Tables::REMOTE_ISSUE_NODE));
        self::assertSame(0, $service->preview()['scans'], 'idempotent');
    }
}
