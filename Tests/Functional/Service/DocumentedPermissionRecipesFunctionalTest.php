<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The two group configurations of Documentation/Configuration/Permissions.rst, entered as an administrator would enter
 * them in the backend user group form, give exactly the documented rights. Change the documentation and this test
 * together.
 */
final class DocumentedPermissionRecipesFunctionalTest extends AbstractFunctionalTestCase
{
    private const REGULAR_GROUP = 10;
    private const RESTRICTED_GROUP = 11;
    // Permissions module: Show page (1) + Edit page (2) + Edit content (16)
    private const GROUP_PAGE_PERMS = 19;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->getConnectionPool();
        // [uid, pid, title, root, owning group]
        foreach ([
            [1, 0, 'Site A', 1, self::REGULAR_GROUP],
            [2, 1, 'News', 0, self::REGULAR_GROUP],
            [3, 1, 'Section', 0, self::RESTRICTED_GROUP],
            [4, 3, 'Section page', 0, self::RESTRICTED_GROUP],
            [100, 0, 'Site B', 1, 0],
        ] as [$uid, $pid, $title, $root, $group]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => '/p' . $uid, 'doktype' => 1, 'is_siteroot' => $root,
                'perms_userid' => 99, 'perms_user' => 31, 'perms_groupid' => $group,
                'perms_group' => $group > 0 ? self::GROUP_PAGE_PERMS : 0, 'perms_everybody' => 0,
            ]);
        }
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 10, 'pid' => 2, 'CType' => 'image', 'header' => 'Chart', 'image' => 1]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 11, 'pid' => 4, 'CType' => 'image', 'header' => 'Chart', 'image' => 1]);
        // A real image file: TYPO3 shows alternative text only on references to images (Sol QA-01 review, form layout).
        $this->getConnectionPool()->getConnectionForTable('sys_file')->insert('sys_file', ['uid' => 5, 'pid' => 0, 'storage' => 1, 'type' => 2, 'name' => 'fixture.png', 'identifier' => '/fixture-5.png', 'mime_type' => 'image/png']);
        $pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', ['uid' => 20, 'pid' => 2, 'uid_local' => 5, 'uid_foreign' => 10, 'tablenames' => 'tt_content', 'fieldname' => 'image', 'sys_language_uid' => 0]);
        $pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', ['uid' => 21, 'pid' => 4, 'uid_local' => 5, 'uid_foreign' => 11, 'tablenames' => 'tt_content', 'fieldname' => 'image', 'sys_language_uid' => 0]);

        $documented = [
            'groupMods' => 'web_layout,web_list,web_a11y',
            'tables_select' => 'pages,tt_content,sys_file_reference,sys_file_metadata',
            'tables_modify' => 'pages,tt_content,sys_file_reference,sys_file_metadata',
            'non_exclude_fields' => 'tt_content:image,sys_file_reference:alternative,sys_file_reference:tx_a11y_is_decorative,sys_file_metadata:alternative,sys_file_metadata:title,sys_file_metadata:description',
            'explicit_allowdeny' => 'tt_content:CType:text,tt_content:CType:textmedia,tt_content:CType:image',
        ];
        $groups = $pool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', ['uid' => self::REGULAR_GROUP, 'title' => 'Accessibility editors', 'db_mountpoints' => '1',
            'TSconfig' => "# Hide the Settings view; global settings are administrator-only anyway.\noptions.a11y_quality_gate.showSettings = 0\n"] + $documented);
        $groups->insert('be_groups', ['uid' => self::RESTRICTED_GROUP, 'title' => 'Section editors', 'db_mountpoints' => '3',
            'TSconfig' => "options.a11y_quality_gate {\n    showScanAll = 0\n    showSettings = 0\n}\n"] + $documented);
        $users = $pool->getConnectionForTable('be_users');
        $users->insert('be_users', ['uid' => 2, 'username' => 'regular', 'admin' => 0, 'usergroup' => (string)self::REGULAR_GROUP, 'disable' => 0, 'deleted' => 0]);
        $users->insert('be_users', ['uid' => 3, 'username' => 'restricted', 'admin' => 0, 'usergroup' => (string)self::RESTRICTED_GROUP, 'disable' => 0, 'deleted' => 0]);

        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->createNewBasicSite('site-a', 1, 'https://a.test/');
        $siteWriter->createNewBasicSite('site-b', 100, 'https://b.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    #[Test]
    public function theRegularAccessibilityEditorGetsTheDocumentedRights(): void
    {
        $user = $this->setUpBackendUser(2);
        $scope = $this->get(ScopeAccessService::class);
        $records = $this->get(BackendRecordAccessService::class);
        $aqg = $this->get(AccessControlService::class);

        self::assertTrue($aqg->canAccessModule($user));
        self::assertTrue($scope->canReadSiteIdentifier('site-a'), 'Overview of their site');
        self::assertFalse($scope->canReadSiteIdentifier('site-b'), 'no other site');
        self::assertTrue($scope->canEditPage(2), 'page scans');
        self::assertTrue($scope->canEditSite($this->get(SiteFinder::class)->getSiteByIdentifier('site-a')), 'site scans');
        self::assertTrue($aqg->canShowScanNow($user) && $aqg->canShowScanAll($user) && $aqg->canEditRecord($user));
        self::assertTrue($records->canEditRecordFields('tt_content', 10, ['image']));
        self::assertTrue($records->canEditRecordFields('sys_file_reference', 20, ['alternative', 'tx_a11y_is_decorative']), 'image fixes');
        self::assertTrue($aqg->canRemediateImages($user), 'no TSconfig needed for image fixes');
        self::assertFalse($aqg->canManageAdminOnlySettings($user), 'no global settings');
        self::assertFalse($aqg->canShowSettings($user), 'the optional line hides the Settings view');
    }

    #[Test]
    public function theRestrictedAccessibilityEditorGetsTheDocumentedRights(): void
    {
        $user = $this->setUpBackendUser(3);
        $scope = $this->get(ScopeAccessService::class);
        $records = $this->get(BackendRecordAccessService::class);
        $aqg = $this->get(AccessControlService::class);

        self::assertFalse($scope->canReadSiteIdentifier('site-a'), 'no site Overview without the site root');
        self::assertFalse($scope->canReadPage(2), 'no page outside the section');
        self::assertTrue($scope->canReadPage(4));
        self::assertTrue($scope->canEditPage(4), 'page scans in the section');
        self::assertFalse($aqg->canShowScanAll($user), 'no site or subtree scans');
        self::assertTrue($aqg->canShowScanNow($user));
        self::assertTrue($records->canEditRecordFields('sys_file_reference', 21, ['alternative', 'tx_a11y_is_decorative']), 'image fixes in the section');
        self::assertFalse($records->canEditRecordFields('sys_file_reference', 20, ['alternative']), 'none outside it');
        self::assertFalse($aqg->canManageAdminOnlySettings($user));
    }
}
