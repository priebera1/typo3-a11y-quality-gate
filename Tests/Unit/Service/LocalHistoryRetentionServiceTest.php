<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\LocalHistoryRetentionService;

/**
 * The local frontend scan history is kept by default and, with a period, offers only scans that are safe to remove:
 * finished, older than the calendar cutoff, not the newest completed scan of their comparison group, not needed by a
 * pending Verify fix or by monitoring. Nothing here deletes on its own (the service's prune() needs the reviewed count).
 */
final class LocalHistoryRetentionServiceTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const DAY = 86400;

    /** @return array<string, mixed> */
    private static function scan(int $uid, int $daysAgo, string $status = 'completed', array $group = []): array
    {
        return $group + [
            'uid' => $uid,
            'job_id' => 'job-' . $uid,
            'status' => $status,
            'finished_at' => $status === 'active' ? 0 : self::NOW - $daysAgo * self::DAY,
            'crdate' => self::NOW - $daysAgo * self::DAY,
            'site_identifier' => 'main',
            'scan_scope' => 'site',
            'page_uid' => 0,
            'language_uid' => 0,
            'source_type' => 'sitemap',
            'start_url' => 'https://example.org/',
            'is_free_preview' => 0,
        ];
    }

    #[Test]
    public function keepIsTheDefaultAndHasNoCutoff(): void
    {
        self::assertNull(LocalHistoryRetentionService::cutoffFor('keep', self::NOW));
        self::assertNull(LocalHistoryRetentionService::cutoffFor('unknown', self::NOW));
        self::assertSame(['keep', '1y', '3y', '5y'], array_keys(LocalHistoryRetentionService::OPTIONS));
    }

    /**
     * Calendar years in UTC, the same time of day: a leap day in between does not make the period a day shorter, and
     * 29 February counts back to 28 February (the earlier day), so a scan younger than the period is never offered.
     */
    #[Test]
    #[DataProvider('calendarCutoffs')]
    public function periodsAreCalendarYearsInUtc(string $option, string $now, string $expected): void
    {
        $utc = new \DateTimeZone('UTC');
        self::assertSame(
            $expected,
            gmdate('Y-m-d H:i:s', (int)LocalHistoryRetentionService::cutoffFor($option, (new \DateTimeImmutable($now, $utc))->getTimestamp()))
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function calendarCutoffs(): iterable
    {
        yield '1y across no leap day' => ['1y', '2026-10-20 18:40:12', '2025-10-20 18:40:12'];
        yield '3y across 29 Feb 2024' => ['3y', '2026-10-20 18:40:12', '2023-10-20 18:40:12'];
        yield '5y across 29 Feb 2024' => ['5y', '2026-10-20 18:40:12', '2021-10-20 18:40:12'];
        yield '1y from 29 Feb' => ['1y', '2028-02-29 10:00:00', '2027-02-28 10:00:00'];
        yield '3y from 29 Feb' => ['3y', '2028-02-29 00:00:01', '2025-02-28 00:00:01'];
        yield '1y on 1 Mar after a leap year' => ['1y', '2025-03-01 00:00:00', '2024-03-01 00:00:00'];
        yield '5y onto a leap day' => ['5y', '2029-02-28 23:59:59', '2024-02-28 23:59:59'];
    }

    /** The boundary is strict: finished exactly at the cutoff is kept, one second earlier is offered. */
    #[Test]
    public function aScanFinishedExactlyAtTheCutoffIsKept(): void
    {
        $cutoff = (int)LocalHistoryRetentionService::cutoffFor('1y', self::NOW);
        $scans = [
            ['finished_at' => $cutoff] + self::scan(1, 0),
            ['finished_at' => $cutoff - 1] + self::scan(2, 0),
            self::scan(3, 1),
        ];

        self::assertSame([2], LocalHistoryRetentionService::selectPrunable($scans, [], [], $cutoff));
    }

    /** Every older scan is either selected or kept for exactly one reason, which the review shows. */
    #[Test]
    public function olderScansAreSelectedOrKeptForOneReason(): void
    {
        $scans = [
            self::scan(1, 900),                  // superseded → selected
            self::scan(2, 900, 'active'),        // running
            self::scan(3, 800),                  // pending Verify fix
            self::scan(4, 700),                  // monitoring
            self::scan(5, 600, 'completed', ['site_identifier' => 'other']), // newest of its group
            self::scan(6, 10),                   // within the period: neither
        ];
        $result = LocalHistoryRetentionService::classify($scans, [3], ['job-4'], (int)LocalHistoryRetentionService::cutoffFor('1y', self::NOW));

        self::assertSame([1], $result['selected']);
        self::assertSame(5, $result['olderTotal']);
        self::assertSame(['running' => 1, 'baseline' => 1, 'verification' => 1, 'monitoring' => 1], $result['protected']);
    }

    /** Only a stored scan can be paired: the newest stored one stays even when a newer scan was not stored. */
    #[Test]
    public function theNewestStoredScanOfAGroupIsKeptNextToTheNewestCompletedOne(): void
    {
        $scans = [
            ['persisted_at' => 1] + self::scan(1, 900),
            ['persisted_at' => 1] + self::scan(2, 800),
            ['persisted_at' => 0] + self::scan(3, 700),
        ];
        $result = LocalHistoryRetentionService::classify($scans, [], [], (int)LocalHistoryRetentionService::cutoffFor('1y', self::NOW));

        self::assertSame([1], $result['selected']);
        self::assertSame(2, $result['protected']['baseline']);
    }

    #[Test]
    public function theReviewBreaksTheSelectionDownBySiteAndLanguage(): void
    {
        self::assertSame(
            [
                ['site' => 'a', 'language' => -1, 'scans' => 1],
                ['site' => 'a', 'language' => 0, 'scans' => 2],
                ['site' => 'b', 'language' => 1, 'scans' => 1],
            ],
            LocalHistoryRetentionService::breakdown([
                ['site_identifier' => 'b', 'language_uid' => 1],
                ['site_identifier' => 'a', 'language_uid' => 0],
                ['site_identifier' => 'a', 'language_uid' => -1],
                ['site_identifier' => 'a', 'language_uid' => 0],
            ])
        );
    }

    #[Test]
    public function timesAreShownInUtc(): void
    {
        self::assertSame('2027-01-15 08:00:00 UTC', LocalHistoryRetentionService::formatUtc(1_800_000_000));
        self::assertSame('', LocalHistoryRetentionService::formatUtc(0));
    }

    #[Test]
    public function onlyOldFinishedSupersededUnreferencedScansAreOffered(): void
    {
        $scans = [
            self::scan(1, 900),                  // old, superseded → offered
            self::scan(2, 800, 'failed'),        // old failed → offered
            self::scan(3, 700),                  // old, referenced by Verify fix → kept
            self::scan(4, 600),                  // old, referenced by monitoring → kept
            self::scan(5, 500),                  // newest completed of the group, although old → kept (baseline)
            self::scan(6, 900, 'active'),        // running → kept
            self::scan(7, 100, 'failed'),        // within the period → kept
            self::scan(8, 900, 'completed', ['site_identifier' => 'other']), // alone in its group → kept
            self::scan(9, 950, 'completed', ['is_free_preview' => 1]),       // newest Free preview of its group → kept
            self::scan(10, 990, 'completed', ['is_free_preview' => 1]),      // older Free preview → offered
        ];
        $cutoff = LocalHistoryRetentionService::cutoffFor('1y', self::NOW);
        $selected = LocalHistoryRetentionService::selectPrunable($scans, [3], ['job-4'], (int)$cutoff);

        self::assertSame([1, 2, 10], $selected);
    }

    #[Test]
    public function aFiveYearPeriodKeepsAThreeYearOldScan(): void
    {
        $scans = [self::scan(1, 3 * 365), self::scan(2, 10)];
        self::assertSame([], LocalHistoryRetentionService::selectPrunable($scans, [], [], (int)LocalHistoryRetentionService::cutoffFor('5y', self::NOW)));
        self::assertSame([1], LocalHistoryRetentionService::selectPrunable($scans, [], [], (int)LocalHistoryRetentionService::cutoffFor('1y', self::NOW)));
    }
}
