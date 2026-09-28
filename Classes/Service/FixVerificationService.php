<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Domain\Repository\FixVerificationRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Utility\BackendLabelUtility;
use Priebera\A11yQualityGate\Utility\BackendTimeUtility;
use Priebera\A11yQualityGate\Utility\ScanUrlUtility;

/**
 * "Verify fix": checks one finding — a rule on a frontend URL — again with a fresh single-page scan.
 *
 * The outcome is one of three, and only a complete fresh result can clear a finding:
 * - resolved: the verification scan of the same URL completed, the page loaded and its findings were
 *   stored, and the rule is no longer reported there;
 * - still_present: the rule is still reported on that URL;
 * - not_verified: the scan failed or was cancelled, it scanned another language, the page failed to load,
 *   the URL is missing from the result, or its findings were not stored completely (the crawler reported
 *   more issue types for the page than were stored). Never "resolved" for lack of evidence.
 */
final class FixVerificationService
{
    public const OUTCOME_PENDING = 'pending';
    public const OUTCOME_RESOLVED = 'resolved';
    public const OUTCOME_STILL_PRESENT = 'still_present';
    public const OUTCOME_NOT_VERIFIED = 'not_verified';

    public function __construct(
        private readonly FixVerificationRepository $fixVerificationRepository,
        private readonly RemoteIssueRepository $remoteIssueRepository,
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly RemoteScanFindingIndex $remoteScanFindingIndex,
        private readonly ScopeAccessService $scopeAccessService,
    ) {
    }

    /**
     * A stored finding with its page and scan, when the user may read it; null otherwise, so a finding of
     * another site is indistinguishable from one that does not exist.
     *
     * @return array{issue:array<string, mixed>,page:array<string, mixed>,scan:array<string, mixed>,occurrences:int}|null
     */
    public function resolveFinding(int $findingId): ?array
    {
        $issue = $this->remoteIssueRepository->findOneByUid($findingId);
        if (!is_array($issue)) {
            return null;
        }

        $page = $this->remoteScanRepository->findPageByUid((int)($issue['remote_scan_page'] ?? 0));
        if (!is_array($page) || trim((string)($page['url'] ?? '')) === '') {
            return null;
        }

        $scan = $this->scopeAccessService->resolveReadableScanForRemotePage($page);
        if (!is_array($scan) || (int)($scan['uid'] ?? 0) !== (int)($issue['remote_scan'] ?? 0)) {
            return null;
        }

        $occurrences = 0;
        foreach ($this->remoteIssueRepository->findByRemoteScanPage((int)$page['uid']) as $row) {
            if ((string)($row['rule_id'] ?? '') === (string)($issue['rule_id'] ?? '')) {
                $occurrences += max(1, (int)($row['nodes_count'] ?? 0));
            }
        }

        return ['issue' => $issue, 'page' => $page, 'scan' => $scan, 'occurrences' => $occurrences];
    }

    /**
     * @param array{issue:array<string, mixed>,page:array<string, mixed>,scan:array<string, mixed>,occurrences:int} $finding
     */
    public function recordRequest(array $finding, int $pageUid, string $verificationJobId, int $requestedBy): int
    {
        return $this->fixVerificationRepository->insert([
            'site_identifier' => (string)($finding['scan']['site_identifier'] ?? ''),
            'rule_id' => (string)($finding['issue']['rule_id'] ?? ''),
            'url' => (string)($finding['page']['url'] ?? ''),
            'page_uid' => $pageUid,
            'language_uid' => (int)($finding['scan']['language_uid'] ?? -1),
            'baseline_issue' => (int)($finding['issue']['uid'] ?? 0),
            'baseline_scan' => (int)($finding['scan']['uid'] ?? 0),
            'baseline_occurrences' => $finding['occurrences'],
            'verification_job_id' => $verificationJobId,
            'outcome' => self::OUTCOME_PENDING,
            'requested_by' => $requestedBy,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findVerification(int $uid): ?array
    {
        return $this->fixVerificationRepository->findByUid($uid);
    }

    /**
     * Evaluates a pending verification once its scan is stored; a decided verification is returned as is.
     *
     * @param array<string, mixed> $verification
     * @return array<string, mixed> the verification row with outcome, reason and scan status
     */
    public function evaluate(array $verification): array
    {
        if ((string)($verification['outcome'] ?? '') !== self::OUTCOME_PENDING) {
            return $verification;
        }

        $scan = $this->remoteScanRepository->findScanByJobId((string)($verification['verification_job_id'] ?? ''));
        if (!is_array($scan)) {
            return $verification + ['scan_status' => 'unknown'];
        }

        $status = strtolower(trim((string)($scan['status'] ?? '')));
        if (in_array($status, ['failed', 'cancelled'], true)) {
            return $this->decide($verification, self::OUTCOME_NOT_VERIFIED, $status === 'failed' ? 'scan_failed' : 'scan_cancelled', $scan, 0);
        }

        if ($status !== 'completed' || (int)($scan['persisted_at'] ?? 0) <= 0) {
            return $verification + ['scan_status' => $status !== '' ? $status : 'queued'];
        }

        $expectedLanguage = (int)($verification['language_uid'] ?? -1);
        $scannedLanguage = (int)($scan['language_uid'] ?? -1);
        if ($expectedLanguage >= 0 && $scannedLanguage >= 0 && $expectedLanguage !== $scannedLanguage) {
            return $this->decide($verification, self::OUTCOME_NOT_VERIFIED, 'scope_mismatch', $scan, 0);
        }

        $index = $this->remoteScanFindingIndex->build($scan);
        $page = $index[ScanUrlUtility::comparable((string)($verification['url'] ?? ''))] ?? null;
        if ($page === null) {
            return $this->decide($verification, self::OUTCOME_NOT_VERIFIED, 'page_missing', $scan, 0);
        }
        if ($page['failed']) {
            return $this->decide($verification, self::OUTCOME_NOT_VERIFIED, 'page_failed', $scan, 0);
        }

        $rule = $page['rules'][(string)($verification['rule_id'] ?? '')] ?? null;
        if ($rule !== null) {
            return $this->decide($verification, self::OUTCOME_STILL_PRESENT, '', $scan, $rule['occurrences'], $page['remotePageUid']);
        }

        if (!$page['evidenceComplete']) {
            return $this->decide($verification, self::OUTCOME_NOT_VERIFIED, 'evidence_incomplete', $scan, 0);
        }

        return $this->decide($verification, self::OUTCOME_RESOLVED, '', $scan, 0, $page['remotePageUid']);
    }

    /**
     * @param array<string, mixed> $verification
     * @param \Closure(int): string $remotePageUrl
     * @param int $viewedScanFinishedAt when shown beside a stored scan that reports the finding: a Resolved
     *        verdict decided before that scan is superseded by it
     * @return array<string, mixed>
     */
    public function presentOutcome(array $verification, \Closure $remotePageUrl, int $viewedScanFinishedAt = 0): array
    {
        $outcome = (string)($verification['outcome'] ?? self::OUTCOME_PENDING);
        $reason = (string)($verification['outcome_reason'] ?? '');
        $evaluatedAt = (int)($verification['evaluated_at'] ?? 0);

        if ($outcome === self::OUTCOME_RESOLVED && $evaluatedAt > 0 && $viewedScanFinishedAt > $evaluatedAt) {
            return [
                'verificationUid' => (int)($verification['uid'] ?? 0),
                'outcome' => 'superseded',
                'reason' => '',
                'label' => BackendLabelUtility::translate('verifyFix.outcome.superseded', 'Resolved earlier'),
                'message' => sprintf(
                    BackendLabelUtility::translate('verifyFix.message.superseded', 'A check on %s found this resolved. This newer scan reports it again.'),
                    BackendTimeUtility::formatDateTime($evaluatedAt)
                ),
                'ruleId' => (string)($verification['rule_id'] ?? ''),
                'baselineOccurrences' => (int)($verification['baseline_occurrences'] ?? 0),
                'remainingOccurrences' => 0,
                'scanStatus' => '',
                'verificationJobId' => (string)($verification['verification_job_id'] ?? ''),
                'siteIdentifier' => (string)($verification['site_identifier'] ?? ''),
                'evaluatedAt' => '',
                'verificationPageUrl' => '',
            ];
        }

        [$labelKey, $labelFallback] = match ($outcome) {
            self::OUTCOME_RESOLVED => ['verifyFix.outcome.resolved', 'Resolved'],
            self::OUTCOME_STILL_PRESENT => ['verifyFix.outcome.stillPresent', 'Still present'],
            self::OUTCOME_NOT_VERIFIED => ['verifyFix.outcome.notVerified', 'Not verified'],
            default => ['verifyFix.outcome.pending', 'Checking…'],
        };

        $message = match (true) {
            $outcome === self::OUTCOME_RESOLVED => BackendLabelUtility::translate('verifyFix.message.resolved', 'The fresh scan of this page no longer reports this issue. Manual review may still be required.'),
            $outcome === self::OUTCOME_STILL_PRESENT => sprintf(
                BackendLabelUtility::translate('verifyFix.message.stillPresent', 'The fresh scan still reports this issue on the page (%d occurrences).'),
                (int)($verification['remaining_occurrences'] ?? 0)
            ),
            $outcome === self::OUTCOME_NOT_VERIFIED => match ($reason) {
                'scan_cancelled' => BackendLabelUtility::translate('verifyFix.reason.scanCancelled', 'The verification scan was cancelled. Nothing was verified.'),
                'page_failed' => BackendLabelUtility::translate('verifyFix.reason.pageFailed', 'The page did not load for the scanner, so the fix could not be checked.'),
                'page_missing' => BackendLabelUtility::translate('verifyFix.reason.pageMissing', 'The verification scan has no result for this URL, so the fix could not be checked.'),
                'evidence_incomplete' => BackendLabelUtility::translate('verifyFix.reason.evidenceIncomplete', 'The fresh scan reported more issue types for this page than AQG stored, so the fix could not be confirmed.'),
                'scope_mismatch' => BackendLabelUtility::translate('verifyFix.reason.scopeMismatch', 'The verification scan checked another language version of the page, so the fix could not be confirmed.'),
                default => BackendLabelUtility::translate('verifyFix.reason.scanFailed', 'The verification scan failed. Nothing was verified; try again later.'),
            },
            default => BackendLabelUtility::translate('verifyFix.message.pending', 'A fresh scan of this page is running.'),
        };

        return [
            'verificationUid' => (int)($verification['uid'] ?? 0),
            'outcome' => $outcome,
            'reason' => $reason,
            'label' => BackendLabelUtility::translate($labelKey, $labelFallback),
            'message' => $message,
            'ruleId' => (string)($verification['rule_id'] ?? ''),
            'baselineOccurrences' => (int)($verification['baseline_occurrences'] ?? 0),
            'remainingOccurrences' => (int)($verification['remaining_occurrences'] ?? 0),
            'scanStatus' => (string)($verification['scan_status'] ?? ''),
            'verificationJobId' => (string)($verification['verification_job_id'] ?? ''),
            'siteIdentifier' => (string)($verification['site_identifier'] ?? ''),
            'evaluatedAt' => $evaluatedAt > 0 ? BackendTimeUtility::formatDateTime($evaluatedAt) : '',
            'verificationPageUrl' => $remotePageUrl((int)($verification['verification_remote_page'] ?? 0)),
        ];
    }

    /**
     * @param array<string, mixed> $verification
     * @param array<string, mixed> $scan
     * @return array<string, mixed>
     */
    private function decide(array $verification, string $outcome, string $reason, array $scan, int $remaining, int $remotePageUid = 0): array
    {
        $this->fixVerificationRepository->storeOutcome(
            (int)($verification['uid'] ?? 0),
            $outcome,
            $reason,
            (int)($scan['uid'] ?? 0),
            $remaining,
        );

        return array_replace($verification, [
            'outcome' => $outcome,
            'outcome_reason' => $reason,
            'verification_scan' => (int)($scan['uid'] ?? 0),
            'remaining_occurrences' => $remaining,
            'evaluated_at' => time(),
            'verification_remote_page' => $remotePageUid,
            'scan_status' => (string)($scan['status'] ?? ''),
        ]);
    }
}
