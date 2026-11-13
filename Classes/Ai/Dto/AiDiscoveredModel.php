<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Dto;

/**
 * A model a provider account can use. Capabilities are null when the provider does not report them; AQG's model
 * registry then decides alone.
 */
final readonly class AiDiscoveredModel
{
    public function __construct(
        public string $id,
        public ?bool $imageInput = null,
        public ?bool $structuredOutputs = null,
        public int $maxInputTokens = 0,
    ) {}
}
