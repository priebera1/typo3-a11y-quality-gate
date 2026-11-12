<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Service\FrontendPageUrlService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * A frontend URL maps to the TYPO3 page of its own site. Slugs are unique per site only, so the same slug in another
 * site (with a lower uid) never stands in for it; a hidden live page still maps, a workspace version does not. Page
 * permission checks on frontend results (MCP findings:read) rely on this mapping.
 */
final class FrontendPageUrlSiteScopeFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        foreach ([
            ['uid' => 1, 'pid' => 0, 'slug' => '/', 'is_siteroot' => 1],
            ['uid' => 100, 'pid' => 0, 'slug' => '/', 'is_siteroot' => 1],
            ['uid' => 5, 'pid' => 100, 'slug' => '/internal'],
            ['uid' => 30, 'pid' => 1, 'slug' => '/internal'],
            ['uid' => 31, 'pid' => 1, 'slug' => '/draft-only', 't3ver_wsid' => 3],
            ['uid' => 32, 'pid' => 1, 'slug' => '/hidden', 'hidden' => 1],
        ] as $page) {
            $pages->insert('pages', $page + ['title' => 'Page', 'doktype' => 1]);
        }
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->createNewBasicSite('site-a', 1, 'https://a.test/');
        $siteWriter->createNewBasicSite('site-b', 100, 'https://b.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    #[Test]
    public function aUrlMapsToThePageOfItsOwnSite(): void
    {
        $service = $this->get(FrontendPageUrlService::class);
        $sites = $this->get(SiteFinder::class);

        self::assertSame(30, $service->resolvePageUidForUrl($sites->getSiteByIdentifier('site-a'), 'https://a.test/internal', 0));
        self::assertSame(5, $service->resolvePageUidForUrl($sites->getSiteByIdentifier('site-b'), 'https://b.test/internal', 0));
        self::assertSame(32, $service->resolvePageUidForUrl($sites->getSiteByIdentifier('site-a'), 'https://a.test/hidden', 0));
        self::assertSame(0, $service->resolvePageUidForUrl($sites->getSiteByIdentifier('site-a'), 'https://a.test/draft-only', 0));
    }
}
