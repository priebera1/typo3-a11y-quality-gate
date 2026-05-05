<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

final class DomainNormalizer
{
    public function normalizeFromSiteBase(string $siteBase): string
    {
        $host = parse_url($siteBase, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return '';
        }

        return $this->normalizeHost($host);
    }

    public function normalizeHost(string $host): string
    {
        $normalizedHost = trim(mb_strtolower($host));

        if ($normalizedHost === '') {
            return '';
        }

        if (str_starts_with($normalizedHost, 'www.')) {
            $normalizedHost = substr($normalizedHost, 4);
        }

        return $normalizedHost;
    }
}