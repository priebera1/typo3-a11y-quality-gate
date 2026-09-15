<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\SettingsController;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResponseDto;
use Priebera\A11yQualityGate\Pro\Http\AqgApiClient;
use Priebera\A11yQualityGate\Pro\Service\DomainNormalizer;
use Priebera\A11yQualityGate\Pro\Service\ProCapabilityService;
use Priebera\A11yQualityGate\Pro\Service\ProLicenceService;
use Priebera\A11yQualityGate\Pro\Service\ProSiteFingerprintService;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Pro\ViewModel\ProStatusViewModel;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Regression guard: a saved, valid licence rendered as INACTIVE — "AQG licence is currently
 * unavailable." — on a Settings screen without page context, before and after Save.
 *
 * That screen is reached from the Overview's "Select a page to start" state, which is where a first
 * licence is typically entered. Validate (Ajax, which never carries page context) checks the key
 * against the first configured site with a host and reported it valid. The status box resolved an
 * empty site identifier instead, validated the persisted key for an empty domain and rendered the
 * API's rejection of that request as an inactive licence until a page was selected.
 *
 * Runs the real status resolver, capability service and licence service; only the HTTP client, the
 * settings and the site lookups are doubles.
 */
final class SettingsLicenceStatusContextTest extends TestCase
{
    /** @var list<string> */
    private array $validatedDomains = [];

    #[Test]
    public function withoutPageContextTheSavedKeyIsResolvedForTheSiteValidateChecked(): void
    {
        $subject = $this->subject(fn (): array => $this->validAgencyResponse());

        $status = $this->licenceStatus($subject, '');

        self::assertTrue($status->valid, 'A key the API accepts must not render as INACTIVE.');
        self::assertSame('agency', $status->plan);
        self::assertSame('a.example', $status->domain);
        self::assertSame(
            $this->validateDomain($subject),
            $status->domain,
            'Validate and the Settings status must describe the same site.'
        );
        self::assertSame(['a.example'], $this->validatedDomains, 'No validation may be sent for an empty domain.');
    }

    #[Test]
    public function withoutPageContextARejectedKeyStillShowsTheApiReason(): void
    {
        $subject = $this->subject(static fn (): array => [
            'success' => false,
            'error' => [
                'code' => 'licence_invalid',
                'message' => 'Invalid licence key',
                'status' => 403,
                'details' => ['reason' => 'invalid_key'],
            ],
        ]);

        $status = $this->licenceStatus($subject, '');

        self::assertFalse($status->valid);
        self::assertSame('invalid_key', $status->reason);
        self::assertSame('The configured licence key is invalid.', $status->reasonLabel);
        self::assertSame('a.example', $status->domain);
    }

    #[Test]
    public function aSiteFromTheRequestIsStillTheSiteTheStatusDescribes(): void
    {
        $subject = $this->subject(fn (): array => $this->validAgencyResponse());

        $status = $this->licenceStatus($subject, 'b');

        self::assertTrue($status->valid);
        self::assertSame('b.example', $status->domain);
        self::assertSame(['b.example'], $this->validatedDomains);
    }

    /**
     * @param \Closure(): array<string, mixed> $apiResponse the API's answer for a non-empty domain
     */
    private function subject(\Closure $apiResponse): SettingsController
    {
        $sites = [
            'relative' => $this->site('relative', '/'),
            'a' => $this->site('a', 'https://a.example/'),
            'b' => $this->site('b', 'https://b.example/'),
        ];

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn($sites);

        $siteResolution = $this->createMock(SiteResolutionService::class);
        $siteResolution->method('resolveSiteByIdentifier')->willReturnCallback(
            static fn (string $identifier): ?Site => $sites[$identifier] ?? null
        );

        $domainNormalizer = new DomainNormalizer();
        $extensionContext = $this->createMock(ExtensionContextService::class);
        $extensionContext->method('getExtensionVersion')->willReturn('1.9.2');
        $extensionContext->method('getNormalizedDomainFromSiteBase')->willReturnCallback(
            static fn (string $siteBase): string => $domainNormalizer->normalizeFromSiteBase($siteBase)
        );

        $apiClient = $this->createMock(AqgApiClient::class);
        $apiClient->method('validate')->willReturnCallback(
            function (string $licenceKey, string $domain) use ($apiResponse): LicenceValidationResponseDto {
                $this->validatedDomains[] = $domain;

                // What the API answers for an empty domain: HTTP 400 without any licence verdict.
                return LicenceValidationResponseDto::fromArray($domain === '' ? [
                    'success' => false,
                    'error' => ['code' => 'invalid_request', 'message' => 'Invalid request payload', 'status' => 400],
                ] : $apiResponse());
            }
        );

        $proSettings = $this->createMock(ProSettings::class);
        $proSettings->method('isConfigured')->willReturn(true);
        $proSettings->method('getLicenceKey')->willReturn('aqg_live_0000000000000000000000000000');
        $proSettings->method('isTrialKey')->willReturn(false);
        $proSettings->method('showProHints')->willReturn(true);

        $fingerprint = $this->createMock(ProSiteFingerprintService::class);
        $fingerprint->method('collectValidationSites')->willReturn(['a.example', 'b.example']);
        $fingerprint->method('buildFingerprint')->willReturn('sites');

        // Let the CacheManager build the cache: it knows the backend constructor of TYPO3 13 and 14.
        $cacheManager = new CacheManager();
        $cacheManager->setCacheConfigurations([
            ProConstants::CACHE_IDENTIFIER => [
                'frontend' => VariableFrontend::class,
                'backend' => TransientMemoryBackend::class,
            ],
        ]);
        $licenceService = new ProLicenceService($apiClient, new ProCacheManager($cacheManager), $proSettings, $fingerprint);

        $subject = (new ReflectionClass(SettingsController::class))->newInstanceWithoutConstructor();
        foreach ([
            'siteFinder' => $siteFinder,
            'extensionContextService' => $extensionContext,
            'proStatusResolverService' => new ProStatusResolverService(
                new ProCapabilityService($licenceService, $proSettings),
                $extensionContext,
                $siteResolution,
            ),
        ] as $property => $value) {
            (new ReflectionProperty(SettingsController::class, $property))->setValue($subject, $value);
        }

        return $subject;
    }

    private function licenceStatus(SettingsController $subject, string $siteIdentifier): ProStatusViewModel
    {
        $status = (new ReflectionMethod(SettingsController::class, 'resolveLicenceStatus'))
            ->invoke($subject, $siteIdentifier);
        self::assertInstanceOf(ProStatusViewModel::class, $status);

        return $status;
    }

    private function validateDomain(SettingsController $subject): string
    {
        return (string)(new ReflectionMethod(SettingsController::class, 'resolveValidationDomain'))
            ->invoke($subject, null);
    }

    private function site(string $identifier, string $base): Site
    {
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn($identifier);
        $site->method('getBase')->willReturn(new Uri($base));
        $site->method('getLanguages')->willReturn([]);

        return $site;
    }

    /**
     * @return array<string, mixed> the API's /licence/validate answer for an active Agency licence
     */
    private function validAgencyResponse(): array
    {
        return [
            'success' => true,
            'valid' => true,
            'product' => ['slug' => ProConstants::PRODUCT_SLUG, 'name' => 'Accessibility Quality Gate'],
            'plan' => 'agency',
            'features' => ['crawler', 'export_pdf', 'multi_site', 'pro_rules', 'screenshot_capture'],
            'expires_at' => '2027-09-15T00:00:00.000Z',
            'status' => 'active',
            'domain_allowed' => true,
            'is_dev_domain' => false,
            'registered_sites' => ['a.example', 'b.example'],
            'last_validated_at' => '2026-09-15T10:00:00.000Z',
        ];
    }
}
