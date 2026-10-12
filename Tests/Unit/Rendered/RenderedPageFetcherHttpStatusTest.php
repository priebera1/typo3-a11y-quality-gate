<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rendered;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Rendered\RenderedPageFetcher;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

/**
 * Regression: a page answering 404 (hidden, removed or served by another installation) was reported as "Rendered page
 * fetch failed. This can happen on very large, protected or slow pages". Guzzle throws on 4xx/5xx unless told not to,
 * so the fetcher's own HTTP status branch never ran. The editor now reads the real reason.
 */
final class RenderedPageFetcherHttpStatusTest extends TestCase
{
    #[Test]
    public function anHttpErrorStatusIsReportedAsThatStatus(): void
    {
        $fetcher = new RenderedPageFetcher($this->requestFactoryAnswering(404), new NullLogger());

        $response = $fetcher->fetch('https://example.org/hidden-page', 'example.org', null, true);

        self::assertFalse($response->success);
        self::assertSame(404, $response->statusCode);
        self::assertStringContainsString('HTTP 404', $response->error);
        self::assertStringNotContainsString('very large, protected or slow', $response->error);
    }

    #[Test]
    public function aSuccessfulHtmlPageIsStillReturned(): void
    {
        $fetcher = new RenderedPageFetcher($this->requestFactoryAnswering(200, '<html><body><h1>Hi</h1></body></html>'), new NullLogger());

        $response = $fetcher->fetch('https://example.org/', 'example.org', null, true);

        self::assertTrue($response->success);
        self::assertStringContainsString('<h1>Hi</h1>', $response->html);
    }

    private function requestFactoryAnswering(int $status, string $body = '<html><body>Not found</body></html>'): RequestFactory
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturnCallback(static function (string $url, string $method, array $options) use ($status, $body): Response {
            if ($status >= 400 && ($options['http_errors'] ?? true) !== false) {
                // What Guzzle does by default.
                throw new \RuntimeException('Client error: `GET ' . $url . '` resulted in a `' . $status . ' Not Found` response');
            }
            $stream = new Stream('php://temp', 'rw');
            $stream->write($body);
            $stream->rewind();

            return new Response($stream, $status, ['Content-Type' => 'text/html; charset=utf-8']);
        });

        return $factory;
    }
}
