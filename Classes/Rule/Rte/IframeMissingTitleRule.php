<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class IframeMissingTitleRule extends AbstractRteRule
{
    public function getRuleId(): string
    {
        return 'rte.iframe_missing_title';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Critical;
    }

    public function getMessage(): string
    {
        return 'Iframe is missing a title attribute.';
    }

    public function getHint(): string
    {
        return 'Add a meaningful title attribute describing the embedded content.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $html = (string)$context->content;

        // Prefer the raw opening tag for this rule. TYPO3 html CTypes and
        // mixed RTE fragments can contain iframe markup that DOMDocument
        // normalizes differently per fragment. The raw fallback is conservative
        // for this rule and keeps page/subtree scans consistent for obvious
        // <iframe> tags without title attributes.
        $markupViolations = $this->detectMissingTitleIframesByMarkupFallback($html);
        if ($markupViolations !== []) {
            return $markupViolations;
        }

        $violations = [];

        try {
            $dom = $this->loadDom($html);
        } catch (\Throwable) {
            return [];
        }

        foreach ($dom->getElementsByTagName('iframe') as $iframe) {
            if (!$iframe instanceof \DOMElement) {
                continue;
            }

            if ($this->hasNonEmptyAttribute($iframe, 'title')) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: $this->getMessage(),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($iframe),
                contextPath: $this->buildXPath($iframe),
            );
        }

        return $violations;
    }

    /**
     * DOMDocument can occasionally recover malformed or mixed RTE fragments in a
     * way that hides iframe elements. Use a conservative raw-markup fallback so
     * local page and subtree scans keep reporting obvious iframe title issues.
     *
     * @return RuleViolation[]
     */
    private function detectMissingTitleIframesByMarkupFallback(string $html): array
    {
        if (stripos($html, '<iframe') === false) {
            return [];
        }

        preg_match_all('~<iframe\b[^>]*(?:>.*?</iframe\s*>|/?>)~is', $html, $matches);
        if (($matches[0] ?? []) === []) {
            return [];
        }

        $violations = [];
        foreach ($matches[0] as $index => $iframeMarkup) {
            $openingTag = $this->extractOpeningIframeTag((string)$iframeMarkup);
            if ($openingTag === '' || $this->hasNonEmptyTitleAttributeInMarkup($openingTag)) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: $this->getMessage(),
                hint: $this->getHint(),
                contextSnippet: mb_substr(trim((string)$iframeMarkup), 0, 200),
                contextPath: count($matches[0]) > 1 ? sprintf('iframe[%d]', $index + 1) : 'iframe',
            );
        }

        return $violations;
    }

    private function extractOpeningIframeTag(string $iframeMarkup): string
    {
        if (!preg_match('~<iframe\b[^>]*>~is', $iframeMarkup, $match)) {
            return '';
        }

        return (string)($match[0] ?? '');
    }

    private function hasNonEmptyTitleAttributeInMarkup(string $openingTag): bool
    {
        if (!preg_match('~\btitle\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $openingTag, $match)) {
            return false;
        }

        $title = $match[1] ?? $match[2] ?? $match[3] ?? '';

        return trim((string)$title) !== '';
    }
}
