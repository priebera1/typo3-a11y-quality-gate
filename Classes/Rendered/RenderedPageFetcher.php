<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class RenderedPageFetcher
{
    private const TIMEOUT_SECONDS = 8;
    private const MAX_HTML_BYTES = 5242880;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function fetch(string $url, string $allowedHost, ?int $allowedPort = null, bool $allowPrivateHosts = false): RenderedPageResponse
    {
        $validationError = $this->validateUrl($url, $allowedHost, $allowedPort, $allowPrivateHosts);
        if ($validationError !== '') {
            $this->logger->warning('Rendered page fetch blocked by URL validation.', [
                'url' => $url,
                'allowedHost' => $allowedHost,
                'allowedPort' => $allowedPort,
                'allowPrivateHosts' => $allowPrivateHosts,
                'reason' => $validationError,
                'resolvedIps' => $this->resolveHostIps($allowedHost),
            ]);
            return new RenderedPageResponse(false, error: $validationError, finalUrl: $url);
        }

        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'timeout' => self::TIMEOUT_SECONDS,
                'allow_redirects' => [
                    'max' => 3,
                    'on_redirect' => function ($request, $response, $uri) use ($allowedHost, $allowedPort, $allowPrivateHosts): void {
                        $redirectUrl = (string)$uri;
                        $validationError = $this->validateUrl($redirectUrl, $allowedHost, $allowedPort, $allowPrivateHosts);
                        if ($validationError !== '') {
                            throw new \RuntimeException('Rendered page check redirect is not allowed: ' . $validationError);
                        }
                    },
                ],
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Rendered page fetch failed.', [
                'url' => $url,
                'allowedHost' => $allowedHost,
                'allowedPort' => $allowedPort,
                'allowPrivateHosts' => $allowPrivateHosts,
                'exceptionClass' => $e::class,
                'exceptionMessage' => $e->getMessage(),
                'resolvedIps' => $this->resolveHostIps($allowedHost),
            ]);
            return new RenderedPageResponse(false, error: $this->userFacingFetchError($e), finalUrl: $url);
        }

        $statusCode = $response->getStatusCode();
        $contentType = strtolower(trim($response->getHeaderLine('Content-Type')));
        $finalUrl = (string)($response->getHeaderLine('X-Guzzle-Effective-Url') ?: $url);
        $finalUrlValidationError = $this->validateUrl($finalUrl, $allowedHost, $allowedPort, $allowPrivateHosts);
        if ($finalUrlValidationError !== '') {
            $this->logger->warning('Rendered page fetch ended on invalid final URL.', [
                'url' => $url,
                'finalUrl' => $finalUrl,
                'allowedHost' => $allowedHost,
                'allowedPort' => $allowedPort,
                'statusCode' => $statusCode,
                'contentType' => $contentType,
                'reason' => $finalUrlValidationError,
            ]);
            return new RenderedPageResponse(false, statusCode: $statusCode, contentType: $contentType, error: 'Rendered page fetch ended on a URL that does not match the configured site host or port.', finalUrl: $finalUrl);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return new RenderedPageResponse(false, statusCode: $statusCode, contentType: $contentType, error: 'Rendered page returned HTTP ' . $statusCode . '. The local rendered check can only inspect successful HTML responses.', finalUrl: $finalUrl);
        }

        if ($contentType !== '' && !str_contains($contentType, 'text/html') && !str_contains($contentType, 'application/xhtml+xml')) {
            return new RenderedPageResponse(false, statusCode: $statusCode, contentType: $contentType, error: 'Rendered page response is not HTML. The local rendered check can only inspect server-rendered HTML pages.', finalUrl: $finalUrl);
        }

        $body = $response->getBody();
        $html = '';
        while (!$body->eof() && strlen($html) <= self::MAX_HTML_BYTES) {
            $html .= $body->read(8192);
        }

        if (strlen($html) > self::MAX_HTML_BYTES) {
            return new RenderedPageResponse(false, statusCode: $statusCode, contentType: $contentType, error: 'Rendered HTML response is too large for the local rendered check. Try scanning a smaller page or use the PRO remote crawler for browser-based scanning.', finalUrl: $finalUrl);
        }

        if (trim($html) === '') {
            return new RenderedPageResponse(false, statusCode: $statusCode, contentType: $contentType, error: 'Rendered page response body is empty. The local rendered check could not inspect the page output.', finalUrl: $finalUrl);
        }

        return new RenderedPageResponse(true, html: $html, statusCode: $statusCode, contentType: $contentType, finalUrl: $finalUrl);
    }

    private function validateUrl(string $url, string $allowedHost, ?int $allowedPort, bool $allowPrivateHosts): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return 'Rendered page URL is invalid.';
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return 'Rendered page URL must use HTTP or HTTPS.';
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || $host !== strtolower($allowedHost)) {
            return 'Rendered page URL does not belong to the configured site host.';
        }

        $actualPort = $this->effectivePort($scheme, $parts['port'] ?? null);
        $expectedPort = $allowedPort ?? $this->defaultPortForScheme($scheme);
        if ($actualPort !== $expectedPort) {
            return 'Rendered page URL port does not match the configured site port.';
        }

        if (!$allowPrivateHosts && $this->resolvesToPrivateAddress($host)) {
            return 'Rendered page check was skipped because the frontend URL resolves to a private/local address. For trusted DDEV or staging environments, enable “Allow private/local frontend hosts for rendered checks” in Settings → Rules.';
        }

        return '';
    }

    private function userFacingFetchError(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'operation timed out')) {
            return 'Rendered page fetch timed out. This can happen on very large, protected or slow pages. Try scanning a smaller page or use the PRO remote crawler for browser-based scanning.';
        }

        if (str_contains($message, 'redirect is not allowed')) {
            return 'Rendered page check was stopped because a redirect led outside the configured site host or port. Check your TYPO3 site base URL configuration.';
        }

        return 'Rendered page fetch failed. This can happen on very large, protected or slow pages. Check that the frontend URL is reachable from TYPO3, or use the PRO remote crawler for browser-based scanning.';
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
        $ips = @gethostbynamel($host) ?: [];
        if ($ips === [] && filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        }

        return array_values(array_unique($ips));
    }

    private function resolvesToPrivateAddress(string $host): bool
    {
        $ips = $this->resolveHostIps($host);

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
        }

        return false;
    }
}
