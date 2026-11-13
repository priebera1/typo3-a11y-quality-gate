<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Media;

/**
 * Reads the text an SVG carries itself (<title>, <desc>, <text>) without AI, so a diagram's labels are known before
 * any request and can be shown as "No AI". The SVG is parsed as inert data: no network access, no external entities,
 * no DTD loading, bounded size and label count. Scripts, links and external references are never followed.
 */
final class SvgTextExtractor
{
    public const MAX_BYTES = 1_000_000;
    private const MAX_LABELS = 40;
    private const MAX_LABEL_LENGTH = 120;

    /** @return list<string> distinct labels in document order */
    public function labels(string $svg): array
    {
        if ($svg === '' || strlen($svg) > self::MAX_BYTES || stripos($svg, '<!ENTITY') !== false) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_COMPACT);
            if (!$loaded || $document->doctype !== null) {
                return [];
            }
            $xpath = new \DOMXPath($document);
            $nodes = $xpath->query('//*[local-name()="title" or local-name()="desc" or local-name()="text"]');
            if (!$nodes instanceof \DOMNodeList) {
                return [];
            }

            $labels = [];
            foreach ($nodes as $node) {
                // A <text> with <tspan> children yields one label for the whole line.
                if ($node->parentNode instanceof \DOMElement && $node->parentNode->localName === 'text') {
                    continue;
                }
                $label = trim((string)preg_replace('/\s+/u', ' ', (string)$node->textContent));
                $label = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]/u', '', $label);
                if ($label === '' || in_array($label, $labels, true)) {
                    continue;
                }
                $labels[] = mb_substr($label, 0, self::MAX_LABEL_LENGTH);
                if (count($labels) >= self::MAX_LABELS) {
                    break;
                }
            }

            return $labels;
        } catch (\Throwable) {
            return [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
