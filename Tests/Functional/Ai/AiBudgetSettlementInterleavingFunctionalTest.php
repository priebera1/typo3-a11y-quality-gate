<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Ai;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetAlertNotifier;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetAlertService;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetService;
use Priebera\A11yQualityGate\Ai\Usage\AiCostBound;
use Priebera\A11yQualityGate\Ai\Usage\AiPriceTable;
use Priebera\A11yQualityGate\Ai\Usage\AiUsage;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageMeter;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder;
use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\AiBudgetAlertRepository;
use Priebera\A11yQualityGate\Domain\Repository\AiBudgetRepository;
use Priebera\A11yQualityGate\Domain\Repository\AiSiteSettingRepository;
use Priebera\A11yQualityGate\Domain\Repository\AiUsageRepository;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Priebera\A11yQualityGate\Tests\Functional\Fixtures\Dbal\StatementProbeMiddleware;
use Symfony\Component\Mime\RawMessage;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * SOL-FINAL-01: the AI hard-stop budget under READ COMMITTED, against the real database with two independent sessions.
 *
 * Request A holds a reservation and settles + releases it on its OWN database connection (a second DBAL connection, i.e.
 * another session, as another PHP process would) at an exact point inside request B's admission, chosen by a test-only
 * DBAL statement probe on B's connection: right after B holds the site/month lock, or right after B's first accounting
 * statement. Before the fix, admission read settled cost and reservations with two SUM statements; under READ COMMITTED
 * the second one saw the state after A's settlement while the first saw it before, so B was admitted beyond the budget
 * (SOL's schedule: budget 1,000,000 µUSD, A 600,000, B 800,000 → 1,400,000 accepted).
 *
 * The forked workload runs 12 real PHP processes with their own connections against the same database (the 12-editor
 * race of the resilience acceptance, without HTTP): the hard stop, a single 80 % warning, no pending row, no leftover
 * reservation and one usage row per dispatched provider call must hold under READ COMMITTED and the database default.
 */
final class AiBudgetSettlementInterleavingFunctionalTest extends AbstractFunctionalTestCase
{
    private const BUDGET = 1_000_000;

    protected array $configurationToUseInTestInstance = [
        'DB' => [
            'Connections' => [
                'Default' => [
                    'driverMiddlewares' => [
                        'aqg-test-statement-probe' => ['target' => StatementProbeMiddleware::class],
                    ],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        StatementProbeMiddleware::reset();
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://example.test/');
    }

    protected function tearDown(): void
    {
        StatementProbeMiddleware::reset();
        parent::tearDown();
    }

    /** @return array<string,array{0:string,1:string}> boundary => [SQL marker after which A settles, session isolation] */
    public static function admissionBoundaries(): array
    {
        return [
            'READ COMMITTED, A settles after B holds the lock' => ['FOR UPDATE', 'READ COMMITTED'],
            'READ COMMITTED, A settles after B\'s first accounting statement (SOL schedule)' => ['SUM(', 'READ COMMITTED'],
            'REPEATABLE READ, A settles after B\'s first accounting statement' => ['SUM(', 'REPEATABLE READ'],
        ];
    }

    #[Test]
    #[DataProvider('admissionBoundaries')]
    public function aSettlementDuringAdmissionNeverLetsTheNextRequestExceedTheBudget(string $boundary, string $isolation): void
    {
        [$period, $from, $until] = AiBudgetService::period();
        $budget = $this->get(AiBudgetRepository::class);
        // A: admitted with a 600,000 reservation, its usage row pending (counted by the reservation only).
        $reservationA = $budget->reserve('main', $period, $from, $until, self::BUDGET, 600_000);
        self::assertIsInt($reservationA);
        $usageA = $this->get(AiUsageRepository::class)->admit($this->pendingRow(600_000, $reservationA));
        [$aUsage, $aBudget] = $this->independentSession();

        $this->useSessionIsolation($isolation);
        $observed = null;
        StatementProbeMiddleware::$afterStatement = function (string $sql) use ($boundary, $aUsage, $aBudget, $usageA, $reservationA, &$observed): void {
            if ($observed !== null || !str_contains($sql, $boundary)) {
                return;
            }
            $observed = $this->currentIsolation();
            // A, on its own session, commits its settlement (600,000) and then gives its reservation back.
            self::assertTrue($aUsage->settle($usageA, $this->settledFields(600_000)));
            $aBudget->release($reservationA);
        };
        $reservationB = $budget->reserve('main', $period, $from, $until, self::BUDGET, 800_000);
        StatementProbeMiddleware::reset();

        self::assertNotNull($observed, 'A settled while B was inside its admission.');
        // PostgreSQL: the reservation transaction is always pinned to READ COMMITTED, whatever the session default.
        $expected = $this->platform() === 'postgresql' ? 'read committed' : $this->normalisedIsolation($isolation);
        self::assertSame($expected, $observed, 'B ran at the isolation level under test.');
        self::assertNull($reservationB, '600,000 settled + 800,000 would exceed the 1,000,000 budget.');
        self::assertSame(['spent' => 600_000, 'reserved' => 0], $this->totals($period, $from, $until));
        self::assertSame(0, $this->countRows(AiBudgetRepository::RESERVATION_TABLE));
        self::assertSame(1, $this->countRows(Tables::AI_USAGE));
    }

    #[Test]
    public function theAdmissionReadsSettledCostAndReservationsWithOneStatement(): void
    {
        [$period, $from, $until] = AiBudgetService::period();
        $budget = $this->get(AiBudgetRepository::class);
        StatementProbeMiddleware::$recording = true;
        self::assertIsInt($budget->reserve('main', $period, $from, $until, self::BUDGET, 1_000));
        $log = StatementProbeMiddleware::$log;
        StatementProbeMiddleware::reset();

        $accounting = array_values(array_filter($log, static fn (string $sql): bool => str_contains($sql, 'SUM(')));
        self::assertCount(1, $accounting, 'Exactly one accounting statement inside the admission.');
        self::assertSame(
            ['spent' => 0, 'reserved' => 1_000],
            $budget->accounting('main', $period, $from, $until),
            'accounting() returns both sums of one snapshot.',
        );
        self::assertStringContainsString(Tables::AI_USAGE, $accounting[0]);
        self::assertStringContainsString(AiBudgetRepository::RESERVATION_TABLE, $accounting[0]);
        $lock = array_keys(array_filter($log, static fn (string $sql): bool => str_contains($sql, 'FOR UPDATE')));
        $sum = array_keys(array_filter($log, static fn (string $sql): bool => str_contains($sql, 'SUM(')));
        self::assertCount(1, $lock);
        self::assertLessThan($sum[0], $lock[0], 'The accounting is read only while the site/month lock is held.');
        if ($this->platform() === 'postgresql') {
            $pin = array_keys(array_filter($log, static fn (string $sql): bool => str_contains($sql, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED')));
            self::assertCount(1, $pin);
            self::assertLessThan($lock[0], $pin[0], 'PostgreSQL: READ COMMITTED is pinned before the lock.');
        }
    }

    #[Test]
    public function postgresqlPinsReadCommittedEvenWhenTheSessionDefaultsToRepeatableRead(): void
    {
        if ($this->platform() !== 'postgresql') {
            // MySQL/MariaDB keep the session level (REPEATABLE READ is safe there: the read view starts after the lock).
            self::assertSame('mysql', $this->platform());

            return;
        }
        [$period, $from, $until] = AiBudgetService::period();
        $this->useSessionIsolation('REPEATABLE READ');
        $seen = null;
        StatementProbeMiddleware::$afterStatement = function (string $sql) use (&$seen): void {
            if ($seen === null && str_contains($sql, 'FOR UPDATE')) {
                $seen = $this->currentIsolation();
            }
        };
        self::assertIsInt($this->get(AiBudgetRepository::class)->reserve('main', $period, $from, $until, self::BUDGET, 1_000));
        StatementProbeMiddleware::reset();

        self::assertSame('read committed', $seen);
        self::assertSame('repeatable read', $this->currentIsolation(), 'Only the reservation transaction is pinned, not the session.');
    }

    #[Test]
    public function insideACallersTransactionTheAdmissionNeverRunsOnAnOlderPostgresqlSnapshot(): void
    {
        [$period, $from, $until] = AiBudgetService::period();
        $budget = $this->get(AiBudgetRepository::class);
        self::assertIsInt($budget->reserve('main', $period, $from, $until, self::BUDGET, 0), 'Lock row created outside.');
        $connection = $this->getConnectionPool()->getConnectionByName('Default');
        $connection->beginTransaction();
        try {
            if ($this->platform() === 'postgresql') {
                $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                try {
                    $budget->reserve('main', $period, $from, $until, self::BUDGET, 1_000);
                    self::fail('A REPEATABLE READ caller transaction must not admit.');
                } catch (\RuntimeException $exception) {
                    self::assertSame(1771003106, $exception->getCode());
                }
            } else {
                // MySQL/MariaDB: the snapshot of a consistent read starts after the lock (see the class contract).
                self::assertIsInt($budget->reserve('main', $period, $from, $until, self::BUDGET, 1_000));
            }
        } finally {
            $connection->rollBack();
        }
    }

    #[Test]
    public function aRequestAdmittedThroughTheRecorderIsRefusedWithoutAnyProviderCallWhenASettlementStraddlesItsAdmission(): void
    {
        $this->configureHardStop(0);
        $price = $this->get(AiPriceTable::class)->find('anthropic', 'claude-sonnet-5-5');
        self::assertNotNull($price);
        $boundB = new AiCostBound(100_000, 40_000);
        $estimateB = $boundB->costMicro($price);
        self::assertGreaterThan(0, $estimateB);
        self::assertLessThan(900_000, $estimateB);
        // A alone fits, B alone fits, both together exceed the budget by 100,000.
        $costA = self::BUDGET - $estimateB + 100_000;
        [$period, $from, $until] = AiBudgetService::period();
        $reservationA = $this->get(AiBudgetRepository::class)->reserve('main', $period, $from, $until, self::BUDGET, $costA);
        self::assertIsInt($reservationA);
        $usageA = $this->get(AiUsageRepository::class)->admit($this->pendingRow($costA, $reservationA));
        [$aUsage, $aBudget] = $this->independentSession();

        $this->useSessionIsolation('READ COMMITTED');
        $fired = false;
        StatementProbeMiddleware::$afterStatement = function (string $sql) use (&$fired, $aUsage, $aBudget, $usageA, $reservationA, $costA): void {
            if ($fired || !str_contains($sql, 'SUM(')) {
                return;
            }
            $fired = true;
            self::assertTrue($aUsage->settle($usageA, $this->settledFields($costA)));
            $aBudget->release($reservationA);
        };
        $dispatched = 0;
        try {
            $this->recorder()->track('main', 'explain', $this->providerConfiguration(), static function () use (&$dispatched): string {
                $dispatched++;

                return 'never';
            }, $boundB);
            self::fail('B must be refused: A\'s settled cost plus B\'s worst case exceed the hard stop.');
        } catch (AiConfigurationException $exception) {
            self::assertSame(AiBudgetService::CODE_BUDGET_REACHED, $exception->getCode());
        } finally {
            StatementProbeMiddleware::reset();
        }

        self::assertTrue($fired);
        self::assertSame(0, $dispatched, 'No provider request for the refused attempt.');
        self::assertSame(1, $this->countRows(Tables::AI_USAGE), 'No usage row for a request that was never admitted.');
        self::assertSame(0, $this->countRows(AiBudgetRepository::RESERVATION_TABLE));
        self::assertSame(['spent' => $costA, 'reserved' => 0], $this->totals($period, $from, $until));
    }

    /**
     * Two admissions of one site, the second WAITING for the lock while the first commits (review F1-01). B's snapshot
     * may be taken before its lock wait ends on some databases (MariaDB 11.6+ innodb_snapshot_isolation, PostgreSQL
     * REPEATABLE READ without the pin); it must then be refused or retried, never admitted on numbers that miss A.
     */
    #[Test]
    #[DataProvider('workloadIsolation')]
    public function anAdmissionThatWaitedForTheLockSeesTheReservationCommittedMeanwhile(string $isolation): void
    {
        if (!function_exists('pcntl_fork')) {
            self::fail('pcntl is required for the lock-wait test.');
        }
        [$period, $from, $until] = AiBudgetService::period();
        $dir = $this->instancePath . '/budget-lockwait-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir));
        // Lock row first, so both sides only wait on it.
        $budget = $this->get(AiBudgetRepository::class);
        self::assertIsInt($release = $budget->reserve('main', $period, $from, $until, self::BUDGET, 0));
        $budget->release($release);
        $this->getConnectionPool()->getConnectionByName('Default')->close();

        $pid = pcntl_fork();
        if ($pid === 0) {
            $result = ['reservation' => 'not-run'];
            try {
                usleep(300_000);
                $this->useSessionIsolation($isolation);
                // B: on a REPEATABLE READ snapshot taken BEFORE A commits where the database allows it.
                $result['isolation'] = $this->currentIsolation();
                $result['server'] = $this->serverFacts();
                $started = microtime(true);
                $result['reservation'] = $this->get(AiBudgetRepository::class)->reserve('main', $period, $from, $until, self::BUDGET, 800_000);
                $result['waitedSeconds'] = round(microtime(true) - $started, 2);
            } catch (\Throwable $throwable) {
                $result['reservation'] = 'error:' . $throwable::class . ':' . $throwable->getMessage();
            }
            file_put_contents($dir . '/b.json', json_encode($result));
            posix_kill(posix_getpid(), SIGKILL);
        }

        // A, on its own session: holds the lock, bumps it as reserve() does, reserves 600,000 and commits after B queued.
        [, , $a] = $this->independentSession(true);
        $a->beginTransaction();
        $lockUid = $a->executeQuery('SELECT uid FROM ' . AiBudgetRepository::LOCK_TABLE . ' WHERE site_identifier = ? AND ' . $a->quoteIdentifier('period') . ' = ? FOR UPDATE', ['main', $period])->fetchOne();
        $a->executeStatement('UPDATE ' . AiBudgetRepository::LOCK_TABLE . ' SET tstamp = tstamp + 1 WHERE uid = ?', [(int)$lockUid]);
        $a->insert(AiBudgetRepository::RESERVATION_TABLE, ['site_identifier' => 'main', 'period' => $period, 'cost_micro_usd' => 600_000, 'crdate' => time()]);
        usleep(1_800_000);
        $a->commit();
        pcntl_waitpid($pid, $status);

        $b = json_decode((string)file_get_contents($dir . '/b.json'), true);
        self::assertIsArray($b);
        self::assertNull($b['reservation'], 'B waited for A and must see A\'s 600,000: ' . json_encode($b));
        self::assertGreaterThan(1.0, $b['waitedSeconds'], 'B really waited for the lock.');
        self::assertSame(['spent' => 0, 'reserved' => 600_000], $this->totals($period, $from, $until));
        self::assertNotSame('', $b['server']['version'] ?? '', 'The database server and its snapshot mode are recorded.');
        $this->recordServerFacts('lock-wait ' . $isolation, $b);
    }

    /** @return array{version:string,snapshotIsolation:string} */
    private function serverFacts(): array
    {
        $connection = $this->getConnectionPool()->getConnectionByName('Default');
        if ($this->platform() === 'postgresql') {
            return ['version' => (string)$connection->executeQuery('SELECT version()')->fetchOne(), 'snapshotIsolation' => 'n/a'];
        }
        $version = (string)$connection->executeQuery('SELECT VERSION()')->fetchOne();
        try {
            $snapshot = (string)$connection->executeQuery('SELECT @@innodb_snapshot_isolation')->fetchOne();
        } catch (\Throwable) {
            $snapshot = 'not available';
        }

        return ['version' => $version, 'snapshotIsolation' => $snapshot];
    }

    /** Appends the facts to the file named by AQG_DB_EVIDENCE (if set) and to the test output. */
    private function recordServerFacts(string $case, array $facts): void
    {
        $line = json_encode(['case' => $case] + $facts, JSON_UNESCAPED_SLASHES);
        $file = (string)getenv('AQG_DB_EVIDENCE');
        if ($file !== '') {
            file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /** @return array<string,array{0:string}> */
    public static function workloadIsolation(): array
    {
        return ['READ COMMITTED' => ['READ COMMITTED'], 'REPEATABLE READ' => ['REPEATABLE READ']];
    }

    /**
     * 12 forked PHP processes (own connections, own sessions) spend one site's budget with a mix of outcomes: reported
     * usage below the worst case, an unreported success (worst case), an ambiguous provider failure (worst case) and a
     * definitive rejection before processing (no cost). Each process keeps asking until it is refused three times.
     */
    #[Test]
    #[DataProvider('workloadIsolation')]
    public function twelveConcurrentProcessesNeverExceedTheHardStop(string $isolation): void
    {
        if (!function_exists('pcntl_fork')) {
            self::fail('pcntl is required for the multi-process budget workload.');
        }
        $this->configureHardStop(80);
        // The instance path is shared by data sets and kept between runs: a fresh, empty directory per run.
        $resultDir = $this->instancePath . '/budget-workload-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($resultDir));
        $mailLog = $resultDir . '/alert-mails.log';
        $bound = new AiCostBound(8_000, 2_000);
        $estimate = $bound->costMicro($this->get(AiPriceTable::class)->find('anthropic', 'claude-sonnet-5-5'));
        self::assertGreaterThan(10_000, $estimate);
        self::assertLessThan(100_000, $estimate);

        // Each process must open its own connection: close ours before forking.
        $this->getConnectionPool()->getConnectionByName('Default')->close();
        $children = [];
        for ($worker = 0; $worker < 12; $worker++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('fork failed');
            }
            if ($pid === 0) {
                $this->runWorker($worker, $isolation, $bound, $mailLog, $resultDir);
                // Never return into PHPUnit inside the child.
                posix_kill(posix_getpid(), SIGKILL);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];
        foreach (glob($resultDir . '/*.json') ?: [] as $file) {
            $results[] = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        }
        self::assertCount(12, $results, 'Every process finished and reported.');
        $dispatched = array_sum(array_column($results, 'dispatched'));
        $refused = array_sum(array_column($results, 'refused'));
        $unexpected = array_merge(...array_column($results, 'unexpected'));
        self::assertSame([], $unexpected, 'Only budget refusals and the injected provider failures occurred.');
        self::assertGreaterThan(0, $refused, 'The workload ran into the hard stop.');

        [$period, $from, $until] = AiBudgetService::period();
        $connection = $this->getConnectionPool()->getConnectionForTable(Tables::AI_USAGE);
        $rows = $connection->select(['outcome', 'estimated_cost_micro_usd', 'reserved_cost_micro_usd', 'budget_reservation'], Tables::AI_USAGE, ['site_identifier' => 'main'])->fetchAllAssociative();
        $spent = array_sum(array_map(static fn (array $row): int => (int)$row['estimated_cost_micro_usd'], $rows));
        self::assertLessThanOrEqual(self::BUDGET, $spent, 'Hard stop: the settled cost never exceeds the budget.');
        self::assertGreaterThanOrEqual(self::BUDGET - 2 * $estimate, $spent, 'The budget was actually used up, not refused early.');
        self::assertSame($dispatched, count($rows), 'One usage row per provider request: no duplicate, no lost row.');
        self::assertSame([], array_values(array_filter($rows, static fn (array $row): bool => $row['outcome'] === 'pending')), 'No row left pending.');
        self::assertSame(0, array_sum(array_map(static fn (array $row): int => (int)$row['reserved_cost_micro_usd'], $rows)));
        self::assertSame(0, $this->countRows(AiBudgetRepository::RESERVATION_TABLE), 'No abandoned reservation.');
        self::assertSame(['spent' => $spent, 'reserved' => 0], $this->totals($period, $from, $until));
        $zeroCost = count(array_filter($rows, static fn (array $row): bool => str_starts_with((string)$row['outcome'], 'failed_1771002311')));
        self::assertGreaterThan(0, $zeroCost, 'Definitive rejections were part of the workload.');
        foreach ($rows as $row) {
            if (str_starts_with((string)$row['outcome'], 'failed_1771002311')) {
                self::assertSame(0, (int)$row['estimated_cost_micro_usd'], 'A definitive rejection costs nothing.');
            }
        }
        // F-39: exactly one 80 % warning for the month, sent once.
        self::assertSame(1, $this->countRows(AiBudgetAlertRepository::TABLE, ['site_identifier' => 'main']));
        self::assertSame('sent', (string)$connection->select(['status'], AiBudgetAlertRepository::TABLE, ['site_identifier' => 'main'])->fetchOne());
        $mails = file($mailLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $mails, "Exactly one warning e-mail. Sent:\n" . implode("\n", $mails));

        // Retry after exhaustion: refused before anything is sent, nothing recorded.
        $retried = 0;
        try {
            $this->recorder($mailLog)->track('main', 'explain', $this->providerConfiguration(), static function () use (&$retried): string {
                $retried++;

                return 'never';
            }, new AiCostBound(200_000, 20_000));
            self::fail('A large request after exhaustion is refused.');
        } catch (AiConfigurationException $exception) {
            self::assertSame(AiBudgetService::CODE_BUDGET_REACHED, $exception->getCode());
        }
        self::assertSame(0, $retried);
        self::assertSame($dispatched, $this->countRows(Tables::AI_USAGE));
    }

    private function runWorker(int $worker, string $isolation, AiCostBound $bound, string $mailLog, string $resultDir): void
    {
        $result = ['worker' => $worker, 'dispatched' => 0, 'refused' => 0, 'admitted' => 0, 'unexpected' => []];
        try {
            $this->useSessionIsolation($isolation);
            $recorder = $this->recorder($mailLog);
            $meter = $this->get(AiUsageMeter::class);
            $configuration = $this->providerConfiguration();
            $refusedInARow = 0;
            for ($attempt = 0; $attempt < 60 && $refusedInARow < 3; $attempt++) {
                $kind = ($worker + $attempt) % 4;
                try {
                    $recorder->track('main', 'explain', $configuration, static function () use (&$result, $kind, $meter): string {
                        $result['dispatched']++;
                        usleep(random_int(1_000, 15_000));
                        return match ($kind) {
                            0 => (static function () use ($meter): string {
                                $meter->report(new AiUsage(4_000, 1_000, true));

                                return 'reported';
                            })(),
                            1 => 'unreported',
                            2 => throw new AiProviderException('ambiguous', 1771002315),
                            default => throw new AiProviderException('rate limited', 1771002311),
                        };
                    }, $bound);
                    $result['admitted']++;
                    $refusedInARow = 0;
                } catch (AiConfigurationException $exception) {
                    if ($exception->getCode() === AiBudgetService::CODE_BUDGET_REACHED) {
                        $result['refused']++;
                        $refusedInARow++;
                        usleep(random_int(1_000, 5_000));
                    } else {
                        $result['unexpected'][] = 'config:' . $exception->getCode();
                    }
                } catch (AiProviderException $exception) {
                    $result['admitted']++;
                    $refusedInARow = 0;
                    if (!in_array($exception->getCode(), [1771002315, 1771002311], true)) {
                        $result['unexpected'][] = 'provider:' . $exception->getCode();
                    }
                }
            }
        } catch (\Throwable $throwable) {
            $result['unexpected'][] = $throwable::class . ':' . $throwable->getCode() . ':' . $throwable->getMessage();
        }
        file_put_contents($resultDir . '/' . $worker . '.json', json_encode($result, JSON_THROW_ON_ERROR));
    }

    private function recorder(?string $mailLog = null): AiUsageRecorder
    {
        $alerts = null;
        if ($mailLog !== null) {
            $mailer = new class ($mailLog) implements MailerInterface {
                public function __construct(private readonly string $log) {}

                public function send(RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
                {
                    $to = $message instanceof \Symfony\Component\Mime\Email ? implode(',', array_map(static fn ($address): string => $address->getAddress(), $message->getTo())) : '?';
                    $subject = $message instanceof \Symfony\Component\Mime\Email ? (string)$message->getSubject() : '?';
                    file_put_contents($this->log, getmypid() . ' ' . microtime(true) . ' ' . $to . ' ' . $subject . "\n", FILE_APPEND | LOCK_EX);
                }

                public function getSentMessage(): ?\Symfony\Component\Mailer\SentMessage
                {
                    return null;
                }

                public function getTransport(): \Symfony\Component\Mailer\Transport\TransportInterface
                {
                    throw new \LogicException('not used');
                }

                public function getRealTransport(): \Symfony\Component\Mailer\Transport\TransportInterface
                {
                    throw new \LogicException('not used');
                }
            };
            $alerts = new AiBudgetAlertService(
                $this->get(AiBudgetAlertRepository::class),
                $this->get(AiBudgetRepository::class),
                $this->get(AiUsageRepository::class),
                $this->get(AiSiteSettingsService::class),
                new AiBudgetAlertNotifier($this->get(UriBuilder::class), $this->get(SiteFinder::class), static fn (): MailerInterface => $mailer),
            );
        }

        return new AiUsageRecorder(
            $this->get(AiUsageRepository::class),
            $this->get(AiUsageMeter::class),
            $this->get(BackendUserServiceInterface::class),
            $this->get(AiBudgetService::class),
            $alerts,
        );
    }

    /** @return array{spent:int,reserved:int} after the sessions are quiet (two reads are fine here) */
    private function totals(int $period, int $from, int $until): array
    {
        $budget = $this->get(AiBudgetRepository::class);

        return ['spent' => $budget->spentMicroUsd('main', $from, $until), 'reserved' => $budget->reservedMicroUsd('main', $period)];
    }

    private function configureHardStop(int $warningPercent): void
    {
        $settings = $this->get(AiSiteSettingRepository::class);
        $settings->saveProvider('main', 'anthropic', 1);
        $settings->saveBudget('main', self::BUDGET, $warningPercent, true, 1);
        if ($warningPercent > 0) {
            $settings->saveBudgetAlertEmail('main', 'alerts@example.com');
        }
    }

    private function providerConfiguration(): AiProviderConfiguration
    {
        return new AiProviderConfiguration('anthropic', 'sk-ant-test', 'claude-sonnet-5-5', 'site', 'fp');
    }

    /** @return array<string,mixed> */
    private function pendingRow(int $worstCase, int $reservation): array
    {
        return [
            'site_identifier' => 'main', 'be_user' => 0, 'feature' => 'explain', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5',
            'outcome' => 'pending', 'estimated_cost_micro_usd' => 0, 'reserved_cost_micro_usd' => $worstCase,
            'budget_reservation' => $reservation, 'cost_known' => 1, 'cost_basis' => 'pending', 'crdate' => time(),
        ];
    }

    /** @return array<string,mixed> the fields AiUsageRecorder::settle() writes for a worst-case settlement */
    private function settledFields(int $cost): array
    {
        return [
            'outcome' => 'success', 'input_tokens' => 0, 'output_tokens' => 0, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0,
            'usage_reported' => 0, 'estimated_cost_micro_usd' => $cost, 'reserved_cost_micro_usd' => 0, 'cost_known' => 1, 'cost_basis' => 'bound',
        ];
    }

    /**
     * Repositories on a second, independent database connection (another session, like another PHP process), without
     * the statement probe.
     *
     * @return array{0:AiUsageRepository,1:AiBudgetRepository,2:Connection}
     */
    private function independentSession(bool $withConnection = false): array
    {
        $params = $this->getConnectionPool()->getConnectionByName('Default')->getParams();
        unset($params['driverMiddlewares']);
        $connection = DriverManager::getConnection(['wrapperClass' => Connection::class] + $params, new Configuration());
        self::assertInstanceOf(Connection::class, $connection);
        $pool = new class ($connection) extends ConnectionPool {
            public function __construct(private readonly Connection $independent) {}

            public function getConnectionForTable(string $tableName): Connection
            {
                return $this->independent;
            }

            public function getConnectionByName(string $connectionName): Connection
            {
                return $this->independent;
            }

            public function getQueryBuilderForTable(string $tableName): QueryBuilder
            {
                return $this->independent->createQueryBuilder();
            }
        };

        return [new AiUsageRepository($pool), new AiBudgetRepository($pool), $connection];
    }

    private function platform(): string
    {
        $platform = strtolower($this->getConnectionPool()->getConnectionByName('Default')->getDatabasePlatform()::class);

        return match (true) {
            str_contains($platform, 'postgre') => 'postgresql',
            str_contains($platform, 'sqlite') => 'sqlite',
            default => 'mysql',
        };
    }

    private function useSessionIsolation(string $level): void
    {
        $connection = $this->getConnectionPool()->getConnectionByName('Default');
        match ($this->platform()) {
            'postgresql' => $connection->executeStatement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL ' . $level),
            'mysql' => $connection->executeStatement('SET SESSION TRANSACTION ISOLATION LEVEL ' . $level),
            default => self::fail('SOL-FINAL-01 is a multi-session property; SQLite serialises writers and is not a target here.'),
        };
    }

    private function currentIsolation(): string
    {
        $connection = $this->getConnectionPool()->getConnectionByName('Default');
        if ($this->platform() === 'postgresql') {
            return (string)$connection->executeQuery('SHOW transaction_isolation')->fetchOne();
        }
        try {
            return (string)$connection->executeQuery('SELECT @@transaction_isolation')->fetchOne();
        } catch (\Throwable) {
            return (string)$connection->executeQuery('SELECT @@tx_isolation')->fetchOne();
        }
    }

    private function normalisedIsolation(string $level): string
    {
        return $this->platform() === 'postgresql' ? strtolower($level) : str_replace(' ', '-', $level);
    }
}
