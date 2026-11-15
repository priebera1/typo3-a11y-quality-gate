<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Mcp\McpAttestation;
use Priebera\A11yQualityGate\Mcp\McpTrustRoots;
use Priebera\A11yQualityGate\Tests\Support\McpAttestationFixture;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * G-02 (AQG 3.0.0 security gate): the built-in trust root and the policy for key sets signed by no root.
 */
final class McpTrustRootsTest extends UnitTestCase
{
    /** McpAttestation / ProSettings may register framework singletons (LogManager) depending on test order. */
    protected bool $resetSingletonInstances = true;

    protected function tearDown(): void
    {
        putenv(McpTrustRoots::ENV);
        parent::tearDown();
    }

    #[Test]
    public function theAqgProductionKeyIsABuiltInRoot(): void
    {
        putenv(McpTrustRoots::ENV);
        $roots = McpTrustRoots::roots();
        self::assertSame([['kid' => 'd95fdde9bf78f61f', 'x' => 'fuewRMO2btJ6c8BZyuwRmUVpM45uwjMVFECEGr_7VXM']], $roots);
        foreach (McpTrustRoots::PRODUCTION_ROOTS as $x) {
            self::assertSame(32, strlen((string)McpAttestation::base64UrlDecode($x)), 'a raw Ed25519 public key, never a secret key');
        }
    }

    #[Test]
    public function configuredRootsAreAddedNeverReplaceTheBuiltInOne(): void
    {
        $staging = new McpAttestationFixture();
        putenv(McpTrustRoots::ENV . '=' . $staging->x . ', not-a-key ,' . $staging->x . ',' . McpTrustRoots::PRODUCTION_ROOTS[0]);
        self::assertSame(['d95fdde9bf78f61f', $staging->kid], array_column(McpTrustRoots::roots(), 'kid'), 'deduplicated, malformed entries ignored');
    }

    #[Test]
    public function unrootedKeySetsAreNeverAcceptedForTheAqgServiceOrInProduction(): void
    {
        $aqg = 'https://api.priebera.sk';
        $own = 'https://api.staging.example';
        self::assertFalse(McpTrustRoots::allowsUnrootedTrust(new ApplicationContext('Production'), $aqg));
        self::assertFalse(McpTrustRoots::allowsUnrootedTrust(new ApplicationContext('Production'), $own), 'Production context: never');
        self::assertFalse(McpTrustRoots::allowsUnrootedTrust(new ApplicationContext('Production/Staging'), $own));
        self::assertFalse(McpTrustRoots::allowsUnrootedTrust(new ApplicationContext('Development'), $aqg), "AQG's own service: never");
        self::assertFalse(McpTrustRoots::allowsUnrootedTrust(new ApplicationContext('Development'), $aqg . '/'));
        self::assertTrue(McpTrustRoots::allowsUnrootedTrust(new ApplicationContext('Development'), $own), 'own service, development');
        self::assertTrue(McpTrustRoots::allowsUnrootedTrust(new ApplicationContext('Development/Local'), 'http://aqg-api.ddev.site'));
        self::assertTrue(McpTrustRoots::allowsUnrootedTrust(new ApplicationContext('Testing'), $aqg), 'the test suite');
    }

    #[Test]
    public function theAqgServiceIsRecognisedHoweverItsUrlIsSpelled(): void
    {
        foreach (['https://api.priebera.sk', 'https://api.priebera.sk/', 'https://API.Priebera.SK', 'https://api.priebera.sk:443/', 'https://api.priebera.sk./', '', 'not a url'] as $url) {
            self::assertTrue(McpTrustRoots::isAqgService($url), $url);
            self::assertFalse(McpTrustRoots::allowsUnrootedTrust(new ApplicationContext('Development'), $url), $url);
        }
        foreach (['https://api.staging.example', 'http://api.priebera.sk', 'https://api.priebera.sk:8443', 'https://api.priebera.sk.evil.example'] as $url) {
            self::assertFalse(McpTrustRoots::isAqgService($url), $url);
        }
    }

    #[Test]
    public function theStoredChainDecidesWhetherPinsAreTrustedNow(): void
    {
        putenv(McpTrustRoots::ENV);
        $stranger = (new McpAttestationFixture())->pinned();
        $production = [['kid' => 'd95fdde9bf78f61f', 'x' => McpTrustRoots::PRODUCTION_ROOTS[0]]];

        self::assertSame('root', McpTrustRoots::effectiveChain('root', $stranger, false), 'a rotated key of a root-paired connection');
        self::assertSame('', McpTrustRoots::effectiveChain('development', $stranger, false), 'development pins in Production: refused');
        self::assertSame('development', McpTrustRoots::effectiveChain('development', $stranger, true));
        self::assertSame('', McpTrustRoots::effectiveChain('', $stranger, false), 'paired before G-02 without a root key: refused');
        self::assertSame('root', McpTrustRoots::effectiveChain('', $production, false), 'paired before G-02 with the production key');
        self::assertSame('', McpTrustRoots::effectiveChain('', [...$stranger, ...$production], false), 'the public root key listed next to a foreign key is no root chain');
        self::assertSame('', McpTrustRoots::effectiveChain('root', [], false), 'no pins: nothing to trust');
        self::assertSame('', McpTrustRoots::effectiveChain('', [['kid' => 'd95fdde9bf78f61f', 'x' => $stranger[0]['x']]], false), 'a root kid with another key is no root');
    }
}
