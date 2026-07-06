<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Domain\Repository\Contract\AiRateLimitRepositoryInterface;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Priebera\A11yQualityGate\Database\Tables;

final class AiRateLimitRepository extends AbstractRepository implements AiRateLimitRepositoryInterface
{
    /** @return array{allowed:bool,retryAfter:int,count:int} */
    public function consume(string $bucketHash, int $limit, int $windowSeconds, int $now): array
    {
        $connection = $this->getConnection(Tables::AI_RATE_LIMIT);
        $boundary = $now - $windowSeconds;

        return $connection->transactional(function () use ($connection, $bucketHash, $limit, $windowSeconds, $now, $boundary): array {
            $state = $connection->fetchAssociative(
                'SELECT window_started, request_count FROM ' . Tables::AI_RATE_LIMIT . ' WHERE bucket_hash = ?',
                [$bucketHash],
            );

            if (!is_array($state)) {
                try {
                    $connection->insert(Tables::AI_RATE_LIMIT, [
                        'bucket_hash' => $bucketHash,
                        'window_started' => $now,
                        'request_count' => 0,
                        'tstamp' => $now,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Another worker created the shared bucket first.
                }
            } elseif ((int)$state['window_started'] <= $boundary) {
                $connection->executeStatement(
                    'UPDATE ' . Tables::AI_RATE_LIMIT . ' SET window_started = ?, request_count = 0, tstamp = ? WHERE bucket_hash = ? AND window_started <= ?',
                    [$now, $now, $bucketHash, $boundary],
                );
            }

            $affected = $connection->executeStatement(
                'UPDATE ' . Tables::AI_RATE_LIMIT . ' SET request_count = request_count + 1, tstamp = ? WHERE bucket_hash = ? AND window_started > ? AND request_count < ?',
                [$now, $bucketHash, $boundary, $limit],
            );
            $state = $connection->fetchAssociative(
                'SELECT window_started, request_count FROM ' . Tables::AI_RATE_LIMIT . ' WHERE bucket_hash = ?',
                [$bucketHash],
            ) ?: ['window_started' => $now, 'request_count' => $limit];

            return [
                'allowed' => $affected === 1,
                'retryAfter' => max(1, ((int)$state['window_started'] + $windowSeconds) - $now),
                'count' => (int)$state['request_count'],
            ];
        });
    }
}
