<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Backend\ToolbarItems\A11yScanToolbarItem;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The toolbar shows a site's Content counts on every backend screen. They are page-level data like the Overview's
 * totals: a page the user may not show (TYPO3 page permissions) adds nothing to the open count, its severity tone,
 * the icon's count or the last scan's "new" count, and the scan's own (all pages) new/resolved numbers are not shown.
 * Real be_users, be_groups and page permission fields.
 */
final class ToolbarPageAccessFunctionalTest extends AbstractFunctionalTestCase
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
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => $uid === 1 ? '/' : '/p' . $uid, 'doktype' => 1,
                'is_siteroot' => $root, 'perms_userid' => $owner, 'perms_groupid' => $group, 'perms_user' => $permsUser,
                'perms_group' => $permsGroup, 'perms_everybody' => $permsEverybody, 'deleted' => 0, 'hidden' => 0,
            ]);
        }
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 10, 'title' => 'Client A editors', 'groupMods' => 'web_a11y', 'db_mountpoints' => '1', 'tables_modify' => 'pages,tt_content',
        ]);
        $users = $pool->getConnectionForTable('be_users');
        $users->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $users->insert('be_users', ['uid' => 2, 'username' => 'editor', 'admin' => 0, 'usergroup' => '10', 'disable' => 0, 'deleted' => 0]);

        // Readable pages 1, 2, 4: four open warnings. Page 3: three open critical issues the editor may not see.
        foreach ([1 => [1, 3], 2 => [2, 3], 3 => [3, 1], 4 => [1, 3]] as $pageUid => [$count, $severity]) {
            for ($i = 0; $i < $count; $i++) {
                // Each finding has a real source record on its page: findings follow the current source record rights (Sol QA-02).
                $contentUid = ($contentUid ?? 100) + 1;
                $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => $contentUid, 'pid' => $pageUid, 'header' => 'Content ' . $contentUid]);
                $this->insertIssue(['site_identifier' => self::SITE, 'page_uid' => $pageUid, 'source_uid' => $contentUid, 'severity' => $severity, 'first_seen_scan_uid' => 7]);
            }
        }
        // The scan row counts every page it processed, including page 3.
        $this->insertScan([
            'uid' => 7, 'site_identifier' => self::SITE, 'root_pid' => 1, 'language_uid' => 0, 'scope' => 'subtree',
            'pages_scanned' => 4, 'issues_new' => 99, 'issues_resolved' => 5,
        ]);

        $this->get(SiteWriter::class)->createNewBasicSite(self::SITE, 1, 'https://a.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    #[Test]
    public function anEditorsToolbarCountsOnlyPagesTheyMayShow(): void
    {
        $this->setUpBackendUser(2);

        $summary = $this->summary();

        self::assertSame('open', $summary['content']['state']);
        self::assertSame('4 open', $summary['content']['tagLabel']);
        self::assertSame(4, $summary['content']['openTotal']);
        self::assertSame('warning', $summary['content']['tagTone'], 'the hidden page\'s critical issues set no tone');
        self::assertSame(4, $summary['state']['count']);
        self::assertStringContainsString('4', $summary['state']['ariaLabel']);
        self::assertSame('4 new in the last scan', $summary['content']['delta'], 'the scan\'s own 99 new / 5 resolved are not shown');
        self::assertStringNotContainsString('99', $summary['content']['delta']);
        self::assertStringNotContainsString('resolved', $summary['content']['delta']);
    }

    #[Test]
    public function anAdministratorSeesTheWholeSiteAndTheScansOwnCounts(): void
    {
        $this->setUpBackendUser(1);

        $summary = $this->summary();

        self::assertSame('7 open', $summary['content']['tagLabel']);
        self::assertSame('critical', $summary['content']['tagTone']);
        self::assertSame(7, $summary['state']['count']);
        self::assertSame('99 new and 5 resolved in the last scan', $summary['content']['delta']);
    }

    #[Test]
    public function aPermissionChangeAppliesToTheNextRefresh(): void
    {
        $this->setUpBackendUser(2);
        self::assertSame(4, $this->summary()['state']['count']);

        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 1], ['uid' => 3]);

        $summary = $this->summary();
        self::assertSame(7, $summary['state']['count']);
        self::assertSame('critical', $summary['content']['tagTone']);
        self::assertSame('7 new in the last scan', $summary['content']['delta']);
    }

    #[Test]
    public function issuesOfADisabledRuleAreNeitherOpenNorNew(): void
    {
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('tx_a11y_issue')->update('tx_a11y_issue', ['rule_id' => 'rte.link_text_vague'], ['page_uid' => 2]);
        $pool->getConnectionForTable('tx_a11y_ruleset')->insert('tx_a11y_ruleset', [
            'site_identifier' => self::SITE, 'title' => 'Client A', 'deleted' => 0,
            'rules_json' => json_encode(['disabledRules' => ['rte.link_text_vague']], JSON_THROW_ON_ERROR),
        ]);
        $this->setUpBackendUser(2);

        $summary = $this->summary();

        self::assertSame('2 open', $summary['content']['tagLabel']);
        self::assertSame('2 new in the last scan', $summary['content']['delta'], '"new" never exceeds "open"');
    }

    #[Test]
    public function anEditorWhoMayNotShowTheSiteRootGetsNoSiteCounts(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 0], ['uid' => 1]);
        $this->setUpBackendUser(2);

        $summary = $this->summary();

        self::assertArrayNotHasKey('openTotal', $summary['content']);
        self::assertSame(0, $summary['state']['count']);
        self::assertStringNotContainsString('99', $summary['content']['delta']);
        self::assertSame('', $summary['siteLabel']);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        $toolbarItem = $this->get(A11yScanToolbarItem::class);
        $toolbarItem->setRequest(
            (new ServerRequest('https://a.test/typo3/module/web/layout'))
                ->withQueryParams(['id' => 2])
                ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
        );

        return (new \ReflectionMethod(A11yScanToolbarItem::class, 'buildSummary'))->invoke($toolbarItem, true);
    }
}
