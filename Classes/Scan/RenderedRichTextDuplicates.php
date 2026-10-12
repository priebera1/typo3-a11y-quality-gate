<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scan;

use Priebera\A11yQualityGate\Rule\RuleViolation;

/**
 * Audit C6: the rendered check and the rich-text rules can report the same element of a content element twice, and
 * both counted. A rendered finding is that element's duplicate only when every one of these holds:
 *
 * - it is mapped to the same record (table + uid) as a rich-text finding of this scan,
 * - its rule is the rendered equivalent of the rich-text rule (same element type, same defect),
 * - the snippets are equal once entities and whitespace are normalised,
 * - the rich-text element path is the tail of the rendered DOM path.
 *
 * Template markup, page-level rules, a rewritten URL or another position stay findings of their own. Each rich-text
 * finding stands for one element, so it absorbs at most one rendered finding.
 */
final class RenderedRichTextDuplicates
{
    private const EQUIVALENT_RULES = [
        'rendered.empty_heading' => ['rte.empty_heading'],
        'rendered.empty_link' => ['rte.empty_link'],
        'rendered.img_missing_alt' => ['rte.img_alt_missing', 'rte.image_in_link_missing_alt'],
        'rendered.iframe_missing_title' => ['rte.iframe_missing_title'],
        'rendered.form_control_missing_label' => ['rte.form_control_missing_label'],
        'rendered.duplicate_id' => ['rte.duplicate_id'],
        'rendered.table_missing_header' => ['rte.table_missing_header'],
        'rendered.empty_button' => ['rte.button_label_missing'],
        'rendered.svg_missing_accessible_name' => ['rte.svg_missing_title'],
    ];

    /**
     * @return array{ruleId: string, table: string, uid: int, snippet: string, path: string}
     */
    public static function richTextFinding(RuleViolation $violation, string $sourceTable, int $sourceUid): array
    {
        return [
            'ruleId' => $violation->ruleId,
            'table' => $violation->sourceTable !== '' ? $violation->sourceTable : $sourceTable,
            'uid' => $violation->sourceUid > 0 ? $violation->sourceUid : $sourceUid,
            'snippet' => $violation->contextSnippet,
            'path' => $violation->contextPath,
        ];
    }

    /**
     * @param iterable<RuleViolation> $renderedViolations
     * @param list<array{ruleId: string, table: string, uid: int, snippet: string, path: string}> $richTextFindings
     * @return array{0: list<RuleViolation>, 1: list<RuleViolation>} kept findings, duplicates of a rich-text finding
     */
    public static function split(iterable $renderedViolations, array $richTextFindings): array
    {
        $available = array_map(static fn (array $finding): array => [
            'ruleId' => $finding['ruleId'],
            'record' => $finding['table'] . ':' . $finding['uid'],
            'snippet' => self::normalizeSnippet($finding['snippet']),
            'path' => self::pathSegments($finding['path']),
        ], $richTextFindings);

        $kept = [];
        $duplicates = [];
        foreach ($renderedViolations as $violation) {
            $match = self::findPartner($violation, $available);
            if ($match === null) {
                $kept[] = $violation;
                continue;
            }
            unset($available[$match]);
            $duplicates[] = $violation;
        }

        return [$kept, $duplicates];
    }

    /**
     * @param array<int, array{ruleId: string, record: string, snippet: string, path: list<string>}> $available
     */
    private static function findPartner(RuleViolation $violation, array $available): ?int
    {
        $equivalents = self::EQUIVALENT_RULES[$violation->ruleId] ?? [];
        if ($equivalents === [] || $violation->sourceTable === '' || $violation->sourceUid <= 0) {
            return null;
        }

        $record = $violation->sourceTable . ':' . $violation->sourceUid;
        $snippet = self::normalizeSnippet($violation->contextSnippet);
        $path = self::pathSegments($violation->contextPath);
        foreach ($available as $index => $finding) {
            if ($finding['record'] !== $record
                || !in_array($finding['ruleId'], $equivalents, true)
                || $finding['snippet'] === ''
                || $finding['snippet'] !== $snippet
                || $finding['path'] === []
                || count($finding['path']) > count($path)
                || array_slice($path, -count($finding['path'])) !== $finding['path']
            ) {
                continue;
            }

            return $index;
        }

        return null;
    }

    private static function normalizeSnippet(string $snippet): string
    {
        $decoded = html_entity_decode($snippet, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = str_replace("\u{00A0}", ' ', $decoded);

        return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $decoded)));
    }

    /**
     * @return list<string>
     */
    private static function pathSegments(string $path): array
    {
        return array_values(array_filter(
            array_map(static fn (string $segment): string => strtolower(trim($segment)), explode('>', $path)),
            static fn (string $segment): bool => $segment !== ''
        ));
    }
}
