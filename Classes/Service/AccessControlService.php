<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class AccessControlService
{
    public function canShowToolbarItem(?BackendUserAuthentication $backendUser = null): bool
    {
        return $this->resolveVisibilityFlag($backendUser, 'showToolbarItem', true);
    }

    public function canShowScanAll(?BackendUserAuthentication $backendUser = null): bool
    {
        return $this->resolveVisibilityFlag($backendUser, 'showScanAll', true);
    }

    public function canShowScanNow(?BackendUserAuthentication $backendUser = null): bool
    {
        return $this->resolveVisibilityFlag($backendUser, 'showScanNow', true);
    }

    public function canShowSettings(?BackendUserAuthentication $backendUser = null): bool
    {
        return $this->resolveVisibilityFlag($backendUser, 'showSettings', true);
    }

    private function resolveVisibilityFlag(
        ?BackendUserAuthentication $backendUser,
        string $key,
        bool $default = true,
    ): bool {
        $backendUser ??= $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        $userTsConfig = $backendUser->getTSConfig();
        $value = $userTsConfig['options.']['a11y_quality_gate.'][$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return (bool)(int)$value;
    }
}