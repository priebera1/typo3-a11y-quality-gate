<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

/**
 * Which IP addresses the rendered check may connect to (2026-10 Sol audit SEC-02).
 *
 * PHP's FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE leave out carrier-grade NAT, benchmark and documentation ranges, and
 * an IPv6 address that carries an IPv4 one (mapped, compatible, NAT64, 6to4) is judged by its IPv6 form only, so
 * `::ffff:127.0.0.1` passed as public. Ranges are matched numerically on the binary address, and an embedded IPv4
 * address is checked again on its own.
 */
final class NetworkAddressPolicy
{
    /** @var list<array{string, int}> */
    private const BLOCKED_IPV4 = [
        ['0.0.0.0', 8],
        ['10.0.0.0', 8],
        ['100.64.0.0', 10],
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],
        ['172.16.0.0', 12],
        ['192.0.0.0', 24],
        ['192.0.2.0', 24],
        ['192.88.99.0', 24],
        ['192.168.0.0', 16],
        ['198.18.0.0', 15],
        ['198.51.100.0', 24],
        ['203.0.113.0', 24],
        ['224.0.0.0', 4],
        ['240.0.0.0', 4],
    ];

    /** @var list<array{string, int}> */
    private const BLOCKED_IPV6 = [
        ['::', 128],
        ['::1', 128],
        ['64:ff9b:1::', 48],
        ['100::', 64],
        ['2001::', 23],
        ['2001:db8::', 32],
        ['fc00::', 7],
        ['fe80::', 10],
        ['fec0::', 10],
        ['ff00::', 8],
    ];

    /** IPv6 forms that carry an IPv4 address in their last (or, for 6to4, second and third) 32 bits. */
    private const EMBEDDED_IPV4 = [
        ['::ffff:0:0', 96, 12],
        ['::', 96, 12],
        ['64:ff9b::', 96, 12],
        ['2002::', 16, 2],
    ];

    public static function isPublic(string $address): bool
    {
        $address = trim($address, " \t\n\r\0\x0B[]");
        $binary = @inet_pton($address);
        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 4) {
            return !self::matchesAny($binary, self::BLOCKED_IPV4);
        }

        foreach (self::EMBEDDED_IPV4 as [$prefix, $bits, $offset]) {
            if (self::inRange($binary, (string)inet_pton($prefix), $bits)) {
                // `::` (and the unspecified `::/96` form with a zero IPv4) stays blocked below.
                $embedded = substr($binary, $offset, 4);
                if ($embedded !== "\0\0\0\0") {
                    return !self::matchesAny($embedded, self::BLOCKED_IPV4);
                }
            }
        }

        return !self::matchesAny($binary, self::BLOCKED_IPV6);
    }

    /**
     * @param list<array{string, int}> $ranges
     */
    private static function matchesAny(string $binary, array $ranges): bool
    {
        foreach ($ranges as [$network, $bits]) {
            $networkBinary = inet_pton($network);
            if ($networkBinary !== false && strlen($networkBinary) === strlen($binary) && self::inRange($binary, $networkBinary, $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $address, string $network, int $bits): bool
    {
        if (strlen($address) !== strlen($network)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        if (strncmp($address, $network, $bytes) !== 0) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
