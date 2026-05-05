<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Enum\CrawlerJobStatus;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Service\DateTimeService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;

final class RemoteScanRecoveryService
{
    private const STALE_SCAN_TIMEOUT = 900;

    public function __construct(
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly ProCrawlerService $proCrawlerService,
        private readonly RemoteScanPersistenceService $remoteScanPersistenceService,
        private readonly ExtensionContextService $extensionContextService,
        private readonly DateTimeService $dateTimeService,
    ) {
    }

    /**
     * @param array<string, mixed> $remoteScan
     * @return array<string, mixed>|null
     */
    public function recoverScanIfNeeded(array $remoteScan, string $siteBase): ?array
    {
        $jobId = trim((string)($remoteScan['job_id'] ?? ''));
        if ($jobId === '') {
            return $remoteScan;
        }

        $status = trim((string)($remoteScan['status'] ?? ''));
        if (!in_array($status, ['waiting', 'queued', 'active', 'running', 'completed'], true)) {
            return $remoteScan;
        }

        $persistedAt = (int)($remoteScan['persisted_at'] ?? 0);
        if ($status === 'completed' && $persistedAt > 0) {
            return $remoteScan;
        }

        $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase($siteBase);
        if ($domain === '') {
            return $remoteScan;
        }

        $version = $this->extensionContextService->getExtensionVersion();

        try {
            $statusResult = $this->proCrawlerService->getStatus(
                domain: $domain,
                version: $version,
                jobId: $jobId,
            );
        } catch (\Throwable $exception) {
            return $this->handleRecoveryFailure($remoteScan, $jobId, $exception);
        }

        $this->remoteScanRepository->syncStatus(
            jobId: $jobId,
            status: $statusResult->status->value,
            pagesScanned: $statusResult->pagesScanned,
            pagesTotal: $statusResult->pagesTotal,
            startedAt: $this->dateTimeService->toNullableTimestamp($statusResult->startedAt),
            finishedAt: $this->dateTimeService->toNullableTimestamp($statusResult->finishedAt),
        );

        if ($statusResult->status === CrawlerJobStatus::Completed) {
            return $this->persistCompletedScan(
                remoteScan: $remoteScan,
                jobId: $jobId,
                domain: $domain,
                version: $version,
            );
        }

        if ($statusResult->status === CrawlerJobStatus::Failed) {
            $this->remoteScanRepository->markFailed(
                $jobId,
                'Remote crawler job failed.',
            );

            return $this->remoteScanRepository->findScanByJobId($jobId);
        }

        return $this->remoteScanRepository->findScanByJobId($jobId) ?? $remoteScan;
    }

    /**
     * @param array<string, mixed> $remoteScan
     * @return array<string, mixed>|null
     */
    private function persistCompletedScan(
        array $remoteScan,
        string $jobId,
        string $domain,
        string $version,
    ): ?array {
        try {
            $summaryResult = $this->proCrawlerService->getSummary(
                domain: $domain,
                version: $version,
                jobId: $jobId,
            );

            $resultsResult = $this->proCrawlerService->getResults(
                domain: $domain,
                version: $version,
                jobId: $jobId,
            );

            $sourceType = RemoteScanSourceType::tryFrom((string)($remoteScan['source_type'] ?? ''))
                ?? RemoteScanSourceType::Crawl;

            $resultsPayload = [
                'status' => $summaryResult->status,
                'pagesScanned' => $summaryResult->pagesScanned,
                'pagesFailed' => $summaryResult->pagesFailed,
                'issuesTotal' => $summaryResult->issuesTotal,
                'issuesNew' => $summaryResult->issuesNew,
                'issuesResolved' => $summaryResult->issuesResolved,
                'startedAt' => $summaryResult->startedAt,
                'finishedAt' => $summaryResult->finishedAt,
                'pagesTotal' => $summaryResult->pagesScanned,
                'pageUid' => (int)($remoteScan['page_uid'] ?? 0),
                'pages' => $resultsResult->pages,
            ];

            $this->remoteScanPersistenceService->persistResults(
                siteIdentifier: (string)($remoteScan['site_identifier'] ?? ''),
                jobId: $jobId,
                sourceType: $sourceType,
                startUrl: (string)($remoteScan['start_url'] ?? ''),
                sitemapUrl: ($remoteScan['sitemap_url'] ?? null) ?: null,
                resultsData: $resultsPayload,
            );
        } catch (\Throwable $exception) {
            $this->remoteScanRepository->markSyncError(
                $jobId,
                'Remote scan completed but result persistence failed: ' . $this->extractPrimaryExceptionMessage($exception),
            );

            return $this->remoteScanRepository->findScanByJobId($jobId) ?? $remoteScan;
        }

        return $this->remoteScanRepository->findScanByJobId($jobId) ?? $remoteScan;
    }

    /**
     * @param array<string, mixed> $remoteScan
     * @return array<string, mixed>|null
     */
    private function handleRecoveryFailure(
        array $remoteScan,
        string $jobId,
        \Throwable $exception,
    ): ?array {
        $message = $this->extractPrimaryExceptionMessage($exception);

        if ($this->isMissingRemoteJob($exception)) {
            $this->remoteScanRepository->markFailed(
                $jobId,
                $this->resolveMissingRemoteJobFailureMessage($exception),
            );

            return $this->remoteScanRepository->findScanByJobId($jobId);
        }

        if ($this->isStaleRunningScan($remoteScan)) {
            $this->remoteScanRepository->markFailed(
                $jobId,
                'Recovered stale remote scan: ' . $message,
            );

            return $this->remoteScanRepository->findScanByJobId($jobId);
        }

        $this->remoteScanRepository->markSyncError(
            $jobId,
            'Remote scan recovery failed: ' . $message,
        );

        return $this->remoteScanRepository->findScanByJobId($jobId) ?? $remoteScan;
    }

    /**
     * @param array<string, mixed> $remoteScan
     */
    private function isStaleRunningScan(array $remoteScan): bool
    {
        $status = trim((string)($remoteScan['status'] ?? ''));

        if (!in_array($status, ['waiting', 'queued', 'active', 'running'], true)) {
            return false;
        }

        $lastSyncedAt = (int)($remoteScan['last_synced_at'] ?? 0);
        $startedAt = (int)($remoteScan['started_at'] ?? 0);
        $lastActivityAt = max($lastSyncedAt, $startedAt);

        if ($lastActivityAt <= 0) {
            return false;
        }

        return ($lastActivityAt + self::STALE_SCAN_TIMEOUT) < time();
    }

    private function isMissingRemoteJob(\Throwable $exception): bool
    {
        foreach ($this->collectExceptionMessages($exception) as $message) {
            $normalized = strtolower($message);

            if (
                str_contains($normalized, 'http 404')
                || str_contains($normalized, 'http=404')
                || str_contains($normalized, 'not_found')
                || str_contains($normalized, 'resource not found')
                || str_contains($normalized, 'job not found')
                || str_contains($normalized, 'no longer exists')
                || str_contains($normalized, 'forbidden_resource')
                || str_contains($normalized, 'does not belong to the current token')
            ) {
                return true;
            }
        }

        return false;
    }

    private function resolveMissingRemoteJobFailureMessage(\Throwable $exception): string
    {
        foreach ($this->collectExceptionMessages($exception) as $message) {
            $normalized = strtolower($message);

            if (
                str_contains($normalized, 'forbidden_resource')
                || str_contains($normalized, 'does not belong to the current token')
            ) {
                return 'Remote crawler job is no longer accessible for the current token.';
            }
        }

        return 'Remote crawler job no longer exists.';
    }

    private function extractPrimaryExceptionMessage(\Throwable $exception): string
    {
        foreach ($this->collectExceptionMessages($exception) as $message) {
            $message = trim($message);
            if ($message !== '') {
                return $message;
            }
        }

        return $exception::class;
    }

    /**
     * @return list<string>
     */
    private function collectExceptionMessages(\Throwable $exception): array
    {
        $messages = [];
        $current = $exception;

        do {
            $message = trim($current->getMessage());
            if ($message !== '') {
                $messages[] = $message;
            }

            $current = $current->getPrevious();
        } while ($current instanceof \Throwable);

        return $messages;
    }
}