<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Service\RemoteScreenshotAccessPolicy;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * SEC-03 (2026-10 Sol audit): the screenshot proxy demanded edit access while the report it belongs to only needs read
 * access, so a read-only editor saw the frontend page detail and a 403 for its screenshot. It now follows the report's
 * read policy, with real backend users and page permissions:
 *
 * - page 2 (site "main", root 1): the reader (show only) and the editor may see it;
 * - page 11 (site "other", root 10): outside both editors' mount;
 * - a page scan of page 2 recorded for site "other": a page of another site never opens through that site;
 * - a Free Remote Preview result never has a screenshot.
 */
final class RemoteScreenshotAccessFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        foreach ([[1, 0, 1], [2, 1, 0], [10, 0, 1], [11, 10, 0]] as [$uid, $pid, $isRoot]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => 'Page ' . $uid, 'doktype' => 1, 'is_siteroot' => $isRoot,
                'perms_userid' => 0, 'perms_groupid' => 20, 'perms_user' => 0, 'perms_group' => 31, 'perms_everybody' => 1,
                'deleted' => 0, 'hidden' => 0,
            ]);
        }

        foreach ([[20, 'editors', 'pages,tt_content'], [21, 'readers', '']] as [$uid, $title, $tablesModify]) {
            $pool->getConnectionForTable('be_groups')->insert('be_groups', [
                'uid' => $uid, 'pid' => 0, 'title' => $title, 'groupMods' => 'web,web_a11y', 'tables_select' => 'pages,tt_content',
                'tables_modify' => $tablesModify, 'db_mountpoints' => '1', 'deleted' => 0, 'hidden' => 0,
            ]);
        }
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        foreach ([[2, 'editor', '20'], [3, 'reader', '21']] as [$uid, $username, $groups]) {
            $pool->getConnectionForTable('be_users')->insert('be_users', [
                'uid' => $uid, 'username' => $username, 'admin' => 0, 'usergroup' => $groups, 'options' => 3,
                'workspace_perms' => 1, 'disable' => 0, 'deleted' => 0,
            ]);
        }

        $scans = $pool->getConnectionForTable('tx_a11y_remote_scan');
        $pages = $pool->getConnectionForTable('tx_a11y_remote_scan_page');
        foreach ([
            // remote scan uid, site, scope, page, free preview, remote page uid
            [100, 'main', 'page', 2, 0, 1000],
            [101, 'main', 'site', 0, 0, 1001],
            [102, 'other', 'page', 11, 0, 1002],
            [103, 'other', 'page', 2, 0, 1003],
            [104, 'main', 'page', 2, 1, 1004],
        ] as [$scanUid, $site, $scope, $pageUid, $free, $remotePageUid]) {
            $scans->insert('tx_a11y_remote_scan', [
                'uid' => $scanUid, 'site_identifier' => $site, 'job_id' => 'job-' . $scanUid, 'status' => 'completed',
                'scan_scope' => $scope, 'page_uid' => $pageUid, 'is_free_preview' => $free,
            ]);
            $pages->insert('tx_a11y_remote_scan_page', [
                'uid' => $remotePageUid, 'remote_scan' => $scanUid, 'url' => 'https://main.test/page', 'screenshot_path' => 'storage/screenshots/a.png',
            ]);
        }

        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://main.test/');
        $this->get(SiteWriter::class)->createNewBasicSite('other', 10, 'https://other.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    #[Test]
    public function aReadOnlyEditorSeesTheScreenshotOfAReportTheyMayRead(): void
    {
        $this->setUpBackendUser(3);
        $policy = $this->get(RemoteScreenshotAccessPolicy::class);

        self::assertTrue($policy->canRead(1000), 'page scan of a page with show access');
        self::assertTrue($policy->canRead(1001), 'site scan with show access to the site root');
    }

    #[Test]
    public function anEditorSeesTheScreenshotsOfTheirSite(): void
    {
        $this->setUpBackendUser(2);
        $policy = $this->get(RemoteScreenshotAccessPolicy::class);

        self::assertTrue($policy->canRead(1000));
        self::assertTrue($policy->canRead(1001));
    }

    #[Test]
    public function aPageOutsideTheMountIsForbidden(): void
    {
        foreach ([2, 3] as $user) {
            $this->setUpBackendUser($user);
            self::assertFalse($this->get(RemoteScreenshotAccessPolicy::class)->canRead(1002));
        }
    }

    #[Test]
    public function aPageOfAnotherSiteDoesNotOpenThroughThatSitesScan(): void
    {
        // The stored scan claims site "other" for page 2 of "main": the site root of "other" decides, outside the mount.
        $this->setUpBackendUser(3);

        self::assertFalse($this->get(RemoteScreenshotAccessPolicy::class)->canRead(1003));
    }

    #[Test]
    public function aFreeRemotePreviewResultHasNoScreenshotWhateverTheUser(): void
    {
        $this->setUpBackendUser(1);
        $policy = $this->get(RemoteScreenshotAccessPolicy::class);

        self::assertFalse($policy->canRead(1004));
        self::assertTrue($policy->canRead(1000), 'the administrator still reads the paid result');
        self::assertFalse($policy->canRead(999999), 'an unknown page is not readable');
    }
}
