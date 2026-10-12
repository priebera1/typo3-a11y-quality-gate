<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Export\LocalReportModel;

/**
 * The Content scan PDFs follow the Content views (audit F2, F6, F8): one group per rule with its fix written once,
 * occurrences numbered n.m, plain rule titles instead of humanised IDs, decoded snippets.
 */
final class LocalReportModelTest extends TestCase
{
    #[Test]
    public function occurrencesAreGroupedByRuleMostSevereFirstAndNumbered(): void
    {
        $groups = LocalReportModel::ruleGroups(
            [
                $this->issue(1, 'rte.non_descriptive_link', 2, 'Link text "read more" is not descriptive.'),
                $this->issue(2, 'rendered.empty_heading', 1, 'Rendered heading is empty.', '<h2>&#xA0;</h2>'),
                $this->issue(3, 'rendered.empty_heading', 1, 'Rendered heading is empty.', '<h2></h2>'),
            ],
            $this->guidance(),
            static fn (array $issue): array => ['title' => 'Block ' . $issue['source_uid'], 'context' => 'Plain HTML', 'technical' => 'tt_content ' . $issue['source_uid']],
        );

        self::assertSame(['rendered.empty_heading', 'rte.non_descriptive_link'], array_column($groups, 'ruleId'));
        self::assertSame(['1.1', '1.2'], array_column($groups[0]['occurrences'], 'index'));
        self::assertSame('2 occurrences', $groups[0]['countLabel']);
        self::assertSame('1 occurrence', $groups[1]['countLabel']);
        self::assertCount(1, $groups[0]['firstOccurrences'], 'The first row stays with the rule header.');
        self::assertCount(1, $groups[0]['moreOccurrences']);
        // The fix is written once per rule, the title is the plain rule title.
        self::assertSame('Remove empty headings', $groups[0]['title']);
        self::assertSame('Remove the empty heading. Or add text.', $groups[0]['howToFix'], 'At most two sentences of the fix.');
        self::assertSame('Block 3', $groups[0]['occurrences'][0]['whereTitle']);
        self::assertSame('<h2> </h2>', $groups[0]['occurrences'][0]['snippet'], 'Entities are decoded for display.');
    }

    #[Test]
    public function aRuleWithoutPlainTitleUsesItsMessageNeverTheHumanisedId(): void
    {
        $rules = LocalReportModel::rules(
            [$this->issue(1, 'rendered.landmark_unique', 4, 'A banner landmark has no accessible name.')],
            static fn (array $issue): array => [],
        );

        self::assertSame('A banner landmark has no accessible name', $rules[0]['title']);
        self::assertStringNotContainsString('Rendered.', $rules[0]['title']);
        self::assertSame('Rendered page', $rules[0]['sourceLabel']);
    }

    #[Test]
    public function theSeverityRowCountsPagesWithCorrectPlurals(): void
    {
        $issues = [
            $this->issue(1, 'rte.empty_link', 1, 'x', '', 10),
            $this->issue(2, 'rte.empty_link', 1, 'x', '', 11),
            $this->issue(3, 'rte.img_alt_missing', 2, 'x', '', 10),
        ];

        $row = LocalReportModel::severityRow(LocalReportModel::totals($issues), LocalReportModel::pageCounts($issues));

        self::assertSame(['Critical', 'Warnings', 'Info', 'Needs review'], array_column($row, 'label'));
        self::assertSame([2, 1, 0, 0], array_column($row, 'count'));
        self::assertSame(['on 2 pages', 'on 1 page', 'on 0 pages', 'on 0 pages'], array_column($row, 'foot'));
    }

    /**
     * @return \Closure(array<string, mixed>): array<string, string>
     */
    private function guidance(): \Closure
    {
        return static fn (array $issue): array => match ((string)$issue['rule_id']) {
            'rendered.empty_heading' => ['title' => 'Remove empty headings', 'howToFix' => 'Remove the empty heading. Or add text. Or both.'],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(int $uid, string $ruleId, int $severity, string $message, string $snippet = '', int $pageUid = 729): array
    {
        return [
            'uid' => $uid,
            'rule_id' => $ruleId,
            'severity' => $severity,
            'message' => $message,
            'context_snippet' => $snippet,
            'source_uid' => $uid + 1,
            'page_uid' => $pageUid,
            'page_title' => 'AQG 01',
        ];
    }
}
