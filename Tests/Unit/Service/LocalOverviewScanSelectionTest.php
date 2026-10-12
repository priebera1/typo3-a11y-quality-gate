<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\LocalOverviewScanSelection;

/**
 * Which content scan the Overview's status describes, and with which scope.
 *
 * Regression guard: at a site root that only ever had page scans, and on a page without its own scan, the status said
 * "No scan on record" six times while the table below listed the open issues those page scans found.
 */
final class LocalOverviewScanSelectionTest extends TestCase
{
    #[Test]
    public function aSiteRootWithOnlyPageScansDescribesTheNewestPageScan(): void
    {
        $selection = LocalOverviewScanSelection::select(
            subtreeScan: null,
            currentPageScan: null,
            newestSiteScan: $this->scan(73, 'page', 463, 1000),
            isPageContext: false,
        );

        self::assertSame(73, $selection->scanUid());
        self::assertSame('page', $selection->scope);
        self::assertSame(463, $selection->scopePageUid);
    }

    #[Test]
    public function aPageWithoutItsOwnScanDescribesTheNewestScanOfTheSite(): void
    {
        $selection = LocalOverviewScanSelection::select(
            subtreeScan: null,
            currentPageScan: null,
            newestSiteScan: $this->scan(73, 'page', 463, 1000),
            isPageContext: true,
        );

        self::assertSame(73, $selection->scanUid());
        self::assertSame('page', $selection->scope);
    }

    #[Test]
    public function aSiteScanStaysTheStatusOfTheSiteRoot(): void
    {
        $selection = LocalOverviewScanSelection::select(
            subtreeScan: $this->scan(60, 'subtree', 461, 900),
            currentPageScan: null,
            newestSiteScan: $this->scan(73, 'page', 463, 1000),
            isPageContext: false,
        );

        self::assertSame(60, $selection->scanUid(), 'A newer page scan of another page must not replace the site scan.');
        self::assertSame('site', $selection->scope);
    }

    #[Test]
    public function aPageKeepsTheNewerOfItsOwnScanAndTheSiteScan(): void
    {
        $own = LocalOverviewScanSelection::select($this->scan(60, 'subtree', 461, 900), $this->scan(64, 'page', 472, 950), null, true);
        self::assertSame([64, 'page', 472], [$own->scanUid(), $own->scope, $own->scopePageUid]);

        $site = LocalOverviewScanSelection::select($this->scan(60, 'subtree', 461, 990), $this->scan(64, 'page', 472, 950), null, true);
        self::assertSame([60, 'site'], [$site->scanUid(), $site->scope]);
    }

    #[Test]
    public function withoutAnyCompletedScanThereIsNoScan(): void
    {
        $selection = LocalOverviewScanSelection::select(null, null, null, false);

        self::assertNull($selection->scan);
        self::assertSame('none', $selection->scope);
    }

    /**
     * @return array<string, int|string>
     */
    private function scan(int $uid, string $scope, int $rootPid, int $finishedAt): array
    {
        return ['uid' => $uid, 'scope' => $scope, 'root_pid' => $rootPid, 'finished_at' => $finishedAt, 'status' => 2];
    }
}
