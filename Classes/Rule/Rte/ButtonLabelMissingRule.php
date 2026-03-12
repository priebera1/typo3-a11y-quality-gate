<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class ButtonLabelMissingRule extends AbstractRteRule
{
    public function getRuleId(): string
    {
        return 'rte.button_label_missing';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Critical;
    }

    public function getMessage(): string
    {
        return 'Button has no accessible name.';
    }

    public function getHint(): string
    {
        return 'Add visible button text or an accessible label such as aria-label.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $dom = $this->loadDom($context->content);
        $xpath = $this->createXPath($dom);
        $buttons = $xpath->query('//button');

        if ($buttons === false) {
            return [];
        }

        foreach ($buttons as $button) {
            if (!$button instanceof \DOMElement) {
                continue;
            }

            if ($this->hasAccessibleName($button, $xpath)) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: $this->getMessage(),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($button),
                contextPath: $this->buildXPath($button),
            );
        }

        return $violations;
    }

    private function hasAccessibleName(\DOMElement $button, \DOMXPath $xpath): bool
    {
        if ($this->hasNonEmptyAttribute($button, 'aria-label')) {
            return true;
        }

        if ($button->hasAttribute('aria-labelledby')) {
            return true;
        }

        if ($this->hasNonEmptyAttribute($button, 'title')) {
            return true;
        }

        if ($this->normalizedText(html_entity_decode($button->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== '') {
            return true;
        }

        $images = $xpath->query('.//img', $button);
        if ($images !== false) {
            foreach ($images as $image) {
                if (!$image instanceof \DOMElement) {
                    continue;
                }

                if ($this->normalizedText(html_entity_decode($image->getAttribute('alt'), ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== '') {
                    return true;
                }
            }
        }

        $svgs = $xpath->query('.//*[local-name()="svg"]', $button);
        if ($svgs !== false) {
            foreach ($svgs as $svg) {
                if (!$svg instanceof \DOMElement) {
                    continue;
                }

                if ($this->hasNonEmptyAttribute($svg, 'aria-label')) {
                    return true;
                }

                $titles = $xpath->query('./*[local-name()="title"]', $svg);
                if ($titles === false) {
                    continue;
                }

                foreach ($titles as $title) {
                    if ($this->normalizedText(html_entity_decode($title->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== '') {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
