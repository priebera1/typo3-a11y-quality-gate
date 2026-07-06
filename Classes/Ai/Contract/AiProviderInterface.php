<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Contract;

use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionRequest;
use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionResult;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;

interface AiProviderInterface
{
    public function supports(string $provider): bool;
    public function suggestAltText(AiAltSuggestionRequest $request, AiProviderConfiguration $configuration): AiAltSuggestionResult;
    public function testConnection(AiProviderConfiguration $configuration): void;
}
