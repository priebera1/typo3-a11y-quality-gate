<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;

final class FrontendDebugMarkerService
{
    public function __construct(
        private readonly Context $context,
    ) {
    }


    public function isEnabled(ServerRequestInterface $request): bool
    {
        if (!$this->isFrontendRequest($request)) {
            return false;
        }

        $queryParams = $request->getQueryParams();

        return (string)($queryParams['aqgDebug'] ?? '') === '1';
    }

//    public function isEnabled(ServerRequestInterface $request): bool
//    {
//        if (!$this->isFrontendRequest($request)) {
//            return false;
//        }
//
//        $queryParams = $request->getQueryParams();
//        if ((string)($queryParams['aqgDebug'] ?? '') !== '1') {
//            return false;
//        }
//
//        if (!$this->isBackendUserLoggedIn()) {
//            return false;
//        }
//
//        return true;
//    }

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