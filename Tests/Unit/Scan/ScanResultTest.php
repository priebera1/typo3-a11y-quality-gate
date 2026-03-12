<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Scan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Scan\ScanResult;

class ScanResultTest extends TestCase
{
    #[Test]
    public function countersStartAtZero(): void
    {
        $result = new ScanResult(scanUid: 1);

        self::assertSame(0, $result->pagesScanned);
        self::assertSame(0, $result->recordsScanned);
        self::assertSame(0, $result->issuesNew);
        self::assertSame(0, $result->issuesResolved);
        self::assertSame(0, $result->issuesIgnored);
    }

    #[Test]
    public function scanUidIsAssignedFromConstructor(): void
    {
        $result = new ScanResult(scanUid: 42);

        self::assertSame(42, $result->scanUid);
    }

    #[Test]
    public function countersAreMutable(): void
    {
        $result = new ScanResult(scanUid: 1);

        $result->pagesScanned = 5;
        $result->recordsScanned = 20;
        $result->issuesNew = 3;
        $result->issuesResolved = 1;
        $result->issuesIgnored = 2;

        self::assertSame(5, $result->pagesScanned);
        self::assertSame(20, $result->recordsScanned);
        self::assertSame(3, $result->issuesNew);
        self::assertSame(1, $result->issuesResolved);
        self::assertSame(2, $result->issuesIgnored);
    }

    #[Test]
    public function summaryStringContainsAllCounters(): void
    {
        $result = new ScanResult(scanUid: 7);

        $result->pagesScanned = 10;
        $result->recordsScanned = 50;
        $result->issuesNew = 4;
        $result->issuesResolved = 2;
        $result->issuesIgnored = 1;

        $summary = $result->toSummaryString();

        self::assertStringContainsString('7', $summary);
        self::assertStringContainsString('10', $summary);
        self::assertStringContainsString('50', $summary);
        self::assertStringContainsString('4', $summary);
        self::assertStringContainsString('2', $summary);
        self::assertStringContainsString('1', $summary);
    }
}
