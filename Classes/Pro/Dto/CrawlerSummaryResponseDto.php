<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Dto;

final class CrawlerSummaryResponseDto
{
    /**
     * @param array<int, array<string, mixed>> $topPages
     * @param array<int, array<string, mixed>> $failedPages
     * @param array<int, array<string, mixed>> $topRules
     * @param array<int, array<string, mixed>> $countsByStatus
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $jobId,
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
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];

        $sitemapUrl = isset($payload['sitemapUrl']) ? trim((string)$payload['sitemapUrl']) : null;
        if ($sitemapUrl === '') {
            $sitemapUrl = null;
        }

        return new self(
            success: isset($payload['jobId']),
            jobId: isset($payload['jobId']) ? (string)$payload['jobId'] : null,
            siteId: (string)($payload['siteId'] ?? ''),
            startUrl: (string)($payload['startUrl'] ?? ''),
            sitemapUrl: $sitemapUrl,
            sourceType: (string)($payload['sourceType'] ?? 'crawl'),
            status: (string)($payload['status'] ?? ''),
            pagesScanned: (int)($payload['pagesScanned'] ?? 0),
            pagesFailed: (int)($payload['pagesFailed'] ?? 0),
            issuesTotal: (int)($payload['issuesTotal'] ?? 0),
            issuesNew: (int)($payload['issuesNew'] ?? 0),
            issuesResolved: (int)($payload['issuesResolved'] ?? 0),
            topPages: is_array($payload['topPages'] ?? null) ? array_values($payload['topPages']) : [],
            failedPages: is_array($payload['failedPages'] ?? null) ? array_values($payload['failedPages']) : [],
            topRules: is_array($payload['topRules'] ?? null) ? array_values($payload['topRules']) : [],
            countsByStatus: is_array($payload['countsByStatus'] ?? null) ? array_values($payload['countsByStatus']) : [],
            startedAt: isset($payload['startedAt']) ? (string)$payload['startedAt'] : null,
            finishedAt: isset($payload['finishedAt']) ? (string)$payload['finishedAt'] : null,
            errorCode: isset($error['code']) ? (string)$error['code'] : null,
            errorMessage: isset($error['message']) ? (string)$error['message'] : null,
        );
    }
}