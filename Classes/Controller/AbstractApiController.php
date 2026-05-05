<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use GuzzleHttp\Utils;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

abstract class AbstractApiController
{
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        protected readonly StreamFactoryInterface $streamFactory,
        protected readonly BackendUserService $backendUserService,
    ) {
    }

    protected function isBackendUserLoggedIn(): bool
    {
        return $this->backendUserService->isLoggedIn();
    }

    protected function getBackendUser(): ?BackendUserAuthentication
    {
        return $this->backendUserService->getBackendUser();
    }

    protected function getBackendUserUid(): int
    {
        return $this->backendUserService->getBackendUserUid();
    }

    protected function unauthorizedResponse(string $message = 'Unauthorized'): ResponseInterface
    {
        return $this->jsonResponse([
            'success' => false,
            'error' => $message,
        ], 401);
    }

    protected function forbiddenResponse(string $message = 'Access denied'): ResponseInterface
    {
        return $this->jsonResponse([
            'success' => false,
            'error' => $message,
        ], 403);
    }

    protected function badRequestResponse(string $message, array $extra = []): ResponseInterface
    {
        return $this->jsonResponse(array_merge([
            'success' => false,
            'error' => $message,
        ], $extra), 400);
    }

    protected function notFoundResponse(string $message, array $extra = []): ResponseInterface
    {
        return $this->jsonResponse(array_merge([
            'success' => false,
            'error' => $message,
        ], $extra), 404);
    }

    protected function ensureBackendUserAccess(
        AccessControlService $accessControlService,
        ?string $permission = null,
    ): ?ResponseInterface {
        if (!$this->isBackendUserLoggedIn()) {
            return $this->unauthorizedResponse();
        }

        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->forbiddenResponse();
        }

        if ($permission === 'scanAll' && !$accessControlService->canShowScanAll($backendUser)) {
            return $this->forbiddenResponse();
        }

        if ($permission === 'scanNow' && !$accessControlService->canShowScanNow($backendUser)) {
            return $this->forbiddenResponse();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function jsonResponse(array $data, int $status = 200): ResponseInterface
    {
        $json = Utils::jsonEncode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stream = $this->streamFactory->createStream($json);

        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-cache, no-store')
            ->withBody($stream);
    }
}