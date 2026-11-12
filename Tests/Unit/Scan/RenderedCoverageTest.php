<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Scan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Scan\RenderedCoverage;

final class RenderedCoverageTest extends TestCase
{
    #[Test]
    public function aSkippedPageIsFoundWithItsReason(): void
    {
        $scan = ['rendered_skipped' => 2, 'rendered_skipped_json' => '[{"pageUid":744,"statusCode":404,"message":"Rendered page returned HTTP 404."},{"pageUid":732,"statusCode":0,"message":"Rendered page fetch timed out."}]'];

        // Scans recorded before the visitor restriction was stored have no reason.
        self::assertSame(['pageUid' => 744, 'statusCode' => 404, 'message' => 'Rendered page returned HTTP 404.', 'reason' => ''], RenderedCoverage::skippedEntryFor($scan, 744));
        self::assertSame(0, RenderedCoverage::skippedEntryFor($scan, 732)['statusCode']);
        self::assertNull(RenderedCoverage::skippedEntryFor($scan, 729));
    }

    #[Test]
    public function aHiddenPageIsDescribedAsSuchWithItsStatus(): void
    {
        $translate = static fn (string $key, string $fallback): string => $fallback;

        self::assertSame("Hidden page · HTTP\u{00A0}404", RenderedCoverage::describe(['statusCode' => 404, 'reason' => 'hidden'], $translate));
        self::assertSame('Limited to frontend user groups', RenderedCoverage::describe(['statusCode' => 0, 'reason' => 'restricted'], $translate));
        self::assertSame("HTTP\u{00A0}500", RenderedCoverage::describe(['statusCode' => 500, 'reason' => ''], $translate));
        self::assertSame('Rendered page fetch timed out.', RenderedCoverage::describe(['statusCode' => 0, 'message' => 'Rendered page fetch timed out.'], $translate));

        $scan = ['rendered_skipped' => 3, 'rendered_skipped_json' => '[{"pageUid":744,"reason":"hidden"},{"pageUid":743,"reason":"scheduled"},{"pageUid":9,"reason":""}]'];
        self::assertSame(2, RenderedCoverage::countNotPublic($scan));
    }

    #[Test]
    public function aScanWithoutARecordReportsNothing(): void
    {
        self::assertNull(RenderedCoverage::skippedEntryFor(['uid' => 1], 744));
        self::assertNull(RenderedCoverage::skippedEntryFor(['rendered_skipped' => 1, 'rendered_skipped_json' => 'not json'], 744));
    }

    #[Test]
    public function theNewerOfTwoScansDescribesThePage(): void
    {
        $page = ['uid' => 2, 'finished_at' => 200];
        $site = ['uid' => 1, 'finished_at' => 100];

        self::assertSame($page, RenderedCoverage::newerScan($page, $site));
        self::assertSame($site, RenderedCoverage::newerScan(null, $site));
        self::assertNull(RenderedCoverage::newerScan(null, null));
    }

    /**
     * U-02 (2026-11-05 company acceptance): 16 pages skipped for one reason ("private/local address") were listed as 16
     * identical lines. Pages are grouped by their reason, the largest group first; each group keeps its pages.
     */
    #[Test]
    public function identicalReasonsAreGroupedWithTheirPages(): void
    {
        $private = 'The frontend host resolves to a private or local address.';
        $groups = RenderedCoverage::groupByReason([
            ['pageUid' => 2, 'title' => 'Products', 'reason' => $private],
            ['pageUid' => 3, 'title' => 'Draft', 'reason' => 'Hidden page'],
            ['pageUid' => 4, 'title' => 'E-Scooters', 'reason' => $private],
            ['pageUid' => 5, 'title' => 'Services', 'reason' => $private],
        ]);

        self::assertSame([$private, 'Hidden page'], array_column($groups, 'reason'));
        self::assertSame([3, 1], array_column($groups, 'count'));
        self::assertSame(['Products', 'E-Scooters', 'Services'], array_column($groups[0]['pages'], 'title'));
    }

    /**
     * U-08 (2026-11-05 company acceptance): a scheduled subtree scan runs no rendered check, so a rendered finding stayed
     * open after the fix and looked as current as the scan. An open rendered finding the newest covering scan did not
     * confirm is marked as not re-checked; content findings and findings that scan confirmed are not.
     */
    #[Test]
    public function anOpenRenderedFindingOlderThanTheCoveringScanIsNotRechecked(): void
    {
        $scheduled = ['uid' => 40, 'finished_at' => 1790000000];
        $rendered = ['source_type' => 'rendered', 'status' => 0, 'last_seen_scan_uid' => 31];

        self::assertTrue(RenderedCoverage::isNotRechecked($rendered, $scheduled));
        self::assertFalse(RenderedCoverage::isNotRechecked(['last_seen_scan_uid' => 40] + $rendered, $scheduled), 'confirmed by that scan');
        self::assertFalse(RenderedCoverage::isNotRechecked(['source_type' => 'rte'] + $rendered, $scheduled), 'content checks run in every scan');
        self::assertFalse(RenderedCoverage::isNotRechecked(['status' => 1] + $rendered, $scheduled), 'resolved');
        self::assertFalse(RenderedCoverage::isNotRechecked($rendered, null));
        self::assertFalse(RenderedCoverage::isNotRechecked(['last_seen_scan_uid' => 0] + $rendered, $scheduled), 'no scan reference');
    }
}
