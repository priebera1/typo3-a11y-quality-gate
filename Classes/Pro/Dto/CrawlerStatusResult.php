<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Dto;

use Priebera\A11yQualityGate\Pro\Enum\CrawlerJobStatus;

final class CrawlerStatusResult
{
    public function __construct(
        public readonly string $jobId,
        public readonly CrawlerJobStatus $status,
        public readonly int $pagesScanned,
        public readonly ?int $pagesTotal,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt,
    ) {
    }

    public static function fromResponseDto(CrawlerStatusResponseDto $dto): self
    {
        return new self(
            jobId: (string)$dto->jobId,
            status: CrawlerJobStatus::fromString((string)$dto->status),
            pagesScanned: $dto->pagesScanned,
            pagesTotal: $dto->pagesTotal,
            startedAt: $dto->startedAt,
            finishedAt: $dto->finishedAt,
        );
    }
}