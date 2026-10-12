<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Export;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Utility\OccurrencePresentationUtility;

/**
 * The content of the Content scan PDFs, shaped like the Content views: issues grouped by rule (most severe and most
 * frequent first), numbered `n` per rule and `n.m` per occurrence, with the rule's plain title and fix written once.
 *
 * Pure data shaping: guidance (title, fix) and record locations come in through closures, so the model is testable
 * without TYPO3, and nothing here decides what a finding is.
 */
final class LocalReportModel
{
    public const SNIPPET_LIMIT = 160;

    private const SEVERITY_KEYS = ['critical', 'warning', 'info', 'needs_review'];

    /**
     * @param list<array<string, mixed>> $issues exported issue rows (`tx_a11y_issue` + `page_title`)
     * @param \Closure(array<string, mixed>): array{title?: string, howToFix?: string, whyItMatters?: string} $guidance
     * @return list<array{ruleId: string, title: string, tone: string, severityLabel: string, count: int, pages: int, sourceLabel: string, howToFix: string, whyItMatters: string}>
     */
    public static function rules(array $issues, \Closure $guidance): array
    {
        $rules = [];
        foreach ($issues as $issue) {
            $ruleId = trim((string)($issue['rule_id'] ?? ''));
            if ($ruleId === '') {
                continue;
            }
            $tone = self::severityKey($issue);
            if (!isset($rules[$ruleId])) {
                $presented = $guidance($issue);
                $rules[$ruleId] = [
                    'ruleId' => $ruleId,
                    'title' => self::ruleTitle($issue, $presented),
                    'tone' => $tone,
                    'severityLabel' => self::severityLabel($tone),
                    'count' => 0,
                    'pages' => [],
                    'sourceLabel' => self::sourceLabel($issue),
                    'howToFix' => self::firstSentences(trim((string)($presented['howToFix'] ?? $issue['hint'] ?? '')), 2),
                    'whyItMatters' => self::firstSentences(trim((string)($presented['whyItMatters'] ?? '')), 2),
                ];
            }
            $rules[$ruleId]['count']++;
            $rules[$ruleId]['pages'][(int)($issue['page_uid'] ?? 0)] = true;
            if (self::weight($tone) > self::weight($rules[$ruleId]['tone'])) {
                $rules[$ruleId]['tone'] = $tone;
                $rules[$ruleId]['severityLabel'] = self::severityLabel($tone);
            }
        }

        $rules = array_values(array_map(
            static fn (array $rule): array => ['pages' => count($rule['pages'])] + $rule,
            $rules
        ));
        usort(
            $rules,
            static fn (array $a, array $b): int => [self::weight($b['tone']), $b['count'], $a['ruleId']]
                <=> [self::weight($a['tone']), $a['count'], $b['ruleId']]
        );

        return $rules;
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return list<array{pageUid: int, title: string, critical: int, warning: int, info: int, needs_review: int, total: int}>
     */
    public static function pages(array $issues): array
    {
        $pages = [];
        foreach ($issues as $issue) {
            $pageUid = (int)($issue['page_uid'] ?? 0);
            if (!isset($pages[$pageUid])) {
                $title = trim((string)($issue['page_title'] ?? ''));
                $pages[$pageUid] = [
                    'pageUid' => $pageUid,
                    'title' => $title !== '' ? $title : 'Page ' . $pageUid,
                    'critical' => 0,
                    'warning' => 0,
                    'info' => 0,
                    'needs_review' => 0,
                    'total' => 0,
                ];
            }
            $pages[$pageUid][self::severityKey($issue)]++;
            $pages[$pageUid]['total']++;
        }

        $pages = array_values($pages);
        usort(
            $pages,
            static fn (array $a, array $b): int => [$b['total'], $b['critical'], $a['title']] <=> [$a['total'], $a['critical'], $b['title']]
        );

        return $pages;
    }

    /**
     * Rule groups for the page report: each rule once, its occurrences as numbered rows.
     *
     * @param list<array<string, mixed>> $issues
     * @param \Closure(array<string, mixed>): array{title?: string, howToFix?: string, whyItMatters?: string} $guidance
     * @param \Closure(array<string, mixed>): array{title: string, context: string, technical: string} $location
     * @return list<array<string, mixed>>
     */
    public static function ruleGroups(array $issues, \Closure $guidance, \Closure $location): array
    {
        $byRule = [];
        foreach ($issues as $issue) {
            $byRule[trim((string)($issue['rule_id'] ?? ''))][] = $issue;
        }

        $groups = [];
        foreach (self::rules($issues, $guidance) as $number => $rule) {
            $occurrences = [];
            foreach ($byRule[$rule['ruleId']] ?? [] as $position => $issue) {
                $where = $location($issue);
                $occurrences[] = [
                    'index' => ($number + 1) . '.' . ($position + 1),
                    'whereTitle' => $where['title'],
                    'whereContext' => $where['context'],
                    'technical' => $where['technical'],
                    'snippet' => OccurrencePresentationUtility::snippetPreview((string)($issue['context_snippet'] ?? ''), self::SNIPPET_LIMIT),
                    'message' => trim((string)($issue['message'] ?? '')),
                ];
            }
            $groups[] = $rule + [
                'number' => $number + 1,
                'occurrences' => $occurrences,
                'firstOccurrences' => array_slice($occurrences, 0, 1),
                'moreOccurrences' => array_slice($occurrences, 1),
                'countLabel' => $rule['count'] === 1 ? '1 occurrence' : $rule['count'] . ' occurrences',
            ];
        }

        return $groups;
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return array{critical: int, warning: int, info: int, needs_review: int, total: int}
     */
    public static function totals(array $issues): array
    {
        $totals = ['critical' => 0, 'warning' => 0, 'info' => 0, 'needs_review' => 0, 'total' => 0];
        foreach ($issues as $issue) {
            $totals[self::severityKey($issue)]++;
            $totals['total']++;
        }

        return $totals;
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return array{critical: int, warning: int, info: int, needs_review: int, total: int}
     */
    public static function pageCounts(array $issues): array
    {
        $pages = ['critical' => [], 'warning' => [], 'info' => [], 'needs_review' => [], 'total' => []];
        foreach ($issues as $issue) {
            $pageUid = (int)($issue['page_uid'] ?? 0);
            $pages[self::severityKey($issue)][$pageUid] = true;
            $pages['total'][$pageUid] = true;
        }

        return array_map('count', $pages);
    }

    /**
     * @param array{critical: int, warning: int, info: int, needs_review: int, total: int} $totals
     * @param array{critical: int, warning: int, info: int, needs_review: int, total: int}|null $pageCounts null on a page report
     * @return list<array{tone: string, label: string, count: int, foot: string}>
     */
    public static function severityRow(array $totals, ?array $pageCounts): array
    {
        $row = [];
        foreach (self::SEVERITY_KEYS as $key) {
            $row[] = [
                'tone' => $key === 'needs_review' ? 'review' : $key,
                'label' => match ($key) {
                    'critical' => 'Critical',
                    'warning' => 'Warnings',
                    'info' => 'Info',
                    default => 'Needs review',
                },
                'count' => (int)$totals[$key],
                'foot' => $pageCounts === null
                    ? ''
                    : ((int)$pageCounts[$key] === 1 ? 'on 1 page' : 'on ' . (int)$pageCounts[$key] . ' pages'),
            ];
        }

        return $row;
    }

    /**
     * The stored message is a sentence ("Rendered heading is empty."); the plain rule title ("Remove empty headings")
     * is preferred. Never the humanised rule ID ("Rendered.empty heading").
     *
     * @param array<string, mixed> $issue
     * @param array<string, mixed> $presented
     */
    public static function ruleTitle(array $issue, array $presented): string
    {
        $title = trim((string)($presented['title'] ?? ''));
        if ($title === '') {
            $title = trim((string)($issue['message'] ?? ''));
        }
        $title = (string)preg_replace('/\.\s*$/u', '', $title);

        return $title !== '' ? $title : (string)($issue['rule_id'] ?? 'Accessibility rule');
    }

    /**
     * @param array<string, mixed> $issue
     */
    public static function sourceLabel(array $issue): string
    {
        $sourceType = trim((string)($issue['source_type'] ?? ''));
        $ruleId = (string)($issue['rule_id'] ?? '');
        if ($sourceType === '') {
            $sourceType = str_starts_with($ruleId, 'structured.') ? 'structured' : (str_starts_with($ruleId, 'rendered.') ? 'rendered' : 'rte');
        }

        return match ($sourceType) {
            'rendered' => 'Rendered page',
            'structured' => 'Content field',
            default => 'Rich text',
        };
    }

    public static function severityLabel(string $tone): string
    {
        return match ($tone) {
            'critical' => 'Critical',
            'warning' => 'Warning',
            'info' => 'Info',
            default => 'Needs review',
        };
    }

    /**
     * @param array<string, mixed> $issue
     */
    private static function severityKey(array $issue): string
    {
        return match (Severity::fromInt((int)($issue['severity'] ?? 0))) {
            Severity::Critical => 'critical',
            Severity::Warning => 'warning',
            Severity::Info => 'info',
            Severity::NeedsReview => 'needs_review',
        };
    }

    private static function weight(string $tone): int
    {
        return match ($tone) {
            'critical' => 30,
            'warning' => 20,
            'needs_review' => 15,
            'info' => 10,
            default => 0,
        };
    }

    private static function firstSentences(string $text, int $sentences): string
    {
        if ($text === '') {
            return '';
        }
        $parts = preg_split('/(?<=[.!?])\s+/u', $text) ?: [$text];

        return trim(implode(' ', array_slice($parts, 0, $sentences)));
    }
}
