<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Domain\Enum;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;

/**
 * Unit tests for Severity and IssueStatus enums.
 *
 * Guards the contracts that the rest of the system depends on:
 *   - DB int values are stable (changing them would corrupt existing data)
 *   - isAtLeastAsSevereAs() logic is correct (lower int = more severe)
 *   - isProtected() covers exactly Ignored + Muted (no more, no less)
 *   - countsForQualityGate() covers only Open
 */
class EnumTest extends TestCase
{
    // =========================================================================
    // Severity — DB values must never change
    // =========================================================================

    #[Test]
    public function severityDbValuesAreStable(): void
    {
        self::assertSame(1, Severity::Critical->value);
        self::assertSame(2, Severity::Warning->value);
        self::assertSame(3, Severity::Info->value);
    }

    #[Test]
    public function severityFromIntReturnsCorrectCase(): void
    {
        self::assertSame(Severity::Critical, Severity::fromInt(1));
        self::assertSame(Severity::Warning, Severity::fromInt(2));
        self::assertSame(Severity::Info, Severity::fromInt(3));
    }

    #[Test]
    public function severityFromIntFallsBackToWarningOnUnknown(): void
    {
        self::assertSame(Severity::Warning, Severity::fromInt(99));
        self::assertSame(Severity::Warning, Severity::fromInt(0));
        self::assertSame(Severity::Warning, Severity::fromInt(-1));
    }

    /**
     * @param bool $expected
     */
    #[Test]
    #[DataProvider('severityComparisonProvider')]
    public function isAtLeastAsSevereAs(Severity $subject, Severity $threshold, bool $expected): void
    {
        self::assertSame($expected, $subject->isAtLeastAsSevereAs($threshold));
    }

    /**
     * @return array<string, array{Severity, Severity, bool}>
     */
    public static function severityComparisonProvider(): array
    {
        return [
            // Critical is at least as severe as everything
            'critical >= critical' => [Severity::Critical, Severity::Critical, true],
            'critical >= warning'  => [Severity::Critical, Severity::Warning,  true],
            'critical >= info'     => [Severity::Critical, Severity::Info,     true],
            // Warning is at least as severe as warning and info
            'warning >= critical'  => [Severity::Warning,  Severity::Critical, false],
            'warning >= warning'   => [Severity::Warning,  Severity::Warning,  true],
            'warning >= info'      => [Severity::Warning,  Severity::Info,     true],
            // Info is only at least as severe as itself
            'info >= critical'     => [Severity::Info,     Severity::Critical, false],
            'info >= warning'      => [Severity::Info,     Severity::Warning,  false],
            'info >= info'         => [Severity::Info,     Severity::Info,     true],
        ];
    }

    #[Test]
    public function allSeveritiesHaveLabel(): void
    {
        foreach (Severity::cases() as $severity) {
            self::assertNotEmpty($severity->label());
        }
    }

    #[Test]
    public function allSeveritiesHaveBadgeClass(): void
    {
        foreach (Severity::cases() as $severity) {
            self::assertNotEmpty($severity->badgeClass());
        }
    }

    #[Test]
    public function allSeveritiesHaveIconIdentifier(): void
    {
        foreach (Severity::cases() as $severity) {
            self::assertNotEmpty($severity->iconIdentifier());
        }
    }

    // =========================================================================
    // IssueStatus — DB values must never change
    // =========================================================================

    #[Test]
    public function issueStatusDbValuesAreStable(): void
    {
        self::assertSame(0, IssueStatus::Open->value);
        self::assertSame(1, IssueStatus::Resolved->value);
        self::assertSame(2, IssueStatus::Ignored->value);
        self::assertSame(3, IssueStatus::Muted->value);
    }

    #[Test]
    public function issueStatusFromIntReturnsCorrectCase(): void
    {
        self::assertSame(IssueStatus::Open, IssueStatus::fromInt(0));
        self::assertSame(IssueStatus::Resolved, IssueStatus::fromInt(1));
        self::assertSame(IssueStatus::Ignored, IssueStatus::fromInt(2));
        self::assertSame(IssueStatus::Muted, IssueStatus::fromInt(3));
    }

    #[Test]
    public function issueStatusFromIntFallsBackToOpenOnUnknown(): void
    {
        self::assertSame(IssueStatus::Open, IssueStatus::fromInt(99));
        self::assertSame(IssueStatus::Open, IssueStatus::fromInt(-1));
    }

    #[Test]
    public function onlyIgnoredAndMutedAreProtected(): void
    {
        self::assertFalse(IssueStatus::Open->isProtected());
        self::assertFalse(IssueStatus::Resolved->isProtected());
        self::assertTrue(IssueStatus::Ignored->isProtected());
        self::assertTrue(IssueStatus::Muted->isProtected());
    }

    #[Test]
    public function onlyOpenCountsForQualityGate(): void
    {
        self::assertTrue(IssueStatus::Open->countsForQualityGate());
        self::assertFalse(IssueStatus::Resolved->countsForQualityGate());
        self::assertFalse(IssueStatus::Ignored->countsForQualityGate());
        self::assertFalse(IssueStatus::Muted->countsForQualityGate());
    }

    #[Test]
    public function allStatusesHaveLabel(): void
    {
        foreach (IssueStatus::cases() as $status) {
            self::assertNotEmpty($status->label());
        }
    }

    #[Test]
    public function allStatusesHaveBadgeClass(): void
    {
        foreach (IssueStatus::cases() as $status) {
            self::assertNotEmpty($status->badgeClass());
        }
    }
}
