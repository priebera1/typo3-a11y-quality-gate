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
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\LanguageUidResolver;
use Priebera\A11yQualityGate\Service\BackendContextService;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use TYPO3\CMS\Backend\Routing\UriBuilder;

/**
 * D-01 (2026-11-05 company acceptance): on a multi-site installation the fallback "first configured site with a host"
 * validated and bound the licence for the wrong site. A site is now explicit or, on a single-site installation, the
 * only site; anything else is refused instead of replaced.
 *
 * Earlier regression guard: a saved, valid licence rendered as INACTIVE — "AQG licence is currently
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
    public function withoutSiteContextAMultiSiteInstallationValidatesNoSite(): void
    {
        // D-01: the alphabetically first site used to be validated (and bound by the API) on the user's behalf.
        $subject = $this->subject(fn (): array => $this->validAgencyResponse());

        $status = $this->licenceStatus($subject, '');

        self::assertFalse($status->valid);
        self::assertSame('site_required', $status->reason);
        self::assertSame([], $this->validatedDomains, 'No site may be validated without an explicit choice.');
        self::assertSame('', $this->validateDomain($subject), 'Validate must not fall back to the first site either.');
    }

    #[Test]
    public function withoutSiteContextASingleSiteInstallationUsesItsOnlySite(): void
    {
        $subject = $this->subject(fn (): array => $this->validAgencyResponse(), ['relative', 'a']);

        $status = $this->licenceStatus($subject, '');

        self::assertTrue($status->valid, 'A key the API accepts must not render as INACTIVE.');
        self::assertSame('a.example', $status->domain);
        self::assertSame('a.example', $this->validateDomain($subject));
        self::assertSame(['a.example'], $this->validatedDomains, 'No validation may be sent for an empty domain.');
    }

    #[Test]
    public function anExplicitSiteIsTheSiteValidateChecks(): void
    {
        $subject = $this->subject(fn (): array => $this->validAgencyResponse());

        $site = $this->requestedSite($subject, ['siteIdentifier' => 'b']);

        self::assertInstanceOf(Site::class, $site);
        self::assertSame('b', $site->getIdentifier());
    }

    #[Test]
    public function anUnknownOrHostlessSiteIsRefusedNotReplaced(): void
    {
        $subject = $this->subject(fn (): array => $this->validAgencyResponse());

        foreach (['missing', 'relative'] as $identifier) {
            $response = $this->requestedSite($subject, ['siteIdentifier' => $identifier]);
            self::assertInstanceOf(JsonResponse::class, $response, $identifier);
            self::assertSame(400, $response->getStatusCode());
            self::assertSame('site_not_found', json_decode((string)$response->getBody(), true)['reason']);
        }
    }

    #[Test]
    public function noSiteOnAMultiSiteInstallationIsRefusedAsAmbiguous(): void
    {
        $subject = $this->subject(fn (): array => $this->validAgencyResponse());

        $response = $this->requestedSite($subject, []);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('site_required', json_decode((string)$response->getBody(), true)['reason']);
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

    #[Test]
    public function anAdministratorWithoutSiteContextGetsEverySiteWithAHostToChooseFrom(): void
    {
        // typo3test14 (2026-11-11): sites `main` and `test`, Settings opened from "Select a page" → site_required
        // rendered as "INACTIVE / unexpected answer / Retry", with no way to choose a site. The card now asks for one.
        $subject = $this->subject(fn (): array => $this->validAgencyResponse());

        self::assertSame('', $this->licenceSiteIdentifier($subject, '', true));
        $options = $this->siteOptions($subject, '', true);
        self::assertSame(['a', 'b'], array_column($options, 'identifier'), 'a host-less site is no licence target');
        self::assertSame(['a.example', 'b.example'], array_column($options, 'domain'));
        self::assertSame([false, false], array_column($options, 'selected'), 'nothing is chosen on the user\'s behalf');
        foreach ($options as $option) {
            self::assertStringContainsString('rulesetSite=' . $option['identifier'], $option['url']);
            self::assertStringContainsString('tab=licence', $option['url']);
        }
        self::assertSame('site_required', $this->licenceStatus($subject, '')->reason);
        self::assertSame([], $this->validatedDomains);
    }

    #[Test]
    public function aChosenSiteIsValidatedAndMarkedInThePicker(): void
    {
        $subject = $this->subject(fn (): array => $this->validAgencyResponse());

        $identifier = $this->licenceSiteIdentifier($subject, 'b', true);
        self::assertSame('b', $identifier);
        self::assertSame([false, true], array_column($this->siteOptions($subject, $identifier, true), 'selected'));
        $status = $this->licenceStatus($subject, $identifier);
        self::assertTrue($status->valid);
        self::assertSame('b.example', $status->domain);
        self::assertSame(['b.example'], $this->validatedDomains);
    }

    #[Test]
    public function aRejectedLicenceStaysRejectedForTheChosenSite(): void
    {
        foreach (['invalid_key', 'expired', 'inactive', 'domain_mismatch', 'project_mismatch'] as $reason) {
            // The API's refusal: HTTP 403 `licence_invalid` with the reason in `details`.
            $subject = $this->subject(fn (): array => [
                'success' => false,
                'error' => ['code' => 'licence_invalid', 'status' => 403, 'details' => ['reason' => $reason]],
            ]);

            $status = $this->licenceStatus($subject, $this->licenceSiteIdentifier($subject, 'a', true));
            self::assertFalse($status->valid, $reason);
            self::assertSame($reason, $status->reason, 'a refusal is never turned into site_required or valid');
        }
    }

    #[Test]
    public function anEditorWhoReadsOneSiteSeesThatSitesStatus(): void
    {
        $subject = $this->subject(fn (): array => $this->validAgencyResponse(), null, ['a']);

        self::assertSame('a', $this->licenceSiteIdentifier($subject, '', false));
        self::assertSame([], $this->siteOptions($subject, 'a', false), 'one readable site needs no picker');
        self::assertTrue($this->licenceStatus($subject, 'a')->valid);
    }

    #[Test]
    public function anEditorCannotNameASiteTheyCannotRead(): void
    {
        // A crafted ?rulesetSite= must not show an editor another site's domain and plan.
        $subject = $this->subject(fn (): array => $this->validAgencyResponse(), null, ['a']);

        self::assertSame('a', $this->licenceSiteIdentifier($subject, 'b', false));

        $none = $this->subject(fn (): array => $this->validAgencyResponse(), null, []);
        self::assertSame('', $this->licenceSiteIdentifier($none, 'b', false));
        self::assertSame([], $this->siteOptions($none, '', false));
        self::assertSame('site_required', $this->licenceStatus($none, '')->reason);
        self::assertSame([], $this->validatedDomains);
    }

    #[Test]
    public function anEditorWhoReadsSeveralSitesChoosesAmongThemOnly(): void
    {
        $subject = $this->subject(fn (): array => $this->validAgencyResponse(), ['relative', 'a', 'b', 'c'], ['a', 'c']);

        self::assertSame('', $this->licenceSiteIdentifier($subject, '', false));
        self::assertSame(['a', 'c'], array_column($this->siteOptions($subject, '', false), 'identifier'));
        self::assertSame(['a', 'b', 'c'], array_column($this->siteOptions($subject, '', true), 'identifier'));
    }

    #[Test]
    public function anUnknownOrHostlessSiteIsNoChoiceAndValidatesNothing(): void
    {
        // A stale ?rulesetSite= (deleted site) or a host-less site used to validate an empty domain → INACTIVE.
        $subject = $this->subject(fn (): array => $this->validAgencyResponse());
        foreach (['deleted_site', 'relative'] as $requested) {
            self::assertSame('', $this->licenceSiteIdentifier($subject, $requested, true), $requested);
        }
        self::assertSame('site_required', $this->licenceStatus($subject, '')->reason);
        self::assertSame([], $this->validatedDomains, 'no empty domain is ever validated');

        $single = $this->subject(fn (): array => $this->validAgencyResponse(), ['relative', 'a']);
        self::assertSame('a', $this->licenceSiteIdentifier($single, 'deleted_site', true), 'single site still falls back');
    }

    #[Test]
    public function aSingleSiteInstallationNeedsNoPicker(): void
    {
        $subject = $this->subject(fn (): array => $this->validAgencyResponse(), ['relative', 'a']);

        self::assertSame('a', $this->licenceSiteIdentifier($subject, '', true));
        self::assertSame([], $this->siteOptions($subject, 'a', true));
    }

    /**
     * @param list<string>|null $only site identifiers to configure (default: relative, a, b)
     */
    private function subject(\Closure $apiResponse, ?array $only = null, ?array $readable = null): SettingsController
    {
        $sites = [
            'relative' => $this->site('relative', '/'),
            'a' => $this->site('a', 'https://a.example/'),
            'b' => $this->site('b', 'https://b.example/'),
            'c' => $this->site('c', 'https://c.example/'),
        ];
        $only ??= ['relative', 'a', 'b'];
        $sites = array_intersect_key($sites, array_flip($only));

        // Editors: the sites they may read (null = every site, as for an administrator).
        $scopeAccess = $this->createMock(ScopeAccessService::class);
        $scopeAccess->method('canReadWithinSiteIdentifier')->willReturnCallback(
            static fn (string $identifier): bool => $readable === null || in_array($identifier, $readable, true)
        );
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturnCallback(
            static fn (string $route, array $parameters): Uri => new Uri('/typo3/module/' . $route . '?' . http_build_query($parameters))
        );

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
            'requestParameterService' => new RequestParameterService(new LanguageUidResolver()),
            'backendContextService' => $this->backendContext(),
            'scopeAccessService' => $scopeAccess,
            'uriBuilder' => $uriBuilder,
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

    private function backendContext(): BackendContextService
    {
        $context = $this->createMock(BackendContextService::class);
        $context->method('translate')->willReturnArgument(0);

        return $context;
    }

    private function licenceStatus(SettingsController $subject, string $siteIdentifier): ProStatusViewModel
    {
        $status = (new ReflectionMethod(SettingsController::class, 'resolveLicenceStatus'))
            ->invoke($subject, $siteIdentifier);
        self::assertInstanceOf(ProStatusViewModel::class, $status);

        return $status;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requestedSite(SettingsController $subject, array $body): Site|JsonResponse
    {
        return (new ReflectionMethod(SettingsController::class, 'resolveRequestedLicenceSite'))
            ->invoke($subject, new ServerRequest('https://backend.example/typo3/ajax/a11y/licence/validate', 'POST'), $body);
    }

    private function licenceSiteIdentifier(SettingsController $subject, string $requested, bool $isAdmin): string
    {
        return (string)(new ReflectionMethod(SettingsController::class, 'resolveLicenceSiteIdentifier'))
            ->invoke($subject, $requested, $isAdmin);
    }

    /**
     * @return list<array{identifier:string,domain:string,url:string,selected:bool}>
     */
    private function siteOptions(SettingsController $subject, string $selected, bool $isAdmin): array
    {
        return (new ReflectionMethod(SettingsController::class, 'buildLicenceSiteOptions'))->invoke(
            $subject,
            new ServerRequest('https://backend.example/typo3/module/web/a11y/settings', 'GET'),
            $selected,
            $isAdmin,
        );
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
