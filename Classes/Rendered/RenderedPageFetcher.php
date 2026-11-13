<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Priebera\A11yQualityGate\Utility\BackendLabelUtility;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class RenderedPageFetcher
{
    private const TIMEOUT_SECONDS = 8;
    /**
     * Largest decoded HTML body the rendered check accepts. Enforced DURING the transfer (SOL-FINAL-02): a declared
     * Content-Length above it ends the request after the headers, and the body sink refuses the write that would cross
     * it, which makes cURL abort, so at most this many decoded bytes are ever buffered (plus the kernel socket buffer
     * and one decoder chunk of at most 16 KiB that is received but not stored).
     */
    public const MAX_HTML_BYTES = 5242880;

    /**
     * English error texts, translated for the editor as "renderedCheck.error.<key>". Logs keep English.
     */
    private const ERRORS = [
        'invalidUrl' => 'Rendered page URL is invalid.',
        'scheme' => 'Rendered page URL must use HTTP or HTTPS.',
        'host' => 'Rendered page URL does not belong to the configured site host.',
        'port' => 'Rendered page URL port does not match the configured site port.',
        'privateHost' => 'Rendered page check was skipped because the frontend URL resolves to a private/local address. For trusted DDEV or staging environments, enable “Allow private/local frontend hosts for rendered checks” in Settings → Rules.',
        'finalUrl' => 'Rendered page fetch ended on a URL that does not match the configured site host or port.',
        'httpStatus' => 'Rendered page returned HTTP %d. The local rendered check can only inspect successful HTML responses.',
        'notHtml' => 'Rendered page response is not HTML. The local rendered check can only inspect server-rendered HTML pages.',
        'tooLarge' => 'Rendered HTML response is too large for the local rendered check. Try scanning a smaller page, or use a frontend scan for browser-based checks.',
        'empty' => 'Rendered page response body is empty. The local rendered check could not inspect the page output.',
        'timeout' => 'Rendered page fetch timed out. This can happen on very large, protected or slow pages. Try scanning a smaller page, or use a frontend scan for browser-based checks.',
        'redirect' => 'Rendered page check was stopped because a redirect led outside the configured site host or port. Check your TYPO3 site base URL configuration.',
        'dnsUnresolved' => 'Rendered page check was skipped because the site host could not be resolved. Check the site base URL and the DNS of this server.',
        'pinUnavailable' => 'Rendered page check was skipped because the PHP cURL extension is not available, which the check needs to connect only to verified public addresses.',
        'failed' => 'Rendered page fetch failed. This can happen on very large, protected or slow pages. Check that the frontend URL is reachable from TYPO3, or use a frontend scan for browser-based checks.',
    ];

    private const MAX_REDIRECTS = 3;

    /**
     * The error key of a stored rendered-check message, for views that show it in the backend language (a scan stores
     * the text it produced: English from the CLI or scheduler, otherwise the scanning user's language). Matches the
     * English text and the current backend language's label; '' when the message is not one of these errors.
     */
    public static function errorKeyForMessage(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return '';
        }
        foreach (self::ERRORS as $key => $english) {
            $translated = BackendLabelUtility::translate('renderedCheck.error.' . $key, $english);
            foreach (array_unique([$english, $translated]) as $text) {
                if (str_contains($text, '%d')) {
                    $pattern = '/^' . str_replace('%d', '\\d+', preg_quote($text, '/')) . '$/u';
                    if (preg_match($pattern, $message) === 1) {
                        return $key;
                    }
                } elseif ($text === $message) {
                    return $key;
                }
            }
        }

        return '';
    }

    private readonly HostAddressResolver $hostAddressResolver;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
        ?HostAddressResolver $hostAddressResolver = null,
    ) {
        $this->hostAddressResolver = $hostAddressResolver ?? new HostAddressResolver();
    }

    /**
     * Fetches the page the way a visitor would, with the SSRF guard of SEC-02 (2026-10 Sol audit): every hop is checked
     * against the site's host and port, every address the host resolves to (A and AAAA) must be public unless private
     * hosts are explicitly allowed, a host that does not resolve is refused, and the connection is pinned to the checked
     * addresses (CURLOPT_RESOLVE), so a second DNS answer between check and connect cannot redirect it. Redirects are
     * followed here, hop by hop, instead of inside the HTTP client.
     */
    public function fetch(string $url, string $allowedHost, ?int $allowedPort = null, bool $allowPrivateHosts = false): RenderedPageResponse
    {
        $currentUrl = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $validationError = $this->validateUrl($currentUrl, $allowedHost, $allowedPort);
            $pinnedAddresses = [];
            if ($validationError === '') {
                [$pinnedAddresses, $validationError] = $this->checkedAddresses($currentUrl, $allowPrivateHosts);
            }
            if ($validationError !== '') {
                $this->logger->warning('Rendered page fetch blocked by URL validation.', [
                    'url' => $url,
                    'hopUrl' => $currentUrl,
                    'hop' => $hop,
                    'allowedHost' => $allowedHost,
                    'allowedPort' => $allowedPort,
                    'allowPrivateHosts' => $allowPrivateHosts,
                    'reason' => self::ERRORS[$validationError],
                    'resolvedIps' => $this->resolveHostIps(\Priebera\A11yQualityGate\Utility\UrlAuthority::host($currentUrl)),
                ]);

                return new RenderedPageResponse(
                    false,
                    error: $this->errorText($hop > 0 && in_array($validationError, ['invalidUrl', 'scheme', 'host', 'port'], true) ? 'redirect' : $validationError),
                    finalUrl: $currentUrl
                );
            }

            $sink = new BoundedResponseSink(self::MAX_HTML_BYTES);
            $response = null;
            $e = null;
            try {
                $response = $this->requestFactory->request($currentUrl, 'GET', $this->requestOptions($currentUrl, $pinnedAddresses, $sink));
            } catch (\Throwable $e) {
                $decided = self::cause($e, ResponseHeadersDecideException::class);
                if ($decided instanceof ResponseHeadersDecideException) {
                    // Redirect, error status or non-HTML type: decided from the headers, no body was read.
                    $response = $decided->response;
                }
            }
            if ($response === null) {
                $e ??= new \RuntimeException('No response.', 1771003105);
                $sink->close();
                if ($sink->exceeded() || self::cause($e, ResponseTooLargeException::class) !== null) {
                    $this->logger->warning('Rendered page fetch stopped: response larger than the limit.', [
                        'url' => $url,
                        'hopUrl' => $currentUrl,
                        'maxBytes' => self::MAX_HTML_BYTES,
                        'storedBytes' => $sink->bytes(),
                    ]);

                    return new RenderedPageResponse(false, error: $this->errorText('tooLarge'), finalUrl: $currentUrl);
                }
                $this->logger->warning('Rendered page fetch failed.', [
                    'url' => $url,
                    'hopUrl' => $currentUrl,
                    'allowedHost' => $allowedHost,
                    'allowedPort' => $allowedPort,
                    'allowPrivateHosts' => $allowPrivateHosts,
                    'exceptionClass' => $e::class,
                    'exceptionMessage' => $e->getMessage(),
                    'resolvedIps' => $pinnedAddresses,
                ]);
                return new RenderedPageResponse(false, error: $this->userFacingFetchError($e), finalUrl: $currentUrl);
            }

            $statusCode = $response->getStatusCode();
            $location = trim($response->getHeaderLine('Location'));
            if ($statusCode >= 300 && $statusCode < 400 && $location !== '') {
                $nextUrl = self::resolveRedirectLocation($currentUrl, $location);
                if ($nextUrl === null) {
                    $this->logger->warning('Rendered page fetch stopped at an unparsable redirect location.', ['url' => $url, 'hopUrl' => $currentUrl, 'location' => $location]);

                    return new RenderedPageResponse(false, error: $this->errorText('redirect'), finalUrl: $currentUrl);
                }
                // The fragment is never sent; the next hop is validated, resolved and pinned like the first one.
                $currentUrl = explode('#', $nextUrl, 2)[0];
                $sink->close();
                continue;
            }

            try {
                return $this->responseResult($response, $currentUrl, $sink);
            } finally {
                $sink->close();
                $response->getBody()->close();
            }
        }

        $this->logger->warning('Rendered page fetch stopped after too many redirects.', ['url' => $url, 'lastUrl' => $currentUrl]);

        return new RenderedPageResponse(false, error: $this->errorText('redirect'), finalUrl: $currentUrl);
    }

    /**
     * @return array{0: list<string>, 1: string} the addresses to pin, and the error key when the host is refused
     */
    private function checkedAddresses(string $url, bool $allowPrivateHosts): array
    {
        $host = \Priebera\A11yQualityGate\Utility\UrlAuthority::host($url);
        $addresses = $this->hostAddressResolver->resolve($host);
        if ($addresses === []) {
            // Fail closed: an empty answer is not "no private address". With private hosts allowed (DDEV, staging)
            // the HTTP client resolves the name itself, as before.
            return [[], $allowPrivateHosts ? '' : 'dnsUnresolved'];
        }

        if ($allowPrivateHosts) {
            return [$addresses, ''];
        }

        foreach ($addresses as $address) {
            if (!NetworkAddressPolicy::isPublic($address)) {
                return [[], 'privateHost'];
            }
        }

        if (!function_exists('curl_init')) {
            // Without cURL the connection cannot be pinned to the checked addresses.
            return [[], 'pinUnavailable'];
        }

        return [$addresses, ''];
    }

    /**
     * @param list<string> $pinnedAddresses
     * @return array<string, mixed>
     */
    private function requestOptions(string $url, array $pinnedAddresses, BoundedResponseSink $sink): array
    {
        $options = [
            'timeout' => self::TIMEOUT_SECONDS,
            // SOL-FINAL-02: the body goes into a bounded sink instead of an unlimited php://temp; crossing the limit aborts
            // the transfer. Decoding stays on (cURL counts decoded bytes into the sink).
            'sink' => $sink,
            'decode_content' => true,
            'on_headers' => static function (\Psr\Http\Message\ResponseInterface $response): void {
                // Headers that decide the outcome end the transfer before any body byte: a redirect (followed and
                // checked in fetch()), an HTTP error status and a non-HTML type need no body.
                $status = $response->getStatusCode();
                if ($status < 200) {
                    // An informational block (103 Early Hints) is not the answer.
                    return;
                }
                $type = strtolower(trim($response->getHeaderLine('Content-Type')));
                if ($status >= 300
                    || ($type !== '' && !str_contains($type, 'text/html') && !str_contains($type, 'application/xhtml+xml'))
                ) {
                    throw new ResponseHeadersDecideException($response);
                }
                // A declared, unencoded length above the limit ends the request before any body byte is read. A
                // compressed length says nothing about the decoded size, and a missing or false length is caught by the sink.
                $length = trim($response->getHeaderLine('Content-Length'));
                $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
                if (($encoding === '' || $encoding === 'identity') && ctype_digit($length) && (strlen($length) > 12 || (int)$length > self::MAX_HTML_BYTES)) {
                    throw new ResponseTooLargeException('Declared response size exceeds the rendered-check limit.', 1771003102);
                }
            },
            // An HTTP error status is an answer, not a transport failure: report "returned HTTP 404" below instead
            // of Guzzle's exception, which ended as the generic "fetch failed" message.
            'http_errors' => false,
            // Redirects are followed in fetch(), where every hop is validated and pinned again.
            'allow_redirects' => false,
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
            ],
        ];

        $host = \Priebera\A11yQualityGate\Utility\UrlAuthority::host($url);
        if ($pinnedAddresses !== [] && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false && defined('CURLOPT_RESOLVE')) {
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            $port = $this->effectivePort($scheme, parse_url($url, PHP_URL_PORT));
            $options['curl'] = [
                CURLOPT_RESOLVE => [sprintf(
                    '%s:%d:%s',
                    $host,
                    $port,
                    implode(',', array_map(
                        static fn (string $address): string => str_contains($address, ':') ? '[' . $address . ']' : $address,
                        $pinnedAddresses
                    ))
                )],
            ];
        }

        return $options;
    }

    /**
     * A Location header resolved against the URL that answered it, by RFC 3986 section 5.2 (Guzzle's UriResolver, as
     * the HTTP client did before redirects were followed here): `?new=2` keeps the path, `#part` keeps path and query,
     * `../x`, `/x`, `//host/x` and absolute URLs resolve as browsers resolve them. Null for a Location that is not a URI.
     * The result is only a candidate: fetch() validates its host and port and resolves and pins its addresses again.
     */
    public static function resolveRedirectLocation(string $baseUrl, string $location): ?string
    {
        try {
            return (string)UriResolver::resolve(new Uri($baseUrl), new Uri(trim($location)));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** The first exception of $class in the chain, or null. */
    private static function cause(?\Throwable $exception, string $class): ?\Throwable
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof $class) {
                return $current;
            }
        }

        return null;
    }

    private function responseResult(\Psr\Http\Message\ResponseInterface $response, string $finalUrl, BoundedResponseSink $sink): RenderedPageResponse
    {
        $statusCode = $response->getStatusCode();
        if ($sink->exceeded()) {
            // Without cURL the stream handler stops copying at the limit instead of failing: still too large.
            return new RenderedPageResponse(false, statusCode: $statusCode, contentType: strtolower(trim($response->getHeaderLine('Content-Type'))), error: $this->errorText('tooLarge'), finalUrl: $finalUrl);
        }
        $contentType = strtolower(trim($response->getHeaderLine('Content-Type')));

        if ($statusCode < 200 || $statusCode >= 300) {
            return new RenderedPageResponse(false, statusCode: $statusCode, contentType: $contentType, error: sprintf($this->errorText('httpStatus'), $statusCode), finalUrl: $finalUrl);
        }

        if ($contentType !== '' && !str_contains($contentType, 'text/html') && !str_contains($contentType, 'application/xhtml+xml')) {
            return new RenderedPageResponse(false, statusCode: $statusCode, contentType: $contentType, error: $this->errorText('notHtml'), finalUrl: $finalUrl);
        }

        // Normally the body IS the bounded sink (at most MAX_HTML_BYTES); a client that ignores the sink option is still
        // read no further than one chunk past the limit.
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $html = '';
        while (!$body->eof() && strlen($html) <= self::MAX_HTML_BYTES) {
            $chunk = $body->read(65536);
            if ($chunk === '') {
                break;
            }
            $html .= $chunk;
        }

        if (strlen($html) > self::MAX_HTML_BYTES) {
            return new RenderedPageResponse(false, statusCode: $statusCode, contentType: $contentType, error: $this->errorText('tooLarge'), finalUrl: $finalUrl);
        }

        if (trim($html) === '') {
            return new RenderedPageResponse(false, statusCode: $statusCode, contentType: $contentType, error: $this->errorText('empty'), finalUrl: $finalUrl);
        }

        return new RenderedPageResponse(true, html: $html, statusCode: $statusCode, contentType: $contentType, finalUrl: $finalUrl);
    }

    private function validateUrl(string $url, string $allowedHost, ?int $allowedPort): string
    {
        $parts = \Priebera\A11yQualityGate\Utility\UrlAuthority::parseUrl($url);
        if (!is_array($parts)) {
            return 'invalidUrl';
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return 'scheme';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'invalidUrl';
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || $host !== strtolower($allowedHost)) {
            return 'host';
        }

        $actualPort = $this->effectivePort($scheme, $parts['port'] ?? null);
        $expectedPort = $allowedPort ?? $this->defaultPortForScheme($scheme);
        if ($actualPort !== $expectedPort) {
            return 'port';
        }

        return '';
    }

    private function userFacingFetchError(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'operation timed out')) {
            return $this->errorText('timeout');
        }

        if (str_contains($message, 'redirect is not allowed')) {
            return $this->errorText('redirect');
        }

        return $this->errorText('failed');
    }

    private function errorText(string $key): string
    {
        return BackendLabelUtility::translate('renderedCheck.error.' . $key, self::ERRORS[$key]);
    }

    private function effectivePort(string $scheme, mixed $port): int
    {
        if (is_int($port)) {
            return $port;
        }

        if (is_string($port) && ctype_digit($port)) {
            return (int)$port;
        }

        return $this->defaultPortForScheme($scheme);
    }

    private function defaultPortForScheme(string $scheme): int
    {
        return strtolower($scheme) === 'http' ? 80 : 443;
    }

    /**
     * @return list<string>
     */
    private function resolveHostIps(string $host): array
    {
        return $this->hostAddressResolver->resolve($host);
    }
}
