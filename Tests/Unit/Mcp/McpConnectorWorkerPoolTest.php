<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Mcp\McpConnectorWorkerPool;

/**
 * Fable F-02: the connector's worker pool, with real child processes speaking the worker protocol
 * (Tests/Fixtures/Mcp/fake-connector-worker.php) — parallel execution, the connector's instance id handed to every
 * worker, crashed and overrunning workers replaced (their call is never handed out again), a pool that cannot start
 * workers disabled so the connector runs calls itself, and a stop that lets running calls finish.
 */
final class McpConnectorWorkerPoolTest extends TestCase
{
    private const INSTANCE = 'abcdef0123456789abcdef0123456789';

    private string $log = '';
    /** @var list<McpConnectorWorkerPool> */
    private array $pools = [];
    private float $offset = 0.0;

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is not available');
        }
        $this->log = (string)tempnam(sys_get_temp_dir(), 'aqg-pool-');
    }

    protected function tearDown(): void
    {
        foreach ($this->pools as $pool) {
            $pool->shutdown(0.0);
        }
        @unlink($this->log);
    }

    private function pool(int $size, string $mode = ''): McpConnectorWorkerPool
    {
        $script = dirname(__DIR__, 2) . '/Fixtures/Mcp/fake-connector-worker.php';
        $pool = new McpConnectorWorkerPool([PHP_BINARY, $script, $this->log, $mode], self::INSTANCE, $size, fn (): float => microtime(true) + $this->offset);
        $this->pools[] = $pool;

        return $pool;
    }

    private function waitUntilIdle(McpConnectorWorkerPool $pool, int $count, float $seconds = 10.0): void
    {
        $until = microtime(true) + $seconds;
        while ($pool->idleCount() < $count && microtime(true) < $until) {
            $pool->reap(0.05);
        }
        self::assertSame($count, $pool->idleCount(), 'workers became ready');
    }

    /** @return list<string> */
    private function collect(McpConnectorWorkerPool $pool, int $count, float $seconds = 10.0): array
    {
        $finished = [];
        $until = microtime(true) + $seconds;
        while (count($finished) < $count && microtime(true) < $until) {
            array_push($finished, ...$pool->reap(0.05));
        }

        return $finished;
    }

    /** @return array<string, mixed> */
    private static function call(string $id, string $tool): array
    {
        return ['id' => $id, 'claimId' => 'claim-' . $id, 'tool' => $tool, 'arguments' => []];
    }

    #[Test]
    public function callsRunInParallelAndEveryWorkerActsUnderTheConnectorsInstanceId(): void
    {
        $pool = $this->pool(3);
        self::assertSame(3, $pool->fill());
        $this->waitUntilIdle($pool, 3);

        $started = microtime(true);
        foreach (['a', 'b', 'c'] as $id) {
            self::assertTrue($pool->dispatch(self::call($id, 'sleep:600'), microtime(true) + 8));
        }
        self::assertSame(0, $pool->idleCount());
        self::assertFalse($pool->dispatch(self::call('d', 'sleep:1'), microtime(true) + 8), 'no idle worker: the connector keeps the call');
        $finished = $this->collect($pool, 3);
        $elapsed = microtime(true) - $started;

        sort($finished);
        self::assertSame(['a', 'b', 'c'], $finished);
        self::assertLessThan(1.6, $elapsed, 'three 600 ms calls ran side by side, not one after another');
        self::assertSame(3, $pool->idleCount());
        self::assertSame(3, substr_count((string)file_get_contents($this->log), 'instance ' . self::INSTANCE));
    }

    #[Test]
    public function aCrashedWorkerIsReplacedAndItsCallIsNotHandedOutAgain(): void
    {
        $pool = $this->pool(2);
        $pool->fill();
        $this->waitUntilIdle($pool, 2);
        self::assertTrue($pool->dispatch(self::call('dies', 'crash'), microtime(true) + 8));
        self::assertTrue($pool->dispatch(self::call('ok', 'sleep:50'), microtime(true) + 8));

        $finished = $this->collect($pool, 1);
        $until = microtime(true) + 5;
        while ($pool->busyCount() + $pool->idleCount() > 1 && microtime(true) < $until && $pool->busyCount() > 0) {
            array_push($finished, ...$pool->reap(0.05));
        }
        self::assertSame(['ok'], $finished, 'the crashed call is not reported as finished');
        self::assertSame(2, $pool->fill(), 'the crashed worker is replaced');
        $this->waitUntilIdle($pool, 2);
        self::assertStringNotContainsString('done dies', (string)file_get_contents($this->log));
        self::assertFalse($pool->isDisabled(), 'one crash after a good start does not disable the pool');
    }

    #[Test]
    public function aWorkerStillBusyPastItsCallsDeadlineIsReplaced(): void
    {
        $pool = $this->pool(1);
        $pool->fill();
        $this->waitUntilIdle($pool, 1);
        self::assertTrue($pool->dispatch(self::call('stuck', 'hang'), microtime(true) + 1));
        self::assertSame([], $pool->reap(0.1));
        self::assertSame(1, $pool->busyCount());
        // 1 s deadline + 30 s grace pass (simulated): the worker is terminated, never waited for.
        $this->offset = 40.0;
        self::assertSame([], $pool->reap(0.0));
        self::assertSame(0, $pool->busyCount() + $pool->idleCount());
        $this->offset = 0.0;
        self::assertSame(1, $pool->fill());
        $this->waitUntilIdle($pool, 1);
    }

    #[Test]
    public function workersThatCannotStartDisableThePool(): void
    {
        $pool = $this->pool(2, 'exit-at-start');
        for ($round = 0; $round < 6 && !$pool->isDisabled(); $round++) {
            $pool->fill();
            usleep(200_000);
            $pool->reap(0.05);
        }
        self::assertTrue($pool->isDisabled());
        self::assertSame('workers_exit_at_start', $pool->disabledReason());
        self::assertSame(0, $pool->idleCount());
        self::assertFalse($pool->dispatch(self::call('x', 'sleep:1'), microtime(true) + 8));
    }

    #[Test]
    public function aWorkerThatNeverBecomesReadyGetsNoCallAndIsReplacedAfterTheStartupTimeout(): void
    {
        $pool = $this->pool(1, 'never-ready');
        $pool->fill();
        $pool->reap(0.3);
        self::assertSame(0, $pool->idleCount());
        self::assertFalse($pool->dispatch(self::call('x', 'sleep:1'), microtime(true) + 8), 'a starting worker takes no call');
        $this->offset = 31.0;
        $pool->reap(0.0);
        self::assertSame(0, $pool->busyCount() + $pool->idleCount());
        // Workers that hang at start every time disable the pool too (no endless 30 s replacements without a poll).
        for ($round = 2; $round <= 3; $round++) {
            $pool->fill();
            $this->offset = 31.0 * $round;
            $pool->reap(0.0);
        }
        self::assertTrue($pool->isDisabled());
    }

    #[Test]
    public function foreignOrMalformedProtocolLinesFinishNothing(): void
    {
        $pool = $this->pool(1);
        $pool->fill();
        $this->waitUntilIdle($pool, 1);
        self::assertTrue($pool->dispatch(self::call('mine', 'noise'), microtime(true) + 8));
        self::assertSame(['mine'], $this->collect($pool, 1), 'only the done line for the call this worker holds counts');
    }

    #[Test]
    public function aStopLetsRunningCallsFinishThenEndsTheWorkers(): void
    {
        $pool = $this->pool(2);
        $pool->fill();
        $this->waitUntilIdle($pool, 2);
        self::assertTrue($pool->dispatch(self::call('finishing', 'sleep:300'), microtime(true) + 8));
        $pool->shutdown(5.0);
        $log = (string)file_get_contents($this->log);
        self::assertStringContainsString('done finishing', $log);
        self::assertSame(2, substr_count($log, 'stdin closed'));
        self::assertSame(0, $pool->busyCount() + $pool->idleCount());
    }

    #[Test]
    public function onlyTypo3sOwnCliScriptIsEverRunAsAWorker(): void
    {
        self::assertNull(McpConnectorWorkerPool::workerCommandFor(null));
        self::assertNull(McpConnectorWorkerPool::workerCommandFor(''));
        self::assertNull(McpConnectorWorkerPool::workerCommandFor(__FILE__), 'a test runner or any other script is never spawned');
        self::assertNull(McpConnectorWorkerPool::workerCommandFor('/nonexistent/typo3'));
        $link = sys_get_temp_dir() . '/aqg-pool-link-' . bin2hex(random_bytes(4));
        mkdir($link);
        symlink(__FILE__, $link . '/typo3');
        try {
            self::assertNull(McpConnectorWorkerPool::workerCommandFor($link . '/typo3'), 'a link named typo3 to another script is refused');
        } finally {
            unlink($link . '/typo3');
            rmdir($link);
        }
        $directory = sys_get_temp_dir() . '/aqg-pool-bin-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/typo3', "<?php\n");
        try {
            self::assertSame([PHP_BINARY, (string)realpath($directory . '/typo3'), 'a11y:mcp:connector:worker'], McpConnectorWorkerPool::workerCommandFor($directory . '/typo3'));
        } finally {
            unlink($directory . '/typo3');
            rmdir($directory);
        }
    }
}
