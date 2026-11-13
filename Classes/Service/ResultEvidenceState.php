<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

/**
 * STATE-01: what AQG may say about a page's or a site's results, for both scan sources (Content and Frontend).
 *
 * A count of visible open findings describes what the user can see. It never proves on its own that the scope was
 * checked, that the results are stored, or that the user sees every result. Only `clean` may be shown as "No issues
 * found" (green): a completed scan whose results are stored, which covered the scope, read by a user who sees every result
 * of it. Every other zero is said as what it is.
 *
 * `restricted` depends on the user's rights only (never on whether hidden findings exist), so it reveals nothing about
 * withheld results: users other than administrators see the results they may read, the same model as the frontend views
 * (RemoteScanSignal::TONE_RESTRICTED, ToolbarSummaryBuilder's restricted card).
 */
final class ResultEvidenceState
{
    public const UNSCANNED = 'unscanned';
    public const RUNNING = 'running';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const PENDING = 'pending';
    public const INCOMPLETE = 'incomplete';
    public const RESTRICTED = 'restricted';
    public const CLEAN = 'clean';
    public const FINDINGS = 'findings';

    /**
     * @param bool $hasEvidence a scan of the scope finished (or findings are stored for it)
     * @param bool $pending the scan completed but its results are not stored yet
     * @param bool $complete the scan covered the whole scope (every page admitted, checked and loaded)
     * @param bool $restricted the user may not see every result of the scope (rights only, see the class comment)
     * @param int $visibleOpen open findings (or issue types) the user may see
     */
    public static function resolve(
        bool $running,
        bool $hasEvidence,
        int $visibleOpen,
        bool $restricted,
        bool $complete = true,
        bool $pending = false,
        bool $failed = false,
        bool $cancelled = false,
    ): string {
        if ($running) {
            return self::RUNNING;
        }
        if ($visibleOpen > 0) {
            return self::FINDINGS;
        }
        if (!$hasEvidence) {
            return self::UNSCANNED;
        }
        if ($failed) {
            return self::FAILED;
        }
        if ($cancelled) {
            return self::CANCELLED;
        }
        if ($pending) {
            return self::PENDING;
        }
        if (!$complete) {
            return self::INCOMPLETE;
        }
        if ($restricted) {
            return self::RESTRICTED;
        }

        return self::CLEAN;
    }

    /** A zero that is not a verified clean result: show it neutrally, never as "No issues found". */
    public static function isUnverifiedZero(string $state): bool
    {
        return in_array($state, [self::FAILED, self::CANCELLED, self::PENDING, self::INCOMPLETE, self::RESTRICTED], true);
    }
}
