<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems\ConnectedAgents;

use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The toolbar footer's "AI agents / MCP" link to the existing connected-agent screen (route `web_a11y.mcpMyAgents`
 * of the MCP integration). It is rendered only when all of these hold, so there is never a working-looking link to a
 * missing or unusable screen:
 * - the route is registered (it is not in builds without the MCP integration);
 * - the user has access to the AQG module the route belongs to;
 * - ConnectedAgentsAvailabilityInterface allows it (default: never; the MCP integration provides the real check).
 * The screen checks its own gates again on every request; this class only decides whether to show the link.
 */
final class ConnectedAgentsLinkResolver
{
    public const ROUTE = 'web_a11y.mcpMyAgents';

    public function __construct(
        private readonly Router $router,
        private readonly UriBuilder $uriBuilder,
        private readonly ConnectedAgentsAvailabilityInterface $availability,
    ) {
    }

    public function resolve(?BackendUserAuthentication $backendUser): string
    {
        if (!$backendUser instanceof BackendUserAuthentication || !$this->router->hasRoute(self::ROUTE)) {
            return '';
        }
        if (!$backendUser->isAdmin() && !$backendUser->check('modules', 'web_a11y')) {
            return '';
        }
        try {
            if (!$this->availability->isAvailableFor($backendUser)) {
                return '';
            }

            return (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE);
        } catch (\Throwable) {
            return '';
        }
    }
}
