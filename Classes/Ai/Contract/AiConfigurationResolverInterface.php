<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Contract;

use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderCredentials;

interface AiConfigurationResolverInterface
{
    public function resolveCredentials(string $siteIdentifier): AiProviderCredentials;

    public function resolve(string $siteIdentifier): AiProviderConfiguration;

    /** @return array<string,mixed> */
    public function status(string $siteIdentifier): array;
}
