<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro\Http;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Http\AqgApiClient;
use Priebera\A11yQualityGate\Pro\Http\AqgCrawlerClient;
use Priebera\A11yQualityGate\Pro\Http\ServiceReachability;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * With the AQG service hanging, the Overview waited for one timeout per request — licence, token, summary and three
 * history reads — and took 41 seconds to render (2026-10-16 audit, TYPO3 14.3). After the first transport failure,
 * later requests to the same service fail at once with the same transport error.
 */
final class ServiceReachabilityTest extends TestCase
{
    protected function tearDown(): void
    {
        ServiceReachability::simulateCli(null);
        parent::tearDown();
    }

    #[Test]
    public function aServiceThatDidNotAnswerIsNotWaitedForAgainInTheSameRequest(): void
    {
        ServiceReachability::simulateCli(false);
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())->method('request')
            ->willThrowException(new ConnectException('Operation timed out', new Request('POST', 'https://api.example.org/licence/validate')));

        $apiClient = new AqgApiClient($factory);
        foreach (['first: times out', 'second: skipped'] as $attempt) {
            try {
                $apiClient->validate('aqg_live_key', 'example.org', '2.0.2');
                self::fail($attempt);
            } catch (ApiRequestFailedException $exception) {
                self::assertSame(0, $exception->httpStatus, $attempt);
                self::assertSame('transport_error', $exception->apiErrorCode, $attempt);
            }
        }
    }

    #[Test]
    public function theCrawlerIsStillAskedWhenTheApiDidNotAnswerEvenOnTheSameHost(): void
    {
        ServiceReachability::simulateCli(false);
        ServiceReachability::markUnreachable(ServiceReachability::SERVICE_API, 'https://api.example.org/licence/validate');
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())->method('request')
            ->willThrowException(new ConnectException('Operation timed out', new Request('GET', 'https://api.example.org/crawl/summary/x')));
        putenv('A11Y_QUALITY_GATE_PRO_CRAWLER_BASE_URL=https://api.example.org');

        try {
            (new AqgCrawlerClient($factory))->summary('token', 'a0000000-0000-4000-8000-000000000001');
            self::fail('a transport failure is reported');
        } catch (ApiRequestFailedException) {
        } finally {
            putenv('A11Y_QUALITY_GATE_PRO_CRAWLER_BASE_URL');
        }
    }

    #[Test]
    public function theCliAsksAgain(): void
    {
        ServiceReachability::simulateCli(true);
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::exactly(2))->method('request')
            ->willThrowException(new ConnectException('Operation timed out', new Request('POST', 'https://api.example.org/licence/validate')));

        $apiClient = new AqgApiClient($factory);
        for ($i = 0; $i < 2; $i++) {
            try {
                $apiClient->validate('aqg_live_key', 'example.org', '2.0.2');
            } catch (ApiRequestFailedException) {
            }
        }
    }
}
