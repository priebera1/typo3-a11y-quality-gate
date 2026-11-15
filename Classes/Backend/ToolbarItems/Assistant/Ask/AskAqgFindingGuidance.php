<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask;

use Priebera\A11yQualityGate\Mcp\Intelligence\RuleEvidenceCatalog;
use Priebera\A11yQualityGate\Service\BackendContextService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * What an editor needs to fix a finding that AI Fixes cannot prepare ("Needs manual work" alone is a dead end): what is
 * wrong, where (record and field), the recommended correction, the editor action, what AQG cannot know, and how AQG
 * will confirm the fix. Deterministic, no AI request, shown only to the editor who may read the finding.
 *
 * Heading levels (rte.heading_hierarchy_jump) are explained from the evidence AQG has: the levels in its stored message
 * and the headings of the saved text field as it is now. The recommendation is the smallest change the evidence
 * supports — one level below the heading before it in the same field (H2 → H3, never a guess such as H4 → H2) — and the
 * guidance says that headings outside this field (the element's own header, other elements, the page template) were
 * not part of the check. Findings from the rendered page (template, layout or plugin output) are never presented as
 * editable content: AQG does not know their source.
 */
final class AskAqgFindingGuidance
{
    public const HEADING_JUMP = 'rte.heading_hierarchy_jump';
    /** IssueLocationService / LocalFindingSourceAccessService: the pseudo field of rendered-page findings. */
    private const RENDERED_FIELD = '__rendered_html';

    /** Tables whose rich text fields AQG reads for a heading outline (the same set the toolbar edits). */
    private const OUTLINE_TABLES = ['tt_content', 'pages'];
    private const MAX_OUTLINE = 12;
    private const MAX_FIELD_BYTES = 200000;

    public function __construct(
        private readonly BackendContextService $backendContextService,
        private readonly ?ConnectionPool $connectionPool = null,
    ) {}

    /**
     * @param array<string,mixed> $issue a tx_a11y_issue row the editor may read
     * @param array{place:string,howToFix:string,reason:string,state:string,editable:bool,editingThis:bool,workspace:int} $facts
     * @return array{problem:string,where:string,outline:list<array{level:int,text:string,flagged:bool}>,recommendation:string,action:string,missing:string,verify:string}
     */
    public function build(array $issue, array $facts): array
    {
        $ruleId = (string)($issue['rule_id'] ?? '');
        $reason = $facts['reason'];
        $guidance = [
            'problem' => '',
            'where' => $facts['place'],
            'outline' => [],
            'recommendation' => $facts['howToFix'],
            'action' => '',
            'missing' => '',
            'verify' => $this->t('toolbar.guide.verify', 'After you save, choose “Check saved page”. AQG checks the page again and closes this issue when the problem is gone.'),
        ];

        if ($reason === 'rendered_output' || self::isRendered($issue)) {
            // Found in the HTML visitors get, not in a record field: the source is not known, so nothing is "editable".
            $guidance['where'] = $this->t('toolbar.guide.rendered.where', 'In the page as visitors see it (template, layout or plugin output).');
            $guidance['action'] = $this->t('toolbar.guide.rendered.action', 'Send this issue to the person who maintains your templates or extensions. If it comes from content, find the element on the page and correct it there.');
            $guidance['missing'] = $this->t('toolbar.guide.rendered.missing', 'AQG found this in the rendered page. It does not know which content element, template or plugin produced it, so it cannot open an editor for it.');

            return $guidance;
        }

        if ($ruleId === self::HEADING_JUMP) {
            $guidance = $this->headingGuidance($issue, $guidance, $facts);
        }

        $guidance['action'] = match (true) {
            $reason === 'no_edit_access' || (!$facts['editable'] && !$facts['editingThis']) => $this->t('toolbar.guide.action.noAccess', 'You may read this content but not change it. Ask an editor with access to this element to make the change.'),
            $guidance['action'] !== '' => $guidance['action'],
            $facts['editingThis'] => $this->t('toolbar.guide.action.here', 'The element is open in the editor. Use “Show in form” to jump to the field, correct it and save.'),
            default => $this->t('toolbar.guide.action.open', 'Open the element in the editor, correct the field named above and save.'),
        };
        if ($facts['workspace'] > 0 && $guidance['missing'] === '') {
            $guidance['missing'] = $this->t('toolbar.guide.workspace', 'AQG checked the live version. Your workspace version may differ; check it before you publish.');
        }

        return $guidance;
    }

    /** Whether a finding comes from the rendered page HTML (rendered.* rules, field "__rendered_html"), not a record field. */
    public static function isRendered(array $issue): bool
    {
        return (string)($issue['source_field'] ?? '') === self::RENDERED_FIELD
            || str_starts_with((string)($issue['rule_id'] ?? ''), 'rendered.')
            || (string)($issue['source_table'] ?? '') === '';
    }

    /**
     * @param array<string,mixed> $issue
     * @param array<string,mixed> $guidance
     * @param array<string,mixed> $facts
     * @return array<string,mixed>
     */
    private function headingGuidance(array $issue, array $guidance, array $facts): array
    {
        $levels = RuleEvidenceCatalog::headingLevels((string)($issue['message'] ?? ''));
        // In a workspace the live text is not the text the editor works on: no outline, the levels from the finding only.
        $outline = (int)($facts['workspace'] ?? 0) > 0 ? [] : $this->outline((string)($issue['source_table'] ?? ''), (int)($issue['source_uid'] ?? 0), (string)($issue['source_field'] ?? ''));
        $guidance['missing'] = $this->t('toolbar.guide.heading.missing', 'AQG checked this text field on its own. Headings in the element’s own header, in other elements and in the page template were not compared. If this text starts a new section of the page, choose the level that fits the page’s heading structure.');
        if ($levels === null) {
            $guidance['problem'] = $this->t('toolbar.guide.heading.problemUnknown', 'A heading in this text skips a level.');

            return $guidance;
        }
        [$from, $to] = $levels;
        // The stored message names the levels; the saved text names the headings. The flagged heading is the first jump
        // of exactly these levels, so the outline points at the element the finding is about.
        $flaggedAt = -1;
        foreach ($outline as $index => $heading) {
            if ($index > 0 && $outline[$index - 1]['level'] === $from && $heading['level'] === $to) {
                $flaggedAt = $index;
                break;
            }
        }
        if ($flaggedAt >= 0) {
            $outline[$flaggedAt]['flagged'] = true;
            $guidance['problem'] = sprintf(
                $this->t('toolbar.guide.heading.problemNamed', 'The heading “%1$s” is an H%2$d, but the heading before it in this text, “%3$s”, is an H%4$d. Level H%5$d is skipped, so screen reader users may think part of the content is missing.'),
                $outline[$flaggedAt]['text'],
                $to,
                $outline[$flaggedAt - 1]['text'],
                $from,
                $from + 1,
            );
            $guidance['recommendation'] = sprintf(
                $this->t('toolbar.guide.heading.recommendNamed', 'Change “%1$s” from H%2$d to H%3$d, one level below the H%4$d before it.'),
                $outline[$flaggedAt]['text'],
                $to,
                $from + 1,
                $from,
            );
        } else {
            $guidance['problem'] = sprintf(
                $this->t('toolbar.guide.heading.problem', 'A heading in this text jumps from H%1$d to H%2$d. Level H%3$d is skipped, so screen reader users may think part of the content is missing.'),
                $from,
                $to,
                $from + 1,
            );
            $guidance['recommendation'] = sprintf(
                $this->t('toolbar.guide.heading.recommend', 'Change that H%1$d to H%2$d, one level below the H%3$d before it.'),
                $to,
                $from + 1,
                $from,
            );
            if ($outline !== []) {
                // The saved text no longer has this jump: say so instead of pointing at a heading that may be fixed.
                $guidance['missing'] = $this->t('toolbar.guide.heading.changed', 'The saved text no longer shows this jump. It may be fixed already; check the saved page to confirm.') . ' ' . $guidance['missing'];
            }
        }
        $guidance['outline'] = array_slice($outline, 0, self::MAX_OUTLINE);
        $guidance['action'] = sprintf(
            $this->t('toolbar.guide.heading.action', 'Open the text in the editor. AQG marks the heading there; choose “Change to Heading %d” in AQG’s panel (no AI), or pick that level in the toolbar’s Heading menu. Then save.'),
            $from + 1,
        );

        return $guidance;
    }

    /**
     * The headings of the saved field, in order (level and the first 80 characters of their text). Only tables the
     * assistant edits; the finding was already authorised for this editor (ToolbarAssistantService::withReadableSources()).
     *
     * @return list<array{level:int,text:string,flagged:bool}>
     */
    public function outline(string $table, int $uid, string $field): array
    {
        if ($this->connectionPool === null || !in_array($table, self::OUTLINE_TABLES, true) || $uid <= 0
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field) !== 1 || !isset($GLOBALS['TCA'][$table]['columns'][$field])) {
            return [];
        }
        // The text is shown to this editor: only from a field the editor may read (an exclude field needs its
        // non_exclude_fields right), never for an exclude field without it.
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof \TYPO3\CMS\Core\Authentication\BackendUserAuthentication) {
            return [];
        }
        if (!$user->isAdmin() && (bool)($GLOBALS['TCA'][$table]['columns'][$field]['exclude'] ?? false)
            && !$user->check('non_exclude_fields', $table . ':' . $field)) {
            return [];
        }
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
            $value = $queryBuilder->select($field)->from($table)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)))
                ->executeQuery()->fetchOne();
        } catch (\Throwable) {
            return [];
        }
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_FIELD_BYTES) {
            return [];
        }

        return self::headingsOf($value);
    }

    /**
     * @return list<array{level:int,text:string,flagged:bool}>
     */
    public static function headingsOf(string $html): array
    {
        if (preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h\1\s*>/is', $html, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }
        $headings = [];
        foreach ($matches as $match) {
            $text = trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $headings[] = [
                'level' => (int)$match[1],
                'text' => $text === '' ? '…' : (mb_strlen($text) > 80 ? mb_substr($text, 0, 79) . '…' : $text),
                'flagged' => false,
            ];
        }

        return $headings;
    }

    private function t(string $key, string $fallback): string
    {
        $translated = $this->backendContextService->translate($key);

        return $translated !== '' && $translated !== $key ? $translated : $fallback;
    }
}
