<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Assignment;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Assignment\AssignmentService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * NEW-03 / NEW-04 (resilience audit): "My issues" showed the first 500 of 2,520 open tasks with no notice (also capped
 * at 1,000 assignments and 5,000 aggregated rows further down), and ran ~21,000 SQL statements per request because
 * every task was fully built before the cut. The list is now paginated on the server with exact totals, a stable order,
 * permission filtering on every row, and a cost per request that does not grow with the number of tasks.
 */
final class MyIssuesPaginationFunctionalTest extends AbstractFunctionalTestCase
{
    private const PAGES = 130;
    private const RULES = ['rte.empty_link', 'rte.img_alt_missing', 'rte.empty_heading', 'rte.non_descriptive_link', 'rte.iframe_missing_title'];

    protected function setUp(): void
    {
        parent::setUp();
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pages = $pool->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1, 'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 31]);
        $pages->insert('pages', ['uid' => 2, 'pid' => 1, 'title' => 'Section A', 'slug' => '/a', 'doktype' => 1, 'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 31]);
        $pages->insert('pages', ['uid' => 3, 'pid' => 1, 'title' => 'Section B', 'slug' => '/b', 'doktype' => 1, 'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 31]);
        for ($i = 0; $i < self::PAGES; $i++) {
            $uid = 100 + $i;
            $pages->insert('pages', ['uid' => $uid, 'pid' => $i % 2 === 0 ? 2 : 3, 'title' => sprintf('Page %03d', $i), 'slug' => '/p' . $uid, 'doktype' => 1,
                'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 31]);
        }
        $pool->getConnectionForTable('be_groups')->insert('be_groups', ['uid' => 1, 'title' => 'section a', 'groupMods' => 'web_a11y,web_layout', 'db_mountpoints' => '2', 'tables_select' => 'pages,tt_content']);
        $users = $pool->getConnectionForTable('be_users');
        $users->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'deleted' => 0, 'disable' => 0]);
        $users->insert('be_users', ['uid' => 2, 'username' => 'editor-a', 'admin' => 0, 'usergroup' => '1', 'deleted' => 0, 'disable' => 0]);
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://main.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);

        $issues = $pool->getConnectionForTable('tx_a11y_issue');
        $n = 0;
        for ($i = 0; $i < self::PAGES; $i++) {
            foreach (self::RULES as $r => $rule) {
                $issues->insert('tx_a11y_issue', ['site_identifier' => 'main', 'page_uid' => 100 + $i, 'source_lang_uid' => 0, 'source_table' => 'tt_content', 'source_uid' => 1000 + $i,
                    'source_field' => 'bodytext', 'source_type' => 'rte', 'rule_id' => $rule, 'severity' => 1 + ($i + $r) % 3, 'message' => $rule, 'fingerprint' => sha1('f' . $n++), 'status' => 0]);
            }
        }
    }

    #[Test]
    public function everyTaskIsReachableExactlyOnceAcrossPagesWithAnExactTotal(): void
    {
        $this->setUpBackendUser(1);
        $service = $this->get(AssignmentService::class);
        $expected = self::PAGES * count(self::RULES);

        $first = $service->listTasks(AssignmentService::FILTER_ALL, AssignmentService::STATE_OPEN, '', 'en', 1, 100);
        self::assertSame($expected, $first['total'], 'The total counts every task (650), not a truncated list.');
        self::assertSame((int)ceil($expected / 100), $first['pages']);
        self::assertSame($expected, $first['unassignedOpen']);

        $seen = [];
        $order = [];
        for ($page = 1; $page <= $first['pages']; $page++) {
            $result = $service->listTasks(AssignmentService::FILTER_ALL, AssignmentService::STATE_OPEN, '', 'en', $page, 100);
            self::assertSame($expected, $result['total']);
            foreach ($result['tasks'] as $task) {
                $key = $task['pageUid'] . '|' . $task['issueKey'];
                self::assertArrayNotHasKey($key, $seen, 'No task is shown on two pages.');
                $seen[$key] = true;
                $order[] = [$task['severity'], -$task['open'], $task['pageTitle']];
            }
        }
        self::assertCount($expected, $seen, 'No task is skipped.');
        $sorted = $order;
        usort($sorted, static fn (array $a, array $b): int => $a <=> $b);
        self::assertSame($sorted, $order, 'The order is stable across pages.');
    }

    #[Test]
    public function aPageOutOfRangeShowsTheLastPageAndAnEmptyListHasNoRange(): void
    {
        $this->setUpBackendUser(1);
        $result = $this->get(AssignmentService::class)->listTasks(AssignmentService::FILTER_ALL, AssignmentService::STATE_OPEN, '', 'en', 999, 100);
        self::assertSame($result['pages'], $result['page']);
        self::assertSame($result['total'], $result['rangeEnd']);

        $none = $this->get(AssignmentService::class)->listTasks(AssignmentService::FILTER_ALL, AssignmentService::STATE_DONE, '', 'en', 1, 100);
        self::assertSame(0, $none['total']);
        self::assertSame([0, 0], [$none['rangeStart'], $none['rangeEnd']]);
    }

    #[Test]
    public function totalsAndRowsOnlyIncludePagesTheViewerMayRead(): void
    {
        $this->setUpBackendUser(2);
        // An editor (no assign right) only ever gets their own tasks; assign some of section A and one of section B.
        $assignments = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_assignment');
        foreach ([100, 102, 104, 101] as $pageUid) {
            $assignments->insert('tx_a11y_assignment', ['site_identifier' => 'main', 'page_uid' => $pageUid, 'language_uid' => 0, 'issue_key' => 'rte.empty_link', 'assignee' => 2, 'assigned_by' => 1]);
        }

        $result = $this->get(AssignmentService::class)->listTasks(AssignmentService::FILTER_ALL, AssignmentService::STATE_OPEN, '', 'en', 1, 100);

        self::assertSame('me', $result['filter'], 'Without the assign right the list is always "me".');
        self::assertSame(3, $result['total'], 'Page 101 (section B) is outside the editor\'s mount and never counted.');
        $pageUids = array_map(static fn (array $t): int => $t['pageUid'], $result['tasks']);
        sort($pageUids);
        self::assertSame([100, 102, 104], $pageUids);
    }

    #[Test]
    public function searchNarrowsTheTotalNotJustThePage(): void
    {
        $this->setUpBackendUser(1);
        $result = $this->get(AssignmentService::class)->listTasks(AssignmentService::FILTER_ALL, AssignmentService::STATE_OPEN, '', 'en', 1, 100, 'Page 007');

        self::assertSame(count(self::RULES), $result['total']);
        foreach ($result['tasks'] as $task) {
            self::assertSame('Page 007', $task['pageTitle']);
        }
    }

    #[Test]
    public function theCostOfARequestDoesNotGrowWithTheNumberOfTasks(): void
    {
        $this->setUpBackendUser(1);
        $service = $this->get(AssignmentService::class);
        $small = $this->statementsFor(fn () => $service->listTasks(AssignmentService::FILTER_ALL, AssignmentService::STATE_OPEN, '', 'en', 1, 20));
        $smallTotal = $service->listTasks(AssignmentService::FILTER_ALL, AssignmentService::STATE_OPEN, '', 'en', 1, 20)['total'];

        self::assertSame(650, $smallTotal);
        // Before NEW-04 every task cost ~8 statements (owner, count, title, licence cache, ruleset, rescan …) before
        // the cut, i.e. more than 5,000 here. A page of 20 tasks now costs a bounded number of statements.
        self::assertLessThan(1000, $small, 'statements for one page of My issues over 650 tasks');
    }

    private function statementsFor(callable $callable): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue');
        $before = (int)($connection->executeQuery("SHOW SESSION STATUS LIKE 'Questions'")->fetchAssociative()['Value'] ?? 0);
        $callable();
        $after = (int)($connection->executeQuery("SHOW SESSION STATUS LIKE 'Questions'")->fetchAssociative()['Value'] ?? 0);

        return $after - $before - 1;
    }
}
