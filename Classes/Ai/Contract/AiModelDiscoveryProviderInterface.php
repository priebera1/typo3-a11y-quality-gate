<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Contract;

use Priebera\A11yQualityGate\Ai\Dto\AiProviderCredentials;

interface AiModelDiscoveryProviderInterface
{
    public function supports(string $provider): bool;

    /** @return list<string> */
    public function listModelIds(AiProviderCredentials $credentials): array;
}
