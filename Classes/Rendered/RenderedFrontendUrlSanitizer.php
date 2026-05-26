<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

final class RenderedFrontendUrlSanitizer
{
    /**
     * @var string[]
     */
    private const INTERNAL_PARAMETERS = [
        'aqgDebug',
        'aqgh',
        'tx_aqg_rendered_check',
        'no_cache',
        '_aqg_page',
        '_aqg_lang',
        '_aqg_nonce',
    ];

    public function sanitize(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);
        foreach (self::INTERNAL_PARAMETERS as $parameterName) {
            unset($query[$parameterName]);
        }

        $cleanUrl = $parts['scheme'] . '://';
        if (isset($parts['user'])) {
            $cleanUrl .= $parts['user'];
            if (isset($parts['pass'])) {
                $cleanUrl .= ':' . $parts['pass'];
            }
            $cleanUrl .= '@';
        }

        $cleanUrl .= $parts['host'];
        if (isset($parts['port'])) {
            $cleanUrl .= ':' . $parts['port'];
        }

        $cleanUrl .= $parts['path'] ?? '/';

        if ($query !== []) {
            $cleanUrl .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $cleanUrl .= '#' . $parts['fragment'];
        }

        return $cleanUrl;
    }
}
