<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\EventListener;

use Priebera\A11yQualityGate\FreePreview\FreePreviewActivationService;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use TYPO3\CMS\Core\Cache\Event\CacheFlushEvent;

/**
 * A TYPO3 cache flush ("cache:flush", "Flush all caches") drops the cached FREE terms and the cached "not published"
 * answer, so the next Settings view asks the AQG service again — the fail-closed check itself is unchanged.
 *
 * Only those entries: the AQG cache also keeps the licence answers' offline grace copies, which must outlive a
 * deployment's flush while the AQG service is unreachable (TYPO3's command-line flush does not reach that cache: it
 * has no configured group). Removing the two entries is harmless whatever groups the flush names.
 */
final class FreeTermsCacheFlushListener
{
    public function __construct(private readonly ProCacheManager $cacheManager)
    {
    }

    public function __invoke(CacheFlushEvent $event): void
    {
        foreach (FreePreviewActivationService::TERMS_CACHE_KEYS as $cacheKey) {
            try {
                $this->cacheManager->removeDisplayPayload($cacheKey);
            } catch (\Throwable) {
                // A cache that cannot be reached right now holds nothing to drop; the flush must not fail over it.
            }
        }
    }
}
