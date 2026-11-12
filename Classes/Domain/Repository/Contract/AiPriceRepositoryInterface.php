<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository\Contract;

/**
 * Pre-3.0 administrator-entered AI prices (USD per 1M tokens in micro-USD). Read only since AQG 3.0: the automatic
 * price catalogue replaced manual price entry; these rows are kept, never rewritten or deleted, as a fallback for a
 * model the automatic catalogue does not price.
 */
interface AiPriceRepositoryInterface
{
    /** @return list<array<string,mixed>> */
    public function findAll(): array;

    /** @return array<string,mixed>|null */
    public function find(string $provider, string $model): ?array;
}
