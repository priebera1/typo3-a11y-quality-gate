<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\ReleaseQa;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Backend\ToolbarItems\ToolbarSummaryBuilder;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Pro\ViewModel\RemoteScanSignal;
use Priebera\A11yQualityGate\Service\ResultEvidenceState;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * SOL audit STATE-01: a permission-filtered zero never proves a clean result on its own. The shared contract
 * (ResultEvidenceState) and its consumers in the toolbar and the frontend Overview signal.
 */
final class SolAuditEvidenceStateTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    #[Test]
    public function onlyAVerifiedCompleteStoredScanReadInFullIsClean(): void
    {
        self::assertSame(ResultEvidenceState::CLEAN, ResultEvidenceState::resolve(false, true, 0, false));
        self::assertSame(ResultEvidenceState::RESTRICTED, ResultEvidenceState::resolve(false, true, 0, true), 'hidden or partially visible');
        self::assertSame(ResultEvidenceState::INCOMPLETE, ResultEvidenceState::resolve(false, true, 0, false, complete: false));
        self::assertSame(ResultEvidenceState::PENDING, ResultEvidenceState::resolve(false, true, 0, false, pending: true));
        self::assertSame(ResultEvidenceState::FAILED, ResultEvidenceState::resolve(false, true, 0, false, failed: true));
        self::assertSame(ResultEvidenceState::CANCELLED, ResultEvidenceState::resolve(false, true, 0, false, cancelled: true));
        self::assertSame(ResultEvidenceState::UNSCANNED, ResultEvidenceState::resolve(false, false, 0, false));
        self::assertSame(ResultEvidenceState::RUNNING, ResultEvidenceState::resolve(true, true, 3, true));
        self::assertSame(ResultEvidenceState::FINDINGS, ResultEvidenceState::resolve(false, true, 2, true, complete: false), 'visible findings stay findings');
        foreach ([ResultEvidenceState::RESTRICTED, ResultEvidenceState::INCOMPLETE, ResultEvidenceState::PENDING, ResultEvidenceState::FAILED, ResultEvidenceState::CANCELLED] as $state) {
            self::assertTrue(ResultEvidenceState::isUnverifiedZero($state), $state);
        }
        self::assertFalse(ResultEvidenceState::isUnverifiedZero(ResultEvidenceState::CLEAN));
    }

    #[Test]
    public function wholeSiteCoverageNeedsARootScanThatLeftNothingOut(): void
    {
        $scan = ['status' => 2, 'scope' => 'subtree', 'root_pid' => 1, 'pages_not_permitted' => 0, 'rendered_skipped' => 0];
        self::assertTrue(ScanRepository::coversWholeSite($scan, 1));
        self::assertFalse(ScanRepository::coversWholeSite(['root_pid' => 5] + $scan, 1), 'a subtree below the root');
        self::assertFalse(ScanRepository::coversWholeSite(['scope' => 'page'] + $scan, 1), 'a page scan');
        self::assertFalse(ScanRepository::coversWholeSite(['pages_not_permitted' => 2, 'not_permitted_json' => '[3,4]'] + $scan, 1), 'pages left out (AUTH-01)');
        self::assertFalse(ScanRepository::coversWholeSite(['rendered_skipped' => 1, 'rendered_skipped_json' => '[{"pageUid":3,"statusCode":0,"message":"x","reason":""}]'] + $scan, 1), 'a failed fetch');
        self::assertTrue(ScanRepository::coversWholeSite(['rendered_skipped' => 1, 'rendered_skipped_json' => '[{"pageUid":3,"statusCode":0,"message":"x","reason":"hidden"}]'] + $scan, 1), 'a page visitors cannot open is skipped by design');
        self::assertFalse(ScanRepository::coversWholeSite(null, 1));
        self::assertTrue(ScanRepository::excludesPage(['pages_not_permitted' => 1, 'not_permitted_json' => '[7]'], 7));
        self::assertFalse(ScanRepository::excludesPage(['pages_not_permitted' => 1, 'not_permitted_json' => '[7]'], 8));
        self::assertTrue(ScanRepository::excludesPage(['pages_not_permitted' => 1, 'not_permitted_json' => 'broken'], 8), 'unreadable list: fail closed');
    }

    #[Test]
    public function theToolbarContentCardIsClearOnlyForAVerifiedZero(): void
    {
        $builder = $this->builder();
        $status = ['running' => false, 'finishedAt' => 1_700_000_000, 'summary' => ['issuesNew' => 0]];
        $zero = ['critical' => 0, 'warning' => 0, 'info' => 0, 'needs_review' => 0, 'total' => 0];

        self::assertSame('clear', $builder->contentCard($status, $zero, ResultEvidenceState::CLEAN)['state']);
        $restricted = $builder->contentCard($status, $zero, ResultEvidenceState::RESTRICTED);
        self::assertSame('restricted', $restricted['state']);
        self::assertStringNotContainsString('No open issues', (string)$restricted['tagLabel']);
        self::assertSame('incomplete', $builder->contentCard($status, $zero, ResultEvidenceState::INCOMPLETE)['state']);
        self::assertSame('open', $builder->contentCard($status, ['critical' => 1, 'total' => 1] + $zero, ResultEvidenceState::RESTRICTED)['state'], 'visible findings');
        $withheld = $builder->contentCard(['summary' => ['issuesNew' => 0, 'countsWithheld' => true]] + $status, $zero, ResultEvidenceState::RESTRICTED);
        self::assertStringNotContainsString('new', (string)$withheld['summary'], 'withheld counters are not shown as 0 new');
    }

    #[Test]
    public function theGlobalIconIsGreenOnlyWhenEveryShownCardIsVerifiedClean(): void
    {
        $builder = $this->builder();
        $status = ['running' => false, 'finishedAt' => time(), 'summary' => []];
        $zero = ['critical' => 0, 'warning' => 0, 'info' => 0, 'needs_review' => 0, 'total' => 0];
        $clean = $builder->contentCard($status, $zero, ResultEvidenceState::CLEAN);
        $restricted = $builder->contentCard($status, $zero, ResultEvidenceState::RESTRICTED);
        $frontendClear = $builder->frontendCard(['uid' => 1, 'status' => 'completed', 'persisted_at' => 5, 'pages_scanned' => 2, 'pages_total' => 2, 'pages_failed' => 0, 'issues_total' => 0, 'finished_at' => time()]);
        $frontendRestricted = $builder->frontendCard(['uid' => 1, 'status' => 'completed', 'persisted_at' => 5, 'pages_scanned' => 2, 'issues_total' => 0, 'aqg_results_restricted' => true, 'aqg_scan_loaded_pages' => true, 'finished_at' => time()]);

        self::assertSame('ok', $builder->toolbarState($clean, $frontendClear)['state']);
        self::assertSame('limited', $builder->toolbarState($restricted, $frontendClear)['state'], 'restricted Content card');
        self::assertSame('limited', $builder->toolbarState($clean, $frontendRestricted)['state'], 'restricted Frontend card (F9: green)');
        self::assertSame('limited', $builder->toolbarState($builder->contentCard($status, null), null)['state'], '"Scan finished" without a count');
    }

    #[Test]
    public function aCompletedFrontendScanWhoseResultsAreNotStoredIsPendingNotClear(): void
    {
        $builder = $this->builder();
        $scan = ['uid' => 1, 'status' => 'completed', 'persisted_at' => 0, 'pages_scanned' => 1, 'pages_total' => 1, 'pages_failed' => 0, 'issues_total' => 0, 'finished_at' => time()];
        $card = $builder->frontendCard($scan);
        self::assertSame('pending', $card['state'], 'F9: "No issues found"');
        self::assertSame('limited', $builder->toolbarState($builder->contentCard(['running' => false, 'finishedAt' => time(), 'summary' => []], ['critical' => 0, 'warning' => 0, 'info' => 0, 'needs_review' => 0, 'total' => 0], ResultEvidenceState::CLEAN), $card)['state']);
        self::assertSame('clear', $builder->frontendCard(['persisted_at' => 9] + $scan)['state'], 'positive control: stored');
        $notStored = $builder->frontendCard(['sync_error' => 'x'] + $scan);
        self::assertSame('failed', $notStored['state']);
        self::assertSame('The last frontend scan finished, but its results could not be saved.', $notStored['delta'], 'F11 D3: it did finish');
        self::assertSame('The last frontend scan did not finish.', $builder->frontendCard(['status' => 'failed'] + $scan)['delta']);
        $head = (string)file_get_contents(dirname(__DIR__, 3) . '/Resources/Private/Partials/Overview/RemoteStatusHead.html');
        self::assertStringContainsString("<f:if condition=\"{remoteSignalTone} != 'pending' && {remoteSignalTone} != 'not-stored'\">", $head, 'F11 D3: no crawler-reported counts before the results are stored');
    }

    #[Test]
    public function theFrontendOverviewSignalIsPendingUntilTheResultsAreStored(): void
    {
        $scan = ['uid' => 1, 'status' => 'completed', 'persisted_at' => 0, 'pages_scanned' => 1, 'pages_total' => 1, 'pages_failed' => 0, 'issues_total' => 0];
        // F9: `good` (and "No priority fixes needed").
        self::assertSame(RemoteScanSignal::TONE_PENDING, RemoteScanSignal::resolve($scan, ['tone' => 'good', 'hasValue' => true], false, [])->tone);
        self::assertSame(RemoteScanSignal::TONE_PENDING, RemoteScanSignal::resolve($scan, [], false, [], 0, true, $scan)->tone);
        self::assertSame(RemoteScanSignal::TONE_GOOD, RemoteScanSignal::resolve(['persisted_at' => 3] + $scan, ['tone' => 'good', 'hasValue' => true], false, [])->tone, 'positive control');
        self::assertSame(RemoteScanSignal::TONE_RESTRICTED, RemoteScanSignal::resolve(['persisted_at' => 3] + $scan, [], false, [], 0, true, ['persisted_at' => 3] + $scan)->tone, 'restricted branch preserved');
        self::assertSame(RemoteScanSignal::TONE_NOT_STORED, RemoteScanSignal::resolve(['sync_error' => 'x'] + $scan, [], false, [])->tone, 'a failed store is not "being saved"');
    }

    private function builder(): ToolbarSummaryBuilder
    {
        return new ToolbarSummaryBuilder(
            static fn (string $key, string $fallback): string => $fallback,
            static fn (int $timestamp): string => 'D' . $timestamp,
            static fn (int $timestamp): string => 'T' . $timestamp,
        );
    }
}
