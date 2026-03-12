<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class LinkNewWindowNoWarningRule extends AbstractRteRule
{
    /**
     * @param string[] $hintPhrases
     */
    public function __construct(
        private readonly array $hintPhrases = [],
    ) {
    }

    public function getRuleId(): string
    {
        return 'rte.link_new_window_no_warning';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Link opens in a new window without warning the user.';
    }

    public function getHint(): string
    {
        return 'Add a visible or accessible hint that the link opens in a new window.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $dom = $this->loadDom($context->content);
        $xpath = $this->createXPath($dom);
        $links = $xpath->query('//a[@target="_blank"]');

        if ($links === false) {
            return [];
        }

        foreach ($links as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }

            if ($this->hasWindowWarning($link, $xpath)) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: $this->getMessage(),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($link),
                contextPath: $this->buildXPath($link),
            );
        }

        return $violations;
    }

    private function hasWindowWarning(\DOMElement $link, \DOMXPath $xpath): bool
    {
        if ($this->containsHint($link->getAttribute('aria-label'))) {
            return true;
        }

        if ($this->containsHint($link->getAttribute('title'))) {
            return true;
        }

        if ($this->containsHint($link->textContent)) {
            return true;
        }

        $childrenWithAriaLabel = $xpath->query('.//*[@aria-label]', $link);
        if ($childrenWithAriaLabel !== false) {
            foreach ($childrenWithAriaLabel as $child) {
                if (!$child instanceof \DOMElement) {
                    continue;
                }

                if ($this->containsHint($child->getAttribute('aria-label'))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function containsHint(string $text): bool
    {
        $normalizedText = mb_strtolower($this->normalizedText($text));
        if ($normalizedText === '') {
            return false;
        }

        foreach ($this->hintPhrases as $phrase) {
            $normalizedPhrase = mb_strtolower($this->normalizedText($phrase));

            if ($normalizedPhrase !== '' && str_contains($normalizedText, $normalizedPhrase)) {
                return true;
            }
        }

        return false;
    }
}
