<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\FreePreview\FreeRemotePreviewService;
use Priebera\A11yQualityGate\Pro\Enum\CrawlerJobStatus;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Service\DateTimeService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class RemoteScanRecoveryService
{
    private const STALE_SCAN_TIMEOUT = 900;

    public function __construct(
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly ProCrawlerService $proCrawlerService,
        private readonly RemoteScanPersistenceService $remoteScanPersistenceService,
        private readonly ExtensionContextService $extensionContextService,
        private readonly DateTimeService $dateTimeService,
        private readonly FreeRemotePreviewService $freeRemotePreviewService,
        private readonly RemoteScanErrorPresenter $remoteScanErrorPresenter,
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
        $siteIdentifier = trim((string)($remoteScan['site_identifier'] ?? ''));
        // A job is read back through the channel that submitted it: a Free Preview job belongs to the Free
        // token's subject and a paid job to the licence, whatever the licence is today.
        $isFreePreview = (int)($remoteScan['is_free_preview'] ?? 0) === 1;

        try {
            $statusResult = $isFreePreview
                ? $this->freeRemotePreviewService->getStatus(
                    siteUrl: rtrim($siteBase, '/') . '/',
                    siteIdentifier: $siteIdentifier,
                    version: $version,
                    jobId: $jobId,
                )
                : $this->proCrawlerService->getStatus(
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
                siteBase: $siteBase,
                siteIdentifier: $siteIdentifier,
                isFreePreview: $isFreePreview,
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
        string $siteBase,
        string $siteIdentifier,
        bool $isFreePreview,
    ): ?array {
        try {
            $summaryResult = $isFreePreview
                ? $this->freeRemotePreviewService->getSummary(
                    siteUrl: rtrim($siteBase, '/') . '/',
                    siteIdentifier: $siteIdentifier,
                    version: $version,
                    jobId: $jobId,
                )
                : $this->proCrawlerService->getSummary(
                    domain: $domain,
                    version: $version,
                    jobId: $jobId,
                );

            $resultsResult = $isFreePreview
                ? $this->freeRemotePreviewService->getResults(
                    siteUrl: rtrim($siteBase, '/') . '/',
                    siteIdentifier: $siteIdentifier,
                    version: $version,
                    jobId: $jobId,
                )
                : $this->proCrawlerService->getResults(
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
                'score' => $summaryResult->score,
                'keyboardSummary' => $summaryResult->keyboardSummary,
                'structureSummary' => $summaryResult->structureSummary,
                'contrastDetails' => $summaryResult->contrastDetails,
                'remediationSummary' => $summaryResult->remediationSummary,
                'componentSummary' => $summaryResult->componentSummary,
                'wcagSummary' => $summaryResult->wcagSummary,
                'priorityFixes' => $summaryResult->priorityFixes,
                'reportSummary' => $summaryResult->reportSummary,
                'manualReviewChecklist' => $summaryResult->manualReviewChecklist,
                'reportingGroups' => $summaryResult->reportingGroups,
                'pageUid' => (int)($remoteScan['page_uid'] ?? 0),
                'pages' => $resultsResult->pages,
                'languageUid' => (int)($remoteScan['language_uid'] ?? -1),
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
            $this->logRecoveryFailure('AQG remote scan result persistence failed', $jobId, $exception);
            $this->remoteScanRepository->markSyncError(
                $jobId,
                $this->remoteScanErrorPresenter->storedMessage($exception, 'Remote scan completed but its results could not be saved'),
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
        $this->logRecoveryFailure('AQG remote scan recovery failed', $jobId, $exception);

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
                $this->remoteScanErrorPresenter->storedMessage($exception, 'Recovered stale remote scan'),
            );

            return $this->remoteScanRepository->findScanByJobId($jobId);
        }

        $this->remoteScanRepository->markSyncError(
            $jobId,
            $this->remoteScanErrorPresenter->storedMessage($exception, 'Remote scan status could not be refreshed'),
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

    /**
     * The crawler answers 404 for a job it no longer has and 403 `forbidden_resource` for one the current
     * token may not read. Decided from the HTTP status and API error code, never from the message text.
     */
    private function isMissingRemoteJob(\Throwable $exception): bool
    {
        $apiException = $this->findApiException($exception);

        return $apiException instanceof ApiRequestFailedException
            && ($apiException->httpStatus === 404
                || in_array($apiException->apiErrorCode, ['not_found', 'forbidden_resource'], true));
    }

    private function resolveMissingRemoteJobFailureMessage(\Throwable $exception): string
    {
        return $this->findApiException($exception)?->apiErrorCode === 'forbidden_resource'
            ? 'Remote crawler job is no longer accessible for the current token.'
            : 'Remote crawler job no longer exists.';
    }

    private function findApiException(\Throwable $exception): ?ApiRequestFailedException
    {
        $current = $exception;
        do {
            if ($current instanceof ApiRequestFailedException) {
                return $current;
            }
            $current = $current->getPrevious();
        } while ($current instanceof \Throwable);

        return null;
    }

    private function logRecoveryFailure(string $message, string $jobId, \Throwable $exception): void
    {
        try {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(__CLASS__)
                ->warning($message, [
                    'jobId' => $jobId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
        } catch (\Throwable) {
        }
    }
}
