<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Service\DomainNormalizer;

/**
 * The extension's domain must be the string the AQG service derives (aqg-api `normalizeEntitlementDomain`: WHATWG URL,
 * UTS #46 non-transitional, then without `www.` and trailing dots). Expected values below are what the service returns.
 */
final class DomainNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function siteBases(): iterable
    {
        yield 'ASCII' => ['https://example.com/', 'example.com'];
        yield 'ASCII upper case, www, trailing dot' => ['https://WWW.Example.COM./sub/', 'example.com'];
        yield 'ASCII with port' => ['https://example.com:8443/', 'example.com'];
        yield 'Unicode' => ['https://müller.de/', 'xn--mller-kva.de'];
        yield 'Unicode upper case' => ['https://MÜLLER.DE/', 'xn--mller-kva.de'];
        yield 'Unicode with www' => ['https://www.müller.de/de/', 'xn--mller-kva.de'];
        yield 'Unicode with trailing dot' => ['https://müller.de./', 'xn--mller-kva.de'];
        yield 'Punycode as given' => ['https://xn--mller-kva.de/', 'xn--mller-kva.de'];
        yield 'Punycode upper case with www' => ['https://WWW.XN--MLLER-KVA.DE/', 'xn--mller-kva.de'];
        yield 'percent-encoded Unicode' => ['https://m%C3%BCller.de/', 'xn--mller-kva.de'];
        yield 'sharp s, non-transitional like WHATWG' => ['https://faß.de/', 'xn--fa-hia.de'];
        yield 'Unicode label and subdomain' => ['https://shop.bücher.example/', 'shop.xn--bcher-kva.example'];
        yield 'fullwidth letters map to ASCII' => ['https://ＥＸＡＭＰＬＥ.com/', 'example.com'];
        yield 'fullwidth www is www' => ['https://ｗｗｗ.müller.de/', 'xn--mller-kva.de'];
        // JavaScript's Final_Sigma rule skips `.`: a Σ before another label stays σ, a Σ ending the host becomes ς.
        yield 'upper-case Greek sigma before another label' => ['https://ΟΔΟΣ.gr/', 'xn--pxavbq.gr'];
        yield 'Greek final sigma typed as ς' => ['https://οδος.gr/', 'xn--pxavbm.gr'];
        yield 'upper-case Greek sigma ending the host' => ['https://example.ΟΔΟΣ/', 'example.xn--pxavbm'];
        yield 'upper-case Greek sigma ending the host before a port' => ['https://example.ΟΔΟΣ:8080/', 'example.xn--pxavbm'];
        yield 'upper-case Greek sigma before a hyphen' => ['https://ΟΔΟΣ-ΑΒ.gr/', 'xn----zlbdf8cbv.gr'];
        yield 'upper-case Greek sigma starting a word' => ['https://ΣΟΦΙΑ.gr/', 'xn--mxaprpq.gr'];
    }

    #[Test]
    #[DataProvider('siteBases')]
    public function aSiteBaseIsTheDomainTheServiceDerives(string $siteBase, string $expected): void
    {
        self::assertSame($expected, (new DomainNormalizer())->normalizeFromSiteBase($siteBase));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHosts(): iterable
    {
        yield 'Unicode with a space' => ['mü ller.de'];
        yield 'empty Unicode label' => ['mü..de'];
        yield 'Unicode label starting with a hyphen' => ['-müller.de'];
        yield 'Unicode label over 63 characters' => [str_repeat('ü', 64) . '.de'];
        yield 'Unicode with a URL delimiter' => ['müller.de/x'];
        yield 'blank' => ['   '];
    }

    #[Test]
    #[DataProvider('invalidHosts')]
    public function aHostThatCannotBeConvertedHasNoDomain(string $host): void
    {
        self::assertSame('', (new DomainNormalizer())->normalizeHost($host));
    }

    #[Test]
    public function asciiHostsKeepTheirEstablishedNormalisation(): void
    {
        $normalizer = new DomainNormalizer();

        // ICU would refuse these; they worked before and the service accepts them, so they must keep working.
        self::assertSame('ab--c.de', $normalizer->normalizeHost('ab--c.de'));
        self::assertSame(str_repeat('a', 70) . '.de', $normalizer->normalizeHost(str_repeat('a', 70) . '.de'));
        self::assertSame('localhost', $normalizer->normalizeHost('localhost'));
        self::assertSame('192.168.0.1', $normalizer->normalizeHost('192.168.0.1'));
        self::assertSame('', $normalizer->normalizeFromSiteBase('not a url'));
    }

    #[Test]
    public function differentDomainsNeverShareADomain(): void
    {
        $normalizer = new DomainNormalizer();
        $domains = array_map(
            static fn (string $base): string => $normalizer->normalizeFromSiteBase($base),
            ['https://müller.de/', 'https://muller.de/', 'https://mueller.de/', 'https://müller.at/', 'https://shop.müller.de/', 'https://fass.de/', 'https://faß.de/']
        );

        self::assertSame($domains, array_values(array_unique($domains)));
        self::assertNotContains('', $domains);
    }

    #[Test]
    public function theDisplayFormIsUnicodeAndOnlyForDisplay(): void
    {
        $normalizer = new DomainNormalizer();

        self::assertSame('müller.de', $normalizer->toDisplay('xn--mller-kva.de'));
        self::assertSame('example.com', $normalizer->toDisplay('example.com'));
        self::assertSame('xn--zz.de', $normalizer->toDisplay('xn--zz.de'), 'an undecodable label is shown as it is');
        self::assertSame('xn--mller-kva.de', $normalizer->normalizeHost($normalizer->toDisplay('xn--mller-kva.de')));
    }
}
