<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Utility\RemoteRuleImpactGroupingUtility;

final class RemoteRuleImpactGroupingUtilityTest extends TestCase
{
    #[Test]
    public function rulesAreGroupedByImpactSoAMinorRuleNeverPrecedesASeriousOne(): void
    {
        $groups = RemoteRuleImpactGroupingUtility::group([
            $this->rule('image-alt', 'critical', 2, true),
            $this->rule('empty-heading', 'minor', 2),
            $this->rule('link-name', 'serious', 2),
            $this->rule('region', 'moderate', 20),
            $this->rule('custom-rule', '', 1),
        ]);

        self::assertSame(['critical', 'serious', 'moderate', 'minor', 'other'], array_column($groups, 'impact'));
        self::assertSame('aqg-impact-serious', $groups[1]['anchorId']);
        self::assertSame(1, $groups[1]['ruleCount']);
        self::assertSame('other', $groups[4]['rules'][0]['impactLevel']);
    }

    #[Test]
    public function withinAGroupTheTopRecommendationComesFirstThenTheMostOccurrences(): void
    {
        $groups = RemoteRuleImpactGroupingUtility::group([
            $this->rule('select-name', 'critical', 2),
            $this->rule('label', 'critical', 1),
            $this->rule('image-alt', 'critical', 1, true),
            $this->rule('button-name', 'critical', 2),
        ]);

        self::assertSame(['image-alt', 'select-name', 'button-name', 'label'], array_column($groups[0]['rules'], 'rule_id'));
        self::assertArrayNotHasKey('sortPosition', $groups[0]['rules'][0]);
    }

    #[Test]
    public function onlyTheRuleTheRecommendationNamesCarriesItsSteps(): void
    {
        $groups = RemoteRuleImpactGroupingUtility::group([
            $this->rule('image-alt', 'critical', 2, true),
            $this->rule('link-name', 'serious', 2),
        ], 'IMAGE-ALT');

        self::assertTrue($groups[0]['rules'][0]['hasRecommendationSteps']);
        self::assertFalse($groups[1]['rules'][0]['hasRecommendationSteps']);
        self::assertFalse(RemoteRuleImpactGroupingUtility::group([$this->rule('image-alt', 'critical', 1, true)])[0]['rules'][0]['hasRecommendationSteps']);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Audit C15/F9: the Highlighted screenshot labels each outline with its issue-type number, ordered like the PDF's
     * issue groups (impact, occurrences, rule id). The page view shows the same number on each rule, whatever order
     * the recommendation puts it in, so a label "3" points at the rule numbered 3.
     */
    #[Test]
    public function everyRuleCarriesTheIssueTypeNumberOfThePdfAndTheScreenshot(): void
    {
        $groups = RemoteRuleImpactGroupingUtility::group([
            $this->rule('region', 'moderate', 18),
            $this->rule('image-alt', 'critical', 2),
            $this->rule('label', 'critical', 1, true),
            $this->rule('select-name', 'critical', 2),
            $this->rule('empty-heading', 'minor', 2),
            $this->rule('custom-check', '', 3),
        ]);

        $numbers = [];
        foreach ($groups as $group) {
            foreach ($group['rules'] as $rule) {
                $numbers[$rule['rule_id']] = $rule['issueTypeNumber'];
            }
        }

        $expected = ['image-alt' => 1, 'select-name' => 2, 'label' => 3, 'region' => 4, 'empty-heading' => 5, 'custom-check' => 6];
        ksort($expected);
        ksort($numbers);
        self::assertSame($expected, $numbers);
        self::assertSame('label', $groups[0]['rules'][0]['rule_id'], 'The display order still leads with the recommendation.');
    }

    private function rule(string $ruleId, string $impact, int $count, bool $primary = false): array
    {
        return ['rule_id' => $ruleId, 'impact' => $impact, 'count' => $count, 'isPrimaryRecommendation' => $primary];
    }
}
