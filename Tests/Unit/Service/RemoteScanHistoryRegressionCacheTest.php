<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\RemoteScanHistoryService;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Audit C16/X1: every Overview render asked the AQG service for the regression signal synchronously, and a scan
 * without a comparison answered 404 each time. The answer is now kept per site, scan type, URL, language and the
 * latest scan, so a new scan asks again; a 404 "nothing to compare" is kept too, a server error never.
 */
final class RemoteScanHistoryRegressionCacheTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $store = [];

    #[Test]
    public function anUnchangedScanReusesTheRegressionAnswer(): void
    {
        $crawler = $this->createMock(ProCrawlerService::class);
        $crawler->expects(self::once())->method('getRegressionAlert')->willReturn(['status' => 'ok', 'regressionDetected' => false]);
        $service = $this->service($crawler);

        $first = $service->loadRegressionAlert('https://example.test/', 'main', 'sitemap', 'https://example.test/', 0, 'job-2@1790000000');
        $second = $service->loadRegressionAlert('https://example.test/', 'main', 'sitemap', 'https://example.test/', 0, 'job-2@1790000000');

        self::assertSame($first, $second);
    }

    #[Test]
    public function aNewScanAsksAgain(): void
    {
        $crawler = $this->createMock(ProCrawlerService::class);
        $crawler->expects(self::exactly(2))->method('getRegressionAlert')->willReturn(['status' => 'ok']);
        $service = $this->service($crawler);

        $service->loadRegressionAlert('https://example.test/', 'main', 'sitemap', 'https://example.test/', 0, 'job-2@1790000000');
        $service->loadRegressionAlert('https://example.test/', 'main', 'sitemap', 'https://example.test/', 0, 'job-3@1790000500');
    }

    #[Test]
    public function nothingToCompareIsKeptAndAServerErrorIsNot(): void
    {
        $notFound = $this->createMock(ProCrawlerService::class);
        $notFound->expects(self::once())->method('getRegressionAlert')->willThrowException($this->failure(404, 'not_found'));
        $service = $this->service($notFound);
        $alert = $service->loadRegressionAlert('https://example.test/', 'main', 'single_page', 'https://example.test/a', 0, 'job-2@1');
        $service->loadRegressionAlert('https://example.test/', 'main', 'single_page', 'https://example.test/a', 0, 'job-2@1');
        self::assertFalse($alert['available']);

        $this->store = [];
        $serverError = $this->createMock(ProCrawlerService::class);
        $serverError->expects(self::exactly(2))->method('getRegressionAlert')->willThrowException($this->failure(500, 'internal_error'));
        $service = $this->service($serverError);
        $service->loadRegressionAlert('https://example.test/', 'main', 'sitemap', 'https://example.test/', 0, 'job-2@1');
        $service->loadRegressionAlert('https://example.test/', 'main', 'sitemap', 'https://example.test/', 0, 'job-2@1');
    }

    private function failure(int $status, string $code): TokenRefreshException
    {
        return new TokenRefreshException(
            'Remote crawler regression alert request failed',
            0,
            new ApiRequestFailedException('AQG crawler HTTP ' . $status . ' | code=' . $code, $status, null, $code),
        );
    }

    private function service(ProCrawlerService $crawler): RemoteScanHistoryService
    {
        $context = $this->createMock(ExtensionContextService::class);
        $context->method('getNormalizedDomainFromSiteBase')->willReturn('example.test');
        $context->method('getExtensionVersion')->willReturn('1.9.9');

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->store[$key] ?? false);
        $cache->method('set')->willReturnCallback(function (string $key, mixed $value): void {
            $this->store[$key] = $value;
        });
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);

        return new RemoteScanHistoryService($context, $crawler, $cacheManager);
    }
}
