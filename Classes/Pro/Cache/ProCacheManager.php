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
    private const LICENCE_GRACE_TTL = 172800; // 48h

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

    public function setLicenceResult(string $cacheKey, LicenceValidationResult $result, int $ttl): void
    {
        $payload = $result->toArray();

        $this->getCache()->set($cacheKey, $payload, [], max(1, $ttl));
        $this->getCache()->set(
            $this->buildGraceKey($cacheKey),
            $payload,
            [],
            self::LICENCE_GRACE_TTL
        );
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
        $this->getCache()->set($cacheKey, $result->toArray(), [], max(1, $ttl));
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