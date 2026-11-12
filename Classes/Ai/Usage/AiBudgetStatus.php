<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

/**
 * The monthly budget of one site this calendar month (server time), for the active provider and model.
 *
 * States: none (no budget), under, warning (at or above the warning threshold), reached (at or above the budget),
 * no_price (a budget is set but the active model has no complete automatic price, so it cannot be enforced). Only a
 * hard-stop budget blocks: reached → budget_reached; no_price → budget_unenforceable (without a complete price AQG
 * cannot bound the spending, so a hard stop holds AI instead of letting it run unbounded). With the automatic price
 * catalogue every supported model is priced; no_price remains for unknown models and incomplete pre-3.0 prices.
 * Warning-only budgets never block.
 */
final readonly class AiBudgetStatus
{
    public const STATE_NONE = 'none';
    public const STATE_UNDER = 'under';
    public const STATE_WARNING = 'warning';
    public const STATE_REACHED = 'reached';
    public const STATE_NO_PRICE = 'no_price';

    public const BLOCK_REACHED = 'budget_reached';
    public const BLOCK_UNENFORCEABLE = 'budget_unenforceable';

    public function __construct(
        public string $state,
        public int $budgetMicroUsd,
        public int $warningPercent,
        public bool $hardStop,
        public int $spentMicroUsd,
        public int $reservedMicroUsd,
        public bool $priceKnown,
        public int $periodStart,
        public int $periodEnd,
    ) {}

    public static function none(int $periodStart = 0, int $periodEnd = 0, bool $priceKnown = false): self
    {
        return new self(self::STATE_NONE, 0, 0, false, 0, 0, $priceKnown, $periodStart, $periodEnd);
    }

    public function committedMicroUsd(): int
    {
        return $this->spentMicroUsd + $this->reservedMicroUsd;
    }

    public function percent(): int
    {
        return AiMoney::percent($this->committedMicroUsd(), $this->budgetMicroUsd);
    }

    /** '' when editors may use AI, otherwise the safe code of the refusal. */
    public function blockCode(): string
    {
        if (!$this->hardStop) {
            return '';
        }

        return match ($this->state) {
            self::STATE_REACHED => self::BLOCK_REACHED,
            self::STATE_NO_PRICE => self::BLOCK_UNENFORCEABLE,
            default => '',
        };
    }

    public function isBlocking(): bool
    {
        return $this->blockCode() !== '';
    }
}
