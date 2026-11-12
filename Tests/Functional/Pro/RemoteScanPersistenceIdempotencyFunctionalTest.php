<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Pro;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueNodeRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanPersistenceService;
use Priebera\A11yQualityGate\Service\DateTimeService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Priebera\A11yQualityGate\Tests\Functional\Fixtures\ReentrantPersistLogWriter;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * NEW-01 (resilience audit): a completed frontend scan is stored exactly once, however many readers reach the
 * persistence at the same time. Status polls, the summary call, Overview, toolbar, Page Detail, Page Module and
 * Monitoring all call it; the audit stored a 97-page / 1,253-finding scan as 291 pages / 3,759 findings.
 *
 * The concurrent reader is simulated deterministically: a log writer fires on the service's own "raw results
 * overview" line, which is written after the old rows are cleared and before the new ones are stored, and runs a
 * second persistResults() for the same job at exactly that point — the interleaving two PHP processes produced.
 */
final class RemoteScanPersistenceIdempotencyFunctionalTest extends AbstractFunctionalTestCase
{
    private const JOB = 'f0f0f0f0-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        ReentrantPersistLogWriter::$callback = null;
        ReentrantPersistLogWriter::$fired = 0;
        ReentrantPersistLogWriter::$message = 'AQG remote persist: raw results overview';
    }

    protected function tearDown(): void
    {
        ReentrantPersistLogWriter::$callback = null;
        unset($GLOBALS['TYPO3_CONF_VARS']['LOG']['Priebera']['A11yQualityGate']['Pro']['Service']['RemoteScanPersistenceService']);
        GeneralUtility::makeInstance(LogManager::class)->reset();
        parent::tearDown();
    }

    #[Test]
    public function aReaderArrivingWhileTheScanIsBeingStoredStoresNothingTwice(): void
    {
        $this->insertUnpersistedScan(self::JOB);
        $service = $this->service();
        $this->reenterOnce(fn () => $service->persistResults('test', self::JOB, RemoteScanSourceType::Sitemap, 'https://example.test/', null, $this->results(3, 2)));

        $service->persistResults('test', self::JOB, RemoteScanSourceType::Sitemap, 'https://example.test/', null, $this->results(3, 2));

        self::assertSame(1, ReentrantPersistLogWriter::$fired, 'The concurrent reader must have run.');
        self::assertSame(3, $this->rows('tx_a11y_remote_scan_page'), 'Each crawled page is stored once.');
        self::assertSame(6, $this->rows('tx_a11y_remote_issue'), 'Each finding is stored once.');
        self::assertSame(6, $this->rows('tx_a11y_remote_issue_node'));
        self::assertGreaterThan(0, (int)$this->scanRow(self::JOB)['persisted_at']);
    }

    #[Test]
    public function repeatedReadsAfterCompletionKeepTheStoredScanAsItIs(): void
    {
        $this->insertUnpersistedScan(self::JOB);
        $service = $this->service();
        $uid = $service->persistResults('test', self::JOB, RemoteScanSourceType::Sitemap, 'https://example.test/', null, $this->results(4, 1));
        $firstPageUids = $this->pageUids();
        $persistedAt = (int)$this->scanRow(self::JOB)['persisted_at'];

        for ($i = 0; $i < 3; $i++) {
            self::assertSame($uid, $service->persistResults('test', self::JOB, RemoteScanSourceType::Sitemap, 'https://example.test/', null, $this->results(4, 1)));
        }

        self::assertSame($firstPageUids, $this->pageUids(), 'A stored scan is not rewritten by later reads.');
        self::assertSame(4, $this->rows('tx_a11y_remote_issue'));
        self::assertSame($persistedAt, (int)$this->scanRow(self::JOB)['persisted_at']);
    }

    #[Test]
    public function aClaimHeldByAnotherProcessIsRespectedUntilItExpires(): void
    {
        $uid = $this->insertUnpersistedScan(self::JOB);
        $repository = new RemoteScanRepository(GeneralUtility::makeInstance(ConnectionPool::class));
        self::assertTrue($repository->claimPersistence($uid, 'other-process', time(), 300));
        self::assertFalse($repository->claimPersistence($uid, 'third-process', time(), 300), 'Only one caller may hold the claim.');

        $this->service()->persistResults('test', self::JOB, RemoteScanSourceType::Sitemap, 'https://example.test/', null, $this->results(2, 1));
        self::assertSame(0, $this->rows('tx_a11y_remote_scan_page'), 'A reader must not store while another process stores.');
        self::assertSame(0, (int)$this->scanRow(self::JOB)['persisted_at']);

        // The other process died: once its claim expired, the next reader stores the scan.
        $this->conn('tx_a11y_remote_scan')->update('tx_a11y_remote_scan', ['persist_claim_until' => time() - 1], ['uid' => $uid]);
        $this->service()->persistResults('test', self::JOB, RemoteScanSourceType::Sitemap, 'https://example.test/', null, $this->results(2, 1));
        self::assertSame(2, $this->rows('tx_a11y_remote_scan_page'));
        self::assertSame('', (string)$this->scanRow(self::JOB)['persist_claim_token'], 'The claim is released after storing.');
    }

    #[Test]
    public function aFailureWhileStoringLeavesNothingPartialAndTheRetryStoresTheWholeScan(): void
    {
        $uid = $this->insertUnpersistedScan(self::JOB);
        $service = $this->service();
        // Fails on the store's last log line: after every page and finding was written and the scan marked persisted.
        ReentrantPersistLogWriter::$message = 'AQG remote persist: save result';
        $this->reenterOnce(static function (): void {
            throw new \RuntimeException('Database connection lost while storing');
        });
        try {
            $service->persistResults('test', self::JOB, RemoteScanSourceType::Sitemap, 'https://example.test/', null, $this->results(3, 2));
            self::fail('The failure must surface to the caller (recovery records a sync error).');
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $this->rows('tx_a11y_remote_scan_page'), 'Rolled back: no partial pages.');
        self::assertSame(0, $this->rows('tx_a11y_remote_issue'), 'Rolled back: no partial findings.');
        $row = $this->scanRow(self::JOB);
        self::assertSame(0, (int)$row['persisted_at']);
        self::assertSame(0, (int)$row['persist_claim_until'], 'The claim is released, so the next reader can retry at once.');

        ReentrantPersistLogWriter::$callback = null;
        self::assertSame($uid, $service->persistResults('test', self::JOB, RemoteScanSourceType::Sitemap, 'https://example.test/', null, $this->results(3, 2)));
        self::assertSame(3, $this->rows('tx_a11y_remote_scan_page'));
        self::assertSame(6, $this->rows('tx_a11y_remote_issue'));
    }

    #[Test]
    public function partialRowsOfAnInterruptedEarlierStoreAreReplacedExactly(): void
    {
        $uid = $this->insertUnpersistedScan(self::JOB);
        // Left behind by a store of an earlier release that died midway (before NEW-01 the store had no transaction).
        $this->conn('tx_a11y_remote_scan_page')->insert('tx_a11y_remote_scan_page', ['remote_scan' => $uid, 'url' => 'https://example.test/p0', 'title' => 'stale']);

        $this->service()->persistResults('test', self::JOB, RemoteScanSourceType::Sitemap, 'https://example.test/', null, $this->results(3, 1));

        self::assertSame(3, $this->rows('tx_a11y_remote_scan_page'));
        self::assertSame(3, $this->rows('tx_a11y_remote_issue'));
    }

    #[Test]
    public function differentScansCompletingTogetherAreStoredIndependently(): void
    {
        $this->insertUnpersistedScan(self::JOB);
        $other = 'f0f0f0f0-0000-4000-8000-000000000002';
        $this->insertUnpersistedScan($other, 'other-site');
        $service = $this->service();
        $this->reenterOnce(fn () => $service->persistResults('other-site', $other, RemoteScanSourceType::Sitemap, 'https://other.test/', null, $this->results(2, 1, 'https://other.test/')));

        $service->persistResults('test', self::JOB, RemoteScanSourceType::Sitemap, 'https://example.test/', null, $this->results(3, 1));

        self::assertGreaterThan(0, (int)$this->scanRow(self::JOB)['persisted_at']);
        self::assertGreaterThan(0, (int)$this->scanRow($other)['persisted_at'], 'A store of another scan is not blocked by this claim.');
        self::assertSame(5, $this->rows('tx_a11y_remote_scan_page'));
    }

    private function service(): RemoteScanPersistenceService
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);

        return new RemoteScanPersistenceService(
            new RemoteScanRepository($pool),
            new RemoteIssueRepository($pool),
            new RemoteIssueNodeRepository($pool),
            new DateTimeService(),
        );
    }

    private function reenterOnce(callable $callback): void
    {
        ReentrantPersistLogWriter::$callback = $callback;
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['Priebera']['A11yQualityGate']['Pro']['Service']['RemoteScanPersistenceService']['writerConfiguration'] = [
            LogLevel::INFO => [ReentrantPersistLogWriter::class => []],
        ];
        GeneralUtility::makeInstance(LogManager::class)->reset();
    }

    private function insertUnpersistedScan(string $jobId, string $site = 'test'): int
    {
        $conn = $this->conn('tx_a11y_remote_scan');
        $conn->insert('tx_a11y_remote_scan', [
            'site_identifier' => $site, 'job_id' => $jobId, 'source_type' => 'sitemap', 'scan_scope' => 'site',
            'start_url' => 'https://example.test/', 'status' => 'completed', 'language_uid' => 0, 'persisted_at' => 0,
        ]);

        return (int)$conn->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function results(int $pages, int $issuesPerPage, string $base = 'https://example.test/'): array
    {
        $list = [];
        for ($p = 0; $p < $pages; $p++) {
            $issues = [];
            for ($i = 0; $i < $issuesPerPage; $i++) {
                $issues[] = ['ruleId' => 'rule-' . $i, 'impact' => 'serious', 'fingerprint' => sha1($base . $p . '-' . $i), 'nodes' => [['target' => ['#n' . $i], 'html' => '<img>']]];
            }
            $list[] = ['url' => $base . 'p' . $p, 'httpStatus' => 200, 'title' => 'P' . $p, 'issues' => $issues];
        }

        return ['status' => 'completed', 'pagesScanned' => $pages, 'pagesTotal' => $pages, 'issuesTotal' => $pages * $issuesPerPage, 'languageUid' => 0, 'pages' => $list];
    }

    private function conn(string $table): \TYPO3\CMS\Core\Database\Connection
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);
    }

    private function rows(string $table): int
    {
        return (int)$this->conn($table)->count('*', $table, []);
    }

    /** @return list<int> */
    private function pageUids(): array
    {
        return array_map('intval', array_column($this->conn('tx_a11y_remote_scan_page')->select(['uid'], 'tx_a11y_remote_scan_page', [], [], ['uid' => 'ASC'])->fetchAllAssociative(), 'uid'));
    }

    /** @return array<string, mixed> */
    private function scanRow(string $jobId): array
    {
        return $this->conn('tx_a11y_remote_scan')->select(['*'], 'tx_a11y_remote_scan', ['job_id' => $jobId])->fetchAssociative() ?: [];
    }
}
