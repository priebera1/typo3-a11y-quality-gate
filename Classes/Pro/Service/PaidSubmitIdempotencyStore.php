<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use TYPO3\CMS\Core\Registry;

/**
 * The Idempotency-Key of a paid frontend scan submit that did not get a definitive answer.
 *
 * A submit that times out (or meets a server error) may still have been accepted by the AQG crawler: the scan then
 * ran as an orphan, its result never showed and a Trial credit was spent, and the editor's retry got "a scan is
 * already running". The key is stored before the request goes out and kept until the crawler answered; the next
 * submit of the same request (the editor's retry, the module's automatic check, the next scheduler run) sends it
 * again and the crawler answers with the scan it already accepted instead of starting a second one.
 *
 * One entry per request fingerprint (ProCrawlerService), so two different scans never share a key. A key an attempt
 * left unconfirmed is released only when the crawler accepts or replays the scan, or after MAX_AGE_SECONDS.
 */
final class PaidSubmitIdempotencyStore
{
    private const REGISTRY_NAMESPACE = 'a11y_quality_gate';
    private const REGISTRY_KEY_PREFIX = 'paid_submit_key_';

    /**
     * The crawler gives up a run that waited this long (CRAWLER_STALE_WAITING_MINUTES, 15 minutes). A retry after that
     * is a new scan: an older key would only replay a run the editor no longer expects.
     */
    private const MAX_AGE_SECONDS = 900;

    public function __construct(
        private readonly Registry $registry,
    ) {
    }

    /**
     * The key left by an unanswered submit of this request, or a new one, stored before it is used.
     */
    public function keyFor(string $fingerprint): string
    {
        $entry = $this->registry->get(self::REGISTRY_NAMESPACE, $this->entryKey($fingerprint));
        if (is_array($entry)) {
            $key = (string)($entry['key'] ?? '');
            $createdAt = (int)($entry['createdAt'] ?? 0);
            if (self::isValidKey($key) && $createdAt > 0 && time() - $createdAt <= self::MAX_AGE_SECONDS) {
                return $key;
            }
        }

        $key = self::newKey();
        $this->registry->set(self::REGISTRY_NAMESPACE, $this->entryKey($fingerprint), [
            'key' => $key,
            'createdAt' => time(),
        ]);

        return $key;
    }

    /**
     * An attempt with this key got no definitive answer: the crawler may have accepted the scan under it.
     */
    public function markUnconfirmed(string $fingerprint, string $key): void
    {
        $entry = $this->registry->get(self::REGISTRY_NAMESPACE, $this->entryKey($fingerprint));
        if (is_array($entry) && (string)($entry['key'] ?? '') === $key) {
            $this->registry->set(self::REGISTRY_NAMESPACE, $this->entryKey($fingerprint), ['unconfirmed' => true] + $entry);
        }
    }

    /**
     * The crawler accepted or replayed the scan ($accepted), or refused this attempt: the next submit of this request
     * is a new scan. A refusal keeps a key an earlier attempt left unconfirmed — the refusal may come from before the
     * crawler's replay lookup (its rate limit, a refused token), so it says nothing about the scan that attempt may
     * have started.
     */
    public function release(string $fingerprint, string $key, bool $accepted = true): void
    {
        $entry = $this->registry->get(self::REGISTRY_NAMESPACE, $this->entryKey($fingerprint));
        if (!is_array($entry) || (string)($entry['key'] ?? '') !== $key) {
            return;
        }
        if (!$accepted && ($entry['unconfirmed'] ?? false) === true) {
            return;
        }
        $this->registry->remove(self::REGISTRY_NAMESPACE, $this->entryKey($fingerprint));
    }

    public static function newKey(): string
    {
        return 'aqg-paid-' . bin2hex(random_bytes(16));
    }

    /**
     * Only a key this store issued is sent again.
     */
    private static function isValidKey(string $key): bool
    {
        return preg_match('/^aqg-paid-[a-f0-9]{32}$/', $key) === 1;
    }

    private function entryKey(string $fingerprint): string
    {
        return self::REGISTRY_KEY_PREFIX . sha1($fingerprint);
    }
}
