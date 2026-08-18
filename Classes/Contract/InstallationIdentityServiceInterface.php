<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Contract;

interface InstallationIdentityServiceInterface
{
    public function getOrCreateInstallationId(): string;
}
