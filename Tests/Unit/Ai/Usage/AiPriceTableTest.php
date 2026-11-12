<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Usage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Pricing\AiBundledPriceCatalogue;
use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Usage\AiPrice;
use Priebera\A11yQualityGate\Ai\Usage\AiPriceTable;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiPriceCatalogueRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiPriceRepositoryInterface;

/**
 * The effective price: automatic (bundled or synced, the newer wins), explicit snapshot mapping, never a guess, and a
 * pre-3.0 manual price only where no automatic price exists.
 */
final class AiPriceTableTest extends TestCase
{
    #[Test]
    public function everySelectableModelOfBothProvidersIsPricedWithoutAnySetup(): void
    {
        $table = $this->table();
        $registry = new AiModelCompatibilityRegistry();

        foreach (AiProviderCatalog::ids() as $provider) {
            foreach ($registry->all() as $id => $profile) {
                if ($registry->find((string)$id, $provider) === null) {
                    continue;
                }
                $price = $table->find($provider, (string)$id);
                self::assertNotNull($price, $provider . ' ' . $id . ' has a price on a fresh installation.');
                self::assertSame(AiPrice::ORIGIN_BUNDLED, $price->origin);
                self::assertTrue($price->complete);
                self::assertSame(AiBundledPriceCatalogue::VERIFIED_AT, $price->verifiedAt);
            }
        }
    }

    #[Test]
    public function aDatedSnapshotIsPricedOnlyThroughItsExplicitAlias(): void
    {
        $table = $this->table();

        self::assertSame(750_000, $table->find('openai', 'gpt-5.4-mini-2026-03-17')?->rates->input);
        self::assertSame('gpt-5.4-mini-2026-03-17', $table->find('openai', 'gpt-5.4-mini-2026-03-17')?->model);
        self::assertSame(1_000_000, $table->find('anthropic', 'claude-haiku-4-5-20251001')?->rates->input);
        self::assertNull($table->find('openai', 'gpt-5.4-mini-2099-01-01'), 'An unknown snapshot is never priced as its family.');
        self::assertNull($table->find('openai', 'gpt-4o'), 'A model AQG does not support has no automatic price.');
        self::assertNull($table->find('anthropic', 'gpt-5.4-mini'), 'Never a price of another provider.');
    }

    #[Test]
    public function aNewerSyncedPriceWinsAndAnOlderOrBrokenOneNever(): void
    {
        $newer = $this->syncedRow(AiBundledPriceCatalogue::VERIFIED_AT + 3600, 900_000);
        self::assertSame(900_000, $this->table(synced: $newer)->find('openai', 'gpt-5.4-mini')?->rates->input);
        self::assertSame(AiPrice::ORIGIN_CENTRAL, $this->table(synced: $newer)->find('openai', 'gpt-5.4-mini')?->origin);

        $older = $this->syncedRow(AiBundledPriceCatalogue::VERIFIED_AT - 3600, 900_000);
        self::assertSame(750_000, $this->table(synced: $older)->find('openai', 'gpt-5.4-mini')?->rates->input, 'A bundled price of a newer release beats an older sync.');

        $broken = $this->syncedRow(AiBundledPriceCatalogue::VERIFIED_AT + 3600, 900_000);
        $broken['rates'] = '{"standard":{"input":900000,"output":0}}';
        self::assertSame(750_000, $this->table(synced: $broken)->find('openai', 'gpt-5.4-mini')?->rates->input, 'Malformed stored rates are ignored, never half-used.');

        $inconsistent = $this->syncedRow(AiBundledPriceCatalogue::VERIFIED_AT + 3600, 900_000, 950_000);
        self::assertSame(750_000, $this->table(synced: $inconsistent)->find('openai', 'gpt-5.4-mini')?->rates->input, 'Cached above input is rejected.');
    }

    #[Test]
    public function aManualPriceFromBefore30IsOnlyAnIncompleteFallback(): void
    {
        $legacy = [
            ['provider' => 'openai', 'model' => 'gpt-5.4-mini', 'input_micro_usd' => 1, 'output_micro_usd' => 1, 'price_as_of' => 5, 'source' => 'old'],
            ['provider' => 'openai', 'model' => 'gpt-4o', 'input_micro_usd' => 2_500_000, 'output_micro_usd' => 10_000_000, 'price_as_of' => 5, 'source' => 'old'],
        ];
        $table = $this->table(legacy: $legacy);

        self::assertSame(750_000, $table->find('openai', 'gpt-5.4-mini')?->rates->input, 'The automatic price replaces the manual one.');
        $fallback = $table->find('openai', 'gpt-4o');
        self::assertNotNull($fallback);
        self::assertSame(AiPrice::ORIGIN_MANUAL, $fallback->origin);
        self::assertFalse($fallback->complete, 'No cache or prompt-length rates: never enough for a hard stop.');
        self::assertSame(2_500_000, $fallback->rates->cachedInput, 'Cache reads without a known discount cost input.');
        self::assertSame(3_125_000, $fallback->rates->cacheWrite, 'Cache writes at the 1.25× upper bound.');

        $rows = array_column($table->legacyPrices(), null, 'model');
        self::assertTrue($rows['gpt-5.4-mini']['superseded']);
        self::assertFalse($rows['gpt-4o']['superseded']);
    }

    #[Test]
    public function aStorageFailureFallsBackToTheBundledCatalogue(): void
    {
        $catalogue = $this->createMock(AiPriceCatalogueRepositoryInterface::class);
        $catalogue->method('findPrice')->willThrowException(new \RuntimeException('Table does not exist'));
        $legacy = $this->createMock(AiPriceRepositoryInterface::class);
        $legacy->method('find')->willThrowException(new \RuntimeException('Table does not exist'));

        $table = new AiPriceTable($legacy, new AiModelCompatibilityRegistry(), $catalogue);

        $price = $table->find('anthropic', 'claude-sonnet-5-5');
        self::assertSame(AiPrice::ORIGIN_BUNDLED, $price?->origin);
        self::assertFalse($price?->complete, 'A newer synced price may exist: estimates only, never a hard stop.');
        self::assertNull($table->find('anthropic', 'claude-unknown-9'));
    }

    #[Test]
    public function theDiagnosticsCatalogueListsEveryPriceIdOnceWithItsModels(): void
    {
        $rows = array_column($this->table()->catalogue('openai'), null, 'priceId');

        self::assertSame(['gpt-4.1-mini', 'gpt-5.4-mini', 'gpt-5.4-nano'], array_keys($rows));
        self::assertSame(['gpt-5.4-mini', 'gpt-5.4-mini-2026-03-17'], $rows['gpt-5.4-mini']['models']);
        self::assertTrue($rows['gpt-5.4-mini']['listed']);
    }

    /**
     * @param array<string,mixed>|null $synced
     * @param list<array<string,mixed>> $legacy
     */
    private function table(?array $synced = null, array $legacy = []): AiPriceTable
    {
        $catalogue = $this->createMock(AiPriceCatalogueRepositoryInterface::class);
        $catalogue->method('findPrice')->willReturnCallback(static fn (string $provider, string $model): ?array => $synced !== null && $provider === 'openai' && $model === 'gpt-5.4-mini' ? $synced : null);
        $legacyRepository = $this->createMock(AiPriceRepositoryInterface::class);
        $legacyRepository->method('findAll')->willReturn($legacy);
        $legacyRepository->method('find')->willReturnCallback(static function (string $provider, string $model) use ($legacy): ?array {
            foreach ($legacy as $row) {
                if ($row['provider'] === $provider && $row['model'] === $model) {
                    return $row;
                }
            }
            return null;
        });

        return new AiPriceTable($legacyRepository, new AiModelCompatibilityRegistry(), $catalogue);
    }

    /** @return array<string,mixed> */
    private function syncedRow(int $verifiedAt, int $input, int $cached = 75_000): array
    {
        return [
            'provider' => 'openai',
            'model' => 'gpt-5.4-mini',
            'rates' => json_encode(['standard' => ['input' => $input, 'output' => 4_500_000, 'cached_input' => $cached, 'cache_write' => $input, 'cache_write_extended' => $input], 'long_context' => null]),
            'source_url' => 'https://developers.openai.com/api/docs/pricing.md',
            'verified_at' => $verifiedAt,
            'listed' => 1,
        ];
    }
}
