<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository\Contract;

/**
 * Budget warning e-mail claims (F-39). One row per (site, month 'YYYY-MM', threshold percent, budget micro-USD); the
 * unique key decides which concurrent settlement may send. A row's status is pending (claimed, delivery in progress),
 * sent or failed.
 */
interface AiBudgetAlertRepositoryInterface
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /**
     * Inserts the alert row as pending with its first attempt. Insert-first: the unique key lets exactly one caller win.
     *
     * @return int|null the new row's uid, or null when the alert already exists (another settlement claimed it)
     */
    public function claim(string $siteIdentifier, string $month, int $thresholdPercent, int $budgetMicroUsd, int $now): ?int;

    /**
     * @return array{uid:int,status:string,attempts:int,last_attempt_at:int}|null
     */
    public function find(string $siteIdentifier, string $month, int $thresholdPercent, int $budgetMicroUsd): ?array;

    /**
     * Claims one more attempt of an existing row, only while it is still in the state the caller read
     * ($expectedStatus, $expectedAttempts, $expectedLastAttemptAt): a conditional update, so of two concurrent
     * retries only one succeeds.
     */
    public function reclaim(int $uid, string $expectedStatus, int $expectedAttempts, int $expectedLastAttemptAt, int $now): bool;

    public function markSent(int $uid, int $now): void;

    public function markFailed(int $uid, int $now): void;
}
