<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository\Contract;

/**
 * Monthly AI budget accounting per site: the estimated cost already recorded in usage rows plus the open worst-case
 * reservations of requests that are still running.
 */
interface AiBudgetRepositoryInterface
{
    /**
     * Atomically checks and reserves: under a per-site-month lock, the recorded cost of [$from, $until) plus all open
     * reservations of $period plus $estimateMicroUsd must not exceed $budgetMicroUsd.
     *
     * @return int|null the reservation uid, or null when the request does not fit the budget
     */
    public function reserve(string $siteIdentifier, int $period, int $from, int $until, int $budgetMicroUsd, int $estimateMicroUsd): ?int;

    /**
     * spentMicroUsd() and reservedMicroUsd() of the same month read together by one statement, i.e. from one committed
     * snapshot. Anything that adds the two (admission, budget status) uses this, never two separate reads.
     *
     * @return array{spent:int,reserved:int}
     */
    public function accounting(string $siteIdentifier, int $period, int $from, int $until): array;

    /** Removes a reservation once its request's actual cost is recorded. */
    public function release(int $reservationUid): void;

    /** Estimated cost recorded in usage rows of [$from, $until), in micro-USD. */
    public function spentMicroUsd(string $siteIdentifier, int $from, int $until): int;

    /** Open reservations of $period, in micro-USD. A crashed request's reservation stays counted for its month. */
    public function reservedMicroUsd(string $siteIdentifier, int $period): int;
}
