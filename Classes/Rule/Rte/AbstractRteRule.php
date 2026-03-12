<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleInterface;

abstract class AbstractRteRule implements RuleInterface
{
    public function supports(CheckContext $context): bool
    {
        return is_string($context->content) && trim($context->content) !== '';
    }

    protected function loadDom(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        $flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING;
        $dom->loadHTML('<html><body>' . $html . '</body></html>', $flags);

        return $dom;
    }

    protected function createXPath(\DOMDocument $dom): \DOMXPath
    {
        return new \DOMXPath($dom);
    }

    protected function elementSnippet(\DOMElement $element, int $maxLength = 200): string
    {
        $document = new \DOMDocument();
        $document->appendChild($document->importNode($element, true));

        $html = $document->saveHTML($document->documentElement) ?: '';
        $html = (string)preg_replace('~^<html><body>(.*)</body></html>$~si', '$1', trim($html));

        return mb_substr(trim($html), 0, $maxLength);
    }

    protected function truncate(string $value, int $maxLength = 200): string
    {
        $value = trim(strip_tags($value));

        return mb_strlen($value) <= $maxLength
            ? $value
            : mb_substr($value, 0, $maxLength - 3) . '...';
    }

    protected function buildXPath(\DOMElement $element): string
    {
        $parts = [];
        $node = $element;

        while ($node instanceof \DOMElement && $node->tagName !== 'body') {
            $tag = $node->tagName;
            $siblings = [];
            $sibling = $node->parentNode?->firstChild;

            while ($sibling !== null) {
                if ($sibling instanceof \DOMElement && $sibling->tagName === $tag) {
                    $siblings[] = $sibling;
                }

                $sibling = $sibling->nextSibling;
            }

            $index = array_search($node, $siblings, true);

            $parts[] = count($siblings) > 1
                ? sprintf('%s[%d]', $tag, (int)$index + 1)
                : $tag;

            $node = $node->parentNode;
        }

        return implode(' > ', array_reverse($parts));
    }

    protected function hasNonEmptyAttribute(\DOMElement $element, string $attributeName): bool
    {
        return trim($element->getAttribute($attributeName)) !== '';
    }

    protected function normalizedText(string $value): string
    {
        $value = str_replace("\u{00A0}", ' ', $value);
        $value = (string)preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    protected function hasMeaningfulText(\DOMElement $element): bool
    {
        return $this->normalizedText($element->textContent) !== '';
    }

    protected function findAncestorTable(\DOMElement $element): ?\DOMElement
    {
        $node = $element->parentNode;

        while ($node instanceof \DOMElement) {
            if (strtolower($node->tagName) === 'table') {
                return $node;
            }

            $node = $node->parentNode;
        }

        return null;
    }

    protected function isPresentationTable(\DOMElement $table): bool
    {
        $role = strtolower($this->normalizedText($table->getAttribute('role')));

        return in_array($role, ['presentation', 'none'], true);
    }
}
