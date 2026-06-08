<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Dto;

final class CrawlerResultsResponseDto
{
    /**
     * @param array<int, array<string, mixed>> $pages
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
        public readonly int $issuesTotal,
        public readonly int $issuesNew,
        public readonly int $issuesResolved,
        public readonly array $pages,
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

        $sitemapUrl = isset($payload['sitemapUrl']) ? trim((string)$payload['sitemapUrl']) : (isset($payload['sitemap_url']) ? trim((string)$payload['sitemap_url']) : null);
        if ($sitemapUrl === '') {
            $sitemapUrl = null;
        }

        return new self(
            success: isset($payload['jobId']) || isset($payload['job_id']),
            jobId: isset($payload['jobId']) ? (string)$payload['jobId'] : (isset($payload['job_id']) ? (string)$payload['job_id'] : null),
            siteId: (string)($payload['siteId'] ?? $payload['site_id'] ?? ''),
            startUrl: (string)($payload['startUrl'] ?? $payload['start_url'] ?? ''),
            sitemapUrl: $sitemapUrl,
            sourceType: (string)($payload['sourceType'] ?? $payload['source_type'] ?? 'crawl'),
            status: (string)($payload['status'] ?? ''),
            pagesScanned: (int)($payload['pagesScanned'] ?? $payload['pages_scanned'] ?? 0),
            issuesTotal: (int)($payload['issuesTotal'] ?? $payload['issues_total'] ?? 0),
            issuesNew: (int)($payload['issuesNew'] ?? $payload['issues_new'] ?? 0),
            issuesResolved: (int)($payload['issuesResolved'] ?? $payload['issues_resolved'] ?? 0),
            pages: is_array($payload['pages'] ?? null) ? array_values($payload['pages']) : [],
            errorCode: isset($error['code']) ? (string)$error['code'] : null,
            errorMessage: isset($error['message']) ? (string)$error['message'] : null,
        );
    }
}
