<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

use Priebera\A11yQualityGate\Ai\Provider\AiRequestTimeBudget;

/**
 * Work a tool call leaves for after its answer was submitted (connector side): the Gateway waits only a few seconds
 * for an answer (aqg-api mcpCallTimeoutMs(), 8 s by default, below its socket timeouts), while an AI provider request
 * can take longer. aqg_prepare_suggestions therefore answers as soon as the AI Fixes items exist, and their suggestions
 * are generated right afterwards in the same connector process, as the same TYPO3 user (McpCallRunner::run()).
 *
 * One call's work only: McpCallRunner clears it before every call and runs it only after a call that executed, so work
 * never leaks into another call, user or token. Shared (DI singleton) between the runner and the tools of one process.
 *
 * Release-gate finding 2 (AQG 3.0.0): a job runs only on a core continuation of the call that left it
 * (McpDeferredAuthorization), so the runner hands over the verified call context with begin(). Without a context —
 * an unattested execution, an unkeyed installation, a test calling the tools directly — nothing can be scheduled.
 */
final class McpDeferredWork
{
    /**
     * Jobs one call may leave behind: one provider request. The worker that ran the call stays busy meanwhile and the pool
     * replaces (kills) a worker still busy McpConnectorWorkerPool::CALL_GRACE after the call's deadline. G-07: providers'
     * own HTTP timeouts differ (Anthropic 45 s, OpenAI 30 s), so run() bounds every provider request to end before that
     * moment (AiRequestTimeBudget); a request with too little time left is not started at all (nothing is charged).
     */
    public const MAX_JOBS = 1;
    /** Deferred work starts only when the answer left at least this long before the call's deadline. */
    public const START_BEFORE_DEADLINE_SECONDS = 2.0;

    /** @var list<callable(array<string, mixed>): void> */
    private array $jobs = [];
    /** @var array<string, mixed>|null the verified call this work belongs to (McpCallRunner) */
    private ?array $callContext = null;

    /**
     * The attested call now executing: callId, attestation (raw, as begin returned it), tool, deadline (microtime) and
     * the verified claims (jti, tokenId, beUserUid, consentRefHash, clientId, scopes, sites). Clears earlier work.
     *
     * @param array<string, mixed> $callContext
     */
    public function begin(array $callContext): void
    {
        $this->jobs = [];
        $this->callContext = $callContext;
    }

    /** Whether the current call may leave work: only an attested call of a keyed installation (D14). */
    public function canSchedule(): bool
    {
        return $this->callContext !== null;
    }

    /** @param callable(array<string, mixed>): void $job receives the call context */
    public function add(callable $job): bool
    {
        if ($this->callContext === null || count($this->jobs) >= self::MAX_JOBS) {
            return false;
        }
        $this->jobs[] = $job;

        return true;
    }

    public function clear(): void
    {
        $this->jobs = [];
        $this->callContext = null;
    }

    public function count(): int
    {
        return count($this->jobs);
    }

    /**
     * Runs and forgets the jobs. A failing job never stops the connector: it is reported to the caller's handler and the
     * next job runs (each job decides itself whether to continue, e.g. after a budget refusal).
     *
     * @param callable(\Throwable): void $onError
     */
    public function run(callable $onError): void
    {
        $jobs = $this->jobs;
        $callContext = $this->callContext;
        $this->clear();
        if ($callContext === null) {
            return;
        }
        $deadline = $callContext['deadline'] ?? null;
        $killAt = (is_float($deadline) || is_int($deadline) ? (float)$deadline : microtime(true)) + McpConnectorWorkerPool::CALL_GRACE;
        foreach ($jobs as $job) {
            try {
                AiRequestTimeBudget::run($killAt, static fn () => $job($callContext));
            } catch (\Throwable $exception) {
                $onError($exception);
            }
        }
    }
}
