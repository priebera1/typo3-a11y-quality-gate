<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rendered;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Rendered\HostAddressResolver;
use Priebera\A11yQualityGate\Rendered\RenderedPageFetcher;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

/**
 * V-06 (2026-10 Sol verification): following redirects by hand (SEC-02) came with a home-made URL resolver that turned
 * `?new=2` on /folder/page into /folder/?new=2 and `#part` into /folder/#part. Locations are resolved by RFC 3986
 * (Guzzle's UriResolver) again; every hop still passes the host/port check, DNS resolution, the public-address policy
 * and CURLOPT_RESOLVE pinning. Fake resolver and HTTP client: no request leaves the test.
 */
final class RenderedPageFetcherRedirectTest extends TestCase
{
    private const BASE = 'https://fixture.example/folder/page?old=1';

    /** @var list<array{url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /**
     * @return array<string, array{string, string}>
     */
    public static function locations(): array
    {
        return [
            'query only (Sol V-06)' => ['?new=2', 'https://fixture.example/folder/page?new=2'],
            'fragment only keeps path and query' => ['#part', 'https://fixture.example/folder/page?old=1#part'],
            'empty reference is the base' => ['', 'https://fixture.example/folder/page?old=1'],
            'sibling' => ['other', 'https://fixture.example/folder/other'],
            'current directory' => ['./', 'https://fixture.example/folder/'],
            'parent directory' => ['../up?x=1', 'https://fixture.example/up?x=1'],
            'above the root stays at the root' => ['../../../top', 'https://fixture.example/top'],
            'root relative' => ['/root?y=2', 'https://fixture.example/root?y=2'],
            'protocol relative' => ['//other.example/x', 'https://other.example/x'],
            'absolute' => ['https://fixture.example/absolute#f', 'https://fixture.example/absolute#f'],
            'absolute with port' => ['http://fixture.example:8080/p', 'http://fixture.example:8080/p'],
        ];
    }

    #[Test]
    #[DataProvider('locations')]
    public function aLocationResolvesLikeRfc3986(string $location, string $expected): void
    {
        self::assertSame($expected, RenderedPageFetcher::resolveRedirectLocation(self::BASE, $location));
    }

    #[Test]
    public function aQueryOnlyRedirectKeepsThePathAndIsCheckedAndPinnedAgain(): void
    {
        $response = $this->fetcher([[302, '?new=2'], 200])->fetch(self::BASE, 'fixture.example');

        self::assertTrue($response->success);
        self::assertSame('https://fixture.example/folder/page?new=2', $response->finalUrl);
        self::assertSame([self::BASE, 'https://fixture.example/folder/page?new=2'], array_column($this->requests, 'url'));
        self::assertSame(['fixture.example:443:93.184.216.34'], $this->requests[1]['options']['curl'][CURLOPT_RESOLVE]);
        self::assertFalse($this->requests[1]['options']['allow_redirects']);
    }

    #[Test]
    public function aFragmentOnlyRedirectIsTheSameResourceAndNeverSendsTheFragment(): void
    {
        $response = $this->fetcher([[302, '#part'], [302, '#part'], [302, '#part'], [302, '#part'], 200])->fetch(self::BASE, 'fixture.example');

        self::assertFalse($response->success, 'a redirect to itself ends at the hop limit');
        self::assertStringContainsString('redirect', strtolower((string)$response->error));
        foreach ($this->requests as $request) {
            self::assertSame(self::BASE, $request['url'], 'same path and query, no fragment on the wire');
        }
        self::assertCount(4, $this->requests);
    }

    #[Test]
    public function anEmptyLocationIsNotFollowed(): void
    {
        $response = $this->fetcher([[302, '']])->fetch(self::BASE, 'fixture.example');

        self::assertFalse($response->success);
        self::assertSame(302, $response->statusCode);
        self::assertCount(1, $this->requests);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sameHostLocations(): array
    {
        return [
            'parent directory' => ['../up?x=1', 'https://fixture.example/up?x=1'],
            'root relative' => ['/root', 'https://fixture.example/root'],
            'protocol relative, same host' => ['//fixture.example/proto', 'https://fixture.example/proto'],
            'absolute, same host' => ['https://fixture.example/abs', 'https://fixture.example/abs'],
            'relative with fragment' => ['next#top', 'https://fixture.example/folder/next'],
        ];
    }

    #[Test]
    #[DataProvider('sameHostLocations')]
    public function aSameHostRedirectIsFollowedToItsResolvedUrl(string $location, string $requested): void
    {
        $response = $this->fetcher([[301, $location], 200])->fetch(self::BASE, 'fixture.example');

        self::assertTrue($response->success);
        self::assertSame($requested, $this->requests[1]['url']);
        self::assertSame($requested, $response->finalUrl);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function offSiteLocations(): array
    {
        return [
            'protocol relative to another host' => ['//other.example/x'],
            'absolute to another host' => ['https://other.example/'],
            'another port' => ['https://fixture.example:8443/'],
            'credentials' => ['https://user:secret@fixture.example/'],
            'not http' => ['ftp://fixture.example/file'],
        ];
    }

    #[Test]
    #[DataProvider('offSiteLocations')]
    public function aRedirectOffTheSiteHostOrPortIsStoppedBeforeAnyRequest(string $location): void
    {
        $response = $this->fetcher([[302, $location], 200])->fetch(self::BASE, 'fixture.example');

        self::assertFalse($response->success);
        self::assertStringContainsString('redirect led outside', (string)$response->error);
        self::assertCount(1, $this->requests, 'the redirect target was never requested');
    }

    #[Test]
    public function aRelativeRedirectToAHostNowResolvingPrivatelyIsStopped(): void
    {
        $resolver = new class () extends HostAddressResolver {
            private int $calls = 0;

            public function resolve(string $host): array
            {
                return ++$this->calls === 1 ? ['93.184.216.34'] : ['10.0.0.7'];
            }
        };
        $fetcher = new RenderedPageFetcher($this->requestFactory([[302, '?new=2'], 200]), new NullLogger(), $resolver);

        $response = $fetcher->fetch(self::BASE, 'fixture.example');

        self::assertFalse($response->success);
        self::assertStringContainsString('private/local address', (string)$response->error);
        self::assertCount(1, $this->requests);
    }

    #[Test]
    public function theStagingOptionFollowsARelativeRedirectAndPinsItToo(): void
    {
        $resolver = new class () extends HostAddressResolver {
            public function resolve(string $host): array
            {
                return ['127.0.0.1'];
            }
        };
        $fetcher = new RenderedPageFetcher($this->requestFactory([[302, '../de/'], 200]), new NullLogger(), $resolver);

        $response = $fetcher->fetch('https://aqg.ddev.site/en/page', 'aqg.ddev.site', null, true);

        self::assertTrue($response->success);
        self::assertSame('https://aqg.ddev.site/de/', $response->finalUrl);
        self::assertSame(['aqg.ddev.site:443:127.0.0.1'], $this->requests[1]['options']['curl'][CURLOPT_RESOLVE]);
    }

    #[Test]
    public function anUnparsableLocationStopsTheFetch(): void
    {
        $response = $this->fetcher([[302, 'http://:80/broken'], 200])->fetch(self::BASE, 'fixture.example');

        self::assertFalse($response->success);
        self::assertCount(1, $this->requests);
    }

    /**
     * @param list<int|array{int, string}> $responses
     */
    private function fetcher(array $responses): RenderedPageFetcher
    {
        $resolver = new class () extends HostAddressResolver {
            public function resolve(string $host): array
            {
                return $host === 'fixture.example' ? ['93.184.216.34'] : ['93.184.216.35'];
            }
        };

        return new RenderedPageFetcher($this->requestFactory($responses), new NullLogger(), $resolver);
    }

    /**
     * @param list<int|array{int, string}> $responses a status, or a redirect status and its Location
     */
    private function requestFactory(array $responses): RequestFactory
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturnCallback(function (string $url, string $method, array $options) use (&$responses): Response {
            $this->requests[] = ['url' => $url, 'options' => $options];
            $next = array_shift($responses) ?? 500;
            [$status, $location] = is_array($next) ? $next : [$next, null];
            $stream = new Stream('php://temp', 'rw');
            $stream->write('<html><body><h1>Page</h1></body></html>');
            $stream->rewind();
            $headers = ['Content-Type' => 'text/html; charset=utf-8'];
            if ($location !== null) {
                $headers['Location'] = $location;
            }

            return new Response($stream, $status, $headers);
        });

        return $factory;
    }
}
