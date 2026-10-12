<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Utility\IssueRuleGroupingUtility;
use Priebera\A11yQualityGate\Utility\RuleAnchorUtility;

final class IssueRuleGroupingUtilityTest extends TestCase
{
    #[Test]
    public function issuesOfTheListPageAreGroupedBySeverityThenRuleMostSevereFirst(): void
    {
        $listPage = [
            $this->issue(1, 2, 'rte.non_descriptive_link'),
            $this->issue(2, 1, 'rte.img_alt_missing'),
            $this->issue(3, 1, 'rte.empty_link'),
            $this->issue(4, 1, 'rte.img_alt_missing'),
        ];

        $groups = IssueRuleGroupingUtility::group($listPage, $listPage);

        self::assertSame(['critical', 'warning'], array_keys($groups));
        self::assertSame(['rte.img_alt_missing', 'rte.empty_link'], array_column($groups['critical']['rules'], 'ruleId'));
        self::assertSame([2, 4], array_column($groups['critical']['rules'][0]['issues'], 'uid'));
        self::assertSame(2, $groups['critical']['rules'][0]['occurrencesOnListPage']);
        self::assertSame(3, $groups['critical']['issuesOnListPage']);
    }

    #[Test]
    public function headingsCountTheFilteredTotalAndSayWhenAGroupContinuesOnAnotherListPage(): void
    {
        $filtered = [];
        for ($uid = 1; $uid <= 14; $uid++) {
            $filtered[] = $this->issue($uid, 2, $uid <= 4 ? 'rte.non_descriptive_link' : 'rte.table_missing_th');
        }
        $listPage = array_slice($filtered, 0, 2);

        $warnings = IssueRuleGroupingUtility::group($listPage, $filtered)['warning'];

        self::assertSame(14, $warnings['issuesTotal'], 'The page total, never the share of this list page.');
        // Audit D2: the heading names issue types and occurrences, like the rule rows and the Frontend view.
        self::assertSame(2, $warnings['issueTypesTotal'], 'Distinct rules of the filtered issues, not of this list page.');
        self::assertSame(2, $warnings['issuesOnListPage']);
        self::assertTrue($warnings['continues']);
        self::assertSame(4, $warnings['rules'][0]['occurrencesTotal']);
        self::assertTrue($warnings['rules'][0]['continues']);
    }

    #[Test]
    public function aRuleCarriesTheFirstIssuesGuidanceAndTheOpenCountsForTheRuleMenu(): void
    {
        $ignored = $this->issue(1, 1, 'rte.empty_link', 2);
        $open = $this->issue(2, 1, 'rte.empty_link', 0) + ['openRuleCountOnPage' => 3, 'openRuleCountOnSite' => 9];
        $rendered = $this->issue(3, 1, 'rte.empty_link', 0) + ['sourceType' => 'rendered'];

        $rule = IssueRuleGroupingUtility::group([$ignored, $open, $rendered], [$ignored, $open, $rendered])['critical']['rules'][0];

        self::assertSame('Provide link text.', $rule['howToFix']);
        self::assertSame('Link has no accessible name.', $rule['title']);
        self::assertTrue($rule['hasOpenIssue']);
        self::assertSame(3, $rule['openRuleCountOnPage']);
        self::assertSame(9, $rule['openRuleCountOnSite']);
        self::assertTrue($rule['hasRenderedSource']);
        self::assertSame(RuleAnchorUtility::anchorId('rte.empty_link') . '-critical', $rule['anchorId']);
    }

    #[Test]
    public function anEmptyListPageHasNoGroups(): void
    {
        self::assertSame([], IssueRuleGroupingUtility::group([], []));
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Audit T3: "10 of 24 on this list page" inside the Critical group — a fixed ten issues per list page cut through
     * rules. List pages now hold whole rule groups, in display order, up to the budget; a larger rule gets its own page.
     */
    #[Test]
    public function listPagesHoldWholeRuleGroupsInDisplayOrder(): void
    {
        $issues = [
            $this->issue(1, 1, 'rte.empty_link'),
            $this->issue(2, 2, 'rte.table_th_missing_scope'),
            $this->issue(3, 1, 'rte.img_alt_missing'),
            $this->issue(4, 1, 'rte.empty_link'),
        ];
        for ($uid = 10; $uid < 34; $uid++) {
            $issues[] = $this->issue($uid, 1, 'rendered.empty_heading');
        }
        $issues[] = $this->issue(40, 4, 'rendered.landmark_unique');

        $pages = IssueRuleGroupingUtility::paginateByRule($issues, 10);

        $rulesPerPage = array_map(
            static fn (array $page): array => array_values(array_unique(array_column($page, 'rule_id'))),
            $pages
        );
        self::assertSame([
            ['rte.empty_link', 'rte.img_alt_missing'],
            ['rendered.empty_heading'],
            ['rte.table_th_missing_scope', 'rendered.landmark_unique'],
        ], $rulesPerPage);
        self::assertSame([1, 4, 3], array_column($pages[0], 'uid'), 'Occurrences of a rule stay together.');
        self::assertCount(24, $pages[1], 'A rule larger than the budget is never split.');
        self::assertSame(count($issues), array_sum(array_map('count', $pages)));
    }

    #[Test]
    public function aRuleCardUsesThePlainRuleTitle(): void
    {
        $issue = $this->issue(1, 1, 'rendered.empty_heading') + ['ruleTitle' => 'Heading is empty'];
        $issue['displayTitle'] = 'Rendered heading is empty.';

        $groups = IssueRuleGroupingUtility::group([$issue], [$issue]);

        self::assertSame('Heading is empty', $groups['critical']['rules'][0]['title']);
    }

    #[Test]
    public function anEmptyListHasOneEmptyPage(): void
    {
        self::assertSame([[]], IssueRuleGroupingUtility::paginateByRule([], 10));
    }

    private function issue(int $uid, int $severity, string $ruleId, int $status = 0): array
    {
        return [
            'uid' => $uid,
            'severity' => $severity,
            'status' => $status,
            'rule_id' => $ruleId,
            'displayTitle' => $ruleId === 'rte.empty_link' ? 'Link has no accessible name.' : $ruleId,
            'guidance' => ['howToFix' => $ruleId === 'rte.empty_link' ? 'Provide link text.' : ''],
        ];
    }
}
