<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Command\McpConnectorCommand;
use Priebera\A11yQualityGate\Mcp\Http\McpGatewayClient;
use Priebera\A11yQualityGate\Mcp\McpAuditRepository;
use Priebera\A11yQualityGate\Mcp\McpConnectionStore;
use Priebera\A11yQualityGate\Mcp\McpConnectorClock;
use Priebera\A11yQualityGate\Mcp\McpConnectorWorkerPool;
use Priebera\A11yQualityGate\Mcp\McpConnectorWorkerPoolFactory;
use Priebera\A11yQualityGate\Mcp\McpConsentLedger;
use Priebera\A11yQualityGate\Mcp\McpPairingService;
use Priebera\A11yQualityGate\Mcp\McpScanRequestStore;
use Priebera\A11yQualityGate\Mcp\McpToolExecutor;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Log\LogManager;

/**
 * Fable F-02 on the connector loop: with workers, a poll asks the Gateway for exactly as many calls as workers are idle,
 * acknowledged calls go to the workers (the connector process begins none of them itself), no poll is made while every
 * worker is busy, and the workers get the connector's own instance id. Without worker support it runs calls itself.
 */
final class McpConnectorParallelTest extends TestCase
{
    private string $log = '';

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is not available');
        }
        $this->log = (string)tempnam(sys_get_temp_dir(), 'aqg-connector-');
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
    }

    #[Test]
    public function pollsAskForTheIdleWorkersAndCallsRunInTheWorkers(): void
    {
        $clock = new ParallelTestClock();
        $gateway = $this->createMock(McpGatewayClient::class);
        $gateway->method('isConnected')->willReturn(true);
        $instance = '';
        $gateway->method('useConnectorInstance')->willReturnCallback(static function (string $id) use (&$instance): void {
            $instance = $id;
        });
        $asked = [];
        $calls = [];
        foreach (['a', 'b', 'c'] as $id) {
            $calls[] = ['id' => $id, 'claimId' => 'claim-' . $id, 'tool' => 'sleep:300', 'arguments' => [], 'deadline' => gmdate('Y-m-d\TH:i:s\Z', time() + 8)];
        }
        $queue = $calls;
        $readyBy = microtime(true) + 1.5;
        $gateway->method('poll')->willReturnCallback(static function (string $version, string $host, ?int $max) use (&$asked, &$queue, $clock, $readyBy): array {
            if (microtime(true) < $readyBy) {
                // While the workers start, the connector keeps polling (heartbeat); nothing arrives yet.
                usleep(100_000);
                return [];
            }
            $asked[] = $max;
            if ($queue === []) {
                $clock->advance(1000.0);
                return [];
            }
            // A Gateway with F-02 hands out at most what was asked for.
            return array_splice($queue, 0, (int)$max);
        });
        $gateway->method('acknowledge')->willReturnCallback(static fn (array $claims): array => array_column($claims, 'id'));
        $gateway->expects(self::never())->method('beginCall');

        $factory = $this->createMock(McpConnectorWorkerPoolFactory::class);
        $factory->method('workerCommand')->willReturn([PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/Mcp/fake-connector-worker.php', $this->log, '']);
        // The pool reads the connector's own clock, as in production.
        $factory->method('create')->willReturnCallback(static fn (array $command, string $instanceId, int $size, \Closure $now): McpConnectorWorkerPool => new McpConnectorWorkerPool($command, $instanceId, $size, $now));

        $tester = new CommandTester($this->command($gateway, $clock, $factory));
        $started = microtime(true);
        self::assertSame(0, $tester->execute(['--max-runtime' => '600', '--workers' => '3']));

        foreach ($asked as $max) {
            self::assertIsInt($max);
            self::assertGreaterThanOrEqual(1, $max, 'no poll while every worker is busy or starting');
            self::assertLessThanOrEqual(3, $max);
        }
        $log = (string)file_get_contents($this->log);
        foreach (['a', 'b', 'c'] as $id) {
            self::assertStringContainsString('done ' . $id, $log);
        }
        self::assertSame(3, substr_count($log, 'instance ' . $instance), 'every worker acts under the connector\'s instance id');
        self::assertSame(3, substr_count($log, 'stdin closed'), 'the stop ended every worker after its call');
        self::assertLessThan(5.0, microtime(true) - $started);
        self::assertStringContainsString('(3 workers)', $tester->getDisplay());
    }

    #[Test]
    public function callsBeyondTheIdleWorkersFromAGatewayBeforeF02RunInTheConnectorItself(): void
    {
        $clock = new ParallelTestClock();
        $gateway = $this->createMock(McpGatewayClient::class);
        $gateway->method('isConnected')->willReturn(true);
        $polls = 0;
        $readyBy = microtime(true) + 1.5;
        $gateway->method('poll')->willReturnCallback(static function (string $version, string $host, ?int $max) use (&$polls, $clock, $readyBy): array {
            if (microtime(true) < $readyBy) {
                usleep(100_000);
                return [];
            }
            if (++$polls === 1) {
                // An older Gateway ignores `max` and sends more calls than one worker can take.
                return [
                    ['id' => 'w', 'claimId' => 'cw', 'tool' => 'sleep:100', 'arguments' => []],
                    ['id' => 'i', 'claimId' => 'ci', 'tool' => 'aqg_whoami', 'arguments' => []],
                ];
            }
            $clock->advance(1000.0);
            return [];
        });
        $gateway->method('acknowledge')->willReturn(['w', 'i']);
        // Only the call no worker could take is begun here; the worker's call is the worker's.
        $gateway->expects(self::once())->method('beginCall')->with('i', 'ci')
            ->willThrowException(new \Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException('refused', 403, null, 'token_revoked'));
        $factory = $this->createMock(McpConnectorWorkerPoolFactory::class);
        $factory->method('workerCommand')->willReturn([PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/Mcp/fake-connector-worker.php', $this->log, '']);
        $factory->method('create')->willReturnCallback(static fn (array $command, string $instanceId, int $size, \Closure $now): McpConnectorWorkerPool => new McpConnectorWorkerPool($command, $instanceId, 1, $now));
        // One worker; the Gateway answers the poll for one call with two.
        self::assertSame(0, (new CommandTester($this->command($gateway, $clock, $factory)))->execute(['--max-runtime' => '600', '--workers' => '2']));
        self::assertStringContainsString('done w', (string)file_get_contents($this->log));
    }

    #[Test]
    public function whileNoWorkerIsReadyTheConnectorKeepsPollingAndRunsCallsItself(): void
    {
        $clock = new ParallelTestClock();
        $gateway = $this->createMock(McpGatewayClient::class);
        $gateway->method('isConnected')->willReturn(true);
        $asked = [];
        $gateway->method('poll')->willReturnCallback(static function (string $version, string $host, ?int $max) use (&$asked, $clock): array {
            $asked[] = $max;
            if (count($asked) === 1) {
                return [['id' => 's', 'claimId' => 'cs', 'tool' => 'aqg_whoami', 'arguments' => []]];
            }
            $clock->advance(1000.0);
            return [];
        });
        $gateway->method('acknowledge')->willReturn(['s']);
        $gateway->expects(self::once())->method('beginCall')->with('s', 'cs')
            ->willThrowException(new \Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException('refused', 403, null, 'token_revoked'));
        $factory = $this->createMock(McpConnectorWorkerPoolFactory::class);
        $factory->method('workerCommand')->willReturn([PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/Mcp/fake-connector-worker.php', $this->log, 'never-ready']);
        $factory->method('create')->willReturnCallback(static fn (array $command, string $instanceId, int $size, \Closure $now): McpConnectorWorkerPool => new McpConnectorWorkerPool($command, $instanceId, $size, $now));

        self::assertSame(0, (new CommandTester($this->command($gateway, $clock, $factory)))->execute(['--max-runtime' => '600', '--workers' => '2']));
        self::assertSame([1, 1], $asked, 'one call at a time, and the polls (the heartbeat) never stopped');
    }

    #[Test]
    public function withoutWorkerSupportTheConnectorRunsCallsItself(): void
    {
        $clock = new ParallelTestClock();
        $gateway = $this->createMock(McpGatewayClient::class);
        $gateway->method('isConnected')->willReturn(true);
        $polls = 0;
        $gateway->method('poll')->willReturnCallback(static function (string $version, string $host, ?int $max) use (&$polls, $clock): array {
            self::assertNull($max, 'inline: the Gateway sends up to its default');
            if (++$polls === 1) {
                return [['id' => 'x', 'claimId' => 'cx', 'tool' => 'aqg_whoami', 'arguments' => []]];
            }
            $clock->advance(1000.0);
            return [];
        });
        $gateway->method('acknowledge')->willReturn(['x']);
        $gateway->expects(self::once())->method('beginCall')->willThrowException(new \Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException('refused', 403, null, 'token_revoked'));
        $factory = $this->createMock(McpConnectorWorkerPoolFactory::class);
        $factory->method('workerCommand')->willReturn(null);
        $factory->expects(self::never())->method('create');

        $tester = new CommandTester($this->command($gateway, $clock, $factory));
        self::assertSame(0, $tester->execute(['--max-runtime' => '600']));
        self::assertStringContainsString('one at a time', $tester->getDisplay());
    }

    #[Test]
    public function oneWorkerMeansNoPool(): void
    {
        $clock = new ParallelTestClock();
        $gateway = $this->createMock(McpGatewayClient::class);
        $gateway->method('isConnected')->willReturn(true);
        $gateway->method('poll')->willReturnCallback(static function () use ($clock): array {
            $clock->advance(1000.0);
            return [];
        });
        $factory = $this->createMock(McpConnectorWorkerPoolFactory::class);
        $factory->expects(self::never())->method('workerCommand');

        self::assertSame(0, (new CommandTester($this->command($gateway, $clock, $factory)))->execute(['--max-runtime' => '60', '--workers' => '1']));
    }

    private function command(McpGatewayClient $gateway, McpConnectorClock $clock, McpConnectorWorkerPoolFactory $factory): McpConnectorCommand
    {
        $store = $this->createMock(McpConnectionStore::class);
        $store->method('get')->willReturn(['unusableReason' => '', 'status' => 'connected']);
        $pairing = $this->createMock(McpPairingService::class);
        $pairing->method('ensureActivated')->willReturn(true);
        $context = $this->createMock(ExtensionContextService::class);
        $context->method('getExtensionVersion')->willReturn('3.0.0');

        return new McpConnectorCommand(
            $gateway,
            $this->createMock(McpToolExecutor::class),
            $this->createMock(McpAuditRepository::class),
            $pairing,
            $this->createMock(McpScanRequestStore::class),
            $this->createMock(McpConsentLedger::class),
            $store,
            $context,
            $this->createMock(LogManager::class),
            $clock,
            null,
            $factory,
        );
    }
}

final class ParallelTestClock extends McpConnectorClock
{
    private float $now = 0.0;

    public function now(): float
    {
        return $this->now;
    }

    public function sleep(float $seconds): void
    {
        $this->now += max(0.0, $seconds);
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
