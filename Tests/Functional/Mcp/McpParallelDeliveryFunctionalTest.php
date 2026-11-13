<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Mcp;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Command\McpConnectorWorkerCommand;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Mcp\Http\McpGatewayClient;
use Priebera\A11yQualityGate\Mcp\McpConnectionStore;
use Priebera\A11yQualityGate\Mcp\McpConsentLedger;
use Priebera\A11yQualityGate\Mcp\McpHostKey;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Fable F-02/F-05/F-10 on the TYPO3 side, against a scripted Gateway: the connector's worker process runs calls under
 * the connector's instance id and only after the Gateway's begin check (and TYPO3's consent check), refuses a call begun
 * under another tool name, and does nothing without a valid instance id or a usable connection; the Gateway client sends
 * UTF-8 bodies, and reads every page of connected agents (active ones first) without looping on a repeated cursor.
 */
final class McpParallelDeliveryFunctionalTest extends AbstractFunctionalTestCase
{
    private const SECRET = 'aqgc_' . 'A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8S9t0U1v';
    private const PAIRING = '11111111-2222-4333-8444-555555555555';
    private const REQUEST_REF = 'aqgr_' . 'consentReferenceOfTheAuthorizationRequest01';
    private const INSTANCE = '0123456789abcdef0123456789abcdef';

    /** @var list<array{method: string, path: string, query: string, raw: string, body: array<string, mixed>, instance: string}> */
    private array $requests = [];

    /** @var array<string, \Closure(array<string, mixed>, string): array{0: int, 1: array<string, mixed>}> */
    private array $routes = [];

    protected function setUp(): void
    {
        parent::setUp();
        putenv(McpHostKey::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
        $this->requests = [];
        $this->routes = [];
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = HandlerStack::create(function (RequestInterface $request, array $options) {
            $path = $request->getUri()->getPath();
            $raw = (string)$request->getBody();
            $body = json_decode($raw, true) ?: [];
            $this->requests[] = [
                'method' => $request->getMethod(), 'path' => $path, 'query' => $request->getUri()->getQuery(), 'raw' => $raw,
                'body' => $body, 'instance' => $request->getHeaderLine('X-AQG-Connector-Instance'),
            ];
            foreach ($this->routes as $pattern => $answer) {
                if (preg_match($pattern, $path) === 1) {
                    [$status, $json] = $answer($body, $request->getUri()->getQuery());
                    return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], (string)json_encode($json)));
                }
            }
            return Create::promiseFor(new Response(404, ['Content-Type' => 'application/json'], '{"success":false,"error":{"code":"route_not_found"}}'));
        });
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']);
        putenv(McpHostKey::ENVIRONMENT_VARIABLE);
        @unlink($this->get(McpHostKey::class)->path());
        parent::tearDown();
    }

    private function connect(): void
    {
        $store = $this->get(McpConnectionStore::class);
        self::assertTrue($store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin'));
        self::assertTrue($store->completePairingIfCurrent(self::PAIRING, self::SECRET, 'slug', 'https://gw.example/mcp/i/slug'));
        self::assertTrue($store->markActivatedIfCurrent($store->get()['credentialId']));
    }

    /** @param array<string, mixed> $call */
    private static function line(array $message): string
    {
        return (string)json_encode($message);
    }

    private function scriptBegin(string $tool): void
    {
        $this->routes['#/mcp/connector/calls/[^/]+/begin$#'] = fn (): array => [200, ['success' => true, 'remainingMs' => 20000, 'tool' => $tool, 'principal' => [
            'tokenId' => 'tttttttt-0000-4000-8000-000000000001', 'tokenSuffix' => 'abcd', 'clientName' => 'Test agent', 'beUserUid' => 1, 'beUserName' => 'Eva',
            'scopes' => ['findings:read'], 'siteIdentifiers' => ['main'], 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400),
            'consentRefHash' => McpConsentLedger::consentRefHash(self::REQUEST_REF),
        ]]];
        $this->routes['#/mcp/connector/calls/[^/]+/result$#'] = fn (): array => [200, ['success' => true]];
    }

    /** @return list<array<string, mixed>> */
    private function requestsTo(string $suffix): array
    {
        return array_values(array_filter($this->requests, static fn (array $request): bool => str_ends_with($request['path'], $suffix)));
    }

    #[Test]
    public function theWorkerRunsCallsUnderTheConnectorsInstanceIdOnlyAfterBegin(): void
    {
        $this->connect();
        self::assertTrue($this->get(McpConsentLedger::class)->record(self::REQUEST_REF, 1, ['findings:read'], ['main']));
        $this->scriptBegin('aqg_whoami');

        $tester = new CommandTester($this->get(McpConnectorWorkerCommand::class));
        $tester->setInputs([
            self::line(['instanceId' => self::INSTANCE]),
            self::line(['call' => ['id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'claimId' => 'cccccccc-0000-4000-8000-000000000001', 'tool' => 'aqg_whoami', 'arguments' => []]]),
            self::line(['call' => ['id' => 'aaaaaaaa-0000-4000-8000-000000000002', 'claimId' => 'cccccccc-0000-4000-8000-000000000002', 'tool' => 'aqg_whoami', 'arguments' => []]]),
        ]);
        self::assertSame(0, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('AQG-MCP-WORKER {"ready":true}', $display);
        self::assertStringContainsString('AQG-MCP-WORKER {"done":"aaaaaaaa-0000-4000-8000-000000000001","outcome":"executed"}', $display);
        self::assertStringContainsString('AQG-MCP-WORKER {"done":"aaaaaaaa-0000-4000-8000-000000000002","outcome":"executed"}', $display);
        $begins = $this->requestsTo('/begin');
        $results = $this->requestsTo('/result');
        self::assertCount(2, $begins);
        self::assertCount(2, $results);
        foreach ([...$begins, ...$results] as $request) {
            self::assertSame(self::INSTANCE, $request['instance'], 'the worker acts inside the connector\'s lease');
        }
        self::assertNotSame('consent_unknown', $results[0]['body']['error']['code'] ?? null, 'the recorded consent was found');
        self::assertSame([], $this->requestsTo('/poll'), 'a worker never polls');
        self::assertSame([], $this->requestsTo('/release'), 'nor releases the connector\'s lease');
    }

    #[Test]
    public function aCallBegunUnderAnotherToolNameIsRefusedAndNothingRuns(): void
    {
        $this->connect();
        self::assertTrue($this->get(McpConsentLedger::class)->record(self::REQUEST_REF, 1, ['findings:read'], ['main']));
        $this->scriptBegin('aqg_start_scan');

        $tester = new CommandTester($this->get(McpConnectorWorkerCommand::class));
        $tester->setInputs([
            self::line(['instanceId' => self::INSTANCE]),
            self::line(['call' => ['id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'claimId' => 'cccccccc-0000-4000-8000-000000000001', 'tool' => 'aqg_whoami', 'arguments' => []]]),
        ]);
        $tester->execute([]);

        $results = $this->requestsTo('/result');
        self::assertCount(1, $results);
        self::assertSame('call_mismatch', $results[0]['body']['error']['code'] ?? null);
        $audit = $this->get(ConnectionPool::class)->getConnectionForTable(Tables::MCP_AUDIT)
            ->select(['action', 'result', 'reason'], Tables::MCP_AUDIT)->fetchAllAssociative();
        self::assertSame([['action' => 'aqg_whoami', 'result' => 'refused', 'reason' => 'call_mismatch']], $audit);
        self::assertSame(0, (int)$this->get(ConnectionPool::class)->getConnectionForTable(Tables::REMOTE_SCAN)->count('*', Tables::REMOTE_SCAN, []));
    }

    #[Test]
    public function withoutAValidInstanceIdOrAConnectionTheWorkerDoesNothing(): void
    {
        $this->connect();
        $tester = new CommandTester($this->get(McpConnectorWorkerCommand::class));
        $tester->setInputs([self::line(['instanceId' => 'short']), self::line(['call' => ['id' => 'x', 'claimId' => 'y', 'tool' => 'aqg_whoami']])]);
        self::assertSame(1, $tester->execute([]));
        self::assertSame([], $this->requests);

        $this->get(McpConnectionStore::class)->markDisconnected('admin');
        $tester = new CommandTester($this->get(McpConnectorWorkerCommand::class));
        $tester->setInputs([self::line(['instanceId' => self::INSTANCE])]);
        self::assertSame(1, $tester->execute([]));
        self::assertSame([], $this->requests);
        self::assertStringNotContainsString('ready', $tester->getDisplay());
    }

    #[Test]
    public function requestBodiesAreUtf8AndConnectedAgentsAreReadPageByPage(): void
    {
        $this->connect();
        $client = $this->get(McpGatewayClient::class);
        $this->routes['#/mcp/connector/calls/[^/]+/result$#'] = fn (): array => [200, ['success' => true]];
        $client->useConnectorInstance(self::INSTANCE);
        $client->submitResult('aaaaaaaa-0000-4000-8000-000000000001', 'cccccccc-0000-4000-8000-000000000001', ['ok' => true, 'result' => ['title' => 'Доступность / 無障害']]);
        $raw = $this->requestsTo('/result')[0]['raw'];
        self::assertStringContainsString('Доступность / 無障害', $raw);
        self::assertStringNotContainsString('\u', $raw);

        $token = static fn (string $id, string $status): array => ['id' => $id, 'status' => $status, 'clientName' => 'A', 'clientId' => 'c', 'beUserUid' => 1, 'beUserName' => 'Eva', 'scopes' => [], 'siteIdentifiers' => [], 'createdAt' => '2026-11-01T00:00:00Z', 'expiresAt' => '2026-12-01T00:00:00Z'];
        $cursor1 = str_repeat('A', 20);
        $cursor2 = str_repeat('B', 20);
        $this->routes['#/mcp/connector/tokens$#'] = function (array $body, string $query) use ($token, $cursor1, $cursor2): array {
            parse_str($query, $parameters);
            return match ($parameters['cursor'] ?? null) {
                null => [200, ['success' => true, 'tokens' => [$token('t1', 'active'), $token('t2', 'revoked')], 'nextCursor' => $cursor1]],
                $cursor1 => [200, ['success' => true, 'tokens' => [$token('t2', 'revoked'), $token('t3', 'expired')], 'nextCursor' => $cursor2]],
                default => [200, ['success' => true, 'tokens' => [$token('t4', 'revoked')], 'nextCursor' => null]],
            };
        };
        self::assertSame(['t1', 't2', 't3', 't4'], array_column($client->listTokens(7), 'id'), 'every page, each token once');
        $queries = array_column($this->requestsTo('/mcp/connector/tokens'), 'query');
        self::assertSame(['beUserUid=7', 'beUserUid=7&cursor=' . $cursor1, 'beUserUid=7&cursor=' . $cursor2], $queries);

        // A Gateway that repeats its cursor (or a pre-F-10 Gateway without one) ends the reading.
        $this->requests = [];
        $this->routes['#/mcp/connector/tokens$#'] = fn (): array => [200, ['success' => true, 'tokens' => [$token('t1', 'active')], 'nextCursor' => $cursor1]];
        self::assertSame(['t1'], array_column($client->listTokens(), 'id'));
        self::assertCount(2, $this->requestsTo('/mcp/connector/tokens'), 'a repeated cursor stops after one more page');
        $this->requests = [];
        $this->routes['#/mcp/connector/tokens$#'] = fn (): array => [200, ['success' => true, 'tokens' => [$token('t9', 'active')]]];
        self::assertSame(['t9'], array_column($client->listTokens(), 'id'));
        self::assertCount(1, $this->requestsTo('/mcp/connector/tokens'));
    }
}
