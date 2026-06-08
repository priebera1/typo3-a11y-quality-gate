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
     * @param array<string, mixed> $score
     * @param array<string, mixed> $contrast
     * @param array<int, array<string, mixed>> $contrastDetails
     * @param array<string, mixed> $keyboardSummary
     * @param array<string, mixed> $structureSummary
     * @param array<string, mixed> $remediationSummary
     * @param array<string, mixed> $componentSummary
     * @param array<int, array<string, mixed>> $wcagSummary
     * @param array<int, array<string, mixed>> $priorityFixes
     * @param array<string, mixed> $reportSummary
     * @param array<int, array<string, mixed>> $manualReviewChecklist
     * @param array<string, mixed> $reportingGroups
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $siteId,
        public readonly string $startUrl,
        public readonly ?string $sitemapUrl,
        public readonly string $sourceType,
        public readonly string $status,
        public readonly string $contractVersion,
        public readonly array $features,
        public readonly int $pagesScanned,
        public readonly int $pagesFailed,
        public readonly int $issuesTotal,
        public readonly int $issuesNew,
        public readonly int $issuesResolved,
        public readonly array $topPages,
        public readonly array $failedPages,
        public readonly array $topRules,
        public readonly array $countsByStatus,
        public readonly array $score,
        public readonly array $contrast,
        public readonly array $contrastDetails,
        public readonly array $keyboardSummary,
        public readonly array $structureSummary,
        public readonly array $remediationSummary,
        public readonly array $componentSummary,
        public readonly array $wcagSummary,
        public readonly array $priorityFixes,
        public readonly array $reportSummary,
        public readonly array $manualReviewChecklist,
        public readonly array $reportingGroups,
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
            contractVersion: $dto->contractVersion,
            features: $dto->features,
            pagesScanned: $dto->pagesScanned,
            pagesFailed: $dto->pagesFailed,
            issuesTotal: $dto->issuesTotal,
            issuesNew: $dto->issuesNew,
            issuesResolved: $dto->issuesResolved,
            topPages: $dto->topPages,
            failedPages: $dto->failedPages,
            topRules: $dto->topRules,
            countsByStatus: $dto->countsByStatus,
            score: $dto->score,
            contrast: $dto->contrast,
            contrastDetails: $dto->contrastDetails,
            keyboardSummary: $dto->keyboardSummary,
            structureSummary: $dto->structureSummary,
            remediationSummary: $dto->remediationSummary,
            componentSummary: $dto->componentSummary,
            wcagSummary: $dto->wcagSummary,
            priorityFixes: $dto->priorityFixes,
            reportSummary: $dto->reportSummary,
            manualReviewChecklist: $dto->manualReviewChecklist,
            reportingGroups: $dto->reportingGroups,
            startedAt: $dto->startedAt,
            finishedAt: $dto->finishedAt,
        );
    }
}
