<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\FreePreview;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\FreePreview\PublicSiteAddressClassifier;

/**
 * Only decides which guidance the Overview shows before a Free Remote Preview. It never makes a host scannable:
 * the crawler's DNS, private network and redirect checks stay authoritative for every address.
 */
final class PublicSiteAddressClassifierTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function nonPublicBases(): array
    {
        return [
            'DDEV' => ['https://typo314.ddev.site/test/', 'typo314.ddev.site'],
            'DDEV uppercase with trailing dot' => ['https://TYPO314.DDEV.SITE./', 'typo314.ddev.site'],
            'Lando' => ['https://aqg.lndo.site/', 'aqg.lndo.site'],
            'localhost' => ['http://localhost:8080/', 'localhost'],
            'localhost subdomain' => ['https://aqg.localhost/', 'aqg.localhost'],
            'mDNS' => ['https://intranet.local/', 'intranet.local'],
            'internal' => ['https://cms.company.internal/', 'cms.company.internal'],
            'reserved .test' => ['https://typo3.test/', 'typo3.test'],
            'home.arpa' => ['https://nas.home.arpa/', 'nas.home.arpa'],
            'lan' => ['https://typo3.lan/', 'typo3.lan'],
            'single label' => ['https://staging/', 'staging'],
            'loopback IPv4' => ['http://127.0.0.1/', '127.0.0.1'],
            'private IPv4' => ['https://192.168.1.20/', '192.168.1.20'],
            'private 10/8' => ['https://10.0.0.5/', '10.0.0.5'],
            'link-local' => ['http://169.254.169.254/', '169.254.169.254'],
            'loopback IPv6' => ['http://[::1]/', '::1'],
            'unique local IPv6' => ['https://[fd00::1]/', 'fd00::1'],
            'base without scheme' => ['typo314.ddev.site/', 'typo314.ddev.site'],
            'protocol-relative base' => ['//typo314.ddev.site/', 'typo314.ddev.site'],
        ];
    }

    #[Test]
    #[DataProvider('nonPublicBases')]
    public function addressesTheCrawlerCanNeverReachAreRecognised(string $base, string $host): void
    {
        self::assertSame($host, (new PublicSiteAddressClassifier())->findNonPublicHost($base));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function possiblyPublicBases(): array
    {
        return [
            'public site' => ['https://typo3test.priebera.sk/'],
            'public site with path' => ['https://www.example.com/de/'],
            'example.com is a real domain' => ['https://example.com/'],
            'public IPv4' => ['https://93.184.216.34/'],
            'domain ending in a reserved word' => ['https://mytest.de/'],
            'domain containing local' => ['https://local.example.org/'],
            'base path only' => ['/'],
            'empty' => [''],
        ];
    }

    #[Test]
    #[DataProvider('possiblyPublicBases')]
    public function everythingElseIsLeftToTheCrawler(string $base): void
    {
        self::assertNull((new PublicSiteAddressClassifier())->findNonPublicHost($base));
    }
}
