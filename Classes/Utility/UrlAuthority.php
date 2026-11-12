<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Utility;

/**
 * COMPAT-01: the scheme, host and port of a URL, parsed without PHP's parse_url().
 *
 * parse_url() replaces the bytes of a host that the C library's current LC_CTYPE classifies as control characters with
 * "_". Under a UTF-8 locale on some platforms (macOS PHP 8.5: C.UTF-8, en_US.UTF-8) that includes UTF-8 continuation
 * bytes such as 0x9C, so `https://MÜLLER.DE/` came back as the host `M\xC3_LLER.DE` and the IDNA conversion failed. This
 * parser only works on bytes and ASCII rules, so the result never depends on the process locale; the process locale is
 * never changed.
 *
 * Same results as parse_url() for well-formed URLs: the authority follows "//" (with or without a scheme) up to the first
 * "/", "?" or "#"; user information ends at the last "@"; an IPv6 literal keeps its brackets; a port is 0–65535 digits
 * (an empty port is none). Stricter, fail closed: a malformed authority (unclosed "[", a non-numeric or too large port,
 * a colon in an unbracketed host) and a host containing ASCII control characters or whitespace give null, never a
 * repaired host. Hosts are returned as written (callers lower-case and convert with IDNA).
 */
final class UrlAuthority
{
    /**
     * @return array{scheme: string, host: string, port: int|null}|null null for a malformed authority; host '' when the
     *         URL has no authority ("/path", "mailto:x", "example.org/" without "//")
     */
    public static function parse(string $url): ?array
    {
        $url = trim($url);
        $scheme = '';
        $rest = $url;
        // parse_url() reads "host:port" without a scheme ("example.org:8080/") as a host and port, not as a scheme; so does
        // this parser.
        if (preg_match('/^[^\/?#@:\s]+:[0-9]{1,5}(?:[\/?#]|$)/', $url) === 1) {
            $rest = '//' . $url;
        } elseif (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):(.*)$/s', $url, $matches) === 1) {
            $scheme = $matches[1];
            $rest = $matches[2];
        }
        if (!str_starts_with($rest, '//')) {
            return ['scheme' => $scheme, 'host' => '', 'port' => null];
        }

        $authority = substr($rest, 2);
        $end = strcspn($authority, '/?#');
        $authority = substr($authority, 0, $end);
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $authority = substr($authority, $at + 1);
        }

        $portPart = null;
        if (str_starts_with($authority, '[')) {
            $close = strpos($authority, ']');
            if ($close === false) {
                return null;
            }
            $host = substr($authority, 0, $close + 1);
            $after = substr($authority, $close + 1);
            if ($after !== '') {
                if ($after[0] !== ':') {
                    return null;
                }
                $portPart = substr($after, 1);
            }
        } else {
            $colon = strrpos($authority, ':');
            if ($colon === false) {
                $host = $authority;
            } else {
                $host = substr($authority, 0, $colon);
                $portPart = substr($authority, $colon + 1);
                if (str_contains($host, ':')) {
                    return null;
                }
            }
        }

        $port = null;
        if ($portPart !== null && $portPart !== '') {
            if (preg_match('/^[0-9]{1,5}$/', $portPart) !== 1 || (int)$portPart > 65535) {
                return null;
            }
            $port = (int)$portPart;
        }

        if (preg_match('/[\x00-\x20\x7F]/', $host) === 1) {
            return null;
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port];
    }

    /**
     * parse_url() with a locale-independent host: the same components, but `host` comes from parse() (COMPAT-01). A URL
     * parse_url() reads as having a host but whose authority parse() refuses gives false (fail closed), as does a URL
     * parse_url() cannot read.
     *
     * @return array<string, int|string>|false
     */
    public static function parseUrl(string $url): array|false
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        if (!array_key_exists('host', $parts)) {
            return $parts;
        }
        $authority = self::parse($url);
        if ($authority === null || $authority['host'] === '') {
            return false;
        }
        $parts['host'] = $authority['host'];

        return $parts;
    }

    /** The host as written ('' without one or for a malformed authority). */
    public static function host(string $url): string
    {
        return self::parse($url)['host'] ?? '';
    }
}
