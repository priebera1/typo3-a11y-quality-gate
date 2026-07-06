<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository\Contract;

interface AiRateLimitRepositoryInterface
{
    /** @return array{allowed:bool,retryAfter:int,count:int} */
    public function consume(string $bucketHash, int $limit, int $windowSeconds, int $now): array;
}
