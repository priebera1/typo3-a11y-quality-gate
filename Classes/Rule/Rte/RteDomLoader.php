<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

/**
 * The one way AQG parses rich-text and plain HTML fields. RTE rules and the editor AI target resolver use the same
 * parser, so an element a rule reports (by its structural path) is the element the target resolver finds again.
 */
final class RteDomLoader
{
    /**
     * libxml reads HTML without a charset declaration as ISO-8859-1. Non-ASCII characters are handed over as numeric
     * entities, so rich text keeps its characters ("Predvolený", not "PredvolenÃ½") in rules and snippets.
     */
    public static function load(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        $flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING;
        $html = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        $dom->loadHTML('<html><body>' . $html . '</body></html>', $flags);

        return $dom;
    }

    public static function elementSnippet(\DOMElement $element, int $maxLength = 200): string
    {
        $document = new \DOMDocument();
        $document->appendChild($document->importNode($element, true));

        $html = $document->saveHTML($document->documentElement) ?: '';
        $html = (string)preg_replace('~^<html><body>(.*)</body></html>$~si', '$1', trim($html));

        return mb_substr(trim($html), 0, $maxLength);
    }
}
