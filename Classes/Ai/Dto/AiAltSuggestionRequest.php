<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Dto;

final readonly class AiAltSuggestionRequest
{
    public function __construct(
        public string $dataUrl,
        public string $mimeType,
        public string $targetLocale,
        public string $findingType,
        public ?string $currentAlt = null,
        public ?string $qualityReason = null,
        public ?string $pageTitle = null,
        public ?string $contentTitle = null,
        public ?string $caption = null,
        public ?bool $isLinked = null,
        public ?string $linkPurpose = null,
    ) {}

    /** @return array<string, string|bool> */
    public function contextPayload(): array
    {
        $payload = [
            'target_locale' => $this->targetLocale,
            'finding_type' => $this->findingType,
        ];

        foreach ([
            'current_alt' => $this->currentAlt,
            'quality_reason' => $this->qualityReason,
            'page_title' => $this->pageTitle,
            'content_title' => $this->contentTitle,
            'caption' => $this->caption,
            'link_purpose' => $this->linkPurpose,
        ] as $key => $value) {
            if (is_string($value) && $value !== '') {
                $payload[$key] = $value;
            }
        }

        if ($this->isLinked === true) {
            $payload['is_linked'] = true;
        }

        return $payload;
    }
}
