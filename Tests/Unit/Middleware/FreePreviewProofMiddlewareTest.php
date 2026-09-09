<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\FreePreview\FreePreviewProofService;
use Priebera\A11yQualityGate\Middleware\FreePreviewProofMiddleware;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;

final class FreePreviewProofMiddlewareTest extends TestCase
{
    #[Test]
    public function publicGetReturnsBoundedJsonWithoutSensitiveData(): void
    {
        $site = $this->createMock(Site::class);
        $site->method('getBase')->willReturn(new Uri('https://example.test/subsite/'));
        $proof = $this->createMock(FreePreviewProofService::class);
        $proof->expects(self::once())
            ->method('buildForSiteBase')
            ->with('https://example.test/subsite/')
            ->willReturn('safe-proof');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $request = (new ServerRequest(
            'https://example.test/subsite/_aqg/free-preview-proof',
            'GET',
        ))->withAttribute('site', $site);

        $response = (new FreePreviewProofMiddleware($proof))->process($request, $handler);
        $body = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertLessThanOrEqual(4096, strlen($body));
        self::assertSame(['version' => 1, 'proof' => 'safe-proof'], json_decode($body, true, 16, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('installationId', $body);
        self::assertStringNotContainsString('licence', strtolower($body));
        self::assertStringNotContainsString('token', strtolower($body));
    }

    #[Test]
    public function proofEndpointRejectsNonGetMethodsWithoutDelegating(): void
    {
        $site = $this->createMock(Site::class);
        $site->method('getBase')->willReturn(new Uri('https://example.test/'));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $request = (new ServerRequest('https://example.test/_aqg/free-preview-proof', 'POST'))
            ->withAttribute('site', $site);

        $response = (new FreePreviewProofMiddleware(
            $this->createMock(FreePreviewProofService::class),
        ))->process($request, $handler);

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->getHeaderLine('Allow'));
    }
}
