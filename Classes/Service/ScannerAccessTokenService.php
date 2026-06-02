<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;
use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use TYPO3\CMS\Core\Cache\CacheManager;

final class ScannerAccessTokenService
{
    private const CACHE_KEY = 'scanner_preview_token_default';
    private const CACHE_TTL = 60;

    public function __construct(
        private readonly RulesetRepository $rulesetRepository,
        private readonly CacheManager $cacheManager,
    ) {
    }

    public function generateAndSaveDefaultToken(): string
    {
        return $this->generateAndSaveTokenForSiteOrDefault('');
    }

    public function generateAndSaveTokenForSiteOrDefault(string $siteIdentifier): string
    {
        $token = bin2hex(random_bytes(32));
        $siteIdentifier = trim($siteIdentifier);

        // Keep the default token in sync because frontend marker validation is
        // currently global. Also save the site-specific token so remote access
        // settings and crawler submit resolve the same ruleset as the UI.
        $this->rulesetRepository->saveScannerTokenForDefault($token);
        if ($siteIdentifier !== '') {
            $this->rulesetRepository->saveScannerTokenForSiteOrDefault($siteIdentifier, $token);
        }
        $this->setCachedToken($token);

        return $token;
    }

    public function getDefaultToken(): string
    {
        $cached = $this->getCachedToken();
        if ($cached !== null) {
            return $cached;
        }

        $ruleset = $this->rulesetRepository->findDefault();
        $token = trim((string)($ruleset['scanner_token'] ?? ''));
        $this->setCachedToken($token);

        return $token;
    }

    public function isValidToken(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        $storedToken = $this->getDefaultToken();
        if ($storedToken === '') {
            return false;
        }

        return hash_equals($storedToken, $token);
    }

    public function flush(): void
    {
        try {
            $this->cacheManager->getCache(ProConstants::CACHE_IDENTIFIER)->remove(self::CACHE_KEY);
        } catch (\Throwable) {
        }
    }

    private function getCachedToken(): ?string
    {
        try {
            $value = $this->cacheManager->getCache(ProConstants::CACHE_IDENTIFIER)->get(self::CACHE_KEY);
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    private function setCachedToken(string $token): void
    {
        try {
            $this->cacheManager->getCache(ProConstants::CACHE_IDENTIFIER)->set(self::CACHE_KEY, $token, [], self::CACHE_TTL);
        } catch (\Throwable) {
        }
    }
}
