<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\RemoteScanHistoryService;

/**
 * Only licensed users see the regression signal on a frontend page. When the API cannot give one, the message
 * follows the API's status and error code — a page without compatible scans is not a licence problem.
 */
final class RemoteScanHistoryRegressionErrorTest extends TestCase
{
    /**
     * @return iterable<string, array{?ApiRequestFailedException, string}>
     */
    public static function failures(): iterable
    {
        yield 'no compatible scan of this page yet' => [
            new ApiRequestFailedException('AQG crawler HTTP 404: Resource not found | code=not_found', 404, null, 'not_found'),
            'No regression signal yet: this page has no compatible frontend scans to compare.',
        ];
        yield 'history switched off by the AQG service' => [
            new ApiRequestFailedException('AQG crawler HTTP 404: Crawl history is not enabled | code=history_disabled', 404, null, 'history_disabled'),
            'Regression signal is available with a remote-scanning licence (Trial, PRO or Agency) when enabled.',
        ];
        yield 'endpoint missing in this environment' => [
            new ApiRequestFailedException('AQG crawler HTTP 404', 404, null, 'route_not_found'),
            'Regression signal is not available for this licence or environment.',
        ];
        yield 'capability refused' => [
            new ApiRequestFailedException('AQG crawler HTTP 403 | code=feature_not_available', 403, null, 'feature_not_available'),
            'Regression signal is not available for this licence or environment.',
        ];
        yield 'page URL missing' => [
            new ApiRequestFailedException('AQG crawler HTTP 400 | code=missing_start_url', 400, null, 'missing_start_url'),
            'Internal configuration issue: page URL is missing.',
        ];
        yield '"404" only inside the request details' => [
            new ApiRequestFailedException(
                'AQG crawler HTTP 500: Internal error | code=internal_error'
                . ' | url=https://api.example/crawl/regression-alert?startUrl=https%3A%2F%2Fexample.test%2F404-page'
                . ' | auth={"accessTokenLength":404}',
                500,
                null,
                'internal_error',
            ),
            'Regression signal is not available right now.',
        ];
        yield 'token refresh without an API answer' => [
            null,
            'Regression signal is not available right now.',
        ];
    }

    #[DataProvider('failures')]
    #[Test]
    public function aFailedRegressionRequestIsExplainedByTheApiAnswer(?ApiRequestFailedException $apiError, string $expectedMessage): void
    {
        $context = $this->createMock(ExtensionContextService::class);
        $context->method('getNormalizedDomainFromSiteBase')->willReturn('example.test');
        $context->method('getExtensionVersion')->willReturn('1.9.5');

        $crawler = $this->createMock(ProCrawlerService::class);
        $crawler->method('getRegressionAlert')->willThrowException(new TokenRefreshException(
            'Remote crawler regression alert request failed: ' . ($apiError?->getMessage() ?? 'token request failed with HTTP 404'),
            0,
            $apiError,
        ));

        $alert = (new RemoteScanHistoryService($context, $crawler))
            ->loadRegressionAlert('https://example.test/', 'main', 'single_page', 'https://example.test/about');

        self::assertFalse($alert['available']);
        self::assertSame($expectedMessage, $alert['message']);
    }
}
