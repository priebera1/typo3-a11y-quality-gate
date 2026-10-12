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
    ): self {
        if (!is_array($remoteScan)) {
            return new self(self::TONE_NO_SCAN, false, null);
        }

        $coverage = RemoteScanCoverage::fromScanRow($remoteScan, $failedPageRows);
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
