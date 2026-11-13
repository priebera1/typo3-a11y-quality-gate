<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\ReleaseQa;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\FreePreview\PublicSiteAddressClassifier;
use Priebera\A11yQualityGate\Pro\Service\DomainNormalizer;
use Priebera\A11yQualityGate\Utility\ScanUrlUtility;
use Priebera\A11yQualityGate\Utility\UrlAuthority;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * SOL audit COMPAT-01: host extraction never depends on the process locale. parse_url() replaced UTF-8 bytes of a Unicode
 * host with "_" under LC_CTYPE=C.UTF-8 / en_US.UTF-8 on macOS PHP 8.5.8 (`MÜLLER.DE` → `M\xC3_LLER.DE`). Every case runs
 * under each locale the process can set and the original locale is restored; product code never changes the locale.
 */
final class SolAuditUnicodeAuthorityTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    /** @return array<string, array{0: string, 1: string}> */
    public static function siteBases(): array
    {
        return [
            'upper-case umlaut' => ['https://MÜLLER.DE/', 'xn--mller-kva.de'],
            'sharp s (non-transitional)' => ['https://faß.de/', 'xn--fa-hia.de'],
            'scheme-relative' => ['//müller.de/', 'xn--mller-kva.de'],
            'www, port, path' => ['https://www.Müller.de:8443/x?y#z', 'xn--mller-kva.de'],
            'user info' => ['https://user:pa@ss@müller.de/', 'xn--mller-kva.de'],
            'punycode control' => ['https://xn--mller-kva.de/', 'xn--mller-kva.de'],
            'ascii control' => ['https://www.Example.org/', 'example.org'],
            'greek final sigma' => ['https://ΟΔΟΣ.gr/', 'xn--pxavbq.gr'],
            'ipv6' => ['https://[::1]:8080/', '[::1]'],
            'no host' => ['/', ''],
            'no scheme no slashes' => ['example.org/', ''],
            'bad port' => ['https://example.org:99999/', ''],
            'non-numeric port' => ['https://example.org:abc/', ''],
            'space in host' => ['https://exa mple.org/', ''],
            'unclosed ipv6' => ['https://[::1/', ''],
        ];
    }

    #[Test]
    #[DataProvider('siteBases')]
    public function domainsAreTheSameInEveryLocale(string $siteBase, string $expected): void
    {
        $normalizer = new DomainNormalizer();
        foreach ($this->locales() as $locale) {
            $this->withLocale($locale, function () use ($normalizer, $siteBase, $expected, $locale): void {
                self::assertSame($expected, $normalizer->normalizeFromSiteBase($siteBase), $locale . ': ' . $siteBase);
            });
        }
    }

    #[Test]
    public function theAuthorityParserAgreesWithParseUrlForAsciiUrls(): void
    {
        foreach (['https://example.org/', 'http://example.org:8080/a?b=c#d', 'https://u:p@example.org/', 'https://[2001:db8::1]:443/', '//example.org', 'mailto:a@b.c', '/relative', 'example.org:8080/', 'example.org:8080', 'example.org/'] as $url) {
            $authority = UrlAuthority::parse($url);
            self::assertIsArray($authority);
            self::assertSame((string)(parse_url($url, PHP_URL_HOST) ?? ''), $authority['host'], $url);
            self::assertSame(parse_url($url, PHP_URL_PORT), $authority['port'], $url);
        }
    }

    #[Test]
    public function urlIdentityAndThePublicAddressGuidanceAreLocaleIndependent(): void
    {
        foreach ($this->locales() as $locale) {
            $this->withLocale($locale, function () use ($locale): void {
                self::assertSame('https://müller.de/Foo', ScanUrlUtility::comparable('HTTPS://müller.de/Foo/'), $locale);
                self::assertNotSame(ScanUrlUtility::comparable('https://müller.de/foo'), ScanUrlUtility::comparable('https://müller.de/Foo'), 'paths stay case-sensitive');
                $classifier = new \ReflectionMethod(PublicSiteAddressClassifier::class, 'extractHost');
                self::assertSame('mÜller.de', $classifier->invoke((new \ReflectionClass(PublicSiteAddressClassifier::class))->newInstanceWithoutConstructor(), 'https://MÜLLER.DE/'), $locale);
            });
        }
    }

    /** @return list<string> */
    private function locales(): array
    {
        $locales = ['C'];
        foreach (['C.UTF-8', 'en_US.UTF-8', 'de_DE.UTF-8'] as $candidate) {
            $previous = (string)setlocale(LC_CTYPE, '0');
            if (setlocale(LC_CTYPE, $candidate) !== false) {
                $locales[] = $candidate;
            }
            setlocale(LC_CTYPE, $previous);
        }

        return $locales;
    }

    private function withLocale(string $locale, \Closure $test): void
    {
        $previous = (string)setlocale(LC_CTYPE, '0');
        setlocale(LC_CTYPE, $locale);
        try {
            $test();
        } finally {
            setlocale(LC_CTYPE, $previous);
        }
    }
}
