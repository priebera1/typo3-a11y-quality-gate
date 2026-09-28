<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResult;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResponseDto;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Http\AqgApiClient;
use Priebera\A11yQualityGate\Pro\Service\ProLicenceService;
use Priebera\A11yQualityGate\Pro\Service\ProSiteFingerprintService;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * State transitions of the cached entitlement. An outage keeps a still-valid last known good licence; a
 * definitive rejection removes every positive state; nothing cached outlives the entitlement's own end.
 */
final class ProLicenceCacheSemanticsTest extends TestCase
{
    /** @var array<string, array{data:mixed,tags:list<string>,lifetime:int}> */
    private array $store = [];
    private ProCacheManager $cache;

    protected function setUp(): void
    {
        $frontend = $this->createMock(FrontendInterface::class);
        $frontend->method('get')->willReturnCallback(fn (string $key): mixed => $this->store[$key]['data'] ?? false);
        $frontend->method('set')->willReturnCallback(function (string $key, mixed $data, array $tags = [], ?int $lifetime = null): void {
            $this->store[$key] = ['data' => $data, 'tags' => $tags, 'lifetime' => (int)$lifetime];
        });
        $frontend->method('remove')->willReturnCallback(function (string $key): bool {
            unset($this->store[$key]);
            return true;
        });
        $frontend->method('flushByTag')->willReturnCallback(function (string $tag): void {
            $this->store = array_filter($this->store, static fn (array $entry): bool => !in_array($tag, $entry['tags'], true));
        });
        $frontend->method('flush')->willReturnCallback(function (): void {
            $this->store = [];
        });

        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($frontend);
        $this->cache = new ProCacheManager($cacheManager);
    }

    /**
     * @return iterable<string, array{0: \Closure(): LicenceValidationResponseDto}>
     */
    public static function transientFailureProvider(): iterable
    {
        yield 'network failure' => [static fn () => throw new ApiRequestFailedException('AQG API request failed.', 0, null, 'transport_error')];
        yield 'timeout' => [static fn () => throw new ApiRequestFailedException('AQG API request failed.', 0, null, 'transport_error')];
        yield 'HTTP 429 without body' => [static fn () => throw new ApiRequestFailedException('AQG API returned an empty response body.', 429, null, 'empty_response')];
        yield 'HTTP 429 JSON' => [static fn () => LicenceValidationResponseDto::fromArray(['success' => false, 'error' => ['code' => 'licence_rate_limited', 'details' => ['reason' => 'rate_limited']]])];
        yield 'HTTP 500 JSON' => [static fn () => LicenceValidationResponseDto::fromArray(['success' => false, 'error' => ['code' => 'internal_error']])];
        yield 'HTTP 503 JSON' => [static fn () => LicenceValidationResponseDto::fromArray(['success' => false, 'error' => ['code' => 'service_unavailable']])];
        yield 'HTTP 502 HTML' => [static fn () => throw new ApiRequestFailedException('AQG API returned invalid JSON.', 502, null, 'invalid_json')];
        yield 'answer without verdict' => [static fn () => LicenceValidationResponseDto::fromArray(['success' => false, 'error' => ['code' => 'invalid_request']])];
    }

    #[DataProvider('transientFailureProvider')]
    #[Test]
    public function aTransientFailureKeepsTheLastKnownGoodLicence(\Closure $failure): void
    {
        $service = $this->service([$this->validResponse('+30 days'), $failure]);
        self::assertTrue($service->validate('example.org', '1.9.6')->valid);
        $this->expireFreshEntries();

        $result = $service->validate('example.org', '1.9.6');

        self::assertTrue($result->valid, 'An outage must not read as an invalid licence.');
        self::assertSame('pro', $result->plan);
        self::assertTrue($this->graceEntry()['data']['valid'], 'The grace copy must survive the outage.');
    }

    #[Test]
    public function aTransientFailureWithoutAKnownLicenceIsAnOutageThatDoesNotCreateGrace(): void
    {
        $service = $this->service([
            static fn () => throw new ApiRequestFailedException('AQG API request failed.', 0, null, 'transport_error'),
        ]);

        $result = $service->validate('example.org', '1.9.6');

        self::assertFalse($result->valid);
        self::assertSame('api_unreachable', $result->reason);
        self::assertNull($this->graceEntry());
    }

    /**
     * @return iterable<string, array{0:string}>
     */
    public static function definitiveRejectionProvider(): iterable
    {
        yield 'expired' => ['expired'];
        yield 'revoked/inactive' => ['inactive'];
        yield 'invalid key' => ['invalid_key'];
        yield 'domain mismatch' => ['domain_mismatch'];
        yield 'project mismatch' => ['project_mismatch'];
    }

    #[DataProvider('definitiveRejectionProvider')]
    #[Test]
    public function aDefinitiveRejectionRemovesEveryPositiveState(string $reason): void
    {
        $service = $this->service([
            $this->validResponse('+30 days'),
            static fn () => LicenceValidationResponseDto::fromArray(['success' => false, 'error' => ['code' => 'licence_invalid', 'details' => ['reason' => $reason]]]),
            static fn () => throw new ApiRequestFailedException('AQG API request failed.', 0, null, 'transport_error'),
        ]);
        self::assertTrue($service->validate('example.org', '1.9.6')->valid);
        $this->cache->setToken('token-key', new AccessTokenResult('jwt', 3600, time(), 'pro', []), 3000);
        $this->expireFreshEntries();

        $rejected = $service->validate('example.org', '1.9.6');
        self::assertFalse($rejected->valid);
        self::assertSame($reason, $rejected->reason);
        self::assertFalse($this->graceEntry()['data']['valid'], 'The grace copy must no longer hold the paid state.');
        self::assertNull($this->cache->getToken('token-key'), 'Tokens issued before the rejection must be dropped.');

        // A later outage cannot resurrect the paid state.
        $this->expireFreshEntries();
        self::assertFalse($service->validate('example.org', '1.9.6')->valid);
    }

    #[Test]
    public function aValidAnswerIsCachedNoLongerThanTheEntitlementLasts(): void
    {
        $service = $this->service([$this->validResponse('+90 seconds')]);

        self::assertTrue($service->validate('example.org', '1.9.6')->valid);

        foreach ($this->store as $key => $entry) {
            self::assertLessThanOrEqual(90, $entry['lifetime'], 'Cache entry ' . $key . ' outlives the entitlement.');
        }
        self::assertLessThan(ProConstants::CACHE_TTL_VALID, $this->freshEntry()['lifetime']);
    }

    #[Test]
    public function aTrialIsCappedByItsTrialEnd(): void
    {
        $service = $this->service([static fn () => LicenceValidationResponseDto::fromArray([
            'success' => true,
            'valid' => true,
            'plan' => 'trial',
            'trial_expires_at' => gmdate(DATE_ATOM, time() + 45),
            'features' => ['crawler'],
        ])]);

        self::assertTrue($service->validate('example.org', '1.9.6')->valid);
        self::assertLessThanOrEqual(45, $this->freshEntry()['lifetime']);
        self::assertLessThanOrEqual(45, $this->graceEntry()['lifetime']);
    }

    #[Test]
    public function graceDoesNotOutliveTheEntitlementWhenTheApiIsDown(): void
    {
        $service = $this->service([
            $this->validResponse('+30 days'),
            static fn () => throw new ApiRequestFailedException('AQG API request failed.', 0, null, 'transport_error'),
        ]);
        self::assertTrue($service->validate('example.org', '1.9.6')->valid);

        // The licence ended while the API is unreachable: the grace copy says so itself.
        $grace = $this->graceEntry();
        $grace['data']['expiresAt'] = gmdate(DATE_ATOM, time() - 1);
        $this->store[$this->graceKey()] = $grace;
        $this->expireFreshEntries();

        $result = $service->validate('example.org', '1.9.6');

        self::assertFalse($result->valid);
        self::assertSame('api_unreachable', $result->reason);
    }

    #[Test]
    public function aFreshValidEntryPastTheEntitlementEndIsRevalidated(): void
    {
        $service = $this->service([
            $this->validResponse('+30 days'),
            static fn () => LicenceValidationResponseDto::fromArray(['success' => false, 'error' => ['code' => 'licence_invalid', 'details' => ['reason' => 'expired']]]),
        ]);
        self::assertTrue($service->validate('example.org', '1.9.6')->valid);

        $fresh = $this->freshEntry();
        $fresh['data']['expiresAt'] = gmdate(DATE_ATOM, time() - 1);
        $this->store[$this->freshKey()] = $fresh;

        $result = $service->validate('example.org', '1.9.6');

        self::assertFalse($result->valid);
        self::assertSame('expired', $result->reason);
    }

    #[Test]
    public function aLicenceConfirmedPastItsPaidThroughDateKeepsNoOfflineGrace(): void
    {
        // The API keeps a past_due licence working while Stripe retries the renewal; offline, AQG cannot know.
        $service = $this->service([$this->validResponse('-2 days')]);

        self::assertTrue($service->validate('example.org', '1.9.6')->valid);
        self::assertLessThanOrEqual(ProConstants::CACHE_TTL_TRANSIENT, $this->freshEntry()['lifetime']);
        self::assertNull($this->graceEntry());
    }

    private function validResponse(string $expiresIn): \Closure
    {
        $expiresAt = gmdate(DATE_ATOM, (int)strtotime($expiresIn));

        return static fn () => LicenceValidationResponseDto::fromArray([
            'success' => true,
            'valid' => true,
            'plan' => 'pro',
            'expires_at' => $expiresAt,
            'features' => ['crawler', 'export_pdf'],
        ]);
    }

    private function expireFreshEntries(): void
    {
        unset($this->store[$this->freshKey()]);
    }

    /** @return array{data:mixed,tags:list<string>,lifetime:int}|null */
    private function freshEntry(): ?array
    {
        return $this->store[$this->freshKey()] ?? null;
    }

    /** @return array{data:mixed,tags:list<string>,lifetime:int}|null */
    private function graceEntry(): ?array
    {
        return $this->store[$this->graceKey()] ?? null;
    }

    private function freshKey(): string
    {
        foreach (array_keys($this->store) as $key) {
            if (!str_ends_with($key, '_grace') && $key !== 'token-key') {
                return $key;
            }
        }

        return md5(implode('|', ['aqg_licence', 'aqg_live_key', 'example.org', ProConstants::PRODUCT_SLUG, 'fingerprint']));
    }

    private function graceKey(): string
    {
        return md5(implode('|', ['aqg_licence', 'aqg_live_key', 'example.org', ProConstants::PRODUCT_SLUG, 'fingerprint'])) . '_grace';
    }

    /**
     * @param list<\Closure(): LicenceValidationResponseDto> $responses
     */
    private function service(array $responses): ProLicenceService
    {
        $apiClient = $this->createMock(AqgApiClient::class);
        $apiClient->method('validate')->willReturnCallback(static function () use (&$responses): LicenceValidationResponseDto {
            $next = array_shift($responses);
            self::assertInstanceOf(\Closure::class, $next, 'Unexpected licence API call.');

            return $next();
        });

        $settings = $this->createMock(ProSettings::class);
        $settings->method('isConfigured')->willReturn(true);
        $settings->method('getLicenceKey')->willReturn('aqg_live_key');
        $settings->method('isTrialKey')->willReturn(false);

        $fingerprint = $this->createMock(ProSiteFingerprintService::class);
        $fingerprint->method('collectValidationSites')->willReturn(['example.org']);
        $fingerprint->method('buildFingerprint')->willReturn('fingerprint');

        return new ProLicenceService($apiClient, $this->cache, $settings, $fingerprint);
    }
}
