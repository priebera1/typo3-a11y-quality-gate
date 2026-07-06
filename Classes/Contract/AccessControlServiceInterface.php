<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Contract;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

interface AccessControlServiceInterface
{
    public function canManageAdminOnlySettings(?BackendUserAuthentication $backendUser = null): bool;

    public function canRemediateImages(?BackendUserAuthentication $backendUser = null): bool;
}
