<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Utility;

/**
 * Display helpers for single occurrences in both page views. They only reshape stored values for reading; nothing here
 * decides what a finding is, where it belongs or whether it is mapped.
 */
final class OccurrencePresentationUtility
{
    private const MAX_SELECTOR_LENGTH = 500;

    /**
     * One line of the offending markup for the occurrence row: entities decoded (the view escapes it again),
     * whitespace collapsed and long markup shortened. The complete snippet stays in the technical details.
     */
    public static function snippetPreview(string $snippet, int $limit = 120): string
    {
        // HTML5 decoding leaves a carriage return reference (&#13;, which DOMDocument writes for RTE line breaks) as text.
        $snippet = (string)preg_replace('/&#(?:0*13|[xX]0*[dD]);/', ' ', $snippet);
        $decoded = html_entity_decode($snippet, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // A non-breaking space reads as a space; collapse it with every other run of whitespace.
        $decoded = str_replace("\u{00A0}", ' ', $decoded);
        $collapsed = trim((string)preg_replace('/\s+/u', ' ', $decoded));
        if ($collapsed === '' || $limit < 2 || mb_strlen($collapsed) <= $limit) {
            return $collapsed;
        }

        return rtrim(mb_substr($collapsed, 0, $limit - 1)) . '…';
    }

    /**
     * The "what" line of a Content occurrence (audit D3). The card carries the plain rule title; an occurrence repeats
     * its stored message only when it says more than that title. Stored messages are English, so other backend
     * languages show the translated title alone.
     */
    public static function occurrenceWhat(string $message, string $ruleTitle, string $languageCode): string
    {
        $message = trim($message);
        if ($message === '' || strtolower(trim($languageCode)) !== 'en') {
            return '';
        }

        $normalize = static fn (string $text): string => strtolower(trim((string)preg_replace(
            ['/^rendered\s+/i', '/[.\s]+$/u', '/\s+/u'],
            ['', '', ' '],
            trim($text)
        )));

        return $normalize($message) === $normalize($ruleTitle) ? '' : $message;
    }

    /**
     * axe reports why a node failed as "Fix any of the following:" / "Fix all of the following:" headings with one
     * indented check per line. Returns those groups so the view can list the checks instead of one run-on paragraph.
     *
     * @return list<array{heading: string, items: list<string>}>
     */
    public static function failureSummaryGroups(string $summary): array
    {
        $groups = [];
        $current = null;

        foreach (preg_split('/\R/u', $summary) ?: [] as $line) {
            $text = trim($line);
            if ($text === '') {
                continue;
            }

            $isIndented = $line !== ltrim($line);
            if (!$isIndented && str_ends_with($text, ':')) {
                if ($current !== null) {
                    $groups[] = $current;
                }
                $current = ['heading' => $text, 'items' => []];
                continue;
            }

            $current ??= ['heading' => '', 'items' => []];
            $current['items'][] = $text;
        }

        if ($current !== null) {
            $groups[] = $current;
        }

        return array_values(array_filter(
            $groups,
            static fn(array $group): bool => $group['heading'] !== '' || $group['items'] !== []
        ));
    }

    /**
     * The selector "Show on page" can look up for a node without a record marker: only a plain top-level axe target.
     * Targets inside iframes or shadow roots (nested lists) cannot be resolved by one querySelector() and return ''.
     */
    public static function highlightSelector(string $targetJson): string
    {
        if (trim($targetJson) === '') {
            return '';
        }

        try {
            $target = json_decode($targetJson, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }

        if (!is_array($target) || count($target) !== 1 || !is_string($target[0] ?? null)) {
            return '';
        }

        $selector = trim($target[0]);
        if ($selector === '' || strlen($selector) > self::MAX_SELECTOR_LENGTH) {
            return '';
        }

        return $selector;
    }

    /**
     * The remediation every node of a rule carries when it is the same for all of them: then it is rule guidance and
     * belongs on the rule once, not under each occurrence. Returns [] when a node has its own guidance.
     *
     * @param list<array<string, mixed>> $nodes nodes with a prepared `nodeRemediation`
     * @return array<string, mixed>
     */
    public static function sharedRemediation(array $nodes): array
    {
        $shared = null;
        foreach ($nodes as $node) {
            $remediation = is_array($node['nodeRemediation'] ?? null) ? $node['nodeRemediation'] : [];
            if ($remediation === []) {
                continue;
            }
            if ($shared === null) {
                $shared = $remediation;
                continue;
            }
            if ($remediation != $shared) {
                return [];
            }
        }

        return $shared ?? [];
    }

    /**
     * What a mapped occurrence offers: "Edit record", a missing permission, or a record that does not exist here
     * (deleted, or a uid from another installation that renders the same host). Only an existing record can lack access.
     */
    public static function recordAccessState(bool $recordExists, bool $canEdit): string
    {
        if (!$recordExists) {
            return 'missing';
        }

        return $canEdit ? 'edit' : 'no-access';
    }
}
