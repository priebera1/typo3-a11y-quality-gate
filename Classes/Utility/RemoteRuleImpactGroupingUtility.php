<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Utility;

/**
 * The Frontend page view lists its rules under impact headings: Critical, Serious, Moderate, Minor, then rules without
 * a known impact. Within a group the top recommendation comes first, then the rule with the most occurrences; equal
 * rules keep the order the scan stored them in.
 *
 * Only the order and the grouping change; every rule keeps all fields the controller prepared.
 */
final class RemoteRuleImpactGroupingUtility
{
    private const IMPACT_ORDER = ['critical', 'serious', 'moderate', 'minor'];

    /**
     * @param list<array<string, mixed>> $rules rules as prepared by RemotePageDetailController::groupIssuesByRule()
     * @param string $recommendationRuleId the page recommendation's primary rule, if any
     * @return list<array{impact: string, anchorId: string, ruleCount: int, rules: list<array<string, mixed>>}>
     */
    public static function group(array $rules, string $recommendationRuleId = ''): array
    {
        $recommendationRuleId = strtolower(trim($recommendationRuleId));
        $rules = self::withIssueTypeNumbers(array_values($rules));
        $groups = [];

        foreach (array_values($rules) as $position => $rule) {
            $impact = strtolower(trim((string)($rule['impact'] ?? '')));
            if (!in_array($impact, self::IMPACT_ORDER, true)) {
                $impact = 'other';
            }

            $rule['impactLevel'] = $impact;
            $rule['hasRecommendationSteps'] = $recommendationRuleId !== ''
                && strtolower((string)($rule['rule_id'] ?? '')) === $recommendationRuleId;
            $rule['sortPosition'] = $position;
            $groups[$impact][] = $rule;
        }

        $ordered = [];
        foreach ([...self::IMPACT_ORDER, 'other'] as $impact) {
            if (!isset($groups[$impact])) {
                continue;
            }

            $groupRules = $groups[$impact];
            usort($groupRules, static function (array $left, array $right): int {
                return [
                    (int)!($left['isPrimaryRecommendation'] ?? false),
                    -(int)($left['count'] ?? 0),
                    (int)$left['sortPosition'],
                ] <=> [
                    (int)!($right['isPrimaryRecommendation'] ?? false),
                    -(int)($right['count'] ?? 0),
                    (int)$right['sortPosition'],
                ];
            });

            $ordered[] = [
                'impact' => $impact,
                'anchorId' => 'aqg-impact-' . $impact,
                'ruleCount' => count($groupRules),
                'rules' => array_map(static function (array $rule): array {
                    unset($rule['sortPosition']);

                    return $rule;
                }, $groupRules),
            ];
        }

        return $ordered;
    }

    /**
     * The issue-type number of the Frontend PDF's issue groups and of the crawler's screenshot labels: impact
     * (critical, serious, moderate, minor, none), then occurrences on the page, then rule id. Display order may differ.
     *
     * @param list<array<string, mixed>> $rules
     * @return list<array<string, mixed>>
     */
    private static function withIssueTypeNumbers(array $rules): array
    {
        $rank = static fn (array $rule): int => match (strtolower(trim((string)($rule['impact'] ?? '')))) {
            'critical' => 4,
            'serious' => 3,
            'moderate' => 2,
            'minor' => 1,
            default => 0,
        };
        $order = array_keys($rules);
        usort($order, static fn (int $a, int $b): int => [$rank($rules[$b]), (int)($rules[$b]['count'] ?? 0), (string)($rules[$a]['rule_id'] ?? '')]
            <=> [$rank($rules[$a]), (int)($rules[$a]['count'] ?? 0), (string)($rules[$b]['rule_id'] ?? '')]);
        foreach ($order as $position => $index) {
            $rules[$index]['issueTypeNumber'] = $position + 1;
        }

        return $rules;
    }
}
