<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered\Rule;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;

final class SvgMissingAccessibleNameRule extends AbstractRenderedHtmlRule
{
    public function getRuleId(): string { return 'rendered.svg_missing_accessible_name'; }
    public function getDefaultSeverity(): Severity { return Severity::Warning; }

    public function evaluate(RenderedHtmlContext $context): iterable
    {
        foreach ($context->document->getElementsByTagName('svg') as $svg) {
            if (!$svg instanceof \DOMElement || $this->isInsideTemplate($svg)) { continue; }
            if ($this->isAriaHidden($svg) || $this->isPresentation($svg) || $this->isSpriteDefinitionContainer($svg)) { continue; }
            if ($this->hasNamedInteractiveAncestor($svg, $context->xpath) && !$this->hasExplicitImageRole($svg)) { continue; }
            if ($this->hasMeaningfulInlineIconContext($svg) && !$this->hasExplicitImageRole($svg)) { continue; }
            if (trim($svg->getAttribute('aria-label')) !== '' || $this->resolveAriaLabelledByText($svg, $context->xpath) !== '' || $this->hasDirectTitle($svg)) { continue; }

            yield $this->issueFactory->create($context, $svg, $this->getRuleId(), $this->getDefaultSeverity(), 'Rendered inline SVG has no accessible name.', 'Add aria-hidden="true" for decorative SVGs, or add a <title>, aria-label, or valid aria-labelledby for meaningful SVG graphics.');
        }
    }


    private function isSpriteDefinitionContainer(\DOMElement $svg): bool
    {
        $elementChildren = [];
        foreach ($svg->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $elementChildren[] = strtolower($child->tagName);
            }
        }

        if ($elementChildren === []) {
            return false;
        }

        $definitionTags = ['symbol', 'defs', 'clippath', 'mask', 'lineargradient', 'radialgradient', 'title', 'desc'];
        foreach ($elementChildren as $tagName) {
            if (!in_array($tagName, $definitionTags, true)) {
                return false;
            }
        }

        return true;
    }

    private function hasNamedInteractiveAncestor(\DOMElement $svg, \DOMXPath $xpath): bool
    {
        $node = $svg->parentNode;
        while ($node instanceof \DOMElement) {
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['a', 'button'], true)) {
                return $this->hasAccessibleName($node, $xpath);
            }

            $node = $node->parentNode;
        }

        return false;
    }

    private function isPresentation(\DOMElement $svg): bool
    {
        $role = strtolower(trim($svg->getAttribute('role')));
        return in_array($role, ['presentation', 'none'], true);
    }

    private function hasExplicitImageRole(\DOMElement $svg): bool
    {
        return strtolower(trim($svg->getAttribute('role'))) === 'img';
    }

    private function hasMeaningfulInlineIconContext(\DOMElement $svg): bool
    {
        $parent = $svg->parentNode;
        if (!$parent instanceof \DOMElement) { return false; }
        $tag = strtolower($parent->tagName);
        if (!in_array($tag, ['a', 'button', 'span'], true)) { return false; }
        $text = $this->normalizedText($parent->textContent);
        return $text !== '';
    }
}
