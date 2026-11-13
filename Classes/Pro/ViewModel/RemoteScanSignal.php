<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\ViewModel;

/**
 * The Overview's frontend signal: coverage first, then the API score tone, then impact and priority fixes.
 *
 * A scan that loaded no page is `no-coverage` and a scan with failed or unreached pages is `incomplete`; neither is ever
 * `good`, whatever the API scored. Only a complete scan keeps the API tone.
 */
final class RemoteScanSignal
{
    public const TONE_NO_SCAN = 'no-scan';
    public const TONE_NO_COVERAGE = 'no-coverage';
    public const TONE_INCOMPLETE = 'incomplete';
    public const TONE_CRITICAL = 'critical';
    public const TONE_ATTENTION = 'attention';
    public const TONE_GOOD = 'good';
    /**
     * A user restricted by the result policy (Sol R01/R02) sees part of a scan that loaded pages. Score, priority fixes
     * and crawler counters cover every page, so the view neither claims an all-clear nor "no page checked" (Sol QA-06).
     */
    public const TONE_RESTRICTED = 'restricted';
    /**
     * STATE-01: the crawler finished but the results are not stored yet (RemoteScanRecoveryService writes the completion
     * before it persists the pages): nothing is known about the findings until they are.
     */
    public const TONE_PENDING = 'pending';
    public const TONE_NOT_STORED = 'not-stored';

    /**
     * F11-02: `pending` / `not-stored` while a completed crawl's results are not in the local store (`persisted_at` 0),
     * else null. Storing that failed (`sync_error`) is not "being saved": it stays unsaved until a retry succeeds. Until
     * then nothing finding-derived — the crawler's priority fixes, WCAG summary, reporting groups, score, counters or
     * comparison — is a saved result to show (OverviewController gates every such view value on this).
     *
     * @param array<string, mixed> $unrestrictedScan the stored scan row (persistence fields)
     * @param array<string, mixed>|null $viewScan the row the view shows (its status), when it differs
     */
    public static function unsavedResultState(array $unrestrictedScan, ?array $viewScan = null): ?string
    {
        if (strtolower((string)(($viewScan ?? $unrestrictedScan)['status'] ?? '')) !== 'completed'
            || !array_key_exists('persisted_at', $unrestrictedScan)
            || (int)($unrestrictedScan['persisted_at'] ?? 0) > 0) {
            return null;
        }

        return trim((string)($unrestrictedScan['sync_error'] ?? '')) !== '' ? self::TONE_NOT_STORED : self::TONE_PENDING;
    }

    private function __construct(
        public readonly string $tone,
        public readonly bool $showScoreValue,
        public readonly ?RemoteScanCoverage $coverage,
    ) {
    }

    /**
     * @param array<string, mixed>|null $remoteScan
     * @param array<string, mixed> $score normalized score (RemoteReportingSummaryService)
     * @param array<string, mixed> $reportSummary
     */
    public static function resolve(
        ?array $remoteScan,
        array $score,
        bool $hasPriorityFixes,
        array $reportSummary,
        int $failedPageRows = 0,
        bool $restrictedView = false,
        ?array $unrestrictedScan = null,
    ): self {
        if (!is_array($remoteScan)) {
            return new self(self::TONE_NO_SCAN, false, null);
        }
        $storage = self::unsavedResultState($unrestrictedScan ?? $remoteScan, $remoteScan);
        if ($storage !== null) {
            return new self($storage, false, null);
        }

        $coverage = RemoteScanCoverage::fromScanRow($remoteScan, $failedPageRows);
        if ($restrictedView) {
            // Only the stored scan's own counters tell whether it loaded pages; they reveal nothing about results the
            // user may not see. Every other restricted state is the same neutral one.
            $scanCoverage = RemoteScanCoverage::fromScanRow($unrestrictedScan ?? $remoteScan);

            return $scanCoverage->hasNoLoadedPage()
                ? new self(self::TONE_NO_COVERAGE, false, $scanCoverage)
                : new self(self::TONE_RESTRICTED, false, $coverage);
        }
        $hasScoreValue = (bool)($score['hasValue'] ?? false);

        if ($coverage->hasNoLoadedPage()) {
            return new self(self::TONE_NO_COVERAGE, false, $coverage);
        }

        if (!$coverage->isComplete()) {
            return new self(self::TONE_INCOMPLETE, $hasScoreValue, $coverage);
        }

        $scoreTone = strtolower(trim((string)($score['tone'] ?? '')));
        if (in_array($scoreTone, [self::TONE_CRITICAL, self::TONE_ATTENTION, self::TONE_GOOD], true)) {
            return new self($scoreTone, $hasScoreValue, $coverage);
        }

        if (strtolower(trim((string)($reportSummary['overallImpact'] ?? ''))) === 'critical') {
            return new self(self::TONE_CRITICAL, $hasScoreValue, $coverage);
        }

        return new self($hasPriorityFixes ? self::TONE_ATTENTION : self::TONE_GOOD, $hasScoreValue, $coverage);
    }
}
