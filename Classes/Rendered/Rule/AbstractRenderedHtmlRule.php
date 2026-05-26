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
            $text = $this->normalizedText((string)$nodes->item(0)?->textContent);
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
