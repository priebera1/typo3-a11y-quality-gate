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
 * G-09 (AQG 3.0.0 security gate): My issues aggregated findings by page and language only. Counts, task existence and
 * the stored rule message (which can name files or form fields) included findings of records the viewer may not read
 * (no tables_select for the table, a language they lack). Page Detail filtered them; My issues now applies the same
 * source constraint (LocalFindingSourceAccessService) for every non-administrator.
 *
 * Users: 1 admin · 2 Anna (assigner, root mount, tt_content only, language 0)
 * Page 1 root · 2 Products. Records on page 2: tt_content 10 (EN), tt_content 11 (DE), sys_file_reference 20.
 */
final class MyIssuesSourceAccessFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pages = $pool->getConnectionForTable('pages');
        foreach ([[1, 0, 'Root', 1], [2, 1, 'Products', 0]] as [$uid, $pid, $title, $root]) {
            $pages->insert('pages', ['uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => '/' . $uid, 'doktype' => 1, 'is_siteroot' => $root,
                'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 31, 'perms_everybody' => 0]);
        }
        $content = $pool->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 10, 'pid' => 2, 'CType' => 'text', 'header' => 'Intro', 'sys_language_uid' => 0]);
        $content->insert('tt_content', ['uid' => 11, 'pid' => 2, 'CType' => 'text', 'header' => 'Einleitung', 'sys_language_uid' => 1, 'l18n_parent' => 10]);
        $pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', ['uid' => 20, 'pid' => 2, 'uid_local' => 1,
            'uid_foreign' => 10, 'tablenames' => 'tt_content', 'fieldname' => 'image']);

        $groups = $pool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', ['uid' => 1, 'title' => 'editors', 'groupMods' => 'web_a11y,web_layout', 'db_mountpoints' => '1',
            'tables_select' => 'pages,tt_content', 'tables_modify' => 'pages,tt_content', 'allowed_languages' => '0',
            'TSconfig' => 'options.a11y_quality_gate.assign = 1']);
        $users = $pool->getConnectionForTable('be_users');
        foreach ([[1, 'admin', 'Alex Admin', 1, ''], [2, 'anna', 'Anna Berger', 0, '1']] as [$uid, $name, $real, $admin, $usergroup]) {
            $users->insert('be_users', ['uid' => $uid, 'username' => $name, 'realName' => $real, 'admin' => $admin, 'usergroup' => $usergroup, 'disable' => 0, 'deleted' => 0]);
        }
        $this->get(SiteWriter::class)->createNewBasicSite('northwind', 1, 'https://northwind.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);

        $finding = fn (array $values): int => $this->insertIssue(['site_identifier' => 'northwind', 'page_uid' => 2, 'severity' => 1] + $values);
        // Readable: an English text element.
        $finding(['rule_id' => 'rte.empty_link', 'source_table' => 'tt_content', 'source_uid' => 10, 'message' => 'Link has no text.']);
        // Not readable for Anna: a file reference (no tables_select) and the German translation (no language 1).
        $finding(['rule_id' => 'image.alt_missing', 'source_table' => 'sys_file_reference', 'source_uid' => 20, 'source_field' => 'alternative',
            'message' => 'Image confidential-merger-plan.jpg has no alternative text.']);
        $finding(['rule_id' => 'rte.empty_link', 'source_table' => 'tt_content', 'source_uid' => 11, 'source_lang_uid' => 1, 'message' => 'Link has no text.']);
        $finding(['rule_id' => 'rte.empty_heading', 'source_table' => 'tt_content', 'source_uid' => 11, 'source_lang_uid' => 1, 'message' => 'Heading "Geheim" is empty.']);
    }

    #[Test]
    public function myIssuesShowsOnlyFindingsOfSourcesTheViewerMayRead(): void
    {
        $this->setUpBackendUser(2);
        $list = $this->get(AssignmentService::class)->listTasks(AssignmentService::FILTER_ALL, 'any');
        $tasks = array_map(static fn (array $t): array => [$t['issueKey'], $t['languageUid'], $t['open']], $list['tasks']);

        self::assertSame([['rte.empty_link', 0, 1]], $tasks, 'neither the file reference nor the German findings form or count a task');
        $text = json_encode($list, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('confidential-merger-plan', $text, 'no stored message of an unreadable record');
        self::assertStringNotContainsString('Geheim', $text);
        self::assertSame(1, $list['total']);
    }

    #[Test]
    public function administratorsStillSeeEveryTask(): void
    {
        $this->setUpBackendUser(1);
        $list = $this->get(AssignmentService::class)->listTasks(AssignmentService::FILTER_ALL, 'any');
        $keys = array_map(static fn (array $t): string => $t['issueKey'] . ':' . $t['languageUid'], $list['tasks']);
        sort($keys);

        self::assertSame(['image.alt_missing:0', 'rte.empty_heading:1', 'rte.empty_link:0', 'rte.empty_link:1'], $keys);
    }
}
