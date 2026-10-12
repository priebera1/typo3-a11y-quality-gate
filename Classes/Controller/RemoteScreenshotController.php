<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Pro\Exception\ProNotConfiguredException;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Service\RemoteScreenshotService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\RemoteScreenshotAccessPolicy;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsController]
final class RemoteScreenshotController extends AbstractApiController
{
    public function __construct(
        private readonly RemoteScreenshotService $remoteScreenshotService,
        private readonly RemoteScreenshotAccessPolicy $accessPolicy,
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

        // Messages from the licence and crawler chain carry crawler URLs, response bodies and token
        // metadata, and database errors carry SQL. They are logged; the client gets a fixed text.
        try {
            if (!$this->accessPolicy->canRead($remotePageUid)) {
                return $this->forbiddenResponse();
            }

            $result = $this->remoteScreenshotService->fetchScreenshotByRemotePageUid(
                $remotePageUid,
                (string)($queryParams['variant'] ?? '') === 'original' ? 'original' : 'highlighted'
            );

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
        } catch (TokenRefreshException | ProNotConfiguredException $exception) {
            $this->logScreenshotFailure($remotePageUid, $exception);
            return $this->forbiddenResponse('Screenshot is not available for the current licence.');
        } catch (\Throwable $exception) {
            $this->logScreenshotFailure($remotePageUid, $exception);
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Screenshot could not be loaded.',
            ], 500);
        }
    }

    private function logScreenshotFailure(int $remotePageUid, \Throwable $exception): void
    {
        try {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(__CLASS__)
                ->warning('AQG remote screenshot request failed', [
                    'remotePageUid' => $remotePageUid,
                    'exceptionClass' => $exception::class,
                    'exceptionMessage' => $exception->getMessage(),
                ]);
        } catch (\Throwable) {
            // Logging must never replace the bounded response with a raw error.
        }
    }
}
