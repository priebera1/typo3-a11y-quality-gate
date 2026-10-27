<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

/**
 * What a stored frontend-scan page can show instead of — or as — its screenshot.
 *
 * Screenshots are kept by the AQG service for a limited time set by the service per plan (counted from the scan); the
 * scan's findings, counts, scores and comparisons stored here are not. The crawler reports per page whether a screenshot is
 * available, was deleted after its retention period (`expired`) or by an authorised deletion (`deleted`), could not
 * be captured (`failed`) or was never taken (`not_captured`), plus when an available one ends. This class turns the
 * stored facts into the state the page shows, without asking the service: a screenshot past its reported end is
 * `expired` already, so the view never offers an image that can no longer load. A screenshot that should exist but
 * cannot be loaded (storage, licence, network) is decided by the proxy at load time — never shown as an expiry. Without
 * a reported reason the state is `unknown` (no screenshot stored), never a guess.
 */
final class RemoteScreenshotState
{
    public const AVAILABLE = 'available';
    public const EXPIRED = 'expired';
    public const DELETED = 'deleted';
    public const FAILED = 'failed';
    public const NOT_CAPTURED = 'not_captured';
    public const UNAVAILABLE = 'unavailable';
    /** No image stored and no reason known: a page stored before the crawler reported screenshot states. */
    public const UNKNOWN = 'unknown';

    private const REPORTED = [self::AVAILABLE, self::EXPIRED, self::DELETED, self::FAILED, self::NOT_CAPTURED];

    /** A crawler-reported state, or '' for an older crawler / anything unknown. */
    public static function normalizeReported(mixed $state): string
    {
        $value = is_string($state) ? strtolower(trim($state)) : '';

        return in_array($value, self::REPORTED, true) ? $value : '';
    }

    /** An ISO 8601 instant as a Unix timestamp, 0 when absent or unreadable. */
    public static function timestampOf(mixed $value): int
    {
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }
        $timestamp = strtotime($value);

        return $timestamp === false || $timestamp <= 0 ? 0 : $timestamp;
    }

    /**
     * @param array<string, mixed> $remotePage a tx_a11y_remote_scan_page row
     */
    public static function resolve(array $remotePage, int $now): string
    {
        $hasPath = trim((string)($remotePage['screenshot_path'] ?? '')) !== '';
        $reported = self::normalizeReported($remotePage['screenshot_state'] ?? null);
        $expiresAt = (int)($remotePage['screenshot_expires_at'] ?? 0);

        if ($hasPath) {
            return $expiresAt > 0 && $expiresAt <= $now ? self::EXPIRED : self::AVAILABLE;
        }

        // Only the crawler's own report names a reason. A page stored before it reported one (or with a value this
        // version does not know) is "no screenshot stored", never presented as not captured or expired.
        return match ($reported) {
            self::EXPIRED, self::DELETED, self::FAILED, self::NOT_CAPTURED => $reported,
            default => self::UNKNOWN,
        };
    }

    /**
     * The state the proxy reports when the AQG service refused a screenshot: from its error code, never from the
     * HTTP status alone. Only the service's explicit retention/deletion codes mean expired/deleted.
     */
    public static function fromServiceError(int $httpStatus, string $errorCode): string
    {
        return match (true) {
            $httpStatus === 410 && $errorCode === 'screenshot_expired' => self::EXPIRED,
            $httpStatus === 410 && $errorCode === 'screenshot_deleted' => self::DELETED,
            $httpStatus === 404 && $errorCode === 'screenshot_failed' => self::FAILED,
            $httpStatus === 404 && $errorCode === 'screenshot_missing' => self::NOT_CAPTURED,
            default => self::UNAVAILABLE,
        };
    }
}
