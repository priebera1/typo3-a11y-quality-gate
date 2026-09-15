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
use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSubmitResponseDto;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Http\AqgCrawlerClient;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * Regression guard for a stale "0 of 5 used" after completed Free Remote Preview scans.
 *
 * The API reserves a credit inside the accepted submit, while the Overview renders the count from a
 * short display cache. The module reloads as soon as the scan finishes — normally well inside that
 * cache window — so the reload showed the pre-scan count although the server had counted the scan.
 * Every submit outcome that may have changed the server count must drop the cached status; outcomes
 * the API rejects before reserving anything must keep it.
 *
 * Runs against the real ProCacheManager and a TYPO3 cache frontend, so the entry the render path
 * reads is the entry the submit path removes.
 */
final class FreeSubmitEntitlementInvalidationTest extends TestCase
{
    private const SITE_URL = 'https://example.test/';
    private const SITE_ID = 'main';
    private const VERSION = '1.9.2';
    private const START_URL = 'https://example.test/page/';

    #[Test]
    public function anAcceptedSubmitMakesTheNextRenderShowTheReservedCredit(): void
    {
        $crawler = $this->crawler($this->freePayload(0), $this->freePayload(1));
        $crawler->method('submitFree')->willReturn(
            new CrawlerSubmitResponseDto(true, 'job-1', 'queued', 'single_page', null, null, null)
        );
        $service = $this->service($crawler);

        $before = $this->renderTwice($service);
        $service->submit(self::SITE_URL, self::SITE_ID, self::START_URL, self::VERSION, 'idempotency-1');
        $after = $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);

        self::assertSame(0, $before['jobsUsed']);
        self::assertFalse($after['fromCache'], 'The reserved credit must not be hidden behind the display cache.');
        self::assertSame('FREE_AVAILABLE', $after['state']);
        self::assertSame(1, $after['jobsUsed']);
        self::assertSame(5, $after['jobsLimit']);
        self::assertSame(4, $after['scansRemaining']);
        self::assertSame(1, $after['pagesUsed']);
        self::assertSame('2026-09-16T00:00:00.000Z', $after['resetsAt']);
    }

    /**
     * The API reserves the credit inside the submit transaction; a lost or failed answer does not
     * tell whether that transaction committed.
     */
    #[Test]
    #[DataProvider('submitFailuresWithUnknownOutcome')]
    public function aSubmitWithUnknownOutcomeMakesTheNextRenderAskTheApi(int $httpStatus, string $apiErrorCode): void
    {
        $crawler = $this->crawler($this->freePayload(1), $this->freePayload(2));
        $crawler->method('submitFree')->willThrowException(
            new ApiRequestFailedException('Submit answer lost.', $httpStatus, null, $apiErrorCode)
        );
        $service = $this->service($crawler);

        $this->renderTwice($service);
        $this->submitExpectingFailure($service);
        $after = $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);

        self::assertFalse($after['fromCache'], 'A possibly reserved credit must not be hidden behind the display cache.');
        self::assertSame(2, $after['jobsUsed']);
        self::assertSame(3, $after['scansRemaining']);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function submitFailuresWithUnknownOutcome(): iterable
    {
        yield 'connection lost' => [0, ''];
        yield 'internal server error' => [500, ''];
        yield 'bad gateway' => [502, ''];
        yield 'service unavailable' => [503, ''];
        yield 'gateway timeout' => [504, ''];
    }

    /**
     * The API rejects these before it reserves anything, so the cached count is still the server
     * count and the render path keeps its cache.
     */
    #[Test]
    #[DataProvider('submitRejectionsThatReserveNothing')]
    public function aSubmitRejectionThatReservesNothingKeepsTheCachedStatus(int $httpStatus, string $apiErrorCode): void
    {
        $crawler = $this->crawler($this->freePayload(1));
        $crawler->method('submitFree')->willThrowException(
            new ApiRequestFailedException('Submit rejected.', $httpStatus, null, $apiErrorCode)
        );
        $service = $this->service($crawler);

        $this->renderTwice($service);
        $this->submitExpectingFailure($service);
        $after = $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);

        self::assertTrue($after['fromCache']);
        self::assertSame(1, $after['jobsUsed']);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function submitRejectionsThatReserveNothing(): iterable
    {
        yield 'proof not verified' => [403, 'invalid_installation_proof'];
        yield 'idempotency key reused' => [409, 'idempotency_key_reused'];
        yield 'unsafe site url' => [400, 'unsafe_site_url'];
        yield 'request rate limit' => [429, 'rate_limit_exceeded'];
    }

    /**
     * @param array<string, mixed> ...$statusPayloads one per expected API status request, in order
     */
    private function crawler(array ...$statusPayloads): AqgCrawlerClient
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->expects(self::exactly(count($statusPayloads)))
            ->method('entitlementStatus')
            ->willReturnOnConsecutiveCalls(...$statusPayloads);

        return $crawler;
    }

    /**
     * Two module loads before the submit; the second one proves the status is served from cache.
     *
     * @return array<string, mixed> the first render
     */
    private function renderTwice(FreeRemotePreviewService $service): array
    {
        $first = $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION);
        self::assertTrue(
            $service->getEntitlementStatus(self::SITE_URL, self::SITE_ID, self::VERSION)['fromCache'],
            'Precondition: repeated renders are answered from the display cache.'
        );

        return $first;
    }

    private function submitExpectingFailure(FreeRemotePreviewService $service): void
    {
        try {
            $service->submit(self::SITE_URL, self::SITE_ID, self::START_URL, self::VERSION, 'idempotency-2');
        } catch (FreePreviewException) {
            return;
        }

        self::fail('A failed submit must surface as a Free Preview error.');
    }

    /**
     * @return array<string, mixed> the API's /entitlement/status answer for a Free installation
     */
    private function freePayload(int $jobsUsed, bool $available = true): array
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
                'resetsAt' => '2026-09-16T00:00:00.000Z',
            ],
            'upgradeUrl' => 'https://example.test/pricing',
        ];
    }

    private function service(AqgCrawlerClient $crawler): FreeRemotePreviewService
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

        // Let the CacheManager build the cache: it knows the backend constructor of TYPO3 13 and 14.
        $cacheManager = new CacheManager();
        $cacheManager->setCacheConfigurations([
            ProConstants::CACHE_IDENTIFIER => [
                'frontend' => VariableFrontend::class,
                'backend' => TransientMemoryBackend::class,
            ],
        ]);

        return new FreeRemotePreviewService($token, $crawler, $identity, new ProCacheManager($cacheManager));
    }
}
