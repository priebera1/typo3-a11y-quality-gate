<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

use Priebera\A11yQualityGate\Ai\Pricing\AiBundledPriceCatalogue;
use Priebera\A11yQualityGate\Ai\Pricing\AiPriceCatalogueService;
use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiPriceCatalogueRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiPriceRepositoryInterface;

/**
 * THE effective price of a provider model — the one definition the settings screen shows, the budget reserves, a
 * request is settled at and the monthly usage is summed from. Administrators never enter prices (AQG 3.0).
 *
 * Resolution for a model id:
 * 1. the id the provider's pricing page prices it under (AiModelCompatibilityRegistry::priceModelId(): its own id, or
 *    an explicit, reviewed snapshot → alias entry). A model AQG does not know has no automatic price.
 * 2. the automatic price of that id: the local copy of the AQG service's central catalogue (tx_a11y_ai_model_price,
 *    refreshed by AiPriceCatalogueService on the first lookup of a request once it is due) or the bundled catalogue,
 *    whichever was verified later — a newer verified price is never replaced by an older bundled one. A stored row
 *    whose rates fail validation is ignored, never half-used.
 * 3. only when neither exists: a pre-3.0 administrator-entered price of exactly that model (tx_a11y_ai_price, read
 *    only). It is incomplete (no cache or prompt-length rates), so a hard-stop budget does not rely on it.
 * Otherwise there is no price: cost is "unknown", never 0, and a hard-stop budget refuses the request.
 */
class AiPriceTable
{
    /** Prices older than this are shown as out of date (they still apply; the sync runs about daily). */
    public const STALE_AFTER_SECONDS = 7 * 86400;

    /** @var array<string,AiPrice|null> */
    private array $cache = [];
    private bool $lookupFailed = false;
    private bool $refreshChecked = false;

    public function __construct(
        private readonly AiPriceRepositoryInterface $legacyRepository,
        private readonly AiModelCompatibilityRegistry $registry,
        private readonly ?AiPriceCatalogueRepositoryInterface $catalogueRepository = null,
        private readonly ?AiPriceCatalogueService $catalogueService = null,
    ) {}

    /** Brings the local catalogue copy up to date once per instance (request), when due; never throws or blocks long. */
    private function refreshCatalogue(): void
    {
        if (!$this->refreshChecked && $this->catalogueService !== null) {
            $this->refreshChecked = true;
            if ($this->catalogueService->refreshIfDue() === AiPriceCatalogueService::RESULT_UPDATED) {
                $this->cache = [];
            }
        }
    }

    public function find(string $provider, string $model): ?AiPrice
    {
        $provider = AiProviderCatalog::normalize($provider);
        $model = trim($model);
        if ($provider === '' || $model === '') {
            return null;
        }
        $key = $provider . "\0" . $model;
        if (!array_key_exists($key, $this->cache)) {
            $priceId = $this->registry->priceModelId($model, $provider);
            $price = $priceId !== null ? $this->findByPriceId($provider, $priceId) : null;
            $price ??= $this->legacyPrice($provider, $model);
            if ($price !== null) {
                $profile = $this->registry->find($model, $provider);
                $price = new AiPrice(
                    $price->provider,
                    $model,
                    $price->rates,
                    $price->longContext,
                    $price->origin,
                    $price->sourceUrl,
                    $price->verifiedAt,
                    $price->complete,
                    (string)($profile['label'] ?? $model),
                );
            }
            $this->cache[$key] = $price;
        }

        return $this->cache[$key];
    }

    /** The automatic price (central or bundled, whichever was verified later) of a provider price id; never a manual one. */
    public function findByPriceId(string $provider, string $priceId): ?AiPrice
    {
        $provider = AiProviderCatalog::normalize($provider);
        $this->refreshCatalogue();
        $bundled = AiBundledPriceCatalogue::find($provider, $priceId);
        $this->lookupFailed = false;
        $synced = $this->syncedPrice($provider, $priceId);
        if ($synced !== null && ($bundled === null || $synced->verifiedAt >= $bundled->verifiedAt)) {
            return $synced;
        }
        if ($bundled !== null && $this->lookupFailed) {
            // The central price could not be read: the bundled one may be older and lower, so it estimates cost but a
            // hard-stop budget does not rely on it.
            return new AiPrice($bundled->provider, $bundled->model, $bundled->rates, $bundled->longContext, $bundled->origin, $bundled->sourceUrl, $bundled->verifiedAt, false, $bundled->label);
        }

        return $bundled;
    }

    /** Whether the central catalogue still lists this price id (true while no copy is stored). */
    public function isListed(string $provider, string $priceId): bool
    {
        $row = $this->syncedRow(AiProviderCatalog::normalize($provider), $priceId);

        return $row === null || (int)($row['listed'] ?? 1) === 1;
    }

    /**
     * The read-only catalogue for the diagnostics: every price id of a provider's selectable registry models with its
     * effective price.
     *
     * @return list<array{provider:string,priceId:string,models:list<string>,price:?AiPrice,listed:bool}>
     */
    public function catalogue(string $provider): array
    {
        $provider = AiProviderCatalog::normalize($provider);
        $models = [];
        foreach (array_keys($this->registry->all()) as $modelId) {
            $priceId = $this->registry->priceModelId((string)$modelId, $provider);
            if ($priceId !== null && $this->registry->find((string)$modelId, $provider) !== null) {
                $models[$priceId][] = (string)$modelId;
            }
        }
        ksort($models, SORT_STRING);

        $rows = [];
        foreach ($models as $priceId => $modelIds) {
            $rows[] = [
                'provider' => $provider,
                'priceId' => (string)$priceId,
                'models' => $modelIds,
                'price' => $this->findByPriceId($provider, (string)$priceId),
                'listed' => $this->isListed($provider, (string)$priceId),
            ];
        }

        return $rows;
    }

    /**
     * Pre-3.0 administrator-entered prices, never edited or removed by AQG 3.0.
     *
     * @return list<array{provider:string,model:string,price:AiPrice,superseded:bool}>
     */
    public function legacyPrices(): array
    {
        try {
            $stored = $this->legacyRepository->findAll();
        } catch (\Throwable) {
            return [];
        }
        $rows = [];
        foreach ($stored as $row) {
            $price = AiPrice::fromLegacyRow($row);
            if ($price === null) {
                continue;
            }
            $priceId = $this->registry->priceModelId($price->model, $price->provider);
            $rows[] = [
                'provider' => $price->provider,
                'model' => $price->model,
                'price' => $price,
                'superseded' => $priceId !== null && $this->findByPriceId($price->provider, $priceId) !== null,
            ];
        }

        return $rows;
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    private function legacyPrice(string $provider, string $model): ?AiPrice
    {
        try {
            $row = $this->legacyRepository->find($provider, $model);
        } catch (\Throwable) {
            return null;
        }

        return is_array($row) ? AiPrice::fromLegacyRow($row) : null;
    }

    private function syncedPrice(string $provider, string $priceId): ?AiPrice
    {
        $row = $this->syncedRow($provider, $priceId);
        if ($row === null) {
            return null;
        }
        try {
            $rates = json_decode((string)($row['rates'] ?? ''), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($rates)) {
            return null;
        }
        $standard = AiRates::fromArray($rates['standard'] ?? null);
        $longData = $rates['long_context'] ?? null;
        $long = $longData !== null ? AiLongContextTier::fromArray($longData) : null;
        $verifiedAt = (int)($row['verified_at'] ?? 0);
        if ($standard === null || ($longData !== null && $long === null) || $verifiedAt <= 0) {
            return null;
        }

        return new AiPrice($provider, $priceId, $standard, $long, AiPrice::ORIGIN_CENTRAL, (string)($row['source_url'] ?? ''), $verifiedAt, true, (string)($row['label'] ?? ''));
    }

    /** @return array<string,mixed>|null */
    private function syncedRow(string $provider, string $priceId): ?array
    {
        if ($this->catalogueRepository === null) {
            return null;
        }
        try {
            return $this->catalogueRepository->findPrice($provider, $priceId);
        } catch (\Throwable) {
            // Not migrated yet, or the database is unavailable: the bundled catalogue still prices supported models,
            // but only for estimates (findByPriceId()).
            $this->lookupFailed = true;
            return null;
        }
    }
}
