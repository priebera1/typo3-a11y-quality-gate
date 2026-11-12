<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Pro;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanTreeCoverageService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * C-01 (2026-11-05 company acceptance): a crawl without sitemap checked the 5 navigation pages of a larger page tree and
 * reported "5 pages loaded". Coverage now compares the scan's recorded TYPO3 pages with the site's public pages.
 *
 * Tree: 1 root · 2 Products · 3 E-Scooters · 4 Cargo Bikes · 5 Services · 6 Maintenance · 7 hidden · 8 scheduled ·
 * 9 restricted (fe_group) · 10 folder (doktype 254) · 20 other site root. Public inventory: 1–6.
 */
final class RemoteScanTreeCoverageFunctionalTest extends AbstractFunctionalTestCase
{
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $pages = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');
        foreach ([
            [1, 0, 'Home', 1, []], [2, 1, 'Products', 1, []], [3, 2, 'E-Scooters', 1, []], [4, 2, 'Cargo Bikes', 1, []],
            [5, 1, 'Services', 1, []], [6, 5, 'Maintenance', 1, []], [7, 1, 'Hidden draft', 1, ['hidden' => 1]],
            [8, 1, 'Scheduled', 1, ['starttime' => time() + 86400]], [9, 1, 'Members', 1, ['fe_group' => '1']], [10, 1, 'Storage', 254, []],
        ] as [$uid, $pid, $title, $doktype, $extra]) {
            $pages->insert('pages', ['uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => '/' . $uid, 'doktype' => $doktype, 'is_siteroot' => $uid === 1 ? 1 : 0] + $extra);
        }
        $pages->insert('pages', ['uid' => 20, 'pid' => 1, 'title' => 'Careers', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1]);
        $this->get(SiteWriter::class)->createNewBasicSite('northwind', 1, 'https://northwind.invalid/');
        $this->site = $this->get(SiteFinder::class)->getSiteByIdentifier('northwind');
    }

    #[Test]
    public function aCrawlWithoutSitemapThatReachedTheNavigationOnlyIsPartialWithItsNextStep(): void
    {
        $scan = $this->scan(1, 'crawl', 3, 0, [[1, 0], [2, 0], [5, 0]]);
        $coverage = $this->get(RemoteScanTreeCoverageService::class)->forScan($scan, $this->site);

        self::assertSame('partial', $coverage['state']);
        self::assertSame([6, 3, 3], [$coverage['inventory'], $coverage['checked'], $coverage['notReached']]);
        self::assertSame(['E-Scooters', 'Cargo Bikes', 'Maintenance'], array_column($coverage['examples'], 'title'));
        self::assertSame(['no_sitemap'], $coverage['reasons']);
    }

    #[Test]
    public function aSitemapScanOfEveryPublicPageIsComplete(): void
    {
        $scan = $this->scan(2, 'sitemap', 6, 0, [[1, 0], [2, 0], [3, 0], [4, 0], [5, 0], [6, 0]]);
        $coverage = $this->get(RemoteScanTreeCoverageService::class)->forScan($scan, $this->site);

        self::assertSame('complete', $coverage['state']);
        self::assertSame([], $coverage['reasons'], 'Hidden, no_index, restricted, folders and other sites are not expected.');
    }

    #[Test]
    public function aScanThatStoppedAtItsPageLimitSaysSo(): void
    {
        $scan = $this->scan(3, 'sitemap', 4, 4, [[1, 0], [2, 0], [3, 0], [4, 0]]);
        $coverage = $this->get(RemoteScanTreeCoverageService::class)->forScan($scan, $this->site);

        self::assertSame('partial', $coverage['state']);
        self::assertSame(['page_limit', 'outside_sitemap'], $coverage['reasons']);
    }

    #[Test]
    public function failedPagesKeepTheScanPartial(): void
    {
        $scan = $this->scan(4, 'sitemap', 6, 0, [[1, 0], [2, 0], [3, 0], [4, 0], [5, 0], [6, 1]]);
        $coverage = $this->get(RemoteScanTreeCoverageService::class)->forScan($scan, $this->site);

        self::assertSame('partial', $coverage['state']);
        self::assertSame([5, 1, 0], [$coverage['checked'], $coverage['failed'], $coverage['notReached']]);
        self::assertSame(['failed'], $coverage['reasons']);
    }

    #[Test]
    public function aScanWithoutRecordedPagesHasUnknownCoverageNotAPercentage(): void
    {
        $scan = $this->scan(5, 'crawl', 5, 0, [[0, 0], [0, 0]]);
        $coverage = $this->get(RemoteScanTreeCoverageService::class)->forScan($scan, $this->site);

        self::assertSame('unknown', $coverage['state']);
        self::assertSame(0, $coverage['notReached']);
    }

    #[Test]
    public function runningFailedPageAndFreeScansAreNotDescribed(): void
    {
        $service = $this->get(RemoteScanTreeCoverageService::class);
        self::assertNull($service->forScan($this->scan(6, 'sitemap', 1, 0, [[1, 0]], ['status' => 'failed']), $this->site));
        self::assertNull($service->forScan($this->scan(7, 'single_page', 1, 0, [[1, 0]], ['scan_scope' => 'page']), $this->site));
        self::assertNull($service->forScan($this->scan(8, 'sitemap', 1, 0, [[1, 0]], ['is_free_preview' => 1]), $this->site));
    }

    /**
     * @param list<array{0:int,1:int}> $pages owner page uid, failed
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function scan(int $uid, string $source, int $pagesTotal, int $maxPages, array $pages, array $overrides = []): array
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $row = array_replace(['uid' => $uid, 'site_identifier' => 'northwind', 'job_id' => 'job-' . $uid, 'source_type' => $source, 'scan_scope' => 'site',
            'language_uid' => 0, 'start_url' => 'https://northwind.invalid/', 'status' => 'completed', 'pages_scanned' => count($pages),
            'pages_total' => $pagesTotal, 'max_pages' => $maxPages], $overrides);
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', $row);
        foreach ($pages as $index => [$owner, $failed]) {
            $pool->getConnectionForTable('tx_a11y_remote_scan_page')->insert('tx_a11y_remote_scan_page', [
                'remote_scan' => $uid, 'url' => 'https://northwind.invalid/p' . $index, 'owner_page_uid' => $owner, 'owner_state' => $owner > 0 ? 1 : 0, 'is_failed' => $failed,
            ]);
        }

        return $row;
    }
}
