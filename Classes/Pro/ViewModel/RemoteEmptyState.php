<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\ViewModel;

/**
 * Which empty state the Overview's "What to fix first" and "Affected pages" sections show for the scan on screen.
 *
 * An empty priority list is only an all-clear when the scan checked every page it expected; a scan that loaded no page
 * or missed pages must say so. The affected-pages list asks for a site scan only when no frontend scan is stored at all.
 */
final class RemoteEmptyState
{
    public const PRIORITY_CLEAR = 'clear';
    public const PRIORITY_INCOMPLETE = 'incomplete';
    public const PRIORITY_NO_COVERAGE = 'no-coverage';

    public const PAGES_NO_SCAN = 'no-scan';
    public const PAGES_RULE = 'rule';
    public const PAGES_NO_COVERAGE = 'no-coverage';
    public const PAGES_NOT_STORED = 'not-stored';

    public static function priorityFixes(?RemoteScanCoverage $coverage): string
    {
        if ($coverage === null || $coverage->hasNoLoadedPage()) {
            return self::PRIORITY_NO_COVERAGE;
        }

        return $coverage->isComplete() ? self::PRIORITY_CLEAR : self::PRIORITY_INCOMPLETE;
    }

    /**
     * For an empty affected-pages list: the list holds every page the scan loaded, so it is empty either because no
     * scan is stored, a rule filter matched nothing, no page could be loaded, or the loaded pages were not stored.
     */
    public static function affectedPages(bool $hasScan, ?RemoteScanCoverage $coverage, bool $hasRuleFilter): string
    {
        if (!$hasScan) {
            return self::PAGES_NO_SCAN;
        }
        if ($hasRuleFilter) {
            return self::PAGES_RULE;
        }
        if ($coverage === null || $coverage->hasNoLoadedPage()) {
            return self::PAGES_NO_COVERAGE;
        }

        return self::PAGES_NOT_STORED;
    }
}
