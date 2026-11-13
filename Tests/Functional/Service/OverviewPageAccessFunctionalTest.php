<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The Overview needs show access to the site root, but its page list, pagination and totals are page-level data:
 * a page the editor may not show (TYPO3 page permissions) contributes no row, title, count or total. Real be_users,
 * be_groups, page permission fields and the repository queries the Overview runs.
 */
final class OverviewPageAccessFunctionalTest extends AbstractFunctionalTestCase
{
    private const SITE = 'client-a';

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->getConnectionPool();
        // [uid, pid, title, is_siteroot, owner, group, perms_user, perms_group, perms_everybody]
        foreach ([
            [1, 0, 'Client A', 1, 0, 0, 0, 0, 31],
            [2, 1, 'Open page', 0, 0, 0, 0, 0, 31],
            [3, 1, 'Board minutes', 0, 99, 99, 31, 31, 0],      // nobody else may show it
            [4, 1, 'Group page', 0, 99, 10, 31, 1, 0],           // the editors' group may show it
        ] as [$uid, $pid, $title, $root, $owner, $group, $permsUser, $permsGroup, $permsEverybody]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'doktype' => 1, 'is_siteroot' => $root,
                'perms_userid' => $owner, 'perms_groupid' => $group, 'perms_user' => $permsUser,
                'perms_group' => $permsGroup, 'perms_everybody' => $permsEverybody, 'deleted' => 0, 'hidden' => 0,
            ]);
        }
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 10, 'title' => 'Client A editors', 'groupMods' => 'web_a11y', 'db_mountpoints' => '1', 'tables_modify' => 'pages,tt_content',
        ]);
        $users = $pool->getConnectionForTable('be_users');
        $users->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $users->insert('be_users', ['uid' => 2, 'username' => 'editor', 'admin' => 0, 'usergroup' => '10', 'disable' => 0, 'deleted' => 0]);

        foreach ([1 => 1, 2 => 2, 3 => 3, 4 => 1] as $pageUid => $count) {
            for ($i = 0; $i < $count; $i++) {
                // Each finding has a real source record on its page: findings follow the current source record rights (Sol QA-02).
                $contentUid = ($contentUid ?? 100) + 1;
                $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => $contentUid, 'pid' => $pageUid, 'header' => 'Content ' . $contentUid]);
                $this->insertIssue(['site_identifier' => self::SITE, 'page_uid' => $pageUid, 'source_uid' => $contentUid, 'severity' => 3, 'first_seen_scan_uid' => 7]);
            }
        }

        $this->get(SiteWriter::class)->createNewBasicSite(self::SITE, 1, 'https://a.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    #[Test]
    public function anEditorSeesOnlyRowsCountsAndTotalsOfPagesTheyMayShow(): void
    {
        $this->setUpBackendUser(2);
        $access = $this->get(ScopeAccessService::class);
        self::assertTrue($access->canReadSiteIdentifier(self::SITE), 'precondition: the editor opens the Overview');
        self::assertFalse($access->canReadPage(3));

        $clause = $access->readablePagesClause();
        self::assertNotSame('', $clause);
        $repository = $this->createIssueRepository();

        $rows = $repository->findOpenPageStatsForSitePaginated(self::SITE, 10, 0, '', -1, $clause);
        self::assertSame([1, 2, 4], $this->pageUids($rows));
        self::assertNotContains('Board minutes', array_column($rows, 'pageTitle'));
        self::assertSame(3, $repository->countOpenPageStatsForSite(self::SITE, '', -1, $clause));
        self::assertSame(0, $repository->countOpenPageStatsForSite(self::SITE, 'Board', -1, $clause), 'a title search reveals nothing either');
        self::assertSame(4, $repository->countOpenTotalsForSite(self::SITE, -1, $clause)['total']);
        self::assertSame(4, $repository->countNewOpenTotalsForScan(self::SITE, 7, -1, $clause)['total']);
        self::assertSame([1, 2, 4], $this->pageUids($repository->findOpenPageStatsForSite(self::SITE, '', -1, $clause)));
    }

    #[Test]
    public function aSiteExportListsOnlyPagesTheEditorMayShow(): void
    {
        $this->setUpBackendUser(2);
        $clause = $this->get(ScopeAccessService::class)->readablePagesClause();

        $rows = $this->createIssueRepository()->findForExport(self::SITE, null, true, -1, $clause);

        self::assertSame([1, 2, 4], array_values(array_unique(array_map(static fn (array $row): int => (int)$row['page_uid'], $rows))));
        self::assertNotContains('Board minutes', array_column($rows, 'page_title'));
    }

    #[Test]
    public function ignoringARuleOnTheSiteChangesOnlyPagesWhoseContentTheEditorMayEdit(): void
    {
        $this->setUpBackendUser(2);
        $clause = $this->get(ScopeAccessService::class)->contentEditablePagesClause();

        $ignored = $this->createIssueRepository()->ignoreAllByRuleOnSite(self::SITE, -1, 'rte.img_alt_missing', 'Decorative logos', 2, 'Editor', 'editor', 0, $clause);

        // Pages 1 and 2 (content editable by everybody); not 3 (no access) or 4 (the group may only show it).
        self::assertSame(3, $ignored);
        $open = $this->getConnectionPool()->getConnectionForTable('tx_a11y_issue')
            ->fetchFirstColumn('SELECT DISTINCT page_uid FROM tx_a11y_issue WHERE status = 0 ORDER BY page_uid');
        self::assertSame([3, 4], array_map('intval', $open));
    }

    #[Test]
    public function withoutTheClauseTheSameQueriesListEveryPage(): void
    {
        // Documents what the Overview returned before it passed the clause (the reproduction of the finding).
        $this->setUpBackendUser(2);
        $repository = $this->createIssueRepository();

        self::assertSame(4, $repository->countOpenPageStatsForSite(self::SITE));
        self::assertContains('Board minutes', array_column($repository->findOpenPageStatsForSitePaginated(self::SITE, 10, 0), 'pageTitle'));
        self::assertSame(7, $repository->countOpenTotalsForSite(self::SITE)['total']);
    }

    #[Test]
    public function anAdministratorIsNotRestricted(): void
    {
        $this->setUpBackendUser(1);
        $clause = $this->get(ScopeAccessService::class)->readablePagesClause();

        self::assertSame('', $clause);
        self::assertSame(4, $this->createIssueRepository()->countOpenPageStatsForSite(self::SITE, '', -1, $clause));
    }

    #[Test]
    public function aPermissionChangeAppliesToTheNextRequest(): void
    {
        $this->setUpBackendUser(2);
        $repository = $this->createIssueRepository();
        self::assertSame(3, $repository->countOpenPageStatsForSite(self::SITE, '', -1, $this->get(ScopeAccessService::class)->readablePagesClause()));

        // An administrator opens "Board minutes" to everybody: it is listed at once.
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 1], ['uid' => 3]);
        self::assertSame(4, $repository->countOpenPageStatsForSite(self::SITE, '', -1, $this->get(ScopeAccessService::class)->readablePagesClause()));
    }

    /** @param list<array{pageUid:int}> $rows @return list<int> */
    private function pageUids(array $rows): array
    {
        $uids = array_map(static fn (array $row): int => $row['pageUid'], $rows);
        sort($uids);

        return $uids;
    }
}
