<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Utility\ScanUrlUtility;

/**
 * Which two stored frontend scans may be compared.
 *
 * A comparison, a regression signal, a fix verification and acceptance evidence all claim that something
 * changed between two scans. That only holds when both scans looked at the same thing: the same site,
 * scope and scan type, the same language, the same start URL, and the same kind of scan (a Free Remote
 * Preview covers less than a paid scan). Anything else is a different measurement, never a baseline.
 */
final class RemoteScanPairingService
{
    private const PAIRING_CANDIDATE_LIMIT = 50;

    public function __construct(
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly ScopeAccessService $scopeAccessService,
    ) {
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function isCompatible(array $a, array $b): bool
    {
        return (string)($a['site_identifier'] ?? '') !== ''
            && (string)($a['site_identifier'] ?? '') === (string)($b['site_identifier'] ?? '')
            && strtolower((string)($a['status'] ?? '')) === 'completed'
            && strtolower((string)($b['status'] ?? '')) === 'completed'
            && (string)($a['scan_scope'] ?? '') === (string)($b['scan_scope'] ?? '')
            && strtolower((string)($a['source_type'] ?? '')) === strtolower((string)($b['source_type'] ?? ''))
            && (int)($a['language_uid'] ?? -1) === (int)($b['language_uid'] ?? -1)
            && (int)($a['is_free_preview'] ?? 0) === (int)($b['is_free_preview'] ?? 0)
            && ScanUrlUtility::comparable((string)($a['start_url'] ?? '')) === ScanUrlUtility::comparable((string)($b['start_url'] ?? ''));
    }

    /**
     * Both job IDs must be stored scans of this site that the user may read and that are compatible;
     * the earlier one becomes the baseline. Null for anything else, including a tampered job ID.
     *
     * @return array{from:array<string, mixed>,to:array<string, mixed>}|null
     */
    public function resolveComparePair(string $siteIdentifier, string $fromJobId, string $toJobId): ?array
    {
        $from = $this->scopeAccessService->resolveReadableScanByJobId($fromJobId, $siteIdentifier);
        $to = $this->scopeAccessService->resolveReadableScanByJobId($toJobId, $siteIdentifier);
        if (!is_array($from) || !is_array($to) || $from['uid'] === $to['uid'] || !$this->isCompatible($from, $to)) {
            return null;
        }

        if ($this->finishedAt($from) > $this->finishedAt($to)) {
            [$from, $to] = [$to, $from];
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * The newest completed, persisted scan before the given one that is compatible with it — and, when given,
     * that the caller accepts (monitoring skips scans that are no trustworthy baseline).
     *
     * @param array<string, mixed> $scan
     * @param (\Closure(array<string, mixed>): bool)|null $accept
     * @return array<string, mixed>|null
     */
    public function findPreviousCompatible(array $scan, ?\Closure $accept = null): ?array
    {
        $candidates = $this->remoteScanRepository->findCompletedScansForPairing(
            siteIdentifier: (string)($scan['site_identifier'] ?? ''),
            scanScope: (string)($scan['scan_scope'] ?? ''),
            sourceType: (string)($scan['source_type'] ?? ''),
            languageUid: (int)($scan['language_uid'] ?? -1),
            isFreePreview: (int)($scan['is_free_preview'] ?? 0) === 1,
            finishedBefore: $this->finishedAt($scan),
            excludeUid: (int)($scan['uid'] ?? 0),
            limit: self::PAIRING_CANDIDATE_LIMIT,
        );

        foreach ($candidates as $candidate) {
            if ((int)($candidate['persisted_at'] ?? 0) > 0
                && $this->isCompatible($candidate, $scan)
                && ($accept === null || $accept($candidate))) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $scan
     */
    private function finishedAt(array $scan): int
    {
        $finishedAt = (int)($scan['finished_at'] ?? 0);

        return $finishedAt > 0 ? $finishedAt : (int)($scan['crdate'] ?? 0);
    }
}
