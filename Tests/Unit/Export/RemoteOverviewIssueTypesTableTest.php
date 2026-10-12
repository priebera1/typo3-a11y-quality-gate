<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Export\RemoteExportBuilder;

/**
 * The Frontend overview PDF lists every stored issue type under "All issue types" so its totals reconcile with the
 * CSV when "What to fix first" is only the service's top ranking. A scan whose ranking already is every issue type
 * with the same occurrences printed the same rows twice (T13 page scan: one type in both tables).
 */
final class RemoteOverviewIssueTypesTableTest extends TestCase
{
    #[Test]
    public function aRankingThatAlreadyListsEveryIssueTypeIsNotRepeated(): void
    {
        $fixes = [['ruleId' => 'region', 'issuesTotal' => 2]];
        $all = [['ruleId' => 'region', 'occurrences' => 2]];

        self::assertFalse(RemoteExportBuilder::allIssueTypesAddInformation($fixes, $all));
    }

    #[Test]
    public function aTopRankingDifferentCountsOrNoRankingKeepTheFullList(): void
    {
        $all = [['ruleId' => 'image-alt', 'occurrences' => 20], ['ruleId' => 'region', 'occurrences' => 227]];

        self::assertTrue(RemoteExportBuilder::allIssueTypesAddInformation([['ruleId' => 'image-alt', 'issuesTotal' => 20]], $all), 'Top ranking only.');
        self::assertTrue(RemoteExportBuilder::allIssueTypesAddInformation([
            ['ruleId' => 'image-alt', 'issuesTotal' => 20],
            ['ruleId' => 'region', 'issuesTotal' => 228],
        ], $all), 'The ranking counted an occurrence that is not stored.');
        self::assertTrue(RemoteExportBuilder::allIssueTypesAddInformation([], $all), 'No ranking from the service.');
        self::assertFalse(RemoteExportBuilder::allIssueTypesAddInformation([], []), 'Nothing stored.');
    }
}
