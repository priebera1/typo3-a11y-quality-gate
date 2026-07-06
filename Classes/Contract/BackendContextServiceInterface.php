<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Contract;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

interface BackendContextServiceInterface
{
    public function getBackendUser(): ?BackendUserAuthentication;
}
