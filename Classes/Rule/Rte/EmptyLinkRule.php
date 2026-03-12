<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class EmptyLinkRule extends AbstractRteRule
{
    public function getRuleId(): string
    {
        return 'rte.empty_link';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Critical;
    }

    public function getMessage(): string
    {
        return 'Link has no accessible name.';
    }

    public function getHint(): string
    {
        return 'Add visible link text or an aria-label attribute describing the destination or action.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $dom = $this->loadDom($context->content);
        $xpath = $this->createXPath($dom);
        $links = $xpath->query('//a[@href]');

        if ($links === false) {
            return [];
        }

        foreach ($links as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }

            if ($this->hasAccessibleName($link, $xpath)) {
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

    private function hasAccessibleName(\DOMElement $element, \DOMXPath $xpath): bool
    {
        if ($this->hasNonEmptyAttribute($element, 'aria-label')) {
            return true;
        }

        if ($element->hasAttribute('aria-labelledby')) {
            return true;
        }

        if ($this->hasNonEmptyAttribute($element, 'title')) {
            return true;
        }

        if ($this->normalizedText($element->textContent) !== '') {
            return true;
        }

        $images = $xpath->query('.//img', $element);
        if ($images !== false) {
            foreach ($images as $image) {
                if (!$image instanceof \DOMElement) {
                    continue;
                }

                if ($image->hasAttribute('alt') && $this->normalizedText($image->getAttribute('alt')) !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
