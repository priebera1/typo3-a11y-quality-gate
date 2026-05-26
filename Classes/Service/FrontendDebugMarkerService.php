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
        private readonly RenderedCheckNonceService $renderedCheckNonceService,
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

        $isRenderedCheckRequest = (string)($queryParams['tx_aqg_rendered_check'] ?? '') === '1';
        $scannerToken = trim($request->getHeaderLine('X-AQG-Scanner-Token'));
        $hasValidScannerToken = $scannerToken !== '' && $this->scannerAccessTokenService->isValidToken($scannerToken);

        if ($isRenderedCheckRequest) {
            return $this->renderedCheckNonceService->isValidRequest($request) || $hasValidScannerToken;
        }

        if ($this->isBackendUserLoggedIn()) {
            return true;
        }

        return $hasValidScannerToken;
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
