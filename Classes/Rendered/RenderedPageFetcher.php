<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

use TYPO3\CMS\Core\Http\RequestFactory;

final class RenderedPageFetcher
{
    private const TIMEOUT_SECONDS = 8;
    private const MAX_HTML_BYTES = 5242880;

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {
    }

    public function fetch(string $url, string $allowedHost, ?int $allowedPort = null, bool $allowPrivateHosts = false): RenderedPageResponse
    {
        $validationError = $this->validateUrl($url, $allowedHost, $allowedPort, $allowPrivateHosts);
        if ($validationError !== '') {
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
            return new RenderedPageResponse(false, error: 'Rendered page fetch failed: ' . $e->getMessage(), finalUrl: $url);
        }

        $statusCode = $response->getStatusCode();
        $contentType = strtolower(trim($response->getHeaderLine('Content-Type')));
        $finalUrl = (string)($response->getHeaderLine('X-Guzzle-Effective-Url') ?: $url);
        if ($this->validateUrl($finalUrl, $allowedHost, $allowedPort, $allowPrivateHosts) !== '') {
            return new RenderedPageResponse(false, statusCode: $statusCode, contentType: $contentType, error: 'Rendered page fetch ended on an invalid host.', finalUrl: $finalUrl);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return new RenderedPageResponse(false, statusCode: $statusCode, contentType: $contentType, error: 'Rendered page returned HTTP ' . $statusCode, finalUrl: $finalUrl);
        }

        if ($contentType !== '' && !str_contains($contentType, 'text/html') && !str_contains($contentType, 'application/xhtml+xml')) {
            return new RenderedPageResponse(false, statusCode: $statusCode, contentType: $contentType, error: 'Rendered page response is not HTML.', finalUrl: $finalUrl);
        }

        $body = $response->getBody();
        $html = '';
        while (!$body->eof() && strlen($html) <= self::MAX_HTML_BYTES) {
            $html .= $body->read(8192);
        }

        if (strlen($html) > self::MAX_HTML_BYTES) {
            return new RenderedPageResponse(false, statusCode: $statusCode, contentType: $contentType, error: 'Rendered page HTML exceeds 5 MB limit.', finalUrl: $finalUrl);
        }

        if (trim($html) === '') {
            return new RenderedPageResponse(false, statusCode: $statusCode, contentType: $contentType, error: 'Rendered page response body is empty.', finalUrl: $finalUrl);
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

        $port = $parts['port'] ?? null;
        if ($allowedPort !== null && $port !== null && (int)$port !== $allowedPort) {
            return 'Rendered page URL port does not match the configured site port.';
        }

        if (!$allowPrivateHosts && $this->resolvesToPrivateAddress($host)) {
            return 'Rendered page URL resolves to a private or local address.';
        }

        return '';
    }

    private function resolvesToPrivateAddress(string $host): bool
    {
        $ips = @gethostbynamel($host) ?: [];
        if ($ips === [] && filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
        }

        return false;
    }
}
