<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

/**
 * A DI singleton that keeps per-request state (memos of permission, AI readiness, budget, prices, records…).
 *
 * The MCP connector is one long-running process that runs calls of many TYPO3 users one after another, so such state
 * must never outlive one call (release-gate finding 1). Every implementation is tagged `a11y_quality_gate.mcp_call_scoped`
 * (Configuration/Services.yaml, _instanceof) and McpUserContext::runAs() calls resetCallState() on all of them before and
 * after every agent call, deferred work included.
 *
 * Tests/Unit/Mcp/McpCallScopedStateGuardTest fails when a service an MCP call can reach writes instance state outside
 * its constructor without implementing this interface (or being listed there as deliberately process-wide).
 */
interface McpCallScopedState
{
    public const TAG = 'a11y_quality_gate.mcp_call_scoped';

    /** Forgets every value remembered for the current request, user or call. */
    public function resetCallState(): void;
}
