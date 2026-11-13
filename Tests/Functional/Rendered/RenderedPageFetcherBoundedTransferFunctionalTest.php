<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Rendered;

use GuzzleHttp\TransferStats;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Rendered\HostAddressResolver;
use Priebera\A11yQualityGate\Rendered\RenderedPageFetcher;
use Priebera\A11yQualityGate\Rendered\RenderedPageResponse;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * SOL-FINAL-02: the rendered-page size limit bounds the TRANSFER, not only the answer. A real loopback HTTP server
 * (synthetic content only, forked per connection) answers with oversized, chunked, length-less, dishonestly declared,
 * gzip-bombed, redirected, truncated and stalled responses; the real TYPO3 RequestFactory → Guzzle → cURL stack fetches
 * them through RenderedPageFetcher with its DNS pinning (the fixture host is pinned to 127.0.0.1 by CURLOPT_RESOLVE).
 *
 * Measured per request: the body bytes cURL received on the wire (TransferStats size_download), the decoded bytes the
 * fetcher stored and the bytes the server's kernel accepted. Before the fix the client received and buffered the full
 * 8 MiB (64 MiB decoded for the gzip case) and only then reported "too large".
 */
final class RenderedPageFetcherBoundedTransferFunctionalTest extends AbstractFunctionalTestCase
{
    private const HOST = 'rendered-fixture.aqg.test';
    private const MAX = RenderedPageFetcher::MAX_HTML_BYTES;
    /** Wire bytes cURL may have read past the limit before the aborting write: its receive buffer plus TCP slack. */
    private const WIRE_ALLOWANCE = 262144;

    /** @var resource|null */
    private $server;
    private int $port = 0;
    private ?AbstractLogger $log = null;
    private string $serverLog = '';
    /** @var \ArrayObject<int,array{url:string,sizeDownload:?float,stored:?int}> */
    private \ArrayObject $transfers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transfers = new \ArrayObject();
        $dir = $this->instancePath . '/rendered-fixture-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir));
        $portFile = $dir . '/port';
        $this->serverLog = $dir . '/server.log';
        touch($this->serverLog);
        $this->server = proc_open(
            [PHP_BINARY, __DIR__ . '/../Fixtures/Http/rendered-fixture-server.php', $portFile, $this->serverLog],
            [0 => ['pipe', 'r'], 1 => ['file', $dir . '/stdout', 'a'], 2 => ['file', $dir . '/stderr', 'a']],
            $pipes,
        );
        for ($i = 0; $i < 100 && !is_file($portFile); $i++) {
            usleep(50_000);
        }
        $this->port = (int)@file_get_contents($portFile);
        self::assertGreaterThan(0, $this->port, 'Fixture server started.');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['aqg-transfer-probe']);
        if (is_resource($this->server)) {
            $status = proc_get_status($this->server);
            if ($status['running']) {
                posix_kill($status['pid'], SIGKILL);
            }
            proc_close($this->server);
        }
        parent::tearDown();
    }

    /**
     * @return array<string,array{0:string,1:string,2:int}> path => [expected error key ('' = success), maximum wire bytes, html length]
     */
    public static function responses(): array
    {
        return [
            'small valid HTML' => ['/small', '', 2048],
            'exact-size boundary is accepted' => ['/exact', '', self::MAX],
            'one byte over the boundary' => ['/exact-plus-one', 'tooLarge', 0],
            'known-length 8 MiB' => ['/known', 'tooLarge', 0],
            'chunked 8 MiB' => ['/chunked', 'tooLarge', 0],
            'no Content-Length, connection-delimited 8 MiB' => ['/no-length', 'tooLarge', 0],
            'declared 1,000 bytes, sends 8 MiB' => ['/dishonest-short', '', 1000],
            'gzip bomb: 64 MiB decoded' => ['/gzip', 'tooLarge', 0],
            'small gzip page is decoded' => ['/gzip-small', '', 4096],
            'same-host redirect to a chunked 8 MiB page' => ['/redirect-oversize', 'tooLarge', 0],
            'connection closed midway' => ['/midway', 'failed', 0],
            'HTTP 500 with an 8 MiB error page keeps its status error' => ['/error-large', 'httpStatus', 0],
            '8 MiB JSON keeps the not-HTML error' => ['/json-large', 'notHtml', 0],
            'redirect with an 8 MiB body is followed' => ['/redirect-large-body', '', 2048],
            '103 Early Hints before the page' => ['/early-hints', '', 2048],
        ];
    }

    #[Test]
    #[DataProvider('responses')]
    public function theTransferStopsAtTheLimitAndValidPagesStillWork(string $path, string $expectedError, int $htmlLength): void
    {
        $peakBefore = memory_get_peak_usage();
        $response = $this->fetch($path);
        $peakDelta = memory_get_peak_usage() - $peakBefore;
        $this->recordMeasurement($path, $response, $peakDelta);

        self::assertSame($expectedError, $this->errorKey($response), 'Outcome of ' . $path . ': ' . $response->error . ' ' . implode(' | ', $this->log->lines ?? []));
        if ($expectedError === '') {
            self::assertTrue($response->success);
            self::assertSame($htmlLength, strlen($response->html));
            self::assertStringStartsWith('<!DOCTYPE html>', $response->html);
        }
        $last = $this->transfer(count($this->transfers) - 1);
        self::assertNotNull($last['sizeDownload'], 'cURL transfer statistics are available.');
        // The bound: never the whole 8 MiB (or 64 MiB decoded) — at most the limit plus cURL's receive slack.
        self::assertLessThanOrEqual(self::MAX + self::WIRE_ALLOWANCE, $last['sizeDownload'], 'Body bytes received on the wire for ' . $path);
        self::assertLessThanOrEqual(self::MAX, $last['buffered'] ?? 0, 'Decoded bytes the transport buffered for ' . $path);
        self::assertNotNull($last['stored'], 'The fetcher passed its bounded sink.');
        self::assertLessThanOrEqual(self::MAX, $last['stored'], 'Decoded bytes stored for ' . $path);
        // Less than two copies of the limit in PHP memory (sink + returned HTML string), never the full response.
        self::assertLessThan(2 * self::MAX + 4 * 1048576, $peakDelta, 'Peak PHP memory growth for ' . $path);
        if (in_array($path, ['/exact-plus-one', '/known', '/error-large', '/json-large', '/redirect-large-body'], true)) {
            self::assertLessThanOrEqual(self::WIRE_ALLOWANCE, $last['sizeDownload'], 'A declared oversized length is refused after the headers.');
        }
        if ($path === '/redirect-large-body') {
            self::assertLessThanOrEqual(self::WIRE_ALLOWANCE, $this->transfer(0)['sizeDownload'], 'The redirect body is not read.');
        }
        if ($path === '/gzip') {
            self::assertLessThan(1048576, $last['sizeDownload'], 'Only a small compressed prefix is received.');
        }
    }

    #[Test]
    public function aStalledResponseEndsAtTheEightSecondTimeout(): void
    {
        $started = microtime(true);
        $response = $this->fetch('/slow');
        $elapsed = microtime(true) - $started;

        self::assertSame('timeout', $this->errorKey($response));
        self::assertGreaterThanOrEqual(7.5, $elapsed);
        self::assertLessThan(10.0, $elapsed, 'The eight-second timeout still bounds the request.');
        $this->recordMeasurement('/slow', $response, 0);
    }

    #[Test]
    public function aRedirectOutsideTheSiteAndAPrivateTargetAreRefusedWithoutARequest(): void
    {
        $external = $this->fetch('/redirect-external');
        self::assertSame('redirect', $this->errorKey($external));
        self::assertCount(1, $this->transfers, 'Only the redirecting hop was requested.');

        $private = $this->fetch('/small', allowPrivateHosts: false);
        self::assertSame('privateHost', $this->errorKey($private));
        self::assertCount(1, $this->transfers, 'A private target is refused before any connection.');
        usleep(200_000);
        self::assertSame(['/redirect-external'], array_map(static fn (string $line): string => explode(' ', $line)[0], $this->serverLines()));
    }

    #[Test]
    public function theConnectionStaysPinnedToTheCheckedAddress(): void
    {
        // The fixture host does not exist in DNS: only the pinned address can reach the server.
        $response = $this->fetch('/small');
        self::assertTrue($response->success, $response->error);
        self::assertSame('http://' . self::HOST . ':' . $this->port . '/small', $response->finalUrl);
    }

    private function fetch(string $path, bool $allowPrivateHosts = true): RenderedPageResponse
    {
        // Measurement only: a Guzzle middleware in TYPO3's HTTP handler stack records cURL's transfer statistics and the sink
        // the fetcher passed. It changes no option the fetcher sets.
        $transfers = $this->transfers;
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['aqg-transfer-probe'] = static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler, $transfers) {
            $index = count($transfers);
            $transfers[$index] = ['url' => (string)$request->getUri(), 'sizeDownload' => null, 'buffered' => null, 'sink' => $options['sink'] ?? null];
            $previous = $options['on_stats'] ?? null;
            $options['on_stats'] = static function (TransferStats $stats) use ($index, $transfers, $previous): void {
                $transfers[$index] = ['sizeDownload' => (float)($stats->getHandlerStats()['size_download'] ?? 0)] + $transfers[$index];
                if (is_callable($previous)) {
                    $previous($stats);
                }
            };

            return $handler($request, $options)->then(static function (ResponseInterface $response) use ($index, $transfers): ResponseInterface {
                // Decoded body bytes the transport buffered before handing the response to the fetcher.
                $transfers[$index] = ['buffered' => $response->getBody()->getSize()] + $transfers[$index];

                return $response;
            });
        };
        $factory = $this->get(RequestFactory::class);
        $resolver = new class () extends HostAddressResolver {
            public function resolve(string $host): array
            {
                return ['127.0.0.1'];
            }
        };
        $this->log = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $message . ' ' . json_encode(array_intersect_key($context, ['exceptionClass' => 1, 'exceptionMessage' => 1, 'reason' => 1, 'storedBytes' => 1]));
            }
        };
        $fetcher = new RenderedPageFetcher($factory, $this->log, $resolver);

        return $fetcher->fetch('http://' . self::HOST . ':' . $this->port . $path, self::HOST, $this->port, $allowPrivateHosts);
    }

    /** @return array{url:string,sizeDownload:?float,buffered:?int,stored:?int} */
    private function transfer(int $index): array
    {
        $entry = $this->transfers[$index] ?? null;
        self::assertIsArray($entry, 'Transfer ' . $index . ' was recorded.');
        $sink = $entry['sink'];

        return ['url' => $entry['url'], 'sizeDownload' => $entry['sizeDownload'], 'buffered' => $entry['buffered'], 'stored' => is_object($sink) && method_exists($sink, 'bytes') ? $sink->bytes() : null];
    }

    private function errorKey(RenderedPageResponse $response): string
    {
        return $response->success ? '' : RenderedPageFetcher::errorKeyForMessage($response->error);
    }

    /** @return list<string> */
    private function serverLines(): array
    {
        return file($this->serverLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    }

    /** Appends the measured bounds to the evidence file named by AQG_TRANSFER_EVIDENCE (if set). */
    private function recordMeasurement(string $path, RenderedPageResponse $response, int $peakDelta): void
    {
        $file = (string)getenv('AQG_TRANSFER_EVIDENCE');
        if ($file === '') {
            return;
        }
        usleep(300_000);
        $server = array_values(array_filter($this->serverLines(), static fn (string $line): bool => str_starts_with($line, $path . ' ')));
        file_put_contents($file, json_encode([
            'path' => $path,
            'outcome' => $this->errorKey($response) ?: 'success',
            'htmlBytes' => strlen($response->html),
            'hops' => array_map(fn (int $i): array => $this->transfer($i), array_keys($this->transfers->getArrayCopy())),
            'serverKernelAcceptedBytes' => $server,
            'phpPeakMemoryDelta' => $peakDelta,
            'typo3' => (new \TYPO3\CMS\Core\Information\Typo3Version())->getVersion(),
            'curl' => curl_version()['version'] ?? '',
            'guzzle' => \Composer\InstalledVersions::getPrettyVersion('guzzlehttp/guzzle'),
        ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }
}
