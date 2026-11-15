<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\LargeInstallation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Service\RemoteResultProvenanceService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * HIGH-2 (large-installation stress test): every site of a multi-site installation has the same slugs (/kontakt, …),
 * and storing one site's frontend scan resolved every candidate page's site through a rootline (80 sites × 200 pages:
 * 520 MB, fatal at 256M). Ownership is now decided from one walk of the site's own page tree; the answers stay those of
 * SiteFinder::getSiteByPageId(): the own site's page, never another site's page with the same slug, never a page of a
 * site nested below this site's root.
 *
 * Site A root 1: 2 /kontakt · 3 /produkte · 9 /alt (deleted) · 30 nested site C root → 31 /team.
 * Site B root 20: 21 /kontakt · 22 /only-b.
 */
final class ProvenanceSharedSlugFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pages = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');
        foreach ([
            [1, 0, '/', 1, 0], [2, 1, '/kontakt', 0, 0], [3, 1, '/produkte', 0, 0], [9, 1, '/alt', 0, 1],
            [30, 1, '/', 1, 0], [31, 30, '/team', 0, 0],
            [20, 0, '/', 1, 0], [21, 20, '/kontakt', 0, 0], [22, 20, '/only-b', 0, 0],
        ] as [$uid, $pid, $slug, $root, $deleted]) {
            $pages->insert('pages', ['uid' => $uid, 'pid' => $pid, 'title' => 'Page ' . $uid, 'slug' => $slug, 'doktype' => 1, 'is_siteroot' => $root, 'deleted' => $deleted]);
        }
        $writer = $this->get(SiteWriter::class);
        $writer->createNewBasicSite('site-a', 1, 'https://a.invalid/');
        $writer->createNewBasicSite('site-b', 20, 'https://b.invalid/');
        $writer->createNewBasicSite('site-c', 30, 'https://c.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    #[Test]
    public function aSharedSlugResolvesToThePageOfTheScannedSite(): void
    {
        $finder = $this->get(SiteFinder::class);
        $service = $this->get(RemoteResultProvenanceService::class);

        $a = $service->resolve($finder->getSiteByIdentifier('site-a'), [10 => 'https://a.invalid/kontakt', 11 => 'https://a.invalid/produkte/']);
        $b = $service->resolve($finder->getSiteByIdentifier('site-b'), [10 => 'https://b.invalid/kontakt']);

        self::assertSame(2, $a[10]['pageUid'] ?? null, 'site A: its own /kontakt');
        self::assertSame(3, $a[11]['pageUid'] ?? null);
        self::assertSame(21, $b[10]['pageUid'] ?? null, 'site B: its own /kontakt, never site A’s');
    }

    #[Test]
    public function pagesOfOtherSitesDeletedPagesAndNestedSitesOwnNothing(): void
    {
        $finder = $this->get(SiteFinder::class);
        $owners = $this->get(RemoteResultProvenanceService::class)->resolve($finder->getSiteByIdentifier('site-a'), [
            1 => 'https://a.invalid/only-b',
            2 => 'https://a.invalid/team',
            3 => 'https://a.invalid/alt',
        ]);

        self::assertArrayNotHasKey(1, $owners, 'a slug that exists only in site B');
        self::assertArrayNotHasKey(2, $owners, 'a page of the nested site C belongs to site C (getSiteByPageId)');
        self::assertArrayNotHasKey(3, $owners, 'a deleted page');
    }
}
