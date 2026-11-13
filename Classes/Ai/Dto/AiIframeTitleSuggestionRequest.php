<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Dto;

final readonly class AiIframeTitleSuggestionRequest
{
    public function __construct(
        public string $targetLocale,
        public string $ruleId,
        public string $iframeSrc,
        public string $contextPath,
        public string $cssSelector,
        public string $frontendUrl,
        public string $pageTitle = '',
    ) {}

    /** The same request without the page title, for sites that do not allow sending page titles. */
    public function withoutPageTitle(): self
    {
        return new self(
            $this->targetLocale,
            $this->ruleId,
            $this->iframeSrc,
            $this->contextPath,
            $this->cssSelector,
            $this->frontendUrl,
        );
    }

    /** @return array<string,string> */
    public function contextPayload(): array
    {
        $payload = [
            'target_locale' => $this->targetLocale,
            'rule_id' => $this->ruleId,
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
