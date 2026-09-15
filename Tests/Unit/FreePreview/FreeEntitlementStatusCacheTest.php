<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\FreePreview;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use Priebera\A11yQualityGate\FreePreview\FreeAccessTokenService;
use Priebera\A11yQualityGate\FreePreview\FreePreviewException;
use Priebera\A11yQualityGate\FreePreview\FreeRemotePreviewService;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSubmitResponseDto;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Http\AqgCrawlerClient;

/**
 * The Overview render path must not depend on a live API round trip for every page load.
 *
 * Guards the safety envelope of that cache: Free display payloads only, never a paid entitlement,
 * never a secret, short TTLs, and an explicit refresh path for the Retry button.
 */
final class FreeEntitlementStatusCacheTest extends TestCase
{
    private const SITE_URL = 'https://example.test/';
    private const SITE_ID = 'main';
    private const VERSION = '1.9.1';

    #[Test]
    public function repeatedModuleLoadsHitTheApiOnlyOnceWithinTheCacheWindow(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->expects(self::once())
            ->method('entitlementStatus')
            ->willReturn($this->freePayload());

        $store = new InMemoryDisplayCache();
        $service = $this->service($crawler, $store);

        $first = $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);
        $second = $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);
        $third = $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);

        self::assertSame('FREE_AVAILABLE', $first['state']);
        self::assertFalse($first['fromCache']);
        self::assertTrue($second['fromCache']);
        self::assertTrue($third['fromCache']);
        self::assertSame($first['jobsUsed'], $second['jobsUsed']);
        self::assertSame($first['resetsAt'], $second['resetsAt']);
    }

    #[Test]
    public function expiredCacheEntryIsRefetched(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->expects(self::exactly(2))
            ->method('entitlementStatus')
            ->willReturn($this->freePayload());

        $store = new InMemoryDisplayCache();
        $service = $this->service($crawler, $store);

        $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);
        $store->expireAll();
        $refetched = $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);

        self::assertFalse($refetched['fromCache']);
    }

    #[Test]
    public function forceRefreshBypassesTheCacheSoRetryAlwaysReachesTheApi(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->expects(self::exactly(2))
            ->method('entitlementStatus')
            ->willReturn($this->freePayload());

        $store = new InMemoryDisplayCache();
        $service = $this->service($crawler, $store);

        $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);
        $forced = $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION, true);

        self::assertFalse($forced['fromCache']);
    }

    #[Test]
    public function aSuccessfulFreeSubmitMakesTheNextRenderShowTheConsumedCredit(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->expects(self::exactly(2))
            ->method('entitlementStatus')
            ->willReturnOnConsecutiveCalls($this->freePayload(1), $this->freePayload(2));
        $crawler->method('submitFree')->willReturn(
            new CrawlerSubmitResponseDto(true, 'job-1', 'queued', 'single_page', null, null, null)
        );

        $service = $this->service($crawler, new InMemoryDisplayCache(), true);

        self::assertSame(1, $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION)['jobsUsed']);
        $service->submit(self::SITE_URL, self::SITE_ID, self::SITE_URL . 'page/', self::VERSION, 'idempotency-1');
        $after = $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);

        self::assertFalse($after['fromCache'], 'A consumed credit must not be hidden behind the display cache.');
        self::assertSame(2, $after['jobsUsed']);
    }

    #[Test]
    public function aDailyLimitRejectionMakesTheNextRenderShowTheUsedState(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->expects(self::exactly(2))
            ->method('entitlementStatus')
            ->willReturnOnConsecutiveCalls($this->freePayload(4), $this->freePayload(5, false));
        $crawler->method('submitFree')->willThrowException(
            new ApiRequestFailedException('Daily limit reached.', 429, null, 'free_daily_limit_reached')
        );

        $service = $this->service($crawler, new InMemoryDisplayCache(), true);
        self::assertSame('FREE_AVAILABLE', $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION)['state']);

        try {
            $service->submit(self::SITE_URL, self::SITE_ID, self::SITE_URL . 'page/', self::VERSION, 'idempotency-6');
            self::fail('A daily-limit rejection must surface as a Free Preview error.');
        } catch (FreePreviewException $exception) {
            self::assertStringContainsString('daily scan limit', $exception->getMessage());
        }

        $after = $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);
        self::assertFalse($after['fromCache'], 'A stale "available" state must not survive a limit rejection.');
        self::assertSame('FREE_USED_TODAY', $after['state']);
    }

    #[Test]
    public function unavailableApiIsNegativeCachedBrieflyToAvoidRequestStorms(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->expects(self::once())
            ->method('entitlementStatus')
            ->willThrowException(new ApiRequestFailedException('gateway timeout', 504));

        $store = new InMemoryDisplayCache();
        $service = $this->service($crawler, $store);

        $first = $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);
        $second = $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);

        self::assertSame('API_UNAVAILABLE', $first['state']);
        self::assertTrue($first['retryable']);
        self::assertTrue($second['retryable']);
        self::assertTrue($second['fromCache']);
        self::assertFalse($second['available'], 'A cached failure must never report Free availability.');
        self::assertLessThanOrEqual(
            ProConstants::FREE_ENTITLEMENT_ERROR_CACHE_TTL,
            $store->ttlFor(array_key_first($store->entries())),
            'Failures must use the short negative TTL, not the success TTL.'
        );
    }

    #[Test]
    public function paidEntitlementIsNeverCached(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->expects(self::exactly(2))
            ->method('entitlementStatus')
            ->willReturn(['entitlement' => 'pro', 'remoteCrawlerVisible' => true]);

        $store = new InMemoryDisplayCache();
        $service = $this->service($crawler, $store);

        $first = $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);
        $second = $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);

        self::assertFalse($first['isFree']);
        self::assertSame('PRO', $first['state']);
        self::assertSame([], $store->entries(), 'A paid entitlement must never be written to cache.');
        self::assertFalse($second['fromCache'] ?? false);
    }

    #[Test]
    public function aStaleFreeCacheCannotUpgradeIntoAPaidEntitlement(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->method('entitlementStatus')->willReturn($this->freePayload());

        $store = new InMemoryDisplayCache();
        // Someone poisons the display cache with a paid-looking payload.
        foreach (['aqg_free_entitlement_' . hash('sha256', self::SITE_URL . '|' . self::SITE_ID)] as $key) {
            $store->set($key, ['isFree' => false, 'entitlement' => 'agency', 'state' => 'AGENCY'], 60);
        }

        $status = $this->service($crawler, $store)
            ->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);

        self::assertTrue($status['isFree']);
        self::assertSame('free_daily', $status['entitlement']);
        self::assertFalse($status['fromCache']);
    }

    #[Test]
    public function cachedPayloadNeverContainsSecrets(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->method('entitlementStatus')->willReturn($this->freePayload());

        $store = new InMemoryDisplayCache();
        $this->service($crawler, $store)->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);

        $stored = strtolower(json_encode($store->entries(), JSON_THROW_ON_ERROR));

        foreach (['accesstoken', 'installationid', 'bearer', 'licence', 'license', 'jwt', 'anonymous-id'] as $secret) {
            self::assertStringNotContainsString($secret, $stored);
        }
    }

    #[Test]
    public function differentSitesDoNotShareACacheEntry(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->expects(self::exactly(2))
            ->method('entitlementStatus')
            ->willReturn($this->freePayload());

        $store = new InMemoryDisplayCache();
        $service = $this->service($crawler, $store);

        $service->getEntitlementStatus(self::SITE_URL, 'site-a', self::VERSION);
        $other = $service->getEntitlementStatus(self::SITE_URL, 'site-b', self::VERSION);

        self::assertFalse($other['fromCache']);
        self::assertCount(2, $store->entries());
    }

    #[Test]
    public function entitlementStatusUsesAShorterTimeoutThanInteractiveCalls(): void
    {
        self::assertLessThan(
            ProConstants::REQUEST_TIMEOUT,
            ProConstants::FREE_ENTITLEMENT_REQUEST_TIMEOUT,
            'The render-path call must not use the full interactive timeout.'
        );
        self::assertLessThanOrEqual(5.0, ProConstants::FREE_ENTITLEMENT_REQUEST_TIMEOUT);
        self::assertLessThanOrEqual(300, ProConstants::FREE_ENTITLEMENT_CACHE_TTL);
    }

    #[Test]
    public function tokenFailureIsStillReportedAsARetryableFreeState(): void
    {
        $token = $this->createMock(FreeAccessTokenService::class);
        $token->method('getValidToken')->willThrowException(
            new FreePreviewException('nope', 'TOKEN_ERROR', 'token_error', 503)
        );

        $service = new FreeRemotePreviewService(
            $token,
            $this->createMock(AqgCrawlerClient::class),
            $this->identity(),
            $this->cacheDouble(new InMemoryDisplayCache()),
        );

        $status = $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);

        self::assertSame('TOKEN_ERROR', $status['state']);
        self::assertFalse($status['available']);
        self::assertTrue($status['retryable']);
    }

    /**
     * @return array<string, mixed>
     */
    private function freePayload(int $jobsUsed = 1, bool $available = true): array
    {
        return [
            'entitlement' => 'free_daily',
            'remoteCrawlerVisible' => true,
            'freeDaily' => [
                'available' => $available,
                'jobsUsed' => $jobsUsed,
                'jobsLimit' => 5,
                'pagesUsed' => $jobsUsed,
                'pagesLimit' => 5,
                'resetsAt' => '2026-09-08T00:00:00.000Z',
            ],
            'upgradeUrl' => 'https://example.test/pricing',
        ];
    }

    private function service(
        AqgCrawlerClient $crawler,
        InMemoryDisplayCache $store,
        bool $withInvalidation = false,
    ): FreeRemotePreviewService {
        $token = $this->createMock(FreeAccessTokenService::class);
        $token->method('getValidToken')->willReturn(new AccessTokenResult(
            'jwt',
            3600,
            time(),
            'free',
            ['crawler'],
            'free_daily',
            ['crawler_submit', 'crawler_status', 'crawler_results', 'crawler_summary'],
        ));

        $cache = $this->cacheDouble($store);
        if ($withInvalidation) {
            $cache->method('removeDisplayPayload')->willReturnCallback(
                static function (string $key) use ($store): void {
                    $store->remove($key);
                }
            );
        }

        return new FreeRemotePreviewService($token, $crawler, $this->identity(), $cache);
    }

    private function cacheDouble(InMemoryDisplayCache $store): ProCacheManager
    {
        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('getDisplayPayload')->willReturnCallback(
            static fn (string $key): ?array => $store->get($key)
        );
        $cache->method('setDisplayPayload')->willReturnCallback(
            static function (string $key, array $payload, int $ttl) use ($store): void {
                $store->set($key, $payload, $ttl);
            }
        );

        return $cache;
    }

    private function identity(): InstallationIdentityServiceInterface
    {
        $identity = $this->createMock(InstallationIdentityServiceInterface::class);
        $identity->method('getOrCreateInstallationId')->willReturn('anonymous-id');

        return $identity;
    }
}

/**
 * Deterministic store behind the ProCacheManager test double.
 *
 * Expiry is driven explicitly instead of by wall-clock time, so cache tests stay reproducible.
 */
final class InMemoryDisplayCache
{
    /** @var array<string, array{payload: array<string, mixed>, ttl: int}> */
    private array $store = [];

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $cacheKey): ?array
    {
        return $this->store[$cacheKey]['payload'] ?? null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function set(string $cacheKey, array $payload, int $ttl): void
    {
        $this->store[$cacheKey] = ['payload' => $payload, 'ttl' => max(1, $ttl)];
    }

    public function remove(string $cacheKey): void
    {
        unset($this->store[$cacheKey]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function entries(): array
    {
        return array_map(static fn (array $entry): array => $entry['payload'], $this->store);
    }

    public function ttlFor(?string $cacheKey): int
    {
        return $cacheKey === null ? 0 : ($this->store[$cacheKey]['ttl'] ?? 0);
    }

    public function expireAll(): void
    {
        $this->store = [];
    }
}
