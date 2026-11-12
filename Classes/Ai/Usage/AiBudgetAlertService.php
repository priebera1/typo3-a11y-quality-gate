<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiBudgetAlertRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiBudgetRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiUsageRepositoryInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Budget warning e-mail (F-39). AiUsageRecorder calls afterUsageRecorded() right after a request's usage row is
 * stored — no scheduler task, no cron. The check uses the recorded estimated cost of the site's calendar month
 * (server time) from the usage rows, the same sum the budget meter and the hard stop start from (open reservations of
 * requests still running are not recorded usage and are left out).
 *
 * - Alert when the site has a budget and a warning threshold (> 0) and recorded cost × 100 ≥ budget × threshold.
 * - Recipient: AiBudgetAlertRecipient (site address, else TYPO3's [BE][warning_email_addr], else none: nothing is
 *   claimed or sent, so an address configured later in the month still gets the alert at the next settlement).
 * - Idempotency: one row per (site, month 'YYYY-MM', threshold %, budget micro-USD), claimed insert-first on its unique
 *   key, so of two concurrent settlements only one sends. A new month, a changed threshold or a changed budget is a new
 *   key: if usage is already above the new threshold, exactly one alert for it follows at the next settlement. Going
 *   back to a threshold/budget pair already alerted this month sends nothing again.
 * - Delivery: pending → sent | failed. A failed send is retried on a later settlement after 5, 10, 20, 40 minutes, at
 *   most MAX_ATTEMPTS attempts in all. A pending row whose process died is taken over after PENDING_STALE_SECONDS.
 *   A sent row is never sent again.
 * - Never throws: a failure is logged (exception class only) and never changes the AI request or its settlement.
 */
class AiBudgetAlertService
{
    public const MAX_ATTEMPTS = 5;
    public const RETRY_BASE_SECONDS = 300;
    public const PENDING_STALE_SECONDS = 3600;

    public const OUTCOME_SENT = 'sent';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_NO_BUDGET = 'no_budget';
    public const OUTCOME_NO_THRESHOLD = 'no_threshold';
    public const OUTCOME_BELOW = 'below';
    public const OUTCOME_NO_RECIPIENT = 'no_recipient';
    public const OUTCOME_ALREADY_SENT = 'already_sent';
    public const OUTCOME_CLAIMED_ELSEWHERE = 'claimed_elsewhere';
    public const OUTCOME_RETRY_LATER = 'retry_later';
    public const OUTCOME_ATTEMPTS_EXHAUSTED = 'attempts_exhausted';
    public const OUTCOME_ERROR = 'error';

    public function __construct(
        private readonly AiBudgetAlertRepositoryInterface $alertRepository,
        private readonly AiBudgetRepositoryInterface $budgetRepository,
        private readonly AiUsageRepositoryInterface $usageRepository,
        private readonly AiSiteSettingsService $siteSettingsService,
        private readonly AiBudgetAlertNotifier $notifier,
    ) {}

    /** @return string one of the OUTCOME_* codes (for tests and logs only) */
    public function afterUsageRecorded(string $siteIdentifier, ?\DateTimeImmutable $now = null): string
    {
        try {
            return $this->evaluate(trim($siteIdentifier), $now ?? new \DateTimeImmutable());
        } catch (\Throwable $exception) {
            $this->log('ai_budget_alert_check_failed', $exception);

            return self::OUTCOME_ERROR;
        }
    }

    /** Seconds to wait after attempt number $attempts failed: 5, 10, 20, 40 minutes. */
    public static function retryDelay(int $attempts): int
    {
        return self::RETRY_BASE_SECONDS * (2 ** max(0, min(10, $attempts - 1)));
    }

    private function evaluate(string $siteIdentifier, \DateTimeImmutable $now): string
    {
        if ($siteIdentifier === '') {
            return self::OUTCOME_NO_BUDGET;
        }
        $settings = $this->siteSettingsService->get($siteIdentifier);
        if (!$settings->hasBudget()) {
            return self::OUTCOME_NO_BUDGET;
        }
        $threshold = $settings->budgetWarningPercent;
        if ($threshold <= 0) {
            return self::OUTCOME_NO_THRESHOLD;
        }

        [$period, $start, $end] = AiBudgetService::period($now);
        $spent = $this->budgetRepository->spentMicroUsd($siteIdentifier, $start, $end);
        if ($spent * 100 < $settings->budgetMicroUsd * $threshold) {
            return self::OUTCOME_BELOW;
        }
        $recipient = AiBudgetAlertRecipient::resolve($settings);
        if (!$recipient->exists()) {
            return self::OUTCOME_NO_RECIPIENT;
        }

        $month = substr((string)$period, 0, 4) . '-' . substr((string)$period, 4, 2);
        $timestamp = $now->getTimestamp();
        // Look first: once an alert exists, every later request of the month would otherwise hit the unique key (an error line
        // per request on PostgreSQL). The insert-first claim still decides between concurrent first requests.
        $row = $this->alertRepository->find($siteIdentifier, $month, $threshold, $settings->budgetMicroUsd);
        $uid = $row === null ? $this->alertRepository->claim($siteIdentifier, $month, $threshold, $settings->budgetMicroUsd, $timestamp) : null;
        if ($uid === null) {
            $row ??= $this->alertRepository->find($siteIdentifier, $month, $threshold, $settings->budgetMicroUsd);
            if ($row === null) {
                return self::OUTCOME_CLAIMED_ELSEWHERE;
            }
            $decision = $this->retryDecision($row, $timestamp);
            if ($decision !== null) {
                return $decision;
            }
            if (!$this->alertRepository->reclaim($row['uid'], $row['status'], $row['attempts'], $row['last_attempt_at'], $timestamp)) {
                return self::OUTCOME_CLAIMED_ELSEWHERE;
            }
            $uid = $row['uid'];
        }

        try {
            $this->notifier->send($recipient, [
                'siteIdentifier' => $siteIdentifier,
                'month' => $month,
                'thresholdPercent' => $threshold,
                'budgetMicroUsd' => $settings->budgetMicroUsd,
                'spentMicroUsd' => $spent,
                'unknownCostRequests' => $this->unknownCostRequests($siteIdentifier, $start, $end),
                'hardStop' => $settings->budgetHardStop,
            ]);
        } catch (\Throwable $exception) {
            $this->log('ai_budget_alert_send_failed', $exception);
            $this->alertRepository->markFailed($uid, $timestamp);

            return self::OUTCOME_FAILED;
        }
        $this->alertRepository->markSent($uid, $timestamp);

        return self::OUTCOME_SENT;
    }

    /**
     * null when the existing row may be attempted again now, otherwise the outcome that stops here.
     *
     * @param array{uid:int,status:string,attempts:int,last_attempt_at:int} $row
     */
    private function retryDecision(array $row, int $now): ?string
    {
        return match (true) {
            $row['status'] === AiBudgetAlertRepositoryInterface::STATUS_SENT => self::OUTCOME_ALREADY_SENT,
            $row['attempts'] >= self::MAX_ATTEMPTS => self::OUTCOME_ATTEMPTS_EXHAUSTED,
            $row['status'] === AiBudgetAlertRepositoryInterface::STATUS_PENDING
                && $now < $row['last_attempt_at'] + self::PENDING_STALE_SECONDS => self::OUTCOME_CLAIMED_ELSEWHERE,
            $row['status'] === AiBudgetAlertRepositoryInterface::STATUS_FAILED
                && $now < $row['last_attempt_at'] + self::retryDelay($row['attempts']) => self::OUTCOME_RETRY_LATER,
            default => null,
        };
    }

    /** Requests of the month whose cost AQG could not price: never counted as a verified $0. */
    private function unknownCostRequests(string $siteIdentifier, int $start, int $end): int
    {
        $unknown = 0;
        foreach ($this->usageRepository->summarize($siteIdentifier, $start, $end) as $group) {
            $unknown += max(0, (int)($group['cost_unknown'] ?? 0));
        }

        return $unknown;
    }

    private function log(string $message, \Throwable $exception): void
    {
        try {
            GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class)->warning($message, ['exception_class' => $exception::class]);
        } catch (\Throwable) {
        }
    }
}
