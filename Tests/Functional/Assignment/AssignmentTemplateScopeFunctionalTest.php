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
 * N-01 (final customer acceptance, P1): a rendered finding outside every content element (the site template's logo,
 * navigation, footer — stored with source_table "pages") was folded into the editor's content task of the same issue
 * type on every page. The editor fixed their content, the task stayed "To do" for ever, and one template defect made up
 * 55 % of a 15-editor team's work list.
 *
 * Content tasks now count content findings only; every template defect is one shared task of the site (page 0, language
 * 0, key "template:<rule>:<snippet hash>"), visible and assignable only with access to the site root, done when the
 * template is fixed on every page and language.
 *
 * Users: 1 admin · 2 Anna (manager, root mount, assign) · 3 Sara (editor, mount 4 only, language 0)
 * Pages: 1 root · 2 Products · 3 Cargo Bikes · 4 News
 */
final class AssignmentTemplateScopeFunctionalTest extends AbstractFunctionalTestCase
{
    private const LOGO = '<a href="/"><img src="/fileadmin/logo.png" class="logo"></a>';

    private int $newsContentLink = 0;
    private int $newsTemplateLink = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pages = $pool->getConnectionForTable('pages');
        foreach ([[1, 0, 'Root', 1], [2, 1, 'Products', 0], [3, 2, 'Cargo Bikes', 0], [4, 1, 'News', 0]] as [$uid, $pid, $title, $root]) {
            $pages->insert('pages', ['uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => '/' . $uid, 'doktype' => 1, 'is_siteroot' => $root,
                'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 31, 'perms_everybody' => 0]);
        }
        $groups = $pool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', ['uid' => 1, 'title' => 'base', 'groupMods' => '', 'tables_select' => 'pages,tt_content', 'tables_modify' => 'pages,tt_content']);
        $groups->insert('be_groups', ['uid' => 2, 'title' => 'managers', 'groupMods' => 'web_a11y,web_layout', 'db_mountpoints' => '1', 'allowed_languages' => '0,1',
            'TSconfig' => 'options.a11y_quality_gate.assign = 1']);
        $groups->insert('be_groups', ['uid' => 4, 'title' => 'junior', 'groupMods' => 'web_a11y,web_layout', 'db_mountpoints' => '4', 'allowed_languages' => '0']);
        $users = $pool->getConnectionForTable('be_users');
        foreach ([[1, 'admin', 'Alex Admin', 1, ''], [2, 'anna', 'Anna Berger', 0, '1,2'], [3, 'sara', 'Sara Yilmaz', 0, '1,4']] as [$uid, $name, $real, $admin, $usergroup]) {
            $users->insert('be_users', ['uid' => $uid, 'username' => $name, 'realName' => $real, 'admin' => $admin, 'usergroup' => $usergroup, 'disable' => 0, 'deleted' => 0]);
        }
        $this->get(SiteWriter::class)->createNewBasicSite('northwind', 1, 'https://northwind.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);

        // Sara's content defect on News: an empty PDF link in a text element.
        $this->newsContentLink = $this->insertIssue(['site_identifier' => 'northwind', 'page_uid' => 4, 'rule_id' => 'rte.empty_link', 'severity' => 1,
            'source_uid' => 26, 'context_snippet' => '<a href="/fileadmin/report.pdf"></a>']);
        // The site template's logo link: on every page and in German too (only the href differs by language).
        foreach ([1, 2, 3, 4] as $page) {
            $uid = $this->template($page, 0, self::LOGO);
            if ($page === 4) {
                $this->newsTemplateLink = $uid;
            }
        }
        $this->template(3, 1, '<a href="/de/"><img src="/fileadmin/logo.png" class="logo"></a>');
    }

    #[Test]
    public function anEditorsContentTaskIsDoneWhenTheirContentIsFixedEvenIfTheTemplateDefectRemains(): void
    {
        $this->setUpBackendUser(1);
        $this->get(AssignmentService::class)->assignByFinding($this->newsContentLink, 3, 'Jobs PDF link has no text');

        $this->setUpBackendUser(3);
        $before = $this->get(AssignmentService::class)->listTasks(AssignmentService::FILTER_ME);
        self::assertSame([['News', 'rte.empty_link', 1, 'open']], $this->rows($before['tasks']), 'Only Sara\'s own occurrence counts, not the logo.');

        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue')->update('tx_a11y_issue', ['status' => 1, 'resolved_at' => time()], ['uid' => $this->newsContentLink]);
        $after = $this->get(AssignmentService::class)->listTasks(AssignmentService::FILTER_ME, 'any');
        self::assertSame([['News', 'rte.empty_link', 0, 'done']], $this->rows($after['tasks']), 'Done although the template logo is still open on News.');
    }

    #[Test]
    public function aTemplateDefectOnEveryPageAndLanguageIsOneSharedTaskOfTheSite(): void
    {
        $this->setUpBackendUser(1);
        $list = $this->get(AssignmentService::class)->listTasks(AssignmentService::FILTER_ALL);

        $shared = array_values(array_filter($list['tasks'], static fn (array $t): bool => $t['shared']));
        self::assertCount(1, $shared, 'One task for the logo, not one per page and language.');
        self::assertStringStartsWith('template:rte.empty_link:', $shared[0]['issueKey']);
        self::assertSame(5, $shared[0]['open']);
        self::assertSame(4, $shared[0]['pageCount']);
        self::assertSame(2, $shared[0]['languageCount']);
        self::assertSame('developer', $shared[0]['owner']);
        $content = array_values(array_filter($list['tasks'], static fn (array $t): bool => !$t['shared']));
        self::assertSame([['News', 'rte.empty_link', 1, 'open']], $this->rows($content), 'No content task is created from the template rows.');
        self::assertSame(2, $list['unassignedOpen'], 'The shared template task and the News content task.');
    }

    #[Test]
    public function aTemplateTaskIsAssignedOnceToSomeoneWhoCanOpenTheSiteRoot(): void
    {
        $this->setUpBackendUser(1);
        $service = $this->get(AssignmentService::class);
        $result = $service->assignByFinding($this->newsTemplateLink, 2, 'Ask the agency to add alt text to the logo');
        self::assertStringStartsWith('template:rte.empty_link:', $result['issueKey']);
        $rows = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_assignment')->select(['page_uid', 'language_uid', 'issue_key', 'assignee'], 'tx_a11y_assignment')->fetchAllAssociative();
        self::assertSame([['page_uid' => 0, 'language_uid' => 0, 'issue_key' => $result['issueKey'], 'assignee' => 2]], array_map(static fn (array $r): array => array_map(static fn ($v) => is_numeric($v) ? (int)$v : $v, $r), $rows));

        try {
            $service->assignByFinding($this->newsTemplateLink, 3);
            self::fail('Sara (News only) cannot work on the site template.');
        } catch (AssignmentException $exception) {
            self::assertSame('assignee_no_page', $exception->reason);
        }
        self::assertSame([1, 2], $this->sorted(array_column($service->candidatesForFinding($this->newsTemplateLink), 'uid')));
        self::assertArrayHasKey($result['issueKey'], $service->chipsForPage('northwind', 4, 0), 'Page Detail shows the shared task\'s assignee on every affected page.');
        self::assertArrayHasKey($result['issueKey'], $service->chipsForPage('northwind', 3, 1));

        $this->setUpBackendUser(2);
        $anna = $this->get(AssignmentService::class)->listTasks(AssignmentService::FILTER_ME);
        self::assertSame([$result['issueKey']], array_column($anna['tasks'], 'issueKey'));
    }

    #[Test]
    public function theSharedTaskIsDoneOnlyWhenTheTemplateIsFixedOnEveryPageAndLanguage(): void
    {
        $this->setUpBackendUser(1);
        $service = $this->get(AssignmentService::class);
        $key = $service->assignByFinding($this->newsTemplateLink, 2)['issueKey'];
        $issues = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue');
        $issues->update('tx_a11y_issue', ['status' => 1, 'resolved_at' => time()], ['source_table' => 'pages', 'source_lang_uid' => 0]);
        $open = array_values(array_filter($this->get(AssignmentService::class)->listTasks(AssignmentService::FILTER_ALL, 'any')['tasks'], static fn (array $t): bool => $t['issueKey'] === $key));
        self::assertSame([1, 'open'], [$open[0]['open'], $open[0]['state']], 'The German page still has the logo defect.');

        $issues->update('tx_a11y_issue', ['status' => 1, 'resolved_at' => time()], ['source_table' => 'pages']);
        $done = array_values(array_filter($this->get(AssignmentService::class)->listTasks(AssignmentService::FILTER_ALL, 'any')['tasks'], static fn (array $t): bool => $t['issueKey'] === $key));
        self::assertSame([0, 'done'], [$done[0]['open'], $done[0]['state']]);
    }

    #[Test]
    public function editorsWithoutTheSiteRootNeverSeeOrAssignSharedTemplateWork(): void
    {
        $this->setUpBackendUser(3);
        $service = $this->get(AssignmentService::class);
        self::assertSame([], $service->candidatesForFinding($this->newsTemplateLink));
        self::assertSame([], array_values(array_filter($service->listTasks(AssignmentService::FILTER_ME, 'any')['tasks'], static fn (array $t): bool => $t['shared'])));
        try {
            $service->assignByFinding($this->newsTemplateLink, 3);
            self::fail('Sara may not assign.');
        } catch (AssignmentException $exception) {
            self::assertContains($exception->reason, ['permission_denied', 'not_found']);
        }
    }

    private function template(int $page, int $language, string $snippet): int
    {
        return $this->insertIssue(['site_identifier' => 'northwind', 'page_uid' => $page, 'source_lang_uid' => $language, 'rule_id' => 'rendered.empty_link',
            'severity' => 1, 'source_table' => 'pages', 'source_uid' => $page, 'source_field' => '__rendered_html', 'source_type' => 'rendered',
            'context_snippet' => $snippet]);
    }

    /**
     * @param list<array<string,mixed>> $tasks
     * @return list<array{0:string,1:string,2:int,3:string}>
     */
    private function rows(array $tasks): array
    {
        return array_map(static fn (array $t): array => [$t['pageTitle'], $t['issueKey'], $t['open'], $t['state']], $tasks);
    }

    /** @param list<int> $values @return list<int> */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
