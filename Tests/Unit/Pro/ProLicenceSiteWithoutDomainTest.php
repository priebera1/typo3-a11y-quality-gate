<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Http\AqgApiClient;
use Priebera\A11yQualityGate\Pro\Service\ProLicenceService;
use Priebera\A11yQualityGate\Pro\Service\ProSiteFingerprintService;
use Priebera\A11yQualityGate\Pro\ViewModel\LicenceGuidance;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Product acceptance D-03: a licensed site whose base has no host (TYPO3 allows a relative base such as "/") made every
 * Overview, Page module and export request validate the licence for an empty domain. The licence API refuses that
 * request (400 invalid_request), so a valid customer saw "Licence could not be validated … contact support" on every
 * page view. An empty domain is never sent; the admin is told to give the site a full base URL instead.
 */
final class ProLicenceSiteWithoutDomainTest extends TestCase
{
    /** @return iterable<string, array{0:string}> */
    public static function hostlessDomains(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
    }

    #[Test]
    #[DataProvider('hostlessDomains')]
    public function aSiteWithoutADomainIsNotSentToTheLicenceApi(string $domain): void
    {
        $apiClient = $this->createMock(AqgApiClient::class);
        $apiClient->expects(self::never())->method('validate');

        $stored = [];
        $frontend = $this->createMock(FrontendInterface::class);
        $frontend->method('get')->willReturn(false);
        $frontend->method('set')->willReturnCallback(static function (string $key) use (&$stored): void {
            $stored[] = $key;
        });
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($frontend);

        $settings = $this->createMock(ProSettings::class);
        $settings->method('isConfigured')->willReturn(true);
        $settings->method('getLicenceKey')->willReturn('aqg_live_key');
        $fingerprint = $this->createMock(ProSiteFingerprintService::class);
        $fingerprint->method('collectValidationSites')->willReturn(['example.org']);

        $result = (new ProLicenceService($apiClient, new ProCacheManager($cacheManager), $settings, $fingerprint))->validate($domain, '3.0.0');

        self::assertFalse($result->valid);
        self::assertSame('site_without_domain', $result->reason);
        self::assertFalse($result->isTransientFailure(), 'not an outage: retrying without a site change cannot help');
        self::assertSame([], $stored, 'no verdict about the licence is cached');
    }

    #[Test]
    public function theGuidanceExplainsTheSiteConfigurationInsteadOfAnUnexpectedAnswer(): void
    {
        $guidance = LicenceGuidance::forReason('site_without_domain');

        self::assertSame('site_without_domain', $guidance->state);
        self::assertSame('settings.licence.guidance.siteWithoutDomain.title', $guidance->titleKey);
        self::assertContains('settings.licence.guidance.siteWithoutDomain.text', LicenceGuidance::translationKeys());
    }
}
