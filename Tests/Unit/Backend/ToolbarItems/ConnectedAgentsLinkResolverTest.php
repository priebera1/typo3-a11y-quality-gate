<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Backend\ToolbarItems;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Backend\ToolbarItems\ConnectedAgents\ConnectedAgentsAvailabilityInterface;
use Priebera\A11yQualityGate\Backend\ToolbarItems\ConnectedAgents\ConnectedAgentsLinkResolver;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\Uri;

/**
 * The footer's "AI agents / MCP" link appears only for an existing route, a user with module access and an MCP
 * integration that says the screen is usable for that user. Never a working-looking link to a missing screen.
 */
final class ConnectedAgentsLinkResolverTest extends TestCase
{
    private function resolver(bool $routeExists, bool $available): ConnectedAgentsLinkResolver
    {
        $router = $this->createMock(Router::class);
        $router->method('hasRoute')->willReturnCallback(static fn (string $route): bool => $routeExists && $route === ConnectedAgentsLinkResolver::ROUTE);
        $uris = $this->createMock(UriBuilder::class);
        $uris->method('buildUriFromRoute')->willReturn(new Uri('/typo3/module/content/a11y/mcp/my-agents?token=t'));
        $availability = $this->createMock(ConnectedAgentsAvailabilityInterface::class);
        $availability->method('isAvailableFor')->willReturn($available);

        return new ConnectedAgentsLinkResolver($router, $uris, $availability);
    }

    private function user(bool $admin, bool $moduleAccess): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn($admin);
        $user->method('check')->willReturn($moduleAccess);

        return $user;
    }

    #[Test]
    public function withoutTheMcpRouteThereIsNoLink(): void
    {
        self::assertSame('', $this->resolver(false, true)->resolve($this->user(true, true)));
    }

    #[Test]
    public function theRouteAloneIsNotEnough(): void
    {
        self::assertSame('', $this->resolver(true, false)->resolve($this->user(true, true)), 'the MCP integration says the screen is not usable');
        self::assertSame('', $this->resolver(true, true)->resolve($this->user(false, false)), 'no module access');
        self::assertSame('', $this->resolver(true, true)->resolve(null));
    }

    #[Test]
    public function anAuthorisedUserGetsTheExistingScreen(): void
    {
        self::assertSame('/typo3/module/content/a11y/mcp/my-agents?token=t', $this->resolver(true, true)->resolve($this->user(false, true)));
    }
}
