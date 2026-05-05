<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Middleware;

use Priebera\A11yQualityGate\Service\FrontendDebugMarkerService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AqgFrontendDebugMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly FrontendDebugMarkerService $frontendDebugMarkerService,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $enabled = $this->frontendDebugMarkerService->isEnabled($request);

        $request = $request->withAttribute('aqgDebugMarkers', $enabled);
        $response = $handler->handle($request);

        if (!$enabled) {
            return $response;
        }

        return $response
            ->withHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('X-AQG-Debug', '1');
    }
}