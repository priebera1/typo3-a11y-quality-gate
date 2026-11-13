<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Scheduler;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Scheduler\A11yScanTask;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Product acceptance D-02: the Scheduler content scan task runs outside dependency injection and takes its services
 * from the container. The scan lock (NEW-06) fetched ScanStatusService, which was not public, so every scheduled
 * content scan failed with "service ... has been removed or inlined" and scanned nothing.
 */
final class A11yScanTaskExecutionFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1, 'deleted' => 0, 'hidden' => 0,
        ]);
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 2, 'pid' => 1, 'title' => 'Offers', 'slug' => '/offers', 'doktype' => 1, 'deleted' => 0, 'hidden' => 0,
        ]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 10, 'pid' => 2, 'CType' => 'text', 'header' => 'Offer', 'bodytext' => '<p><img src="photo.png"></p>',
            'sorting' => 256, 'sys_language_uid' => 0, 'deleted' => 0, 'hidden' => 0,
        ]);
        $pool->getConnectionForTable('tx_a11y_field_config')->insert('tx_a11y_field_config', [
            'table_name' => 'tt_content', 'field_name' => 'bodytext', 'field_type' => 'rte', 'field_label' => 'Text',
            'is_enabled' => 1, 'is_auto_detected' => 1,
        ]);

        $this->get(SiteWriter::class)->createNewBasicSite('fixture', 1, 'https://fixture.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function aScheduledSubtreeScanRunsAndStoresItsFindings(): void
    {
        $task = GeneralUtility::makeInstance(A11yScanTask::class);
        $task->rootPid = 1;
        $task->depth = 99;
        $task->languageUid = -1;
        $task->changedOnly = false;

        self::assertTrue($task->execute());

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        self::assertSame(1, (int)$pool->getConnectionForTable('tx_a11y_scan')->count('*', 'tx_a11y_scan', ['root_pid' => 1]));
        self::assertSame(1, (int)$pool->getConnectionForTable('tx_a11y_issue')->count('*', 'tx_a11y_issue', ['page_uid' => 2, 'rule_id' => 'rte.img_alt_missing']));
    }

    #[Test]
    public function aScheduledSinglePageScanRuns(): void
    {
        $task = GeneralUtility::makeInstance(A11yScanTask::class);
        $task->pageUid = 2;

        self::assertTrue($task->execute());
        self::assertSame(1, (int)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue')
            ->count('*', 'tx_a11y_issue', ['page_uid' => 2, 'rule_id' => 'rte.img_alt_missing']));
    }
}
