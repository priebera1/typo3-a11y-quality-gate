<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;

final class FrontendDebugMarkerService
{
    public function __construct(
        private readonly Context $context,
        private readonly ScannerAccessTokenService $scannerAccessTokenService,
    ) {
    }


    public function isEnabled(ServerRequestInterface $request): bool
    {
        if (!$this->isFrontendRequest($request)) {
            return false;
        }

        $queryParams = $request->getQueryParams();
        if ((string)($queryParams['aqgDebug'] ?? '') !== '1') {
            return false;
        }

        if ($this->isBackendUserLoggedIn()) {
            return true;
        }

        $scannerToken = trim($request->getHeaderLine('X-AQG-Scanner-Token'));
        if ($scannerToken !== '' && $this->scannerAccessTokenService->isValidToken($scannerToken)) {
            return true;
        }

        return false;
    }

    private function isFrontendRequest(ServerRequestInterface $request): bool
    {
        return (int)($request->getAttribute('applicationType') ?? 0) === 1;
    }

    private function isBackendUserLoggedIn(): bool
    {
        return (bool)$this->context->getPropertyFromAspect(
            'backend.user',
            'isLoggedIn',
            false
        );
    }
}
