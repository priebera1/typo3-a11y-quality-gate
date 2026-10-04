<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\FreePreview;

/**
 * Recognises a site base URL that the AQG crawler can never reach from the internet: localhost, development
 * domains such as *.ddev.site, reserved and internal-only names (.test, .local, .internal, .lan, .home.arpa …),
 * single-label host names and private, loopback or link-local IP addresses.
 *
 * This only decides which guidance the backend shows before a scan; it performs no DNS lookup or request and
 * grants nothing. The crawler's own checks (DNS resolution, private networks, redirects) stay authoritative and
 * still refuse everything else that is not public, such as a host that only resolves in a VPN.
 */
final class PublicSiteAddressClassifier
{
    /** Name suffixes that public DNS never resolves to a public address. */
    private const NON_PUBLIC_SUFFIXES = [
        'localhost',
        'local',
        'localdomain',
        'internal',
        'intranet',
        'lan',
        'home',
        'home.arpa',
        'corp',
        'test',
        'example',
        'invalid',
        'ddev.site',
        'lndo.site',
    ];

    /**
     * The host of a site base that is clearly not reachable from the internet, or null when it may be public
     * (or has no host at all).
     */
    public function findNonPublicHost(string $siteBase): ?string
    {
        $host = $this->extractHost($siteBase);
        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $public = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

            return $public === false ? $host : null;
        }

        if (!str_contains($host, '.')) {
            return $host;
        }

        foreach (self::NON_PUBLIC_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return $host;
            }
        }

        return null;
    }

    public function isClearlyNotPublic(string $siteBase): bool
    {
        return $this->findNonPublicHost($siteBase) !== null;
    }

    private function extractHost(string $siteBase): string
    {
        $siteBase = trim($siteBase);
        if ($siteBase === '') {
            return '';
        }

        // TYPO3 accepts bases without a scheme ("//example.org/" or "example.org/"); only "/" has no host.
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $siteBase)) {
            if (str_starts_with($siteBase, '/') && !str_starts_with($siteBase, '//')) {
                return '';
            }
            $siteBase = 'https://' . ltrim($siteBase, '/');
        }

        $host = parse_url($siteBase, PHP_URL_HOST);
        if (!is_string($host)) {
            return '';
        }

        return rtrim(strtolower(trim($host, '[]')), '.');
    }
}
