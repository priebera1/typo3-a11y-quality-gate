<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Middleware;

use Priebera\A11yQualityGate\FreePreview\FreePreviewProofService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Site\Entity\Site;

final class FreePreviewProofMiddleware implements MiddlewareInterface
{
    private const PROOF_PATH = '/_aqg/free-preview-proof';

    public function __construct(
        private readonly FreePreviewProofService $proofService,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $site = $request->getAttribute('site');
        if (!$site instanceof Site || !$this->isProofPath($request, $site)) {
            return $handler->handle($request);
        }

        if (strtoupper($request->getMethod()) !== 'GET') {
            return (new JsonResponse([
                'error' => 'Method not allowed',
            ], 405))->withHeader('Allow', 'GET');
        }

        return (new JsonResponse([
            'version' => 1,
            'proof' => $this->proofService->buildForSiteBase((string)$site->getBase()),
        ]))
            ->withHeader('Cache-Control', 'public, max-age=300')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function isProofPath(ServerRequestInterface $request, Site $site): bool
    {
        $basePath = rtrim($site->getBase()->getPath(), '/');

        return $request->getUri()->getPath() === $basePath . self::PROOF_PATH;
    }
}
