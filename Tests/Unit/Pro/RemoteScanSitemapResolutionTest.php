<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Service\DomainNormalizer;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanInputResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Before a site scan this TYPO3 server fetches the site's /sitemap.xml itself. A redirect (for example a redirect
 * record an editor created for /sitemap.xml) may not lead it to another host, and a sitemap index may not point the
 * scan at a sitemap of another origin.
 */
final class RemoteScanSitemapResolutionTest extends TestCase
{
    private const INDEX = '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>%s</loc></sitemap></sitemapindex>';

    #[Test]
    public function aSitemapOfTheSitesOwnOriginIsUsed(): void
    {
        $request = $this->resolve(sprintf(self::INDEX, 'https://example.com/?type=1533906435&amp;sitemap=pages'));

        self::assertSame('https://example.com/?type=1533906435&sitemap=pages', $request->sitemapUrl);
    }

    #[Test]
    public function aSitemapOfAnotherOriginIsIgnoredAndTheSiteIsCrawled(): void
    {
        foreach ([
            'https://evil.example/?sitemap=pages',
            'http://example.com/?sitemap=pages',
            'https://example.com:8443/?sitemap=pages',
            'https://user:pass@example.com/?sitemap=pages',
            'file:///etc/passwd?sitemap=pages',
        ] as $loc) {
            $request = $this->resolve(sprintf(self::INDEX, htmlspecialchars($loc, ENT_XML1)));
            self::assertNull($request->sitemapUrl, $loc);
            self::assertTrue($request->followLinks, $loc);
        }
    }

    #[Test]
    public function aRedirectToAnotherHostIsNotFollowed(): void
    {
        $options = [];
        $this->resolve(sprintf(self::INDEX, 'https://example.com/?sitemap=pages'), $options);

        self::assertIsArray($options['allow_redirects']);
        self::assertSame(3, $options['allow_redirects']['max']);
        $onRedirect = $options['allow_redirects']['on_redirect'];
        $onRedirect(null, null, new Uri('https://example.com/sitemap-index.xml'));

        $this->expectException(\RuntimeException::class);
        $onRedirect(null, null, new Uri('http://169.254.169.254/latest/meta-data/'));
    }

    /**
     * A new TYPO3 site has no /sitemap.xml until the EXT:seo sitemap is configured. Every site scan of such a site (one
     * per language) was submitted as a sitemap scan and failed in the crawler with "Sitemap request failed … HTTP 404".
     */
    #[Test]
    public function aLanguageWithoutSitemapIsCrawledFromItsStartPage(): void
    {
        foreach ([404, 410] as $status) {
            $request = $this->resolveLanguage($status, '<!DOCTYPE html><title>Page Not Found</title>');
            self::assertNull($request->sitemapUrl, (string)$status);
            self::assertSame('crawl', $request->sourceType->value, (string)$status);
            self::assertTrue($request->followLinks, (string)$status);
            self::assertSame('https://example.com/de/', $request->startUrl);
        }

        $request = $this->resolveLanguage(200, '<!DOCTYPE html><title>Home</title>');
        self::assertNull($request->sitemapUrl, 'a page that is no sitemap');
    }

    #[Test]
    public function aLanguageSitemapIsKeptUnlessItIsDefinitelyMissing(): void
    {
        $urlSet = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/de/</loc></url></urlset>';
        foreach ([
            'sitemap' => [200, $urlSet],
            'sitemap index' => [200, sprintf(self::INDEX, 'https://example.com/de/?sitemap=pages')],
            // Staging behind HTTP authentication or a firewall: the crawler fetches it with the scan access settings.
            'authentication' => [401, ''],
            'forbidden' => [403, ''],
            'server error' => [503, ''],
            'transport error' => [0, null],
        ] as $case => [$status, $body]) {
            $request = $this->resolveLanguage($status, $body);
            self::assertSame('https://example.com/de/sitemap.xml', $request->sitemapUrl, $case);
            self::assertSame('sitemap', $request->sourceType->value, $case);
            self::assertFalse($request->followLinks, $case);
        }
    }

    private function resolveLanguage(int $status, ?string $body): \Priebera\A11yQualityGate\Pro\Dto\RemoteScanRequestData
    {
        $factory = $this->createMock(RequestFactory::class);
        if ($body === null) {
            $factory->method('request')->willThrowException(new \RuntimeException('Connection refused'));
        } else {
            $stream = $this->createMock(StreamInterface::class);
            $stream->method('__toString')->willReturn($body);
            $response = $this->createMock(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn($status);
            $response->method('getBody')->willReturn($stream);
            $factory->method('request')->willReturn($response);
        }

        return (new RemoteScanInputResolver(new DomainNormalizer(), $factory))->resolveForOverviewLanguage(
            new Site('main', 1, ['base' => 'https://example.com/']),
            ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE', 'flagIdentifier' => 'de', 'base' => 'https://example.com/de/', 'sitemapUrl' => 'https://example.com/de/sitemap.xml', 'isAll' => false],
            50,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolve(string $body, array &$options = []): \Priebera\A11yQualityGate\Pro\Dto\RemoteScanRequestData
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($stream);
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturnCallback(function (string $url, string $method, array $requestOptions) use ($response, &$options): ResponseInterface {
            $options = $requestOptions;
            return $response;
        });

        return (new RemoteScanInputResolver(new DomainNormalizer(), $factory))
            ->resolveForOverview(new Site('main', 1, ['base' => 'https://example.com/']), 50);
    }
}
