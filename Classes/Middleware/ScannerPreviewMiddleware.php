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
use TYPO3\CMS\Frontend\Cache\CacheInstruction;

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

        $hasValidScannerToken = false;
        if ($scannerToken !== '') {
            try {
                $hasValidScannerToken = $this->scannerAccessTokenService->isValidTokenForRequest($scannerToken, $request);
            } catch (\Throwable) {
                $hasValidScannerToken = false;
            }
        }

        if ($hasValidScannerToken) {
            $this->context->setAspect(
                'visibility',
                new VisibilityAspect(
                    includeHiddenPages: true,
                    includeHiddenContent: true,
                )
            );

            // Fable E-2: this render includes hidden pages and content but has no backend user, so TYPO3's
            // PreviewSimulator never marks it as a preview, and the page cache identifier (13.4 and 14.x) does not
            // vary by visibility. With caching allowed the scanner's render would be stored under the public page's
            // cache entry (hidden content served to every visitor), and a cached public render would be handed to the
            // scanner (hidden content missing from the scan). Neither read nor write the page cache for it. A
            // PreviewAspect is not set on purpose: it would add TYPO3's preview notice to the scanned markup.
            $cacheInstruction = $request->getAttribute('frontend.cache.instruction');
            if (!$cacheInstruction instanceof CacheInstruction) {
                $cacheInstruction = new CacheInstruction();
            }
            $cacheInstruction->disableCache('EXT:a11y_quality_gate: scanner preview renders hidden content');
            $request = $request
                ->withAttribute('frontend.cache.instruction', $cacheInstruction)
                ->withAttribute('aqgScannerPreviewTokenValid', true);
            $GLOBALS['TYPO3_REQUEST'] = $request;
        }

        return $handler->handle($request);
    }
}
