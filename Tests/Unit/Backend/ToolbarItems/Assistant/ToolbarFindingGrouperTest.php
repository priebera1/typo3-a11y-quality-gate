<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Backend\ToolbarItems\Assistant;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\ToolbarFindingGrouper;

/**
 * Regression guard for the toolbar UX review (TYPO3 14): four cards repeated the same landmark issue. Identical
 * findings form one group with its real occurrence count; other issue types keep their own group.
 */
final class ToolbarFindingGrouperTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function issue(int $uid, string $rule, int $severity, string $type = 'rendered', int $record = 0): array
    {
        return ['uid' => $uid, 'rule_id' => $rule, 'severity' => $severity, 'source_type' => $type, 'source_table' => $record > 0 ? 'tt_content' : 'pages', 'source_uid' => $record > 0 ? $record : 5];
    }

    #[Test]
    public function identicalFindingsBecomeOneGroupWithTheirCount(): void
    {
        $groups = ToolbarFindingGrouper::group([
            self::issue(1, 'rendered.landmark_unique', 2),
            self::issue(2, 'rendered.landmark_unique', 2),
            self::issue(3, 'rendered.landmark_unique', 2),
            self::issue(4, 'rendered.landmark_unique', 2),
            self::issue(5, 'rte.img_alt_missing', 1, 'rte', 10),
        ]);

        self::assertCount(2, $groups);
        self::assertSame('rte.img_alt_missing', $groups[0]['ruleId'], 'the critical type leads although it has fewer occurrences');
        self::assertSame(4, $groups[1]['count']);
        self::assertSame(1, $groups[1]['records']);
        self::assertSame(5, array_sum(array_column($groups, 'count')), 'no occurrence is dropped');
    }

    #[Test]
    public function theSameRuleFromAnotherSourceIsAnotherGroup(): void
    {
        $groups = ToolbarFindingGrouper::group([
            self::issue(1, 'heading.order', 2, 'rendered'),
            self::issue(2, 'heading.order', 2, 'rte', 10),
            self::issue(3, 'heading.order', 2, 'rte', 11),
        ]);

        self::assertSame(['heading.order|content', 'heading.order|rendered'], array_column($groups, 'key'));
        self::assertSame(2, $groups[0]['records']);
        self::assertSame(1, ToolbarFindingGrouper::issueTypes(array_merge(...array_column($groups, 'issues'))));
    }

    #[Test]
    public function aGroupIsLedByItsMostSevereOccurrenceAndOrderedBySeverityThenSize(): void
    {
        $groups = ToolbarFindingGrouper::group([
            self::issue(7, 'a.rule', 3),
            self::issue(8, 'b.rule', 2),
            self::issue(9, 'b.rule', 2),
            self::issue(10, 'c.rule', 2),
            self::issue(11, 'a.rule', 1),
        ]);

        self::assertSame(['a.rule', 'b.rule', 'c.rule'], array_column($groups, 'ruleId'));
        self::assertSame(11, $groups[0]['issues'][0]['uid'], 'the critical occurrence carries the actions');
        self::assertSame(1, $groups[0]['severity']);
    }

    #[Test]
    public function nothingIsGroupedFromNothing(): void
    {
        self::assertSame([], ToolbarFindingGrouper::group([]));
        self::assertSame(0, ToolbarFindingGrouper::issueTypes([]));
    }
}
