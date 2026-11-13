<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro\Configuration;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/** Fable P3-11: licence keys and MCP secrets never go to a plain-http service URL on a public host. */
final class ProSettingsServiceUrlTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        putenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL');
        parent::tearDown();
    }

    #[Test]
    public function httpsAndDevelopmentHostsAreAccepted(): void
    {
        self::assertSame('https://api.example.org', ProSettings::acceptedServiceOverride('https://api.example.org/'));
        foreach (['http://localhost:3000', 'http://127.0.0.1:3000', 'http://aqg-api:3000', 'http://api.aqg.ddev.site', 'http://api.example.test', 'http://host.docker.internal:3000'] as $url) {
            self::assertSame($url, ProSettings::acceptedServiceOverride($url), $url);
        }
    }

    #[Test]
    public function plainHttpOnPublicHostsAndOtherSchemesAreIgnored(): void
    {
        foreach (['http://api.priebera.sk', 'http://203.0.113.5', 'ftp://api.example.org', 'https://user:pass@api.example.org', 'not a url', '', null, false] as $value) {
            self::assertNull(ProSettings::acceptedServiceOverride($value), var_export($value, true));
        }
        putenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL=http://evil.example.com');
        self::assertSame(ProConstants::API_BASE_URL, ProSettings::resolveApiBaseUrl(), 'an unsafe override falls back to the AQG service');
    }
}
