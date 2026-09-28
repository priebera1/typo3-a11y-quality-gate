<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Service\RemoteScanPairingService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Results belong to pages and sites; module access alone must never open another site's results.
 * Two sites, editors with different mounts, and scans whose identifiers a request could tamper with.
 */
final class ScopeAccessFunctionalTest extends AbstractFunctionalTestCase
{
    private const JOB_A_OLD = '11111111-1111-4111-8111-111111111111';
    private const JOB_A_NEW = '22222222-2222-4222-8222-222222222222';
    private const JOB_A_DE = '33333333-3333-4333-8333-333333333333';
    private const JOB_B = '44444444-4444-4444-8444-444444444444';

    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        foreach ([
            [1, 0, 'Client A', 1],
            [2, 1, 'Client A page', 0],
            [100, 0, 'Client B', 1],
            [101, 100, 'Client B page', 0],
        ] as [$uid, $pid, $title, $isRoot]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid,
                'pid' => $pid,
                'title' => $title,
                'doktype' => 1,
                'is_siteroot' => $isRoot,
                'perms_userid' => 0,
                'perms_groupid' => 0,
                'perms_user' => 0,
                'perms_group' => 0,
                'perms_everybody' => 31,
                'deleted' => 0,
                'hidden' => 0,
            ]);
        }

        $this->insertGroup($pool, 10, 'Client A editors', '1', 'pages,tt_content');
        $this->insertGroup($pool, 11, 'Client A subtree editors', '2', 'pages,tt_content');
        $this->insertGroup($pool, 12, 'Client A readers', '1', '');
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $this->insertUser($pool, 2, 'client-a-editor', 10);
        $this->insertUser($pool, 3, 'client-a-subtree', 11);
        $this->insertUser($pool, 4, 'client-a-reader', 12);

        $this->insertRemoteScan($pool, 1, 'client-a', 'site', 0, 0, self::JOB_A_OLD, 'https://a.test/', 1000);
        $this->insertRemoteScan($pool, 2, 'client-a', 'site', 0, 0, self::JOB_A_NEW, 'https://a.test/', 2000);
        $this->insertRemoteScan($pool, 3, 'client-a', 'site', 0, 1, self::JOB_A_DE, 'https://a.test/de/', 3000);
        $this->insertRemoteScan($pool, 4, 'client-b', 'site', 0, 0, self::JOB_B, 'https://b.test/', 2500);
        $this->insertRemoteScan($pool, 5, 'client-a', 'page', 2, 0, '55555555-5555-4555-8555-555555555555', 'https://a.test/page', 2600);
        // A page scan row that names a page of another site: it may not borrow that page's scope.
        $this->insertRemoteScan($pool, 6, 'client-a', 'page', 101, 0, '66666666-6666-4666-8666-666666666666', 'https://a.test/x', 2700);
        $pool->getConnectionForTable('tx_a11y_remote_scan_page')->insert('tx_a11y_remote_scan_page', [
            'uid' => 50, 'remote_scan' => 4, 'url' => 'https://b.test/private', 'crdate' => time(), 'tstamp' => time(),
        ]);

        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->createNewBasicSite('client-a', 1, 'https://a.test/');
        $siteWriter->createNewBasicSite('client-b', 100, 'https://b.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    #[Test]
    public function anEditorOfOneClientCannotReadTheOtherClientsResults(): void
    {
        $this->setUpBackendUser(2);
        $access = $this->get(ScopeAccessService::class);
        $sites = $this->get(SiteFinder::class);

        self::assertTrue($access->canReadSite($sites->getSiteByIdentifier('client-a')));
        self::assertFalse($access->canReadSite($sites->getSiteByIdentifier('client-b')));
        self::assertFalse($access->canReadSiteIdentifier('client-b'));
        self::assertTrue($access->canReadRemoteScan(['site_identifier' => 'client-a', 'page_uid' => 0]));
        self::assertFalse($access->canReadRemoteScan(['site_identifier' => 'client-b', 'page_uid' => 0]));
        self::assertNull($access->resolveReadableScanForRemotePage(['uid' => 50, 'remote_scan' => 4]));
        self::assertNull($access->resolveReadableScanByJobId(self::JOB_B, 'client-b'));
        // A job of another site cannot be read through this site's identifier either.
        self::assertNull($access->resolveReadableScanByJobId(self::JOB_B, 'client-a'));
    }

    #[Test]
    public function aSubtreeEditorReadsItsPageScanButNotSiteWideResults(): void
    {
        $this->setUpBackendUser(3);
        $access = $this->get(ScopeAccessService::class);

        self::assertTrue($access->canReadPage(2));
        self::assertFalse($access->canReadPage(1));
        self::assertFalse($access->canReadSiteIdentifier('client-a'));
        self::assertTrue($access->canReadRemoteScan(['site_identifier' => 'client-a', 'page_uid' => 2]));
        self::assertFalse($access->canReadRemoteScan(['site_identifier' => 'client-a', 'page_uid' => 0]));
        self::assertFalse(
            $access->canReadRemoteScan(['site_identifier' => 'client-a', 'page_uid' => 101]),
            'A page of another site falls back to the scan site root.'
        );
    }

    #[Test]
    public function readAccessDoesNotGrantScanOrCancelAccess(): void
    {
        $this->setUpBackendUser(4);
        $access = $this->get(ScopeAccessService::class);

        self::assertTrue($access->canReadRemoteScan(['site_identifier' => 'client-a', 'page_uid' => 0]));
        self::assertFalse($access->canEditRemoteScan(['site_identifier' => 'client-a', 'page_uid' => 0]));
        self::assertFalse($access->canEditPage(2));
    }

    #[Test]
    public function localScanStatusOutsideTheUsersPagesIsReducedToRunning(): void
    {
        $this->setUpBackendUser(2);
        $access = $this->get(ScopeAccessService::class);

        $restricted = $access->restrictLocalScanStatus(['running' => true, 'pageUid' => 101, 'triggeredBy' => 'other', 'error' => 'x']);
        $visible = $access->restrictLocalScanStatus(['running' => true, 'pageUid' => 2, 'triggeredBy' => 'me']);

        self::assertSame(['running' => true, 'restricted' => true], $restricted);
        self::assertSame('me', $visible['triggeredBy']);
    }

    #[Test]
    public function onlyCompatibleScansOfTheSameSiteAndLanguageArePaired(): void
    {
        $this->setUpBackendUser(1);
        $pairing = $this->get(RemoteScanPairingService::class);

        $pair = $pairing->resolveComparePair('client-a', self::JOB_A_NEW, self::JOB_A_OLD);
        self::assertNotNull($pair);
        self::assertSame(self::JOB_A_OLD, $pair['from']['job_id'], 'The earlier scan is the baseline.');
        self::assertSame(self::JOB_A_NEW, $pair['to']['job_id']);

        self::assertNull($pairing->resolveComparePair('client-a', self::JOB_A_OLD, self::JOB_A_DE), 'Different language.');
        self::assertNull($pairing->resolveComparePair('client-a', self::JOB_A_OLD, self::JOB_B), 'Different site.');

        $german = $this->get(\Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository::class)->findScanByJobId(self::JOB_A_DE);
        self::assertIsArray($german);
        self::assertNull($pairing->findPreviousCompatible($german), 'The German scan has no German predecessor.');

        $latest = $this->get(\Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository::class)->findScanByJobId(self::JOB_A_NEW);
        self::assertIsArray($latest);
        self::assertSame(self::JOB_A_OLD, $pairing->findPreviousCompatible($latest)['job_id'] ?? null);
    }

    private function insertGroup(ConnectionPool $pool, int $uid, string $title, string $mounts, string $tablesModify): void
    {
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => $uid,
            'pid' => 0,
            'title' => $title,
            'groupMods' => 'web,web_a11y',
            'tables_select' => 'pages,tt_content',
            'tables_modify' => $tablesModify,
            'db_mountpoints' => $mounts,
            'deleted' => 0,
            'hidden' => 0,
        ]);
    }

    private function insertUser(ConnectionPool $pool, int $uid, string $username, int $group): void
    {
        $pool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => $uid,
            'username' => $username,
            'admin' => 0,
            'usergroup' => (string)$group,
            'options' => 3,
            'workspace_perms' => 1,
            'disable' => 0,
            'deleted' => 0,
        ]);
    }

    private function insertRemoteScan(
        ConnectionPool $pool,
        int $uid,
        string $site,
        string $scope,
        int $pageUid,
        int $languageUid,
        string $jobId,
        string $startUrl,
        int $finishedAt,
    ): void {
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', [
            'uid' => $uid,
            'site_identifier' => $site,
            'job_id' => $jobId,
            'source_type' => $scope === 'page' ? 'single_page' : 'sitemap',
            'scan_scope' => $scope,
            'page_uid' => $pageUid,
            'language_uid' => $languageUid,
            'start_url' => $startUrl,
            'status' => 'completed',
            'is_free_preview' => 0,
            'finished_at' => $finishedAt,
            'persisted_at' => $finishedAt,
            'crdate' => $finishedAt,
            'tstamp' => $finishedAt,
        ]);
    }
}
