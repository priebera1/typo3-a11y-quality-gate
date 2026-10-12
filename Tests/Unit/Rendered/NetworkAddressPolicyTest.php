<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rendered;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Rendered\NetworkAddressPolicy;

/**
 * SEC-02: the rendered check connected to any address PHP's filter flags did not name, including carrier-grade NAT and
 * IPv6 forms that carry a private IPv4 address.
 */
final class NetworkAddressPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedAddresses(): iterable
    {
        foreach ([
            '127.0.0.1', '10.1.2.3', '172.16.5.4', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0',
            '198.18.0.1', '192.0.2.10', '224.0.0.1', '255.255.255.255',
            '::', '::1', 'fd00::1', 'fc00::5', 'fe80::1', 'fec0::1', 'ff02::1', '2001:db8::1',
            '::ffff:127.0.0.1', '::ffff:7f00:1', '::ffff:10.0.0.1', '::127.0.0.1', '64:ff9b::a00:1', '2002:a00:1::',
            '[::1]', 'not-an-ip', '',
        ] as $address) {
            yield $address === '' ? 'empty' : $address => [$address];
        }
    }

    #[Test]
    #[DataProvider('blockedAddresses')]
    public function anInternalAddressIsNotPublic(string $address): void
    {
        self::assertFalse(NetworkAddressPolicy::isPublic($address));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicAddresses(): iterable
    {
        foreach (['93.184.216.34', '8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '2a00:1450:4001:80b::200e', '::ffff:8.8.8.8', '64:ff9b::808:808', '2002:808:808::'] as $address) {
            yield $address => [$address];
        }
    }

    #[Test]
    #[DataProvider('publicAddresses')]
    public function aPublicAddressIsPublic(string $address): void
    {
        self::assertTrue(NetworkAddressPolicy::isPublic($address));
    }
}
