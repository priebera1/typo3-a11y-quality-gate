<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Pricing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Pricing\AiBundledPriceCatalogue;
use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;

/**
 * The shipped catalogue covers every price id of the model registry with complete, consistent prices, so a fresh
 * installation — or one that cannot reach the AQG service — needs no price setup. The figures are the ones the AQG
 * service's parsers read from the official pages on VERIFIED_AT (aqg-api test/ai-pricing.test.ts pins the same values).
 */
final class AiBundledPriceCatalogueTest extends TestCase
{
    private const EXPECTED = [
        'gpt-4.1-mini' => [400_000, 1_600_000, 100_000, 400_000, 400_000],
        'gpt-5.4-mini' => [750_000, 4_500_000, 75_000, 750_000, 750_000],
        'gpt-5.4-nano' => [200_000, 1_250_000, 20_000, 200_000, 200_000],
        'claude-haiku-4-5' => [1_000_000, 5_000_000, 100_000, 1_250_000, 2_000_000],
        'claude-haiku-5-5' => [100_000, 500_000, 10_000, 125_000, 200_000],
        'claude-opus-4-8' => [5_000_000, 25_000_000, 500_000, 6_250_000, 10_000_000],
        'claude-opus-5' => [5_000_000, 25_000_000, 500_000, 6_250_000, 10_000_000],
        'claude-opus-5-5' => [4_000_000, 20_000_000, 200_000, 5_000_000, 8_000_000],
        'claude-sonnet-5' => [2_000_000, 10_000_000, 200_000, 2_500_000, 4_000_000],
        'claude-sonnet-5-5' => [2_000_000, 10_000_000, 100_000, 2_500_000, 4_000_000],
    ];

    #[Test]
    public function everyRegistryPriceIdIsBundledWithCompleteReviewedRates(): void
    {
        $registry = new AiModelCompatibilityRegistry();
        foreach (AiProviderCatalog::ids() as $provider) {
            $ids = $registry->priceModelIds($provider);
            self::assertSame($ids, AiBundledPriceCatalogue::modelIds($provider), $provider);
            foreach ($ids as $id) {
                $bundled = AiBundledPriceCatalogue::find($provider, $id);
                self::assertNotNull($bundled, $provider . ' ' . $id);
                self::assertTrue($bundled->complete);
                self::assertTrue($bundled->rates->isConsistent(), $id);
                self::assertSame(self::EXPECTED[$id], $bundled->rates->values(), $id);
            }
        }
        $haiku = AiBundledPriceCatalogue::find('anthropic', 'claude-haiku-5-5');
        self::assertSame(100_000, $haiku?->longContext?->threshold);
        self::assertSame([500_000, 2_500_000, 50_000, 625_000, 1_000_000], $haiku?->longContext?->rates->values());
        self::assertSame('2026-11-02 13:42:03', gmdate('Y-m-d H:i:s', AiBundledPriceCatalogue::VERIFIED_AT));
        self::assertNull(AiBundledPriceCatalogue::find('openai', 'gpt-5.4-mini-2026-03-17'), 'Snapshots resolve through the registry, not the catalogue.');
    }

    #[Test]
    public function registryPriceIdsMapSnapshotsExplicitly(): void
    {
        $registry = new AiModelCompatibilityRegistry();

        self::assertSame(['gpt-4.1-mini', 'gpt-5.4-mini', 'gpt-5.4-nano'], $registry->priceModelIds('openai'));
        self::assertSame('gpt-5.4-nano', $registry->priceModelId('gpt-5.4-nano-2026-03-17', 'openai'));
        self::assertSame('claude-haiku-4-5', $registry->priceModelId('claude-haiku-4-5-20251001', 'anthropic'));
        self::assertNull($registry->priceModelId('claude-opus-5-5', 'openai'));
        self::assertNull($registry->priceModelId('gpt-4o', 'openai'));
    }
}
