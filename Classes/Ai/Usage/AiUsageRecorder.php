<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Domain\Repository\AiConfigurationRepository;
use Priebera\A11yQualityGate\Ai\Task\AiTask;
use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiUsageRepositoryInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The one choke point every provider request passes: it enforces the site's monthly budget before the request
 * (AiBudgetService), then admits the attempt as one usage row ("pending": site, backend user, feature, provider, model,
 * conversation ref and the worst-case cost it may reach) BEFORE the provider is contacted, and settles that same row
 * afterwards with the provider-reported tokens, a safe outcome and the estimated cost. No prompt, image, answer or key is
 * ever recorded. After the row is settled, the budget warning e-mail is checked (AiBudgetAlertService, F-39); it can
 * never fail the request or the settlement.
 *
 * Admission accounting (PI-03): every count that limits requests (per-user daily caps, conversation caps) reads these
 * rows, so an admitted attempt counts from before its request, whatever happens later.
 * - The admission row cannot be written → the request is refused (fail closed, CODE_USAGE_UNAVAILABLE); nothing is sent.
 * - A caller's post-admission check ($afterAdmission, e.g. Ask AQG's caps counted again with this row) refuses → the
 *   row is withdrawn (it was never sent) and the refusal is rethrown.
 * - The settlement write fails (provider answered, timed out or failed) → the row stays "pending" with its worst-case
 *   cost (reserved_cost_micro_usd) and keeps counting; the monthly hard-stop reservation is kept as well. Nothing ever
 *   deletes a row whose request was sent. A settled row carries the actual cost and no reserved cost, so nothing is
 *   counted twice (AiBudgetRepository::spentMicroUsd() counts reserved cost only for rows without a budget reservation).
 * Recording never changes the result of an AI request that was sent.
 *
 * Cost of a row (micro-USD, the effective price valid when the request started; a later price sync never rewrites it,
 * and the row keeps where that price came from — price_origin, price_verified_at):
 * - provider reported usage → each reported category (ordinary input, cache reads, cache writes, output) at its own
 *   rate, at the prompt-length tier the request's input reached ("reported");
 * - no usage reported → the request's worst-case bound × price ("bound"), because AQG cannot prove the provider
 *   did not bill it; except for definitive rejections before processing (PRE_PROCESSING_REJECTIONS: key rejected,
 *   rate limited, model unavailable or not permitted, quota/billing, malformed request), which cost nothing ("none");
 * - no price for the model → 0 and cost_known = 0 ("unknown").
 */
final class AiUsageRecorder
{
    /** AiProviderException codes for answers a provider gives before it processes (and bills) a request. */
    public const PRE_PROCESSING_REJECTIONS = [
        1771002310, // 401 key rejected
        1771002311, // 429 rate limited
        1771002317, // 404 model unavailable
        1771002320, // insufficient quota
        1771002321, // 403 model not permitted
        1771002322, // 400/413 request rejected
        1771002323, // 402 billing problem
    ];

    /**
     * The usage this recorder computed for the last provider request of this PHP request (null: none was sent since
     * forgetLastRecord()). Read by the Page Detail AI actions to say what a click actually cost; never persisted.
     *
     * @var array{outcome:string,costMicro:int,costKnown:bool,basis:string}|null
     */
    private ?array $lastRecord = null;

    /** The attempt could not be admitted (its usage row not written): refused before anything is sent. */
    public const CODE_USAGE_UNAVAILABLE = 1771002406;
    public const OUTCOME_PENDING = 'pending';

    public function __construct(
        private readonly AiUsageRepositoryInterface $repository,
        private readonly AiUsageMeter $meter,
        private readonly BackendUserServiceInterface $backendUserService,
        private readonly ?AiBudgetService $budgetService = null,
        private readonly ?AiBudgetAlertService $budgetAlertService = null,
        private readonly ?AiConfigurationRepository $configurationRepository = null,
    ) {}

    /** Provider rejections that mean the stored key/model verification is no longer valid (M-04). */
    public const VERIFICATION_LOST_CODES = [
        1771002310, // 401 key rejected
        1771002317, // 404 model unavailable
        1771002321, // 403 model not permitted
    ];

    /**
     * The outcome describes the provider request: "success" means the provider answered and AQG accepted the answer
     * format (tokens are billed either way). A suggestion that AQG's own validators reject later stays "success".
     *
     * @template T
     * @param callable():T $request sends exactly one provider request
     * @param AiCostBound|null $bound worst case of this request; without one a deliberately large fallback is reserved
     * @param string $reference opaque id grouping requests (an "Ask AQG" conversation), never content; '' = none
     * @param (callable():void)|null $afterAdmission runs after the attempt's row is admitted and before the request; a
     *        throw refuses the attempt (the row is withdrawn, nothing is sent)
     * @return T
     * @throws \Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException budget_reached / budget_unenforceable /
     *         usage accounting unavailable (CODE_USAGE_UNAVAILABLE), before anything is sent
     */
    public function track(string $siteIdentifier, string $task, AiProviderConfiguration $configuration, callable $request, ?AiCostBound $bound = null, string $reference = '', ?callable $afterAdmission = null): mixed
    {
        $bound ??= $task === AiTask::CONNECTION_TEST
            ? AiCostBound::forConnectionTest($configuration->provider)
            : AiCostBound::fallback($configuration->provider);
        $reservation = $this->budgetService?->reserve($siteIdentifier, $task, $configuration, $bound)
            ?? new AiBudgetReservation(0, null, $bound, 0);

        $attempt = $this->admit($siteIdentifier, $task, $configuration, $reservation, $reference);
        if ($afterAdmission !== null) {
            try {
                $afterAdmission();
            } catch (\Throwable $refusal) {
                $this->withdraw($attempt, $reservation);
                throw $refusal;
            }
        }

        $this->meter->reset();
        try {
            $result = $request();
        } catch (\Throwable $exception) {
            $this->settle($attempt, $siteIdentifier, 'failed_' . $this->safeCode($exception), $reservation, $exception);
            if ($task !== AiTask::CONNECTION_TEST
                && $exception instanceof AiProviderException
                && in_array((int)$exception->getCode(), self::VERIFICATION_LOST_CODES, true)
            ) {
                try {
                    $this->configurationRepository?->markProviderRejected($siteIdentifier, $configuration->provider, 'provider_' . (int)$exception->getCode());
                } catch (\Throwable $markFailure) {
                    $this->log('ai_verification_reset_failed', $markFailure);
                }
            }
            throw $exception;
        }
        $this->settle($attempt, $siteIdentifier, 'success', $reservation, null);

        return $result;
    }

    /** Writes the attempt's pending row; without it nothing may be sent (its hold on the budget is given back). */
    private function admit(string $siteIdentifier, string $task, AiProviderConfiguration $configuration, AiBudgetReservation $reservation, string $reference): int
    {
        $known = $reservation->price !== null;
        try {
            $uid = $this->repository->admit([
                'site_identifier' => substr($siteIdentifier, 0, 255),
                'be_user' => max(0, $this->backendUserService->getBackendUserUid()),
                'feature' => AiTask::isKnown($task) ? $task : 'other',
                'provider' => substr($configuration->provider, 0, 32),
                'model' => substr($configuration->model, 0, 100),
                'outcome' => self::OUTCOME_PENDING,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cache_read_tokens' => 0,
                'cache_write_tokens' => 0,
                'usage_reported' => 0,
                'estimated_cost_micro_usd' => 0,
                'reserved_cost_micro_usd' => $known ? max(0, $reservation->estimateMicroUsd) : 0,
                'budget_reservation' => max(0, $reservation->uid),
                'cost_known' => $known ? 1 : 0,
                'cost_basis' => self::OUTCOME_PENDING,
                'price_origin' => $known ? (string)$reservation->price?->origin : '',
                'price_verified_at' => $known ? (int)$reservation->price?->verifiedAt : 0,
                'crdate' => time(),
            ] + (preg_match('/^[a-f0-9]{1,32}$/', $reference) === 1 ? ['conversation_ref' => $reference] : []));
        } catch (\Throwable $exception) {
            $uid = 0;
            $this->log('ai_usage_admission_failed', $exception);
        }
        if ($uid <= 0) {
            $this->budgetService?->release($reservation);
            throw new AiConfigurationException('The AI request could not be recorded, so it was not sent.', self::CODE_USAGE_UNAVAILABLE);
        }

        return $uid;
    }

    /** Removes an admitted attempt that was refused before its request; if that fails the row keeps counting (conservative). */
    private function withdraw(int $attempt, AiBudgetReservation $reservation): void
    {
        try {
            $this->repository->withdraw($attempt);
        } catch (\Throwable $exception) {
            $this->log('ai_usage_withdraw_failed', $exception);
        }
        // Nothing was sent: the budget hold is given back either way.
        $this->budgetService?->release($reservation);
    }

    private function settle(int $attempt, string $siteIdentifier, string $outcome, AiBudgetReservation $reservation, ?\Throwable $failure): void
    {
        $usage = $this->meter->take() ?? AiUsage::notReported();
        [$cost, $known, $basis] = self::cost($usage, $reservation, $failure);
        $this->lastRecord = ['outcome' => $outcome === 'success' ? 'success' : 'failed', 'costMicro' => $cost, 'costKnown' => $known, 'basis' => $basis];
        $settled = false;
        try {
            $settled = $this->repository->settle($attempt, [
                'outcome' => substr($outcome, 0, 64),
                'input_tokens' => $usage->inputTokens,
                'output_tokens' => $usage->outputTokens,
                'cache_read_tokens' => $usage->cacheReadTokens,
                'cache_write_tokens' => $usage->cacheWriteTotal(),
                'usage_reported' => $usage->reported ? 1 : 0,
                'estimated_cost_micro_usd' => $cost,
                'reserved_cost_micro_usd' => 0,
                'cost_known' => $known ? 1 : 0,
                'cost_basis' => $basis,
                'price_origin' => $known ? (string)$reservation->price?->origin : '',
                'price_verified_at' => $known ? (int)$reservation->price?->verifiedAt : 0,
            ]);
        } catch (\Throwable $exception) {
            $this->log('ai_usage_record_failed', $exception);
        }
        if (!$settled) {
            // The admitted row stays pending with its worst case and keeps counting; the monthly reservation stays too.
            return;
        }
        // Released only after the row is settled: in between both count, never neither. Admission reads both in ONE
        // statement (AiBudgetRepository::accounting()), so it never sees the cost before and the reservation after.
        $this->budgetService?->release($reservation);
        // F-39: the budget warning e-mail follows the recorded usage. It never throws; the guard is a second line.
        try {
            $this->budgetAlertService?->afterUsageRecorded($siteIdentifier);
        } catch (\Throwable) {
        }
    }

    private function log(string $message, \Throwable $exception): void
    {
        try {
            GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class)->warning($message, [
                'exception_class' => $exception::class,
            ]);
        } catch (\Throwable) {
            // Usage accounting must never replace the AI result.
        }
    }

    /** @return array{outcome:string,costMicro:int,costKnown:bool,basis:string}|null */
    public function lastRecord(): ?array
    {
        return $this->lastRecord;
    }

    public function forgetLastRecord(): void
    {
        $this->lastRecord = null;
    }

    /** @return array{0:int,1:bool,2:string} estimated cost (micro-USD), whether a price was known, basis */
    public static function cost(AiUsage $usage, AiBudgetReservation $reservation, ?\Throwable $failure): array
    {
        if ($reservation->price === null) {
            return [0, false, 'unknown'];
        }
        if ($usage->reported) {
            return [$reservation->price->costOfUsage($usage), true, 'reported'];
        }
        if ($failure instanceof AiProviderException && in_array((int)$failure->getCode(), self::PRE_PROCESSING_REJECTIONS, true)) {
            return [0, true, 'none'];
        }

        return [$reservation->bound->costMicro($reservation->price), true, 'bound'];
    }

    private function safeCode(\Throwable $exception): string
    {
        $code = (int)$exception->getCode();

        return $code > 0 ? (string)$code : 'error';
    }
}
