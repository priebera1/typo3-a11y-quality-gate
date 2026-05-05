<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Dto;

final class CrawlerSubmitResult
{
    public function __construct(
        public readonly string $jobId,
        public readonly string $status,
        public readonly ?string $sourceType,
        public readonly ?string $sitemapUrl,
    ) {
    }

    public static function fromResponseDto(CrawlerSubmitResponseDto $dto): self
    {
        return new self(
            jobId: (string)$dto->jobId,
            status: (string)($dto->status ?? 'queued'),
            sourceType: $dto->sourceType,
            sitemapUrl: $dto->sitemapUrl,
        );
    }
}