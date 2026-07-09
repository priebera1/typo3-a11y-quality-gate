<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Dto;

final readonly class AiIframeTitleSuggestionResult
{
    public const STATUS_SUGGESTION = 'suggestion';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_UNSUPPORTED_CONTEXT = 'unsupported_context';
    public const STATUS_REFUSAL = 'refusal';

    public function __construct(
        public string $status,
        public string $suggestedIframeTitle,
        public string $reason,
        public bool $needsReview = true,
        public string $provider = '',
        public string $model = '',
        public string $promptVersion = '',
    ) {}

    /** @return array<string,bool|string> */
    public function toResponsePayload(): array
    {
        return [
            'status' => $this->status,
            'suggestedIframeTitle' => $this->suggestedIframeTitle,
            'reason' => $this->reason,
            'needsReview' => $this->needsReview,
            'provider' => $this->provider,
            'model' => $this->model,
            'promptVersion' => $this->promptVersion,
        ];
    }
}
