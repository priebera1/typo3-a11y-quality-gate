<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Assignment;

use Priebera\A11yQualityGate\Scan\RenderedRichTextDuplicates;

/**
 * Shared template work (N-01, final customer acceptance): a rendered finding outside every content element — the site
 * template's logo, navigation or footer, stored against the page itself (source_table "pages") — is not an editor's
 * content task. It belongs to one shared task of the site, identified by its issue type and the normalised element
 * snippet, so the same defect on every page and in every language is one piece of developer work.
 *
 * Content tasks keep their identity (site + page + language + canonical rule) and count content findings only.
 */
final class TemplateScope
{
    public const KEY_PREFIX = 'template:';

    /** @param array<string, mixed> $issue a stored finding row */
    public static function isTemplateFinding(array $issue): bool
    {
        return (string)($issue['source_table'] ?? $issue['sourceTable'] ?? '') === 'pages'
            && str_starts_with((string)($issue['rule_id'] ?? $issue['ruleId'] ?? ''), 'rendered.');
    }

    /** The shared task key of a template finding: "template:<canonical rule>:<snippet hash>". */
    public static function key(string $ruleId, string $snippet): string
    {
        return self::KEY_PREFIX . RenderedRichTextDuplicates::canonicalRuleId($ruleId) . ':'
            . substr(hash('sha256', self::normalizeSnippet($snippet)), 0, 16);
    }

    public static function isKey(string $issueKey): bool
    {
        return str_starts_with($issueKey, self::KEY_PREFIX);
    }

    /** The canonical rule of a shared key ("rte.empty_link"), or the key itself for content tasks. */
    public static function ruleOfKey(string $issueKey): string
    {
        if (!self::isKey($issueKey)) {
            return $issueKey;
        }
        $rest = substr($issueKey, strlen(self::KEY_PREFIX));
        $colon = strrpos($rest, ':');

        return $colon === false ? $rest : substr($rest, 0, $colon);
    }

    /**
     * The same template element renders with different link targets and ids per page and language ("/" vs "/de/"):
     * those values are left out so it stays one defect. Image sources and classes stay, so two different icons
     * without a name remain two defects.
     */
    public static function normalizeSnippet(string $snippet): string
    {
        $snippet = (string)preg_replace('/\s+/u', ' ', trim($snippet));
        $snippet = (string)preg_replace('/>\s+</u', '><', $snippet);
        $snippet = (string)preg_replace('/\b(href|action|id|for|aria-labelledby|aria-describedby|aria-controls|data-[a-z0-9_-]+)\s*=\s*("[^"]*"|\'[^\']*\')/iu', '$1=""', $snippet);

        return mb_strtolower($snippet);
    }
}
