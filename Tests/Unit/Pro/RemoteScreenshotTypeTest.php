<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResult;
use Priebera\A11yQualityGate\Pro\Service\ProTokenService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScreenshotService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Screenshots are served inline from the TYPO3 backend origin. Only raster images pass: an SVG answer would be a
 * document able to run script in the backend.
 */
final class RemoteScreenshotTypeTest extends TestCase
{
    #[Test]
    public function onlyRasterImagesAreServed(): void
    {
        foreach ([
            'image/png' => true,
            'image/jpeg; charset=binary' => true,
            'IMAGE/WEBP' => true,
            'image/svg+xml' => false,
            'text/html' => false,
            'image/svg+xml; charset=utf-8' => false,
        ] as $contentType => $served) {
            $result = $this->service($contentType)->fetchScreenshotByRemotePageUid(7);
            self::assertSame($served, is_array($result), $contentType);
        }
    }

    private function service(string $contentType): RemoteScreenshotService
    {
        $scans = $this->createMock(RemoteScanRepository::class);
        $scans->method('findPageByUid')->willReturn(['uid' => 7, 'external_page_id' => 'page-1', 'remote_scan' => 3]);
        $scans->method('findScanByUid')->willReturn(['uid' => 3, 'site_identifier' => 'main']);
        $sites = $this->createMock(SiteResolutionService::class);
        $sites->method('resolveSiteByIdentifier')->willReturn(new Site('main', 1, ['base' => 'https://example.com/']));
        $tokens = $this->createMock(ProTokenService::class);
        $tokens->method('getValidToken')->willReturn(new AccessTokenResult('jwt', 3600, time(), 'pro', ['crawler']));
        $context = $this->createMock(ExtensionContextService::class);
        $context->method('getNormalizedDomainFromSiteBase')->willReturn('example.com');
        $context->method('getExtensionVersion')->willReturn('1.9.8');

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('binary');
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaderLine')->willReturnCallback(static fn (string $name): string => strtolower($name) === 'content-type' ? $contentType : '');
        $response->method('getBody')->willReturn($stream);
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturn($response);

        return new RemoteScreenshotService($scans, $sites, $tokens, $factory, $context);
    }
}
