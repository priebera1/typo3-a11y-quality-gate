<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiBudgetRepositoryInterface;
use TYPO3\CMS\Core\Database\Connection;

/**
 * Concurrency: reserve() locks the site's row in tx_a11y_ai_budget_lock for the month (SELECT … FOR UPDATE on
 * MySQL/MariaDB/PostgreSQL) before it reads the accounting, so two requests of one site can never both fit into the
 * same remaining budget. SQLite has no row locks and serialises writers itself; the lock is skipped there.
 *
 * Settlement and release do not take that lock (SOL-FINAL-01). They write in a fixed order: the usage row is settled
 * first, the reservation is deleted afterwards, so at every committed instant a request's cost is counted at least once
 * (pending row + reservation, settled row + reservation, or settled row alone), never not at all. That only holds for a
 * reader that sees ONE committed instant: accounting() therefore reads the settled cost and the open reservations in a
 * single statement, which every supported database evaluates against one snapshot. Two separate SUM statements under
 * READ COMMITTED could straddle a settlement (cost read before it, reservation read after it) and admit beyond the budget.
 *
 * Isolation contract (what this class relies on and enforces):
 * - MySQL/MariaDB: READ COMMITTED, REPEATABLE READ (default) and SERIALIZABLE. Under REPEATABLE READ InnoDB normally
 *   creates the read view at the transaction's first non-locking read, which is accounting() after the lock is held.
 *   Where a snapshot can be older than the lock (MariaDB 11.6+ innodb_snapshot_isolation=ON creates it for the locking
 *   read), every admission also writes the lock row: a waiting admission whose snapshot predates the previous one's
 *   commit gets "record has changed since last read" at its FOR UPDATE and is retried (MAX_ATTEMPTS), never admitted
 *   on stale numbers. READ UNCOMMITTED is not supported.
 * - PostgreSQL: the reservation transaction is pinned to READ COMMITTED (its first statement). Under REPEATABLE READ or
 *   SERIALIZABLE the snapshot would be taken before the lock wait ends and miss a reservation committed meanwhile.
 * - SQLite: one writer at a time.
 */
final class AiBudgetRepository extends AbstractRepository implements AiBudgetRepositoryInterface
{
    public const LOCK_TABLE = 'tx_a11y_ai_budget_lock';
    public const RESERVATION_TABLE = 'tx_a11y_ai_budget_reservation';

    public function reserve(string $siteIdentifier, int $period, int $from, int $until, int $budgetMicroUsd, int $estimateMicroUsd): ?int
    {
        // Created outside the transaction: a duplicate-key error would abort a PostgreSQL transaction.
        $this->ensureLockRow($siteIdentifier, $period);
        // Only an admission that owns its transaction may be retried: inside a caller's transaction a deadlock or
        // snapshot conflict has already rolled back (or doomed) the caller's work.
        $ownsTransaction = $this->getConnection(self::RESERVATION_TABLE)->getTransactionNestingLevel() === 0;
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->reserveOnce($siteIdentifier, $period, $from, $until, $budgetMicroUsd, $estimateMicroUsd);
            } catch (\Throwable $exception) {
                // A stale snapshot is refused by the database (deadlock, serialization failure, InnoDB snapshot isolation
                // "record has changed since last read"): the whole admission is read again; anything else fails closed.
                if (!$ownsTransaction || $attempt >= self::MAX_ATTEMPTS || !self::isRetryable($exception)) {
                    throw $exception;
                }
                usleep(random_int(2_000, 20_000) * $attempt);
            }
        }
    }

    private const MAX_ATTEMPTS = 3;

    private function reserveOnce(string $siteIdentifier, int $period, int $from, int $until, int $budgetMicroUsd, int $estimateMicroUsd): ?int
    {
        $connection = $this->getConnection(self::RESERVATION_TABLE);

        return $connection->transactional(function (Connection $connection) use ($siteIdentifier, $period, $from, $until, $budgetMicroUsd, $estimateMicroUsd): ?int {
            $platform = strtolower($connection->getDatabasePlatform()::class);
            $sqlite = str_contains($platform, 'sqlite');
            if (str_contains($platform, 'postgre')) {
                if ($connection->getTransactionNestingLevel() === 1) {
                    // Must be the transaction's first statement; see the isolation contract above.
                    $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                } elseif (strtolower((string)$connection->executeQuery('SHOW transaction_isolation')->fetchOne()) !== 'read committed') {
                    // Inside a caller's transaction the level cannot be changed any more: an older snapshot could miss
                    // a reservation, so the admission fails closed (budget_unenforceable).
                    throw new \RuntimeException('The AI budget cannot be checked inside a transaction that is not READ COMMITTED.', 1771003106);
                }
            }
            $lockUid = $connection->executeQuery(
                'SELECT uid FROM ' . self::LOCK_TABLE . ' WHERE site_identifier = ? AND ' . $connection->quoteIdentifier('period') . ' = ?' . ($sqlite ? '' : ' FOR UPDATE'),
                [$siteIdentifier, $period],
                [Connection::PARAM_STR, Connection::PARAM_INT],
            )->fetchOne();
            if ($lockUid === false) {
                // Without the lock row nothing serialises this admission: fail closed.
                throw new \RuntimeException('The budget lock row is missing.', 1771003103);
            }
            if (!$sqlite) {
                // Every admission writes the lock row it holds. A later admission whose snapshot was taken before this
                // one committed (MariaDB innodb_snapshot_isolation, REPEATABLE READ/SERIALIZABLE elsewhere) is then refused
                // by the database at its locking read instead of reading stale accounting; reserve() retries it.
                $connection->executeStatement(
                    'UPDATE ' . self::LOCK_TABLE . ' SET tstamp = tstamp + 1 WHERE uid = ?',
                    [(int)$lockUid],
                    [Connection::PARAM_INT],
                );
            }

            $accounting = $this->readAccounting($connection, $siteIdentifier, $period, $from, $until);
            $committed = $accounting['spent'] + $accounting['reserved'];
            if ($committed + max(0, $estimateMicroUsd) > $budgetMicroUsd) {
                return null;
            }

            $connection->insert(self::RESERVATION_TABLE, [
                'site_identifier' => $siteIdentifier,
                'period' => $period,
                'cost_micro_usd' => max(0, $estimateMicroUsd),
                'crdate' => time(),
            ]);

            return (int)$connection->lastInsertId();
        });
    }

    private static function isRetryable(\Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof \Doctrine\DBAL\Exception\RetryableException) {
                return true;
            }
            if (!$current instanceof \Doctrine\DBAL\Exception\DriverException && !$current instanceof \Doctrine\DBAL\Driver\Exception) {
                continue;
            }
            // MariaDB ER_CHECKREAD (1020) under innodb_snapshot_isolation; serialization failure SQLSTATE 40001.
            $sqlState = method_exists($current, 'getSQLState') ? (string)$current->getSQLState() : '';
            if ((int)$current->getCode() === 1020 || $sqlState === '40001' || str_contains(strtolower($current->getMessage()), 'record has changed since last read')) {
                return true;
            }
        }

        return false;
    }

    public function release(int $reservationUid): void
    {
        if ($reservationUid > 0) {
            $this->getConnection(self::RESERVATION_TABLE)->delete(self::RESERVATION_TABLE, ['uid' => $reservationUid]);
        }
    }

    /**
     * Settled cost and open reservations of the month, read by ONE statement (one snapshot), so a settlement that
     * commits meanwhile is seen either entirely before or entirely after; see the class comment.
     *
     * @return array{spent:int,reserved:int}
     */
    public function accounting(string $siteIdentifier, int $period, int $from, int $until): array
    {
        return $this->readAccounting($this->getConnection(self::RESERVATION_TABLE), $siteIdentifier, $period, $from, $until);
    }

    /** @return array{spent:int,reserved:int} */
    private function readAccounting(Connection $connection, string $siteIdentifier, int $period, int $from, int $until): array
    {
        $q = static fn (string $identifier): string => $connection->quoteIdentifier($identifier);
        $row = $connection->executeQuery(
            'SELECT'
            . ' (SELECT COALESCE(SUM(' . $q('estimated_cost_micro_usd') . ' + CASE WHEN ' . $q('budget_reservation') . ' = 0 THEN ' . $q('reserved_cost_micro_usd') . ' ELSE 0 END), 0)'
            . ' FROM ' . $q(Tables::AI_USAGE) . ' WHERE ' . $q('site_identifier') . ' = ? AND ' . $q('crdate') . ' >= ? AND ' . $q('crdate') . ' < ?) AS spent,'
            . ' (SELECT COALESCE(SUM(' . $q('cost_micro_usd') . '), 0)'
            . ' FROM ' . $q(self::RESERVATION_TABLE) . ' WHERE ' . $q('site_identifier') . ' = ? AND ' . $q('period') . ' = ?) AS reserved',
            [$siteIdentifier, $from, $until, $siteIdentifier, $period],
            [Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT],
        )->fetchAssociative();

        return [
            'spent' => max(0, (int)($row['spent'] ?? 0)),
            'reserved' => max(0, (int)($row['reserved'] ?? 0)),
        ];
    }

    /**
     * Settled cost of the month, plus the worst case of attempts that are still pending (admitted, not settled) and hold
     * no budget reservation of their own; a pending attempt with a reservation is counted by reservedMicroUsd(), never twice.
     */
    public function spentMicroUsd(string $siteIdentifier, int $from, int $until): int
    {
        $qb = $this->getQueryBuilder(Tables::AI_USAGE);
        $sum = $qb->addSelectLiteral('SUM(' . $qb->quoteIdentifier('estimated_cost_micro_usd') . ' + CASE WHEN ' . $qb->quoteIdentifier('budget_reservation') . ' = 0 THEN ' . $qb->quoteIdentifier('reserved_cost_micro_usd') . ' ELSE 0 END) AS total')
            ->from(Tables::AI_USAGE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->gte('crdate', $qb->createNamedParameter($from, Connection::PARAM_INT)),
                $qb->expr()->lt('crdate', $qb->createNamedParameter($until, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return max(0, (int)$sum);
    }

    public function reservedMicroUsd(string $siteIdentifier, int $period): int
    {
        $qb = $this->getQueryBuilder(self::RESERVATION_TABLE);
        $sum = $qb->addSelectLiteral('SUM(' . $qb->quoteIdentifier('cost_micro_usd') . ') AS total')
            ->from(self::RESERVATION_TABLE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('period', $qb->createNamedParameter($period, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return max(0, (int)$sum);
    }

    private function ensureLockRow(string $siteIdentifier, int $period): void
    {
        $qb = $this->getQueryBuilder(self::LOCK_TABLE);
        $exists = $qb->count('uid')
            ->from(self::LOCK_TABLE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('period', $qb->createNamedParameter($period, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
        if ((int)$exists > 0) {
            return;
        }

        $connection = $this->getConnection(self::LOCK_TABLE);
        try {
            $connection->insert(self::LOCK_TABLE, ['site_identifier' => $siteIdentifier, 'period' => $period, 'tstamp' => time()]);
        } catch (UniqueConstraintViolationException) {
            // Another request created it first.
        }
        // Earlier months are closed: their lock rows and leftover reservations are no longer read.
        $connection->executeStatement('DELETE FROM ' . self::LOCK_TABLE . ' WHERE site_identifier = ? AND ' . $connection->quoteIdentifier('period') . ' < ?', [$siteIdentifier, $period], [Connection::PARAM_STR, Connection::PARAM_INT]);
        $connection->executeStatement('DELETE FROM ' . self::RESERVATION_TABLE . ' WHERE site_identifier = ? AND ' . $connection->quoteIdentifier('period') . ' < ?', [$siteIdentifier, $period], [Connection::PARAM_STR, Connection::PARAM_INT]);
    }
}
