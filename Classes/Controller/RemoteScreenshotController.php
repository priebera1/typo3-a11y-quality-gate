<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Service\RemoteScreenshotService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Backend\Attribute\AsController;

#[AsController]
final class RemoteScreenshotController extends AbstractApiController
{
    public function __construct(
        private readonly RemoteScreenshotService $remoteScreenshotService,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        BackendUserService $backendUserService,
    ) {
        parent::__construct($responseFactory, $streamFactory, $backendUserService);
    }

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isBackendUserLoggedIn()) {
            return $this->unauthorizedResponse();
        }

        $queryParams = $request->getQueryParams();
        $remotePageUid = (int)($queryParams['remotePageUid'] ?? 0);

        if ($remotePageUid <= 0) {
            return $this->badRequestResponse('Missing remotePageUid');
        }

        try {
            $result = $this->remoteScreenshotService->fetchScreenshotByRemotePageUid($remotePageUid);

            if (!is_array($result)) {
                return $this->notFoundResponse('Screenshot could not be loaded');
            }

            $response = $this->responseFactory->createResponse(200)
                ->withHeader('Content-Type', $result['contentType'])
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Cache-Control', 'private, max-age=300')
                ->withHeader('Content-Disposition', 'inline; filename="' . $result['filename'] . '"');

            $response->getBody()->write($result['content']);

            return $response;
        } catch (TokenRefreshException $exception) {
            return $this->jsonResponse([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 403);
        } catch (\Throwable $exception) {
            return $this->jsonResponse([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 500);
        }
    }
}