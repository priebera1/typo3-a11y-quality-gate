<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rendered;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Rendered\RenderedPageFetcher;

final class RenderedPageFetcherPrivateAddressTest extends TestCase
{
    #[Test]
    public function directPrivateIpv4AndIpv6HostsAreDetectedAsPrivate(): void
    {
        self::assertTrue($this->resolvesToPrivateAddress('127.0.0.1'));
        self::assertTrue($this->resolvesToPrivateAddress('::1'));
        self::assertTrue($this->resolvesToPrivateAddress('10.0.0.1'));
        self::assertTrue($this->resolvesToPrivateAddress('172.16.0.1'));
        self::assertTrue($this->resolvesToPrivateAddress('192.168.1.1'));
    }

    #[Test]
    public function publicIpv4HostIsNotDetectedAsPrivate(): void
    {
        self::assertFalse($this->resolvesToPrivateAddress('8.8.8.8'));
    }

    private function resolvesToPrivateAddress(string $host): bool
    {
        $fetcher = (new \ReflectionClass(RenderedPageFetcher::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(RenderedPageFetcher::class, 'resolvesToPrivateAddress');
        $method->setAccessible(true);

        return (bool)$method->invoke($fetcher, $host);
    }
}
