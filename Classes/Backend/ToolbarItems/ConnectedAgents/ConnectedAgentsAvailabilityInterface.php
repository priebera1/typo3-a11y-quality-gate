<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems\ConnectedAgents;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Whether the connected-agent (MCP) screen is usable for a user. Implemented by McpConnectedAgentsAvailability, which
 * applies the screen's own gates (per-user `options.a11y_quality_gate.mcp.connect`, a connected MCP Gateway).
 */
interface ConnectedAgentsAvailabilityInterface
{
    public function isAvailableFor(BackendUserAuthentication $backendUser): bool;
}
