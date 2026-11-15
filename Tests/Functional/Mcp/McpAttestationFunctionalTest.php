<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Mcp;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Command\McpConnectorCommand;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Mcp\McpAttestationKeys;
use Priebera\A11yQualityGate\Mcp\McpConnectionStore;
use Priebera\A11yQualityGate\Mcp\McpConsentLedger;
use Priebera\A11yQualityGate\Mcp\McpHostKey;
use Priebera\A11yQualityGate\Mcp\McpInstallationKey;
use Priebera\A11yQualityGate\Mcp\McpPairingService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Priebera\A11yQualityGate\Tests\Support\McpAttestationFixture;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * V1–V5 on the TYPO3 side (AQG 3.0.0 attested execution), against a scripted Gateway and a stand-in licence API key
 * (McpAttestationFixture, real Ed25519): a call runs only with a valid licence API attestation for this installation,
 * this call, these exact arguments, a consent this installation signed for that user/client/grant, bound to one token and
 * not revoked here; every other case runs nothing and is audited. Pairing completes only with the licence API's binding
 * receipt for this installation's own key; key sets rotate only when signed by a pinned key.
 */
final class McpAttestationFunctionalTest extends AbstractFunctionalTestCase
{
    private const SECRET = 'aqgc_' . 'A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8S9t0U1v';
    private const PAIRING = '11111111-2222-4333-8444-555555555555';
    private const REQUEST_REF = 'aqgr_' . 'consentReferenceOfTheAuthorizationRequest01';
    private const TOKEN = 'abcdef01-0000-4000-8000-000000000001';
    private const CALL = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const CLIENT = 'mcp_client_test_0001';

    /** @var list<array{method: string, path: string, body: array<string, mixed>}> */
    private array $requests = [];
    /** @var array<string, \Closure(array<string, mixed>): array{0: int, 1: array<string, mixed>}> */
    private array $routes = [];
    private McpAttestationFixture $licenceApi;
    private int $executed = 0;

    protected function setUp(): void
    {
        parent::setUp();
        putenv(McpHostKey::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
        $this->licenceApi = new McpAttestationFixture();
        $this->requests = [];
        $this->routes = [];
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = HandlerStack::create(function (RequestInterface $request) {
            $path = $request->getUri()->getPath();
            $body = json_decode((string)$request->getBody(), true) ?: [];
            $this->requests[] = ['method' => $request->getMethod(), 'path' => $path, 'body' => $body];
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
        putenv(McpAttestationKeys::TRUST_ENV);
        @unlink($this->get(McpHostKey::class)->path());
        parent::tearDown();
    }

    /** A connection paired with attestation: installation key, pinned licence API key, Gateway installation id. */
    private function connectAttested(): McpConnectionStore
    {
        $store = $this->get(McpConnectionStore::class);
        $key = McpInstallationKey::generate();
        self::assertTrue($store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin',
            $key + ['pinnedKeys' => $this->licenceApi->pinned()]));
        self::assertTrue($store->completePairingIfCurrent(self::PAIRING, self::SECRET, 'slug', 'https://gw.example/mcp/i/slug', McpAttestationFixture::INSTALLATION_ID));
        self::assertTrue($store->markActivatedIfCurrent($store->get()['credentialId']));
        self::assertNotSame('', $store->installationSigningKey());
        return $store;
    }

    private function consent(int $beUserUid = 1, array $scopes = ['findings:read'], string $client = self::CLIENT): string
    {
        self::assertTrue($this->get(McpConsentLedger::class)->record(self::REQUEST_REF, $beUserUid, $scopes, ['main'], $client));
        return McpConsentLedger::consentRefHash(self::REQUEST_REF);
    }

    /** @param array<string, mixed> $call @param array<string, mixed> $begin */
    private function script(array $call, array $begin): void
    {
        $this->routes['#/mcp/connector/poll$#'] = fn (): array => [200, ['success' => true, 'calls' => [$call]]];
        $this->routes['#/mcp/connector/calls/ack$#'] = fn (): array => [200, ['success' => true, 'acknowledged' => [$call['id']]]];
        $this->routes['#/mcp/connector/calls/[^/]+/begin$#'] = fn (): array => [200, ['success' => true, 'remainingMs' => 20000, 'tool' => $call['tool']] + $begin];
        $this->routes['#/mcp/connector/calls/[^/]+/result$#'] = fn (): array => [200, ['success' => true]];
        $this->routes['#/mcp/connector/release$#'] = fn (): array => [200, ['success' => true, 'released' => true]];
    }

    /** @return array<string, mixed> */
    private function principal(int $beUserUid = 1, array $scopes = ['findings:read']): array
    {
        return [
            'tokenId' => self::TOKEN, 'tokenSuffix' => 'abcd', 'clientName' => 'Test agent', 'beUserUid' => $beUserUid, 'beUserName' => 'Eva',
            'scopes' => $scopes, 'siteIdentifiers' => ['main'], 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400),
            'consentRefHash' => McpConsentLedger::consentRefHash(self::REQUEST_REF),
        ];
    }

    /** Runs one scripted call through the real connector; returns the posted result. */
    private function runOnce(array $call, ?string $attestation, ?array $principal = null): array
    {
        $this->requests = [];
        $this->script($call, ['principal' => $principal ?? $this->principal(), 'attestation' => $attestation]);
        (new CommandTester($this->get(McpConnectorCommand::class)))->execute(['--once' => true, '--workers' => '1']);
        foreach ($this->requests as $request) {
            if (str_ends_with($request['path'], '/result')) {
                return $request['body'];
            }
        }
        self::fail('no result was posted');
    }

    private function lastAuditReason(): string
    {
        $rows = $this->get(ConnectionPool::class)->getConnectionForTable(Tables::MCP_AUDIT)
            ->select(['result', 'reason'], Tables::MCP_AUDIT, [], [], ['uid' => 'DESC'], 1)->fetchAllAssociative();
        return ($rows[0]['result'] ?? '') . ':' . ($rows[0]['reason'] ?? '');
    }

    private function attest(array $call, array $override = [], array $scopes = ['findings:read'], int $beUserUid = 1, string $client = self::CLIENT, string $tokenId = self::TOKEN): string
    {
        return $this->licenceApi->attest($call['id'], $call['tool'], $call['arguments'], $tokenId, McpConsentLedger::consentRefHash(self::REQUEST_REF),
            $beUserUid, $client, $scopes, ['main'], $override);
    }

    #[Test]
    public function aValidAttestationLetsTheCallReachTheModuleChecksAndAnInvalidOneRunsNothing(): void
    {
        $this->connectAttested();
        $this->consent();
        $call = ['id' => self::CALL, 'claimId' => 'cccccccc-0000-4000-8000-000000000001', 'tool' => 'aqg_whoami', 'arguments' => []];

        // Valid: passes V4; whatever the module then answers, it is not an attestation or consent refusal.
        $ok = $this->runOnce($call, $this->attest($call));
        self::assertDoesNotMatchRegularExpression('/^(attestation_|consent_)/', (string)($ok['error']['code'] ?? ''), json_encode($ok));

        $cases = [
            'attestation_missing' => null,
            'attestation_invalid' => (new McpAttestationFixture())->attest($call['id'], 'aqg_whoami', [], self::TOKEN, McpConsentLedger::consentRefHash(self::REQUEST_REF), 1, self::CLIENT, ['findings:read'], ['main']),
            'attestation_expired' => $this->attest($call, ['iat' => time() - 300, 'exp' => time() - 240]),
            'attestation_mismatch' => $this->attest(['id' => 'bbbbbbbb-0000-4000-8000-000000000009'] + $call),
        ];
        foreach ($cases as $expected => $attestation) {
            $result = $this->runOnce($call, $attestation);
            self::assertFalse($result['ok'], $expected);
            self::assertSame($expected, $result['error']['code'], $expected);
            self::assertSame('refused:' . $expected, $this->lastAuditReason(), 'audited: ' . $expected);
        }
        // Another installation's audience.
        $foreign = $this->licenceApi->attest($call['id'], 'aqg_whoami', [], self::TOKEN, McpConsentLedger::consentRefHash(self::REQUEST_REF), 1, self::CLIENT, ['findings:read'], ['main'], [], 'ffffffff-0000-4000-8000-000000000000');
        self::assertSame('attestation_invalid', $this->runOnce($call, $foreign)['error']['code']);
        // A tampered signature.
        $valid = $this->attest($call);
        self::assertSame('attestation_invalid', $this->runOnce($call, substr($valid, 0, -4) . 'AAAA')['error']['code']);
    }

    #[Test]
    public function argumentsChangedAfterAttestationAndReplayedAttestationsRunNothing(): void
    {
        $this->connectAttested();
        $this->consent(1, ['suggestions:prepare']);
        $attested = ['id' => self::CALL, 'claimId' => 'cccccccc-0000-4000-8000-000000000001', 'tool' => 'aqg_prepare_suggestions',
            'arguments' => ['siteIdentifier' => 'main', 'findingIds' => [5], 'pageUid' => null, 'languageUid' => -1, 'generate' => true]];
        $attestation = $this->attest($attested, [], ['suggestions:prepare']);
        $widened = $attested;
        $widened['arguments']['findingIds'] = [5, 6, 7];
        $principal = $this->principal(1, ['suggestions:prepare']);
        self::assertSame('attestation_args_mismatch', $this->runOnce($widened, $attestation, $principal)['error']['code']);
        $otherSite = $attested;
        $otherSite['arguments']['siteIdentifier'] = 'other';
        self::assertSame('attestation_args_mismatch', $this->runOnce($otherSite, $attestation, $principal)['error']['code']);
        // Key order of the relayed arguments does not matter (the attested ones are executed).
        $reordered = $attested;
        $reordered['arguments'] = array_reverse($attested['arguments'], true);
        $first = $this->runOnce($reordered, $attestation, $principal);
        self::assertDoesNotMatchRegularExpression('/^(attestation_|consent_)/', (string)($first['error']['code'] ?? ''), json_encode($first));
        self::assertSame('attestation_replayed', $this->runOnce($attested, $attestation, $principal)['error']['code']);
    }

    #[Test]
    public function theLedgerDecidesUserClientGrantTokenAndLocalRevocation(): void
    {
        $this->connectAttested();
        $this->consent();
        $call = ['id' => self::CALL, 'claimId' => 'cccccccc-0000-4000-8000-000000000001', 'tool' => 'aqg_whoami', 'arguments' => []];
        self::assertSame('consent_unknown', $this->runOnce($call, $this->attest($call, [], ['findings:read'], 2), $this->principal(2))['error']['code'], 'another user');
        self::assertSame('consent_unknown', $this->runOnce($call, $this->attest($call, [], ['findings:read'], 1, 'another-client'))['error']['code'], 'another agent client');
        self::assertSame('consent_unknown', $this->runOnce($call, $this->attest($call, [], ['findings:read', 'content:read']))['error']['code'], 'a wider grant');
        // First use binds the consent to the token; a second token for the same consent is refused.
        $first = $this->runOnce($call, $this->attest($call));
        self::assertDoesNotMatchRegularExpression('/^(attestation_|consent_)/', (string)($first['error']['code'] ?? ''));
        $otherToken = 'abcdef01-0000-4000-8000-000000000002';
        self::assertSame('consent_token_mismatch', $this->runOnce($call, $this->attest($call, [], ['findings:read'], 1, self::CLIENT, $otherToken), ['tokenId' => $otherToken] + $this->principal())['error']['code']);
        // Revoked here: refused whatever the Gateway says.
        $ledger = $this->get(McpConsentLedger::class);
        self::assertTrue($ledger->isTokenOfUser(self::TOKEN, 1));
        self::assertFalse($ledger->isTokenOfUser(self::TOKEN, 2));
        $ledger->revokeToken(self::TOKEN);
        self::assertSame('token_revoked', $this->runOnce($call, $this->attest($call))['error']['code']);
        // F1: revoking a connection locally by the consent the Gateway listed, before the token ever ran, holds too.
        $ledger->revokeConnection('abcdef01-0000-4000-8000-0000000000ff', ['consentRefHash' => McpConsentLedger::consentRefHash(self::REQUEST_REF)], 1);
        self::assertTrue($ledger->isTokenRevoked('abcdef01-0000-4000-8000-0000000000ff'));
        $row = $this->get(ConnectionPool::class)->getConnectionForTable(Tables::MCP_CONSENT)->select(['revoked'], Tables::MCP_CONSENT, ['be_user_uid' => 1])->fetchOne();
        self::assertSame(1, (int)$row, 'the consent itself is revoked: no other token of it can run');
        $ledger->revokeConnection('abcdef01-0000-4000-8000-0000000000fe', ['consentRefHash' => McpConsentLedger::consentRefHash(self::REQUEST_REF)], 2);
        self::assertSame(1, (int)$this->get(ConnectionPool::class)->getConnectionForTable(Tables::MCP_CONSENT)->count('uid', Tables::MCP_CONSENT, ['revoked' => 1]), 'another user\'s revocation reaches only that user\'s rows');
        $this->get(ConnectionPool::class)->getConnectionForTable(Tables::MCP_CONSENT)->update(Tables::MCP_CONSENT, ['revoked' => 0], ['be_user_uid' => 1]);
        // An unsigned consent (recorded by an earlier build) is never enough.
        $this->get(ConnectionPool::class)->getConnectionForTable(Tables::MCP_CONSENT)->update(Tables::MCP_CONSENT, ['signed' => 0, 'token_id' => ''], ['be_user_uid' => 1]);
        $this->get(ConnectionPool::class)->getConnectionForTable(Tables::MCP_TOKEN_REVOCATION)->truncate(Tables::MCP_TOKEN_REVOCATION);
        self::assertSame('consent_unknown', $this->runOnce($call, $this->attest($call))['error']['code']);
    }

    #[Test]
    public function aConnectionWithoutAttestationTrustRunsNothing(): void
    {
        $store = $this->get(McpConnectionStore::class);
        self::assertTrue($store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example', 'admin'));
        self::assertTrue($store->completePairingIfCurrent(self::PAIRING, self::SECRET, 'slug', 'https://gw.example/mcp/i/slug'));
        $store->markActivatedIfCurrent($store->get()['credentialId']);
        $this->consent();
        $call = ['id' => self::CALL, 'claimId' => 'cccccccc-0000-4000-8000-000000000001', 'tool' => 'aqg_whoami', 'arguments' => []];
        self::assertSame('attestation_untrusted', $this->runOnce($call, $this->attest($call))['error']['code']);
        self::assertSame('', $store->installationSigningKey(), 'no key: the consent page cannot sign');
    }

    #[Test]
    public function pairingCompletesOnlyWithTheLicenceApisReceiptForThisInstallationsKey(): void
    {
        $this->routes['#/licence/mcp/attestation-keys$#'] = fn (): array => [200, ['success' => true, 'keys' => $this->licenceApi->pinned(), 'keySet' => $this->licenceApi->keySet()]];
        $pinned = $this->get(McpAttestationKeys::class)->keysForPairing();
        self::assertSame($this->licenceApi->pinned(), $pinned);
        // A configured trust root refuses a key set signed by any other key.
        putenv(McpAttestationKeys::TRUST_ENV . '=' . (new McpAttestationFixture())->x);
        try {
            $this->get(McpAttestationKeys::class)->keysForPairing();
            self::fail('a key set from an untrusted key was accepted');
        } catch (\Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException $exception) {
            self::assertSame('attestation_keys_untrusted', $exception->apiErrorCode);
        }
        putenv(McpAttestationKeys::TRUST_ENV . '=' . $this->licenceApi->x);
        self::assertSame($this->licenceApi->pinned(), $this->get(McpAttestationKeys::class)->keysForPairing());
        putenv(McpAttestationKeys::TRUST_ENV);

        foreach (['missing', 'swapped', 'valid'] as $case) {
            $store = $this->get(McpConnectionStore::class);
            $store->resetLocal('test');
            $key = McpInstallationKey::generate();
            self::assertTrue($store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example', 'admin', $key + ['pinnedKeys' => $pinned]));
            $receiptKey = $case === 'swapped' ? McpInstallationKey::generate()['publicKey'] : $key['publicKey'];
            $this->routes['#/mcp/pairing/[^/]+/complete$#'] = fn (): array => [200, ['success' => true, 'status' => 'completed', 'connectorSecret' => self::SECRET,
                'installationId' => McpAttestationFixture::INSTALLATION_ID, 'slug' => 'slug', 'serverUrl' => 'https://gw.example/mcp/i/slug']
                + ($case === 'missing' ? [] : ['binding' => $this->licenceApi->binding(McpAttestationFixture::INSTALLATION_ID, self::PAIRING, $receiptKey)])];
            $this->routes['#/mcp/connector/disconnect$#'] = fn (): array => [200, ['success' => true]];
            $this->routes['#/mcp/connector/activate$#'] = fn (): array => [200, ['success' => true, 'status' => 'active']];
            $this->requests = [];
            $outcome = $this->get(McpPairingService::class)->poll();
            if ($case === 'valid') {
                self::assertSame('connected', $outcome);
                self::assertSame(McpAttestationFixture::INSTALLATION_ID, $store->attestationTrust()['installationId']);
                self::assertSame($key['publicKey'], McpInstallationKey::publicKeyOf($store->installationSigningKey()));
            } else {
                self::assertSame('cancelled', $outcome, $case);
                self::assertNotSame('connected', $store->get()['status'], $case);
                self::assertContains('/mcp/connector/disconnect', array_column($this->requests, 'path'), $case . ': the unusable Gateway installation is discarded');
            }
        }
    }

    #[Test]
    public function keySetsRotateOnlyWhenSignedByAPinnedKey(): void
    {
        $store = $this->connectAttested();
        $next = new McpAttestationFixture();
        $stranger = new McpAttestationFixture();
        // A key set signed by an unknown key changes nothing.
        $this->routes['#/licence/mcp/attestation-keys$#'] = fn (): array => [200, ['success' => true, 'keySet' => $stranger->keySet()]];
        self::assertFalse($this->get(McpAttestationKeys::class)->refresh());
        self::assertSame($this->licenceApi->pinned(), $store->attestationTrust()['pinnedKeys']);
        // Step 1: the current key announces the next one; step 2: the next key signs a set of its own.
        $this->routes['#/licence/mcp/attestation-keys$#'] = fn (): array => [200, ['success' => true, 'keySet' => $this->licenceApi->keySet([...$this->licenceApi->pinned(), ...$next->pinned()])]];
        self::assertTrue($this->get(McpAttestationKeys::class)->refresh());
        $this->routes['#/licence/mcp/attestation-keys$#'] = fn (): array => [200, ['success' => true, 'keySet' => $next->keySet()]];
        self::assertTrue($this->get(McpAttestationKeys::class)->refresh());
        self::assertSame($next->pinned(), $store->attestationTrust()['pinnedKeys']);
        // A replayed older key set (signed by a key still pinned) never rolls the pins back.
        $old = $next->sign(['typ' => 'aqg-mcp-keyset', 'iss' => 'aqg-licence-api', 'iat' => time() - 3600, 'keys' => [...$this->licenceApi->pinned(), ...$next->pinned()]]);
        $this->routes['#/licence/mcp/attestation-keys$#'] = fn (): array => [200, ['success' => true, 'keySet' => $old]];
        self::assertFalse($this->get(McpAttestationKeys::class)->refresh());
        self::assertSame($next->pinned(), $store->attestationTrust()['pinnedKeys']);
    }

    #[Test]
    public function theSignedConsentVerifiesAgainstTheInstallationKeyAndBindsTheAgentsPkceChallenge(): void
    {
        $key = McpInstallationKey::generate();
        $signed = McpInstallationKey::signConsent($key['secretKey'], McpAttestationFixture::INSTALLATION_ID, self::REQUEST_REF, 7, self::CLIENT,
            'http://127.0.0.1:33418/callback', str_repeat('A', 43), ['suggestions:prepare', 'content:read'], ['main'], 7);
        $payload = \Priebera\A11yQualityGate\Mcp\McpAttestation::base64UrlDecode($signed['payload']);
        $signature = \Priebera\A11yQualityGate\Mcp\McpAttestation::base64UrlDecode($signed['signature']);
        $publicKey = \Priebera\A11yQualityGate\Mcp\McpAttestation::base64UrlDecode($key['publicKey']);
        self::assertTrue(sodium_crypto_sign_verify_detached($signature, $payload, $publicKey));
        $claims = json_decode($payload, true);
        self::assertSame('aqg-mcp-consent', $claims['typ']);
        self::assertSame(['content:read', 'suggestions:prepare'], $claims['scopes']);
        self::assertSame(McpConsentLedger::consentRefHash(self::REQUEST_REF), $claims['requestRefHash']);
        self::assertSame(str_repeat('A', 43), $claims['codeChallenge']);
        self::assertLessThanOrEqual(600, $claims['exp'] - $claims['iat']);
    }
}
