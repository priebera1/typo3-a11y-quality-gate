<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Contract\AccessControlServiceInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class AccessControlService implements AccessControlServiceInterface
{
    public function __construct(private readonly ConnectionPool $connectionPool) {}

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

    public function canEditRecord(?BackendUserAuthentication $backendUser = null): bool
    {
        return $this->resolveVisibilityFlag($backendUser, 'editRecord', true);
    }

    public function canManageAdminOnlySettings(?BackendUserAuthentication $backendUser = null): bool
    {
        $backendUser ??= $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || !$backendUser->isAdmin()) {
            return false;
        }

        $backendUserId = (int)($backendUser->user['uid'] ?? 0);
        if ($backendUserId <= 0) {
            return false;
        }

        try {
            $adminFlag = $this->connectionPool
                ->getConnectionForTable('be_users')
                ->fetchOne(
                    'SELECT admin FROM be_users WHERE uid = ? AND deleted = 0 AND disable = 0',
                    [$backendUserId],
                );
        } catch (\Throwable) {
            return false;
        }

        return (int)$adminFlag === 1;
    }

    public function canRemediateImages(?BackendUserAuthentication $backendUser = null): bool
    {
        $backendUser ??= $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        $userTsConfig = $backendUser->getTSConfig();
        $value = $userTsConfig['options.']['a11y_quality_gate.']['allowImageRemediation'] ?? null;

        return $value === 1 || $value === '1';
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
