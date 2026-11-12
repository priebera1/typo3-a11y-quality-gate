<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Utility;

/**
 * Links that arrive from the AQG service (crawler page URLs, rule help and documentation links) and are rendered as
 * `href` in the backend. Fluid escapes the value but not its scheme: a `javascript:` or `data:` URL would still run
 * when an editor clicks it. Only absolute http(s) URLs with a host are linked (Fable final audit E-14); anything else
 * is shown as no link.
 */
final class ExternalLinkUtility
{
    public static function httpOrEmpty(mixed $url): string
    {
        $url = trim(is_string($url) ? $url : '');
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || (string)($parts['host'] ?? '') === '') {
            return '';
        }

        return $url;
    }
}
