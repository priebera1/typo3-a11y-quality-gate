<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository\Contract;

/**
 * The local copy of the central AI price catalogue (tx_a11y_ai_model_price) and its state (tx_a11y_ai_price_catalogue).
 * Only AiPriceCatalogueService writes it, from the AQG service's verified catalogue; nothing in the backend edits prices.
 */
interface AiPriceCatalogueRepositoryInterface
{
    /** @return array<string,mixed>|null the stored row of one provider price id */
    public function findPrice(string $provider, string $model): ?array;

    /** @return list<array<string,mixed>> */
    public function findPrices(string $provider): array;

    /** @return array<string,mixed>|null the state row, null before the first refresh attempt */
    public function findState(): ?array;

    /**
     * Claims the next refresh: succeeds only when no other request holds an unexpired claim. Atomic across processes (a
     * single conditional UPDATE).
     *
     * @return string|null the claim token, null while another request refreshes
     */
    public function claim(int $now, int $leaseSeconds): ?string;

    public function releaseClaim(string $token): void;

    /**
     * Replaces the local copy with a validated catalogue in one transaction, under the claim: every model's rates,
     * label, source and verification time; listed = 0 for stored models the catalogue no longer has (their prices
     * stay). Nothing is written when the claim was lost meanwhile.
     *
     * @param list<array{provider:string,model:string,label:string,rates:string,source_url:string,verified_at:int,listed:bool}> $prices
     */
    public function storeCatalogue(string $token, int $version, string $etag, int $publishedAt, array $prices, int $now): bool;

    /** The service confirmed the copy is current (HTTP 304). */
    public function markCurrent(string $token, int $now): void;

    /** A refresh failed; the local copy is not touched. */
    public function storeFailure(string $token, int $now, string $reason): void;
}
