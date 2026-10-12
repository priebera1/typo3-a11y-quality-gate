<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rendered;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Rendered\HostAddressResolver;
use Priebera\A11yQualityGate\Rendered\RenderedPageFetcher;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

/**
 * SEC-02 (2026-10 Sol audit): the rendered check resolved A records only, treated an empty answer as "not private",
 * and let the HTTP client resolve the name again — and follow redirects — on its own. Every hop is now checked against
 * all A and AAAA answers, refused when the name does not resolve, and pinned to the checked addresses.
 * DNS answers come from a fake resolver; no request leaves the test.
 */
final class RenderedPageFetcherSsrfTest extends TestCase
{
    /** @var list<array{url: string, options: array<string, mixed>}> */
    private array $requests = [];

    #[Test]
    public function aHostWithAPrivateIpv6AnswerIsRefusedBeforeAnyRequest(): void
    {
        $fetcher = $this->fetcher(['example.org' => ['93.184.216.34', 'fd00::5']], [200]);

        $response = $fetcher->fetch('https://example.org/', 'example.org');

        self::assertFalse($response->success);
        self::assertStringContainsString('private/local address', $response->error);
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function aHostThatDoesNotResolveIsRefused(): void
    {
        $fetcher = $this->fetcher(['example.org' => []], [200]);

        $response = $fetcher->fetch('https://example.org/', 'example.org');

        self::assertFalse($response->success);
        self::assertStringContainsString('could not be resolved', $response->error);
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function aMappedIpv6LoopbackAnswerIsRefused(): void
    {
        $fetcher = $this->fetcher(['example.org' => ['::ffff:127.0.0.1']], [200]);

        self::assertFalse($fetcher->fetch('https://example.org/', 'example.org')->success);
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function aPublicHostIsFetchedOverThePinnedAddressesWithoutClientRedirects(): void
    {
        $fetcher = $this->fetcher(['example.org' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']], [200]);

        $response = $fetcher->fetch('https://example.org/page', 'example.org');

        self::assertTrue($response->success);
        self::assertCount(1, $this->requests);
        $options = $this->requests[0]['options'];
        self::assertFalse($options['allow_redirects']);
        self::assertSame(['example.org:443:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]'], $options['curl'][CURLOPT_RESOLVE]);
    }

    #[Test]
    public function aRedirectIsCheckedAndPinnedAgainAndADnsChangeToAPrivateAddressStopsIt(): void
    {
        $answers = ['example.org' => ['93.184.216.34']];
        $resolver = new class ($answers) extends HostAddressResolver {
            private int $calls = 0;

            /** @param array<string, list<string>> $answers */
            public function __construct(private readonly array $answers)
            {
            }

            public function resolve(string $host): array
            {
                // Rebinding: the second lookup of the same name answers with a private address.
                return ++$this->calls === 1 ? $this->answers[$host] : ['10.0.0.7'];
            }
        };
        $fetcher = new RenderedPageFetcher($this->requestFactory([[301, '/next'], 200]), new NullLogger(), $resolver);

        $response = $fetcher->fetch('https://example.org/', 'example.org');

        self::assertFalse($response->success);
        self::assertStringContainsString('private/local address', $response->error);
        self::assertCount(1, $this->requests, 'The redirect target was never requested.');
    }

    #[Test]
    public function aRedirectToAnotherHostIsStopped(): void
    {
        $fetcher = $this->fetcher(['example.org' => ['93.184.216.34'], 'internal.example' => ['93.184.216.35']], [[302, 'https://internal.example/']]);

        $response = $fetcher->fetch('https://example.org/', 'example.org');

        self::assertFalse($response->success);
        self::assertStringContainsString('redirect led outside', $response->error);
        self::assertCount(1, $this->requests);
    }

    #[Test]
    public function aSameHostRedirectIsFollowed(): void
    {
        $fetcher = $this->fetcher(['example.org' => ['93.184.216.34']], [[301, '/en/'], 200]);

        $response = $fetcher->fetch('https://example.org/', 'example.org');

        self::assertTrue($response->success);
        self::assertSame('https://example.org/en/', $response->finalUrl);
        self::assertSame(['https://example.org/', 'https://example.org/en/'], array_column($this->requests, 'url'));
    }

    #[Test]
    public function aRedirectLoopEndsAfterThreeHops(): void
    {
        $fetcher = $this->fetcher(['example.org' => ['93.184.216.34']], [[302, '/a'], [302, '/b'], [302, '/c'], [302, '/d'], 200]);

        $response = $fetcher->fetch('https://example.org/', 'example.org');

        self::assertFalse($response->success);
        self::assertCount(4, $this->requests);
    }

    #[Test]
    public function theStagingOptionStillAllowsAPrivateHost(): void
    {
        $fetcher = $this->fetcher(['aqg.ddev.site' => ['127.0.0.1']], [200]);

        $response = $fetcher->fetch('https://aqg.ddev.site/', 'aqg.ddev.site', null, true);

        self::assertTrue($response->success);
        self::assertSame(['aqg.ddev.site:443:127.0.0.1'], $this->requests[0]['options']['curl'][CURLOPT_RESOLVE]);
    }

    #[Test]
    public function credentialsInTheUrlAreRefused(): void
    {
        $fetcher = $this->fetcher(['example.org' => ['93.184.216.34']], [200]);

        self::assertFalse($fetcher->fetch('https://user:secret@example.org/', 'example.org')->success);
        self::assertSame([], $this->requests);
    }

    /**
     * @param array<string, list<string>> $answers
     * @param list<int|array{int, string}> $responses
     */
    private function fetcher(array $answers, array $responses): RenderedPageFetcher
    {
        $resolver = new class ($answers) extends HostAddressResolver {
            /** @param array<string, list<string>> $answers */
            public function __construct(private readonly array $answers)
            {
            }

            public function resolve(string $host): array
            {
                return $this->answers[$host] ?? [];
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
            [$status, $location] = is_array($next) ? $next : [$next, ''];
            $stream = new Stream('php://temp', 'rw');
            $stream->write('<html><body><h1>Page</h1></body></html>');
            $stream->rewind();
            $headers = ['Content-Type' => 'text/html; charset=utf-8'];
            if ($location !== '') {
                $headers['Location'] = $location;
            }

            return new Response($stream, $status, $headers);
        });

        return $factory;
    }
}
