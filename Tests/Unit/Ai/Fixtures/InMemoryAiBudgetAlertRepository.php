<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures;

use Priebera\A11yQualityGate\Domain\Repository\Contract\AiBudgetAlertRepositoryInterface;

/** The alert table with its unique key (site, month, threshold, budget) and conditional reclaim, in memory. */
final class InMemoryAiBudgetAlertRepository implements AiBudgetAlertRepositoryInterface
{
    /** @var array<int,array{uid:int,key:string,status:string,attempts:int,last_attempt_at:int,sent_at:int}> */
    public array $rows = [];
    public bool $failClaims = false;
    private int $nextUid = 1;

    public function claim(string $siteIdentifier, string $month, int $thresholdPercent, int $budgetMicroUsd, int $now): ?int
    {
        if ($this->failClaims) {
            throw new \RuntimeException('database unavailable');
        }
        $key = self::key($siteIdentifier, $month, $thresholdPercent, $budgetMicroUsd);
        foreach ($this->rows as $row) {
            if ($row['key'] === $key) {
                return null;
            }
        }
        $uid = $this->nextUid++;
        $this->rows[$uid] = ['uid' => $uid, 'key' => $key, 'status' => self::STATUS_PENDING, 'attempts' => 1, 'last_attempt_at' => $now, 'sent_at' => 0];

        return $uid;
    }

    public function find(string $siteIdentifier, string $month, int $thresholdPercent, int $budgetMicroUsd): ?array
    {
        $key = self::key($siteIdentifier, $month, $thresholdPercent, $budgetMicroUsd);
        foreach ($this->rows as $row) {
            if ($row['key'] === $key) {
                return ['uid' => $row['uid'], 'status' => $row['status'], 'attempts' => $row['attempts'], 'last_attempt_at' => $row['last_attempt_at']];
            }
        }

        return null;
    }

    public function reclaim(int $uid, string $expectedStatus, int $expectedAttempts, int $expectedLastAttemptAt, int $now): bool
    {
        $row = $this->rows[$uid] ?? null;
        if ($row === null || $row['status'] !== $expectedStatus || $row['attempts'] !== $expectedAttempts || $row['last_attempt_at'] !== $expectedLastAttemptAt) {
            return false;
        }
        $this->rows[$uid] = ['status' => self::STATUS_PENDING, 'attempts' => $expectedAttempts + 1, 'last_attempt_at' => $now] + $row;

        return true;
    }

    public function markSent(int $uid, int $now): void
    {
        $this->rows[$uid] = ['status' => self::STATUS_SENT, 'sent_at' => $now] + $this->rows[$uid];
    }

    public function markFailed(int $uid, int $now): void
    {
        if ($this->rows[$uid]['status'] === self::STATUS_PENDING) {
            $this->rows[$uid]['status'] = self::STATUS_FAILED;
        }
    }

    /** @return array{uid:int,key:string,status:string,attempts:int,last_attempt_at:int,sent_at:int}|null */
    public function row(string $siteIdentifier, string $month, int $thresholdPercent, int $budgetMicroUsd): ?array
    {
        $key = self::key($siteIdentifier, $month, $thresholdPercent, $budgetMicroUsd);
        foreach ($this->rows as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        return null;
    }

    private static function key(string $siteIdentifier, string $month, int $thresholdPercent, int $budgetMicroUsd): string
    {
        return $siteIdentifier . '|' . $month . '|' . $thresholdPercent . '|' . $budgetMicroUsd;
    }
}
