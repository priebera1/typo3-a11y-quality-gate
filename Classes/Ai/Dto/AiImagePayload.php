<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Dto;

final readonly class AiImagePayload
{
    public function __construct(
        public string $dataUrl,
        public string $mimeType,
    ) {}
}
