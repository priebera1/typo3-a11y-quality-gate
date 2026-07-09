<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Dto;

final readonly class AiIframeTitleSuggestionContext
{
    public function __construct(
        public int $findingId,
        public string $siteIdentifier,
        public int $pageUid,
        public int $languageUid,
        public string $ruleId,
        public string $iframeSrc,
        public string $contextPath,
        public string $cssSelector,
        public string $frontendUrl,
        public string $targetLocale,
        public string $pageTitle = '',
    ) {}

    /** @return array<string,string|int> */
    public function payload(): array
    {
        $payload = [
            'rule_id' => $this->ruleId,
            'target_locale' => $this->targetLocale,
            'iframe_src' => $this->iframeSrc,
            'context_path' => $this->contextPath,
            'css_selector' => $this->cssSelector,
            'frontend_url' => $this->frontendUrl,
        ];

        if ($this->pageTitle !== '') {
            $payload['page_title'] = $this->pageTitle;
        }

        return $payload;
    }
}
