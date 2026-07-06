<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Contract;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

interface BackendUserServiceInterface
{
    public function getBackendUser(): ?BackendUserAuthentication;
    public function getBackendUserUid(): int;
    /** @return array{uid:int,username:string,name:string} */
    public function getBackendUserSnapshot(): array;
}
