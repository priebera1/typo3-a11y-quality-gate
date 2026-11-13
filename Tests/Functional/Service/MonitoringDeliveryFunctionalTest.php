<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\MonitoringRunRepository;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * NEW-10 (resilience audit): a run whose regression mail failed (SMTP down) stayed delivery_pending = 1 for good, even
 * after a later run delivered the same state. Now the delivering run supersedes exactly those earlier runs.
 */
final class MonitoringDeliveryFunctionalTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function aLaterDeliveryOfTheSameStateSupersedesOnlyThatStatesPendingRuns(): void
    {
        $sameState = $this->insertRun('main', 0, 'regression:abc', 1);
        $otherState = $this->insertRun('main', 0, 'regression:other', 1);
        $otherSite = $this->insertRun('second', 0, 'regression:abc', 1);
        $otherLanguage = $this->insertRun('main', 1, 'regression:abc', 1);
        $delivering = $this->insertRun('main', 0, 'regression:abc', 0);
        $later = $this->insertRun('main', 0, 'regression:abc', 1);

        $count = (new MonitoringRunRepository(GeneralUtility::makeInstance(ConnectionPool::class)))
            ->supersedePendingDeliveries('main', 0, $delivering, 'regression:abc');

        self::assertSame(1, $count);
        self::assertSame([0, $delivering], $this->delivery($sameState));
        self::assertSame([1, 0], $this->delivery($otherState), 'A never-delivered different state stays pending.');
        self::assertSame([1, 0], $this->delivery($otherSite));
        self::assertSame([1, 0], $this->delivery($otherLanguage));
        self::assertSame([1, 0], $this->delivery($later), 'Only earlier runs are superseded.');
    }

    private function insertRun(string $site, int $language, string $fingerprint, int $pending): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_monitoring_run');
        $connection->insert('tx_a11y_monitoring_run', [
            'site_identifier' => $site, 'language_uid' => $language, 'status' => 'evaluated', 'outcome' => 'regression',
            'state_fingerprint' => $fingerprint, 'coverage_complete' => 1, 'delivery_pending' => $pending,
            'delivery_error' => $pending === 1 ? 'mail_not_sent' : '', 'delivery_attempts' => 1,
        ]);

        return (int)$connection->lastInsertId();
    }

    /** @return array{0:int,1:int} */
    private function delivery(int $uid): array
    {
        $row = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_monitoring_run')
            ->select(['delivery_pending', 'delivery_superseded_by'], 'tx_a11y_monitoring_run', ['uid' => $uid])->fetchAssociative();

        return [(int)$row['delivery_pending'], (int)$row['delivery_superseded_by']];
    }
}
