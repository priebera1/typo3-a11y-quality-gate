<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Cache;

use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResult;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResult;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final class ProCacheManager
{
    private const TOKEN_TAG = 'aqg_access_token';

    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {
    }

    public function getFreshLicenceResult(string $cacheKey): ?LicenceValidationResult
    {
        return $this->restoreLicenceResult($this->getCache()->get($cacheKey));
    }

    public function getGraceLicenceResult(string $cacheKey): ?LicenceValidationResult
    {
        return $this->restoreLicenceResult(
            $this->getCache()->get($this->buildGraceKey($cacheKey))
        );
    }

    /**
     * Stores an answer and its offline grace copy. A grace TTL of 0 removes the grace copy, so an
     * entitlement that already ended leaves nothing for an outage to fall back on.
     */
    public function setLicenceResult(string $cacheKey, LicenceValidationResult $result, int $ttl, int $graceTtl = ProConstants::CACHE_TTL_GRACE): void
    {
        $payload = $result->toArray();

        $this->getCache()->set($cacheKey, $payload, [], max(1, $ttl));
        if ($graceTtl <= 0) {
            $this->getCache()->remove($this->buildGraceKey($cacheKey));
            return;
        }

        $this->getCache()->set($this->buildGraceKey($cacheKey), $payload, [], $graceTtl);
    }

    /**
     * Caches an answer for the request path only; the offline grace copy is left as it is.
     */
    public function setFreshLicenceResult(string $cacheKey, LicenceValidationResult $result, int $ttl): void
    {
        $this->getCache()->set($cacheKey, $result->toArray(), [], max(1, $ttl));
    }

    /**
     * Drops every cached access token, so no request runs on a token issued before a definitive
     * licence rejection.
     */
    public function flushTokens(): void
    {
        $this->getCache()->flushByTag(self::TOKEN_TAG);
    }

    public function getToken(string $cacheKey): ?AccessTokenResult
    {
        $payload = $this->getCache()->get($cacheKey);
        if (!is_array($payload)) {
            return null;
        }

        $result = AccessTokenResult::fromCacheArray($payload);
        if ($result->accessToken === '') {
            return null;
        }

        return $result;
    }

    public function setToken(string $cacheKey, AccessTokenResult $result, int $ttl): void
    {
        $this->getCache()->set($cacheKey, $result->toArray(), [self::TOKEN_TAG], max(1, $ttl));
    }

    /**
     * Short-lived, secret-free display payloads (currently the Free Remote Preview entitlement
     * status). Never store tokens, licence keys or installation identifiers here.
     *
     * @return array<string, mixed>|null
     */
    public function getDisplayPayload(string $cacheKey): ?array
    {
        $payload = $this->getCache()->get($cacheKey);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setDisplayPayload(string $cacheKey, array $payload, int $ttl): void
    {
        $this->getCache()->set($cacheKey, $payload, [], max(1, $ttl));
    }

    public function removeDisplayPayload(string $cacheKey): void
    {
        $this->getCache()->remove($cacheKey);
    }

    public function flushByPrefix(string $prefix): void
    {
        $this->getCache()->flushByTag($prefix);
    }

    public function flushAll(): void
    {
        $this->getCache()->flush();
    }

    private function getCache(): FrontendInterface
    {
        return $this->cacheManager->getCache(ProConstants::CACHE_IDENTIFIER);
    }

    private function buildGraceKey(string $cacheKey): string
    {
        return $cacheKey . '_grace';
    }

    private function restoreLicenceResult(mixed $payload): ?LicenceValidationResult
    {
        if (!is_array($payload)) {
            return null;
        }

        return LicenceValidationResult::fromCacheArray($payload);
    }
}
