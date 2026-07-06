<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Dto;

final readonly class AiAltSuggestionResult
{
    public const STATUS_SUGGESTION = 'suggestion';
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public function __construct(
        public string $status,
        public string $suggestion,
        public string $provider,
        public string $model,
        public string $promptVersion,
    ) {}
}
