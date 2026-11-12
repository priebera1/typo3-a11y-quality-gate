<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Mcp;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Command\McpConnectorCommand;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Mcp\McpAuditRepository;
use Priebera\A11yQualityGate\Mcp\McpConnectionStore;
use Priebera\A11yQualityGate\Mcp\McpPairingService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Sol audit F1/F4/F7 on the TYPO3 side, against a scripted Gateway (Guzzle middleware): a completion that arrives after
 * the pairing was cancelled is never stored and its Gateway installation is disconnected; a cancel goes to the Gateway
 * first; and the connector executes nothing the Gateway's begin check refuses and nothing it did not acknowledge.
 */
final class McpPairingAndDeliveryFunctionalTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function auditRetentionIsTheApproved90DaysAndInvalidValuesDeleteNothing(): void
    {
        $repository = $this->get(McpAuditRepository::class);
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable(Tables::MCP_AUDIT);
        $age = static fn (string $action, int $seconds) => $connection->update(Tables::MCP_AUDIT, ['crdate' => time() - $seconds], ['action' => $action]);
        $repository->record('kept', 'ok', 'backend', ['beUserUid' => 1]);
        $repository->record('expired', 'ok', 'backend', ['beUserUid' => 1]);
        $age('kept', 89 * 86400 + 82800);
        $age('expired', 90 * 86400 + 3600);
        self::assertSame(90, $repository->retentionDays(), 'empty configuration: the approved 90 days');
        self::assertFalse($repository->retentionMisconfigured());
        self::assertSame(1, $repository->prune());
        self::assertSame(['kept'], array_column($repository->findRecent(), 'action'), 'kept until 90 days after it was written');

        $age('kept', 400 * 86400);
        foreach (['abc', '-5', '0', '4000', '1.5'] as $invalid) {
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']['mcpAuditRetentionDays'] = $invalid;
            self::assertNull($repository->retentionDays(), $invalid);
            self::assertTrue($repository->retentionMisconfigured(), $invalid);
            self::assertNull($repository->prune(), 'a typo never shortens the trail: ' . $invalid);
        }
        self::assertSame(1, (int)$connection->count('*', Tables::MCP_AUDIT, []));
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']['mcpAuditRetentionDays'] = '365';
        self::assertSame(365, $repository->retentionDays());
        self::assertSame(1, $repository->prune());
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']['mcpAuditRetentionDays']);
    }

    private const SECRET = 'aqgc_' . 'A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8S9t0U1v';
    private const PAIRING = '11111111-2222-4333-8444-555555555555';
    private const NEXT_PAIRING = '66666666-7777-4888-8999-aaaaaaaaaaaa';

    /** @var list<array{method: string, path: string, body: array<string, mixed>, auth: string}> */
    private array $requests = [];

    /** @var array<string, \Closure(array<string, mixed>): array{0: int, 1: array<string, mixed>}> */
    private array $routes = [];

    protected function setUp(): void
    {
        parent::setUp();
        // This instance keeps var/ inside its web root: the host key comes from the environment (McpHostKey).
        putenv(\Priebera\A11yQualityGate\Mcp\McpHostKey::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
        $this->requests = [];
        $this->routes = [];
        // A handler stack with this test's terminal handler: no request leaves the process.
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = HandlerStack::create(function (RequestInterface $request, array $options) {
            $path = $request->getUri()->getPath();
            $body = json_decode((string)$request->getBody(), true) ?: [];
            $this->requests[] = ['method' => $request->getMethod(), 'path' => $path, 'body' => $body, 'auth' => $request->getHeaderLine('Authorization')];
            foreach ($this->routes as $pattern => $answer) {
                if (preg_match($pattern, $path) === 1) {
                    [$status, $json] = $answer($body);
                    return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], (string)json_encode($json)));
                }
            }
            return Create::promiseFor(new Response(404, ['Content-Type' => 'application/json'], '{"success":false,"error":{"code":"route_not_found"}}'));
        });
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']);
        putenv(\Priebera\A11yQualityGate\Mcp\McpHostKey::ENVIRONMENT_VARIABLE);
        parent::tearDown();
    }

    #[Test]
    public function connectionTransitionsAreCompareAndSetOnThePairing(): void
    {
        $store = $this->get(McpConnectionStore::class);
        $store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin');

        self::assertFalse($store->completePairingIfCurrent(self::NEXT_PAIRING, self::SECRET, 'slug', 'https://gw.example/mcp/i/slug'), 'another pairing cannot complete this one');
        self::assertTrue($store->cancelPairingIfCurrent(self::PAIRING));
        self::assertFalse($store->completePairingIfCurrent(self::PAIRING, self::SECRET, 'slug', 'https://gw.example/mcp/i/slug'), 'a cancelled pairing never becomes connected');
        self::assertSame('none', $store->get()['status']);
        self::assertSame('', $store->getConnectorSecret());

        $store->startPairing(self::NEXT_PAIRING, 'EFGH6789', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin');
        self::assertFalse($store->cancelPairingIfCurrent(self::PAIRING), 'an old cancel does not end the newer pairing');
        self::assertTrue($store->completePairingIfCurrent(self::NEXT_PAIRING, self::SECRET, 'slug', 'https://gw.example/mcp/i/slug'));
        self::assertTrue($store->get()['activationPending']);
        self::assertSame(self::SECRET, $store->getConnectorSecret());
        self::assertFalse($store->markActivatedIfCurrent('another-credential'), 'an answer about another credential changes nothing');
        self::assertTrue($store->get()['activationPending']);
        self::assertTrue($store->markActivatedIfCurrent($store->get()['credentialId']));
        self::assertFalse($store->get()['activationPending']);
    }

    #[Test]
    public function aCompletionThatLostTheRaceIsDisconnectedAndNeverStored(): void
    {
        $store = $this->get(McpConnectionStore::class);
        $store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin');
        // While the Gateway answers, the administrator cancels in another request.
        $this->routes['#/mcp/pairing/[^/]+/complete$#'] = function () use ($store): array {
            $store->cancelPairingIfCurrent(self::PAIRING);
            return [200, ['success' => true, 'status' => 'completed', 'connectorSecret' => self::SECRET, 'slug' => 'slug', 'serverUrl' => 'https://gw.example/mcp/i/slug']];
        };
        $this->routes['#/mcp/connector/disconnect$#'] = fn (): array => [200, ['success' => true, 'revokedTokens' => 0]];

        self::assertSame('cancelled', $this->get(McpPairingService::class)->poll());
        self::assertSame('none', $store->get()['status']);
        self::assertSame('', $store->getConnectorSecret());
        $disconnects = array_values(array_filter($this->requests, static fn (array $request): bool => str_ends_with($request['path'], '/mcp/connector/disconnect')));
        self::assertCount(1, $disconnects, 'the lost installation is disconnected at the Gateway');
        self::assertSame('Bearer ' . self::SECRET, $disconnects[0]['auth']);
        self::assertNotContains('/mcp/connector/activate', array_column($this->requests, 'path'), 'it is never activated');
    }

    #[Test]
    public function aWinningCompletionIsActivatedAndACancelGoesToTheGatewayFirst(): void
    {
        $store = $this->get(McpConnectionStore::class);
        $store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin');
        $this->routes['#/mcp/pairing/[^/]+/complete$#'] = fn (): array => [200, ['success' => true, 'status' => 'completed', 'connectorSecret' => self::SECRET, 'slug' => 'slug', 'serverUrl' => 'https://gw.example/mcp/i/slug']];
        $this->routes['#/mcp/connector/activate$#'] = fn (): array => [200, ['success' => true, 'status' => 'active']];
        self::assertSame('connected', $this->get(McpPairingService::class)->poll());
        self::assertFalse($store->get()['activationPending']);

        // A live connection is never overwritten by a new pairing (its secret would be lost).
        self::assertFalse($store->startPairing(self::NEXT_PAIRING, 'EFGH6789', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin'));
        self::assertSame(self::SECRET, $store->getConnectorSecret());

        $store->markDisconnected('admin');
        self::assertTrue($store->startPairing(self::NEXT_PAIRING, 'EFGH6789', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin'));
        $this->routes['#/mcp/pairing/[^/]+/cancel$#'] = fn (): array => [200, ['success' => true, 'status' => 'cancelled']];
        self::assertSame('cancelled', $this->get(McpPairingService::class)->cancel());
        $cancels = array_values(array_filter($this->requests, static fn (array $request): bool => str_ends_with($request['path'], '/cancel')));
        self::assertCount(1, $cancels);
        self::assertStringContainsString(self::NEXT_PAIRING, $cancels[0]['path']);
        self::assertSame('none', $store->get()['status']);
    }

    #[Test]
    public function anOverlappingPollWithoutTheSecretLeavesTheOtherPollsCompletionAlone(): void
    {
        $store = $this->get(McpConnectionStore::class);
        $store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin');
        // A second tab polls after the first collected the secret: the Gateway answers completed without a secret.
        $this->routes['#/mcp/pairing/[^/]+/complete$#'] = fn (): array => [200, ['success' => true, 'status' => 'completed']];
        self::assertSame('pending', $this->get(McpPairingService::class)->poll());
        self::assertSame('pairing', $store->get()['status'], 'the first poll can still store its secret');
        self::assertTrue($store->completePairingIfCurrent(self::PAIRING, self::SECRET, 'slug', 'https://gw.example/mcp/i/slug'));

        // A lost response: once the pairing and activation windows are over, the wait ends.
        $store->markDisconnected('admin');
        $store->startPairing(self::NEXT_PAIRING, 'EFGH6789', 'aqgp_poll', time() - 700, 'https://app.example/app/agent-access', 'admin');
        self::assertSame('cancelled', $this->get(McpPairingService::class)->poll());
    }

    #[Test]
    public function anActivationTheGatewayRefusesEndsTheLocalConnection(): void
    {
        $store = $this->get(McpConnectionStore::class);
        $store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin');
        $store->completePairingIfCurrent(self::PAIRING, self::SECRET, 'slug', 'https://gw.example/mcp/i/slug');
        $this->routes['#/mcp/connector/activate$#'] = fn (): array => [410, ['success' => false, 'error' => ['code' => 'activation_expired']]];
        self::assertFalse($this->get(McpPairingService::class)->ensureActivated());
        self::assertSame('disconnected', $store->get()['status']);
        self::assertSame('', $store->getConnectorSecret());
    }

    #[Test]
    public function theConnectorRunsNothingTheGatewayRefusesAtBeginOrDidNotAcknowledge(): void
    {
        $store = $this->get(McpConnectionStore::class);
        $store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin');
        $store->completePairingIfCurrent(self::PAIRING, self::SECRET, 'slug', 'https://gw.example/mcp/i/slug');
        $store->markActivatedIfCurrent($store->get()['credentialId']);
        $calls = [
            ['id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'claimId' => 'cccccccc-0000-4000-8000-000000000001', 'tool' => 'aqg_start_scan', 'arguments' => ['pageUid' => 1]],
            ['id' => 'aaaaaaaa-0000-4000-8000-000000000002', 'claimId' => 'cccccccc-0000-4000-8000-000000000002', 'tool' => 'aqg_whoami', 'arguments' => []],
        ];
        $this->routes['#/mcp/connector/poll$#'] = fn (): array => [200, ['success' => true, 'calls' => $calls]];
        // Only the first call is acknowledged; the Gateway then refuses to begin it (token revoked meanwhile).
        $this->routes['#/mcp/connector/calls/ack$#'] = fn (): array => [200, ['success' => true, 'acknowledged' => [$calls[0]['id']]]];
        $this->routes['#/mcp/connector/calls/[^/]+/begin$#'] = fn (): array => [403, ['success' => false, 'error' => ['code' => 'token_revoked', 'message' => 'The agent token was revoked']]];

        $tester = new CommandTester($this->get(McpConnectorCommand::class));
        self::assertSame(0, $tester->execute(['--once' => true]));

        $paths = array_column($this->requests, 'path');
        self::assertContains('/mcp/connector/calls/' . $calls[0]['id'] . '/begin', $paths);
        self::assertNotContains('/mcp/connector/calls/' . $calls[1]['id'] . '/begin', $paths, 'an unacknowledged call is not touched');
        foreach ($paths as $path) {
            self::assertStringEndsNotWith('/result', $path, 'nothing was executed, so nothing is answered');
        }
        $audit = $this->get(ConnectionPool::class)->getConnectionForTable(Tables::MCP_AUDIT)
            ->select(['action', 'result', 'reason'], Tables::MCP_AUDIT)->fetchAllAssociative();
        self::assertSame([['action' => 'aqg_start_scan', 'result' => 'refused', 'reason' => 'token_revoked']], $audit);
        self::assertSame(0, (int)$this->get(ConnectionPool::class)->getConnectionForTable(Tables::REMOTE_SCAN)->count('*', Tables::REMOTE_SCAN, []), 'no scan was started');
    }

    private const NEXT_SECRET = 'aqgc_' . 'Z9y8X7w6V5u4T3s2R1q0P9o8N7m6L5k4J3i2H1g0F9e';

    #[Test]
    public function aLateActivationAnswerForAnEarlierConnectionNeverChangesTheNewerOne(): void
    {
        $store = $this->get(McpConnectionStore::class);
        $replaceWithNewerConnection = function () use ($store): void {
            // While activation A is in flight, the administrator disconnects and pairs again (connection B).
            $store->markDisconnected('admin');
            $store->startPairing(self::NEXT_PAIRING, 'EFGH6789', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin');
            $store->completePairingIfCurrent(self::NEXT_PAIRING, self::NEXT_SECRET, 'slug-b', 'https://gw.example/mcp/i/slug-b');
        };

        // A's late success must not clear B's pending activation (audit A5).
        $store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin');
        $store->completePairingIfCurrent(self::PAIRING, self::SECRET, 'slug', 'https://gw.example/mcp/i/slug');
        $this->routes['#/mcp/connector/activate$#'] = function () use ($replaceWithNewerConnection): array {
            $replaceWithNewerConnection();
            return [200, ['success' => true, 'status' => 'active']];
        };
        self::assertTrue($this->get(McpPairingService::class)->ensureActivated());
        $activations = array_values(array_filter($this->requests, static fn (array $request): bool => str_ends_with($request['path'], '/mcp/connector/activate')));
        self::assertSame('Bearer ' . self::SECRET, $activations[0]['auth'], 'activation A is sent with A\'s secret');
        self::assertSame(self::NEXT_PAIRING, $store->get()['pairingId']);
        self::assertTrue($store->get()['activationPending'], 'B still has to be activated with its own secret');
        self::assertSame(self::NEXT_SECRET, $store->getConnectorSecret());

        // A's late refusal must not discard B's credential.
        $store->markDisconnected('admin');
        $store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin');
        $store->completePairingIfCurrent(self::PAIRING, self::SECRET, 'slug', 'https://gw.example/mcp/i/slug');
        $this->routes['#/mcp/connector/activate$#'] = function () use ($replaceWithNewerConnection): array {
            $replaceWithNewerConnection();
            return [409, ['success' => false, 'error' => ['code' => 'pairing_state_changed']]];
        };
        self::assertTrue($this->get(McpPairingService::class)->ensureActivated(), 'the refusal ended nothing that is stored now');
        self::assertSame('connected', $store->get()['status']);
        self::assertSame(self::NEXT_SECRET, $store->getConnectorSecret());

        // B's own answers apply to B.
        $this->routes['#/mcp/connector/activate$#'] = fn (): array => [200, ['success' => true, 'status' => 'active']];
        self::assertTrue($this->get(McpPairingService::class)->ensureActivated());
        self::assertFalse($store->get()['activationPending']);
        self::assertFalse($store->markDisconnectedIfCurrent('credential-of-a', 'admin'), 'a disconnect answer about A leaves B connected');
        self::assertSame('connected', $store->get()['status']);
        self::assertTrue($store->markDisconnectedIfCurrent($store->get()['credentialId'], 'admin'));
        self::assertSame('disconnected', $store->get()['status']);
    }

    #[Test]
    public function onlyTheGatewaysOwnRefusalEndsAPendingActivation(): void
    {
        $store = $this->get(McpConnectionStore::class);
        $store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin');
        $store->completePairingIfCurrent(self::PAIRING, self::SECRET, 'slug', 'https://gw.example/mcp/i/slug');
        $service = $this->get(McpPairingService::class);
        // A rate limit, a proxy's or WAF's answer (not the Gateway's JSON) and a disabled Gateway keep it pending.
        foreach ([
            [429, '{"statusCode":429,"error":"Too Many Requests"}'],
            [403, '<html>Forbidden</html>'],
            [404, '{"success":false,"error":{"code":"route_not_found"}}'],
            [408, '{}'],
        ] as [$status, $body]) {
            $this->routes['#/mcp/connector/activate$#'] = fn (): array => [$status, json_decode($body, true) ?? ['raw' => $body]];
            self::assertTrue($service->ensureActivated(), 'HTTP ' . $status);
            self::assertSame('connected', $store->get()['status'], 'HTTP ' . $status);
            self::assertTrue($store->get()['activationPending'], 'HTTP ' . $status);
        }
        $this->routes['#/mcp/connector/activate$#'] = fn (): array => [401, ['success' => false, 'error' => ['code' => 'connector_unauthorized']]];
        self::assertFalse($service->ensureActivated());
        self::assertSame('disconnected', $store->get()['status']);
    }

    #[Test]
    public function aCancelTheGatewayDidNotConfirmIsReportedNotSwallowed(): void
    {
        $store = $this->get(McpConnectionStore::class);
        $service = $this->get(McpPairingService::class);
        $store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin');
        $this->routes['#/mcp/pairing/[^/]+/cancel$#'] = fn (): array => [503, ['success' => false, 'error' => ['code' => 'concurrent_update']]];
        self::assertSame('unconfirmed', $service->cancel());
        self::assertSame('none', $store->get()['status'], 'the local wait ends either way: a late completion fails its compare-and-set');

        $store->startPairing(self::NEXT_PAIRING, 'EFGH6789', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin');
        $this->routes['#/mcp/pairing/[^/]+/cancel$#'] = fn (): array => [404, ['success' => false, 'error' => ['code' => 'pairing_not_found']]];
        self::assertSame('cancelled', $service->cancel(), 'nothing open at the Gateway is a confirmed cancel');
        self::assertSame('none', $service->cancel());
    }
}
