<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Service\PhraseMatcher;

final class LinkTextDuplicateDifferentTargetsRule extends AbstractRteRule
{
    public function getRuleId(): string
    {
        return 'rte.link_text_duplicate_different_targets';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Same link text is used for different destinations.';
    }

    public function getHint(): string
    {
        return 'Use unique link text that describes each destination, or add context via aria-label or surrounding text.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $dom = $this->loadDom($context->content);
        $linksByText = [];

        foreach ($dom->getElementsByTagName('a') as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }

            $text = PhraseMatcher::normalize($link->textContent);
            if ($text === '' || $this->isGenericLinkText($text)) {
                continue;
            }

            $href = $this->normalizeHref($link->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }

            $linksByText[$text][] = [
                'href' => $href,
                'element' => $link,
            ];
        }

        $violations = [];
        foreach ($linksByText as $text => $items) {
            $uniqueHrefs = array_values(array_unique(array_map(
                static fn(array $item): string => $item['href'],
                $items
            )));

            if (count($uniqueHrefs) <= 1) {
                continue;
            }

            $reportedHrefs = [];
            foreach ($items as $item) {
                $href = $item['href'];
                if (isset($reportedHrefs[$href])) {
                    continue;
                }
                $reportedHrefs[$href] = true;

                $link = $item['element'];
                if (!$link instanceof \DOMElement) {
                    continue;
                }

                $violations[] = new RuleViolation(
                    ruleId: $this->getRuleId(),
                    severity: $this->getDefaultSeverity(),
                    message: sprintf('Link text "%s" is used for multiple different destinations.', $this->truncate($text, 80)),
                    hint: $this->getHint(),
                    contextSnippet: $this->elementSnippet($link),
                    contextPath: $this->buildXPath($link),
                );
            }
        }

        return $violations;
    }

    private function normalizeHref(string $href): string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '') {
            return '';
        }

        $parts = parse_url($href);
        if (!is_array($parts)) {
            return mb_strtolower($href);
        }

        $scheme = isset($parts['scheme']) ? mb_strtolower((string)$parts['scheme']) . '://' : '';
        $host = isset($parts['host']) ? mb_strtolower((string)$parts['host']) : '';
        $path = (string)($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?' . (string)$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . (string)$parts['fragment'] : '';

        return $scheme . $host . $path . $query . $fragment;
    }

    private function isGenericLinkText(string $text): bool
    {
        return in_array($text, [
            'click',
            'click here',
            'here',
            'go here',
            'more',
            'read more',
            'learn more',
            'see more',
            'find out more',
            'details',
            'more details',
            'download',
            'open',
            'continue',
            'view',
        ], true);
    }
}
