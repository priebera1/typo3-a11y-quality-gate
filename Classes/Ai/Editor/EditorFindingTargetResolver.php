<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Editor;

use Priebera\A11yQualityGate\Rule\Rte\RteDomLoader;
use Priebera\A11yQualityGate\Rule\RuleViolation;

/**
 * Finds the element a live RTE finding is about, in the same parse the rule used, and describes it so an editor can
 * prove it found the same element (see EditorFindingTarget). The rule's structural path ("p[2] > a") is unique in
 * that parse; its snippet must match the element's serialization, otherwise the finding is not targeted at all.
 */
final class EditorFindingTargetResolver
{
    public const MAX_TEXT_LENGTH = 1000;
    public const MAX_ATTRIBUTE_LENGTH = 2000;

    /** The last parse, reused while one live check resolves several findings of the same HTML. */
    private string $parsedHash = '';
    private ?\DOMDocument $parsed = null;

    public function resolve(string $html, RuleViolation $violation): ?EditorFindingTarget
    {
        $entry = EditorAiRuleCatalog::entry($violation->ruleId);
        if ($entry === null || trim($html) === '') {
            return null;
        }

        $dom = $this->parse($html);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            return null;
        }

        // IframeMissingTitleRule reports "iframe[n]" from a raw markup scan (n-th iframe in the source), which is not a
        // structural path: only the markup mapping may resolve it.
        if ($violation->ruleId === 'rte.iframe_missing_title' && preg_match('/^iframe(?:\[\d+\])?$/', trim($violation->contextPath)) === 1) {
            $reported = $this->iframeFromMarkupFallback($dom, $html, $violation->contextPath);
        } else {
            $reported = $this->elementAtPath($body, $violation->contextPath);
            if ($reported !== null && RteDomLoader::elementSnippet($reported) !== $violation->contextSnippet) {
                return null;
            }
        }
        if ($reported === null) {
            return null;
        }

        $element = $reported;
        if ($entry['childTag'] !== '') {
            $children = [];
            foreach ($reported->childNodes as $child) {
                if ($child instanceof \DOMElement && strtolower($child->tagName) === $entry['childTag']) {
                    $children[] = $child;
                }
            }
            if (count($children) !== 1) {
                return null;
            }
            $element = $children[0];
        }

        $tag = strtolower($element->tagName);
        if ($entry['tag'] === 'h*' ? preg_match('/^h[1-6]$/', $tag) !== 1 : $tag !== ($entry['childTag'] !== '' ? $entry['childTag'] : $entry['tag'])) {
            return null;
        }

        $occurrence = -1;
        $all = $dom->getElementsByTagName($tag);
        foreach ($all as $index => $candidate) {
            if ($candidate === $element) {
                $occurrence = (int)$index;
                break;
            }
        }
        if ($occurrence < 0) {
            return null;
        }

        $attributes = [];
        foreach ($element->attributes ?? [] as $attribute) {
            if ($attribute instanceof \DOMAttr) {
                $attributes[strtolower($attribute->name)] = (string)$attribute->value;
            }
        }
        $hasChildElements = false;
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $hasChildElements = true;
                break;
            }
        }
        $text = self::normalizeText($element->textContent);
        $currentValue = match ($entry['operation']) {
            EditorAiRuleCatalog::OPERATION_ATTRIBUTE => (string)($attributes[$entry['attribute']] ?? ''),
            default => $text,
        };

        $headingLevel = preg_match('/^h([1-6])$/', $tag, $match) === 1 ? (int)$match[1] : 0;
        $targetHeadingLevel = $entry['operation'] === EditorAiRuleCatalog::OPERATION_HEADING_LEVEL
            ? $this->expectedHeadingLevel($dom, $element)
            : 0;

        return new EditorFindingTarget(
            ruleId: $violation->ruleId,
            kind: $entry['kind'],
            operation: $entry['operation'],
            attribute: $entry['attribute'],
            tag: $tag,
            occurrence: $occurrence,
            total: $all->length,
            contextPath: $violation->contextPath,
            attributes: $attributes,
            text: mb_substr($text, 0, self::MAX_TEXT_LENGTH),
            hasChildElements: $hasChildElements,
            currentValue: $currentValue,
            headingLevel: $headingLevel,
            targetHeadingLevel: $targetHeadingLevel,
            unavailableReason: $this->unavailableReason($entry, $attributes, $text, $hasChildElements, $targetHeadingLevel),
            element: $element,
        );
    }

    private function parse(string $html): \DOMDocument
    {
        $hash = hash('sha256', $html);
        if ($this->parsed === null || $this->parsedHash !== $hash) {
            $this->parsed = RteDomLoader::load($html);
            $this->parsedHash = $hash;
        }

        return $this->parsed;
    }

    /** Whitespace as both the PHP and the JavaScript side compare it. */
    public static function normalizeText(string $value): string
    {
        // The same whitespace as JavaScript's \s (Unicode spaces such as U+202F and the BOM included).
        return trim((string)preg_replace('/[\s\p{Z}\x{FEFF}]+/u', ' ', $value), ' ');
    }

    /**
     * @param array{kind:string,tag:string,operation:string,attribute:string,childTag:string} $entry
     * @param array<string,string> $attributes
     */
    private function unavailableReason(array $entry, array $attributes, string $text, bool $hasChildElements, int $targetHeadingLevel): string
    {
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            return 'too_long';
        }
        foreach ($attributes as $value) {
            if (mb_strlen($value) > self::MAX_ATTRIBUTE_LENGTH) {
                return 'too_long';
            }
            // Fluid or other template placeholders: what visitors get is not this source.
            if (preg_match('/\{[^}]*\}/', $value) === 1) {
                return 'template_placeholder';
            }
        }
        if (preg_match('/\{[^}]*\}/', $text) === 1) {
            return 'template_placeholder';
        }
        if ($entry['operation'] === EditorAiRuleCatalog::OPERATION_TEXT && $hasChildElements) {
            // Replacing the text would drop the inner markup (formatting, icons); the editor fixes it by hand.
            return 'nested_markup';
        }
        if ($entry['operation'] === EditorAiRuleCatalog::OPERATION_HEADING_LEVEL && $targetHeadingLevel === 0) {
            return 'no_rule_based_fix';
        }

        return '';
    }

    /** Resolves an AbstractRteRule::buildXPath() path ("p[2] > a") from <body>; null if any step is not unique. */
    private function elementAtPath(\DOMElement $body, string $path): ?\DOMElement
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $current = $body;
        foreach (explode(' > ', $path) as $segment) {
            if (preg_match('/^([a-z][a-z0-9:-]*)(?:\[(\d+)\])?$/i', trim($segment), $match) !== 1) {
                return null;
            }
            $tag = $match[1];
            $index = isset($match[2]) ? (int)$match[2] : 0;
            $siblings = [];
            foreach ($current->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->tagName === $tag) {
                    $siblings[] = $child;
                }
            }
            if ($index === 0) {
                // buildXPath() omits the index only for a single element of that tag among its siblings.
                if (count($siblings) !== 1) {
                    return null;
                }
                $current = $siblings[0];
                continue;
            }
            if (count($siblings) < 2 || !isset($siblings[$index - 1])) {
                return null;
            }
            $current = $siblings[$index - 1];
        }

        return $current === $body ? null : $current;
    }

    /**
     * IframeMissingTitleRule reports through a raw-markup scan first ("iframe[2]" = second <iframe> tag in the
     * source). It is only used when that scan and the parse see the same number of iframes.
     */
    private function iframeFromMarkupFallback(\DOMDocument $dom, string $html, string $path): ?\DOMElement
    {
        if (preg_match('/^iframe(?:\[(\d+)\])?$/', trim($path), $match) !== 1) {
            return null;
        }
        preg_match_all('~<iframe\b[^>]*(?:>.*?</iframe\s*>|/?>)~is', $html, $matches);
        $markupCount = count($matches[0] ?? []);
        $iframes = $dom->getElementsByTagName('iframe');
        if ($markupCount === 0 || $markupCount !== $iframes->length) {
            return null;
        }
        $index = isset($match[1]) ? (int)$match[1] : 1;
        if (!isset($match[1]) && $markupCount !== 1) {
            return null;
        }
        $element = $iframes->item($index - 1);

        return $element instanceof \DOMElement && trim($element->getAttribute('title')) === '' ? $element : null;
    }

    /** The level the heading should have: one below the previous heading (HeadingHierarchyRule). */
    private function expectedHeadingLevel(\DOMDocument $dom, \DOMElement $heading): int
    {
        $xpath = new \DOMXPath($dom);
        $previous = 0;
        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') ?: [] as $node) {
            if ($node === $heading) {
                $level = (int)substr(strtolower($heading->tagName), 1);
                return $previous > 0 && $level > $previous + 1 ? $previous + 1 : 0;
            }
            if ($node instanceof \DOMElement) {
                $previous = (int)substr(strtolower($node->tagName), 1);
            }
        }

        return 0;
    }
}
