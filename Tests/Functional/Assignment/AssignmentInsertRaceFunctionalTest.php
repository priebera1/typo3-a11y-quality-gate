<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Assignment;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Assignment\AssignmentRepository;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * NEW-08 (resilience audit): two managers assigning the same work unit for the first time at the same instant made
 * the loser's INSERT hit UNIQUE issue_on_page, which surfaced as an uncaught HTTP 500 (3 of 30 barrier trials).
 *
 * The lost race is reproduced deterministically: a row that collides on the unique index (its issue_key shares the
 * indexed 100-character prefix) but that save()'s exact lookup does not find — exactly what the loser sees when the
 * winner's row appears between its lookup and its insert.
 */
final class AssignmentInsertRaceFunctionalTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function aFirstAssignmentThatLosesTheInsertRaceIsAConflictNotAnError(): void
    {
        $prefix = str_repeat('k', 100);
        $this->connection()->insert('tx_a11y_assignment', ['site_identifier' => 'main', 'page_uid' => 7, 'language_uid' => 0, 'issue_key' => $prefix . 'winner', 'assignee' => 11, 'assigned_by' => 2]);

        $saved = $this->repository()->save('main', 7, 0, $prefix . 'loser', 12, 3, 'race', null);

        self::assertFalse($saved, 'The losing manager gets a conflict (HTTP 409 assignment_conflict).');
        self::assertSame(1, (int)$this->connection()->count('*', 'tx_a11y_assignment', []));
        self::assertSame(11, (int)$this->connection()->select(['assignee'], 'tx_a11y_assignment', [])->fetchOne(), 'The winner keeps the assignment.');
    }

    #[Test]
    public function aRequestWithoutExpectedAssigneeCannotOverwriteAChangeItDidNotSee(): void
    {
        $repository = $this->repository();
        self::assertTrue($repository->save('main', 7, 0, 'rte.empty_link', 11, 2, '', null));
        // An API client without expectedAssignee reassigns sequentially: allowed (it read the current assignee).
        self::assertTrue($repository->save('main', 7, 0, 'rte.empty_link', 12, 2, '', null));
        self::assertSame(12, (int)$this->connection()->select(['assignee'], 'tx_a11y_assignment', [])->fetchOne());
        // A stale expectation (the UI saw 11, another manager made it 12 meanwhile) is refused.
        self::assertFalse($repository->save('main', 7, 0, 'rte.empty_link', 13, 3, '', 11));
        self::assertSame(12, (int)$this->connection()->select(['assignee'], 'tx_a11y_assignment', [])->fetchOne());
    }

    /**
     * Review P2-6: another manager reassigns the task between this request's read and its write. Without an
     * expectedAssignee the write is still a compare-and-set against what this request read: a conflict, not an overwrite.
     * (Before NEW-08 the update had no condition and replaced the other manager's assignee.)
     */
    #[Test]
    public function aConcurrentReassignmentBetweenReadAndWriteIsAConflictWithoutExpectedAssignee(): void
    {
        self::assertTrue($this->repository()->save('main', 7, 0, 'rte.empty_link', 11, 2, '', null));
        $connection = $this->connection();
        $racing = new class (GeneralUtility::makeInstance(ConnectionPool::class), $connection) extends AssignmentRepository {
            public function __construct(ConnectionPool $pool, private readonly \TYPO3\CMS\Core\Database\Connection $other)
            {
                parent::__construct($pool);
            }

            public function find(string $siteIdentifier, int $pageUid, int $languageUid, string $issueKey): ?array
            {
                $row = parent::find($siteIdentifier, $pageUid, $languageUid, $issueKey);
                // The other manager's write lands right after this request read the row.
                $this->other->update('tx_a11y_assignment', ['assignee' => 99], ['page_uid' => $pageUid]);

                return $row;
            }
        };

        self::assertFalse($racing->save('main', 7, 0, 'rte.empty_link', 12, 3, '', null));
        self::assertSame(99, (int)$connection->select(['assignee'], 'tx_a11y_assignment', [])->fetchOne(), 'the other manager\'s decision stays');
    }

    private function repository(): AssignmentRepository
    {
        return new AssignmentRepository(GeneralUtility::makeInstance(ConnectionPool::class));
    }

    private function connection(): \TYPO3\CMS\Core\Database\Connection
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_assignment');
    }
}
