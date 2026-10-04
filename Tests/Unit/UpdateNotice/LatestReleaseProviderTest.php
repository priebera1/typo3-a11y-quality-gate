<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\UpdateNotice;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\UpdateNotice\LatestReleaseProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * The release check runs while a backend module renders: it asks once per cache period, sends nothing about the
 * installation, and any failure is silent and cached for a shorter time.
 */
final class LatestReleaseProviderTest extends TestCase
{
    private const VALID_BODY = '{"success":true,"release":{"product":"accessibility-quality-gate","channel":"stable","version":"1.9.9","releasedAt":"2026-10-20","releaseNotesUrl":"https://typo3.priebera.sk/docs/changelog#v199","updateInstructionsUrl":"https://typo3.priebera.sk/docs/installation","importance":"normal"}}';

    /** @var array<string, array{payload: array<string, mixed>, ttl: int}> */
    private array $cache = [];

    /** @var list<array{url: string, method: string, options: array<string, mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        putenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL');
        $this->cache = [];
        $this->requests = [];
    }

    #[Test]
    public function aValidAnswerIsCachedForTwelveHours(): void
    {
        $release = $this->provider(new Response(200, ['Content-Type' => 'application/json'], self::VALID_BODY))->getLatestRelease();

        self::assertNotNull($release);
        self::assertSame('1.9.9', $release->version->toString());
        self::assertSame(43200, $this->cache[LatestReleaseProvider::CACHE_KEY]['ttl']);
        self::assertSame('1.9.9', $this->cache[LatestReleaseProvider::CACHE_KEY]['payload']['release']['version']);
    }

    #[Test]
    public function theRequestCarriesNoInstallationData(): void
    {
        $this->provider(new Response(200, [], self::VALID_BODY))->getLatestRelease();

        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('GET', $request['method']);
        self::assertSame('https://api.priebera.sk/extension/releases/latest', $request['url']);
        self::assertSame(['Accept' => 'application/json'], $request['options']['headers']);
        self::assertArrayNotHasKey('body', $request['options']);
        self::assertArrayNotHasKey('json', $request['options']);
        self::assertArrayNotHasKey('query', $request['options']);
        self::assertFalse($request['options']['allow_redirects']);
        self::assertLessThanOrEqual(3.0, $request['options']['timeout']);
        self::assertLessThanOrEqual(3.0, $request['options']['connect_timeout']);
    }

    #[Test]
    public function aCachedAnswerIsUsedWithoutAskingAgain(): void
    {
        $provider = $this->provider(new Response(200, [], self::VALID_BODY));
        $provider->getLatestRelease();
        $release = $provider->getLatestRelease();

        self::assertNotNull($release);
        self::assertSame('1.9.9', $release->version->toString());
        self::assertCount(1, $this->requests);
    }

    #[Test]
    public function aTimeoutIsSilentAndCachedForAnHour(): void
    {
        $provider = $this->provider(new ConnectException('Connection timed out', new Request('GET', 'https://api.priebera.sk')));

        self::assertNull($provider->getLatestRelease());
        self::assertSame(3600, $this->cache[LatestReleaseProvider::CACHE_KEY]['ttl']);
        self::assertNull($this->cache[LatestReleaseProvider::CACHE_KEY]['payload']['release']);

        // The cached failure answers the next page load without another request.
        self::assertNull($provider->getLatestRelease());
        self::assertCount(1, $this->requests);
    }

    /**
     * @return array<string, array{ResponseInterface}>
     */
    public static function unusableAnswers(): array
    {
        return [
            'server error' => [new Response(503, [], '{"success":false,"error":{"code":"release_info_unavailable"}}')],
            'older service without the route' => [new Response(404, [], '{"success":false,"error":{"code":"route_not_found"}}')],
            'redirect' => [new Response(302, ['Location' => 'https://evil.example/'], '')],
            'empty body' => [new Response(200, [], '')],
            'malformed JSON' => [new Response(200, [], '{"success":true,"release":')],
            'not an object' => [new Response(200, [], '"1.9.9"')],
            'not successful' => [new Response(200, [], '{"success":false}')],
            'success is not a boolean' => [new Response(200, [], '{"success":"true","release":{"version":"1.9.9"}}')],
            'missing release' => [new Response(200, [], '{"success":true}')],
            'pre-release version' => [new Response(200, [], '{"success":true,"release":{"version":"2.0.0-beta1"}}')],
            'oversized' => [new Response(200, [], '{"success":true,"release":{"version":"1.9.9","padding":"' . str_repeat('x', 20000) . '"}}')],
        ];
    }

    #[Test]
    #[DataProvider('unusableAnswers')]
    public function anUnusableAnswerMeansNoReleaseAndIsCachedAsAFailure(ResponseInterface $response): void
    {
        self::assertNull($this->provider($response)->getLatestRelease());
        self::assertSame(3600, $this->cache[LatestReleaseProvider::CACHE_KEY]['ttl']);
    }

    #[Test]
    public function aBrokenCacheDoesNotBreakTheCheck(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturn(new Response(200, [], self::VALID_BODY));
        $cacheManager = $this->createMock(ProCacheManager::class);
        $cacheManager->method('getDisplayPayload')->willThrowException(new \RuntimeException('cache down'));
        $cacheManager->method('setDisplayPayload')->willThrowException(new \RuntimeException('cache down'));

        $release = (new LatestReleaseProvider($requestFactory, $cacheManager, new NullLogger()))->getLatestRelease();

        self::assertNotNull($release);
        self::assertSame('1.9.9', $release->version->toString());
    }

    #[Test]
    public function aDevelopmentApiEndpointIsUsedWhenConfigured(): void
    {
        putenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL=https://api.aqg.ddev.site/');
        try {
            $this->provider(new Response(200, [], self::VALID_BODY))->getLatestRelease();
        } finally {
            putenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL');
        }

        self::assertSame('https://api.aqg.ddev.site/extension/releases/latest', $this->requests[0]['url']);
    }

    private function provider(ResponseInterface|\Throwable $answer): LatestReleaseProvider
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(
            function (string $url, string $method = 'GET', array $options = []) use ($answer): ResponseInterface {
                $this->requests[] = ['url' => $url, 'method' => $method, 'options' => $options];
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer;
            }
        );

        $cacheManager = $this->createMock(ProCacheManager::class);
        $cacheManager->method('getDisplayPayload')->willReturnCallback(
            fn (string $key): ?array => $this->cache[$key]['payload'] ?? null
        );
        $cacheManager->method('setDisplayPayload')->willReturnCallback(
            function (string $key, array $payload, int $ttl): void {
                $this->cache[$key] = ['payload' => $payload, 'ttl' => $ttl];
            }
        );

        return new LatestReleaseProvider($requestFactory, $cacheManager, new NullLogger());
    }
}
