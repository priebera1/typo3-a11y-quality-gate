<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Contract;

interface AiConfigurationManagerInterface
{
    public function save(string $siteIdentifier, #[\SensitiveParameter] string $apiKey): void;

    public function selectModel(string $siteIdentifier, string $modelId): void;
}
