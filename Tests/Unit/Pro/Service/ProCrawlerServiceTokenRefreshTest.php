<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerResultsResponseDto;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerStatusResponseDto;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResult;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Http\AqgCrawlerClient;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Pro\Service\ProLicenceService;
use Priebera\A11yQualityGate\Pro\Service\ProTokenService;

/**
 * The AQG service revokes a cached scanner token before it expires — a removed project, a cancelled licence, or a plan
 * change whose rights the token no longer matches. Scan status and results then ask for a new token once instead of
 * failing until the cached one runs out (the history reads already did).
 */
final class ProCrawlerServiceTokenRefreshTest extends TestCase
{
    #[Test]
    public function aRevokedTokenIsReplacedOnceForStatusAndResults(): void
    {
        $tokens = $this->createMock(ProTokenService::class);
        $tokens->expects(self::exactly(4))->method('getValidToken')->willReturnCallback(
            static fn (string $domain, string $version, bool $forceRefresh = false): AccessTokenResult => new AccessTokenResult($forceRefresh ? 'fresh' : 'cached', 3600, time(), 'pro', []),
        );
        $client = $this->createMock(AqgCrawlerClient::class);
        $revoked = new ApiRequestFailedException('Crawler request failed | http=401 | code=token_revoked', 401, null, 'token_revoked');
        $client->method('status')->willReturnCallback(static function (string $token, string $jobId) use ($revoked): CrawlerStatusResponseDto {
            if ($token !== 'fresh') {
                throw $revoked;
            }
            return CrawlerStatusResponseDto::fromArray(['jobId' => $jobId, 'status' => 'running']);
        });
        $client->method('results')->willReturnCallback(static function (string $token, string $jobId) use ($revoked): CrawlerResultsResponseDto {
            if ($token !== 'fresh') {
                throw $revoked;
            }
            return CrawlerResultsResponseDto::fromArray(['jobId' => $jobId, 'status' => 'completed', 'pages' => []]);
        });

        $service = new ProCrawlerService($this->createMock(ProLicenceService::class), $tokens, $client);

        self::assertSame('a0000000-0000-4000-8000-000000000001', $service->getStatus('example.org', '2.0.2', 'a0000000-0000-4000-8000-000000000001')->jobId);
        self::assertSame('a0000000-0000-4000-8000-000000000001', $service->getResults('example.org', '2.0.2', 'a0000000-0000-4000-8000-000000000001')->jobId);
    }

    /**
     * A submit is not idempotent: a timeout may mean the crawler accepted the scan. The retry used to match "401" or
     * "invalid token" anywhere in the exception message, which carries the request URL and payload.
     */
    #[Test]
    public function aSubmitIsNotRepeatedForAMessageThatMerelyMentionsATokenProblem(): void
    {
        $tokens = $this->createMock(ProTokenService::class);
        $tokens->expects(self::once())->method('getValidToken')->willReturn(new AccessTokenResult('cached', 3600, time(), 'pro', []));
        $client = $this->createMock(AqgCrawlerClient::class);
        $client->expects(self::once())->method('submit')->willThrowException(new ApiRequestFailedException(
            'AQG crawler request failed: Operation timed out | url=https://api.example.org/crawl/submit | payload={"startUrl":"https://example.org/error-401/","note":"invalid token page"}',
            0,
        ));

        $licence = $this->createMock(ProLicenceService::class);
        $licence->method('validate')->willReturn(new LicenceValidationResult(valid: true, plan: 'pro', features: ['crawler']));

        $this->expectException(TokenRefreshException::class);
        (new ProCrawlerService($licence, $tokens, $client))->submit(
            'example.org', '2.0.2', 'main', 'https://example.org/error-401/', null, RemoteScanSourceType::SinglePage, 1, false,
        );
    }

    #[Test]
    public function otherFailuresAreNotRetriedAndASecondRefusalIsReported(): void
    {
        $tokens = $this->createMock(ProTokenService::class);
        $tokens->method('getValidToken')->willReturn(new AccessTokenResult('cached', 3600, time(), 'pro', []));
        $client = $this->createMock(AqgCrawlerClient::class);
        $client->expects(self::once())->method('status')->willThrowException(new ApiRequestFailedException('Crawler request failed | http=404', 404, null, 'not_found'));

        $service = new ProCrawlerService($this->createMock(ProLicenceService::class), $tokens, $client);
        try {
            $service->getStatus('example.org', '2.0.2', 'a0000000-0000-4000-8000-000000000001');
            self::fail('a 404 is reported, not retried');
        } catch (TokenRefreshException $exception) {
            self::assertSame(404, $exception->getPrevious() instanceof ApiRequestFailedException ? $exception->getPrevious()->httpStatus : 0);
        }

        $stillRevoked = $this->createMock(AqgCrawlerClient::class);
        $stillRevoked->expects(self::exactly(2))->method('results')->willThrowException(new ApiRequestFailedException('Crawler request failed | http=401', 401, null, 'token_revoked'));
        $this->expectException(TokenRefreshException::class);
        $this->expectExceptionMessageMatches('/after token refresh/');
        (new ProCrawlerService($this->createMock(ProLicenceService::class), $tokens, $stillRevoked))->getResults('example.org', '2.0.2', 'a0000000-0000-4000-8000-000000000001');
    }
}
