<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Utility;

final class ScanUrlUtility
{
    /**
     * Normalise a scanned URL so two spellings of the *same* resource compare equal.
     *
     * Only the case-insensitive components are lowercased. Scheme and host are case-insensitive per
     * RFC 3986, but the path and query are not: lowercasing the whole URL would make `/Foo` and
     * `/foo` compare equal, so a scan of one page could be matched to a different page whose slug
     * differs only in case.
     */
    public static function comparable(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            // Unparseable input is compared verbatim: guessing here could only merge distinct URLs.
            return rtrim($url, '/');
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . (string)$parts['query'] : '';

        return ($scheme !== '' ? $scheme . '://' : '') . $host . $port . $path . $query;
    }
}
