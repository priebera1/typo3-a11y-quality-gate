<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Backend\ToolbarItems\A11yScanToolbarItem;
use Priebera\A11yQualityGate\Scan\ScanResult;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The toolbar's Content card describes the site and language of the page the backend shows. The last content scan
 * kept in the registry is the last scan of any site: shown in the context of another site, the card paired that
 * site's open issues with the other site's scan time and new/resolved counts.
 */
final class ToolbarContentContextTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        foreach ([1, 2] as $root) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $root, 'pid' => 0, 'title' => 'Root ' . $root, 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1,
                'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0,
            ]);
        }
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://main.test/');
        $this->get(SiteWriter::class)->createNewBasicSite('other', 2, 'https://other.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->setUpBackendUser(1);

        // Site "main" was scanned first; the last scan of all was a scan of site "other".
        $this->insertScan(['uid' => 10, 'site_identifier' => 'main', 'root_pid' => 1, 'language_uid' => 0, 'issues_new' => 1, 'issues_resolved' => 2]);
        $status = $this->get(ScanStatusService::class);
        $status->markRunning('backend', 'admin', null, 2, 0);
        $status->markScanRunStarted(20);
        $result = new ScanResult(20);
        $result->issuesNew = 7;
        $status->markFinished($result);
    }

    #[Test]
    public function anotherSitesLastScanIsNotReportedForThisSite(): void
    {
        $status = $this->localStatusFor(['id' => 1]);

        self::assertSame(10, $status['summary']['scanUid'] ?? null);
        self::assertSame(1, $status['summary']['issuesNew'] ?? null);
    }

    #[Test]
    public function theLastScanIsReportedForItsOwnSite(): void
    {
        $status = $this->localStatusFor(['id' => 2]);

        self::assertSame(20, $status['summary']['scanUid'] ?? null);
        self::assertSame(7, $status['summary']['issuesNew'] ?? null);
    }

    #[Test]
    public function aSiteWithoutContentScansIsNotScanned(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_scan')->delete('tx_a11y_scan', ['uid' => 10]);

        self::assertArrayNotHasKey('summary', $this->localStatusFor(['id' => 1]));
    }

    #[Test]
    public function aFreeRemotePreviewIsNotTheSitesLatestFrontendScan(): void
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        foreach ([[1, 0, 1000], [2, 1, 2000]] as [$uid, $isFreePreview, $finishedAt]) {
            $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', [
                'uid' => $uid, 'site_identifier' => 'main', 'job_id' => 'job-' . $uid, 'source_type' => 'single_page',
                'scan_scope' => 'page', 'language_uid' => 0, 'start_url' => 'https://main.test/', 'status' => 'completed',
                'is_free_preview' => $isFreePreview, 'finished_at' => $finishedAt, 'persisted_at' => $finishedAt,
            ]);
        }

        $toolbarItem = $this->toolbarItemFor(['id' => 1]);
        $scan = (new \ReflectionMethod(A11yScanToolbarItem::class, 'resolveRemoteStatus'))->invoke($toolbarItem, 'main');

        self::assertSame(1, (int)($scan['uid'] ?? 0));
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function localStatusFor(array $query): array
    {
        return (new \ReflectionMethod(A11yScanToolbarItem::class, 'resolveLocalStatus'))->invoke($this->toolbarItemFor($query));
    }

    /**
     * @param array<string, mixed> $query
     */
    private function toolbarItemFor(array $query): A11yScanToolbarItem
    {
        $toolbarItem = $this->get(A11yScanToolbarItem::class);
        $toolbarItem->setRequest(
            (new ServerRequest('https://main.test/typo3/module/web/layout'))
                ->withQueryParams($query)
                ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
        );

        return $toolbarItem;
    }
}
