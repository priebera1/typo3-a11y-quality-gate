<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\EventListener;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\EventListener\FreeTermsCacheFlushListener;
use Priebera\A11yQualityGate\FreePreview\FreePreviewActivationService;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use TYPO3\CMS\Core\Cache\Event\CacheFlushEvent;

/**
 * A TYPO3 cache flush drops the cached FREE terms and the cached refusal — and nothing else of the AQG cache: the
 * licence answers and their offline grace copies must outlive a deployment's flush.
 */
final class FreeTermsCacheFlushListenerTest extends TestCase
{
    #[Test]
    public function aCacheFlushDropsOnlyTheFreeTermsEntries(): void
    {
        $removed = [];
        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('removeDisplayPayload')->willReturnCallback(static function (string $key) use (&$removed): void {
            $removed[] = $key;
        });
        $cache->expects(self::never())->method('flushAll');
        $cache->expects(self::never())->method('flushLicenceAnswersAndTokens');

        (new FreeTermsCacheFlushListener($cache))(new CacheFlushEvent(['pages', 'system']));

        self::assertSame(['aqg_free_terms_v1', 'aqg_free_terms_failure_v1'], $removed);
        self::assertSame(FreePreviewActivationService::TERMS_CACHE_KEYS, $removed);
    }

    #[Test]
    public function anUnreachableCacheNeverFailsTheFlush(): void
    {
        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('removeDisplayPayload')->willThrowException(new \RuntimeException('cache backend down'));

        (new FreeTermsCacheFlushListener($cache))(new CacheFlushEvent(['all']));
        $this->addToAssertionCount(1);
    }
}
