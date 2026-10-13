<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Backend\ToolbarItems;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Backend\ToolbarItems\ToolbarSummaryBuilder;

/**
 * The toolbar's Content scan and Frontend scan summaries.
 *
 * Regression guard (audit C2): the Content card said "Idle · clean" whenever the last scan found no NEW issue, while the
 * site still had 324 open issues. The headline is what is open now; the last scan's delta is secondary.
 */
final class ToolbarSummaryBuilderTest extends TestCase
{
    private function builder(): ToolbarSummaryBuilder
    {
        return new ToolbarSummaryBuilder(
            static fn (string $key, string $fallback): string => $fallback,
            static fn (int $timestamp): string => 'D' . $timestamp,
            static fn (int $timestamp): string => 'T' . $timestamp,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function finishedStatus(int $new = 0, int $resolved = 0, ?int $pageUid = null): array
    {
        return [
            'running' => false,
            'finishedAt' => 1000,
            'pageUid' => $pageUid,
            'summary' => ['issuesNew' => $new, 'issuesResolved' => $resolved, 'pagesScanned' => 25],
        ];
    }

    #[Test]
    public function openIssuesAreTheHeadlineEvenWhenTheLastScanFoundNothingNew(): void
    {
        $card = $this->builder()->contentCard(
            $this->finishedStatus(new: 0, resolved: 1),
            ['critical' => 189, 'warning' => 93, 'info' => 12, 'needs_review' => 30, 'total' => 324]
        );

        self::assertSame('open', $card['state']);
        self::assertSame('324 open', $card['tagLabel']);
        self::assertSame('critical', $card['tagTone']);
        self::assertSame('0 new and 1 resolved in the last scan', $card['delta']);
        self::assertStringNotContainsStringIgnoringCase('clean', $card['tagLabel'] . $card['delta']);
        self::assertSame('Site scan · D1000 · 25 pages', $card['meta']);
    }

    #[Test]
    public function aSiteWithoutOpenIssuesSaysSo(): void
    {
        $card = $this->builder()->contentCard(
            $this->finishedStatus(),
            ['critical' => 0, 'warning' => 0, 'info' => 0, 'needs_review' => 0, 'total' => 0]
        );

        self::assertSame('clear', $card['state']);
        self::assertSame('No open issues', $card['tagLabel']);
        self::assertSame('ok', $card['tagTone']);
    }

    #[Test]
    public function withoutReadableOpenTotalsTheCardMakesNoClaimAboutTheSite(): void
    {
        $card = $this->builder()->contentCard($this->finishedStatus(), null);

        self::assertSame('finished', $card['state']);
        self::assertSame('none', $card['tagTone']);
        self::assertStringNotContainsStringIgnoringCase('no open', $card['tagLabel']);
    }

    #[Test]
    public function aRunningContentScanShowsItsStartTime(): void
    {
        $card = $this->builder()->contentCard(['running' => true, 'startedAt' => 77], null);

        self::assertSame('running', $card['state']);
        self::assertSame('Scanning', $card['tagLabel']);
        self::assertSame('info', $card['tagTone']);
        self::assertSame('Started T77', $card['meta']);
    }

    #[Test]
    public function aFrontendScanReportsItsOccurrencesAndDelta(): void
    {
        $card = $this->builder()->frontendCard([
            'uid' => 5, 'status' => 'completed', 'scan_scope' => 'site', 'finished_at' => 2000,
            'issues_total' => 408, 'issues_new' => 0, 'issues_resolved' => 0,
            'pages_scanned' => 19, 'pages_total' => 19, 'pages_failed' => 0,
        ], ['available' => true, 'new' => 3, 'resolved' => 1]);

        self::assertSame('open', $card['state']);
        self::assertSame('408 occurrences', $card['tagLabel']);
        self::assertSame('3 new and 1 resolved in the last scan', $card['delta']);
        self::assertSame('Site scan · D2000 · 19 of 19 pages', $card['meta']);
    }

    #[Test]
    public function theCrawlersOwnNewCountNeverReachesTheFrontendCard(): void
    {
        // A re-run of an unchanged site: the crawler counted all 396 as new, the stored findings show no change.
        $scan = [
            'uid' => 7, 'status' => 'completed', 'scan_scope' => 'site', 'finished_at' => 2000,
            'issues_total' => 396, 'issues_new' => 396, 'issues_resolved' => 0,
            'pages_scanned' => 19, 'pages_total' => 19, 'pages_failed' => 0,
        ];

        self::assertSame('0 new and 0 resolved in the last scan', $this->builder()->frontendCard($scan, ['available' => true, 'new' => 0, 'resolved' => 0])['delta']);
        self::assertSame('', $this->builder()->frontendCard($scan, ['available' => false])['delta'], 'Without a compatible earlier scan nothing is claimed.');
        self::assertSame('', $this->builder()->frontendCard($scan)['delta']);
        self::assertSame('396 occurrences', $this->builder()->frontendCard($scan)['tagLabel']);
    }

    #[Test]
    public function aFrontendScanThatLoadedNoPageIsNotClear(): void
    {
        $card = $this->builder()->frontendCard([
            'uid' => 6, 'status' => 'completed', 'scan_scope' => 'page', 'finished_at' => 3000,
            'issues_total' => 0, 'pages_scanned' => 0, 'pages_total' => 1, 'pages_failed' => 1,
        ]);

        self::assertSame('no-coverage', $card['state']);
        self::assertSame('error', $card['tagTone']);
        self::assertSame('No page checked', $card['tagLabel']);
    }

    #[Test]
    public function theToolbarIconReflectsOpenContentIssues(): void
    {
        $builder = $this->builder();
        $state = $builder->toolbarState(
            $builder->contentCard($this->finishedStatus(), ['critical' => 2, 'warning' => 0, 'info' => 0, 'needs_review' => 0, 'total' => 2]),
            null
        );

        self::assertSame('issues', $state['state']);
        self::assertSame('critical', $state['tone']);
        self::assertSame(2, $state['count']);
        self::assertSame('Accessibility: 2 open content issues', $state['ariaLabel']);
    }
}
