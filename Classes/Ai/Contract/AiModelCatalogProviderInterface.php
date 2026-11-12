<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Contract;

use Priebera\A11yQualityGate\Ai\Dto\AiDiscoveredModel;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderCredentials;

/**
 * Model discovery for providers whose model list reports capabilities (Anthropic's GET /v1/models). The discovery
 * service uses those capabilities to exclude a model the provider says cannot take images or structured outputs.
 */
interface AiModelCatalogProviderInterface extends AiModelDiscoveryProviderInterface
{
    /** @return list<AiDiscoveredModel> */
    public function listModels(AiProviderCredentials $credentials): array;
}
