<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Service\DomainNormalizer;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanInputResolver;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * The licence check and the crawler token of a single-page scan are scoped to the scanned host. A page on a
 * language base of another domain was scanned with a token for the site's default host, which the crawler refuses
 * (`domain_mismatch`), although both hosts come from the same Site Configuration.
 */
final class RemoteScanInputResolverDomainTest extends TestCase
{
    #[Test]
    public function aPageOnAnotherLanguageDomainIsScannedForThatDomain(): void
    {
        $resolved = $this->resolver()->resolveForSinglePage($this->site(), 'https://www.example.de/seite');

        self::assertSame('example.de', $resolved->domain);
        self::assertSame('https://www.example.de/seite', $resolved->startUrl);
        self::assertSame('main', $resolved->siteIdentifier);
    }

    #[Test]
    public function aPageOnTheDefaultBaseKeepsTheSiteDomain(): void
    {
        self::assertSame('example.com', $this->resolver()->resolveForSinglePage($this->site(), 'https://example.com/page')->domain);
    }

    #[Test]
    public function aHostOutsideTheSiteConfigurationIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1779360104);

        $this->resolver()->resolveForSinglePage($this->site(), 'https://other.example/page');
    }

    private function resolver(): RemoteScanInputResolver
    {
        return new RemoteScanInputResolver(new DomainNormalizer(), $this->createMock(RequestFactory::class));
    }

    private function site(): Site
    {
        $english = $this->createMock(SiteLanguage::class);
        $english->method('getBase')->willReturn(new Uri('https://example.com/'));
        $german = $this->createMock(SiteLanguage::class);
        $german->method('getBase')->willReturn(new Uri('https://www.example.de/'));

        $site = $this->createMock(Site::class);
        $site->method('getBase')->willReturn(new Uri('https://example.com/'));
        $site->method('getIdentifier')->willReturn('main');
        $site->method('getLanguages')->willReturn([0 => $english, 1 => $german]);

        return $site;
    }
}
