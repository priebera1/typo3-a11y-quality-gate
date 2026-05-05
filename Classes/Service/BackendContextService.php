<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

final class BackendContextService
{
    public function __construct(
        private readonly BackendLanguageService $backendLanguageService,
        private readonly BackendUserService $backendUserService,
        private readonly BackendFlashMessageService $backendFlashMessageService,
    ) {
    }

    public function translate(string $key, string $file = 'locallang.xlf'): string
    {
        return $this->backendLanguageService->translate($key, $file);
    }

    public function getBackendUser(): ?BackendUserAuthentication
    {
        return $this->backendUserService->getBackendUser();
    }

    public function getBackendUserUid(): int
    {
        return $this->backendUserService->getBackendUserUid();
    }

    public function isAdmin(): bool
    {
        return $this->backendUserService->isAdmin();
    }

    public function addFlashMessage(
        string $message,
        ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::OK,
        string $title = '',
        bool $storeInSession = true,
    ): void {
        $this->backendFlashMessageService->addMessage(
            $message,
            $severity,
            $title,
            $storeInSession,
        );
    }
}