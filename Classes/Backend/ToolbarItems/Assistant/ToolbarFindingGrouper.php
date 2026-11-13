<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant;

/**
 * Groups the stored open findings of one context by rule and source, so the toolbar shows "Landmark not unique ·
 * Rendered HTML · 4 occurrences" once instead of four identical cards. Every finding of the input stays in exactly
 * one group (counts are never dropped); the order is most severe first, then the larger group, then the rule id.
 */
final class ToolbarFindingGrouper
{
    public const SOURCE_RENDERED = 'rendered';
    public const SOURCE_STRUCTURED = 'structured';
    public const SOURCE_CONTENT = 'content';

    /**
     * The source bucket of a finding: rendered HTML (template, layout, plugin output), structured fields, or the
     * editor's content (rich text and every other content source). Page Detail uses the same three labels.
     *
     * @param array<string, mixed> $issue
     */
    public static function sourceOf(array $issue): string
    {
        return match ((string)($issue['source_type'] ?? '')) {
            'rendered' => self::SOURCE_RENDERED,
            'structured' => self::SOURCE_STRUCTURED,
            default => self::SOURCE_CONTENT,
        };
    }

    /**
     * @param list<array<string, mixed>> $issues open finding rows (tx_a11y_issue)
     * @return list<array{key: string, ruleId: string, source: string, severity: int, count: int, records: int, issues: list<array<string, mixed>>}>
     */
    public static function group(array $issues): array
    {
        $groups = [];
        foreach ($issues as $issue) {
            $ruleId = (string)($issue['rule_id'] ?? '');
            if ($ruleId === '') {
                continue;
            }
            $source = self::sourceOf($issue);
            $key = $ruleId . '|' . $source;
            $groups[$key] ??= ['key' => $key, 'ruleId' => $ruleId, 'source' => $source, 'severity' => PHP_INT_MAX, 'count' => 0, 'records' => 0, 'issues' => [], 'recordKeys' => []];
            $groups[$key]['issues'][] = $issue;
            $groups[$key]['count']++;
            $groups[$key]['severity'] = min($groups[$key]['severity'], (int)($issue['severity'] ?? PHP_INT_MAX));
            $recordKey = (string)($issue['source_table'] ?? '') . ':' . (int)($issue['source_uid'] ?? 0);
            $groups[$key]['recordKeys'][$recordKey] = true;
        }

        $result = [];
        foreach ($groups as $group) {
            $group['records'] = count($group['recordKeys']);
            unset($group['recordKeys']);
            // The most severe occurrence leads the group and carries its actions.
            usort($group['issues'], static fn (array $a, array $b): int => [(int)($a['severity'] ?? 0), (int)($a['uid'] ?? 0)] <=> [(int)($b['severity'] ?? 0), (int)($b['uid'] ?? 0)]);
            $result[] = $group;
        }
        usort($result, static fn (array $a, array $b): int => [$a['severity'], -$a['count'], $a['ruleId'], $a['source']] <=> [$b['severity'], -$b['count'], $b['ruleId'], $b['source']]);

        return $result;
    }

    /**
     * Distinct rules ("issue types") among the findings.
     *
     * @param list<array<string, mixed>> $issues
     */
    public static function issueTypes(array $issues): int
    {
        return count(array_unique(array_filter(array_map(static fn (array $issue): string => (string)($issue['rule_id'] ?? ''), $issues))));
    }
}
