<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Registry;

/**
 * Retention of the frontend scan history stored in this TYPO3 installation (tx_a11y_remote_scan with its pages,
 * findings and occurrences): the list the Overview and the page detail show as "Frontend scan history", and the
 * results "View report" opens.
 *
 * The data belongs to this installation; the AQG service keeps its own copy of the scan history and, for a limited
 * time, the screenshots (shown by the service, never stored here). This setting decides nothing about either.
 *
 * Nothing is ever deleted on its own: the setting only names the period. Older scans are deleted when an
 * administrator reviews them in Settings › Scan history and confirms — exactly the reviewed selection (reviewToken:
 * the reviewed cutoff and scan uids), never a scan that became eligible after the review — or when an operator runs
 * `a11y:history:prune --apply` (a dry run without `--apply`; not a Scheduler task). Shortening the period therefore
 * never destroys history unexpectedly.
 *
 * Periods are calendar years, computed in UTC: the cutoff is the same date and time N years earlier (29 February
 * becomes 28 February, the earlier day), so no scan younger than the period is ever offered. A scan is eligible when
 * it finished strictly before the cutoff.
 *
 * Never deleted, whatever their age:
 * - a scan that is still running;
 * - the newest completed scan of each comparison group (site, scope, page, language, source type, start URL, Free vs
 *   paid) — the baseline the next scan is compared with and the latest result shown;
 * - the scans of a Verify fix that is still pending (its baseline and verification scan). A decided verification keeps
 *   its outcome in its own row and is shown without its scans, so it does not hold them;
 * - the scans monitoring still needs: per site and language the scan of the latest evaluated run and of the latest run
 *   with complete coverage (the next run's baseline) and, while a run is not evaluated yet, its scan and every completed
 *   scan of that site and language (its baseline is chosen at evaluation). Older evaluated runs keep their outcome in
 *   their own row.
 *
 * Local content scans (tx_a11y_scan / tx_a11y_issue) are not part of this: they hold the current findings of
 * records, not a history list, and their findings refer back to the scans they were first and last seen in.
 */
final class LocalHistoryRetentionService
{
    public const KEEP = 'keep';

    /** Option => calendar years. `keep` = no period (the default). */
    public const OPTIONS = [
        self::KEEP => 0,
        '1y' => 1,
        '3y' => 3,
        '5y' => 5,
    ];

    /** Why an older scan is kept, in the order a scan is classified. */
    public const PROTECTED_RUNNING = 'running';
    public const PROTECTED_BASELINE = 'baseline';
    public const PROTECTED_VERIFICATION = 'verification';
    public const PROTECTED_MONITORING = 'monitoring';

    private const REGISTRY_NAMESPACE = 'tx_a11y_quality_gate';
    private const REGISTRY_KEY = 'localHistoryRetention';
    private const FINISHED_STATUSES = ['completed', 'failed', 'cancelled', 'error'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly Registry $registry,
    ) {
    }

    public function getSetting(): string
    {
        $value = $this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, self::KEEP);

        return is_string($value) && array_key_exists($value, self::OPTIONS) ? $value : self::KEEP;
    }

    public function saveSetting(string $option): bool
    {
        if (!array_key_exists($option, self::OPTIONS)) {
            return false;
        }
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, $option);

        return true;
    }

    /**
     * The cutoff for an option (scans that finished before it are eligible), or null for `keep`: the same UTC date
     * and time N calendar years earlier, clamped to the last day of the month (29 Feb → 28 Feb).
     */
    public static function cutoffFor(string $option, int $now): ?int
    {
        $years = self::OPTIONS[$option] ?? 0;
        if ($years <= 0) {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        $date = (new \DateTimeImmutable('@' . $now))->setTimezone($utc);
        $year = (int)$date->format('Y') - $years;
        $month = (int)$date->format('n');
        $daysInMonth = (int)(new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $utc))->format('t');

        return $date
            ->setDate($year, $month, min((int)$date->format('j'), $daysInMonth))
            ->getTimestamp();
    }

    /** A timestamp as the review and the command show it: `2025-10-20 18:40:12 UTC`, '' for none. */
    public static function formatUtc(int $timestamp): string
    {
        return $timestamp > 0 ? gmdate('Y-m-d H:i:s', $timestamp) . ' UTC' : '';
    }

    /**
     * What a confirmation would delete now, for the review in Settings and the command.
     *
     * @return array{
     *     option:string, cutoff:?int, scans:int, pages:int, occurrences:int, oldest:int, newest:int, uids:list<int>,
     *     reviewToken:string, olderTotal:int, protected:array<string,int>,
     *     bySite:list<array{site:string,language:int,scans:int}>
     * }
     */
    public function preview(?string $option = null, ?int $now = null, ?int $cutoffOverride = null): array
    {
        $option ??= $this->getSetting();
        $cutoff = $cutoffOverride ?? self::cutoffFor($option, $now ?? time());
        $result = [
            'option' => $option, 'cutoff' => $cutoff, 'scans' => 0, 'pages' => 0, 'occurrences' => 0, 'oldest' => 0,
            'newest' => 0, 'uids' => [], 'reviewToken' => '', 'olderTotal' => 0, 'protected' => self::emptyProtected(),
            'bySite' => [],
        ];
        if ($cutoff === null) {
            return $result;
        }

        $scans = $this->loadScans();
        $classification = self::classify($scans, $this->protectedScanUids($scans), $this->monitoringJobIds($scans), $cutoff);
        $result['olderTotal'] = $classification['olderTotal'];
        $result['protected'] = $classification['protected'];
        $uids = $classification['selected'];
        if ($uids === []) {
            return $result;
        }

        $byUid = [];
        foreach ($scans as $scan) {
            $byUid[(int)$scan['uid']] = $scan;
        }
        $times = array_map(static fn (int $uid): int => self::finishedAt($byUid[$uid]), $uids);

        return array_merge($result, [
            'scans' => count($uids),
            'pages' => $this->countWhereIn(Tables::REMOTE_SCAN_PAGE, 'remote_scan', $uids),
            'occurrences' => $this->countOccurrences($uids),
            'oldest' => min($times),
            'newest' => max($times),
            'uids' => $uids,
            'reviewToken' => self::reviewToken($cutoff, $uids),
            'bySite' => self::breakdown(array_map(static fn (int $uid): array => $byUid[$uid], $uids)),
        ]);
    }

    /** Binds a confirmation to the exact selection that was reviewed. @param list<int> $uids */
    public static function reviewToken(int $cutoff, array $uids): string
    {
        return hash('sha256', $cutoff . '|' . implode(',', $uids));
    }

    /**
     * Deletes exactly the reviewed selection: the selection is computed again with the reviewed cutoff (never later
     * than the period allows now) and must still be the one the administrator saw (reviewToken), so a scan that crossed
     * the cutoff, or whose protection changed, after the review is never deleted. Each scan is checked once more inside
     * its own transaction, so a Verify fix or monitoring run that starts to refer to it in the meantime keeps it.
     * Returns the number of deleted scans, or null when there is nothing to confirm or the review is out of date.
     */
    public function prune(string $option, int $reviewedCutoff, string $reviewToken, ?int $now = null): ?int
    {
        $allowedCutoff = self::cutoffFor($option, $now ?? time());
        if ($allowedCutoff === null || $reviewedCutoff <= 0 || $reviewedCutoff > $allowedCutoff || $reviewToken === '') {
            return null;
        }
        $preview = $this->preview($option, $now, $reviewedCutoff);
        if ($preview['scans'] === 0 || !hash_equals($preview['reviewToken'], $reviewToken)) {
            return null;
        }

        return $this->deleteScans($preview['uids'], $reviewedCutoff);
    }

    /**
     * The selection, as a pure function of the stored rows (unit-tested).
     *
     * @param list<array<string, mixed>> $scans tx_a11y_remote_scan rows (uid, job_id, status, finished_at, crdate and the group columns)
     * @param list<int> $protectedUids scans a pending Verify fix refers to
     * @param list<string> $protectedJobIds job ids of the scans monitoring still needs
     * @return list<int>
     */
    public static function selectPrunable(array $scans, array $protectedUids, array $protectedJobIds, int $cutoff): array
    {
        return self::classify($scans, $protectedUids, $protectedJobIds, $cutoff)['selected'];
    }

    /**
     * Every scan older than the cutoff is either selected or kept for exactly one reason (the first that applies).
     *
     * @param list<array<string, mixed>> $scans
     * @param list<int> $protectedUids
     * @param list<string> $protectedJobIds
     * @return array{selected:list<int>, olderTotal:int, protected:array<string,int>}
     */
    public static function classify(array $scans, array $protectedUids, array $protectedJobIds, int $cutoff): array
    {
        // The newest completed scan of each group, and the newest one whose results were stored (persisted_at): only a
        // stored scan can be paired with the next one (RemoteScanPairingService), so a newer scan whose storing failed
        // must not leave the group without its comparison baseline.
        $newestCompleted = [];
        $newestPersisted = [];
        foreach ($scans as $scan) {
            if ((string)($scan['status'] ?? '') !== 'completed') {
                continue;
            }
            $group = self::groupKey($scan);
            $current = $newestCompleted[$group] ?? null;
            if ($current === null || self::isNewer($scan, $current)) {
                $newestCompleted[$group] = $scan;
            }
            if ((int)($scan['persisted_at'] ?? 1) > 0) {
                $current = $newestPersisted[$group] ?? null;
                if ($current === null || self::isNewer($scan, $current)) {
                    $newestPersisted[$group] = $scan;
                }
            }
        }
        $baselines = array_fill_keys(array_map(
            static fn (array $scan): int => (int)$scan['uid'],
            array_merge(array_values($newestCompleted), array_values($newestPersisted))
        ), true);
        $protectedUidSet = array_fill_keys(array_map('intval', $protectedUids), true);
        $protectedJobSet = array_fill_keys(array_map('strval', $protectedJobIds), true);

        $selected = [];
        $olderTotal = 0;
        $protected = self::emptyProtected();
        foreach ($scans as $scan) {
            $uid = (int)($scan['uid'] ?? 0);
            $finishedAt = self::finishedAt($scan);
            if ($uid <= 0 || $finishedAt <= 0 || $finishedAt >= $cutoff) {
                continue;
            }
            $olderTotal++;
            $jobId = (string)($scan['job_id'] ?? '');
            $reason = match (true) {
                !in_array((string)($scan['status'] ?? ''), self::FINISHED_STATUSES, true) => self::PROTECTED_RUNNING,
                isset($baselines[$uid]) => self::PROTECTED_BASELINE,
                isset($protectedUidSet[$uid]) => self::PROTECTED_VERIFICATION,
                $jobId !== '' && isset($protectedJobSet[$jobId]) => self::PROTECTED_MONITORING,
                default => null,
            };
            if ($reason !== null) {
                $protected[$reason]++;
                continue;
            }
            $selected[] = $uid;
        }
        sort($selected);

        return ['selected' => $selected, 'olderTotal' => $olderTotal, 'protected' => $protected];
    }

    /**
     * Selected scans per site and language, for the review (an Agency installation holds several sites).
     *
     * @param list<array<string, mixed>> $scans
     * @return list<array{site:string,language:int,scans:int}>
     */
    public static function breakdown(array $scans): array
    {
        $counts = [];
        foreach ($scans as $scan) {
            $key = (string)($scan['site_identifier'] ?? '') . "\x1f" . (int)($scan['language_uid'] ?? -1);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);
        $rows = [];
        foreach ($counts as $key => $count) {
            [$site, $language] = explode("\x1f", (string)$key);
            $rows[] = ['site' => $site, 'language' => (int)$language, 'scans' => $count];
        }

        return $rows;
    }

    /** @return array<string, int> */
    private static function emptyProtected(): array
    {
        return [
            self::PROTECTED_RUNNING => 0,
            self::PROTECTED_BASELINE => 0,
            self::PROTECTED_VERIFICATION => 0,
            self::PROTECTED_MONITORING => 0,
        ];
    }

    /** @param array<string, mixed> $scan */
    private static function finishedAt(array $scan): int
    {
        $finished = (int)($scan['finished_at'] ?? 0);

        return $finished > 0 ? $finished : (int)($scan['crdate'] ?? 0);
    }

    /** @param array<string, mixed> $scan */
    private static function groupKey(array $scan): string
    {
        return implode("\x1f", [
            (string)($scan['site_identifier'] ?? ''),
            (string)($scan['scan_scope'] ?? ''),
            (string)($scan['page_uid'] ?? ''),
            (string)($scan['language_uid'] ?? ''),
            (string)($scan['source_type'] ?? ''),
            (string)($scan['start_url'] ?? ''),
            (string)($scan['is_free_preview'] ?? ''),
        ]);
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private static function isNewer(array $a, array $b): bool
    {
        return self::finishedAt($a) === self::finishedAt($b)
            ? (int)$a['uid'] > (int)$b['uid']
            : self::finishedAt($a) > self::finishedAt($b);
    }

    /** @return list<array<string, mixed>> */
    private function loadScans(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(Tables::REMOTE_SCAN);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('uid', 'job_id', 'status', 'finished_at', 'crdate', 'persisted_at', 'site_identifier', 'scan_scope', 'page_uid', 'language_uid', 'source_type', 'start_url', 'is_free_preview')
            ->from(Tables::REMOTE_SCAN)
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Scans a pending Verify fix refers to: its baseline and verification scan by uid, and its verification scan by job
     * id while the verification is not decided yet.
     *
     * @param list<array<string, mixed>> $scans
     * @return list<int>
     */
    private function protectedScanUids(array $scans): array
    {
        $uids = [];
        $jobIds = [];
        foreach ($this->pendingVerifications() as $row) {
            foreach (['baseline_scan', 'verification_scan'] as $column) {
                if ((int)($row[$column] ?? 0) > 0) {
                    $uids[] = (int)$row[$column];
                }
            }
            $jobId = trim((string)($row['verification_job_id'] ?? ''));
            if ($jobId !== '') {
                $jobIds[$jobId] = true;
            }
        }
        foreach ($scans as $scan) {
            if (isset($jobIds[trim((string)($scan['job_id'] ?? ''))])) {
                $uids[] = (int)$scan['uid'];
            }
        }

        return array_values(array_unique($uids));
    }

    /**
     * Job ids of the scans monitoring still compares with — see the class comment. While a run of a site and language is
     * not evaluated yet, its baseline is not known (it is chosen at evaluation: the latest complete run before it, or
     * the newest compatible scan without gaps), so every completed scan of that site and language is kept until then.
     *
     * @param list<array<string, mixed>>|null $scans the stored scans, for the pending runs' possible baselines
     * @return list<string>
     */
    private function monitoringJobIds(?array $scans = null): array
    {
        $ids = [];
        $pendingSiteLanguages = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(Tables::MONITORING_RUN);
        $queryBuilder->getRestrictions()->removeAll();
        $latestEvaluated = [];
        $latestTrusted = [];
        $rows = $queryBuilder
            ->select('uid', 'site_identifier', 'language_uid', 'job_id', 'baseline_job_id', 'status', 'coverage_complete')
            ->from(Tables::MONITORING_RUN)
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            $key = (string)($row['site_identifier'] ?? '') . "\x1f" . (int)($row['language_uid'] ?? -1);
            if ((string)($row['status'] ?? '') !== 'evaluated') {
                // Not evaluated yet: its own scan, and any scan its baseline may turn out to be.
                $ids[] = trim((string)($row['job_id'] ?? ''));
                $ids[] = trim((string)($row['baseline_job_id'] ?? ''));
                $pendingSiteLanguages[$key] = true;
                continue;
            }
            $latestEvaluated[$key] = trim((string)($row['job_id'] ?? ''));
            if ((int)($row['coverage_complete'] ?? 0) === 1) {
                $latestTrusted[$key] = trim((string)($row['job_id'] ?? ''));
            }
        }
        $ids = array_merge($ids, array_values($latestEvaluated), array_values($latestTrusted));
        if ($pendingSiteLanguages !== []) {
            foreach ($scans ?? $this->loadScans() as $scan) {
                $key = (string)($scan['site_identifier'] ?? '') . "\x1f" . (int)($scan['language_uid'] ?? -1);
                if (isset($pendingSiteLanguages[$key]) && (string)($scan['status'] ?? '') === 'completed') {
                    $ids[] = trim((string)($scan['job_id'] ?? ''));
                }
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
    }

    /** @return list<array<string, mixed>> */
    private function pendingVerifications(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(Tables::FIX_VERIFICATION);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('baseline_scan', 'verification_scan', 'verification_job_id')
            ->from(Tables::FIX_VERIFICATION)
            ->where($queryBuilder->expr()->eq('outcome', $queryBuilder->createNamedParameter(FixVerificationService::OUTCOME_PENDING)))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /** @param list<int> $uids */
    private function countWhereIn(string $table, string $column, array $uids): int
    {
        $total = 0;
        foreach (array_chunk($uids, 500) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            $total += (int)$queryBuilder->count('uid')->from($table)
                ->where($queryBuilder->expr()->in($column, $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()->fetchOne();
        }

        return $total;
    }

    /** @param list<int> $uids */
    private function countOccurrences(array $uids): int
    {
        $total = 0;
        foreach (array_chunk($uids, 500) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(Tables::REMOTE_ISSUE);
            $queryBuilder->getRestrictions()->removeAll();
            $total += (int)$queryBuilder->selectLiteral('COALESCE(SUM(nodes_count), 0)')->from(Tables::REMOTE_ISSUE)
                ->where($queryBuilder->expr()->in('remote_scan', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()->fetchOne();
        }

        return $total;
    }

    /**
     * Each scan in its own transaction: occurrences, findings, pages, then the scan. An interrupted run leaves whole
     * scans, and the next confirmation (or command run) continues with what is left. Inside the transaction the scan is
     * checked once more — still stored, finished before the cutoff, not referenced by a pending Verify fix or by
     * monitoring — so a reference created after the selection keeps it.
     *
     * @param list<int> $uids
     */
    private function deleteScans(array $uids, int $cutoff): int
    {
        $deleted = 0;
        $protectedJobIds = $this->monitoringJobIds();
        $connection = $this->connectionPool->getConnectionForTable(Tables::REMOTE_SCAN);
        foreach ($uids as $uid) {
            $connection->transactional(function (Connection $connection) use ($uid, $cutoff, $protectedJobIds, &$deleted): void {
                $scan = $connection->select(['uid', 'job_id', 'status', 'finished_at', 'crdate'], Tables::REMOTE_SCAN, ['uid' => $uid])->fetchAssociative();
                $jobId = is_array($scan) ? trim((string)($scan['job_id'] ?? '')) : '';
                if (!is_array($scan)
                    || !in_array((string)($scan['status'] ?? ''), self::FINISHED_STATUSES, true)
                    || self::finishedAt($scan) <= 0
                    || self::finishedAt($scan) >= $cutoff
                    || ($jobId !== '' && in_array($jobId, $protectedJobIds, true))
                    || $this->isReferencedByPendingVerification($connection, $uid, $jobId)
                ) {
                    return;
                }
                $issueUids = array_map('intval', $connection->select(['uid'], Tables::REMOTE_ISSUE, ['remote_scan' => $uid])->fetchFirstColumn());
                foreach (array_chunk($issueUids, 500) as $chunk) {
                    $queryBuilder = $connection->createQueryBuilder();
                    $queryBuilder->delete(Tables::REMOTE_ISSUE_NODE)
                        ->where($queryBuilder->expr()->in('remote_issue', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                        ->executeStatement();
                }
                $connection->delete(Tables::REMOTE_ISSUE, ['remote_scan' => $uid]);
                $connection->delete(Tables::REMOTE_SCAN_PAGE, ['remote_scan' => $uid]);
                $deleted += $connection->delete(Tables::REMOTE_SCAN, ['uid' => $uid]) > 0 ? 1 : 0;
            });
        }

        return $deleted;
    }

    private function isReferencedByPendingVerification(Connection $connection, int $uid, string $jobId): bool
    {
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $references = [
            $queryBuilder->expr()->eq('baseline_scan', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('verification_scan', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
        ];
        if ($jobId !== '') {
            $references[] = $queryBuilder->expr()->eq('verification_job_id', $queryBuilder->createNamedParameter($jobId));
        }

        return (int)$queryBuilder->count('uid')->from(Tables::FIX_VERIFICATION)
            ->where(
                $queryBuilder->expr()->eq('outcome', $queryBuilder->createNamedParameter(FixVerificationService::OUTCOME_PENDING)),
                $queryBuilder->expr()->or(...$references)
            )
            ->executeQuery()->fetchOne() > 0;
    }
}
