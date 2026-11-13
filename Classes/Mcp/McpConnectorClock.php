<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

/**
 * Time for the connector's run loop (max runtime, retry backoff), so its lifecycle is testable without waiting.
 */
class McpConnectorClock
{
    public function now(): float
    {
        return microtime(true);
    }

    /**
     * Sleeps up to the given seconds; a signal (SIGTERM, SIGINT with async signals) ends it early.
     */
    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int)round($seconds * 1_000_000));
        }
    }
}
