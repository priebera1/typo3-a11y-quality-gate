<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Dto;

final readonly class AiLinkTextSuggestionContext
{
    public function __construct(
        public int $findingId,
        public string $siteIdentifier,
        public int $pageUid,
        public int $languageUid,
        public string $ruleId,
        public string $sourceTable,
        public int $sourceUid,
        public string $sourceField,
        public string $currentLinkText,
        public string $href,
        public string $surroundingText,
        public string $targetLocale,
        public string $pageTitle = '',
    ) {}

    /** @return array<string,string|int> */
    public function payload(): array
    {
        $payload = [
            'rule_id' => $this->ruleId,
            'target_locale' => $this->targetLocale,
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
