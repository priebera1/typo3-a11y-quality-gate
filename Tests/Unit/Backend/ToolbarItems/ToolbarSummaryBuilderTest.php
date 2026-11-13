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
    public function aWithheldResolvedCountReportsOnlyTheNewOnes(): void
    {
        $status = $this->finishedStatus(new: 0, resolved: 0);
        $status['summary']['issuesNew'] = 4;
        $status['summary']['issuesResolved'] = null;

        $card = $this->builder()->contentCard($status, ['critical' => 0, 'warning' => 4, 'info' => 0, 'needs_review' => 0, 'total' => 4]);

        self::assertSame('4 new in the last scan', $card['delta']);
    }

    #[Test]
    public function aWithheldNewCountMakesNoDeltaClaim(): void
    {
        $status = $this->finishedStatus(new: 0, resolved: 0);
        $status['summary']['issuesNew'] = null;
        $status['summary']['issuesResolved'] = null;

        $card = $this->builder()->contentCard($status, ['critical' => 0, 'warning' => 4, 'info' => 0, 'needs_review' => 0, 'total' => 4]);

        self::assertSame('', $card['delta']);
        self::assertSame('4 open', $card['tagLabel']);
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

    /** Sol QA-06: a restricted card neither says "No page checked" nor "No issues found" for a scan that loaded pages. */
    #[Test]
    public function aRestrictedFrontendCardIsNeutral(): void
    {
        $base = ['uid' => 6, 'status' => 'completed', 'scan_scope' => 'site', 'finished_at' => 3000, 'pages_failed' => 0,
            'aqg_results_restricted' => true, 'aqg_scan_loaded_pages' => true];

        $none = $this->builder()->frontendCard($base + ['issues_total' => 0, 'pages_scanned' => 0, 'pages_total' => 0]);
        self::assertSame('restricted', $none['state']);
        self::assertSame('Limited to your access', $none['tagLabel']);
        self::assertSame('No results of this scan are available to you.', $none['delta']);
        self::assertSame(['Limited to your access', 'info', ''], [$none['statusLabel'], $none['statusTone'], $none['summary']], 'the collapsed section claims no result');

        $some = $this->builder()->frontendCard($base + ['issues_total' => 3, 'pages_scanned' => 1, 'pages_total' => 1]);
        self::assertSame('open', $some['state']);
        self::assertSame('Only results your permissions allow are shown.', $some['delta']);

        $unloaded = $this->builder()->frontendCard(['aqg_scan_loaded_pages' => false] + $base + ['issues_total' => 0, 'pages_scanned' => 0, 'pages_total' => 0]);
        self::assertSame('no-coverage', $unloaded['state']);
    }

    #[Test]
    public function aCompletedScanWithIssuesIsCompleteNotAnErrorAndSummarisesNewAndOpen(): void
    {
        $card = $this->builder()->contentCard(
            ['running' => false, 'finishedAt' => time() - 60, 'summary' => ['issuesNew' => 2, 'issuesResolved' => 0, 'pagesScanned' => 3]],
            ['critical' => 5, 'warning' => 295, 'info' => 0, 'needs_review' => 0, 'total' => 300]
        );

        self::assertSame('Complete', $card['statusLabel']);
        self::assertSame('ok', $card['statusTone'], 'issues are counted in the summary; the status is the scan\'s own');
        self::assertSame('300 open on the website · 2 new', $card['summary']);
    }

    #[Test]
    public function runningFailedNotScannedAndOldScansHaveTheirOwnStatus(): void
    {
        $builder = $this->builder();

        $running = $builder->contentCard(['running' => true, 'startedAt' => 50], null);
        self::assertSame(['Running', 'info', 'Started T50'], [$running['statusLabel'], $running['statusTone'], $running['summary']]);

        $failed = $builder->contentCard(['running' => false, 'finishedAt' => time(), 'error' => 'x', 'summary' => []], null);
        self::assertSame(['Failed', 'error'], [$failed['statusLabel'], $failed['statusTone']]);

        $none = $builder->contentCard([], null);
        self::assertSame(['Not scanned', 'none', ''], [$none['statusLabel'], $none['statusTone'], $none['summary']]);

        $old = $builder->contentCard($this->finishedStatus(), ['critical' => 0, 'warning' => 1, 'info' => 0, 'needs_review' => 0, 'total' => 1]);
        self::assertSame(['Out of date', 'warning'], [$old['statusLabel'], $old['statusTone']]);
    }

    #[Test]
    public function theFrontendSummaryCountsOccurrencesAndNewOnlyWithAComparison(): void
    {
        $scan = ['uid' => 4, 'status' => 'completed', 'finished_at' => time(), 'pages_scanned' => 10, 'pages_total' => 10, 'issues_total' => 42];

        self::assertSame('42 occurrences', $this->builder()->frontendCard($scan)['summary']);
        self::assertSame('42 occurrences · 3 new', $this->builder()->frontendCard($scan, ['available' => true, 'new' => 3, 'resolved' => 1])['summary']);
        self::assertSame('Complete', $this->builder()->frontendCard($scan)['statusLabel']);

        $runningScan = ['uid' => 5, 'status' => 'running', 'started_at' => 70, 'pages_scanned' => 2, 'pages_total' => 8];
        $running = $this->builder()->frontendCard($runningScan);
        self::assertSame(['Running', 'info', 'Started T70 · 2 of 8 pages'], [$running['statusLabel'], $running['statusTone'], $running['summary']]);
    }

    #[Test]
    public function withoutFrontendResultsTheSectionIsNeutralAndClaimsNothing(): void
    {
        $card = $this->builder()->frontendUnavailableCard();

        self::assertSame(['frontend', 'unavailable', 'none', ''], [$card['key'], $card['state'], $card['statusTone'], $card['summary']]);
        self::assertSame('Not available here', $card['statusLabel']);
    }

    #[Test]
    public function theSummaryNamesTheWebsiteScopeAndLeavesOutZeroNew(): void
    {
        // Owner review 2026-11-10 (TYPO3 14, page "Resources"): "0 new · 14 open" next to a Page module with "1 warning,
        // 1 needs review" read as if the 14 were on that page. The 14 are the website's open content issues.
        $card = $this->builder()->contentCard(
            $this->finishedStatus(new: 0, resolved: 0, pageUid: 761) + ['pageTitle' => 'Resources'],
            ['critical' => 0, 'warning' => 9, 'info' => 1, 'needs_review' => 4, 'total' => 14],
            null,
            ['critical' => 0, 'warning' => 1, 'info' => 0, 'needs_review' => 1],
        );

        self::assertSame('14 open on the website', $card['summary']);
        self::assertSame('This page: 2 open · 1 warning · 1 needs review', $card['pageLine']);
        self::assertSame('0 new and 0 resolved in the last scan', $card['delta']);
        self::assertStringStartsWith('Page scan: “Resources” · D1000', $card['meta']);
        self::assertStringContainsString('all pages you can access', $card['scopeNote']);
    }

    #[Test]
    public function thePageLineAppearsOnlyForAnOpenPageWithOpenIssues(): void
    {
        $totals = ['critical' => 1, 'warning' => 0, 'info' => 0, 'needs_review' => 0, 'total' => 1];
        $builder = $this->builder();

        self::assertSame('', $builder->contentCard($this->finishedStatus(), $totals)['pageLine'], 'no page open');
        self::assertSame('', $builder->contentCard($this->finishedStatus(), $totals, null, ['critical' => 0, 'warning' => 0, 'info' => 0, 'needs_review' => 0])['pageLine'], 'a zero is not claimed as clean');
        self::assertSame('This page: 3 open · 1 critical · 2 warnings', $builder->contentCard($this->finishedStatus(), $totals, null, ['critical' => 1, 'warning' => 2, 'info' => 0])['pageLine']);
        self::assertSame('', $builder->contentCard($this->finishedStatus(), null, null, ['critical' => 1, 'warning' => 0, 'info' => 0])['pageLine'], 'no readable site: no claim');
        self::assertSame('', $builder->contentCard(['running' => true, 'startedAt' => 5], $totals, null, ['critical' => 1, 'warning' => 0, 'info' => 0])['pageLine'], 'running');
    }

    #[Test]
    public function anOutOfDateScanSaysWhyAndTheFrontendCardNamesItsOwnScope(): void
    {
        $scan = ['uid' => 4, 'status' => 'completed', 'finished_at' => time() - 20 * 86400, 'pages_scanned' => 10, 'pages_total' => 10, 'issues_total' => 5];
        $card = $this->builder()->frontendCard($scan);

        self::assertSame(['Out of date', '5 occurrences'], [$card['statusLabel'], $card['summary']]);
        self::assertSame('Older than 14 days. Later changes are not included.', $card['staleNote']);
        self::assertStringContainsString('counted per affected element', $card['scopeNote']);

        $fresh = $this->builder()->frontendCard(['finished_at' => time()] + $scan);
        self::assertSame('', $fresh['staleNote']);
    }
}
