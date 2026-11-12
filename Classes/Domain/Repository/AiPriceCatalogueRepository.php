<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiPriceCatalogueRepositoryInterface;
use TYPO3\CMS\Core\Database\Connection;

final class AiPriceCatalogueRepository extends AbstractRepository implements AiPriceCatalogueRepositoryInterface
{
    private const IDENTIFIER = 'central';

    public function findPrice(string $provider, string $model): ?array
    {
        $qb = $this->getQueryBuilder(Tables::AI_MODEL_PRICE);
        $row = $qb->select('*')
            ->from(Tables::AI_MODEL_PRICE)
            ->where(
                $qb->expr()->eq('provider', $qb->createNamedParameter($provider)),
                $qb->expr()->eq('model', $qb->createNamedParameter($model)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findPrices(string $provider): array
    {
        $qb = $this->getQueryBuilder(Tables::AI_MODEL_PRICE);

        return $qb->select('*')
            ->from(Tables::AI_MODEL_PRICE)
            ->where($qb->expr()->eq('provider', $qb->createNamedParameter($provider)))
            ->orderBy('model')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function findState(): ?array
    {
        $qb = $this->getQueryBuilder(Tables::AI_PRICE_CATALOGUE);
        $row = $qb->select('*')
            ->from(Tables::AI_PRICE_CATALOGUE)
            ->where($qb->expr()->eq('identifier', $qb->createNamedParameter(self::IDENTIFIER)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function claim(int $now, int $leaseSeconds): ?string
    {
        $this->ensureStateRow();
        $token = bin2hex(random_bytes(16));
        $qb = $this->getQueryBuilder(Tables::AI_PRICE_CATALOGUE);
        $claimed = $qb->update(Tables::AI_PRICE_CATALOGUE)
            ->set('claim_token', $token)
            ->set('claimed_at', $now, true, Connection::PARAM_INT)
            ->where(
                $qb->expr()->eq('identifier', $qb->createNamedParameter(self::IDENTIFIER)),
                $qb->expr()->lt('claimed_at', $qb->createNamedParameter($now - $leaseSeconds, Connection::PARAM_INT)),
            )
            ->executeStatement();

        return $claimed === 1 ? $token : null;
    }

    public function releaseClaim(string $token): void
    {
        $this->getConnection(Tables::AI_PRICE_CATALOGUE)->update(
            Tables::AI_PRICE_CATALOGUE,
            ['claim_token' => '', 'claimed_at' => 0],
            ['identifier' => self::IDENTIFIER, 'claim_token' => $token],
        );
    }

    public function storeCatalogue(string $token, int $version, string $etag, int $publishedAt, array $prices, int $now): bool
    {
        $connection = $this->getConnection(Tables::AI_MODEL_PRICE);

        return (bool)$connection->transactional(function (Connection $connection) use ($token, $version, $etag, $publishedAt, $prices, $now): bool {
            // The claim is re-checked inside the transaction: a refresh whose lease expired must not overwrite a newer one.
            $updated = $connection->update(
                Tables::AI_PRICE_CATALOGUE,
                [
                    'catalogue_version' => $version,
                    'etag' => mb_substr($etag, 0, 100),
                    'published_at' => $publishedAt,
                    'refreshed_at' => $now,
                    'last_attempt_at' => $now,
                    'consecutive_failures' => 0,
                    'error_reason' => '',
                    'tstamp' => $now,
                ],
                ['identifier' => self::IDENTIFIER, 'claim_token' => $token],
            );
            if ($updated !== 1) {
                return false;
            }
            $kept = [];
            foreach ($prices as $price) {
                $values = [
                    'label' => mb_substr($price['label'], 0, 100),
                    'rates' => $price['rates'],
                    'source_url' => mb_substr($price['source_url'], 0, 255),
                    'verified_at' => $price['verified_at'],
                    'listed' => $price['listed'] ? 1 : 0,
                    'tstamp' => $now,
                ];
                $affected = $connection->update(Tables::AI_MODEL_PRICE, $values, ['provider' => $price['provider'], 'model' => $price['model']]);
                if ($affected === 0 && $this->findPrice($price['provider'], $price['model']) === null) {
                    $connection->insert(Tables::AI_MODEL_PRICE, $values + ['pid' => 0, 'provider' => $price['provider'], 'model' => $price['model'], 'crdate' => $now]);
                }
                $kept[$price['provider'] . "\0" . $price['model']] = true;
            }
            foreach ($connection->select(['uid', 'provider', 'model'], Tables::AI_MODEL_PRICE)->fetchAllAssociative() as $row) {
                if (!isset($kept[$row['provider'] . "\0" . $row['model']])) {
                    $connection->update(Tables::AI_MODEL_PRICE, ['listed' => 0, 'tstamp' => $now], ['uid' => (int)$row['uid']]);
                }
            }

            return true;
        });
    }

    public function markCurrent(string $token, int $now): void
    {
        $this->getConnection(Tables::AI_PRICE_CATALOGUE)->update(
            Tables::AI_PRICE_CATALOGUE,
            ['refreshed_at' => $now, 'last_attempt_at' => $now, 'consecutive_failures' => 0, 'error_reason' => '', 'tstamp' => $now],
            ['identifier' => self::IDENTIFIER, 'claim_token' => $token],
        );
    }

    public function storeFailure(string $token, int $now, string $reason): void
    {
        $qb = $this->getQueryBuilder(Tables::AI_PRICE_CATALOGUE);
        $qb->update(Tables::AI_PRICE_CATALOGUE)
            ->set('last_attempt_at', $now, true, Connection::PARAM_INT)
            ->set('error_reason', mb_substr($reason, 0, 32))
            ->set('tstamp', $now, true, Connection::PARAM_INT)
            ->set('consecutive_failures', $qb->quoteIdentifier('consecutive_failures') . ' + 1', false)
            ->where(
                $qb->expr()->eq('identifier', $qb->createNamedParameter(self::IDENTIFIER)),
                $qb->expr()->eq('claim_token', $qb->createNamedParameter($token)),
            )
            ->executeStatement();
    }

    private function ensureStateRow(): void
    {
        if ($this->findState() !== null) {
            return;
        }
        try {
            $this->getConnection(Tables::AI_PRICE_CATALOGUE)->insert(Tables::AI_PRICE_CATALOGUE, ['identifier' => self::IDENTIFIER, 'tstamp' => time()]);
        } catch (UniqueConstraintViolationException) {
            // Another request created it first.
        }
    }
}
