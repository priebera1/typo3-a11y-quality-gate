<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Pricing;

use Priebera\A11yQualityGate\Domain\Repository\Contract\AiPriceCatalogueRepositoryInterface;

/** The local catalogue tables in memory, with the claim semantics of the database implementation. */
final class InMemoryAiPriceCatalogueRepository implements AiPriceCatalogueRepositoryInterface
{
    /** @var array<string,array<string,mixed>> "provider\0model" => row */
    public array $prices = [];
    /** @var array<string,mixed>|null */
    public ?array $state = null;
    public bool $failStore = false;

    public function findPrice(string $provider, string $model): ?array
    {
        return $this->prices[$provider . "\0" . $model] ?? null;
    }

    public function findPrices(string $provider): array
    {
        return array_values(array_filter($this->prices, static fn (array $row): bool => $row['provider'] === $provider));
    }

    public function findState(): ?array
    {
        return $this->state;
    }

    public function claim(int $now, int $leaseSeconds): ?string
    {
        $this->state ??= ['catalogue_version' => 0, 'etag' => '', 'published_at' => 0, 'refreshed_at' => 0, 'last_attempt_at' => 0, 'consecutive_failures' => 0, 'error_reason' => '', 'claim_token' => '', 'claimed_at' => 0];
        if ((int)$this->state['claimed_at'] >= $now - $leaseSeconds) {
            return null;
        }
        $token = bin2hex(random_bytes(4));
        $this->state['claim_token'] = $token;
        $this->state['claimed_at'] = $now;

        return $token;
    }

    public function releaseClaim(string $token): void
    {
        if (($this->state['claim_token'] ?? '') === $token) {
            $this->state['claim_token'] = '';
            $this->state['claimed_at'] = 0;
        }
    }

    public function storeCatalogue(string $token, int $version, string $etag, int $publishedAt, array $prices, int $now): bool
    {
        if ($this->failStore) {
            throw new \RuntimeException('Deadlock found when trying to get lock');
        }
        if (($this->state['claim_token'] ?? '') !== $token) {
            return false;
        }
        $kept = [];
        foreach ($prices as $price) {
            $key = $price['provider'] . "\0" . $price['model'];
            $this->prices[$key] = ['provider' => $price['provider'], 'model' => $price['model'], 'label' => $price['label'], 'rates' => $price['rates'], 'source_url' => $price['source_url'], 'verified_at' => $price['verified_at'], 'listed' => $price['listed'] ? 1 : 0];
            $kept[$key] = true;
        }
        foreach ($this->prices as $key => $row) {
            if (!isset($kept[$key])) {
                $this->prices[$key]['listed'] = 0;
            }
        }
        $this->state = ['catalogue_version' => $version, 'etag' => $etag, 'published_at' => $publishedAt, 'refreshed_at' => $now, 'last_attempt_at' => $now, 'consecutive_failures' => 0, 'error_reason' => ''] + $this->state;

        return true;
    }

    public function markCurrent(string $token, int $now): void
    {
        if (($this->state['claim_token'] ?? '') === $token) {
            $this->state = ['refreshed_at' => $now, 'last_attempt_at' => $now, 'consecutive_failures' => 0, 'error_reason' => ''] + $this->state;
        }
    }

    public function storeFailure(string $token, int $now, string $reason): void
    {
        if (($this->state['claim_token'] ?? '') === $token) {
            $this->state = ['last_attempt_at' => $now, 'error_reason' => $reason, 'consecutive_failures' => (int)$this->state['consecutive_failures'] + 1] + $this->state;
        }
    }
}
