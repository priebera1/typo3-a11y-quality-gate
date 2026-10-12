<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Service\ScanPermissionService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Scan controls follow the rule the scan endpoints enforce: the `showScanNow` / `showScanAll` user TSconfig flag AND
 * edit access to the page (or site root).
 *
 * Regression guard: a view-only editor saw every "Scan this page" / "Scan site" button and got
 * "Scan failed: Unknown error" (HTTP 403); with `showScanNow = 0` the Overview table rows still offered the scan.
 */
final class ScanPermissionFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        foreach ([[1, 0, 1], [2, 1, 0]] as [$uid, $pid, $isRoot]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid,
                'pid' => $pid,
                'title' => 'Page ' . $uid,
                'doktype' => 1,
                'is_siteroot' => $isRoot,
                'perms_userid' => 0,
                'perms_groupid' => 20,
                'perms_user' => 0,
                'perms_group' => 31,
                'perms_everybody' => 1,
                'deleted' => 0,
                'hidden' => 0,
            ]);
        }

        // Group 20 may edit pages; group 21 has the same mount but no tables_modify (view only).
        $this->insertGroup($pool, 20, 'editors', 'pages,tt_content', '');
        $this->insertGroup($pool, 21, 'readers', '', '');
        $this->insertGroup($pool, 22, 'editors without scan', 'pages,tt_content', "options.a11y_quality_gate.showScanNow = 0\noptions.a11y_quality_gate.showScanAll = 0");
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $this->insertUser($pool, 2, 'editor', '20');
        $this->insertUser($pool, 3, 'reader', '21');
        $this->insertUser($pool, 4, 'editor-no-scan', '22');

        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://main.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    #[Test]
    public function anAdministratorMayScanPagesAndTheSite(): void
    {
        $this->setUpBackendUser(1);
        $permissions = $this->get(ScanPermissionService::class);

        self::assertTrue($permissions->canScanPage(2));
        self::assertTrue($permissions->canScanSite(1));
    }

    #[Test]
    public function anEditorMayScanPagesAndTheSite(): void
    {
        $this->setUpBackendUser(2);
        $permissions = $this->get(ScanPermissionService::class);

        self::assertTrue($permissions->canScanPage(2));
        self::assertTrue($permissions->canScanSite(1));
    }

    #[Test]
    public function aViewOnlyUserMayNotScanAlthoughTheScanFlagsAreOn(): void
    {
        $this->setUpBackendUser(3);
        $permissions = $this->get(ScanPermissionService::class);

        self::assertFalse($permissions->canScanPage(2), 'The endpoint answers 403 without edit access to the page.');
        self::assertFalse($permissions->canScanSite(1));
    }

    #[Test]
    public function theTsconfigFlagsHideScansFromEditorsToo(): void
    {
        $this->setUpBackendUser(4);
        $permissions = $this->get(ScanPermissionService::class);

        self::assertFalse($permissions->canScanPage(2));
        self::assertFalse($permissions->canScanSite(1));
    }

    #[Test]
    public function aMissingPageCannotBeScanned(): void
    {
        $this->setUpBackendUser(1);

        self::assertFalse($this->get(ScanPermissionService::class)->canScanPage(0));
    }

    #[Test]
    public function onlyUsersWhoMayStartTheScanMayCancelTheRunningContentScan(): void
    {
        $runningPageScan = ['running' => true, 'pageUid' => 2, 'rootPid' => 1];

        $this->setUpBackendUser(2);
        self::assertTrue($this->get(ScanPermissionService::class)->canCancelLocalScan($runningPageScan));

        // Regression guard: the progress block offered Cancel to view-only users; the endpoint answered 403.
        foreach ([3, 4] as $userWithoutScanAccess) {
            $this->setUpBackendUser($userWithoutScanAccess);
            self::assertFalse($this->get(ScanPermissionService::class)->canCancelLocalScan($runningPageScan));
        }
    }

    #[Test]
    public function onlyUsersWhoMayStartTheScanMayCancelARunningFrontendScan(): void
    {
        $pageScan = ['site_identifier' => 'main', 'scan_scope' => 'page', 'page_uid' => 2];
        $siteScan = ['site_identifier' => 'main', 'scan_scope' => 'site', 'page_uid' => 0];

        $this->setUpBackendUser(2);
        $editor = $this->get(ScanPermissionService::class);
        self::assertTrue($editor->canCancelRemoteScan($pageScan));
        self::assertTrue($editor->canCancelRemoteScan($siteScan));

        foreach ([3, 4] as $userWithoutScanAccess) {
            $this->setUpBackendUser($userWithoutScanAccess);
            self::assertFalse($this->get(ScanPermissionService::class)->canCancelRemoteScan($pageScan));
            self::assertFalse($this->get(ScanPermissionService::class)->canCancelRemoteScan($siteScan));
        }
    }

    private function insertGroup(ConnectionPool $pool, int $uid, string $title, string $tablesModify, string $tsConfig): void
    {
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => $uid,
            'pid' => 0,
            'title' => $title,
            'groupMods' => 'web,web_a11y',
            'tables_select' => 'pages,tt_content',
            'tables_modify' => $tablesModify,
            'db_mountpoints' => '1',
            'TSconfig' => $tsConfig,
            'deleted' => 0,
            'hidden' => 0,
        ]);
    }

    private function insertUser(ConnectionPool $pool, int $uid, string $username, string $groups): void
    {
        $pool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => $uid,
            'username' => $username,
            'admin' => 0,
            'usergroup' => $groups,
            'options' => 3,
            'workspace_perms' => 1,
            'disable' => 0,
            'deleted' => 0,
        ]);
    }
}
