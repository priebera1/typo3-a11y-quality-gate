<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Large-installation stress test (AQG 3.0.0, 80 sites / 16,500 pages): guards for root causes whose effect is a query
 * count or a query plan rather than a different answer (measured in the stress report; the answers themselves are
 * pinned by the functional tests in Tests/Functional/LargeInstallation).
 */
final class LargeInstallationRegressionTest extends TestCase
{
    private static function source(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    #[Test]
    public function findingsHaveAnIndexLedByThePageForPageScopedUpdates(): void
    {
        // MEDIUM-1: a content scan resolves open findings of each scanned page stored under unconfigured site
        // identifiers (IssueRepository::resolveOpenOfUnconfiguredSitesForPage(), WHERE page_uid = ? AND status = 0 AND
        // site_identifier NOT IN …). Without an index starting with page_uid it scanned the whole table once per page:
        // 6 s → 22 s per 200-page site at 248,000 findings.
        $sql = self::source('ext_tables.sql');
        self::assertMatchesRegularExpression('/CREATE TABLE tx_a11y_issue \((?:(?!CREATE TABLE).)*KEY idx_page_status \(page_uid, status\)/s', $sql);
        self::assertStringContainsString("\$qb->expr()->eq('page_uid'", self::source('Classes/Domain/Repository/IssueRepository.php'));
    }

    #[Test]
    public function pageDetailChecksEachRecordAndFieldOnlyOncePerResponse(): void
    {
        // MEDIUM-2: a page with 459 findings in 60 records ran FormEngine's data compiler 459 times (2.4 s of 2.9 s).
        $controller = self::source('Classes/Controller/PageDetailController.php');
        self::assertStringContainsString("\$fieldEditable[\$table . ':' . \$uid . ':' . \$field]\n                        ??= \$this->backendRecordAccessService->canEditRecordFields(\$table, \$uid, [\$field]);", $controller);
        self::assertStringContainsString("\$recordEditable[\$table . ':' . \$uid] ??= \$this->backendRecordAccessService->canEditRecord(\$table, \$uid);", $controller);
        self::assertStringNotContainsString("&& \$this->backendRecordAccessService->canEditRecord(\$sourceTable, \$sourceUid)", $controller);
    }

    #[Test]
    public function theToolbarItemBuildsItsSummaryOncePerRequest(): void
    {
        // MEDIUM-3: getItem() and getDropDown() each built the whole summary in the same backend request.
        $item = self::source('Classes/Backend/ToolbarItems/A11yScanToolbarItem.php');
        foreach (["\$this->once('localStatus'", "\$this->once('totals|'", "\$this->once('evidence|'", "\$this->once('remote|'"] as $memo) {
            self::assertStringContainsString($memo, $item);
        }
    }

    #[Test]
    public function myIssuesStreamsTheFindingsOfReadableSitesOnly(): void
    {
        // HIGH-1: every finding of every site was fetched into PHP (fatal at 256 MB for administrators and managers).
        $service = self::source('Classes/Assignment/AssignmentService.php');
        self::assertStringContainsString('$this->repository->iterateAggregatedFindings(', $service);
        self::assertStringContainsString('$this->readableSiteIdentifiers()', $service);
        self::assertStringNotContainsString('$findingRows = $this->repository->aggregateFindings(', $service);
    }
}
