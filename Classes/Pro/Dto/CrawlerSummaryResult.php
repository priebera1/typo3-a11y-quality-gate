<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Dto;

final class CrawlerSummaryResult
{
    /**
     * @param array<int, array<string, mixed>> $topPages
     * @param array<int, array<string, mixed>> $failedPages
     * @param array<int, array<string, mixed>> $topRules
     * @param array<int, array<string, mixed>> $countsByStatus
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $siteId,
        public readonly string $startUrl,
        public readonly ?string $sitemapUrl,
        public readonly string $sourceType,
        public readonly string $status,
        public readonly int $pagesScanned,
        public readonly int $pagesFailed,
        public readonly int $issuesTotal,
        public readonly int $issuesNew,
        public readonly int $issuesResolved,
        public readonly array $topPages,
        public readonly array $failedPages,
        public readonly array $topRules,
        public readonly array $countsByStatus,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt,
    ) {
    }

    public static function fromResponseDto(CrawlerSummaryResponseDto $dto): self
    {
        return new self(
            jobId: (string)$dto->jobId,
            siteId: $dto->siteId,
            startUrl: $dto->startUrl,
            sitemapUrl: $dto->sitemapUrl,
            sourceType: $dto->sourceType !== '' ? $dto->sourceType : 'crawl',
            status: $dto->status,
            pagesScanned: $dto->pagesScanned,
            pagesFailed: $dto->pagesFailed,
            issuesTotal: $dto->issuesTotal,
            issuesNew: $dto->issuesNew,
            issuesResolved: $dto->issuesResolved,
            topPages: $dto->topPages,
            failedPages: $dto->failedPages,
            topRules: $dto->topRules,
            countsByStatus: $dto->countsByStatus,
            startedAt: $dto->startedAt,
            finishedAt: $dto->finishedAt,
        );
    }
}