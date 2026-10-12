<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Backend\ToolbarItems\A11yScanToolbarItem;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\PageModuleIndicatorService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * AQG data and actions belong to users with access to the Accessibility module (web_a11y).
 *
 * Regression guard: an editor without the module still saw the AQG toolbar item with scan status, and the AQG panel
 * with counts and "Scan this page" in Web > Page, because both only checked TSconfig visibility flags.
 */
final class ModuleAccessFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1,
            'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0,
        ]);
        foreach ([[30, 'web_layout,web_a11y'], [31, 'web_layout']] as [$uid, $modules]) {
            $pool->getConnectionForTable('be_groups')->insert('be_groups', [
                'uid' => $uid, 'pid' => 0, 'title' => 'group ' . $uid, 'groupMods' => $modules,
                'tables_select' => 'pages,tt_content', 'tables_modify' => 'pages,tt_content', 'db_mountpoints' => '1',
                'deleted' => 0, 'hidden' => 0,
            ]);
        }
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        foreach ([[2, 'with-module', '30'], [3, 'without-module', '31']] as [$uid, $name, $groups]) {
            $pool->getConnectionForTable('be_users')->insert('be_users', [
                'uid' => $uid, 'username' => $name, 'admin' => 0, 'usergroup' => $groups, 'options' => 3,
                'workspace_perms' => 1, 'disable' => 0, 'deleted' => 0,
            ]);
        }

        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://main.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    #[Test]
    public function onlyUsersWithTheAccessibilityModuleHaveModuleAccess(): void
    {
        $this->setUpBackendUser(1);
        self::assertTrue($this->get(AccessControlService::class)->canAccessModule());

        $this->setUpBackendUser(2);
        self::assertTrue($this->get(AccessControlService::class)->canAccessModule());

        $this->setUpBackendUser(3);
        self::assertFalse($this->get(AccessControlService::class)->canAccessModule());
    }

    #[Test]
    public function theToolbarItemIsHiddenWithoutTheModule(): void
    {
        $this->setUpBackendUser(3);
        $toolbarItem = $this->get(A11yScanToolbarItem::class);

        self::assertFalse($toolbarItem->checkAccess());
        self::assertSame('', $toolbarItem->getItem());
        self::assertSame('', $toolbarItem->getDropDown());
    }

    #[Test]
    public function theToolbarItemStaysForUsersWithTheModule(): void
    {
        $this->setUpBackendUser(2);

        self::assertTrue($this->get(A11yScanToolbarItem::class)->checkAccess());
    }

    #[Test]
    public function thePageModulePanelCarriesNoAqgDataWithoutTheModule(): void
    {
        $this->setUpBackendUser(3);
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('main');
        $indicator = $this->get(PageModuleIndicatorService::class);

        self::assertNull($indicator->buildViewData(1, $site, 0));
        self::assertSame('', $indicator->buildForPage(1, $site, 0));
        self::assertNull($indicator->buildState(1, $site, 0));
    }

    #[Test]
    public function thePageModulePanelStaysForUsersWithTheModule(): void
    {
        $this->setUpBackendUser(2);
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('main');

        self::assertIsArray($this->get(PageModuleIndicatorService::class)->buildViewData(1, $site, 0));
    }
}
