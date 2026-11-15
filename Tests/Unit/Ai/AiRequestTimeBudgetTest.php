<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Provider\AiRequestTimeBudget;
use Priebera\A11yQualityGate\Mcp\McpConnectorWorkerPool;
use Priebera\A11yQualityGate\Mcp\McpDeferredWork;

/**
 * G-07 (AQG 3.0.0 security gate): a deferred MCP AI request could outlive the connector worker (killed at the call's
 * deadline + CALL_GRACE = 30 s) because Anthropic's HTTP timeout is 45 s: paid, lost, then paid again. Deferred work now
 * bounds every provider request to end before that moment, or does not start it.
 */
final class AiRequestTimeBudgetTest extends TestCase
{
    #[Test]
    public function withoutABudgetProvidersKeepTheirOwnTimeout(): void
    {
        self::assertFalse(AiRequestTimeBudget::isActive());
        self::assertSame(45, AiRequestTimeBudget::timeoutSeconds(45));
    }

    #[Test]
    public function aBudgetCapsTheTimeoutAndRefusesWhenTooLittleIsLeft(): void
    {
        $now = 1_000_000.0;
        AiRequestTimeBudget::run($now + 40.0, function () use ($now): void {
            self::assertSame(30, AiRequestTimeBudget::timeoutSeconds(30, $now), 'enough time: the provider timeout stands');
            self::assertSame(35, AiRequestTimeBudget::timeoutSeconds(45, $now), '40 s left minus the settle margin');
            self::assertSame(5, AiRequestTimeBudget::timeoutSeconds(45, $now + 30.0));
            self::assertNull(AiRequestTimeBudget::timeoutSeconds(45, $now + 30.5), 'under 5 s: not started');
            AiRequestTimeBudget::run($now + 12.0, function () use ($now): void {
                self::assertSame(7, AiRequestTimeBudget::timeoutSeconds(45, $now), 'a nested budget never extends the outer one');
            });
            AiRequestTimeBudget::run($now + 100.0, function () use ($now): void {
                self::assertSame(35, AiRequestTimeBudget::timeoutSeconds(45, $now));
            });
        });
        self::assertFalse(AiRequestTimeBudget::isActive(), 'restored after the run');
    }

    #[Test]
    public function theBudgetEndsEvenWhenTheWorkThrows(): void
    {
        try {
            AiRequestTimeBudget::run(microtime(true) + 10.0, static function (): void {
                throw new \RuntimeException('provider failed');
            });
        } catch (\RuntimeException) {
        }
        self::assertFalse(AiRequestTimeBudget::isActive());
    }

    #[Test]
    public function deferredWorkRunsUnderTheWorkersEndOfTheCall(): void
    {
        $seen = [];
        $work = new McpDeferredWork();
        // A call answered 2 s before its deadline: the worker is replaced at deadline + CALL_GRACE.
        $deadline = microtime(true) + 2.0;
        $work->begin(['callId' => 'c1', 'deadline' => $deadline]);
        self::assertTrue($work->add(static function () use (&$seen): void {
            $seen[] = AiRequestTimeBudget::timeoutSeconds(45);
        }));
        $work->run(static fn () => null);
        $expected = (int)floor(2.0 + McpConnectorWorkerPool::CALL_GRACE - AiRequestTimeBudget::SETTLE_MARGIN_SECONDS);
        self::assertCount(1, $seen);
        self::assertLessThanOrEqual($expected, $seen[0], 'Anthropic\'s 45 s never outlives the worker');
        self::assertGreaterThanOrEqual($expected - 1, $seen[0]);

        // Work left by a call whose worker ends in 8 s (5 s settle margin) starts no provider request at all.
        $work->begin(['callId' => 'c2', 'deadline' => microtime(true) - McpConnectorWorkerPool::CALL_GRACE + 8.0]);
        $work->add(static function () use (&$seen): void {
            $seen[] = AiRequestTimeBudget::timeoutSeconds(45);
        });
        $work->run(static fn () => null);
        self::assertNull($seen[1]);
        self::assertFalse(AiRequestTimeBudget::isActive());
    }
}
