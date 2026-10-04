<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\UpdateNotice;

use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Reads the latest stable AQG release from the AQG service (`GET /extension/releases/latest`).
 *
 * The request is a plain GET without query, body, licence key, installation id or site data. The answer is
 * cached for 12 hours, a failure for one hour, so a backend module asks at most once per cache period and a
 * slow or unreachable service costs one short request, not one per page load. Failures are silent: a
 * timeout, an HTTP error or a malformed answer simply means "no update notice".
 */
final class LatestReleaseProvider
{
    public const ENDPOINT = '/extension/releases/latest';
    public const CACHE_KEY = 'aqg_latest_extension_release_v1';
    public const CACHE_TTL = 43200;
    public const FAILURE_CACHE_TTL = 3600;
    public const REQUEST_TIMEOUT = 2.0;
    private const MAX_RESPONSE_BYTES = 16384;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ProCacheManager $cacheManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLatestRelease(): ?LatestRelease
    {
        try {
            $cached = $this->cacheManager->getDisplayPayload(self::CACHE_KEY);
        } catch (\Throwable) {
            $cached = null;
        }

        if (is_array($cached) && array_key_exists('release', $cached)) {
            return LatestRelease::fromArray($cached['release']);
        }

        $release = $this->fetch();
        try {
            $this->cacheManager->setDisplayPayload(
                self::CACHE_KEY,
                ['release' => $release?->toArray()],
                $release !== null ? self::CACHE_TTL : self::FAILURE_CACHE_TTL,
            );
        } catch (\Throwable) {
            // An unavailable cache must not turn a successful check into an error.
        }

        return $release;
    }

    private function fetch(): ?LatestRelease
    {
        try {
            $response = $this->requestFactory->request(
                rtrim(ProSettings::resolveApiBaseUrl(), '/') . self::ENDPOINT,
                'GET',
                [
                    'headers' => ['Accept' => 'application/json'],
                    'timeout' => self::REQUEST_TIMEOUT,
                    'connect_timeout' => self::REQUEST_TIMEOUT,
                    'http_errors' => false,
                    'allow_redirects' => false,
                ]
            );

            if ($response->getStatusCode() !== 200) {
                return $this->unavailable('http_' . $response->getStatusCode());
            }

            $body = (string)$response->getBody();
            if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
                return $this->unavailable('invalid_body');
            }

            $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            return $this->unavailable($exception instanceof \JsonException ? 'invalid_json' : 'transport_error');
        }

        if (!is_array($payload) || ($payload['success'] ?? null) !== true) {
            return $this->unavailable('invalid_response');
        }

        return LatestRelease::fromArray($payload['release'] ?? null) ?? $this->unavailable('invalid_release');
    }

    private function unavailable(string $reason): null
    {
        $this->logger->info('AQG release check unavailable', ['reason' => $reason]);

        return null;
    }
}
