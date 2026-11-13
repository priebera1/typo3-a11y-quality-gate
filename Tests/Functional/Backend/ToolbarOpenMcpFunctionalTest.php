<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Backend\ToolbarItems\ConnectedAgents\ConnectedAgentsAvailabilityInterface;
use Priebera\A11yQualityGate\Backend\ToolbarItems\ConnectedAgents\ConnectedAgentsLinkResolver;
use Priebera\A11yQualityGate\Backend\ToolbarItems\ConnectedAgents\McpConnectedAgentsAvailability;
use Priebera\A11yQualityGate\Mcp\McpConnectionStore;
use Priebera\A11yQualityGate\Mcp\McpHostKey;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * The toolbar footer's "Open MCP" opens the MCP integration's existing "My connected agents" screen
 * (web_a11y.mcpMyAgents) and is shown only when that screen could list anything for the user: the user may connect
 * agents (options.a11y_quality_gate.mcp.connect = 1, administrators too), has the Accessibility module, and this
 * installation is connected to the AQG MCP Gateway. No request leaves the process.
 */
final class ToolbarOpenMcpFunctionalTest extends AbstractFunctionalTestCase
{
    private const SECRET = 'aqgc_' . 'A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8S9t0U1v';
    private const PAIRING = '11111111-2222-4333-8444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        putenv(McpHostKey::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
        $pool = $this->getConnectionPool();
        $groups = $pool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', ['uid' => 10, 'title' => 'Editors', 'groupMods' => 'web_a11y', 'TSconfig' => '']);
        $groups->insert('be_groups', ['uid' => 11, 'title' => 'Agent editors', 'groupMods' => 'web_a11y', 'TSconfig' => "options.a11y_quality_gate.mcp.connect = 1\n"]);
        $groups->insert('be_groups', ['uid' => 12, 'title' => 'Agents without module', 'groupMods' => '', 'TSconfig' => "options.a11y_quality_gate.mcp.connect = 1\n"]);
        $users = $pool->getConnectionForTable('be_users');
        $users->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $users->insert('be_users', ['uid' => 2, 'username' => 'editor', 'admin' => 0, 'usergroup' => '10', 'disable' => 0, 'deleted' => 0]);
        $users->insert('be_users', ['uid' => 3, 'username' => 'agenteditor', 'admin' => 0, 'usergroup' => '11', 'disable' => 0, 'deleted' => 0]);
        $users->insert('be_users', ['uid' => 4, 'username' => 'nomodule', 'admin' => 0, 'usergroup' => '12', 'disable' => 0, 'deleted' => 0]);
        $request = (new ServerRequest('https://a.test/typo3/'))->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $GLOBALS['TYPO3_REQUEST'] = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        @unlink($this->get(McpHostKey::class)->path());
        putenv(McpHostKey::ENVIRONMENT_VARIABLE);
        parent::tearDown();
    }

    private function connect(): void
    {
        $store = $this->get(McpConnectionStore::class);
        self::assertTrue($store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin'));
        self::assertTrue($store->completePairingIfCurrent(self::PAIRING, self::SECRET, 'slug', 'https://gw.example/mcp/i/slug'));
        self::assertTrue($store->markActivatedIfCurrent($store->get()['credentialId']));
    }

    private function linkFor(int $uid): string
    {
        $user = $this->setUpBackendUser($uid);

        return $this->get(ConnectedAgentsLinkResolver::class)->resolve($user);
    }

    #[Test]
    public function theMcpIntegrationDecidesAvailability(): void
    {
        self::assertInstanceOf(McpConnectedAgentsAvailability::class, $this->get(ConnectedAgentsAvailabilityInterface::class));
    }

    #[Test]
    public function withoutAGatewayConnectionNobodyGetsTheLink(): void
    {
        self::assertSame('', $this->linkFor(3), 'allowed to connect, but the installation is not connected');
        self::assertSame('', $this->linkFor(1));
    }

    #[Test]
    public function aConnectedInstallationLinksOnlyUsersWhoMayConnectAgents(): void
    {
        $this->connect();

        self::assertStringContainsString('/mcp/my-agents', $this->linkFor(3), 'the real "My connected agents" route');
        self::assertSame('', $this->linkFor(2), 'no options.a11y_quality_gate.mcp.connect');
        self::assertSame('', $this->linkFor(1), 'administrators need the TSconfig option explicitly, as on the screen itself');
        self::assertSame('', $this->linkFor(4), 'no Accessibility module');
    }
}
