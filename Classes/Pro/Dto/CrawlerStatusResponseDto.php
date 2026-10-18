<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Dto;

final class CrawlerStatusResponseDto
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $jobId,
        public readonly ?string $status,
        public readonly int $pagesScanned,
        public readonly ?int $pagesTotal,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly ?string $failureCode = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $progress = is_array($payload['progress'] ?? null) ? $payload['progress'] : [];

        $jobId = isset($payload['jobId']) ? trim((string)$payload['jobId']) : null;
        if ($jobId === '') {
            $jobId = null;
        }

        $status = isset($payload['status']) ? trim((string)$payload['status']) : null;
        if ($status === '') {
            $status = null;
        }

        // Why a failed job failed (crawler `errorCode`, a bounded identifier). The crawler's free-text `error` is
        // operator detail and is never read.
        $failureCode = is_string($payload['errorCode'] ?? null) ? trim($payload['errorCode']) : '';
        if (preg_match('/^[a-z0-9_]{1,64}$/', $failureCode) !== 1) {
            $failureCode = '';
        }

        $rawPagesScanned = $progress['pagesScanned'] ?? $payload['pagesScanned'] ?? 0;
        $rawPagesTotal = $progress['pagesTotal'] ?? $payload['pagesTotal'] ?? null;

        return new self(
            success: $jobId !== null && $status !== null,
            jobId: $jobId,
            status: $status,
            pagesScanned: (int)$rawPagesScanned,
            pagesTotal: $rawPagesTotal !== null ? (int)$rawPagesTotal : null,
            startedAt: isset($payload['startedAt']) ? (string)$payload['startedAt'] : null,
            finishedAt: isset($payload['finishedAt']) ? (string)$payload['finishedAt'] : null,
            errorCode: isset($error['code']) ? (string)$error['code'] : null,
            errorMessage: isset($error['message']) ? (string)$error['message'] : null,
            failureCode: $failureCode !== '' ? $failureCode : null,
        );
    }
}
