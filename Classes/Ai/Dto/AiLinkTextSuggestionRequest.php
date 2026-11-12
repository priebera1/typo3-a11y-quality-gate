<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Dto;

final readonly class AiLinkTextSuggestionRequest
{
    public function __construct(
        public string $targetLocale,
        public string $ruleId,
        public string $currentLinkText,
        public string $href,
        public string $surroundingText,
        public string $pageTitle = '',
    ) {}

    /** The same request without the page title, for sites that do not allow sending page titles. */
    public function withoutPageTitle(): self
    {
        return new self(
            $this->targetLocale,
            $this->ruleId,
            $this->currentLinkText,
            $this->href,
            $this->surroundingText,
        );
    }

    /** @return array<string,string> */
    public function contextPayload(): array
    {
        $payload = [
            'target_locale' => $this->targetLocale,
            'rule_id' => $this->ruleId,
            'current_link_text' => $this->currentLinkText,
            'href' => $this->href,
            'surrounding_text' => $this->surroundingText,
        ];

        if ($this->pageTitle !== '') {
            $payload['page_title'] = $this->pageTitle;
        }

        return $payload;
    }
}
