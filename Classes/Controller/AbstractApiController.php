<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use GuzzleHttp\Utils;
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
