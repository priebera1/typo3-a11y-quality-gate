<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Contract\BackendContextServiceInterface;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

final class BackendContextService implements BackendContextServiceInterface
{
    public function __construct(
        private readonly BackendLanguageService $backendLanguageService,
        private readonly BackendUserService $backendUserService,
        private readonly BackendFlashMessageService $backendFlashMessageService,
        private readonly Context $context,
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


    /**
     * @return array{uid:int,username:string,name:string}
     */
    public function getBackendUserSnapshot(): array
    {
        return $this->backendUserService->getBackendUserSnapshot();
    }

    public function isAdmin(): bool
    {
        if ($this->backendUserService->isAdmin()) {
            return true;
        }

        try {
            return (bool)$this->context->getPropertyFromAspect('backend.user', 'isAdmin', false);
        } catch (\Throwable) {
            return false;
        }
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
