<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\LargeInstallation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Service\BatchedPageShowAccess;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Large-installation stress test (AQG 3.0.0): the batched page check that replaced readPageAccess() per page (32,000
 * queries per My issues request at 80 sites) must answer exactly as readPageAccess() does, page by page.
 *
 * Tree: 1 site A root · 2 Products · 3 Locked (other owner, no group/everybody show) · 4 below Locked · 5 deleted ·
 * 6 below the deleted page · 7 German translation of 2 · 8 German translation of 3 · 20 site B root · 21 below it.
 * Users: 2 editor (mount 1) · 3 section editor (mount 2) · 4 editor without any mount.
 */
final class BatchedPageShowAccessFunctionalTest extends AbstractFunctionalTestCase
{
    private const PAGES = [1, 2, 3, 4, 5, 6, 7, 8, 20, 21, 999];

    protected function setUp(): void
    {
        parent::setUp();
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pages = $pool->getConnectionForTable('pages');
        $open = ['perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 31, 'perms_everybody' => 0];
        $locked = ['perms_userid' => 99, 'perms_groupid' => 99, 'perms_user' => 31, 'perms_group' => 31, 'perms_everybody' => 0];
        foreach ([
            [1, 0, 'Site A', 1, 0, 0, 0, $open],
            [2, 1, 'Products', 0, 0, 0, 0, $open],
            [3, 1, 'Locked', 0, 0, 0, 0, $locked],
            [4, 3, 'Below locked', 0, 0, 0, 0, $open],
            [5, 1, 'Deleted', 0, 1, 0, 0, $open],
            [6, 5, 'Below deleted', 0, 0, 0, 0, $open],
            [7, 1, 'Produkte', 0, 0, 1, 2, $open],
            [8, 1, 'Gesperrt', 0, 0, 1, 3, $open],
            [20, 0, 'Site B', 1, 0, 0, 0, $open],
            [21, 20, 'B page', 0, 0, 0, 0, $open],
        ] as [$uid, $pid, $title, $root, $deleted, $language, $parent, $perms]) {
            $pages->insert('pages', ['uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => '/' . $uid, 'doktype' => 1, 'is_siteroot' => $root,
                'deleted' => $deleted, 'sys_language_uid' => $language, 'l10n_parent' => $parent] + $perms);
        }
        $groups = $pool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', ['uid' => 1, 'title' => 'editors', 'groupMods' => 'web_a11y', 'db_mountpoints' => '1', 'tables_select' => 'pages']);
        $groups->insert('be_groups', ['uid' => 2, 'title' => 'section', 'groupMods' => 'web_a11y', 'db_mountpoints' => '2', 'tables_select' => 'pages']);
        $users = $pool->getConnectionForTable('be_users');
        foreach ([[1, 'admin', 1, ''], [2, 'editor', 0, '1'], [3, 'section', 0, '2'], [4, 'nomount', 0, '']] as [$uid, $name, $admin, $usergroup]) {
            $users->insert('be_users', ['uid' => $uid, 'username' => $name, 'admin' => $admin, 'usergroup' => $usergroup, 'disable' => 0, 'deleted' => 0]);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function users(): iterable
    {
        yield 'editor with the site mount' => [2];
        yield 'section editor' => [3];
        yield 'editor without a mount' => [4];
    }

    #[Test]
    #[DataProvider('users')]
    public function answersExactlyAsReadPageAccessForEveryPage(int $userUid): void
    {
        $backendUser = $this->setUpBackendUser($userUid);
        $expected = [];
        foreach (self::PAGES as $uid) {
            $page = BackendUtility::readPageAccess($uid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
            $expected[$uid] = is_array($page) && $page !== [];
        }

        $actual = BatchedPageShowAccess::readableMap($backendUser, GeneralUtility::makeInstance(ConnectionPool::class), self::PAGES);

        self::assertSame($expected, $actual);
    }

    #[Test]
    public function theFixtureCoversBothAnswers(): void
    {
        $actual = BatchedPageShowAccess::readableMap($this->setUpBackendUser(2), GeneralUtility::makeInstance(ConnectionPool::class), self::PAGES);

        self::assertSame([1 => true, 2 => true, 3 => false, 4 => false, 5 => false, 6 => false, 7 => true, 8 => false, 20 => false, 21 => false, 999 => false], $actual);
    }

    #[Test]
    public function administratorsAndWorkspacesKeepThePerPageCheck(): void
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        self::assertNull(BatchedPageShowAccess::readableMap($this->setUpBackendUser(1), $pool, self::PAGES));

        $editor = $this->setUpBackendUser(2);
        $editor->workspace = 1;
        self::assertNull(BatchedPageShowAccess::readableMap($editor, $pool, self::PAGES), 'workspace rootlines are not modelled');
    }
}
