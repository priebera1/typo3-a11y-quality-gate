<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Dto;

final class CrawlerSubmitResponseDto
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $jobId,
        public readonly ?string $status,
        public readonly ?string $sourceType,
        public readonly ?string $sitemapUrl,
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

        $jobId = isset($payload['jobId']) ? trim((string)$payload['jobId']) : null;
        if ($jobId === '') {
            $jobId = null;
        }

        $status = isset($payload['status']) ? trim((string)$payload['status']) : null;
        if ($status === '') {
            $status = null;
        }

        $sourceType = isset($payload['sourceType']) ? trim((string)$payload['sourceType']) : null;
        if ($sourceType === '') {
            $sourceType = null;
        }

        $sitemapUrl = isset($payload['sitemapUrl']) ? trim((string)$payload['sitemapUrl']) : null;
        if ($sitemapUrl === '') {
            $sitemapUrl = null;
        }

        return new self(
            success: $jobId !== null,
            jobId: $jobId,
            status: $status,
            sourceType: $sourceType,
            sitemapUrl: $sitemapUrl,
            errorCode: isset($error['code']) ? (string)$error['code'] : null,
            errorMessage: isset($error['message']) ? (string)$error['message'] : null,
        );
    }
}