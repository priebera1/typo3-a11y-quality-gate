<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Dto;

final class CrawlerResultsResult
{
    /**
     * @param array<int, array<string, mixed>> $pages
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $siteId,
        public readonly string $startUrl,
        public readonly ?string $sitemapUrl,
        public readonly string $sourceType,
        public readonly string $status,
        public readonly int $pagesScanned,
        public readonly int $issuesTotal,
        public readonly int $issuesNew,
        public readonly int $issuesResolved,
        public readonly array $pages,
    ) {
    }

    public static function fromResponseDto(CrawlerResultsResponseDto $dto): self
    {
        return new self(
            jobId: (string)$dto->jobId,
            siteId: $dto->siteId,
            startUrl: $dto->startUrl,
            sitemapUrl: $dto->sitemapUrl,
            sourceType: $dto->sourceType !== '' ? $dto->sourceType : 'crawl',
            status: $dto->status,
            pagesScanned: $dto->pagesScanned,
            issuesTotal: $dto->issuesTotal,
            issuesNew: $dto->issuesNew,
            issuesResolved: $dto->issuesResolved,
            pages: $dto->pages,
        );
    }
}