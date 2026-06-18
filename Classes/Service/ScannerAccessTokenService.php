<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;
use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Site\SiteFinder;

final class ScannerAccessTokenService
{
    private const CACHE_KEY = 'scanner_preview_token_default';
    private const CACHE_TTL = 60;

    public function __construct(
        private readonly RulesetRepository $rulesetRepository,
        private readonly CacheManager $cacheManager,
        private readonly SiteFinder $siteFinder,
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

        if ($siteIdentifier === '') {
            $this->rulesetRepository->saveScannerTokenForDefault($token);
            $this->setCachedToken($token);

            return $token;
        }

        $this->rulesetRepository->saveScannerTokenForSiteOrDefault($siteIdentifier, $token);
        $this->flush();

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

        try {
            $storedToken = $this->getDefaultToken();
            if ($storedToken !== '' && hash_equals($storedToken, $token)) {
                return true;
            }

            $ruleset = $this->rulesetRepository->findByScannerToken($token);
            if (!is_array($ruleset)) {
                return false;
            }

            $storedToken = trim((string)($ruleset['scanner_token'] ?? ''));
            return $storedToken !== '' && hash_equals($storedToken, $token);
        } catch (\Throwable) {
            return false;
        }
    }

    public function isValidTokenForRequest(string $token, ServerRequestInterface $request): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        try {
            $rulesets = $this->rulesetRepository->findAllByScannerToken($token);
            if ($rulesets === []) {
                return false;
            }

            $siteRulesets = array_values(array_filter(
                $rulesets,
                static fn (array $ruleset): bool => trim((string)($ruleset['site_identifier'] ?? '')) !== ''
            ));

            if ($siteRulesets === []) {
                return $this->defaultTokenMatchesRequest($rulesets, $token, $request);
            }

            foreach ($siteRulesets as $ruleset) {
                $storedToken = trim((string)($ruleset['scanner_token'] ?? ''));
                if (
                    $this->rulesetMatchesRequest($ruleset, $request)
                    && $storedToken !== ''
                    && hash_equals($storedToken, $token)
                ) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    public function flush(): void
    {
        try {
            $this->cacheManager->getCache(ProConstants::CACHE_IDENTIFIER)->remove(self::CACHE_KEY);
        } catch (\Throwable) {
        }
    }


    /**
     * Default tokens are migration/default-site fallback only. They may unlock a
     * request site only while that site has no own scanner token configured.
     *
     * @param list<array<string, mixed>> $rulesets
     */
    private function defaultTokenMatchesRequest(array $rulesets, string $token, ServerRequestInterface $request): bool
    {
        $siteIdentifier = $this->resolveRequestSiteIdentifier($request);
        if ($siteIdentifier === '') {
            return false;
        }

        try {
            $siteRuleset = $this->rulesetRepository->findBySiteIdentifier($siteIdentifier);
        } catch (\Throwable) {
            return false;
        }
        $siteToken = trim((string)($siteRuleset['scanner_token'] ?? ''));

        if ($siteToken !== '') {
            return false;
        }

        return $this->matchesDefaultStoredTokenOnly($rulesets, $token);
    }

    /**
     * @param list<array<string, mixed>> $rulesets
     */
    private function matchesDefaultStoredTokenOnly(array $rulesets, string $token): bool
    {
        foreach ($rulesets as $ruleset) {
            $siteIdentifier = trim((string)($ruleset['site_identifier'] ?? ''));
            if ($siteIdentifier !== '') {
                continue;
            }

            $storedToken = trim((string)($ruleset['scanner_token'] ?? ''));
            if ($storedToken !== '' && hash_equals($storedToken, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $ruleset
     */
    private function rulesetMatchesRequest(array $ruleset, ServerRequestInterface $request): bool
    {
        $siteIdentifier = trim((string)($ruleset['site_identifier'] ?? ''));
        if ($siteIdentifier === '') {
            return false;
        }

        $requestSite = $request->getAttribute('site');
        if (
            is_object($requestSite)
            && method_exists($requestSite, 'getIdentifier')
            && $requestSite->getIdentifier() === $siteIdentifier
        ) {
            return true;
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (\Throwable) {
            return false;
        }

        $siteBase = $site->getBase();
        $siteHost = strtolower($siteBase->getHost());
        $requestHost = strtolower($request->getUri()->getHost());
        if ($siteHost === '' || $requestHost === '' || $siteHost !== $requestHost) {
            return false;
        }

        return $this->normalizePort($siteBase->getPort(), $siteBase->getScheme()) === $this->normalizePort(
            $request->getUri()->getPort(),
            $request->getUri()->getScheme()
        );
    }

    private function resolveRequestSiteIdentifier(ServerRequestInterface $request): string
    {
        $requestSite = $request->getAttribute('site');
        if (is_object($requestSite) && method_exists($requestSite, 'getIdentifier')) {
            return trim((string)$requestSite->getIdentifier());
        }

        $requestHost = strtolower($request->getUri()->getHost());
        if ($requestHost === '') {
            return '';
        }

        $requestPort = $this->normalizePort($request->getUri()->getPort(), $request->getUri()->getScheme());
        $requestPath = '/' . ltrim($request->getUri()->getPath(), '/');
        $matchedIdentifier = '';
        $matchedPathLength = -1;

        try {
            $sites = $this->siteFinder->getAllSites();
        } catch (\Throwable) {
            return '';
        }

        foreach ($sites as $site) {
            if (!is_object($site) || !method_exists($site, 'getBase') || !method_exists($site, 'getIdentifier')) {
                continue;
            }

            $siteBase = $site->getBase();
            $siteHost = strtolower($siteBase->getHost());
            if ($siteHost === '' || $siteHost !== $requestHost) {
                continue;
            }

            if ($this->normalizePort($siteBase->getPort(), $siteBase->getScheme()) !== $requestPort) {
                continue;
            }

            $sitePath = '/' . trim($siteBase->getPath(), '/');
            if ($sitePath !== '/') {
                $sitePath .= '/';
            }

            if (!str_starts_with($requestPath . '/', $sitePath)) {
                continue;
            }

            $sitePathLength = strlen($sitePath);
            if ($sitePathLength > $matchedPathLength) {
                $matchedIdentifier = trim((string)$site->getIdentifier());
                $matchedPathLength = $sitePathLength;
            }
        }

        return $matchedIdentifier;
    }

    private function normalizePort(?int $port, string $scheme): int
    {
        if ($port !== null) {
            return $port;
        }

        return strtolower($scheme) === 'https' ? 443 : 80;
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
