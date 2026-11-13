<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Contract;

use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderCredentials;

interface AiConfigurationResolverInterface
{
    public function resolveCredentials(string $siteIdentifier): AiProviderCredentials;

    /** The configuration AI features may use; refuses a model that must be revalidated first. */
    public function resolve(string $siteIdentifier): AiProviderConfiguration;

    /** The configuration a connection test verifies, including one that awaits revalidation. */
    public function resolveForConnectionTest(string $siteIdentifier): AiProviderConfiguration;

    /** @return array<string,mixed> */
    public function status(string $siteIdentifier): array;
}
