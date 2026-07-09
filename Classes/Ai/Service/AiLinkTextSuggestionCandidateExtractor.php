<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Service\PhraseMatcher;

final class AiLinkTextSuggestionCandidateExtractor
{
    private const CONTEXT_LENGTH = 500;

    private const MAX_HTML_BYTES = 200000;

    private const MAX_LINKS_TO_INSPECT = 200;

    private const MAX_CANDIDATES = 25;

    private const DEFAULT_NON_DESCRIPTIVE_TEXTS = [
        'click',
        'click here',
        'here',
        'read more',
        'more',
        'learn more',
        'show more',
        'see more',
        'details',
        'link',
        'this link',
        'weiterlesen',
        'mehr',
        'hier',
        'viac',
        'čítať viac',
        'zobraziť viac',
        'kliknite sem',
    ];

    /**
     * @param array<string,mixed> $issue
     * @return array{text:string,href:string,surroundingText:string}|null
     */
    public function resolve(string $html, array $issue): ?array
    {
        $ruleId = trim((string)($issue['rule_id'] ?? ''));
        $mode = in_array($ruleId, ['rte.empty_link', 'rendered.empty_link'], true) ? 'empty' : 'non_descriptive';
        if (!$this->isSafeToParse($html)) {
            return null;
        }

        try {
            $candidates = $this->extractCandidates($html, $mode);
        } catch (\Throwable) {
            return null;
        }
        if ($candidates === []) {
            return null;
        }

        $contextPath = trim((string)($issue['context_path'] ?? ''));
        if ($contextPath !== '') {
            $pathMatches = array_values(array_filter(
                $candidates,
                static fn(array $candidate): bool => $candidate['path'] === $contextPath,
            ));
            if (count($pathMatches) === 1) {
                return $this->toResult($pathMatches[0], $html);
            }
        }

        $contextSnippet = trim((string)($issue['context_snippet'] ?? ''));
        if ($contextSnippet !== '') {
            $normalizedContextSnippet = $this->normalizeSnippet($contextSnippet);
            $snippetMatches = array_values(array_filter(
                $candidates,
                fn(array $candidate): bool => $candidate['snippet'] === $contextSnippet
                    || $this->normalizeSnippet($candidate['snippet']) === $normalizedContextSnippet,
            ));
            if (count($snippetMatches) === 1) {
                return $this->toResult($snippetMatches[0], $html);
            }
        }

        if (count($candidates) === 1) {
            return $this->toResult($candidates[0], $html);
        }

        return null;
    }

    private function isSafeToParse(string $html): bool
    {
        if ($html === '') {
            return false;
        }

        if (strlen($html) > self::MAX_HTML_BYTES) {
            return false;
        }

        return substr_count(strtolower($html), '<a') <= self::MAX_LINKS_TO_INSPECT;
    }

    /** @return list<array{text:string,href:string,path:string,snippet:string,plainText:string}> */
    private function extractCandidates(string $html, string $mode): array
    {
        if (!class_exists(\DOMDocument::class)) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET;
        $loaded = $dom->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', $flags);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [];
        }

        $xpath = new \DOMXPath($dom);
        $plainDocumentText = $this->plainDocumentText($dom);
        $candidates = [];
        $inspectedLinks = 0;
        foreach ($dom->getElementsByTagName('a') as $link) {
            ++$inspectedLinks;
            if ($inspectedLinks > self::MAX_LINKS_TO_INSPECT) {
                return [];
            }
            if (!$link instanceof \DOMElement || !$link->hasAttribute('href')) {
                continue;
            }

            $href = $this->sanitizeHref(trim($link->getAttribute('href')));
            if ($href === '') {
                continue;
            }

            $text = $this->visibleLinkText($link, $xpath);
            if ($mode === 'empty') {
                if ($text !== '') {
                    continue;
                }
            } elseif ($text === '' || !$this->isNonDescriptiveText($text)) {
                continue;
            }

            $candidates[] = [
                'text' => $text,
                'href' => $href,
                'path' => $this->buildXPath($link),
                'snippet' => $this->elementSnippet($link),
                'plainText' => $plainDocumentText,
            ];
            if (count($candidates) > self::MAX_CANDIDATES) {
                return [];
            }
        }

        return $candidates;
    }

    /** @param array{text:string,href:string,path:string,snippet:string,plainText:string} $candidate @return array{text:string,href:string,surroundingText:string} */
    private function toResult(array $candidate, string $html): array
    {
        return [
            'text' => $candidate['text'],
            'href' => $candidate['href'],
            'surroundingText' => $this->surroundingText($candidate['plainText'], $candidate['text'], $html),
        ];
    }

    private function visibleLinkText(\DOMElement $link, \DOMXPath $xpath): string
    {
        $ariaLabel = trim($link->getAttribute('aria-label'));
        if ($ariaLabel !== '') {
            return $this->normalizeVisibleText($ariaLabel);
        }

        $labelledBy = trim($link->getAttribute('aria-labelledby'));
        if ($labelledBy !== '') {
            $labels = [];
            foreach (preg_split('/\s+/', $labelledBy) ?: [] as $id) {
                $id = trim($id);
                if ($id === '') {
                    continue;
                }
                $nodes = $xpath->query('//*[@id=' . $this->xpathLiteral($id) . ']');
                if ($nodes === false) {
                    continue;
                }
                foreach ($nodes as $node) {
                    if ($node instanceof \DOMNode) {
                        $labels[] = (string)$node->textContent;
                    }
                }
            }
            $label = $this->normalizeVisibleText(implode(' ', $labels));
            if ($label !== '') {
                return $label;
            }
        }

        $title = trim($link->getAttribute('title'));
        if ($title !== '') {
            return $this->normalizeVisibleText($title);
        }

        $text = $this->normalizeVisibleText((string)$link->textContent);
        if ($text !== '') {
            return $text;
        }

        foreach ($link->getElementsByTagName('img') as $image) {
            if (!$image instanceof \DOMElement || !$image->hasAttribute('alt')) {
                continue;
            }
            $alt = $this->normalizeVisibleText($image->getAttribute('alt'));
            if ($alt !== '') {
                return $alt;
            }
        }

        return '';
    }

    private function normalizeVisibleText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    private function sanitizeHref(string $href): string
    {
        $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $href = trim((string)preg_replace('/\s+/u', ' ', $href));
        if ($href === '' || preg_match('/[\x00-\x1F\x7F<>]/u', $href) === 1) {
            return '';
        }

        if (preg_match('~^(?:javascript|data|vbscript):~i', $href) === 1) {
            return '';
        }

        return mb_substr($href, 0, 500);
    }

    private function isNonDescriptiveText(string $text): bool
    {
        $normalized = PhraseMatcher::normalize($text);
        $phrases = array_map(static fn(string $phrase): string => PhraseMatcher::normalize($phrase), self::DEFAULT_NON_DESCRIPTIVE_TEXTS);

        return in_array($normalized, $phrases, true);
    }

    private function surroundingText(string $plainText, string $linkText, string $fallbackHtml): string
    {
        $plainText = trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($plainText), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($plainText === '') {
            $plainText = trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($fallbackHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }

        if ($plainText === '') {
            return '';
        }

        if ($linkText === '') {
            return mb_substr($plainText, 0, self::CONTEXT_LENGTH);
        }

        $position = mb_stripos($plainText, $linkText);
        if ($position === false) {
            return mb_substr($plainText, 0, self::CONTEXT_LENGTH);
        }

        return trim(mb_substr($plainText, max(0, (int)$position - 240), self::CONTEXT_LENGTH));
    }

    private function plainDocumentText(\DOMDocument $dom): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', (string)$dom->textContent));
    }

    private function elementSnippet(\DOMElement $element): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->appendChild($document->importNode($element, true));
        $html = $document->saveHTML($document->documentElement) ?: '';
        $html = (string)preg_replace('~^<html><body>(.*)</body></html>$~si', '$1', trim($html));

        return mb_substr(trim($html), 0, 200);
    }

    private function normalizeSnippet(string $snippet): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', html_entity_decode($snippet, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function buildXPath(\DOMElement $element): string
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

    private function xpathLiteral(string $value): string
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
}
