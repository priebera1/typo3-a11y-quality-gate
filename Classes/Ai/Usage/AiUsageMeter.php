<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

use Priebera\A11yQualityGate\Mcp\McpCallScopedState;

/**
 * Carries the usage of the last provider request from the provider client to AiUsageRecorder, which knows the site,
 * user and feature. Shared service; one AI request runs at a time within a PHP request.
 */
final class AiUsageMeter implements McpCallScopedState
{
    private ?AiUsage $last = null;

    public function reset(): void
    {
        $this->last = null;
    }

    public function report(AiUsage $usage): void
    {
        $this->last = $usage;
    }

    public function take(): ?AiUsage
    {
        $usage = $this->last;
        $this->last = null;

        return $usage;
    }

    /** MCP connector: nothing remembered for one call, user or request decides the next one (McpUserContext). */
    public function resetCallState(): void
    {
        $this->last = null;
    }
}
