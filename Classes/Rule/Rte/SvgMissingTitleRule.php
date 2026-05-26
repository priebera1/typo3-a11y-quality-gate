<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class SvgMissingTitleRule extends AbstractRteRule
{
    public function getRuleId(): string
    {
        return 'rte.svg_missing_title';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Inline SVG has no accessible label.';
    }

    public function getHint(): string
    {
        return 'Add a <title> element or aria-label to meaningful inline SVG graphics.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $dom = $this->loadDom($context->content);
        $xpath = $this->createXPath($dom);
        $svgNodes = $xpath->query('//*[local-name()="svg"]');

        if ($svgNodes === false) {
            return [];
        }

        foreach ($svgNodes as $svg) {
            if (!$svg instanceof \DOMElement) {
                continue;
            }

            if ($this->isHiddenFromAssistiveTechnology($svg) || $this->hasPresentationRole($svg)) {
                continue;
            }

            if ($this->hasAccessibleName($svg, $xpath)) {
                continue;
            }

            if ($this->isLikelyDecorativeInlineIcon($svg)) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: $this->getMessage(),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($svg),
                contextPath: $this->buildXPath($svg),
            );
        }

        return $violations;
    }

    private function isHiddenFromAssistiveTechnology(\DOMElement $svg): bool
    {
        $node = $svg;
        while ($node instanceof \DOMElement) {
            if (strtolower($this->normalizedText($node->getAttribute('aria-hidden'))) === 'true') {
                return true;
            }
            $node = $node->parentNode;
        }

        return false;
    }

    private function hasPresentationRole(\DOMElement $svg): bool
    {
        return in_array(strtolower($this->normalizedText($svg->getAttribute('role'))), ['presentation', 'none'], true);
    }

    private function hasAccessibleName(\DOMElement $svg, \DOMXPath $xpath): bool
    {
        if ($this->hasNonEmptyAttribute($svg, 'aria-label')) {
            return true;
        }

        if ($this->hasValidAriaLabelledBy($svg, $xpath)) {
            return true;
        }

        $titles = $xpath->query('./*[local-name()="title"]', $svg);
        if ($titles === false) {
            return false;
        }

        foreach ($titles as $title) {
            if ($title instanceof \DOMElement && $this->normalizedText($title->textContent) !== '') {
                return true;
            }
        }

        return false;
    }

    private function isLikelyDecorativeInlineIcon(\DOMElement $svg): bool
    {
        $role = strtolower($this->normalizedText($svg->getAttribute('role')));
        if ($role === 'img') {
            return false;
        }

        $parent = $svg->parentNode;
        if (!$parent instanceof \DOMElement) {
            return false;
        }

        $parentText = $this->normalizedText($parent->textContent);
        if ($parentText === '') {
            return false;
        }

        return in_array(strtolower($parent->tagName), ['a', 'button', 'span'], true);
    }
}
