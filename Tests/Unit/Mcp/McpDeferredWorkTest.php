<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Mcp\McpDeferredWork;

/**
 * Work a call leaves for after its answer: bounded (one provider request per call), run once, isolated per call, only
 * for an attested call (finding 2: the job receives that call's context) and never stopped by a failing job.
 */
final class McpDeferredWorkTest extends TestCase
{
    private const CONTEXT = ['callId' => 'c1', 'attestation' => 'a.b.c', 'claims' => ['jti' => 'j']];

    #[Test]
    public function oneJobPerCallRunsOnceWithTheCallContextAndAFailureIsReportedNotThrown(): void
    {
        $work = new McpDeferredWork();
        $work->begin(self::CONTEXT);
        $runs = 0;
        $seen = null;
        self::assertTrue($work->add(function (array $context) use (&$runs, &$seen): void {
            $runs++;
            $seen = $context;
            throw new \RuntimeException('provider down');
        }));
        self::assertFalse($work->add(static function (): void {}), 'A second provider request is not left behind by the same call.');
        $errors = [];
        $work->run(static function (\Throwable $exception) use (&$errors): void {
            $errors[] = $exception->getMessage();
        });
        $work->run(static function (): void {});

        self::assertSame(1, $runs);
        self::assertSame(self::CONTEXT, $seen);
        self::assertSame(['provider down'], $errors);
        self::assertSame(0, $work->count());
        self::assertFalse($work->canSchedule(), 'Running forgets the call: nothing more can be left for it.');
    }

    #[Test]
    public function clearedWorkNeverRuns(): void
    {
        $work = new McpDeferredWork();
        $work->begin(self::CONTEXT);
        $ran = false;
        $work->add(static function () use (&$ran): void {
            $ran = true;
        });
        $work->clear();
        $work->run(static function (): void {});

        self::assertFalse($ran);
        self::assertFalse($work->canSchedule());
    }

    #[Test]
    public function withoutAnAttestedCallNothingCanBeScheduled(): void
    {
        $work = new McpDeferredWork();
        self::assertFalse($work->canSchedule());
        self::assertFalse($work->add(static function (): void {}), 'D14: no call context, no deferred work.');
        self::assertSame(0, $work->count());
    }

    #[Test]
    public function beginDropsWorkOfTheCallBefore(): void
    {
        $work = new McpDeferredWork();
        $work->begin(self::CONTEXT);
        $work->add(static function (): void {});
        $work->begin(['callId' => 'c2'] + self::CONTEXT);
        self::assertSame(0, $work->count());
    }
}
