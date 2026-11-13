<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\OverviewController;

/**
 * Regression: the Overview's "N resolved by this scan" came from the scan row, which counts every page the scan
 * processed, also for an editor restricted by page permissions. Such a user gets no scan-wide resolved or ignored count.
 */
final class OverviewScanWideCountsTest extends TestCase
{
    private const SCAN = ['uid' => 7, 'issues_new' => 99, 'issues_resolved' => 5, 'issues_ignored' => 2, 'pages_scanned' => 4];

    #[Test]
    public function aPageRestrictedUserGetsNoScanWideResolvedOrIgnoredCount(): void
    {
        $scan = OverviewController::withoutScanWideCounts(self::SCAN, 'pages.perms_everybody & 1 = 1');

        self::assertSame(0, $scan['issues_resolved']);
        self::assertSame(0, $scan['issues_ignored']);
        self::assertSame(7, $scan['uid']);
    }

    #[Test]
    public function anAdministratorKeepsTheScansOwnCounts(): void
    {
        self::assertSame(self::SCAN, OverviewController::withoutScanWideCounts(self::SCAN, ''));
        self::assertNull(OverviewController::withoutScanWideCounts(null, 'pages.perms_everybody & 1 = 1'));
    }
}
