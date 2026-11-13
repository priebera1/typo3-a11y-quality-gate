<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Assignment;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Assignment\AssignmentException;
use Priebera\A11yQualityGate\Assignment\AssignmentService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Team assignment (My issues) — the security and lifecycle matrix of the 2026-11-05 company acceptance follow-up:
 * administrator, authorised manager, editor without assign rights, page- and language-restricted editors, a disabled
 * editor, forged/cross-site findings, rescan/resolve/reopen and a changed page mount.
 *
 * Users: 1 admin · 2 Anna (manager: mounts 1, languages 0+1, assign = 1) · 3 Ben (mount 2 only, language 1 only)
 *        4 Cara (mount 4 only, language 0, no assign) · 5 Dora (disabled) · 6 Eve (no module access)
 * Pages: 1 root · 2 Products · 3 Cargo Bikes (under 2) · 4 News · 30 Northwind Careers (other site)
 */
final class AssignmentWorkflowFunctionalTest extends AbstractFunctionalTestCase
{
    private int $findingCargoRte = 0;
    private int $findingCargoRendered = 0;
    private int $findingNews = 0;
    private int $findingCargoDe = 0;
    private int $findingCareers = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pages = $pool->getConnectionForTable('pages');
        foreach ([[1, 0, 'Root', 1], [2, 1, 'Products', 0], [3, 2, 'Cargo Bikes', 0], [4, 1, 'News', 0], [30, 0, 'Northwind Careers', 1]] as [$uid, $pid, $title, $root]) {
            $pages->insert('pages', ['uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => '/' . $uid, 'doktype' => 1, 'is_siteroot' => $root,
                'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 31, 'perms_everybody' => 0]);
        }
        $pages->insert('pages', ['uid' => 33, 'pid' => 2, 'title' => 'Lastenräder', 'slug' => '/de/3', 'doktype' => 1, 'sys_language_uid' => 1, 'l10n_parent' => 3,
            'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 31]);

        $groups = $pool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', ['uid' => 1, 'title' => 'base', 'groupMods' => '', 'tables_select' => 'pages,tt_content', 'tables_modify' => 'pages,tt_content']);
        $groups->insert('be_groups', ['uid' => 2, 'title' => 'managers', 'groupMods' => 'web_a11y,web_layout', 'db_mountpoints' => '1', 'allowed_languages' => '0,1',
            'TSconfig' => "options.a11y_quality_gate.assign = 1"]);
        $groups->insert('be_groups', ['uid' => 3, 'title' => 'products de', 'groupMods' => 'web_a11y,web_layout', 'db_mountpoints' => '2', 'allowed_languages' => '1']);
        $groups->insert('be_groups', ['uid' => 4, 'title' => 'junior', 'groupMods' => 'web_a11y,web_layout', 'db_mountpoints' => '4', 'allowed_languages' => '0']);
        $groups->insert('be_groups', ['uid' => 5, 'title' => 'no module', 'groupMods' => 'web_layout', 'db_mountpoints' => '1', 'allowed_languages' => '0']);
        $users = $pool->getConnectionForTable('be_users');
        foreach ([
            [1, 'admin', 'Alex Admin', 1, '', 0],
            [2, 'anna', 'Anna Berger', 0, '1,2', 0],
            [3, 'ben', 'Ben Keller', 0, '1,3', 0],
            [4, 'cara', 'Cara Novak', 0, '1,4', 0],
            [5, 'dora', 'Dora Former', 0, '1,2', 1],
            [6, 'eve', 'Eve Layout', 0, '1,5', 0],
        ] as [$uid, $name, $real, $admin, $usergroup, $disable]) {
            $users->insert('be_users', ['uid' => $uid, 'username' => $name, 'realName' => $real, 'admin' => $admin, 'usergroup' => $usergroup, 'disable' => $disable, 'deleted' => 0]);
        }

        $writer = $this->get(SiteWriter::class);
        $writer->createNewBasicSite('northwind', 1, 'https://northwind.invalid/');
        $writer->createNewBasicSite('careers', 30, 'https://careers.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);

        $this->findingCargoRte = $this->insertIssue(['site_identifier' => 'northwind', 'page_uid' => 3, 'rule_id' => 'rte.empty_link', 'severity' => 1, 'source_uid' => 5]);
        // The same defect reported by the rendered check: one task, not two.
        $this->findingCargoRendered = $this->insertIssue(['site_identifier' => 'northwind', 'page_uid' => 3, 'rule_id' => 'rendered.empty_link', 'severity' => 1, 'source_uid' => 6, 'source_field' => '__rendered_html']);
        $this->findingCargoDe = $this->insertIssue(['site_identifier' => 'northwind', 'page_uid' => 3, 'source_lang_uid' => 1, 'rule_id' => 'rte.empty_link', 'severity' => 1, 'source_uid' => 33]);
        $this->findingNews = $this->insertIssue(['site_identifier' => 'northwind', 'page_uid' => 4, 'rule_id' => 'rte.img_alt_missing', 'severity' => 1, 'source_uid' => 11]);
        $this->findingCareers = $this->insertIssue(['site_identifier' => 'careers', 'page_uid' => 30, 'rule_id' => 'rte.form_control_missing_label', 'severity' => 1, 'source_uid' => 15]);
        $this->insertIssue(['site_identifier' => 'northwind', 'page_uid' => 3, 'rule_id' => 'rte.non_descriptive_link', 'severity' => 2, 'source_uid' => 5]);
    }

    #[Test]
    public function anAdministratorAssignsAnIssueTypeOnceForBothEngines(): void
    {
        $this->setUpBackendUser(1);
        $service = $this->get(AssignmentService::class);

        $first = $service->assignByFinding($this->findingCargoRendered, 2, 'Please fix before Friday');
        self::assertSame('rte.empty_link', $first['issueKey'], 'A rendered rule folds into its rich-text equivalent.');
        self::assertSame('Anna Berger', $first['assigneeLabel']);
        $service->assignByFinding($this->findingCargoRte, 2);

        self::assertSame(1, (int)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_assignment')
            ->count('uid', 'tx_a11y_assignment', ['page_uid' => 3, 'language_uid' => 0]), 'One task per issue type on the page.');
    }

    #[Test]
    public function assigneesMustBeAbleToOpenTheWorkThemselves(): void
    {
        $this->setUpBackendUser(1);
        $service = $this->get(AssignmentService::class);

        foreach ([[4, 'assignee_no_page'], [5, 'assignee_disabled'], [6, 'assignee_no_module'], [99, 'assignee_missing'], [3, 'assignee_no_language']] as [$user, $reason]) {
            try {
                $service->assignByFinding($this->findingCargoRte, $user);
                self::fail('User ' . $user . ' must not be assignable.');
            } catch (AssignmentException $exception) {
                self::assertSame($reason, $exception->reason, 'user ' . $user);
            }
        }
        // Ben (German only, Products mount) may take the German task.
        self::assertSame(3, $service->assignByFinding($this->findingCargoDe, 3)['assignee']);
        $candidates = array_column($service->candidatesForFinding($this->findingCargoRte), 'uid');
        sort($candidates);
        self::assertSame([1, 2], $candidates, 'Only people who can open the English Cargo Bikes page are offered.');
    }

    #[Test]
    public function aStaleReassignmentDoesNotOverwriteSomeoneElsesChange(): void
    {
        // N-07: two managers open the same card (nobody assigned); the first assigns Anna, the second — still seeing
        // "Not assigned" — must not silently replace that with their own choice.
        $this->setUpBackendUser(1);
        $service = $this->get(AssignmentService::class);
        $service->assignByFinding($this->findingCargoRte, 2, '', 0);
        try {
            $service->assignByFinding($this->findingCargoRte, 1, '', 0);
            self::fail('A reassignment based on a stale view must be refused.');
        } catch (AssignmentException $exception) {
            self::assertSame('assignment_conflict', $exception->reason);
            self::assertSame(409, $exception->status);
        }
        $row = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_assignment')
            ->select(['assignee'], 'tx_a11y_assignment', ['page_uid' => 3, 'language_uid' => 0, 'issue_key' => 'rte.empty_link'])->fetchAssociative();
        self::assertSame(2, (int)$row['assignee'], 'The first change stays.');
        // With the current state the change goes through; unassigning is checked the same way.
        self::assertSame(1, $service->assignByFinding($this->findingCargoRte, 1, '', 2)['assignee']);
        try {
            $service->assignByFinding($this->findingCargoRte, 0, '', 2);
            self::fail('A stale unassign must be refused.');
        } catch (AssignmentException $exception) {
            self::assertSame('assignment_conflict', $exception->reason);
        }
        // Callers that do not state what they saw (older clients) keep the previous behaviour.
        self::assertSame(0, $service->assignByFinding($this->findingCargoRte, 0)['assignee']);
    }

    #[Test]
    public function onlyAssignersWithEditAccessMayAssign(): void
    {
        $this->setUpBackendUser(4); // Cara: no assign right
        try {
            $this->get(AssignmentService::class)->assignByFinding($this->findingNews, 4);
            self::fail('An editor without the assign right must not assign.');
        } catch (AssignmentException $exception) {
            self::assertSame('permission_denied', $exception->reason);
        }
    }

    #[Test]
    public function aForgedOrForeignFindingIsNotDisclosed(): void
    {
        $this->setUpBackendUser(2); // Anna: corporate mount only
        $service = $this->get(AssignmentService::class);
        foreach ([$this->findingCareers, 424242] as $finding) {
            try {
                $service->assignByFinding($finding, 2);
                self::fail('Finding ' . $finding . ' is outside Anna\'s access.');
            } catch (AssignmentException $exception) {
                self::assertSame('not_found', $exception->reason);
            }
        }
        self::assertSame([], $service->candidatesForFinding($this->findingCareers));
    }

    #[Test]
    public function myIssuesShowsOnlyAccessibleWorkAndFollowsResolveAndReopen(): void
    {
        $this->setUpBackendUser(1);
        $admin = $this->get(AssignmentService::class);
        $admin->assignByFinding($this->findingCargoRte, 2);
        $admin->assignByFinding($this->findingCargoDe, 3);
        $admin->assignByFinding($this->findingNews, 2);

        $this->setUpBackendUser(2);
        $anna = $this->get(AssignmentService::class);
        $list = $anna->listTasks(AssignmentService::FILTER_ME);
        self::assertSame(['Cargo Bikes|rte.empty_link|2', 'News|rte.img_alt_missing|1'], array_map(static fn (array $t): string => $t['pageTitle'] . '|' . $t['issueKey'] . '|' . $t['open'], $list['tasks']));
        self::assertSame(1, $list['unassignedOpen'], 'The non-descriptive link on Cargo Bikes waits for an owner (the careers task is not Anna\'s site).');

        // Resolved by a rescan: the task is done, not gone; a new occurrence reopens it.
        $issues = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue');
        $issues->update('tx_a11y_issue', ['status' => 1, 'resolved_at' => time()], ['uid' => $this->findingNews]);
        self::assertSame(['Cargo Bikes'], array_column($anna->listTasks(AssignmentService::FILTER_ME)['tasks'], 'pageTitle'));
        self::assertSame(['News'], array_column($anna->listTasks(AssignmentService::FILTER_ME, AssignmentService::STATE_DONE)['tasks'], 'pageTitle'));
        $this->insertIssue(['site_identifier' => 'northwind', 'page_uid' => 4, 'rule_id' => 'rte.img_alt_missing', 'severity' => 1, 'source_uid' => 12]);
        self::assertContains('News', array_column($this->get(AssignmentService::class)->listTasks(AssignmentService::FILTER_ME)['tasks'], 'pageTitle'));

        // Ben sees his German task only — never Anna's English work, never filters beyond his own.
        $this->setUpBackendUser(3);
        $ben = $this->get(AssignmentService::class);
        $benList = $ben->listTasks(AssignmentService::FILTER_ALL);
        self::assertSame('me', $benList['filter'], 'Without the assign right the list is always "mine".');
        self::assertSame([[3, 1]], array_map(static fn (array $t): array => [$t['pageUid'], $t['languageUid']], $benList['tasks']));
        self::assertSame([], $benList['workload']);
    }

    #[Test]
    public function aLostMountHidesTheTaskFromTheAssigneeAndFlagsItForTheAssigner(): void
    {
        $this->setUpBackendUser(1);
        $this->get(AssignmentService::class)->assignByFinding($this->findingNews, 2);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', ['db_mountpoints' => '2'], ['uid' => 2]);

        $this->setUpBackendUser(1);
        $chips = $this->get(AssignmentService::class)->chipsForPage('northwind', 4, 0);
        self::assertSame('no_page', $chips['rte.img_alt_missing']['flag'], 'The assigner sees that the assignee can no longer open it.');

        $this->setUpBackendUser(2);
        self::assertSame([], $this->get(AssignmentService::class)->listTasks(AssignmentService::FILTER_ME)['tasks']);
    }
}
