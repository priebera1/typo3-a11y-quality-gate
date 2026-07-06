<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Contract;

interface AiFeatureAccessServiceInterface
{
    public function isAvailable(string $siteIdentifier): bool;
}
