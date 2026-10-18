<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Http;

use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Remembers for the rest of one backend request that an AQG service origin did not answer.
 *
 * A view such as the Overview asks the AQG service several times (licence, token, summary, history, regression
 * signal, plan). Each request waited for the full timeout, so an unreachable or hanging service kept the page loading
 * for 40 seconds and more. After the first transport failure the later requests of the same origin fail at once with
 * the same transport error, which every caller already treats as an outage (grace licence, "service unavailable").
 *
 * Only in web requests and only for a short time: a Scheduler or CLI run outlives an outage, and PHP-FPM starts every
 * request with an empty memory. Kept per service (API or crawler) even when both share one host, so a slow crawler
 * read never stops the licence check or the token request.
 */
final class ServiceReachability
{
    public const SERVICE_API = 'api';
    public const SERVICE_CRAWLER = 'crawler';

    private const MEMORY_SECONDS = 30;

    /** @var array<string, int> origin => time of the failure */
    private static array $unreachableSince = [];

    private static ?bool $cliOverride = null;

    public static function assertReachable(string $service, string $url, string $message): void
    {
        $origin = $service . '|' . self::origin($url);
        $since = self::$unreachableSince[$origin] ?? 0;
        if ($since === 0) {
            return;
        }
        if (time() - $since > self::MEMORY_SECONDS) {
            unset(self::$unreachableSince[$origin]);
            return;
        }

        throw new ApiRequestFailedException($message, 0, null, 'transport_error');
    }

    public static function markUnreachable(string $service, string $url): void
    {
        if (self::$cliOverride ?? Environment::isCli()) {
            return;
        }

        self::$unreachableSince[$service . '|' . self::origin($url)] = time();
    }

    /**
     * @internal Tests only: run as a web request (false) or as the CLI (true); null restores the real context.
     */
    public static function simulateCli(?bool $cli): void
    {
        self::$cliOverride = $cli;
        self::$unreachableSince = [];
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        return strtolower(($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . ':' . ($parts['port'] ?? ''));
    }
}
