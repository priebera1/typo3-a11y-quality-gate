<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp\Intelligence;

/**
 * What a stored local finding proves, and what it does not (agent access, Accessibility Intelligence).
 *
 * Fixed English sentences chosen by the rule and the kind of check, never derived from editor content: an agent quotes
 * them instead of guessing the cause. The concrete step of a finding is a template filled only with numbers parsed
 * from AQG's own stored message and the TCA label of the field — never with snippet, title or other editor text.
 */
final class RuleEvidenceCatalog
{
    private const HEADING_JUMP = 'rte.heading_hierarchy_jump';

    /**
     * @param array<string, mixed> $issue a tx_a11y_issue row
     * @return array{known: list<string>, notKnown: list<string>}
     */
    public static function evidenceFor(array $issue, string $sourceType, bool $stale): array
    {
        $ruleId = (string)($issue['rule_id'] ?? '');
        $known = ['AQG stored the rule message and a short snippet of the affected element when this source was last checked.'];
        $notKnown = [];

        if ($sourceType === 'rendered') {
            $known[] = 'Found in the rendered HTML of the page (output of templates, layouts, plugins or content); the selector points at the element.';
            $notKnown[] = 'Which content element, template or plugin produced the element is not stored.';
        } elseif ($sourceType === 'structured') {
            $known[] = 'The value of this record field was checked.';
            $notKnown[] = 'How the field is rendered on the page (template output) is not part of this check.';
        } else {
            $known[] = 'The rich text of this one field was checked on its own.';
            $notKnown[] = 'Other content elements, the page template and plugins were not part of this check.';
        }

        if ($ruleId === self::HEADING_JUMP) {
            $levels = self::headingLevels((string)($issue['message'] ?? ''));
            $known[] = $levels !== null
                ? sprintf('The stored element is the H%d that follows an H%d in the same field; H%d was skipped.', $levels[1], $levels[0], $levels[0] + 1)
                : 'The stored element is the heading that skips a level in this field.';
            $notKnown[] = 'The text of the preceding heading is not stored.';
            $notKnown[] = 'Headings in other content elements or in the page template were not compared: the full heading order of the page is not known from this finding.';
        }

        if ($stale) {
            $notKnown[] = 'Whether the current content still has this problem: it changed after the last check, so the finding may be fixed already.';
        }

        return ['known' => $known, 'notKnown' => $notKnown];
    }

    /**
     * One concrete step for this finding, or null when the rule has no template. Uses only levels parsed from AQG's
     * stored message and the field's TCA label.
     *
     * @param array<string, mixed> $issue
     */
    public static function concreteStep(array $issue, string $fieldLabel): ?string
    {
        $ruleId = (string)($issue['rule_id'] ?? '');
        $table = (string)($issue['source_table'] ?? '');
        $uid = (int)($issue['source_uid'] ?? 0);
        $where = $table === 'tt_content'
            ? sprintf('in the field "%s" of content element #%d', $fieldLabel, $uid)
            : sprintf('in the field "%s" of record %s #%d', $fieldLabel, $table, $uid);

        if ($ruleId === self::HEADING_JUMP) {
            $levels = self::headingLevels((string)($issue['message'] ?? ''));
            if ($levels === null) {
                return null;
            }

            return sprintf('Change this H%d to H%d, or add the missing H%d before it, %s.', $levels[1], $levels[0] + 1, $levels[0] + 1, $where);
        }

        return match ($ruleId) {
            'rte.img_alt_missing', 'structured.file_reference_alt', 'rte.image_in_link_missing_alt'
                => sprintf('Add a text alternative that says what the image shows or does %s, or mark the image as decorative if it carries no information.', $where),
            'rte.img_alt_is_filename', 'structured.file_reference_alt_quality', 'rte.img_alt_redundant_phrase', 'rte.img_alt_too_long'
                => sprintf('Rewrite the text alternative %s so that it describes the image briefly, without file names or phrases like "image of".', $where),
            'rte.empty_link', 'structured.header_link_no_text'
                => sprintf('Give the link a visible text that names its target %s, or remove the empty link.', $where),
            'rte.non_descriptive_link', 'rte.link_text_is_url_or_filename'
                => sprintf('Replace the link text %s with words that name the target (not "click here", "more" or a URL).', $where),
            'rte.empty_heading', 'structured.header_ctype_empty'
                => sprintf('Add text to the empty heading %s, or remove the heading.', $where),
            'rte.table_missing_header', 'rte.table_missing_caption', 'structured.table_missing_caption', 'rte.table_th_missing_scope'
                => sprintf('Mark the header cells (and a caption where the table needs one) of the table %s.', $where),
            'rte.iframe_missing_title'
                => sprintf('Add a title that describes the embedded content to the iframe %s.', $where),
            default => null,
        };
    }

    /**
     * Levels from AQG's own message "Heading jumps from H2 to H4. …" (HeadingHierarchyRule).
     *
     * @return array{0: int, 1: int}|null
     */
    public static function headingLevels(string $message): ?array
    {
        if (preg_match('/Heading jumps from H([1-6]) to H([1-6])\./', $message, $matches) !== 1) {
            return null;
        }
        $from = (int)$matches[1];
        $to = (int)$matches[2];

        return $to > $from + 1 ? [$from, $to] : null;
    }
}
