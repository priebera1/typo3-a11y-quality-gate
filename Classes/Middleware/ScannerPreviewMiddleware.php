<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Middleware;

use Priebera\A11yQualityGate\Service\ScannerAccessTokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\VisibilityAspect;

final class ScannerPreviewMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Context $context,
        private readonly ScannerAccessTokenService $scannerAccessTokenService,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $scannerToken = trim($request->getHeaderLine('X-AQG-Scanner-Token'));

        if ($scannerToken !== '' && $this->scannerAccessTokenService->isValidToken($scannerToken)) {
            $this->context->setAspect(
                'visibility',
                new VisibilityAspect(
                    includeHiddenPages: true,
                    includeHiddenContent: true,
                )
            );

            $request = $request->withAttribute('aqgScannerPreviewTokenValid', true);
        }

        return $handler->handle($request);
    }
}
