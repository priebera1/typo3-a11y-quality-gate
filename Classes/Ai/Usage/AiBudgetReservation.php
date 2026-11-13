<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

/**
 * The budget hold of one running provider request. $uid is 0 when nothing was reserved (no hard-stop budget, or an
 * exempt connection test); $price is the price valid when the request started, used again for its actual cost.
 */
final readonly class AiBudgetReservation
{
    public function __construct(
        public int $uid,
        public ?AiPrice $price,
        public AiCostBound $bound,
        public int $estimateMicroUsd,
    ) {}
}
