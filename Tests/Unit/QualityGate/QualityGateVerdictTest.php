<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\QualityGate;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\QualityGate\QualityGateVerdict;

/**
 * Unit tests for QualityGateVerdict.
 *
 * QualityGateChecker itself requires DB (functional test territory).
 * Here we test the verdict value object in isolation.
 */
class QualityGateVerdictTest extends TestCase
{
    #[Test]
    public function passVerdictIsPassed(): void
    {
        $verdict = QualityGateVerdict::pass();

        self::assertTrue($verdict->isPassed());
        self::assertFalse($verdict->isFailed());
        self::assertSame(0, $verdict->mode);
        self::assertEmpty($verdict->reasons);
    }

    #[Test]
    public function failVerdictIsFailed(): void
    {
        $verdict = QualityGateVerdict::fail(
            mode: 1,
            counts: ['critical' => 3, 'warning' => 5, 'info' => 0],
            reasons: ['3 critical issue(s)'],
        );

        self::assertFalse($verdict->isPassed());
        self::assertTrue($verdict->isFailed());
        self::assertSame(1, $verdict->mode);
        self::assertSame(3, $verdict->counts['critical']);
        self::assertCount(1, $verdict->reasons);
    }

    #[Test]
    public function flashMessageContainsReasons(): void
    {
        $verdict = QualityGateVerdict::fail(
            mode: 1,
            counts: ['critical' => 2, 'warning' => 0, 'info' => 0],
            reasons: ['2 critical issue(s)'],
        );

        self::assertStringContainsString('2 critical', $verdict->toFlashMessage());
    }

    #[Test]
    public function flashMessageWithMultipleReasonsContainsAll(): void
    {
        $verdict = QualityGateVerdict::fail(
            mode: 1,
            counts: ['critical' => 2, 'warning' => 8, 'info' => 0],
            reasons: ['2 critical issue(s)', '8 warning(s) (threshold: 5)'],
        );

        $msg = $verdict->toFlashMessage();

        self::assertStringContainsString('2 critical', $msg);
        self::assertStringContainsString('8 warning', $msg);
    }

    #[Test]
    public function passVerdictCountsAreZero(): void
    {
        $verdict = QualityGateVerdict::pass();

        self::assertSame(0, $verdict->counts['critical']);
        self::assertSame(0, $verdict->counts['warning']);
        self::assertSame(0, $verdict->counts['info']);
    }

    #[Test]
    public function failVerdictPreservesProvidedPayload(): void
    {
        $counts = ['critical' => 1, 'warning' => 2, 'info' => 3];
        $reasons = ['1 critical issue(s)', '2 warning(s)'];

        $verdict = QualityGateVerdict::fail(
            mode: 1,
            counts: $counts,
            reasons: $reasons,
        );

        self::assertSame($counts, $verdict->counts);
        self::assertSame($reasons, $verdict->reasons);
        self::assertSame(1, $verdict->mode);
    }
}
