<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro\ViewModel;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\ViewModel\RemoteOccurrenceTotals;

/**
 * Regression: the Frontend overview PDF said "408 occurrences" over a severity row of 40 + 48 + 227 + 0 = 315. The row
 * summed only the ten priority fixes, and the headline was the crawler's run counter, which also counted a node it did
 * not store. Totals now come from the stored findings, every occurrence lands in exactly one impact (or "Not rated"),
 * and a gap to the crawler's counter is stated, never hidden.
 */
final class RemoteOccurrenceTotalsTest extends TestCase
{
    #[Test]
    public function theBreakdownAlwaysAddsUpToTheTotal(): void
    {
        $totals = RemoteOccurrenceTotals::fromStored(
            ['critical' => 40, 'serious' => 48, 'moderate' => 309, 'minor' => 10, '' => 0],
            16,
            408,
        );

        self::assertSame(407, $totals->total);
        self::assertSame(['critical' => 40, 'serious' => 48, 'moderate' => 309, 'minor' => 10, 'unrated' => 0], $totals->byImpact);
        self::assertSame(407, array_sum($totals->byImpact));
        self::assertSame(16, $totals->issueTypes);
        self::assertSame(1, $totals->notStored, 'The crawler counted one occurrence that is not in the stored results.');
    }

    #[Test]
    public function anUnknownImpactIsNotRatedInsteadOfDropped(): void
    {
        $totals = RemoteOccurrenceTotals::fromStored(['critical' => 2, 'unknown' => 3, '' => 1, 'Serious' => 4], 4, 10);

        self::assertSame(['critical' => 2, 'serious' => 4, 'moderate' => 0, 'minor' => 0, 'unrated' => 4], $totals->byImpact);
        self::assertSame(10, $totals->total);
        self::assertSame(0, $totals->notStored);
    }

    #[Test]
    public function theSeverityRowShowsNotRatedOnlyWhenItHasOccurrences(): void
    {
        $rated = RemoteOccurrenceTotals::fromStored(['critical' => 40, 'serious' => 48, 'moderate' => 309, 'minor' => 10], 16, 407);
        self::assertSame(['Critical', 'Serious', 'Moderate', 'Minor'], array_column($rated->severityRow(), 'label'));
        self::assertSame(407, array_sum(array_column($rated->severityRow(), 'count')));

        $unrated = RemoteOccurrenceTotals::fromStored(['critical' => 1, '' => 2], 2, 3);
        self::assertSame(['Critical', 'Serious', 'Moderate', 'Minor', 'Not rated'], array_column($unrated->severityRow(), 'label'));
        self::assertSame(['critical', 'serious', 'moderate', 'minor', 'none'], array_column($unrated->severityRow(), 'tone'));
        self::assertSame(['occurrence', 'occurrences', 'occurrences', 'occurrences', 'occurrences'], array_column($unrated->severityRow(), 'foot'));
    }

    #[Test]
    public function withoutStoredResultsTheCrawlerTotalIsShownWithoutABreakdown(): void
    {
        $totals = RemoteOccurrenceTotals::reportedOnly(12);

        self::assertSame(12, $totals->total);
        self::assertFalse($totals->hasBreakdown);
        self::assertSame(0, $totals->notStored);
    }
}
