<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

use Priebera\A11yQualityGate\Ai\Editor\EditorAiRuleCatalog;
use Priebera\A11yQualityGate\Ai\Editor\EditorFindingTarget;
use Priebera\A11yQualityGate\Rule\Rte\LiveValidationHtml;
use Priebera\A11yQualityGate\Rule\Rte\RteDomLoader;

/**
 * Direct reviewed apply of a rich-text AI fix (AQG 3.0.0): the stored field value with exactly one element changed.
 *
 * The change is made in the stored string itself — one attribute of the element's start tag, or the plain text inside
 * the element — and every other byte of the field stays as it is (no re-serialization, no reformatting of the rest).
 * The result is then proven: parsed again, it must be exactly the document AQG's editor flow would produce for this
 * one change (changedHtml()), so a miscounted element, markup the parser repairs or anything else unexpected refuses
 * the write. A refused write is never an error for the user: the change is applied in the editor instead.
 *
 * Only plain text values are inserted, always HTML-escaped. A text change is only made where the element holds no
 * markup of its own (an icon or a span inside a link is never removed).
 */
final class RichTextElementWriter
{
    public const OK = '';
    public const ENCODED_SOURCE = 'encoded_source';
    public const ELEMENT_NOT_FOUND = 'element_not_found';
    public const NESTED_MARKUP = 'nested_markup';
    public const UNSUPPORTED = 'unsupported_change';
    public const NOT_PROVEN = 'not_proven';

    /**
     * @return array{html: string, reason: string} reason '' (OK) with the new field value, else the refusal code
     */
    public static function apply(string $stored, EditorFindingTarget $target, string $value): array
    {
        $normalized = LiveValidationHtml::normalize($stored);
        if ($normalized['decoded']) {
            return ['html' => '', 'reason' => self::ENCODED_SOURCE];
        }
        $value = trim($value);
        $tag = strtolower($target->tag);
        if ($value === '' || preg_match('/^[a-z][a-z0-9]{0,10}$/', $tag) !== 1 || $target->occurrence < 0
            || !in_array($target->operation, [EditorAiRuleCatalog::OPERATION_ATTRIBUTE, EditorAiRuleCatalog::OPERATION_TEXT], true)
            || ($target->operation === EditorAiRuleCatalog::OPERATION_ATTRIBUTE && preg_match('/^[a-z][a-z0-9-]{0,40}$/', $target->attribute) !== 1)) {
            return ['html' => '', 'reason' => self::UNSUPPORTED];
        }

        $start = self::startTag($stored, $tag, $target->occurrence);
        if ($start === null) {
            return ['html' => '', 'reason' => self::ELEMENT_NOT_FOUND];
        }
        [$offset, $length] = $start;
        if ($target->operation === EditorAiRuleCatalog::OPERATION_ATTRIBUTE) {
            $changed = substr($stored, 0, $offset) . self::withAttribute(substr($stored, $offset, $length), $target->attribute, $value) . substr($stored, $offset + $length);
        } else {
            $inner = self::innerRange($stored, $tag, $offset + $length);
            if ($inner === null) {
                return ['html' => '', 'reason' => self::ELEMENT_NOT_FOUND];
            }
            [$innerOffset, $innerLength] = $inner;
            if (str_contains(substr($stored, $innerOffset, $innerLength), '<')) {
                return ['html' => '', 'reason' => self::NESTED_MARKUP];
            }
            $changed = substr($stored, 0, $innerOffset) . htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . substr($stored, $innerOffset + $innerLength);
        }

        // The proof: the stored string with this one splice is the document the change is meant to produce.
        $intended = self::changedHtml($normalized['html'], $target, $value);
        $after = LiveValidationHtml::normalize($changed);
        // The document must change too: a splice the comparison cannot see (inside a comment) proves nothing.
        if ($intended === null || $after['decoded'] || self::serialized($after['html']) !== $intended || $changed === $stored
            || $intended === self::serialized($normalized['html'])) {
            return ['html' => '', 'reason' => self::NOT_PROVEN];
        }

        return ['html' => $changed, 'reason' => self::OK];
    }

    /**
     * The field HTML with the value applied to the target element, re-serialized (the editor flow's document and the
     * basis of the proof), or null when the element cannot be found.
     */
    public static function changedHtml(string $html, EditorFindingTarget $target, string $value): ?string
    {
        $dom = RteDomLoader::load($html);
        $element = $dom->getElementsByTagName($target->tag)->item($target->occurrence);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$element instanceof \DOMElement || !$body instanceof \DOMElement) {
            return null;
        }
        if ($target->operation === EditorAiRuleCatalog::OPERATION_ATTRIBUTE) {
            $element->setAttribute($target->attribute, $value);
        } else {
            while ($element->firstChild !== null) {
                $element->removeChild($element->firstChild);
            }
            $element->appendChild($dom->createTextNode($value));
        }

        return self::serializedBody($dom, $body);
    }

    /** The field HTML as the DOM parser reads it, serialized like changedHtml(). */
    public static function serialized(string $html): string
    {
        $dom = RteDomLoader::load($html);
        $body = $dom->getElementsByTagName('body')->item(0);

        return $body instanceof \DOMElement ? self::serializedBody($dom, $body) : '';
    }

    private static function serializedBody(\DOMDocument $dom, \DOMElement $body): string
    {
        $html = '';
        foreach ($body->childNodes as $child) {
            $html .= (string)$dom->saveHTML($child);
        }

        return $html;
    }

    /**
     * Offset and length of the n-th start tag of $tag in document order; comments are skipped as the parser skips
     * them. Null when there are fewer.
     *
     * @return array{0:int,1:int}|null
     */
    private static function startTag(string $html, string $tag, int $occurrence): ?array
    {
        $count = 0;
        foreach (self::tokens($html, $tag) as [$kind, $offset, $length]) {
            if ($kind !== 'start') {
                continue;
            }
            if ($count === $occurrence) {
                return [$offset, $length];
            }
            $count++;
        }

        return null;
    }

    /**
     * The content between a start tag (ending at $from) and its matching end tag. Null without one.
     *
     * @return array{0:int,1:int}|null
     */
    private static function innerRange(string $html, string $tag, int $from): ?array
    {
        $depth = 0;
        foreach (self::tokens($html, $tag, $from) as [$kind, $offset, $length]) {
            if ($kind === 'start') {
                $depth++;
            } elseif ($kind === 'end') {
                if ($depth === 0) {
                    return [$from, $offset - $from];
                }
                $depth--;
            }
        }

        return null;
    }

    /**
     * Comments, start tags and end tags of $tag from $from on: [kind, offset, length]. Attribute values may contain
     * ">" and are skipped as quoted strings. A self-closing start tag ("<iframe ... />") is reported as "start" too.
     *
     * @return \Generator<int, array{0:string,1:int,2:int}>
     */
    private static function tokens(string $html, string $tag, int $from = 0): \Generator
    {
        $pattern = '~<!--.*?-->|<(/?)' . preg_quote($tag, '~') . '(?=[\s/>])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~is';
        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $from) === false) {
            return;
        }
        foreach ($matches as $match) {
            [$text, $offset] = $match[0];
            if (str_starts_with($text, '<!--')) {
                yield ['comment', $offset, strlen($text)];
                continue;
            }
            yield [($match[1][0] ?? '') === '/' ? 'end' : 'start', $offset, strlen($text)];
        }
    }

    /** The start tag with the attribute set to the escaped value (replaced where present, else appended). */
    private static function withAttribute(string $startTag, string $attribute, string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $replacement = ' ' . $attribute . '="' . $escaped . '"';
        $attributePattern = '~\s' . preg_quote($attribute, '~') . '(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?(?=[\s/>])~i';
        $replaced = preg_replace($attributePattern, $replacement, $startTag, 1, $count);
        if (is_string($replaced) && $count === 1) {
            return $replaced;
        }
        $end = str_ends_with($startTag, '/>') ? strlen($startTag) - 2 : strlen($startTag) - 1;
        $head = rtrim(substr($startTag, 0, $end));

        return $head . $replacement . substr($startTag, $end);
    }
}
