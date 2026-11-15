<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Provider;

/**
 * G-07 (AQG 3.0.0 security gate): an upper bound on the time an AI provider request may take, for work that a
 * supervisor ends at a fixed moment. MCP deferred suggestions run in a connector worker that the pool replaces
 * (SIGKILL) at the call's deadline + McpConnectorWorkerPool::CALL_GRACE; a provider request with a longer HTTP timeout
 * (Anthropic 45 s) could be killed mid-request: paid, its result lost, the item claimed until the claim expires, and paid
 * again by the next status call.
 *
 * Inside run(), providers use timeoutSeconds() for their HTTP timeout: the smaller of their own timeout and the time
 * left, minus a margin for recording the result. When less than MIN_REQUEST_SECONDS is left, no request is started at
 * all (nothing is sent, nothing is charged); the caller's ordinary provider-failure path applies. Outside run() nothing
 * changes. One budget per process at a time (the connector worker runs one call's work).
 */
final class AiRequestTimeBudget
{
    /** Below this the request is not started: shorter than any realistic generation. */
    public const MIN_REQUEST_SECONDS = 5;
    /** Left after the request for storing the suggestion and settling usage before the supervisor's moment. */
    public const SETTLE_MARGIN_SECONDS = 5.0;

    private static ?float $until = null;

    /**
     * Runs `$work` with provider requests bounded to end before `$until` (microtime) minus the settle margin.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function run(float $until, callable $work): mixed
    {
        $previous = self::$until;
        self::$until = $previous === null ? $until : min($previous, $until);
        try {
            return $work();
        } finally {
            self::$until = $previous;
        }
    }

    /**
     * The HTTP timeout to use: `$default` without a budget; otherwise the whole seconds left (≤ `$default`), or null when
     * fewer than MIN_REQUEST_SECONDS are left — then the request must not be started.
     */
    public static function timeoutSeconds(int $default, ?float $now = null): ?int
    {
        if (self::$until === null) {
            return $default;
        }
        $left = (int)floor(self::$until - self::SETTLE_MARGIN_SECONDS - ($now ?? microtime(true)));

        return $left < self::MIN_REQUEST_SECONDS ? null : min($default, $left);
    }

    public static function isActive(): bool
    {
        return self::$until !== null;
    }
}
