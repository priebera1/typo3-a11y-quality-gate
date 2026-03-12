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

            $role = strtolower($this->normalizedText($svg->getAttribute('role')));
            if (in_array($role, ['presentation', 'none'], true)) {
                continue;
            }

            if ($this->hasNonEmptyAttribute($svg, 'aria-label')) {
                continue;
            }

            if ($svg->hasAttribute('aria-labelledby')) {
                continue;
            }

            $titles = $xpath->query('./*[local-name()="title"]', $svg);
            $hasTitle = false;

            if ($titles !== false) {
                foreach ($titles as $title) {
                    if ($title instanceof \DOMElement && $this->normalizedText($title->textContent) !== '') {
                        $hasTitle = true;
                        break;
                    }
                }
            }

            if ($hasTitle) {
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
}
