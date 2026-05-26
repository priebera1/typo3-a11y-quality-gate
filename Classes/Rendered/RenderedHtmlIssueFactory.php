<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class RenderedHtmlIssueFactory
{
    public function __construct(
        private readonly RenderedHtmlIssueMapper $issueMapper,
        private readonly RenderedFrontendUrlSanitizer $frontendUrlSanitizer,
    ) {
    }

    public function create(
        RenderedHtmlContext $context,
        \DOMElement $element,
        string $ruleId,
        Severity $severity,
        string $message,
        string $hint,
        string $contextSnippet = '',
    ): RuleViolation {
        $mapping = $this->issueMapper->mapElement($element);
        $selector = $this->buildCssSelector($element);
        $path = $this->buildPath($element);
        $snippet = $contextSnippet !== '' ? $contextSnippet : $this->elementSnippet($element);
        $hint = $mapping['note'] !== '' ? $hint . ' ' . $mapping['note'] : $hint;

        return new RuleViolation(
            ruleId: $ruleId,
            severity: $severity,
            message: $message,
            hint: $hint,
            contextSnippet: $snippet,
            contextPath: $path,
            sourceType: 'rendered',
            frontendUrl: $this->frontendUrlSanitizer->sanitize($context->url),
            cssSelector: $selector,
            sourceTable: $mapping['sourceTable'],
            sourceUid: $mapping['sourceUid'],
            sourceField: $mapping['sourceField'],
        );
    }

    public function elementSnippet(\DOMElement $element, int $maxLength = 240): string
    {
        $document = new \DOMDocument();
        $document->appendChild($document->importNode($element, true));
        $html = trim($document->saveHTML($document->documentElement) ?: '');

        return mb_substr($html, 0, $maxLength);
    }

    public function buildPath(\DOMElement $element): string
    {
        $parts = [];
        $node = $element;
        while ($node instanceof \DOMElement && strtolower($node->tagName) !== 'html') {
            $tag = strtolower($node->tagName);
            $siblings = [];
            $sibling = $node->parentNode?->firstChild;
            while ($sibling !== null) {
                if ($sibling instanceof \DOMElement && strtolower($sibling->tagName) === $tag) {
                    $siblings[] = $sibling;
                }
                $sibling = $sibling->nextSibling;
            }
            $index = array_search($node, $siblings, true);
            $parts[] = count($siblings) > 1 ? sprintf('%s[%d]', $tag, (int)$index + 1) : $tag;
            $parent = $node->parentNode;
            $node = $parent instanceof \DOMElement ? $parent : null;
        }

        return implode(' > ', array_reverse($parts));
    }

    public function buildCssSelector(\DOMElement $element): string
    {
        $parts = [];
        $node = $element;
        while ($node instanceof \DOMElement && strtolower($node->tagName) !== 'html') {
            $tag = strtolower($node->tagName);
            $id = trim($node->getAttribute('id'));
            if ($id !== '') {
                $parts[] = $tag . '#' . $this->cssEscape($id);
                break;
            }

            $index = 1;
            $sibling = $node->previousSibling;
            while ($sibling !== null) {
                if ($sibling instanceof \DOMElement && strtolower($sibling->tagName) === $tag) {
                    $index++;
                }
                $sibling = $sibling->previousSibling;
            }

            $parts[] = $tag . ':nth-of-type(' . $index . ')';
            $parent = $node->parentNode;
            $node = $parent instanceof \DOMElement ? $parent : null;
        }

        return mb_substr(implode(' > ', array_reverse($parts)), 0, 512);
    }

    private function cssEscape(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '\\\\$0', $value) ?? $value;
    }
}
