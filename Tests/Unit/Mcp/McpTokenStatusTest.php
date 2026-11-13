<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Mcp\McpTokenStatus;

/** G6 (2026-11-11): a new 7-day agent connection is "Active", not "Expiring soon" (same rule as the Gateway). */
final class McpTokenStatusTest extends TestCase
{
    private const T0 = 1794398400; // 2026-11-11T12:00:00Z
    private const DAY = 86400;

    private static function at(int $offset): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z', self::T0 + $offset);
    }

    #[Test]
    public function aNewSevenDayConnectionIsActiveUntilItsLastQuarter(): void
    {
        $created = self::at(0);
        $expires = self::at(7 * self::DAY);
        foreach (['active', 'expiring_soon'] as $reported) {
            self::assertSame('active', McpTokenStatus::resolve($reported, $created, $expires, self::T0), 'a Gateway with the old window says ' . $reported);
            self::assertSame('active', McpTokenStatus::resolve($reported, $created, $expires, self::T0 + 5 * self::DAY));
            self::assertSame('expiring_soon', McpTokenStatus::resolve($reported, $created, $expires, self::T0 + 7 * self::DAY - 42 * 3600));
        }
    }

    #[Test]
    public function longerConnectionsKeepTheSevenDayWindowAndEndedOnesStayEnded(): void
    {
        self::assertSame('active', McpTokenStatus::resolve('active', self::at(0), self::at(30 * self::DAY), self::T0 + 22 * self::DAY));
        self::assertSame('expiring_soon', McpTokenStatus::resolve('active', self::at(0), self::at(30 * self::DAY), self::T0 + 23 * self::DAY));
        self::assertSame('expiring_soon', McpTokenStatus::resolve('active', self::at(0), self::at(90 * self::DAY), self::T0 + 85 * self::DAY));
        self::assertSame('expired', McpTokenStatus::resolve('active', self::at(0), self::at(7 * self::DAY), self::T0 + 7 * self::DAY));
        self::assertSame('revoked', McpTokenStatus::resolve('revoked', self::at(0), self::at(7 * self::DAY), self::T0));
        self::assertSame('expired', McpTokenStatus::resolve('something-else', self::at(0), self::at(7 * self::DAY), self::T0));
        self::assertSame('expiring_soon', McpTokenStatus::resolve('expiring_soon', '', self::at(7 * self::DAY), self::T0), 'without times the Gateway decides');
    }
}
