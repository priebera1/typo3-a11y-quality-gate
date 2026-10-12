<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro\ViewModel;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\ViewModel\RemoteEmptyState;
use Priebera\A11yQualityGate\Pro\ViewModel\RemoteScanCoverage;

/**
 * What the Overview says when a frontend scan has no priority fixes or no page rows.
 *
 * Regression guard (audit C1/C9): a page scan that could not load its page showed "No priority fixes needed" and
 * "No completed site frontend scan yet" under "No page could be scanned", an all-clear for a page that was never checked.
 */
final class RemoteEmptyStateTest extends TestCase
{
    #[Test]
    public function aScanThatCheckedNoPageIsNeverAnAllClear(): void
    {
        $coverage = RemoteScanCoverage::fromScanRow(['pages_scanned' => 0, 'pages_failed' => 1, 'pages_total' => 1]);

        self::assertSame(RemoteEmptyState::PRIORITY_NO_COVERAGE, RemoteEmptyState::priorityFixes($coverage));
        self::assertSame(RemoteEmptyState::PAGES_NO_COVERAGE, RemoteEmptyState::affectedPages(true, $coverage, false));
    }

    #[Test]
    public function anIncompleteScanWithoutPriorityFixesSaysOnlyTheCheckedPagesAreClear(): void
    {
        $coverage = RemoteScanCoverage::fromScanRow(['pages_scanned' => 3, 'pages_failed' => 2, 'pages_total' => 5]);

        self::assertSame(RemoteEmptyState::PRIORITY_INCOMPLETE, RemoteEmptyState::priorityFixes($coverage));
    }

    #[Test]
    public function onlyACompleteScanWithoutPriorityFixesIsClear(): void
    {
        $coverage = RemoteScanCoverage::fromScanRow(['pages_scanned' => 4, 'pages_failed' => 0, 'pages_total' => 4]);

        self::assertSame(RemoteEmptyState::PRIORITY_CLEAR, RemoteEmptyState::priorityFixes($coverage));
    }

    #[Test]
    public function aMissingCoverageIsTreatedAsNotChecked(): void
    {
        self::assertSame(RemoteEmptyState::PRIORITY_NO_COVERAGE, RemoteEmptyState::priorityFixes(null));
    }

    #[Test]
    public function theAffectedPagesEmptyStateDescribesTheScanShown(): void
    {
        $loaded = RemoteScanCoverage::fromScanRow(['pages_scanned' => 2, 'pages_failed' => 0, 'pages_total' => 2]);

        // Only without any stored frontend scan does the list ask for a site scan.
        self::assertSame(RemoteEmptyState::PAGES_NO_SCAN, RemoteEmptyState::affectedPages(false, null, false));
        self::assertSame(RemoteEmptyState::PAGES_RULE, RemoteEmptyState::affectedPages(true, $loaded, true));
        self::assertSame(RemoteEmptyState::PAGES_NOT_STORED, RemoteEmptyState::affectedPages(true, $loaded, false));
    }
}
