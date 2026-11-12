<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems\ConnectedAgents;

use Priebera\A11yQualityGate\Mcp\Http\McpGatewayClient;
use Priebera\A11yQualityGate\Mcp\McpUserContext;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The MCP integration's answer for the toolbar's "Open MCP" link: the same two gates the "My connected agents" screen
 * (McpConsentController::myAgentsAction) applies before it lists anything — the user may connect agents
 * (options.a11y_quality_gate.mcp.connect = 1, administrators included) and this installation is connected to the AQG
 * MCP Gateway. Without either, the screen could only say "not allowed" or "not set up", so the toolbar shows no link.
 * The screen re-checks both on every request; this class only decides whether the link is shown.
 */
final class McpConnectedAgentsAvailability implements ConnectedAgentsAvailabilityInterface
{
    public function __construct(
        private readonly McpGatewayClient $gatewayClient,
    ) {
    }

    public function isAvailableFor(BackendUserAuthentication $backendUser): bool
    {
        return McpUserContext::mayConnectAgents($backendUser) && $this->gatewayClient->isConnected();
    }
}
