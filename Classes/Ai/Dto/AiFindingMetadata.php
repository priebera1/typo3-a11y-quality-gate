<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Dto;

final readonly class AiFindingMetadata
{
    public function __construct(
        public string $findingType,
        public ?string $currentAlt,
        public ?string $qualityReason,
    ) {}
}
