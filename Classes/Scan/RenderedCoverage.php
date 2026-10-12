<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scan;

/**
 * Reads the rendered-check coverage a Content scan recorded (audit C13): which pages' rendered HTML was not checked,
 * and why. Scans from before 1.9.9 carry no record and report nothing.
 */
final class RenderedCoverage
{
    /**
     * Label keys of the visitor restrictions a skipped entry can carry (RenderedPageTypeGuard::RESTRICTION_*).
     */
    private const REASON_LABELS = [
        'hidden' => ['renderedCoverage.reason.hidden', 'Hidden page'],
        'scheduled' => ['renderedCoverage.reason.scheduled', 'Outside its start or stop time'],
        'restricted' => ['renderedCoverage.reason.restricted', 'Limited to frontend user groups'],
    ];

    /**
     * The reason one page was not checked, for the editor: "Hidden page · HTTP 404", "HTTP 500" or the fetch message.
     *
     * @param array{statusCode?:int,message?:string,reason?:string} $entry
     * @param \Closure(string, string): string $translate label key and English fallback to the label
     */
    public static function describe(array $entry, \Closure $translate): string
    {
        $statusCode = (int)($entry['statusCode'] ?? 0);
        // "HTTP 404" stays on one line.
        $status = $statusCode > 0
            ? str_replace(' ', "\u{00A0}", sprintf($translate('overview.local.renderedSkipped.httpStatus', 'HTTP %d'), $statusCode))
            : '';
        $reason = (string)($entry['reason'] ?? '');
        if (isset(self::REASON_LABELS[$reason])) {
            $label = $translate(...self::REASON_LABELS[$reason]);

            return $status !== '' ? $label . ' · ' . $status : $label;
        }

        return $status !== '' ? $status : trim((string)($entry['message'] ?? ''));
    }

    /**
     * @param array<string, mixed>|null $scan a tx_a11y_scan row
     */
    public static function countNotPublic(?array $scan): int
    {
        $entries = json_decode((string)($scan['rendered_skipped_json'] ?? ''), true);

        return count(array_filter(
            is_array($entries) ? $entries : [],
            static fn (mixed $entry): bool => is_array($entry) && isset(self::REASON_LABELS[(string)($entry['reason'] ?? '')])
        ));
    }

    /**
     * @param array<string, mixed> $scan a tx_a11y_scan row
     * @return array{pageUid:int,statusCode:int,message:string,reason:string}|null
     */
    public static function skippedEntryFor(array $scan, int $pageUid): ?array
    {
        if ((int)($scan['rendered_skipped'] ?? 0) <= 0 || $pageUid <= 0) {
            return null;
        }

        $entries = json_decode((string)($scan['rendered_skipped_json'] ?? ''), true);
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (is_array($entry) && (int)($entry['pageUid'] ?? 0) === $pageUid) {
                return [
                    'pageUid' => $pageUid,
                    'statusCode' => (int)($entry['statusCode'] ?? 0),
                    'message' => trim((string)($entry['message'] ?? '')),
                    'reason' => (string)($entry['reason'] ?? ''),
                ];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $first
     * @param array<string, mixed>|null $second
     * @return array<string, mixed>|null
     */
    public static function newerScan(?array $first, ?array $second): ?array
    {
        if ($first === null || $second === null) {
            return $first ?? $second;
        }

        return (int)($first['finished_at'] ?? 0) >= (int)($second['finished_at'] ?? 0) ? $first : $second;
    }
}
