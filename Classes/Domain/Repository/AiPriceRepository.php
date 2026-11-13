<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Domain\Repository\Contract\AiPriceRepositoryInterface;

/**
 * Pre-3.0 administrator-entered prices, read only since AQG 3.0 (AiPriceTable uses them only where the automatic
 * catalogue has no price). Recorded usage never depends on them: every usage row stores the cost estimated when the
 * request ran.
 */
final class AiPriceRepository extends AbstractRepository implements AiPriceRepositoryInterface
{
    public const TABLE = 'tx_a11y_ai_price';

    public function findAll(): array
    {
        return $this->getQueryBuilder(self::TABLE)
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('provider')
            ->addOrderBy('model')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function find(string $provider, string $model): ?array
    {
        $qb = $this->getQueryBuilder(self::TABLE);
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('provider', $qb->createNamedParameter($provider)),
                $qb->expr()->eq('model', $qb->createNamedParameter($model)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}
