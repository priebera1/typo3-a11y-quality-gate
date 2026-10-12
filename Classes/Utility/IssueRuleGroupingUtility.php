<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Utility;

use Priebera\A11yQualityGate\Domain\Enum\Severity;

/**
 * The Content page view lists one card per rule and one row per occurrence.
 *
 * Pagination stays per issue (the list page the controller sliced); the grouping only arranges that list page. Every
 * group and rule also knows its total among all issues of the active filters, so a heading can say when part of it
 * continues on another list page instead of presenting the list page's share as the page's count.
 */
final class IssueRuleGroupingUtility
{
    private const SEVERITY_ORDER = ['critical', 'warning', 'info', 'needs_review'];

    /**
     * List pages that hold whole rule groups (audit T3). Issues are bucketed by severity (most severe first) and rule
     * (first appearance), and the buckets are packed into pages of about $budget occurrences. A page always takes at
     * least one rule, so a rule larger than the budget fills a page of its own instead of being cut.
     *
     * @param list<array<string, mixed>> $filteredIssues all issues of the active status and severity filters, in display order
     * @return non-empty-list<list<array<string, mixed>>>
     */
    public static function paginateByRule(array $filteredIssues, int $budget): array
    {
        $buckets = [];
        foreach ($filteredIssues as $issue) {
            $buckets[self::severityKey($issue)][self::ruleId($issue)][] = $issue;
        }

        $pages = [];
        $page = [];
        foreach (self::SEVERITY_ORDER as $severity) {
            foreach ($buckets[$severity] ?? [] as $ruleIssues) {
                if ($page !== [] && count($page) + count($ruleIssues) > max(1, $budget)) {
                    $pages[] = $page;
                    $page = [];
                }
                array_push($page, ...$ruleIssues);
            }
        }
        if ($page !== []) {
            $pages[] = $page;
        }

        return $pages !== [] ? $pages : [[]];
    }

    /**
     * @param list<array<string, mixed>> $listPageIssues issues of the current list page, in display order
     * @param list<array<string, mixed>> $filteredIssues all issues of the active status and severity filters
     * @return array<string, array{
     *   key: string,
     *   issuesOnListPage: int,
     *   issuesTotal: int,
     *   issueTypesTotal: int,
     *   continues: bool,
     *   rules: list<array<string, mixed>>
     * }> keyed by severity, most severe first; severities without an issue on this list page are left out
     */
    public static function group(array $listPageIssues, array $filteredIssues): array
    {
        $severityTotals = [];
        $ruleTotals = [];
        foreach ($filteredIssues as $issue) {
            $severity = self::severityKey($issue);
            $severityTotals[$severity] = ($severityTotals[$severity] ?? 0) + 1;
            $ruleKey = $severity . '|' . self::ruleId($issue);
            $ruleTotals[$ruleKey] = ($ruleTotals[$ruleKey] ?? 0) + 1;
        }

        $issueTypeTotals = [];
        foreach (array_keys($ruleTotals) as $ruleKey) {
            $severity = explode('|', $ruleKey, 2)[0];
            $issueTypeTotals[$severity] = ($issueTypeTotals[$severity] ?? 0) + 1;
        }

        $groups = [];
        foreach ($listPageIssues as $issue) {
            $severity = self::severityKey($issue);
            $ruleId = self::ruleId($issue);

            $groups[$severity] ??= [
                'key' => $severity,
                'issuesOnListPage' => 0,
                'issuesTotal' => (int)($severityTotals[$severity] ?? 0),
                'issueTypesTotal' => (int)($issueTypeTotals[$severity] ?? 0),
                'continues' => false,
                'rules' => [],
            ];
            $groups[$severity]['issuesOnListPage']++;

            if (!isset($groups[$severity]['rules'][$ruleId])) {
                $guidance = is_array($issue['guidance'] ?? null) ? $issue['guidance'] : [];
                $groups[$severity]['rules'][$ruleId] = [
                    'ruleId' => $ruleId,
                    'anchorId' => RuleAnchorUtility::anchorId($ruleId) . '-' . $severity,
                    'title' => (string)($issue['ruleTitle'] ?? $issue['displayTitle'] ?? $issue['message'] ?? $ruleId),
                    'guidance' => $guidance,
                    'howToFix' => trim((string)($guidance['howToFix'] ?? '')),
                    'issues' => [],
                    'occurrencesOnListPage' => 0,
                    'occurrencesTotal' => (int)($ruleTotals[$severity . '|' . $ruleId] ?? 0),
                    'continues' => false,
                    'hasOpenIssue' => false,
                    'openRuleCountOnPage' => 0,
                    'openRuleCountOnSite' => 0,
                    'hasRenderedSource' => false,
                ];
            }

            $rule = &$groups[$severity]['rules'][$ruleId];
            $rule['issues'][] = $issue;
            $rule['occurrencesOnListPage']++;
            if ((string)($issue['sourceType'] ?? '') === 'rendered') {
                $rule['hasRenderedSource'] = true;
            }
            if ((int)($issue['status'] ?? -1) === 0 && !$rule['hasOpenIssue']) {
                $rule['hasOpenIssue'] = true;
                $rule['openRuleCountOnPage'] = (int)($issue['openRuleCountOnPage'] ?? 0);
                $rule['openRuleCountOnSite'] = (int)($issue['openRuleCountOnSite'] ?? 0);
            }
            unset($rule);
        }

        $ordered = [];
        foreach (self::SEVERITY_ORDER as $severity) {
            if (!isset($groups[$severity])) {
                continue;
            }

            $group = $groups[$severity];
            $group['continues'] = $group['issuesOnListPage'] < $group['issuesTotal'];
            $group['rules'] = array_values(array_map(
                static fn (array $rule): array => ['continues' => $rule['occurrencesOnListPage'] < $rule['occurrencesTotal']] + $rule,
                $group['rules']
            ));
            $ordered[$severity] = $group;
        }

        return $ordered;
    }

    /**
     * @param array<string, mixed> $issue
     */
    private static function severityKey(array $issue): string
    {
        return Severity::fromInt((int)($issue['severity'] ?? 0))->key();
    }

    /**
     * @param array<string, mixed> $issue
     */
    private static function ruleId(array $issue): string
    {
        $ruleId = trim((string)($issue['rule_id'] ?? ''));

        return $ruleId !== '' ? $ruleId : 'unknown';
    }
}
