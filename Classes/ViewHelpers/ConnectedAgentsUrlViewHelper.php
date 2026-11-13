<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\ViewHelpers;

use Priebera\A11yQualityGate\Backend\ToolbarItems\ConnectedAgents\ConnectedAgentsLinkResolver;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * The URL of "My connected agents" for the module sub-navigation, or an empty string when the signed-in user may not
 * use that screen. It applies exactly the gates of the toolbar's link (ConnectedAgentsLinkResolver: route registered,
 * access to the AQG module, options.a11y_quality_gate.mcp.connect = 1 and an installation connected to the Gateway),
 * so the sub-navigation never shows a link the screen would answer with "not allowed". The screen checks again.
 * Returns the raw URL: use it through a variable (<f:variable name="url"><aqg:connectedAgentsUrl /></f:variable>), so it is
 * escaped exactly once where it is printed.
 */
final class ConnectedAgentsUrlViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function __construct(
        private readonly ConnectedAgentsLinkResolver $linkResolver,
    ) {
    }

    public function render(): string
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $this->linkResolver->resolve($user instanceof BackendUserAuthentication ? $user : null);
    }
}
