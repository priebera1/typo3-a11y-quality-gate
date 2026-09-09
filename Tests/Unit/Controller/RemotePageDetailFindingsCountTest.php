<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\RemotePageDetailController;
use ReflectionClass;
use ReflectionMethod;

/**
 * The Remote Page Detail hero must report findings for the selected remote page only.
 *
 * Regression guard: the header previously rendered tx_a11y_remote_scan.issues_total,
 * i.e. the crawl-wide finding count, so a 4-finding page advertised the whole site scan.
 */
final class RemotePageDetailFindingsCountTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../Resources/Private/Templates/RemotePageDetail/Show.html';

    #[Test]
    public function pageFindingsCountSumsNodeCountsOfThisPageOnly(): void
    {
        // Page A: 4 findings across 2 rules. A sibling page in the same scan is irrelevant here,
        // because only this page's issue rows are ever passed in.
        $pageA = $this->countPageFindings([
            ['uid' => 1, 'rule_id' => 'landmark-unique', 'nodes_count' => 3],
            ['uid' => 2, 'rule_id' => 'color-contrast', 'nodes_count' => 1],
        ]);

        self::assertSame(4, $pageA);
    }

    #[Test]
    public function differentPagesProduceDifferentCounts(): void
    {
        $pageA = $this->countPageFindings([
            ['uid' => 1, 'rule_id' => 'landmark-unique', 'nodes_count' => 4],
        ]);
        $pageB = $this->countPageFindings([
            ['uid' => 7, 'rule_id' => 'color-contrast', 'nodes_count' => 11],
            ['uid' => 8, 'rule_id' => 'link-name', 'nodes_count' => 2],
        ]);

        self::assertSame(4, $pageA);
        self::assertSame(13, $pageB);
        self::assertNotSame($pageA, $pageB);
    }

    #[Test]
    public function missingNodesCountCountsAsOneFinding(): void
    {
        self::assertSame(2, $this->countPageFindings([
            ['uid' => 1, 'rule_id' => 'a'],
            ['uid' => 2, 'rule_id' => 'b'],
        ]));
    }

    #[Test]
    public function negativeOrCorruptNodeCountsNeverReduceTheTotal(): void
    {
        self::assertSame(5, $this->countPageFindings([
            ['uid' => 1, 'rule_id' => 'a', 'nodes_count' => 5],
            ['uid' => 2, 'rule_id' => 'b', 'nodes_count' => -9],
        ]));
    }

    #[Test]
    public function pageWithoutFindingsReportsZeroAndNotTheScanTotal(): void
    {
        self::assertSame(0, $this->countPageFindings([]));
    }

    #[Test]
    public function pageWithoutPersistedIssueRowsFallsBackToPageScopedSummaryColumn(): void
    {
        // tx_a11y_remote_scan_page.issues_count is still page-scoped; the scan-wide total is not.
        self::assertSame(9, $this->countPageFindings([], 9));
    }

    #[Test]
    public function heroFindingsStatIsBoundToThePageScopedVariable(): void
    {
        $template = file_get_contents(self::TEMPLATE);
        self::assertIsString($template);

        $heroStrip = $this->heroStripMarkup($template);

        self::assertStringContainsString('{pageFindingsCount}', $heroStrip);
        self::assertStringNotContainsString(
            'remoteScan.issues_total',
            $heroStrip,
            'The Remote Page Detail hero must never render the crawl-wide issues_total.'
        );
    }

    #[Test]
    public function scanWideIssuesTotalIsNotRenderedAnywhereOnThePageDetailScreen(): void
    {
        $template = file_get_contents(self::TEMPLATE);
        self::assertIsString($template);

        self::assertStringNotContainsString('remoteScan.issues_total', $template);
        self::assertStringNotContainsString('remotePage.issues_total', $template);
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     */
    private function countPageFindings(array $issues, int $pageSummaryIssuesCount = 0): int
    {
        $method = new ReflectionMethod(RemotePageDetailController::class, 'countPageFindings');

        return (int)$method->invoke($this->subject(), $issues, $pageSummaryIssuesCount);
    }

    private function subject(): RemotePageDetailController
    {
        $subject = (new ReflectionClass(RemotePageDetailController::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(RemotePageDetailController::class, $subject);

        return $subject;
    }

    private function heroStripMarkup(string $template): string
    {
        $start = strpos($template, '<div class="aqg-hero__strip">');
        self::assertIsInt($start, 'Remote Page Detail hero strip not found.');

        $end = strpos($template, '</header>', $start);
        self::assertIsInt($end, 'Remote Page Detail header end not found.');

        return substr($template, $start, $end - $start);
    }
}
