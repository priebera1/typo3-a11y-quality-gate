<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Task\AiTask;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiBudgetRepositoryInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Monthly AI budget per site (UX Atlas 01 E, D6). The period is the calendar month in server time. Spent = the
 * estimated cost stored on this month's usage rows (a snapshot of the automatic price at request time) plus the
 * worst-case reservations of requests still running. Prices come from AiPriceTable only — the same price the settings
 * screen shows and the request is settled at.
 *
 * Enforcement happens in AiUsageRecorder::track() before the provider request: under a per-site-month lock the
 * request's worst case (AiCostBound × price) must fit the remaining budget, otherwise it is refused with
 * budget_reached. Warning-only budgets never block, and connection tests are exempt (fixed prompt, no site content).
 */
class AiBudgetService
{
    public const CODE_BUDGET_REACHED = 1771002008;
    public const CODE_BUDGET_UNENFORCEABLE = 1771002009;

    public function __construct(
        private readonly AiBudgetRepositoryInterface $repository,
        private readonly AiPriceTable $priceTable,
        private readonly AiSiteSettingsService $siteSettingsService,
    ) {}

    public function status(string $siteIdentifier, string $provider, string $model, ?\DateTimeImmutable $now = null): AiBudgetStatus
    {
        [$period, $start, $end] = self::period($now);
        $settings = $this->siteSettingsService->get($siteIdentifier);
        $priceKnown = self::enforceable($this->priceTable->find($provider, $model));
        if (!$settings->hasBudget()) {
            return AiBudgetStatus::none($start, $end, $priceKnown);
        }

        // One snapshot (SOL-FINAL-01): two separate reads could miss a request that settles in between.
        $accounting = $this->repository->accounting($siteIdentifier, $period, $start, $end);
        $spent = max(0, (int)($accounting['spent'] ?? 0));
        $reserved = max(0, (int)($accounting['reserved'] ?? 0));
        $committed = $spent + $reserved;
        $state = match (true) {
            !$priceKnown => AiBudgetStatus::STATE_NO_PRICE,
            $committed >= $settings->budgetMicroUsd => AiBudgetStatus::STATE_REACHED,
            $settings->budgetWarningPercent > 0
                && $committed * 100 >= $settings->budgetMicroUsd * $settings->budgetWarningPercent => AiBudgetStatus::STATE_WARNING,
            default => AiBudgetStatus::STATE_UNDER,
        };

        return new AiBudgetStatus(
            $state,
            $settings->budgetMicroUsd,
            $settings->budgetWarningPercent,
            $settings->budgetHardStop,
            $spent,
            $reserved,
            $priceKnown,
            $start,
            $end,
        );
    }

    /**
     * Reserves the worst case of one provider request, or refuses it.
     *
     * @throws AiConfigurationException 1771002008 budget_reached, 1771002009 budget_unenforceable
     */
    public function reserve(string $siteIdentifier, string $task, AiProviderConfiguration $configuration, AiCostBound $bound, ?\DateTimeImmutable $now = null): AiBudgetReservation
    {
        $price = $this->priceTable->find($configuration->provider, $configuration->model);
        $estimate = $price !== null ? $bound->costMicro($price) : 0;
        $unreserved = new AiBudgetReservation(0, $price, $bound, $estimate);

        if ($task === AiTask::CONNECTION_TEST) {
            return $unreserved;
        }
        $settings = $this->siteSettingsService->get($siteIdentifier);
        if (!$settings->hasBudget() || !$settings->budgetHardStop) {
            return $unreserved;
        }
        if (!self::enforceable($price)) {
            // No price, or a pre-3.0 manual price without cache and prompt-length rates: the worst case is unbounded.
            throw new AiConfigurationException('The monthly AI budget cannot be enforced without a complete price for the model.', self::CODE_BUDGET_UNENFORCEABLE);
        }

        [$period, $start, $end] = self::period($now);
        try {
            $uid = $this->repository->reserve($siteIdentifier, $period, $start, $end, $settings->budgetMicroUsd, $estimate);
        } catch (\Throwable $exception) {
            // Fail closed: a hard-stop budget that cannot be checked must not let the request through.
            $this->log('ai_budget_reservation_failed', $exception);
            throw new AiConfigurationException('The monthly AI budget could not be checked.', self::CODE_BUDGET_UNENFORCEABLE);
        }
        if ($uid === null) {
            throw new AiConfigurationException('The monthly AI budget of this site is reached.', self::CODE_BUDGET_REACHED);
        }

        return new AiBudgetReservation($uid, $price, $bound, $estimate);
    }

    /** Whether a hard-stop budget can rely on $price: every billed category and any prompt-length tier is known. */
    public static function enforceable(?AiPrice $price): bool
    {
        return $price !== null && $price->complete;
    }

    public function release(AiBudgetReservation $reservation): void
    {
        if ($reservation->uid <= 0) {
            return;
        }
        try {
            $this->repository->release($reservation->uid);
        } catch (\Throwable $exception) {
            // The reservation stays counted for this month: conservative, never in the editor's favour.
            $this->log('ai_budget_release_failed', $exception);
        }
    }

    /**
     * Calendar month of $now in the server's time zone.
     *
     * @return array{0:int,1:int,2:int} period id (YYYYMM), start, end (exclusive)
     */
    public static function period(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $start = $now->modify('first day of this month')->setTime(0, 0);

        return [(int)$start->format('Ym'), $start->getTimestamp(), $start->modify('+1 month')->getTimestamp()];
    }

    private function log(string $message, \Throwable $exception): void
    {
        try {
            GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class)->warning($message, ['exception_class' => $exception::class]);
        } catch (\Throwable) {
        }
    }
}
