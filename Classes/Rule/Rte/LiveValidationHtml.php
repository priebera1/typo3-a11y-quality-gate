<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

/**
 * The HTML the live editor check (and the editor AI actions) actually examine: comments removed and, as a fallback,
 * escaped markup decoded. A decoded result no longer corresponds to the editor's source, so it is flagged: editor AI
 * targets are only offered when the checked HTML is the editor content itself.
 */
final class LiveValidationHtml
{
    /** @return array{html:string,decoded:bool} */
    public static function normalize(string $html): array
    {
        $html = (string)preg_replace('/<!--.*?-->/s', '', $html);

        // CKEditor normally sends real HTML through editor.getData(). In some source/editing flows,
        // however, the payload can contain escaped HTML fragments wrapped in normal paragraph tags:
        // <p>&lt;img href="nic" /&gt;</p>. The previous fallback did not decode this because
        // the wrapper <p> counted as a real HTML element, so the RTE rules only saw text.
        if (self::containsEscapedHtmlElement($html) && self::countActionableHtmlElements($html) === 0) {
            $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (self::countActionableHtmlElements($decoded) > 0) {
                return ['html' => $decoded, 'decoded' => true];
            }
        }

        return ['html' => $html, 'decoded' => false];
    }

    private static function containsEscapedHtmlElement(string $html): bool
    {
        return preg_match('/&lt;\s*(a|img|table|thead|tbody|tr|th|td|button|iframe|svg|h[1-6]|ul|ol|li)\b/i', $html) === 1;
    }

    private static function countActionableHtmlElements(string $html): int
    {
        preg_match_all('/<\s*(a|img|table|thead|tbody|tr|th|td|button|iframe|svg|h[1-6]|ul|ol|li)\b/i', $html, $matches);

        return count($matches[0] ?? []);
    }
}
