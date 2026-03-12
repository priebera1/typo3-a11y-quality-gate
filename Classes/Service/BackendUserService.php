<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class BackendUserService
{
    public function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }

    public function isLoggedIn(): bool
    {
        return $this->getBackendUser() instanceof BackendUserAuthentication;
    }

    public function getBackendUserUid(): int
    {
        return (int)($this->getBackendUser()?->user['uid'] ?? 0);
    }

    public function isAdmin(): bool
    {
        return (bool)($this->getBackendUser()?->isAdmin() ?? false);
    }
}
