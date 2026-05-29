<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered\Rule;

use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlIssueFactory;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlRuleInterface;

abstract class AbstractRenderedHtmlRule implements RenderedHtmlRuleInterface
{
    public function __construct(
        protected readonly RenderedHtmlIssueFactory $issueFactory,
    ) {
    }

    public function supports(RenderedHtmlContext $context): bool
    {
        return trim($context->html) !== '';
    }

    protected function normalizedText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\u{00A0}", ' ', $value);
        $value = (string)preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    protected function hasMeaningfulText(\DOMElement $element): bool
    {
        return $this->normalizedText($element->textContent) !== '';
    }

    protected function hasAccessibleName(\DOMElement $element, \DOMXPath $xpath): bool
    {
        if ($this->normalizedText($element->textContent) !== '') {
            return true;
        }

        foreach ($element->getElementsByTagName('img') as $img) {
            if ($img instanceof \DOMElement && trim($img->getAttribute('alt')) !== '') {
                return true;
            }
        }

        if (trim($element->getAttribute('aria-label')) !== '') {
            return true;
        }

        if ($this->resolveAriaLabelledByText($element, $xpath) !== '') {
            return true;
        }

        $title = trim($element->getAttribute('title'));
        return $title !== '';
    }

    protected function resolveAriaLabelledByText(\DOMElement $element, \DOMXPath $xpath): string
    {
        $value = trim($element->getAttribute('aria-labelledby'));
        if ($value === '') {
            return '';
        }

        $texts = [];
        $ids = preg_split('/\s+/', $value) ?: [];
        foreach ($ids as $id) {
            $id = trim($id);
            if ($id === '') {
                continue;
            }
            $nodes = $xpath->query('//*[@id=' . $this->xpathLiteral($id) . ']');
            if ($nodes === false || $nodes->length === 0) {
                continue;
            }
            $labelledByElement = $nodes->item(0);
            if (!$labelledByElement instanceof \DOMElement || $this->isInsideTemplate($labelledByElement)) {
                continue;
            }

            $text = $this->normalizedText((string)$labelledByElement->textContent);
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return trim(implode(' ', $texts));
    }

    protected function xpathLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }
        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }
        $parts = array_map(static fn(string $part): string => "'" . $part . "'", explode("'", $value));
        return 'concat(' . implode(', "\'", ', $parts) . ')';
    }

    protected function isAriaHidden(\DOMElement $element): bool
    {
        $node = $element;
        while ($node instanceof \DOMElement) {
            if (strtolower(trim($node->getAttribute('aria-hidden'))) === 'true') {
                return true;
            }
            $parent = $node->parentNode;
            $node = $parent instanceof \DOMElement ? $parent : null;
        }

        return false;
    }

    protected function isInsideTemplate(\DOMElement $element): bool
    {
        $node = $element->parentNode;
        while ($node instanceof \DOMElement) {
            if (strtolower($node->tagName) === 'template') {
                return true;
            }

            $node = $node->parentNode;
        }

        return false;
    }

    protected function isRenderedHidden(\DOMElement $element, bool $includeDisabled = false, bool $includeHiddenClassAllowlist = false): bool
    {
        $node = $element;
        while ($node instanceof \DOMElement) {
            if (strtolower(trim($node->getAttribute('aria-hidden'))) === 'true') {
                return true;
            }

            if ($node->hasAttribute('hidden')) {
                return true;
            }

            if ($includeDisabled && $node === $element && $node->hasAttribute('disabled')) {
                return true;
            }

            $style = strtolower((string)$node->getAttribute('style'));
            if ($style !== '' && (
                preg_match('/(?:^|;)\s*display\s*:\s*none\b/', $style)
                || preg_match('/(?:^|;)\s*visibility\s*:\s*hidden\b/', $style)
            )) {
                return true;
            }

            if ($includeHiddenClassAllowlist && $this->hasHiddenClassAllowlistMatch($node)) {
                return true;
            }

            $parent = $node->parentNode;
            $node = $parent instanceof \DOMElement ? $parent : null;
        }

        return false;
    }

    protected function hasHiddenClassAllowlistMatch(\DOMElement $element): bool
    {
        $classes = preg_split('/\s+/', strtolower(trim($element->getAttribute('class')))) ?: [];
        foreach ($classes as $className) {
            if (in_array($className, ['is-hidden', 'hidden', 'd-none'], true)) {
                return true;
            }
        }

        return false;
    }

    protected function hasDirectTitle(\DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->tagName) === 'title' && $this->normalizedText($child->textContent) !== '') {
                return true;
            }
        }

        return false;
    }
}
