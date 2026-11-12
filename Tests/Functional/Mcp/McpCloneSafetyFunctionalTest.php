<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Mcp;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Command\McpConnectorCommand;
use Priebera\A11yQualityGate\Command\McpResetLocalCommand;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Mcp\McpConnectionStore;
use Priebera\A11yQualityGate\Mcp\McpConsentLedger;
use Priebera\A11yQualityGate\Mcp\McpHostKey;
use Priebera\A11yQualityGate\Service\InstallationIdentityService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Clone safety N2 on the TYPO3 side, against a scripted Gateway (no request leaves the process): one named connector
 * process with a released lease, calls run only for consents this installation recorded for that user, secrets sealed
 * with the host key and bound to the installation identity, and a local reset that never calls the Gateway.
 */
final class McpCloneSafetyFunctionalTest extends AbstractFunctionalTestCase
{
    private const SECRET = 'aqgc_' . 'A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8S9t0U1v';
    private const PAIRING = '11111111-2222-4333-8444-555555555555';
    private const REQUEST_REF = 'aqgr_' . 'consentReferenceOfTheAuthorizationRequest01';

    /** @var list<array{method: string, path: string, body: array<string, mixed>, instance: string}> */
    private array $requests = [];

    /** @var array<string, \Closure(array<string, mixed>): array{0: int, 1: array<string, mixed>}> */
    private array $routes = [];

    private string $hostKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        // The functional-test instance keeps var/ inside its web root (classic layout), where no key file may live: this
        // host's key comes from the environment, as on classic-mode and multi-host installations.
        $this->hostKey = base64_encode(random_bytes(32));
        putenv(McpHostKey::ENVIRONMENT_VARIABLE . '=' . $this->hostKey);
        $this->requests = [];
        $this->routes = [];
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = HandlerStack::create(function (RequestInterface $request, array $options) {
            $path = $request->getUri()->getPath();
            $body = json_decode((string)$request->getBody(), true) ?: [];
            $this->requests[] = ['method' => $request->getMethod(), 'path' => $path, 'body' => $body, 'instance' => $request->getHeaderLine('X-AQG-Connector-Instance')];
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
        putenv(McpHostKey::ENVIRONMENT_VARIABLE);
        @unlink($this->get(McpHostKey::class)->path());
        parent::tearDown();
    }

    private function connect(): McpConnectionStore
    {
        $store = $this->get(McpConnectionStore::class);
        self::assertTrue($store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin'));
        self::assertTrue($store->completePairingIfCurrent(self::PAIRING, self::SECRET, 'slug', 'https://gw.example/mcp/i/slug'));
        self::assertTrue($store->markActivatedIfCurrent($store->get()['credentialId']));
        self::assertSame(self::SECRET, $store->getConnectorSecret());

        return $store;
    }

    /** @param array<string, mixed> $principal */
    private function scriptOneCall(array $principal, string $tool = 'aqg_whoami'): void
    {
        $call = ['id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'claimId' => 'cccccccc-0000-4000-8000-000000000001', 'tool' => $tool, 'arguments' => []];
        $this->routes['#/mcp/connector/poll$#'] = fn (): array => [200, ['success' => true, 'calls' => [$call]]];
        $this->routes['#/mcp/connector/calls/ack$#'] = fn (): array => [200, ['success' => true, 'acknowledged' => [$call['id']]]];
        $this->routes['#/mcp/connector/calls/[^/]+/begin$#'] = fn (): array => [200, ['success' => true, 'remainingMs' => 20000, 'principal' => $principal + [
            'tokenId' => 'tttttttt-0000-4000-8000-000000000001', 'tokenSuffix' => 'abcd', 'clientName' => 'Test agent', 'beUserName' => 'Eva',
            'scopes' => ['findings:read'], 'siteIdentifiers' => ['main'], 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400),
        ]]];
        $this->routes['#/mcp/connector/calls/[^/]+/result$#'] = fn (): array => [200, ['success' => true]];
        $this->routes['#/mcp/connector/release$#'] = fn (): array => [200, ['success' => true, 'released' => true]];
    }

    /** @return array<string, mixed>|null */
    private function postedResult(): ?array
    {
        foreach ($this->requests as $request) {
            if (str_ends_with($request['path'], '/result')) {
                return $request['body'];
            }
        }

        return null;
    }

    #[Test]
    public function oneNamedConnectorProcessPollsAndHandsItsLeaseOverOnStop(): void
    {
        $this->connect();
        $this->routes['#/mcp/connector/poll$#'] = fn (): array => [200, ['success' => true, 'calls' => []]];
        $this->routes['#/mcp/connector/release$#'] = fn (): array => [200, ['success' => true, 'released' => true]];

        self::assertSame(0, (new CommandTester($this->get(McpConnectorCommand::class)))->execute(['--once' => true]));

        $poll = array_values(array_filter($this->requests, static fn (array $r): bool => $r['path'] === '/mcp/connector/poll'));
        $release = array_values(array_filter($this->requests, static fn (array $r): bool => $r['path'] === '/mcp/connector/release'));
        self::assertCount(1, $poll);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $poll[0]['instance'], 'every connector request names its process');
        self::assertSame($poll[0]['instance'], $poll[0]['body']['instanceId']);
        self::assertCount(1, $release, 'a clean stop releases the lease');
        self::assertSame($poll[0]['instance'], $release[0]['instance']);
    }

    #[Test]
    public function aSecondConnectorIsToldToResetTheCopyNeverToDisconnectIt(): void
    {
        $this->connect();
        $this->routes['#/mcp/connector/poll$#'] = fn (): array => [409, ['success' => false, 'error' => ['code' => 'connector_conflict', 'message' => 'x']]];
        $tester = new CommandTester($this->get(McpConnectorCommand::class));
        self::assertSame(1, $tester->execute(['--once' => true]));
        self::assertStringContainsString('a11y:mcp:reset-local', $tester->getDisplay());
        self::assertStringContainsString('Do not disconnect the copy', $tester->getDisplay());
        self::assertNotContains('/mcp/connector/disconnect', array_column($this->requests, 'path'));
    }

    #[Test]
    public function aCallWhoseConsentThisInstallationNeverRecordedIsRefusedAsConsentUnknown(): void
    {
        $this->connect();
        // Consent decided on a copy: a valid-looking consent reference, but no row in this installation's ledger.
        $this->scriptOneCall(['beUserUid' => 1, 'consentRefHash' => McpConsentLedger::consentRefHash(self::REQUEST_REF)]);

        self::assertSame(0, (new CommandTester($this->get(McpConnectorCommand::class)))->execute(['--once' => true]));

        $result = $this->postedResult();
        self::assertIsArray($result);
        self::assertFalse($result['ok']);
        self::assertSame('consent_unknown', $result['error']['code']);
        self::assertSame('cccccccc-0000-4000-8000-000000000001', $result['claimId']);
        $audit = $this->get(ConnectionPool::class)->getConnectionForTable(Tables::MCP_AUDIT)
            ->select(['action', 'result', 'reason'], Tables::MCP_AUDIT)->fetchAllAssociative();
        self::assertSame([['action' => 'aqg_whoami', 'result' => 'refused', 'reason' => 'consent_unknown']], $audit);
    }

    #[Test]
    public function aRecordedConsentRunsOnlyForTheUserWhoGaveIt(): void
    {
        $this->connect();
        $ledger = $this->get(McpConsentLedger::class);
        self::assertTrue($ledger->record(self::REQUEST_REF, 1, ['findings:read'], ['main']));
        // A second submission (double click, or another tab with a wider selection) neither claims nor replaces the grant.
        self::assertFalse($ledger->record(self::REQUEST_REF, 1, ['findings:read', 'scans:run-page'], ['main']));
        $hash = McpConsentLedger::consentRefHash(self::REQUEST_REF);
        self::assertTrue($ledger->isRecorded($hash, 1, ['findings:read'], ['main']));
        self::assertFalse($ledger->isRecorded($hash, 2, ['findings:read'], ['main']), 'another user cannot borrow the consent');
        self::assertFalse($ledger->isRecorded($hash, 1, ['findings:read', 'scans:run-page'], ['main']), 'a wider scope than this page granted');
        self::assertFalse($ledger->isRecorded($hash, 1, ['findings:read'], ['main', 'client-a']), 'a site this page did not grant');
        self::assertFalse($ledger->isRecorded('', 1, ['findings:read'], ['main']));
        self::assertFalse($ledger->isRecorded(strtoupper($hash), 1, ['findings:read'], ['main']), 'only the canonical hex form');
        $ledger->record(self::REQUEST_REF . 'failed', 1, ['findings:read'], ['main']);
        $ledger->withdraw(self::REQUEST_REF . 'failed', 1);
        self::assertFalse($ledger->isRecorded(McpConsentLedger::consentRefHash(self::REQUEST_REF . 'failed'), 1, ['findings:read'], ['main']), 'a decision the Gateway did not confirm is withdrawn');

        // Same consent, but the Gateway names another TYPO3 user: refused.
        $this->scriptOneCall(['beUserUid' => 2, 'consentRefHash' => McpConsentLedger::consentRefHash(self::REQUEST_REF)]);
        (new CommandTester($this->get(McpConnectorCommand::class)))->execute(['--once' => true]);
        self::assertSame('consent_unknown', $this->postedResult()['error']['code'] ?? null);

        // The user who consented: the consent check passes (the executor then applies that user's own rights).
        $this->requests = [];
        $this->scriptOneCall(['beUserUid' => 1, 'consentRefHash' => McpConsentLedger::consentRefHash(self::REQUEST_REF)]);
        (new CommandTester($this->get(McpConnectorCommand::class)))->execute(['--once' => true]);
        $result = $this->postedResult();
        self::assertIsArray($result);
        self::assertNotSame('consent_unknown', $result['error']['code'] ?? null);

        // The same consent with a wider grant than this page decided (decided elsewhere with the request reference): refused.
        $this->requests = [];
        $this->scriptOneCall(['beUserUid' => 1, 'consentRefHash' => McpConsentLedger::consentRefHash(self::REQUEST_REF), 'scopes' => ['findings:read', 'history:read']]);
        (new CommandTester($this->get(McpConnectorCommand::class)))->execute(['--once' => true]);
        self::assertSame('consent_unknown', $this->postedResult()['error']['code'] ?? null);

        // A missing consent reference (a token from before 097) never runs.
        $this->requests = [];
        $this->scriptOneCall(['beUserUid' => 1]);
        (new CommandTester($this->get(McpConnectorCommand::class)))->execute(['--once' => true]);
        self::assertSame('consent_unknown', $this->postedResult()['error']['code'] ?? null);
    }

    #[Test]
    public function theLedgerKeepsConsentsLongerThanTheLongestToken(): void
    {
        $ledger = $this->get(McpConsentLedger::class);
        $ledger->record(self::REQUEST_REF, 1, ['findings:read'], ['main']);
        $ledger->record(self::REQUEST_REF . 'old', 1, ['findings:read'], ['main']);
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable(Tables::MCP_CONSENT);
        $connection->update(Tables::MCP_CONSENT, ['crdate' => time() - 95 * 86400], ['consent_ref_hash' => McpConsentLedger::consentRefHash(self::REQUEST_REF)]);
        $connection->update(Tables::MCP_CONSENT, ['crdate' => time() - 101 * 86400], ['consent_ref_hash' => McpConsentLedger::consentRefHash(self::REQUEST_REF . 'old')]);
        self::assertSame(1, $ledger->prune());
        self::assertTrue($ledger->isRecorded(McpConsentLedger::consentRefHash(self::REQUEST_REF), 1, ['findings:read'], ['main']), '95 days > 90-day token: kept');
    }

    #[Test]
    public function aDatabaseCopyWithoutThisHostsKeyCannotOpenTheSecretAndMakesNoGatewayCall(): void
    {
        $store = $this->connect();
        $raw = (string)$this->get(ConnectionPool::class)->getConnectionForTable('sys_registry')
            ->select(['entry_value'], 'sys_registry', ['entry_namespace' => 'a11y_quality_gate', 'entry_key' => 'mcp_connection'])->fetchOne();
        self::assertStringNotContainsString(self::SECRET, $raw, 'never stored in plain text');

        // The copy: same database and encryptionKey, but a host without this key.
        putenv(McpHostKey::ENVIRONMENT_VARIABLE);
        self::assertSame('host_key_missing', $store->get()['unusableReason']);
        self::assertSame('', $store->getConnectorSecret());
        self::assertNull($store->currentCredential());
        $tester = new CommandTester($this->get(McpConnectorCommand::class));
        self::assertSame(1, $tester->execute(['--once' => true]));
        self::assertStringContainsString('cannot be used on this host', $tester->getDisplay());

        // A different key is refused just the same — and never replaces the stored binding.
        putenv(McpHostKey::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
        self::assertSame('host_key_mismatch', $store->get()['unusableReason']);
        self::assertSame('', $store->getConnectorSecret());
        putenv(McpHostKey::ENVIRONMENT_VARIABLE . '=not-a-key');
        self::assertSame('host_key_missing', $store->get()['unusableReason'], 'an invalid configured key fails closed');

        // Back on the original host: works again.
        putenv(McpHostKey::ENVIRONMENT_VARIABLE . '=' . $this->hostKey);
        self::assertSame('', $store->get()['unusableReason']);
        self::assertSame(self::SECRET, $store->getConnectorSecret());
        self::assertSame([], $this->requests, 'nothing was sent to the Gateway');
    }

    #[Test]
    public function noKeyFileIsEverWrittenInsideTheWebRoot(): void
    {
        $hostKey = $this->get(McpHostKey::class);
        putenv(McpHostKey::ENVIRONMENT_VARIABLE);
        self::assertFalse($hostKey->fileStoreAllowed(), 'this instance keeps var/ under its public path (classic layout)');
        self::assertNull($hostKey->current());
        try {
            $hostKey->ensure();
            self::fail('a key file under the web root must be refused');
        } catch (\Priebera\A11yQualityGate\Mcp\McpHostKeyUnavailable) {
        }
        self::assertFileDoesNotExist($hostKey->path());
        // Pairing is refused before anything is stored here.
        self::assertSame('none', $this->get(McpConnectionStore::class)->get()['status']);
    }

    #[Test]
    public function aNewInstallationIdentityMakesTheCopiedConnectionUnusableAndResetLocalNeverCallsTheGateway(): void
    {
        $store = $this->connect();
        $this->get(InstallationIdentityService::class)->assignNewInstallationId();
        self::assertSame('identity_changed', $store->get()['unusableReason']);
        self::assertSame('', $store->getConnectorSecret());

        $dryRun = new CommandTester($this->get(McpResetLocalCommand::class));
        self::assertSame(0, $dryRun->execute([]));
        self::assertSame('connected', $store->get()['status'], 'dry run without --confirm');
        $reset = new CommandTester($this->get(McpResetLocalCommand::class));
        self::assertSame(0, $reset->execute(['--confirm' => true]));
        self::assertSame('none', $store->get()['status']);
        self::assertSame([], $this->requests, 'a local reset makes no Gateway call (a copy must never disconnect)');
    }

    #[Test]
    public function aFullCopyIsResetWithoutAGatewayCallAndToldToReplaceAConfiguredHostKey(): void
    {
        // Sol AQG-MCP-07: a full copy carries the database, the configuration and this host's key. Software cannot tell
        // it from the original; the supported procedure resets it locally and gives it a new key before pairing.
        $store = $this->connect();
        $reset = new CommandTester($this->get(McpResetLocalCommand::class));
        self::assertSame(0, $reset->execute(['--confirm' => true, '--new-host-key' => true]));
        self::assertSame('none', $store->get()['status']);
        self::assertSame('', $store->getConnectorSecret(), 'no sealed secret is left in this database');
        self::assertStringContainsString('AQG_MCP_CONNECTOR_KEY', $reset->getDisplay(), 'a configured key can only be replaced by the operator');
        self::assertSame([], $this->requests, 'never a Gateway call');
    }

    #[Test]
    public function connectionsFromBeforeCloneSafetyMustBePairedAgain(): void
    {
        // A connection stored by the pre-N2 code: no format, no host key id, no installation binding.
        $this->get(\TYPO3\CMS\Core\Registry::class)->set('a11y_quality_gate', 'mcp_connection', [
            'status' => 'connected', 'connectorSecret' => 'sodium:v1:legacy', 'credentialId' => 'x', 'slug' => 'slug',
        ]);
        $store = $this->get(McpConnectionStore::class);
        self::assertSame('legacy_format', $store->get()['unusableReason']);
        self::assertSame('', $store->getConnectorSecret());
    }
}
