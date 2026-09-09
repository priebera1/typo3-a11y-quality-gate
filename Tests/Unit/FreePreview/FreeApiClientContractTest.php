<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\FreePreview;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Http\AqgApiClient;
use Priebera\A11yQualityGate\Pro\Http\AqgCrawlerClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class FreeApiClientContractTest extends TestCase
{
    #[Test]
    public function freeTokenRequestContainsRequiredIdentityAndSiteFields(): void
    {
        $captured = [];
        $client = new AqgApiClient($this->capturingFactory($captured, [
            'plan' => 'free',
            'entitlement' => 'free_daily',
            'access_token' => 'jwt',
            'expires_in' => 3600,
            'features' => ['crawler'],
            'capabilities' => ['crawler_submit', 'crawler_status', 'crawler_results', 'crawler_summary'],
        ]));

        $response = $client->issueFreeToken('anonymous-id', 'https://example.test/subsite/', 'main', '1.9.0');

        self::assertTrue($response->success);
        self::assertSame('free_daily', $response->entitlement);
        self::assertSame(
            ['crawler_submit', 'crawler_status', 'crawler_results', 'crawler_summary'],
            $response->capabilities,
        );
        self::assertSame([
            'installationId' => 'anonymous-id',
            'siteUrl' => 'https://example.test/subsite/',
            'siteIdentifier' => 'main',
            'version' => '1.9.0',
        ], $captured['payload']);
        self::assertStringEndsWith('/auth/token', $captured['url']);
    }

    #[Test]
    public function freeStatusRequestUsesBearerAndExactBody(): void
    {
        $captured = [];
        $client = new AqgCrawlerClient($this->capturingFactory($captured, [
            'entitlement' => 'free_daily',
            'remoteCrawlerVisible' => true,
            'freeDaily' => ['available' => true],
        ]));

        $client->entitlementStatus('jwt', 'anonymous-id', 'https://example.test/', 'main');

        self::assertSame([
            'installationId' => 'anonymous-id',
            'siteUrl' => 'https://example.test/',
            'siteIdentifier' => 'main',
        ], $captured['payload']);
        self::assertSame('Bearer jwt', $captured['headers']['Authorization']);
        self::assertSame('POST', $captured['method']);
        self::assertStringEndsWith('/entitlement/status', $captured['url']);
    }

    #[Test]
    public function freeSubmitScansExactlyOnePageAndHasNoAdvancedOptions(): void
    {
        $captured = [];
        $client = new AqgCrawlerClient($this->capturingFactory($captured, [
            'jobId' => 'job-1',
            'status' => 'queued',
        ]));

        $client->submitFree(
            'jwt',
            'anonymous-id',
            'https://example.test/subsite/',
            'main',
            'https://example.test/subsite/contact',
            'aqg-free-idempotency',
        );

        self::assertSame([
            'installationId' => 'anonymous-id',
            'siteUrl' => 'https://example.test/subsite/',
            'siteIdentifier' => 'main',
            'startUrl' => 'https://example.test/subsite/contact',
            'maxPages' => 1,
        ], $captured['payload']);
        self::assertSame('aqg-free-idempotency', $captured['headers']['Idempotency-Key']);
        foreach (['sitemapUrl', 'captureScreenshot', 'scannerToken', 'httpAuth', 'excludedPatterns', 'priorityUrls', 'cookieSelectors', 'axeLocale'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $captured['payload']);
        }
    }

    #[Test]
    public function structuredCrawlerErrorsExposeSafeStatusCodeAndQuotaDetails(): void
    {
        $captured = [];
        $factory = $this->capturingFactory($captured, [
            'success' => false,
            'error' => [
                'code' => 'free_daily_limit_reached',
                'message' => 'Daily limit reached.',
                'details' => ['resetsAt' => '2026-08-11T00:00:00.000Z'],
            ],
        ], 429, ['Retry-After' => '120']);
        $client = new AqgCrawlerClient($factory);

        try {
            $client->submitFree('jwt', 'id', 'https://example.test/', 'main', 'https://example.test/', 'key');
            self::fail('Expected API error.');
        } catch (ApiRequestFailedException $exception) {
            self::assertSame(429, $exception->httpStatus);
            self::assertSame('free_daily_limit_reached', $exception->apiErrorCode);
            self::assertSame(['resetsAt' => '2026-08-11T00:00:00.000Z'], $exception->details);
            self::assertSame(120, $exception->retryAfter);
        }
    }

    #[Test]
    public function missingCrawlerRouteIsExposedAsBoundedErrorCode(): void
    {
        $captured = [];
        $client = new AqgCrawlerClient($this->capturingFactory($captured, [
            'message' => 'Route not found',
            'error' => 'Not Found',
            'statusCode' => 404,
        ], 404));

        try {
            $client->entitlementStatus('jwt', 'id', 'https://example.test/', 'main');
            self::fail('Expected missing route error.');
        } catch (ApiRequestFailedException $exception) {
            self::assertSame(404, $exception->httpStatus);
            self::assertSame('route_not_found', $exception->apiErrorCode);
        }
    }

    #[Test]
    public function genericCrawlerRateLimitIsExposedAsBoundedErrorCode(): void
    {
        $captured = [];
        $client = new AqgCrawlerClient($this->capturingFactory($captured, [
            'error' => 'Too Many Requests',
            'message' => 'Rate limit exceeded, retry in 1 minute',
            'statusCode' => 429,
        ], 429));

        try {
            $client->entitlementStatus('jwt', 'id', 'https://example.test/', 'main');
            self::fail('Expected rate-limit error.');
        } catch (ApiRequestFailedException $exception) {
            self::assertSame(429, $exception->httpStatus);
            self::assertSame('rate_limit_exceeded', $exception->apiErrorCode);
        }
    }

    #[Test]
    public function freeTokenHttpErrorKeepsBoundedApiCode(): void
    {
        $captured = [];
        $client = new AqgApiClient($this->capturingFactory($captured, [
            'success' => false,
            'error' => [
                'code' => 'missing_installation_id',
                'message' => 'Installation identity required.',
            ],
        ], 400));

        try {
            $client->issueFreeToken('', 'https://example.test/', 'main', '1.9.0');
            self::fail('Expected token contract error.');
        } catch (ApiRequestFailedException $exception) {
            self::assertSame(400, $exception->httpStatus);
            self::assertSame('missing_installation_id', $exception->apiErrorCode);
        }
    }

    /**
     * @param array<string, mixed> $captured
     * @param array<string, mixed> $responsePayload
     * @param array<string, string> $responseHeaders
     */
    private function capturingFactory(
        array &$captured,
        array $responsePayload,
        int $status = 200,
        array $responseHeaders = [],
    ): RequestFactory {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')
            ->willReturnCallback(function (string $url, string $method, array $options) use (
                &$captured,
                $responsePayload,
                $status,
                $responseHeaders,
            ): ResponseInterface {
                $captured = [
                    'url' => $url,
                    'method' => $method,
                    'headers' => $options['headers'] ?? [],
                    'payload' => json_decode((string)($options['body'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR),
                ];

                return $this->response($status, json_encode($responsePayload, JSON_THROW_ON_ERROR), $responseHeaders);
            });

        return $factory;
    }

    /** @param array<string, string> $headers */
    private function response(int $status, string $body, array $headers = []): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeaderLine')->willReturnCallback(
            static fn (string $name): string => $headers[$name] ?? '',
        );

        return $response;
    }
}
