<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiBudgetAlertRepositoryInterface;
use TYPO3\CMS\Core\Database\Connection;

/**
 * Budget warning e-mail claims (F-39). claim() inserts first and relies on the UNIQUE KEY alert_key; it runs outside
 * any transaction, so a duplicate-key error never aborts a surrounding PostgreSQL transaction.
 */
final class AiBudgetAlertRepository extends AbstractRepository implements AiBudgetAlertRepositoryInterface
{
    public const TABLE = 'tx_a11y_ai_budget_alert';

    public function claim(string $siteIdentifier, string $month, int $thresholdPercent, int $budgetMicroUsd, int $now): ?int
    {
        $connection = $this->getConnection(self::TABLE);
        try {
            $connection->insert(self::TABLE, [
                'pid' => 0,
                'site_identifier' => $siteIdentifier,
                'alert_month' => $month,
                'threshold_percent' => $thresholdPercent,
                'budget_micro_usd' => $budgetMicroUsd,
                'status' => self::STATUS_PENDING,
                'attempts' => 1,
                'last_attempt_at' => $now,
                'sent_at' => 0,
                'crdate' => $now,
                'tstamp' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return (int)$connection->lastInsertId();
    }

    public function find(string $siteIdentifier, string $month, int $thresholdPercent, int $budgetMicroUsd): ?array
    {
        $qb = $this->getQueryBuilder(self::TABLE);
        $row = $qb->select('uid', 'status', 'attempts', 'last_attempt_at')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('alert_month', $qb->createNamedParameter($month)),
                $qb->expr()->eq('threshold_percent', $qb->createNamedParameter($thresholdPercent, Connection::PARAM_INT)),
                $qb->expr()->eq('budget_micro_usd', $qb->createNamedParameter($budgetMicroUsd, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($row)) {
            return null;
        }

        return [
            'uid' => (int)$row['uid'],
            'status' => (string)$row['status'],
            'attempts' => (int)$row['attempts'],
            'last_attempt_at' => (int)$row['last_attempt_at'],
        ];
    }

    public function reclaim(int $uid, string $expectedStatus, int $expectedAttempts, int $expectedLastAttemptAt, int $now): bool
    {
        $affected = $this->getConnection(self::TABLE)->update(
            self::TABLE,
            ['status' => self::STATUS_PENDING, 'attempts' => $expectedAttempts + 1, 'last_attempt_at' => $now, 'tstamp' => $now],
            ['uid' => $uid, 'status' => $expectedStatus, 'attempts' => $expectedAttempts, 'last_attempt_at' => $expectedLastAttemptAt],
        );

        return $affected === 1;
    }

    public function markSent(int $uid, int $now): void
    {
        $this->getConnection(self::TABLE)->update(self::TABLE, ['status' => self::STATUS_SENT, 'sent_at' => $now, 'tstamp' => $now], ['uid' => $uid]);
    }

    public function markFailed(int $uid, int $now): void
    {
        // Never downgrades a row another process already marked sent.
        $this->getConnection(self::TABLE)->update(self::TABLE, ['status' => self::STATUS_FAILED, 'tstamp' => $now], ['uid' => $uid, 'status' => self::STATUS_PENDING]);
    }
}
