<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Command\McpConnectorCommand;
use Priebera\A11yQualityGate\Mcp\Http\McpGatewayClient;
use Priebera\A11yQualityGate\Mcp\McpAuditRepository;
use Priebera\A11yQualityGate\Mcp\McpConnectionStore;
use Priebera\A11yQualityGate\Mcp\McpConnectorClock;
use Priebera\A11yQualityGate\Mcp\McpConsentLedger;
use Priebera\A11yQualityGate\Mcp\McpPairingService;
use Priebera\A11yQualityGate\Mcp\McpScanRequestStore;
use Priebera\A11yQualityGate\Mcp\McpToolExecutor;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Log\LogManager;

/**
 * Sol AQG-MCP-09: --max-runtime bounds the connector on every path. A Gateway that keeps failing (outage, conflict) must
 * not keep the process alive past it, and no retry wait outlasts the remaining runtime. Simulated time: nothing sleeps.
 */
final class McpConnectorRuntimeTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ApiRequestFailedException}>
     */
    public static function persistentFailures(): iterable
    {
        yield 'Gateway outage (503)' => [new ApiRequestFailedException('Service unavailable', 503, null, 'service_unavailable')];
        yield 'transport error' => [new ApiRequestFailedException('Connection refused', 0)];
        yield 'another connector holds the lease' => [new ApiRequestFailedException('Conflict', 409, null, 'connector_conflict')];
    }

    #[Test]
    #[DataProvider('persistentFailures')]
    public function aPersistentGatewayFailureEndsAtTheMaximumRuntime(ApiRequestFailedException $failure): void
    {
        $clock = new SimulatedClock();
        $gateway = $this->gateway();
        $gateway->method('poll')->willReturnCallback(static function () use ($clock, $failure): array {
            $clock->advance(0.2);
            throw $failure;
        });
        $gateway->expects(self::once())->method('releaseConnectorLease');

        $tester = new CommandTester($this->command($gateway, $clock));
        $exitCode = $tester->execute(['--max-runtime' => '100']);

        self::assertSame(0, $exitCode);
        self::assertGreaterThanOrEqual(100.0, $clock->now());
        self::assertLessThan(100.5, $clock->now(), 'no retry wait outlasts the remaining runtime');
        self::assertLessThanOrEqual(100.0, max($clock->sleeps) + 0.0);
        self::assertStringContainsString('maximum runtime', $tester->getDisplay());
        // I-12: a conflict is an answer of a reachable Gateway; an outage or transport error is not.
        $isConflict = $failure->apiErrorCode === 'connector_conflict';
        self::assertSame(!$isConflict, str_contains($tester->getDisplay(), 'Gateway not reachable'));
        self::assertSame($isConflict, str_contains($tester->getDisplay(), 'refused this connector while another one polls'));
    }

    #[Test]
    public function successfulPollsEndAtTheMaximumRuntimeToo(): void
    {
        $clock = new SimulatedClock();
        $gateway = $this->gateway();
        $gateway->method('poll')->willReturnCallback(static function () use ($clock): array {
            $clock->advance(8.0);
            return [];
        });

        $tester = new CommandTester($this->command($gateway, $clock));

        self::assertSame(0, $tester->execute(['--max-runtime' => '30']));
        self::assertGreaterThanOrEqual(30.0, $clock->now());
        self::assertLessThan(40.0, $clock->now());
    }

    #[Test]
    public function aStopSignalDuringAnOutageEndsTheLoopAndReleasesTheLease(): void
    {
        $clock = new SimulatedClock();
        $gateway = $this->gateway();
        $command = null;
        $polls = 0;
        $gateway->method('poll')->willReturnCallback(static function () use (&$command, &$polls): array {
            if (++$polls === 3) {
                // What the SIGTERM handler does.
                (new \ReflectionProperty(McpConnectorCommand::class, 'stopRequested'))->setValue($command, true);
            }
            throw new ApiRequestFailedException('Service unavailable', 503);
        });
        $gateway->expects(self::once())->method('releaseConnectorLease');
        $command = $this->command($gateway, $clock);

        self::assertSame(0, (new CommandTester($command))->execute(['--max-runtime' => '0']));
        self::assertSame(3, $polls);
    }

    #[Test]
    public function anUnlimitedRuntimeKeepsTheFullBackoff(): void
    {
        $clock = new SimulatedClock();
        $gateway = $this->gateway();
        $command = null;
        $gateway->method('poll')->willReturnCallback(static function () use (&$command, $clock): array {
            if (count($clock->sleeps) >= 6) {
                (new \ReflectionProperty(McpConnectorCommand::class, 'stopRequested'))->setValue($command, true);
            }
            throw new ApiRequestFailedException('Service unavailable', 503);
        });
        $command = $this->command($gateway, $clock);

        (new CommandTester($command))->execute(['--max-runtime' => '0']);
        self::assertSame([2.0, 5.0, 10.0, 30.0, 60.0, 60.0], $clock->sleeps);
    }

    private function gateway(): McpGatewayClient&\PHPUnit\Framework\MockObject\MockObject
    {
        $gateway = $this->createMock(McpGatewayClient::class);
        $gateway->method('isConnected')->willReturn(true);

        return $gateway;
    }

    private function command(McpGatewayClient $gateway, McpConnectorClock $clock): McpConnectorCommand
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
        );
    }
}

final class SimulatedClock extends McpConnectorClock
{
    private float $now = 1_000_000.0;
    private float $start = 1_000_000.0;
    /** @var list<float> */
    public array $sleeps = [];

    public function now(): float
    {
        return $this->now - $this->start;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->now += max(0.0, $seconds);
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
