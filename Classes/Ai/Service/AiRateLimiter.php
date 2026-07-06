<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiRateLimitRepositoryInterface;

use Priebera\A11yQualityGate\Ai\Exception\AiRateLimitException;

final class AiRateLimiter
{
    public const LIMIT = 10;
    public const WINDOW_SECONDS = 60;

    public function __construct(
        private readonly AiRateLimitRepositoryInterface $repository,
        private readonly BackendUserServiceInterface $backendUserService,
    ) {}

    public function assertAllowed(string $siteIdentifier, ?int $now = null): void
    {
        $userUid = $this->backendUserService->getBackendUserUid();
        if ($siteIdentifier === '' || $userUid <= 0) {
            throw new AiRateLimitException('AI requests require an authenticated backend user and site context.', self::WINDOW_SECONDS, 1771002100);
        }

        $state = $this->repository->consume(
            hash('sha256', $siteIdentifier . ':' . $userUid),
            self::LIMIT,
            self::WINDOW_SECONDS,
            $now ?? time(),
        );
        if (!$state['allowed']) {
            throw new AiRateLimitException(
                'Too many AI requests. Please wait and try again.',
                $state['retryAfter'],
                1771002101,
            );
        }
    }
}
