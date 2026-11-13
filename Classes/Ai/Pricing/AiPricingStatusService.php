<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Pricing;

use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Ai\Usage\AiPrice;
use Priebera\A11yQualityGate\Ai\Usage\AiPriceTable;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiPriceCatalogueRepositoryInterface;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Utility\BackendLocaleFormatter;

/**
 * Read-only pricing state for the AI settings screen: the selected model's price in one line, a price per offered
 * model, and the diagnostics (catalogue source and refresh, prices with provenance, pre-3.0 manual prices).
 * Every figure comes from AiPriceTable, the same resolver the budget and the usage settlement use. The labels are
 * formatted by $formatter (the settings screen passes the backend user's language; English otherwise).
 */
final class AiPricingStatusService
{
    public const STATE_CURRENT = 'current';
    public const STATE_STALE = 'stale';
    public const STATE_NONE = 'none';

    public function __construct(
        private readonly AiPriceTable $priceTable,
        private readonly ?AiPriceCatalogueRepositoryInterface $catalogueRepository = null,
    ) {}

    /** @return array<string,mixed> one model's price for display, or known = false */
    public function modelPrice(string $provider, string $model, ?int $now = null, ?BackendLocaleFormatter $formatter = null): array
    {
        $now ??= time();
        $formatter ??= new BackendLocaleFormatter('en');
        $price = trim($model) !== '' ? $this->priceTable->find($provider, $model) : null;
        if ($price === null) {
            return ['known' => false, 'model' => $model, 'state' => self::STATE_NONE];
        }

        return ['known' => true, 'model' => $model] + $this->describe($price, $now, $formatter);
    }

    /**
     * @param list<array{id:string,label:string}> $models
     * @return array<string,array<string,mixed>> keyed by model id
     */
    public function modelPrices(string $provider, array $models, ?int $now = null, ?BackendLocaleFormatter $formatter = null): array
    {
        $prices = [];
        foreach ($models as $model) {
            $prices[$model['id']] = $this->modelPrice($provider, $model['id'], $now, $formatter);
        }

        return $prices;
    }

    /**
     * Read-only diagnostics: where the prices come from (the AQG service's catalogue or the bundled list), when the
     * local copy was last refreshed, whether the service is reachable, the prices per provider with their provenance,
     * and pre-3.0 manual prices. Nothing here asks the administrator to do anything about prices.
     *
     * @return array<string,mixed>
     */
    public function diagnostics(?int $now = null, ?BackendLocaleFormatter $formatter = null): array
    {
        $now ??= time();
        $formatter ??= new BackendLocaleFormatter('en');
        $state = $this->catalogueState();
        $version = (int)($state['catalogue_version'] ?? 0);
        $failures = (int)($state['consecutive_failures'] ?? 0);
        $reason = (string)($state['error_reason'] ?? '');
        $providers = [];
        foreach (AiProviderCatalog::ids() as $provider) {
            $catalogue = [];
            $latest = 0;
            foreach ($this->priceTable->catalogue($provider) as $row) {
                $catalogue[] = ['priceId' => $row['priceId'], 'models' => implode(', ', $row['models']), 'listed' => $row['listed']]
                    + ($row['price'] !== null ? ['known' => true] + $this->describe($row['price'], $now, $formatter) : ['known' => false]);
                $latest = max($latest, (int)($row['price']?->verifiedAt ?? 0));
            }
            $providers[] = [
                'id' => $provider,
                'label' => AiProviderCatalog::label($provider),
                'sourceUrl' => AiBundledPriceCatalogue::sourceUrl($provider),
                'lastVerifiedLabel' => $formatter->date($latest),
                'catalogue' => $catalogue,
            ];
        }
        $legacy = [];
        foreach ($this->priceTable->legacyPrices() as $row) {
            $legacy[] = [
                'providerLabel' => AiProviderCatalog::label($row['provider']) ?: $row['provider'],
                'model' => $row['model'],
                'superseded' => $row['superseded'],
                'inputLabel' => $formatter->usdPrice($row['price']->rates->input),
                'outputLabel' => $formatter->usdPrice($row['price']->rates->output),
                'asOfLabel' => $formatter->date($row['price']->verifiedAt),
            ];
        }

        return [
            'catalogue' => [
                'source' => $version > 0 ? 'central' : 'bundled',
                'version' => $version,
                'refreshedLabel' => $formatter->dateTime((int)($state['refreshed_at'] ?? 0)),
                'publishedLabel' => $formatter->dateTime((int)($state['published_at'] ?? 0)),
                'lastAttemptLabel' => $formatter->dateTime((int)($state['last_attempt_at'] ?? 0)),
                'failing' => $failures > 0,
                'endpointMissing' => $failures > 0 && $reason === 'endpoint_missing',
                // Reached, but its answer was not taken (invalid, or older than the copy) — not an outage.
                'answerRefused' => $failures > 0 && !in_array($reason, ['endpoint_missing', 'transport_error', 'catalogue_unavailable', 'error'], true) && !str_starts_with($reason, 'http_'),
                'errorReason' => $failures > 0 ? $reason : '',
                'serviceUrl' => rtrim(ProSettings::resolveApiBaseUrl(), '/') . AiPriceCatalogueService::ENDPOINT,
            ],
            'providers' => $providers,
            'legacy' => $legacy,
            'anyFailed' => $failures > 0,
            'bundledLabel' => $formatter->date(AiBundledPriceCatalogue::VERIFIED_AT),
        ];
    }

    /** @return array<string,mixed> */
    private function describe(AiPrice $price, int $now, BackendLocaleFormatter $formatter): array
    {
        $tier = $price->longContext;

        return [
            'state' => $price->isStale($now, AiPriceTable::STALE_AFTER_SECONDS) ? self::STATE_STALE : self::STATE_CURRENT,
            'origin' => $price->origin,
            'complete' => $price->complete,
            'inputLabel' => $formatter->usdPrice($price->rates->input),
            'outputLabel' => $formatter->usdPrice($price->rates->output),
            'cachedInputLabel' => $formatter->usdPrice($price->rates->cachedInput),
            'cacheWriteLabel' => $formatter->usdPrice($price->rates->cacheWrite),
            'hasCacheDiscount' => $price->rates->cachedInput < $price->rates->input,
            'hasWritePremium' => $price->rates->cacheWrite > $price->rates->input,
            'longContext' => $tier !== null,
            'longContextThreshold' => $tier !== null ? $formatter->integer($tier->threshold) : '',
            'longInputLabel' => $tier !== null ? $formatter->usdPrice($tier->rates->input) : '',
            'longOutputLabel' => $tier !== null ? $formatter->usdPrice($tier->rates->output) : '',
            'verifiedAt' => $price->verifiedAt,
            'verifiedLabel' => $formatter->date($price->verifiedAt),
            'sourceUrl' => $price->sourceUrl,
        ];
    }

    /** @return array<string,mixed> */
    private function catalogueState(): array
    {
        try {
            return $this->catalogueRepository?->findState() ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}
