<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Domain\Repository\MonitoringRunRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Service\ProCapabilityService;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanAccessSettingsService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanInputResolver;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanRecoveryService;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Scheduled frontend monitoring of one site and language (a11y:monitor).
 *
 * Each run finishes the previous monitoring scan if it is still pending, otherwise starts a site scan and
 * waits for it for a bounded time. A finished scan is compared with the baseline: the scan of the latest
 * monitoring run whose coverage was complete (for the first run, the newest compatible scan that is complete
 * on its own). Only a change in state is notified: new or regressed issue types, an incomplete scan, or a
 * failed scan — never a run whose result is the same as the last one, and never "no regression" mail.
 *
 * Coverage is part of the result. When the current scan misses, fails or stores incompletely a page the
 * baseline had checked, the run is incomplete — never "clear" — and its scan never becomes a baseline, so
 * missing evidence cannot silently turn into the reference for the next run.
 */
final class RemoteMonitoringService
{
    public const OUTCOME_NOT_ENTITLED = 'not_entitled';
    public const OUTCOME_BUSY = 'busy';
    public const OUTCOME_WAITING = 'waiting';
    public const OUTCOME_NO_BASELINE = 'no_baseline';
    public const OUTCOME_CLEAR = 'clear';
    public const OUTCOME_REGRESSION = 'regression';
    public const OUTCOME_INCOMPLETE = 'incomplete';
    public const OUTCOME_FAILED = 'failed';

    private const POLL_SECONDS = 15;
    private const ACTIVE_STATES = ['waiting', 'queued', 'active', 'running'];

    public function __construct(
        private readonly SiteLanguageService $siteLanguageService,
        private readonly RemoteScanInputResolver $remoteScanInputResolver,
        private readonly ProCapabilityService $proCapabilityService,
        private readonly ExtensionContextService $extensionContextService,
        private readonly ProCrawlerService $proCrawlerService,
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly RemoteScanRecoveryService $remoteScanRecoveryService,
        private readonly RemoteScanAccessSettingsService $remoteScanAccessSettingsService,
        private readonly RemoteScanPairingService $remoteScanPairingService,
        private readonly ScanComparisonService $scanComparisonService,
        private readonly RemoteScanFindingIndex $remoteScanFindingIndex,
        private readonly MonitoringRunRepository $monitoringRunRepository,
        private readonly MonitoringNotifier $monitoringNotifier,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /**
     * @return array{valid:bool,multiSite:bool}
     */
    public function resolveEntitlement(Site $site): array
    {
        $status = $this->proCapabilityService->getStatus(
            $this->extensionContextService->getNormalizedDomainFromSiteBase((string)$site->getBase()),
            $this->extensionContextService->getExtensionVersion()
        );

        // Monitoring is a PRO/Agency feature: a trial keeps its limited scan budget for its own scans.
        return [
            'valid' => (bool)$status->valid && (bool)$status->hasCrawler && !(bool)$status->isTrial,
            'multiSite' => (bool)$status->hasMultiSite,
        ];
    }

    /**
     * @param list<string> $recipients
     * @param (\Closure(int): void)|null $sleep
     * @return array{outcome:string,runUid:int,jobId:string,notified:bool,summary:array<string, int>}
     */
    public function run(
        Site $site,
        int $languageUid,
        int $maxPages,
        int $maxWaitSeconds,
        array $recipients,
        string $backendBaseUrl,
        ?\Closure $sleep = null,
    ): array {
        $sleep ??= static function (int $seconds): void {
            sleep($seconds);
        };

        if (!$this->resolveEntitlement($site)['valid']) {
            return $this->result(self::OUTCOME_NOT_ENTITLED);
        }

        $siteIdentifier = $site->getIdentifier();
        $pending = $this->monitoringRunRepository->findPending($siteIdentifier, $languageUid);
        if (is_array($pending)) {
            $finished = $this->finishRun($site, $pending, $maxWaitSeconds, $recipients, $backendBaseUrl, $sleep);
            if ($finished['outcome'] === self::OUTCOME_WAITING) {
                // The previous monitoring scan is still running: never pile up scans of the same site.
                return $finished;
            }
        }

        $run = $this->submit($site, $languageUid, $maxPages);
        if (!is_array($run)) {
            return $this->result(self::OUTCOME_BUSY);
        }

        return $this->finishRun($site, $run, $maxWaitSeconds, $recipients, $backendBaseUrl, $sleep);
    }

    /**
     * @return array<string, mixed>|null the new run, or null when another scan of the site is active
     */
    private function submit(Site $site, int $languageUid, int $maxPages): ?array
    {
        $siteIdentifier = $site->getIdentifier();
        $lock = $this->lockFactory->createLocker(
            'aqg_remote_scan:' . sha1($siteIdentifier),
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE
        );
        // The same per-site submit lock as the backend: a backend scan and a monitoring scan never start together.
        if (!$lock->acquire()) {
            return null;
        }

        try {
            $active = $this->remoteScanRepository->findLatestActiveScanBySite($siteIdentifier);
            if (is_array($active)) {
                $active = $this->remoteScanRecoveryService->recoverScanIfNeeded($active, (string)$site->getBase()) ?? $active;
                if (in_array((string)($active['status'] ?? ''), self::ACTIVE_STATES, true)) {
                    return null;
                }
            }

            $language = $this->siteLanguageService->resolveLanguageContext($site, $languageUid);
            $resolved = $language !== null
                ? $this->remoteScanInputResolver->resolveForOverviewLanguage($site, $language, $maxPages)
                : $this->remoteScanInputResolver->resolveForOverview($site, $maxPages);
            $settings = $this->remoteScanAccessSettingsService->buildForSite($siteIdentifier);
            $languageId = $language !== null ? (int)$language['languageId'] : null;

            $result = $this->proCrawlerService->submit(
                domain: $resolved->domain,
                version: $this->extensionContextService->getExtensionVersion(),
                siteId: $resolved->siteIdentifier,
                startUrl: $resolved->startUrl,
                sitemapUrl: $resolved->sitemapUrl,
                sourceType: $resolved->sourceType,
                maxPages: $resolved->maxPages,
                followLinks: $resolved->followLinks,
                axeLocale: $resolved->axeLocale,
                captureScreenshot: false,
                cookieDismiss: true,
                scannerPreviewToken: $settings['scannerPreviewToken'],
                httpAuthUser: $settings['httpAuthUser'],
                httpAuthPass: $settings['httpAuthPass'],
                excludedPatterns: $settings['excludedPatterns'],
                priorityUrls: $settings['priorityUrls'],
                cookieSelectors: $settings['cookieSelectors'],
                languageId: $languageId,
                languageCode: $this->siteLanguageService->resolveLanguageCode($language),
            );

            $this->remoteScanRepository->markSubmitted(
                siteIdentifier: $resolved->siteIdentifier,
                jobId: $result->jobId,
                sourceType: $resolved->sourceType,
                startUrl: $resolved->startUrl,
                sitemapUrl: $resolved->sitemapUrl,
                status: $result->status,
                scanScope: 'site',
                languageUid: $languageId ?? -1,
            );
            $uid = $this->monitoringRunRepository->insertSubmitted($siteIdentifier, $languageUid, $result->jobId);

            return ['uid' => $uid, 'site_identifier' => $siteIdentifier, 'language_uid' => $languageUid, 'job_id' => $result->jobId, 'status' => 'submitted'];
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, mixed> $run
     * @param list<string> $recipients
     * @param \Closure(int): void $sleep
     * @return array{outcome:string,runUid:int,jobId:string,notified:bool,summary:array<string, int>}
     */
    private function finishRun(Site $site, array $run, int $maxWaitSeconds, array $recipients, string $backendBaseUrl, \Closure $sleep): array
    {
        $runUid = (int)$run['uid'];
        $jobId = (string)$run['job_id'];
        $waited = 0;

        while (true) {
            $scan = $this->remoteScanRepository->findScanByJobId($jobId);
            if (!is_array($scan)) {
                $this->monitoringRunRepository->update($runUid, ['status' => 'evaluated', 'outcome' => self::OUTCOME_FAILED, 'evaluated_at' => time()]);
                return $this->result(self::OUTCOME_FAILED, $runUid, $jobId);
            }

            $scan = $this->remoteScanRecoveryService->recoverScanIfNeeded($scan, (string)$site->getBase()) ?? $scan;
            $status = (string)($scan['status'] ?? '');
            if (in_array($status, ['failed', 'cancelled'], true)
                || ($status === 'completed' && (int)($scan['persisted_at'] ?? 0) > 0)) {
                return $this->evaluate($site, $run, $scan, $recipients, $backendBaseUrl);
            }

            if ($waited >= $maxWaitSeconds) {
                return $this->result(self::OUTCOME_WAITING, $runUid, $jobId);
            }

            $sleep(self::POLL_SECONDS);
            $waited += self::POLL_SECONDS;
        }
    }

    /**
     * @param array<string, mixed> $run
     * @param array<string, mixed> $scan
     * @param list<string> $recipients
     * @return array{outcome:string,runUid:int,jobId:string,notified:bool,summary:array<string, int>}
     */
    private function evaluate(Site $site, array $run, array $scan, array $recipients, string $backendBaseUrl): array
    {
        $runUid = (int)$run['uid'];
        $jobId = (string)$run['job_id'];
        $baseline = null;
        $comparison = null;
        $gaps = [];
        $coverageComplete = false;

        if ((string)($scan['status'] ?? '') !== 'completed') {
            $outcome = self::OUTCOME_FAILED;
            $fingerprint = 'failed';
        } else {
            $current = $this->remoteScanFindingIndex->build($scan);
            $baseline = $this->findBaseline($run, $scan);
            if ($baseline === null) {
                $gaps = $this->standaloneGaps($current);
                $coverageComplete = $gaps === [];
                $outcome = $coverageComplete ? self::OUTCOME_NO_BASELINE : self::OUTCOME_INCOMPLETE;
                $fingerprint = $coverageComplete ? '' : $this->gapFingerprint($gaps);
            } else {
                $baselineIndex = $this->remoteScanFindingIndex->build($baseline);
                $comparison = $this->scanComparisonService->compareIndexes($baselineIndex, $current);
                $gaps = $this->coverageGaps($baselineIndex, $current);
                $coverageComplete = $gaps === [];
                $regression = $this->scanComparisonService->regressionFingerprint($comparison);
                if ($regression !== '') {
                    $outcome = self::OUTCOME_REGRESSION;
                    $fingerprint = 'regression:' . $regression;
                } elseif (!$coverageComplete) {
                    $outcome = self::OUTCOME_INCOMPLETE;
                    $fingerprint = $this->gapFingerprint($gaps);
                } else {
                    $outcome = self::OUTCOME_CLEAR;
                    $fingerprint = '';
                }
            }
        }

        $summary = [
            'newIssueTypes' => count($comparison['new'] ?? []),
            'regressedIssueTypes' => count($comparison['regressed'] ?? []),
            'fixedIssueTypes' => count($comparison['fixed'] ?? []),
            'comparedPages' => (int)($comparison['comparedPages'] ?? 0),
            'unverifiedPages' => count($comparison['unverified'] ?? []),
            'coverageGaps' => count($gaps),
        ];

        // Only a state that was actually delivered suppresses a repeat. notified_at carries the delivery of the
        // same state forward across unchanged runs; a failed or impossible delivery leaves it 0, so the next
        // run with the same state tries again instead of staying silent for good.
        $previous = $this->monitoringRunRepository->findPreviousEvaluated((string)$run['site_identifier'], (int)$run['language_uid'], $runUid);
        $previousDelivery = is_array($previous) ? (int)($previous['notified_at'] ?? 0) : 0;
        $unchanged = $fingerprint !== ''
            && is_array($previous)
            && (string)($previous['state_fingerprint'] ?? '') === $fingerprint
            && $previousDelivery > 0;
        $notify = in_array($outcome, [self::OUTCOME_REGRESSION, self::OUTCOME_INCOMPLETE, self::OUTCOME_FAILED], true)
            && !$unchanged
            && $recipients !== [];

        $notified = $notify && $this->monitoringNotifier->send($recipients, [
            'outcome' => $outcome,
            'site' => $site,
            'scan' => $scan,
            'baseline' => $baseline,
            'comparison' => $comparison,
            'gaps' => $gaps,
            'backendBaseUrl' => $backendBaseUrl,
            'languageUid' => (int)$run['language_uid'],
        ]);

        $this->monitoringRunRepository->update($runUid, [
            'status' => 'evaluated',
            'outcome' => $outcome,
            'baseline_job_id' => is_array($baseline) ? (string)($baseline['job_id'] ?? '') : '',
            'new_issue_types' => $summary['newIssueTypes'],
            'regressed_issue_types' => $summary['regressedIssueTypes'],
            'state_fingerprint' => $fingerprint,
            'coverage_complete' => $coverageComplete ? 1 : 0,
            'notified_at' => $notified ? time() : ($unchanged ? $previousDelivery : 0),
            'evaluated_at' => time(),
        ]);

        return $this->result($outcome, $runUid, $jobId, $notified, $summary);
    }

    /**
     * The scan of the latest monitoring run with complete coverage; before there is one, the newest compatible
     * scan that is complete on its own and was not rejected as a monitoring baseline.
     *
     * @param array<string, mixed> $run
     * @param array<string, mixed> $scan
     * @return array<string, mixed>|null
     */
    private function findBaseline(array $run, array $scan): ?array
    {
        $trusted = $this->monitoringRunRepository->findLatestTrustedRun((string)$run['site_identifier'], (int)$run['language_uid'], (int)$run['uid']);
        if (is_array($trusted)) {
            $candidate = $this->remoteScanRepository->findScanByJobId((string)($trusted['job_id'] ?? ''));
            if (is_array($candidate)
                && (string)($candidate['status'] ?? '') === 'completed'
                && (int)($candidate['persisted_at'] ?? 0) > 0
                && (int)($candidate['uid'] ?? 0) !== (int)($scan['uid'] ?? 0)
                && $this->remoteScanPairingService->isCompatible($candidate, $scan)) {
                return $candidate;
            }
        }

        return $this->remoteScanPairingService->findPreviousCompatible(
            $scan,
            fn (array $candidate): bool => !$this->monitoringRunRepository->isUntrustedRunJob((string)($candidate['job_id'] ?? ''))
                && $this->standaloneGaps($this->remoteScanFindingIndex->build($candidate)) === []
        );
    }

    /**
     * What the current scan failed to cover that the baseline had checked, plus current pages that were not
     * checked completely. A page that failed in both scans is a known broken URL, not lost coverage.
     *
     * @param array<string, array<string, mixed>> $baseline
     * @param array<string, array<string, mixed>> $current
     * @return list<array{url:string,reason:string}>
     */
    private function coverageGaps(array $baseline, array $current): array
    {
        if ($current === []) {
            return [['url' => '', 'reason' => 'no_pages']];
        }

        $gaps = [];
        foreach ($baseline as $key => $before) {
            if ($before['failed'] || !$before['evidenceComplete']) {
                continue;
            }
            $after = $current[$key] ?? null;
            $reason = match (true) {
                $after === null => 'not_in_current',
                $after['failed'] => 'page_failed',
                !$after['evidenceComplete'] => 'evidence_incomplete',
                default => '',
            };
            if ($reason !== '') {
                $gaps[] = ['url' => (string)$before['url'], 'reason' => $reason];
            }
        }

        foreach ($current as $key => $after) {
            $before = $baseline[$key] ?? null;
            if (is_array($before) && !$before['failed'] && $before['evidenceComplete']) {
                continue;
            }
            if ($after['failed'] && !(is_array($before) && $before['failed'])) {
                $gaps[] = ['url' => (string)$after['url'], 'reason' => 'page_failed'];
            } elseif (!$after['failed'] && !$after['evidenceComplete']) {
                $gaps[] = ['url' => (string)$after['url'], 'reason' => 'evidence_incomplete'];
            }
        }

        usort($gaps, static fn (array $a, array $b): int => [$a['url'], $a['reason']] <=> [$b['url'], $b['reason']]);

        return $gaps;
    }

    /**
     * Whether a scan can be a baseline without an earlier one: it checked at least one page completely and
     * stored every checked page's findings completely.
     *
     * @param array<string, array<string, mixed>> $index
     * @return list<array{url:string,reason:string}>
     */
    private function standaloneGaps(array $index): array
    {
        $gaps = [];
        $checked = 0;
        foreach ($index as $page) {
            if ($page['failed']) {
                continue;
            }
            if ($page['evidenceComplete']) {
                $checked++;
            } else {
                $gaps[] = ['url' => (string)$page['url'], 'reason' => 'evidence_incomplete'];
            }
        }
        if ($checked === 0 && $gaps === []) {
            $gaps[] = ['url' => '', 'reason' => 'no_pages'];
        }

        return $gaps;
    }

    /**
     * @param list<array{url:string,reason:string}> $gaps
     */
    private function gapFingerprint(array $gaps): string
    {
        return 'incomplete:' . hash('sha256', implode("\n", array_map(static fn (array $gap): string => $gap['reason'] . '|' . $gap['url'], $gaps)));
    }

    /**
     * @param array<string, int> $summary
     * @return array{outcome:string,runUid:int,jobId:string,notified:bool,summary:array<string, int>}
     */
    private function result(string $outcome, int $runUid = 0, string $jobId = '', bool $notified = false, array $summary = []): array
    {
        return ['outcome' => $outcome, 'runUid' => $runUid, 'jobId' => $jobId, 'notified' => $notified, 'summary' => $summary];
    }
}
