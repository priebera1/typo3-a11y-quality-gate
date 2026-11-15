<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Mcp;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Mcp\McpAttestationKeys;
use Priebera\A11yQualityGate\Mcp\McpConnectionStore;
use Priebera\A11yQualityGate\Mcp\McpHostKey;
use Priebera\A11yQualityGate\Mcp\McpInstallationKey;
use Priebera\A11yQualityGate\Mcp\McpTrustRoots;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Priebera\A11yQualityGate\Tests\Support\McpAttestationFixture;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

/**
 * G-02 (AQG 3.0.0 security gate): the MCP attestation trust root. In a customer installation (Production context, AQG's
 * own service) a key set signed by no trust root is never pinned, pins without a root chain verify nothing, and a
 * root-paired connection keeps its chain through a legitimate key rotation. A scripted licence API with real Ed25519
 * keys (McpAttestationFixture) stands in for AQG; the production root's private key is never needed (its role is played
 * by a root configured in AQG_MCP_ATTESTATION_KEYS, which the extension adds to the built-in one).
 */
final class McpTrustRootFunctionalTest extends AbstractFunctionalTestCase
{
    private const SECRET = 'aqgc_' . 'A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8S9t0U1v';
    private const PAIRING = '11111111-2222-4333-8444-555555555555';

    private McpAttestationFixture $licenceApi;
    private ?ApplicationContext $originalContext = null;
    /** @var \Closure(): array{0: int, 1: array<string, mixed>} */
    private \Closure $keyAnswer;

    protected function setUp(): void
    {
        parent::setUp();
        putenv(McpHostKey::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
        putenv(McpTrustRoots::ENV);
        putenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL');
        $this->licenceApi = new McpAttestationFixture();
        $this->keyAnswer = fn (): array => [200, ['success' => true, 'keys' => $this->licenceApi->pinned(), 'keySet' => $this->licenceApi->keySet()]];
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = HandlerStack::create(function (RequestInterface $request) {
            if (preg_match('#/licence/mcp/attestation-keys$#', $request->getUri()->getPath()) === 1) {
                [$status, $json] = ($this->keyAnswer)();
                return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], (string)json_encode($json)));
            }
            return Create::promiseFor(new Response(404, ['Content-Type' => 'application/json'], '{"success":false}'));
        });
        $this->originalContext = Environment::getContext();
    }

    protected function tearDown(): void
    {
        if ($this->originalContext !== null) {
            $this->setContext($this->originalContext);
        }
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']);
        putenv(McpHostKey::ENVIRONMENT_VARIABLE);
        putenv(McpTrustRoots::ENV);
        putenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL');
        @unlink($this->get(McpHostKey::class)->path());
        parent::tearDown();
    }

    private function setContext(ApplicationContext $context): void
    {
        (new \ReflectionProperty(Environment::class, 'context'))->setValue(null, $context);
    }

    /** @param array{trustChain?: string} $extra */
    private function connect(array $pins, array $extra = []): McpConnectionStore
    {
        $store = $this->get(McpConnectionStore::class);
        $store->resetLocal('test');
        self::assertTrue($store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example', 'admin',
            McpInstallationKey::generate() + ['pinnedKeys' => $pins] + $extra));
        self::assertTrue($store->completePairingIfCurrent(self::PAIRING, self::SECRET, 'slug', 'https://gw.example/mcp/i/slug', McpAttestationFixture::INSTALLATION_ID));

        return $store;
    }

    private function assertUntrusted(callable $pairing, string $message): void
    {
        try {
            $pairing();
            self::fail($message);
        } catch (ApiRequestFailedException $exception) {
            self::assertSame('attestation_keys_untrusted', $exception->apiErrorCode, $message);
        }
    }

    #[Test]
    public function aCustomerInstallationNeverPinsAKeySetSignedByNoRoot(): void
    {
        $keys = $this->get(McpAttestationKeys::class);
        $this->setContext(new ApplicationContext('Production'));
        $this->assertUntrusted(fn () => $keys->pairingTrust(), 'Production, AQG service: the self-signed answer is refused');

        $this->setContext(new ApplicationContext('Development'));
        $this->assertUntrusted(fn () => $keys->pairingTrust(), "Development against AQG's own service: still refused");

        putenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL=https://api.staging.example');
        $this->setContext(new ApplicationContext('Production'));
        $this->assertUntrusted(fn () => $keys->pairingTrust(), 'an own service in Production context needs a configured root');

        $this->setContext(new ApplicationContext('Development'));
        self::assertSame(['keys' => $this->licenceApi->pinned(), 'trustChain' => 'development'], $keys->pairingTrust(), 'only a development setup with its own service');
    }

    #[Test]
    public function aKeySetSignedByARootIsPinnedAsRootInProduction(): void
    {
        $this->setContext(new ApplicationContext('Production'));
        putenv(McpTrustRoots::ENV . '=' . $this->licenceApi->x);
        self::assertSame(['keys' => $this->licenceApi->pinned(), 'trustChain' => 'root'], $this->get(McpAttestationKeys::class)->pairingTrust());

        // A key set signed by another key is refused even when it lists the root key (signature, not listing, decides).
        $impostor = new McpAttestationFixture();
        $this->keyAnswer = fn (): array => [200, ['success' => true, 'keys' => $this->licenceApi->pinned(), 'keySet' => $impostor->keySet($this->licenceApi->pinned())]];
        $this->assertUntrusted(fn () => $this->get(McpAttestationKeys::class)->pairingTrust(), 'impostor signature');
    }

    #[Test]
    public function aRootPairedConnectionKeepsItsChainThroughAKeyRotation(): void
    {
        $this->setContext(new ApplicationContext('Production'));
        putenv(McpTrustRoots::ENV . '=' . $this->licenceApi->x);
        $trust = $this->get(McpAttestationKeys::class)->pairingTrust();
        $store = $this->connect($trust['keys'], ['trustChain' => $trust['trustChain']]);
        self::assertSame('root', $store->attestationTrust()['trustChain']);

        // The licence API announces its next key, then switches to it; the next key is no root of this release.
        $next = new McpAttestationFixture();
        $this->keyAnswer = fn (): array => [200, ['success' => true, 'keySet' => $this->licenceApi->keySet([...$this->licenceApi->pinned(), ...$next->pinned()])]];
        self::assertTrue($this->get(McpAttestationKeys::class)->refresh());
        $this->keyAnswer = fn (): array => [200, ['success' => true, 'keySet' => $next->keySet()]];
        self::assertTrue($this->get(McpAttestationKeys::class)->refresh());

        putenv(McpTrustRoots::ENV);
        $after = $store->attestationTrust();
        self::assertSame($next->pinned(), $after['pinnedKeys'], 'the rotated key is trusted: it chains to the root the pairing verified');
        self::assertSame('root', $after['trustChain']);
        self::assertFalse($store->hasUntrustedAttestationKeys());
    }

    #[Test]
    public function theChainRecordedAtPairingStartSurvivesCompletion(): void
    {
        $this->setContext(new ApplicationContext('Production'));
        putenv(McpTrustRoots::ENV . '=' . $this->licenceApi->x);
        $trust = $this->get(McpAttestationKeys::class)->pairingTrust();
        $store = $this->connect($trust['keys'], ['trustChain' => $trust['trustChain']]);
        putenv(McpTrustRoots::ENV);
        self::assertSame('root', $store->attestationTrust()['trustChain'], 'start → complete keeps the recorded chain');
        self::assertFalse($store->hasUntrustedAttestationKeys());
    }

    #[Test]
    public function anUnrootedSetListingTheRootKeyNextToItsOwnIsNeverPromotedToRoot(): void
    {
        // Review finding (G-02): a development service (or whoever answers for it) lists the public production root next to
        // its own key and signs with its own key. Accepted as `development` only; after completion and in Production it
        // must verify nothing, and no refresh may record it as `root`.
        $attacker = new McpAttestationFixture();
        $production = ['kid' => 'd95fdde9bf78f61f', 'x' => McpTrustRoots::PRODUCTION_ROOTS[0]];
        $this->keyAnswer = fn (): array => [200, ['success' => true, 'keys' => [$production, ...$attacker->pinned()],
            'keySet' => $attacker->keySet([$production, ...$attacker->pinned()])]];
        putenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL=https://api.staging.example');
        $this->setContext(new ApplicationContext('Development'));
        $trust = $this->get(McpAttestationKeys::class)->pairingTrust();
        self::assertSame('development', $trust['trustChain']);
        $store = $this->connect($trust['keys'], ['trustChain' => $trust['trustChain']]);
        self::assertSame('development', $store->attestationTrust()['trustChain']);

        $this->setContext(new ApplicationContext('Production'));
        self::assertSame([], $store->attestationTrust()['pinnedKeys']);
        self::assertFalse($this->get(McpAttestationKeys::class)->refresh());
        self::assertSame('', $store->attestationTrust()['trustChain'], 'never promoted');

        // The same pins stored without a chain (a connection paired before G-02) are no root chain either.
        $legacy = $this->connect([$production, ...$attacker->pinned()]);
        self::assertSame([], $legacy->attestationTrust()['pinnedKeys']);
        self::assertTrue($legacy->hasUntrustedAttestationKeys());
    }

    #[Test]
    public function developmentPinsVerifyNothingOnceTheInstallationRunsInProduction(): void
    {
        putenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL=https://api.staging.example');
        $this->setContext(new ApplicationContext('Development'));
        $trust = $this->get(McpAttestationKeys::class)->pairingTrust();
        $store = $this->connect($trust['keys'], ['trustChain' => $trust['trustChain']]);
        self::assertSame($this->licenceApi->pinned(), $store->attestationTrust()['pinnedKeys']);

        $this->setContext(new ApplicationContext('Production'));
        self::assertSame([], $store->attestationTrust()['pinnedKeys'], 'no attestation, receipt or continuation verifies');
        self::assertSame('', $store->attestationTrust()['trustChain']);
        self::assertTrue($store->hasUntrustedAttestationKeys());
        self::assertFalse($this->get(McpAttestationKeys::class)->refresh(), 'and untrusted pins never rotate');

        putenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL');
        $this->setContext(new ApplicationContext('Development'));
        self::assertSame([], $store->attestationTrust()['pinnedKeys'], "development pins are not trusted against AQG's own service");
    }

    #[Test]
    public function aConnectionPairedBeforeTheTrustRootIsTrustedOnlyWhenItPinnedARootKey(): void
    {
        $this->setContext(new ApplicationContext('Production'));
        // Pinned by trust on first use before G-02 (no chain recorded), with a key that is no root.
        $store = $this->connect($this->licenceApi->pinned());
        self::assertSame([], $store->attestationTrust()['pinnedKeys']);
        self::assertTrue($store->hasUntrustedAttestationKeys());

        // The same legacy connection with the production key pinned (as the pilot installations did) stays usable.
        $production = [['kid' => 'd95fdde9bf78f61f', 'x' => McpTrustRoots::PRODUCTION_ROOTS[0]]];
        $legacy = $this->connect($production);
        self::assertSame($production, $legacy->attestationTrust()['pinnedKeys']);
        self::assertSame('root', $legacy->attestationTrust()['trustChain']);

        // A legacy connection whose pin is a configured root records the chain at the next refresh and keeps it.
        putenv(McpTrustRoots::ENV . '=' . $this->licenceApi->x);
        $configured = $this->connect($this->licenceApi->pinned());
        self::assertSame('root', $configured->attestationTrust()['trustChain']);
        $this->get(McpAttestationKeys::class)->refresh();
        putenv(McpTrustRoots::ENV);
        self::assertSame('root', $configured->attestationTrust()['trustChain'], 'recorded, no longer dependent on the variable');
        self::assertSame($this->licenceApi->pinned(), $configured->attestationTrust()['pinnedKeys']);
    }
}
