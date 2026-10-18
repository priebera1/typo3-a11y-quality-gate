<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * One DataHandler save of several content elements (an import, a copied page tree) runs one page scan per page in
 * the same second. The Overview then named a different "last scan" than the toolbar and the notification, because
 * only the second-resolution timestamp decided which scan was the latest.
 */
final class ScanRecencyRepositoryTest extends AbstractFunctionalTestCase
{
    private ScanRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_scan');
        foreach ([[2, 'page'], [3, 'page'], [1, 'subtree'], [4, 'subtree'], [5, 'page']] as [$rootPid, $scope]) {
            $connection->insert('tx_a11y_scan', [
                'site_identifier' => 'main',
                'root_pid' => $rootPid,
                'language_uid' => 0,
                'scope' => $scope,
                'status' => 2,
                'started_at' => 1_790_000_000,
                'finished_at' => 1_790_000_001,
            ]);
        }

        $this->subject = new ScanRepository(GeneralUtility::makeInstance(ConnectionPool::class));
    }

    #[Test]
    public function theLastCreatedOfScansFinishedInTheSameSecondIsTheLatest(): void
    {
        self::assertSame(5, (int)$this->subject->findLastCompletedScan('main', 0)['root_pid']);
        self::assertSame(5, (int)$this->subject->findLastCompletedLocalScan('main', 0)['root_pid']);
        self::assertSame(4, (int)$this->subject->findLastCompletedSubtreeScan('main', 0)['root_pid']);
    }
}
