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
