<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Mcp;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\ProCrawlerAjaxController;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Mcp\Http\McpGatewayClient;
use Priebera\A11yQualityGate\Mcp\Intelligence\McpIntelligenceTools;
use Priebera\A11yQualityGate\Mcp\McpAttestation;
use Priebera\A11yQualityGate\Mcp\McpAttestationKeys;
use Priebera\A11yQualityGate\Mcp\McpAuditRepository;
use Priebera\A11yQualityGate\Mcp\McpCallRefused;
use Priebera\A11yQualityGate\Mcp\McpCallRunner;
use Priebera\A11yQualityGate\Mcp\McpConnectionStore;
use Priebera\A11yQualityGate\Mcp\McpConsentLedger;
use Priebera\A11yQualityGate\Mcp\McpDeferredAuthorization;
use Priebera\A11yQualityGate\Mcp\McpDeferredWork;
use Priebera\A11yQualityGate\Mcp\McpHostKey;
use Priebera\A11yQualityGate\Mcp\McpInstallationKey;
use Priebera\A11yQualityGate\Mcp\McpScanRequestStore;
use Priebera\A11yQualityGate\Mcp\McpSiteSettingsRepository;
use Priebera\A11yQualityGate\Mcp\McpToolExecutor;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\RemoteResultAuthorizationService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Priebera\A11yQualityGate\Tests\Support\McpAttestationFixture;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Release-gate finding 2, regression cases D1–D14 of the contract: a call's deferred AI request runs only on the AQG
 * licence API's single-use continuation of exactly that call and item (backend migration 103), verified with the pinned
 * keys, and on this installation's consent, revocation, grant and site capability read right before the claim.
 *
 * Everything below the HTTP client is real: McpCallRunner, McpGatewayClient, V4 attestation and continuation
 * verification (real Ed25519, McpAttestationFixture as the licence API key), consent ledger, AI Fixes workflow and its
 * claim, AiStructuredTaskRunner, AiUsageRecorder (tx_a11y_ai_usage), the OpenAI provider and the MCP audit. The Gateway
 * and core are scripted here after aqg-api's continueCall() (consent-authority.ts: token revoked/expired, once per call
 * jti, ≤ 30 s, bound to call, jti, token, user, consent, client, scopes, sites and subject); the AI provider is a QA
 * stand-in answering at the HTTP layer — no real AI request, no charge. Licence and AI configuration are test doubles.
 */
final class McpDeferredContinuationFunctionalTest extends AbstractFunctionalTestCase
{
    use McpRemediationFixture;

    private const SECRET = 'aqgc_' . 'A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8S9t0U1v';
    private const PAIRING = '11111111-2222-4333-8444-555555555555';
    private const CLIENT = 'mcp_client_test_0001';
    private const REF_A = 'aqgr_' . 'consentReferenceOfTheAuthorizationRequestA1';
    private const REF_A2 = 'aqgr_' . 'consentReferenceOfTheAuthorizationRequestA2';
    private const REF_B = 'aqgr_' . 'consentReferenceOfTheAuthorizationRequestB1';
    private const TOKEN_A = 'abcdef01-0000-4000-8000-00000000000a';
    private const TOKEN_A2 = 'abcdef01-0000-4000-8000-0000000000a2';
    private const TOKEN_B = 'abcdef01-0000-4000-8000-00000000000b';

    private McpAttestationFixture $licenceApi;
    /** @var array<string, true> tokens core treats as revoked */
    private array $coreRevoked = [];
    /** @var array<string, true> call jtis core continued (mcp_call_continuations) */
    private array $continued = [];
    /** @var array<string, mixed> overrides for the continuation core signs */
    private array $continuationOverride = [];
    /** @var (\Closure(array<string, mixed>): ?array{0: int, 1: array<string, mixed>})|null replaces core's answer */
    private ?\Closure $coreAnswer = null;
    /** @var (\Closure(): void)|null runs after core recorded the continuation, before it answers */
    private ?\Closure $afterContinuation = null;
    /** @var (\Closure(): void)|null runs while the provider request is in flight */
    private ?\Closure $duringProvider = null;
    /** @var (\Closure(): void)|null runs when a call's result is posted (before its deferred job) */
    private ?\Closure $onResult = null;
    private int $providerRequests = 0;
    /** @var list<array<string, mixed>> */
    private array $continueRequests = [];
    /** @var array<string, mixed> what the next begin answers */
    private array $begin = [];
    /** @var array<string, mixed> */
    private array $posted = [];
    private McpDeferredWork $deferred;
    private McpCallRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemediationFixture();
        putenv(McpHostKey::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
        $this->licenceApi = new McpAttestationFixture();
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = HandlerStack::create(fn (RequestInterface $request) => $this->answer($request));
        $this->connectAttested();
        $ledger = $this->get(McpConsentLedger::class);
        self::assertTrue($ledger->record(self::REF_A, self::ADMIN, self::SCOPES, ['client-a'], self::CLIENT));
        self::assertTrue($ledger->record(self::REF_B, self::EDITOR_B, self::SCOPES, ['client-a'], self::CLIENT));
        [$this->runner, $this->deferred] = $this->connector();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']);
        putenv(McpHostKey::ENVIRONMENT_VARIABLE);
        putenv(McpAttestationKeys::TRUST_ENV);
        @unlink($this->get(McpHostKey::class)->path());
        parent::tearDown();
    }

    // ---------------------------------------------------------------- harness

    private function connectAttested(): void
    {
        $store = $this->get(McpConnectionStore::class);
        $key = McpInstallationKey::generate();
        self::assertTrue($store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin',
            $key + ['pinnedKeys' => $this->licenceApi->pinned()]));
        self::assertTrue($store->completePairingIfCurrent(self::PAIRING, self::SECRET, 'slug', 'https://gw.example/mcp/i/slug', McpAttestationFixture::INSTALLATION_ID));
        self::assertTrue($store->markActivatedIfCurrent($store->get()['credentialId']));
    }

    /**
     * One connector process: runner, executor, suggestion tools and their deferred work, the container's Gateway
     * client, attestation, ledger, audit and McpDeferredAuthorization.
     *
     * @return array{0: McpCallRunner, 1: McpDeferredWork}
     */
    private function connector(): array
    {
        $deferred = new McpDeferredWork();
        $tools = $this->suggestionTools($deferred, null, $this->get(McpDeferredAuthorization::class));
        $userContext = $this->userContextResetting($this->access, $this->editorAiAccess);
        $executor = new McpToolExecutor(
            $userContext,
            $this->get(McpSiteSettingsRepository::class),
            $this->get(ScopeAccessService::class),
            $this->get(AccessControlService::class),
            $this->get(SiteResolutionService::class),
            $this->get(RemoteScanRepository::class),
            $this->get(RemoteResultAuthorizationService::class),
            $this->get(ConnectionPool::class),
            $this->get(McpScanRequestStore::class),
            $this->get(ProCrawlerAjaxController::class),
            $this->get(McpIntelligenceTools::class),
            $tools,
        );
        $runner = new McpCallRunner($this->get(McpGatewayClient::class), $executor, $this->get(McpAuditRepository::class), $this->get(McpConsentLedger::class),
            $this->get(LogManager::class), $deferred, $this->get(McpAttestation::class), $this->get(McpConnectionStore::class));

        return [$runner, $deferred];
    }

    private function answer(RequestInterface $request): mixed
    {
        $json = static fn (int $status, array $data): Response => new Response($status, ['Content-Type' => 'application/json'], (string)json_encode($data, JSON_UNESCAPED_SLASHES));
        if (strtolower($request->getUri()->getHost()) === 'api.openai.com') {
            $this->providerRequests++;
            if ($this->duringProvider !== null) {
                ($this->duringProvider)();
            }
            return Create::promiseFor($json(200, self::qaProviderAnswer((string)$request->getBody())));
        }
        $path = $request->getUri()->getPath();
        $body = json_decode((string)$request->getBody(), true) ?: [];
        if (preg_match('#/mcp/connector/calls/([^/]+)/begin$#', $path) === 1) {
            return Create::promiseFor($json(200, ['success' => true, 'remainingMs' => 20000] + $this->begin));
        }
        if (preg_match('#/mcp/connector/calls/([^/]+)/result$#', $path) === 1) {
            $this->posted = $body;
            if ($this->onResult !== null) {
                $hook = $this->onResult;
                $this->onResult = null;
                $hook();
            }
            return Create::promiseFor($json(200, ['success' => true]));
        }
        if (preg_match('#/mcp/connector/calls/([^/]+)/continue$#', $path, $match) === 1) {
            $this->continueRequests[] = $body + ['callId' => $match[1]];
            if ($this->coreAnswer !== null && ($answer = ($this->coreAnswer)($body)) !== null) {
                return $answer instanceof \Throwable ? Create::rejectionFor($answer) : Create::promiseFor($json($answer[0], $answer[1]));
            }
            [$status, $data] = $this->core($match[1], $body);
            return Create::promiseFor($json($status, $data));
        }

        return Create::promiseFor($json(404, ['success' => false, 'error' => ['code' => 'route_not_found']]));
    }

    /**
     * aqg-api continueCall() as scripted core: its own attestation for this call, token not revoked, once per call jti,
     * then a ≤ 30 s continuation bound to the call, the token's grant and the subject.
     *
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function core(string $callId, array $body): array
    {
        $refuse = static fn (string $code): array => [403, ['success' => false, 'error' => ['code' => $code, 'message' => 'refused']]];
        $parts = explode('.', (string)($body['attestation'] ?? ''));
        $claims = count($parts) === 3 ? json_decode((string)McpAttestation::base64UrlDecode($parts[1]), true) : null;
        if (!is_array($claims) || ($claims['callId'] ?? null) !== $callId || McpAttestation::verifyJws((string)$body['attestation'], $this->licenceApi->pinned()) === null
            || ($body['purpose'] ?? '') !== 'deferred_generation') {
            return $refuse('continuation_refused');
        }
        if (isset($this->coreRevoked[$claims['tokenId']])) {
            return $refuse('token_revoked');
        }
        if (isset($this->continued[$claims['jti']])) {
            return $refuse('continuation_used');
        }
        $this->continued[$claims['jti']] = true;
        if ($this->afterContinuation !== null) {
            ($this->afterContinuation)();
        }
        $now = time();
        $continuation = $this->licenceApi->sign($this->continuationOverride + [
            'typ' => 'aqg-mcp-continuation', 'iss' => McpAttestation::ISSUER, 'aud' => McpAttestationFixture::INSTALLATION_ID, 'sub' => $claims['tokenId'],
            'jti' => McpAttestation::base64UrlEncode(random_bytes(18)), 'iat' => $now, 'exp' => $now + 30, 'purpose' => 'deferred_generation',
            'subject' => (string)$body['subject'], 'callId' => $callId, 'callJti' => $claims['jti'], 'tool' => $claims['tool'], 'tokenId' => $claims['tokenId'],
            'consentRefHash' => $claims['consentRefHash'], 'beUserUid' => $claims['beUserUid'], 'clientId' => $claims['clientId'],
            'scopes' => $claims['scopes'], 'sites' => $claims['sites'], 'tokenExpiresAt' => $now + 3600,
        ]);

        return [200, ['success' => true, 'continuation' => $continuation, 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $now + 30)]];
    }

    /**
     * One attested agent call through the runner; returns the posted result.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments, int $beUserUid = self::ADMIN, string $tokenId = self::TOKEN_A, string $ref = self::REF_A, ?McpCallRunner $runner = null): array
    {
        $callId = self::uuid();
        $arguments += ['siteIdentifier' => 'client-a'];
        $this->begin = [
            'tool' => $tool,
            'attestation' => $this->licenceApi->attest($callId, $tool, $arguments, $tokenId, McpConsentLedger::consentRefHash($ref), $beUserUid, self::CLIENT, self::SCOPES, ['client-a']),
            'principal' => ['tokenId' => $tokenId, 'tokenSuffix' => substr($tokenId, -4), 'clientName' => 'Test agent', 'beUserUid' => $beUserUid, 'beUserName' => 'user' . $beUserUid,
                'scopes' => self::SCOPES, 'siteIdentifiers' => ['client-a'], 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400), 'consentRefHash' => McpConsentLedger::consentRefHash($ref)],
        ];
        $this->posted = [];
        ($runner ?? $this->runner)->run(['id' => $callId, 'claimId' => self::uuid(), 'tool' => $tool, 'arguments' => $arguments]);

        return $this->posted;
    }

    /** @return array<string, mixed> the first result entry of a prepare answer */
    private function prepareA0(int $beUserUid = self::ADMIN, string $tokenId = self::TOKEN_A, string $ref = self::REF_A, string $finding = 'a0'): array
    {
        $posted = $this->call('aqg_prepare_suggestions', ['findingIds' => [$this->issues[$finding]]], $beUserUid, $tokenId, $ref);
        self::assertTrue($posted['ok'] ?? false, json_encode($posted));

        return $posted['result']['results'][0];
    }

    private function itemOf(string $finding = 'a0'): array
    {
        return (array)$this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remediation_item')
            ->fetchAssociative('SELECT * FROM tx_a11y_remediation_item WHERE finding_uid = ? ORDER BY uid DESC', [$this->issues[$finding]]);
    }

    /** @return list<string> "result:reason" of the deferred_generation audit rows, oldest first */
    private function deferredAudit(): array
    {
        $rows = $this->get(ConnectionPool::class)->getConnectionForTable(Tables::MCP_AUDIT)
            ->select(['result', 'reason'], Tables::MCP_AUDIT, ['action' => 'deferred_generation'], [], ['uid' => 'ASC'])->fetchAllAssociative();

        return array_map(static fn (array $row): string => $row['result'] . ':' . $row['reason'], $rows);
    }

    private function usageRows(): int
    {
        return $this->rowCount('tx_a11y_ai_usage');
    }

    private function assertNothingGenerated(string $expectedAudit, string $finding = 'a0'): void
    {
        self::assertSame(0, $this->providerRequests, 'No provider request.');
        self::assertSame(0, $this->usageRows(), 'No AI usage row.');
        $item = $this->itemOf($finding);
        self::assertSame(RemediationStatus::PREPARING, (string)$item['status'], 'The item stays Preparing.');
        self::assertNotSame('generating', (string)$item['status_reason'], 'Not claimed.');
        self::assertSame([$expectedAudit], $this->deferredAudit());
    }

    /** @param array<string, mixed> $payload */
    private static function qaProviderAnswer(string $requestBody): array
    {
        $payload = json_decode($requestBody, true) ?: [];
        $fill = static function (array $schema) use (&$fill): array {
            $out = [];
            foreach ((array)($schema['properties'] ?? []) as $name => $property) {
                $type = $property['type'] ?? 'string';
                $out[$name] = match (true) {
                    isset($property['enum']) => $property['enum'][0],
                    $type === 'boolean' => false,
                    $type === 'object' => $fill($property),
                    $type === 'array' => [],
                    $type === 'integer', $type === 'number' => 0,
                    default => 'QA mock ' . str_replace('_', ' ', (string)$name),
                };
            }
            return $out;
        };
        $text = (string)json_encode($fill($payload['text']['format']['schema'] ?? []), JSON_UNESCAPED_UNICODE);

        return ['id' => 'resp_qa', 'object' => 'response', 'status' => 'completed', 'model' => $payload['model'] ?? '',
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]]],
            'output_text' => $text, 'usage' => ['input_tokens' => 111, 'output_tokens' => 22, 'total_tokens' => 133]];
    }

    private static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));

        return sprintf('%s-%s-4%s-8%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), substr($hex, 17, 3), substr($hex, 20, 12));
    }

    // ---------------------------------------------------------------- cases

    #[Test]
    public function d1AValidContinuationGeneratesOnceAndIsRecorded(): void
    {
        $result = $this->prepareA0();

        self::assertSame('scheduled', $result['generation']);
        self::assertSame(1, count($this->continueRequests), 'One continuation per job.');
        self::assertSame('item:' . $result['item']['itemId'], $this->continueRequests[0]['subject']);
        self::assertSame('deferred_generation', $this->continueRequests[0]['purpose']);
        self::assertSame($this->begin['attestation'], $this->continueRequests[0]['attestation'], 'The call attestation exactly as begin returned it.');
        self::assertSame(1, $this->providerRequests);
        self::assertSame(1, $this->usageRows(), 'AI usage is recorded for the one request.');
        self::assertSame(RemediationStatus::PENDING_REVIEW, (string)$this->itemOf()['status']);
        self::assertSame(['deferred_generated:'], $this->deferredAudit());
        // Call attestation + continuation, each once in the replay table.
        self::assertSame(2, $this->rowCount(Tables::MCP_ATTESTATION));
    }

    #[Test]
    public function d2ARevocationBeforeTheJobGeneratesNothing(): void
    {
        $this->coreAnswer = fn (): array => [403, ['success' => false, 'error' => ['code' => 'token_revoked']]];
        self::assertSame('scheduled', $this->prepareA0()['generation']);
        $this->assertNothingGenerated('deferred_refused:token_revoked');
    }

    #[Test]
    public function d3ACapabilitySwitchedOffBeforeTheJobGeneratesNothing(): void
    {
        $this->afterContinuation = fn () => $this->get(McpSiteSettingsRepository::class)->save('client-a', true, ['findings:read', 'content:read'], 1);
        $this->prepareA0();
        $this->assertNothingGenerated('deferred_refused:capability_off');
    }

    #[Test]
    public function d4AgentAccessSwitchedOffForTheSiteGeneratesNothing(): void
    {
        $this->afterContinuation = fn () => $this->get(McpSiteSettingsRepository::class)->save('client-a', false, self::SCOPES, 1);
        $this->prepareA0();
        $this->assertNothingGenerated('deferred_refused:site_not_available');
    }

    #[Test]
    public function d5AnExpiredTokenGeneratesNothingWhetherCoreOrTheContinuationSaysSo(): void
    {
        $this->coreAnswer = fn (): array => [403, ['success' => false, 'error' => ['code' => 'token_expired']]];
        $this->prepareA0();
        $this->assertNothingGenerated('deferred_refused:token_expired');

        // A valid continuation whose token end has passed.
        $this->coreAnswer = null;
        $this->continuationOverride = ['tokenExpiresAt' => time() - 1];
        $this->call('aqg_get_suggestion_status', ['findingIds' => [$this->issues['a0']]]);
        self::assertSame(0, $this->providerRequests);
        self::assertSame(['deferred_refused:token_expired', 'deferred_refused:token_expired'], $this->deferredAudit());
    }

    #[Test]
    public function d6AReplacedConsentStopsTheOldCallsJobAndTheNewTokenGenerates(): void
    {
        // The user consents again: the earlier connection is revoked here and at the Gateway after the call answered.
        $this->onResult = function (): void {
            $ledger = $this->get(McpConsentLedger::class);
            $ledger->revokeConsent(McpConsentLedger::consentRefHash(self::REF_A), self::ADMIN);
            self::assertTrue($ledger->record(self::REF_A2, self::ADMIN, self::SCOPES, ['client-a'], self::CLIENT));
        };
        $this->prepareA0();
        $this->assertNothingGenerated('deferred_refused:consent_revoked');

        // The new token's own call continues the item normally.
        $this->call('aqg_get_suggestion_status', ['findingIds' => [$this->issues['a0']]], self::ADMIN, self::TOKEN_A2, self::REF_A2);
        self::assertSame(1, $this->providerRequests);
        self::assertSame(RemediationStatus::PENDING_REVIEW, (string)$this->itemOf()['status']);
        self::assertSame(['deferred_refused:consent_revoked', 'deferred_generated:'], $this->deferredAudit());
    }

    #[Test]
    public function d7ALocalRevocationRefusesAlthoughCoreContinued(): void
    {
        $this->afterContinuation = fn () => $this->get(McpConsentLedger::class)->revokeToken(self::TOKEN_A);
        $this->prepareA0();
        $this->assertNothingGenerated('deferred_refused:token_revoked');
    }

    #[Test]
    public function d8TheSameContinuationPresentedTwiceIsRefusedTheSecondTime(): void
    {
        $this->prepareA0();
        self::assertSame(1, $this->providerRequests);
        // Core (misbehaving or replayed by a Gateway) hands the same continuation out again for a retried job.
        $issued = [];
        $context = null;
        $this->coreAnswer = function (array $body) use (&$issued): ?array {
            $issued[$body['subject']] ??= $this->core($this->continueRequests[count($this->continueRequests) - 1]['callId'], $body);
            return $issued[$body['subject']];
        };
        $authorization = $this->get(McpDeferredAuthorization::class);
        $callId = self::uuid();
        $attestation = $this->licenceApi->attest($callId, 'aqg_get_suggestion_status', [], self::TOKEN_A, McpConsentLedger::consentRefHash(self::REF_A), self::ADMIN, self::CLIENT, self::SCOPES, ['client-a']);
        $claims = json_decode((string)McpAttestation::base64UrlDecode(explode('.', $attestation)[1]), true);
        $context = ['callId' => $callId, 'attestation' => $attestation, 'deadline' => microtime(true) + 10, 'claims' => [
            'jti' => $claims['jti'], 'callId' => $callId, 'tool' => 'aqg_get_suggestion_status', 'tokenId' => self::TOKEN_A, 'beUserUid' => self::ADMIN,
            'consentRefHash' => McpConsentLedger::consentRefHash(self::REF_A), 'clientId' => self::CLIENT, 'scopes' => self::SCOPES, 'sites' => ['client-a']]];
        $first = $authorization->continuation($context, 'item:1');
        self::assertSame(self::TOKEN_A, $first['tokenId']);
        try {
            $authorization->continuation($context, 'item:1');
            self::fail('A continuation is used once.');
        } catch (McpCallRefused $refused) {
            self::assertSame('continuation_replayed', $refused->reason);
        }
        self::assertSame(1, $this->providerRequests, 'One generate() in total.');
    }

    #[Test]
    public function d9TwoWorkersWithTwoValidContinuationsMakeOneProviderRequest(): void
    {
        // Worker 2 (another connector process: its own runner, tools and deferred work) runs a status call for the same
        // item between worker 1's answer and worker 1's job; both get a valid continuation of their own call.
        $this->call('aqg_prepare_suggestions', ['findingIds' => [$this->issues['a0']], 'generate' => false]);
        [$worker2] = $this->connector();
        [$worker1] = $this->connector();
        $this->onResult = function () use ($worker2): void {
            $this->call('aqg_get_suggestion_status', ['findingIds' => [$this->issues['a0']]], self::ADMIN, self::TOKEN_A, self::REF_A, $worker2);
        };
        $this->call('aqg_get_suggestion_status', ['findingIds' => [$this->issues['a0']]], self::ADMIN, self::TOKEN_A, self::REF_A, $worker1);

        self::assertCount(2, $this->continueRequests, 'Both jobs were continued by core.');
        self::assertSame(1, $this->providerRequests, 'One provider request.');
        self::assertSame(1, $this->usageRows(), 'One charge.');
        self::assertSame(['deferred_generated:', 'deferred_skipped:not_needed'], $this->deferredAudit());
        self::assertSame(RemediationStatus::PENDING_REVIEW, (string)$this->itemOf()['status']);
    }

    #[Test]
    public function d10AContinuationForAnythingElseIsRefusedWithItsCode(): void
    {
        $cases = [
            'continuation_mismatch' => [['subject' => 'item:999'], ['callJti' => 'otherCallJti0000000001'], ['tokenId' => self::TOKEN_B], ['beUserUid' => self::EDITOR_B],
                ['consentRefHash' => str_repeat('b', 64)], ['clientId' => 'other_client'], ['callId' => self::uuid()], ['tool' => 'aqg_prepare_suggestions'], ['purpose' => 'other']],
            'continuation_invalid' => [['aud' => 'ffffffff-0000-4000-8000-000000000000'], ['typ' => 'aqg-mcp-call'], ['iss' => 'someone'], ['exp' => time() + 120]],
            'continuation_expired' => [['iat' => time() - 100, 'exp' => time() - 70]],
        ];
        $this->prepareItemWithoutGeneration();
        $expected = [];
        foreach ($cases as $code => $overrides) {
            foreach ($overrides as $override) {
                $this->continuationOverride = $override;
                // A status call (aqg_get_suggestion_status) continues the item each time; tool 'aqg_prepare_suggestions'
                // in the continuation then names another tool than the call.
                $this->call('aqg_get_suggestion_status', ['findingIds' => [$this->issues['a0']]]);
                $expected[] = 'deferred_refused:' . $code;
            }
        }
        // An unpinned key.
        $this->continuationOverride = [];
        $foreign = new McpAttestationFixture();
        $this->coreAnswer = fn (array $body): array => [200, ['success' => true, 'continuation' => $foreign->sign(['typ' => 'aqg-mcp-continuation', 'iss' => McpAttestation::ISSUER,
            'aud' => McpAttestationFixture::INSTALLATION_ID, 'iat' => time(), 'exp' => time() + 30]), 'expiresAt' => '']];
        $this->call('aqg_get_suggestion_status', ['findingIds' => [$this->issues['a0']]]);
        $expected[] = 'deferred_refused:continuation_invalid';

        self::assertSame(0, $this->providerRequests);
        self::assertSame(0, $this->usageRows());
        self::assertSame($expected, array_slice($this->deferredAudit(), -count($expected)));
    }

    protected function prepareItemWithoutGeneration(): void
    {
        $posted = $this->call('aqg_prepare_suggestions', ['findingIds' => [$this->issues['a0']], 'generate' => false]);
        self::assertTrue($posted['ok'], json_encode($posted));
    }

    #[Test]
    public function d11AnUnreachableOrOlderBackendGeneratesNothingAndALaterCallContinues(): void
    {
        $this->coreAnswer = fn (array $body) => new ConnectException('down', new \GuzzleHttp\Psr7\Request('POST', 'https://api.invalid/'));
        $this->prepareA0();
        $this->assertNothingGenerated('deferred_refused:continuation_unavailable');
        // A backend before migration 103: the route does not exist.
        $this->coreAnswer = fn (): array => [404, ['success' => false, 'error' => ['code' => 'route_not_found']]];
        $this->call('aqg_get_suggestion_status', ['findingIds' => [$this->issues['a0']]]);
        self::assertSame(0, $this->providerRequests);
        // Core answers 5xx.
        $this->coreAnswer = fn (): array => [503, ['success' => false, 'error' => ['code' => 'core_unavailable']]];
        $this->call('aqg_get_suggestion_status', ['findingIds' => [$this->issues['a0']]]);
        self::assertSame(0, $this->providerRequests);
        self::assertCount(3, $this->continueRequests, 'Never retried within a job.');

        // Back: a later status call schedules it again under a new call and attestation.
        $this->coreAnswer = null;
        $status = $this->call('aqg_get_suggestion_status', ['findingIds' => [$this->issues['a0']]]);
        self::assertSame('scheduled', $status['result']['entries'][0]['item']['generation']);
        self::assertSame(1, $this->providerRequests);
        self::assertSame(['deferred_refused:continuation_unavailable', 'deferred_refused:continuation_unavailable', 'deferred_refused:continuation_unavailable', 'deferred_generated:'], $this->deferredAudit());
    }

    #[Test]
    public function d12ARevocationDuringTheProviderRequestCompletesAndIsReportedHonestly(): void
    {
        $this->duringProvider = function (): void {
            $this->get(McpConsentLedger::class)->revokeToken(self::TOKEN_A);
            $this->coreRevoked[self::TOKEN_A] = true;
        };
        $this->prepareA0();

        self::assertSame(1, $this->providerRequests);
        self::assertSame(1, $this->usageRows());
        self::assertSame(RemediationStatus::PENDING_REVIEW, (string)$this->itemOf()['status'], 'Pending review: never approved or applied by an agent.');
        self::assertSame(['generated_before_revocation:'], $this->deferredAudit(), 'Reported as generated before the revocation, never as cancelled.');

        // The agent's next call is refused, and nothing more is generated.
        $next = $this->call('aqg_get_suggestion_status', ['findingIds' => [$this->issues['a0']]]);
        self::assertFalse($next['ok']);
        self::assertSame('token_revoked', $next['error']['code']);
        self::assertSame(1, $this->providerRequests, 'No second provider request.');
    }

    #[Test]
    public function d13TwoUsersSharingOneConnectorAreIsolated(): void
    {
        // B's call runs first in the worker (B prepares a1 without generation; B has the queue permission).
        $b = $this->call('aqg_prepare_suggestions', ['findingIds' => [$this->issues['a1']], 'generate' => false], self::EDITOR_B, self::TOKEN_B, self::REF_B);
        self::assertTrue($b['ok'], json_encode($b));
        self::assertSame('finding_not_found', $b['result']['results'][0]['reason'], 'R1 with D13: B cannot read page 2, so B gets nothing of it.');

        // Then A's call, whose token core has revoked by the time the job runs.
        $this->coreRevoked[self::TOKEN_A] = true;
        $this->prepareA0();
        self::assertSame(0, $this->providerRequests);
        self::assertSame(['deferred_refused:token_revoked'], $this->deferredAudit());
        self::assertSame(RemediationStatus::PREPARING, (string)$this->itemOf()['status']);

        // B's own call afterwards is unaffected by A's revocation.
        $bStatus = $this->call('aqg_get_suggestion_status', ['findingIds' => [$this->issues['b0']]], self::EDITOR_B, self::TOKEN_B, self::REF_B);
        self::assertTrue($bStatus['ok'], json_encode($bStatus));
    }

    #[Test]
    public function d14AnInstallationWithoutPinnedKeysNeverSchedulesDeferredWork(): void
    {
        // Without an attested call (no begin() context) nothing is scheduled: generation is not_started.
        $this->setUpBackendUser(self::ADMIN);
        $deferred = new McpDeferredWork();
        $tools = $this->suggestionTools($deferred, null, $this->get(McpDeferredAuthorization::class));
        $result = $tools->prepare($GLOBALS['BE_USER'], $this->get(SiteFinder::class)->getSiteByIdentifier('client-a'), ['findingIds' => [$this->issues['a0']]], true);
        self::assertSame('not_started', $result['results'][0]['generation']);
        self::assertSame(0, $deferred->count());
        unset($GLOBALS['BE_USER']);

        // An unkeyed connection (paired before V1: no pinned keys) runs no call at all, so nothing can be left behind.
        $store = $this->get(McpConnectionStore::class);
        $stored = (new \ReflectionMethod($store, 'read'))->invoke($store);
        unset($stored['attestationKeys']);
        (new \ReflectionMethod($store, 'write'))->invoke($store, $stored);
        self::assertSame([], $store->attestationTrust()['pinnedKeys']);
        $posted = $this->call('aqg_prepare_suggestions', ['findingIds' => [$this->issues['a1']]]);
        self::assertFalse($posted['ok']);
        self::assertSame('attestation_untrusted', $posted['error']['code']);
        self::assertSame([], $this->continueRequests);
        self::assertSame(0, $this->providerRequests);
    }
}
