<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Configuration;

use InvalidArgumentException;

final class PublicLinkProvider
{
    public const PRODUCT = 'product';
    public const DOCUMENTATION = 'documentation';
    public const PRICING = 'pricing';
    public const TRIAL = 'trial';
    public const SUPPORT = 'support';
    public const PORTAL = 'portal';

    /**
     * @var array<string, string>
     */
    private const CANONICAL_LINKS = [
        self::PRODUCT => 'https://typo3.priebera.sk/products/accessibility-quality-gate',
        self::DOCUMENTATION => 'https://typo3.priebera.sk/docs',
        self::PRICING => 'https://typo3.priebera.sk/pricing',
        self::TRIAL => 'https://typo3.priebera.sk/trial',
        self::SUPPORT => 'https://typo3.priebera.sk/contact',
        self::PORTAL => 'https://typo3.priebera.sk/portal',
    ];

    /**
     * @var array<string, string>
     */
    private const BACKEND_CAMPAIGN_PARAMETERS = [
        'utm_source' => 'typo3_backend',
        'utm_medium' => 'referral',
        'utm_campaign' => 'aqg_extension',
    ];

    /**
     * @return array<string, string>
     */
    public function getCanonicalLinks(): array
    {
        return self::CANONICAL_LINKS;
    }

    /**
     * @return array<string, string>
     */
    public function getBackendLinks(): array
    {
        $links = [];
        foreach (self::CANONICAL_LINKS as $name => $url) {
            $links[$name] = $name === self::PORTAL
                ? $url
                : $this->appendQueryParameters($url, self::BACKEND_CAMPAIGN_PARAMETERS);
        }

        return $links;
    }

    public function getCanonicalUrl(string $name): string
    {
        return self::CANONICAL_LINKS[$name] ?? throw new InvalidArgumentException(
            sprintf('Unknown public link "%s".', $name)
        );
    }

    public function getBackendUrl(string $name): string
    {
        $url = $this->getCanonicalUrl($name);
        if ($name === self::PORTAL) {
            return $url;
        }

        return $this->appendQueryParameters($url, self::BACKEND_CAMPAIGN_PARAMETERS);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function appendQueryParameters(string $url, array $parameters): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query(
            $parameters,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }
}
