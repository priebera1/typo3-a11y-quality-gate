<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class AccessControlService
{
    public function canShowToolbarItem(?BackendUserAuthentication $backendUser = null): bool
    {
        return $this->resolvePermission($backendUser, 'showToolbarItem');
    }

    public function canShowScanAll(?BackendUserAuthentication $backendUser = null): bool
    {
        return $this->resolvePermission($backendUser, 'showScanAll');
    }

    public function canShowScanNow(?BackendUserAuthentication $backendUser = null): bool
    {
        return $this->resolvePermission($backendUser, 'showScanNow');
    }

    public function canShowSettings(?BackendUserAuthentication $backendUser = null): bool
    {
        return $this->resolvePermission($backendUser, 'showSettings');
    }

    private function resolvePermission(?BackendUserAuthentication $backendUser, string $key): bool
    {
        $backendUser ??= $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        $userTsConfig = $backendUser->getTSConfig();
        $value = $userTsConfig['options.']['a11y_quality_gate.'][$key] ?? null;

        return (bool)((int)$value);
    }
}
