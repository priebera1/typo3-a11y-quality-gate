<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The Overview told an editor who had selected a page to "Select a page inside a configured TYPO3 site root". TYPO3
 * reports "no site" alike for a page outside every Site Configuration and for an id that names no page; only the
 * first is fixed by creating a Site Configuration, so the two are told apart.
 */
final class PageSiteContextFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        foreach ([
            [1, 0, 'Site root', 1, 0, 0],
            [2, 1, 'Page in the site', 0, 0, 0],
            [200, 0, 'Tree without a Site Configuration', 0, 0, 0],
            [201, 200, 'About us', 0, 0, 0],
            [202, 200, 'Hidden page', 0, 1, 0],
            [203, 200, 'Deleted page', 0, 0, 1],
        ] as [$uid, $pid, $title, $isRoot, $hidden, $deleted]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid,
                'pid' => $pid,
                'title' => $title,
                'doktype' => 1,
                'is_siteroot' => $isRoot,
                'hidden' => $hidden,
                'deleted' => $deleted,
            ]);
        }

        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://www.example.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    #[Test]
    public function aSelectedPageOutsideEverySiteIsToldApartFromAMissingPage(): void
    {
        $sites = $this->get(SiteResolutionService::class);

        self::assertSame(SiteResolutionService::PAGE_CONTEXT_NO_PAGE, $sites->describePageSiteContext(0));
        self::assertSame(SiteResolutionService::PAGE_CONTEXT_IN_SITE, $sites->describePageSiteContext(1));
        self::assertSame(SiteResolutionService::PAGE_CONTEXT_IN_SITE, $sites->describePageSiteContext(2));
        self::assertSame(SiteResolutionService::PAGE_CONTEXT_OUTSIDE_SITE, $sites->describePageSiteContext(200));
        self::assertSame(SiteResolutionService::PAGE_CONTEXT_OUTSIDE_SITE, $sites->describePageSiteContext(201));
        self::assertSame(SiteResolutionService::PAGE_CONTEXT_OUTSIDE_SITE, $sites->describePageSiteContext(202), 'A hidden page is still a page of the tree.');
        self::assertSame(SiteResolutionService::PAGE_CONTEXT_NOT_FOUND, $sites->describePageSiteContext(203), 'A deleted page is no selected page.');
        self::assertSame(SiteResolutionService::PAGE_CONTEXT_NOT_FOUND, $sites->describePageSiteContext(999));
    }
}
