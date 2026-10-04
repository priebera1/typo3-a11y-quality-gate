<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A site scan checks every page below the page it started on. The Page module counts it as that page's content scan,
 * so a page without content records no longer shows its last single-page scan as its latest.
 */
final class ScanCoverageRepositoryTest extends AbstractFunctionalTestCase
{
    private ScanRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        foreach ([[1, 0], [2, 1], [3, 2], [4, 1]] as [$uid, $pid]) {
            $pool->getConnectionForTable('pages')->insert('pages', ['uid' => $uid, 'pid' => $pid, 'title' => 'Page ' . $uid, 'doktype' => 1]);
        }

        foreach ([
            // site, root page, language, scope, status, finished
            ['main', 1, 0, 'subtree', 2, 1_790_001_000],
            ['main', 2, 1, 'subtree', 2, 1_790_002_000],
            ['main', 4, 0, 'subtree', 2, 1_790_003_000],
            ['main', 2, 0, 'subtree', 4, 1_790_004_000],
            ['main', 3, 0, 'page', 2, 1_790_005_000],
            ['other', 1, 0, 'subtree', 2, 1_790_006_000],
        ] as [$site, $rootPid, $languageUid, $scope, $status, $finishedAt]) {
            $pool->getConnectionForTable('tx_a11y_scan')->insert('tx_a11y_scan', [
                'site_identifier' => $site,
                'root_pid' => $rootPid,
                'language_uid' => $languageUid,
                'scope' => $scope,
                'status' => $status,
                'started_at' => $finishedAt - 10,
                'finished_at' => $finishedAt,
            ]);
        }

        $this->subject = new ScanRepository($pool);
    }

    #[Test]
    public function aCompletedSiteScanOfAnAncestorCoversThePage(): void
    {
        self::assertSame(1_790_001_000, (int)$this->subject->findLastCompletedSubtreeScanCoveringPage('main', 3, 0)['finished_at']);
    }

    #[Test]
    public function onlyScansOfThePagesLanguageSiteAndBranchCount(): void
    {
        self::assertSame(1_790_002_000, (int)$this->subject->findLastCompletedSubtreeScanCoveringPage('main', 3, 1)['finished_at']);
        self::assertSame(1_790_003_000, (int)$this->subject->findLastCompletedSubtreeScanCoveringPage('main', 4, 0)['finished_at']);
        self::assertNull($this->subject->findLastCompletedSubtreeScanCoveringPage('third', 3, 0));
    }

    #[Test]
    public function aPageWithoutScansAboveItHasNone(): void
    {
        self::assertNull($this->subject->findLastCompletedSubtreeScanCoveringPage('main', 999, 0));
        self::assertNull($this->subject->findLastCompletedSubtreeScanCoveringPage('main', 0, 0));
    }
}
