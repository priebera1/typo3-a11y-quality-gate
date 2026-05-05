<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class EmptyHeadingRule extends AbstractRteRule
{
    public function getRuleId(): string
    {
        return 'rte.empty_heading';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Critical;
    }

    public function getMessage(): string
    {
        return 'Heading element has no visible text.';
    }

    public function getHint(): string
    {
        return 'Add meaningful text to this heading, or remove the empty heading element.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $dom = $this->loadDom($context->content);
        $xpath = $this->createXPath($dom);
        $headings = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');

        if ($headings === false) {
            return [];
        }

        foreach ($headings as $heading) {
            if (!$heading instanceof \DOMElement) {
                continue;
            }

            if ($this->hasAccessibleContent($heading, $xpath)) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: sprintf('<%s> element has no visible text.', $heading->tagName),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($heading),
                contextPath: $this->buildXPath($heading),
            );
        }

        return $violations;
    }

    private function hasAccessibleContent(\DOMElement $element, \DOMXPath $xpath): bool
    {
        if ($this->hasNonEmptyAttribute($element, 'aria-label')) {
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

                if ($this->normalizedText($image->getAttribute('alt')) !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
