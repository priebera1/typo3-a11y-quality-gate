<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Pro\Exception\ProNotConfiguredException;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Service\RemoteScreenshotService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\RemoteScreenshotAccessPolicy;
use Priebera\A11yQualityGate\Service\RemoteScreenshotState;
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

            $variant = (string)($queryParams['variant'] ?? '') === 'original' ? 'original' : 'highlighted';
            // The browser keeps the image for this backend user only: every view revalidates (no-cache), the ETag names
            // the user, and a matching ETag is answered after the access check above without asking the AQG service.
            // After Switch User or another login the ETag no longer matches, so no other user's image is reused. Within
            // the hour of the ETag a copy this user already loaded may still be shown after the service deleted it.
            $etag = $this->buildEtag($remotePageUid, $variant);
            if ($etag !== '' && trim($request->getHeaderLine('If-None-Match')) === $etag) {
                return $this->privateImageHeaders($this->responseFactory->createResponse(304), $etag);
            }

            $result = $this->remoteScreenshotService->fetchScreenshotOrState($remotePageUid, $variant);

            if (!isset($result['content'])) {
                // Why there is no image, for the page's fallback text (pro-module.js): expired and deleted only on
                // the AQG service's explicit answer, everything else is "could not be loaded".
                $state = (string)($result['state'] ?? RemoteScreenshotState::UNAVAILABLE);
                return $this->jsonResponse([
                    'success' => false,
                    'screenshotState' => $state,
                    'error' => 'Screenshot could not be loaded.',
                ], in_array($state, [RemoteScreenshotState::EXPIRED, RemoteScreenshotState::DELETED], true) ? 410 : 404)
                    ->withHeader('Cache-Control', 'no-store');
            }

            $response = $this->privateImageHeaders($this->responseFactory->createResponse(200), $etag)
                ->withHeader('Content-Type', $result['contentType'])
                ->withHeader('X-Content-Type-Options', 'nosniff')
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

    /** Bound to the user and to the hour, so a kept copy is fetched again at least hourly. '' without a user. */
    private function buildEtag(int $remotePageUid, string $variant): string
    {
        $userUid = $this->getBackendUserUid();
        if ($userUid <= 0) {
            return '';
        }

        return '"' . hash('sha256', implode('|', ['aqg-screenshot', $remotePageUid, $variant, $userUid, intdiv(time(), 3600)])) . '"';
    }

    private function privateImageHeaders(ResponseInterface $response, string $etag): ResponseInterface
    {
        $response = $response
            ->withHeader('Cache-Control', 'private, no-cache')
            ->withHeader('Vary', 'Cookie');

        return $etag !== '' ? $response->withHeader('ETag', $etag) : $response;
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
