<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\FreePreview;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use Priebera\A11yQualityGate\FreePreview\FreeAccessTokenService;
use Priebera\A11yQualityGate\FreePreview\FreePreviewException;
use Priebera\A11yQualityGate\FreePreview\FreeRemotePreviewService;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResult;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Http\AqgCrawlerClient;

final class FreeRemotePreviewServiceTest extends TestCase
{
    #[Test]
    public function availableStatusMapsApiQuotaWithoutLocalCalculation(): void
    {
        $service = $this->serviceWithStatus([
            'entitlement' => 'free_daily',
            'remoteCrawlerVisible' => true,
            'freeDaily' => [
                'available' => true,
                'jobsUsed' => 0,
                'jobsLimit' => 5,
                'pagesUsed' => 0,
                'pagesLimit' => 5,
                'resetsAt' => '2026-08-11T00:00:00.000Z',
            ],
            'upgradeUrl' => 'https://example.test/pricing',
        ]);

        $status = $service->getEntitlementStatus('https://example.test/', 'main', '1.9.0');

        self::assertSame('FREE_AVAILABLE', $status['state']);
        self::assertTrue($status['available']);
        self::assertSame(0, $status['jobsUsed']);
        self::assertSame(5, $status['jobsLimit']);
        self::assertSame(5, $status['scansRemaining']);
        self::assertSame(0, $status['pagesUsed']);
        self::assertSame(5, $status['pagesLimit']);
        self::assertSame('2026-08-11T00:00:00.000Z', $status['resetsAt']);
    }

    #[Test]
    public function usedStatusMapsApiQuota(): void
    {
        $service = $this->serviceWithStatus([
            'entitlement' => 'free_daily',
            'remoteCrawlerVisible' => true,
            'freeDaily' => [
                'available' => false,
                'jobsUsed' => 5,
                'jobsLimit' => 5,
                'pagesUsed' => 5,
                'pagesLimit' => 5,
                'resetsAt' => '2026-08-11T00:00:00.000Z',
            ],
        ]);

        $status = $service->getEntitlementStatus('https://example.test/', 'main', '1.9.0');

        self::assertSame('FREE_USED_TODAY', $status['state']);
        self::assertFalse($status['available']);
        self::assertSame(5, $status['jobsUsed']);
        self::assertSame(0, $status['scansRemaining']);
        self::assertSame(5, $status['pagesUsed']);
    }

    #[DataProvider('availableUsageProvider')]
    #[Test]
    public function availableUsageMapsEveryIntermediateFreeCredit(int $used, int $remaining): void
    {
        $status = $this->serviceWithStatus([
            'entitlement' => 'free_daily',
            'remoteCrawlerVisible' => true,
            'freeDaily' => [
                'available' => true,
                'jobsUsed' => $used,
                'jobsLimit' => 5,
                'pagesUsed' => $used,
                'pagesLimit' => 5,
                'resetsAt' => '2026-08-11T00:00:00.000Z',
            ],
        ])->getEntitlementStatus('https://example.test/', 'main', '1.9.0');

        self::assertSame('FREE_AVAILABLE', $status['state']);
        self::assertSame($used, $status['jobsUsed']);
        self::assertSame($remaining, $status['scansRemaining']);
    }

    /** @return iterable<string, array{int,int}> */
    public static function availableUsageProvider(): iterable
    {
        yield 'one of five' => [1, 4];
        yield 'two of five' => [2, 3];
        yield 'three of five' => [3, 2];
        yield 'four of five' => [4, 1];
    }

    #[Test]
    public function incompleteSuccessfulStatusResponseIsAContractError(): void
    {
        $status = $this->serviceWithStatus([
            'entitlement' => 'free_daily',
            'remoteCrawlerVisible' => true,
        ])->getEntitlementStatus('https://example.test/', 'main', '1.9.0');

        self::assertSame('API_CONTRACT_ERROR', $status['state']);
        self::assertSame('invalid_entitlement_status_contract', $status['errorCode']);
        self::assertFalse($status['retryable']);
    }

    #[Test]
    public function unavailableStatusMapsToSafeApiState(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->method('entitlementStatus')->willThrowException(new ApiRequestFailedException(
            'upstream unavailable',
            503,
            null,
            'free_daily_status_unavailable',
        ));

        $status = $this->service($crawler)->getEntitlementStatus('https://example.test/', 'main', '1.9.0');

        self::assertSame('API_UNAVAILABLE', $status['state']);
        self::assertSame('free_daily_status_unavailable', $status['errorCode']);
        self::assertArrayNotHasKey('installationId', $status);
    }

    #[Test]
    public function genericStatusRateLimitMapsToRetryableUnavailableState(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->method('entitlementStatus')->willThrowException(new ApiRequestFailedException(
            'rate limited',
            429,
            null,
            'rate_limit_exceeded',
        ));

        $status = $this->service($crawler)->getEntitlementStatus('https://example.test/', 'main', '1.9.0');

        self::assertSame('API_UNAVAILABLE', $status['state']);
        self::assertSame('rate_limit_exceeded', $status['errorCode']);
        self::assertTrue($status['retryable']);
    }

    #[Test]
    public function missingLiveEntitlementRouteIsNotHiddenAsGenericApiUnavailable(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->method('entitlementStatus')->willThrowException(new ApiRequestFailedException(
            'route missing',
            404,
            null,
            'route_not_found',
        ));

        $status = $this->service($crawler)->getEntitlementStatus('https://example.test/', 'main', '1.9.0');

        self::assertSame('ENDPOINT_NOT_FOUND', $status['state']);
        self::assertSame('route_not_found', $status['errorCode']);
        self::assertTrue($status['retryable']);
    }

    #[DataProvider('statusContractErrorProvider')]
    #[Test]
    public function boundedStatusContractErrorsRemainDistinct(string $code, int $httpStatus, string $expectedState): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->method('entitlementStatus')->willThrowException(new ApiRequestFailedException(
            'bounded status error',
            $httpStatus,
            null,
            $code,
        ));

        $status = $this->service($crawler)->getEntitlementStatus('https://example.test/', 'main', '1.9.0');

        self::assertSame($expectedState, $status['state']);
        self::assertSame($code, $status['errorCode']);
        self::assertNotSame('API_UNAVAILABLE', $status['state']);
    }

    /** @return iterable<string, array{string,int,string}> */
    public static function statusContractErrorProvider(): iterable
    {
        yield 'installation missing' => ['missing_installation_id', 400, 'MISSING_INSTALLATION_ID'];
        yield 'installation mismatch' => ['installation_identity_mismatch', 403, 'INSTALLATION_IDENTITY_MISMATCH'];
        yield 'site mismatch' => ['site_identity_mismatch', 403, 'SITE_IDENTITY_MISMATCH'];
        yield 'invalid token' => ['invalid_token', 401, 'TOKEN_ERROR'];
        yield 'unknown client contract rejection' => ['new_client_error', 400, 'API_CONTRACT_ERROR'];
    }

    #[Test]
    public function tokenFailureMapsToSafeTokenState(): void
    {
        $token = $this->createMock(FreeAccessTokenService::class);
        $token->method('getValidToken')->willThrowException(new FreePreviewException(
            'Free Remote Preview authentication failed.',
            'TOKEN_ERROR',
            'token_error',
            503,
        ));
        $identity = $this->createMock(InstallationIdentityServiceInterface::class);
        $service = new FreeRemotePreviewService(
            $token,
            $this->createMock(AqgCrawlerClient::class),
            $identity,
            $this->missingCache(),
        );

        $status = $service->getEntitlementStatus('https://example.test/', 'main', '1.9.0');

        self::assertSame('TOKEN_ERROR', $status['state']);
        self::assertSame('token_error', $status['errorCode']);
        self::assertFalse($status['available']);
    }

    #[DataProvider('paidEntitlementProvider')]
    #[Test]
    public function higherEntitlementsAreNeverMappedAsFree(string $entitlement, string $expectedState): void
    {
        $status = $this->serviceWithStatus([
            'entitlement' => $entitlement,
            'remoteCrawlerVisible' => true,
            'freeDaily' => null,
        ])->getEntitlementStatus('https://example.test/', 'main', '1.9.0');

        self::assertFalse($status['isFree']);
        self::assertSame($expectedState, $status['state']);
    }

    /** @return iterable<string, array{string,string}> */
    public static function paidEntitlementProvider(): iterable
    {
        yield 'trial' => ['trial', 'TRIAL'];
        yield 'pro' => ['pro', 'PRO'];
        yield 'agency' => ['agency', 'AGENCY'];
    }

    #[Test]
    public function freeQuotaComesBackUnchangedAfterAProEntitlementTransition(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->method('entitlementStatus')->willReturnOnConsecutiveCalls(
            [
                'entitlement' => 'free_daily',
                'remoteCrawlerVisible' => true,
                'freeDaily' => [
                    'available' => true,
                    'jobsUsed' => 3,
                    'jobsLimit' => 5,
                    'pagesUsed' => 3,
                    'pagesLimit' => 5,
                    'resetsAt' => '2026-08-11T00:00:00.000Z',
                ],
            ],
            [
                'entitlement' => 'pro',
                'remoteCrawlerVisible' => true,
                'freeDaily' => null,
            ],
            [
                'entitlement' => 'free_daily',
                'remoteCrawlerVisible' => true,
                'freeDaily' => [
                    'available' => true,
                    'jobsUsed' => 3,
                    'jobsLimit' => 5,
                    'pagesUsed' => 3,
                    'pagesLimit' => 5,
                    'resetsAt' => '2026-08-11T00:00:00.000Z',
                ],
            ],
        );
        $service = $this->service($crawler);

        $before = $service->getEntitlementStatus('https://example.test/', 'main', '1.9.0');
        $pro = $service->getEntitlementStatus('https://example.test/', 'main', '1.9.0');
        $after = $service->getEntitlementStatus('https://example.test/', 'main', '1.9.0');

        self::assertSame(3, $before['jobsUsed']);
        self::assertSame('PRO', $pro['state']);
        self::assertSame(3, $after['jobsUsed']);
        self::assertSame(2, $after['scansRemaining']);
    }

    #[DataProvider('freeSubmitErrorProvider')]
    #[Test]
    public function submitErrorsMapToSafeStates(string $code, int $httpStatus, string $expectedState): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->method('submitFree')->willThrowException(new ApiRequestFailedException(
            'sanitized upstream error',
            $httpStatus,
            null,
            $code,
            ['resetsAt' => '2026-08-11T00:00:00.000Z'],
        ));

        try {
            $this->service($crawler)->submit(
                'https://example.test/',
                'main',
                'https://example.test/',
                '1.9.0',
                'aqg-free-key',
            );
            self::fail('Expected Free Preview exception.');
        } catch (FreePreviewException $exception) {
            self::assertSame($expectedState, $exception->state);
            self::assertSame($code, $exception->errorCode);
            self::assertSame($httpStatus, $exception->httpStatus);
        }
    }

    /** @return iterable<string, array{string,int,string}> */
    public static function freeSubmitErrorProvider(): iterable
    {
        yield 'daily limit' => ['free_daily_limit_reached', 429, 'FREE_LIMIT_REACHED'];
        yield 'feature' => ['feature_not_available', 403, 'FEATURE_NOT_AVAILABLE'];
        yield 'idempotency' => ['idempotency_key_reused', 409, 'IDEMPOTENCY_CONFLICT'];
        yield 'proof' => ['invalid_installation_proof', 403, 'PROOF_ERROR'];
    }

    /** @param array<string, mixed> $payload */
    private function serviceWithStatus(array $payload): FreeRemotePreviewService
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->method('entitlementStatus')->willReturn($payload);

        return $this->service($crawler);
    }

    private function service(AqgCrawlerClient $crawler, ?ProCacheManager $cache = null): FreeRemotePreviewService
    {
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
        $identity = $this->createMock(InstallationIdentityServiceInterface::class);
        $identity->method('getOrCreateInstallationId')->willReturn('anonymous-id');

        return new FreeRemotePreviewService($token, $crawler, $identity, $cache ?? $this->missingCache());
    }

    /**
     * Default double: always a cache miss, so the existing mapping tests keep hitting the client.
     */
    private function missingCache(): ProCacheManager
    {
        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('getDisplayPayload')->willReturn(null);

        return $cache;
    }
}
