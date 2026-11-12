<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Service\RemoteScreenshotState;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class RemoteScanRepository extends AbstractRepository
{
    /** @var array<string, bool>|null */
    private ?array $remoteScanPageColumns = null;

    public function upsertScan(
        string $siteIdentifier,
        string $jobId,
        RemoteScanSourceType $sourceType,
        string $startUrl,
        ?string $sitemapUrl,
        string $status,
        int $pagesScanned,
        int $pagesFailed,
        int $issuesTotal,
        int $issuesNew,
        int $issuesResolved,
        int $startedAt,
        int $finishedAt,
        int $pagesTotal = 0,
        string $scanScope = 'site',
        int $pageUid = 0,
        int $lastSyncedAt = 0,
        int $persistedAt = 0,
        string $syncError = '',
        int $languageUid = -1,
        int $pid = 0,
        ?bool $isFreePreview = null,
        ?bool $isPagePreview = null,
    ): int {
        $connection = $this->getConnection(Tables::REMOTE_SCAN);
        $now = time();

        $existing = $this->findScanByJobId($jobId);

        $data = [
            'pid' => $pid,
            'site_identifier' => $siteIdentifier,
            'job_id' => $jobId,
            'source_type' => $sourceType->value,
            'scan_scope' => $scanScope,
            'page_uid' => $pageUid,
            'language_uid' => $languageUid,
            'start_url' => $startUrl,
            'sitemap_url' => $sitemapUrl,
            'status' => $status,
            'pages_scanned' => $pagesScanned,
            'pages_total' => $pagesTotal,
            'pages_failed' => $pagesFailed,
            'issues_total' => $issuesTotal,
            'issues_new' => $issuesNew,
            'issues_resolved' => $issuesResolved,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'last_synced_at' => $lastSyncedAt,
            'persisted_at' => $persistedAt,
            'sync_error' => $syncError,
            'tstamp' => $now,
        ];

        if ($isFreePreview !== null) {
            $data['is_free_preview'] = $isFreePreview ? 1 : 0;
        }

        // Like is_free_preview: decided once at submit time, kept by every later sync (null).
        if ($isPagePreview !== null) {
            $data['is_page_preview'] = $isPagePreview ? 1 : 0;
        }

        if (is_array($existing)) {
            $connection->update(
                Tables::REMOTE_SCAN,
                $data,
                [
                    'uid' => (int)$existing['uid'],
                ]
            );

            return (int)$existing['uid'];
        }

        $data['crdate'] = $now;
        $connection->insert(Tables::REMOTE_SCAN, $data);

        return (int)$connection->lastInsertId();
    }

    /**
     * The page limit the scan was submitted with (coverage: "stopped at its page limit"). Kept apart from upsertScan(),
     * which status syncs and the persistence rewrite; 0 = unknown (older scans, other submit paths).
     */
    public function recordMaxPages(string $jobId, int $maxPages): void
    {
        if ($jobId === '' || $maxPages <= 0) {
            return;
        }
        $this->getConnection(Tables::REMOTE_SCAN)->update(Tables::REMOTE_SCAN, ['max_pages' => $maxPages], ['job_id' => $jobId]);
    }

    public function markSubmitted(
        string $siteIdentifier,
        string $jobId,
        RemoteScanSourceType $sourceType,
        string $startUrl,
        ?string $sitemapUrl,
        string $status,
        string $scanScope = 'site',
        int $pageUid = 0,
        int $languageUid = -1,
        int $pid = 0,
        bool $isFreePreview = false,
        bool $isPagePreview = false,
    ): int {
        $now = time();

        return $this->upsertScan(
            siteIdentifier: $siteIdentifier,
            jobId: $jobId,
            sourceType: $sourceType,
            startUrl: $startUrl,
            sitemapUrl: $sitemapUrl,
            status: $status,
            pagesScanned: 0,
            pagesFailed: 0,
            issuesTotal: 0,
            issuesNew: 0,
            issuesResolved: 0,
            startedAt: $now,
            finishedAt: 0,
            pagesTotal: 0,
            scanScope: $scanScope,
            pageUid: $pageUid,
            lastSyncedAt: $now,
            persistedAt: 0,
            syncError: '',
            languageUid: $languageUid,
            pid: $pid,
            isFreePreview: $isFreePreview,
            isPagePreview: $isPagePreview,
        );
    }

    public function syncStatus(
        string $jobId,
        string $status,
        ?int $pagesScanned,
        ?int $pagesTotal,
        ?int $startedAt,
        ?int $finishedAt,
        string $syncError = '',
    ): void {
        $existing = $this->findScanByJobId($jobId);
        if (!is_array($existing)) {
            return;
        }

        $this->getConnection(Tables::REMOTE_SCAN)->update(
            Tables::REMOTE_SCAN,
            [
                'status' => $status,
                'pages_scanned' => $pagesScanned ?? (int)($existing['pages_scanned'] ?? 0),
                'pages_total' => $pagesTotal ?? (int)($existing['pages_total'] ?? 0),
                'started_at' => ($startedAt ?? 0) > 0 ? (int)$startedAt : (int)($existing['started_at'] ?? 0),
                'finished_at' => ($finishedAt ?? 0) > 0 ? (int)$finishedAt : (int)($existing['finished_at'] ?? 0),
                'last_synced_at' => time(),
                'sync_error' => $syncError,
                'tstamp' => time(),
            ],
            [
                'uid' => (int)$existing['uid'],
            ]
        );
    }

    public function markSyncError(string $jobId, string $message): void
    {
        $existing = $this->findScanByJobId($jobId);
        if (!is_array($existing)) {
            return;
        }

        $this->getConnection(Tables::REMOTE_SCAN)->update(
            Tables::REMOTE_SCAN,
            [
                'last_synced_at' => time(),
                'sync_error' => $message,
                'tstamp' => time(),
            ],
            [
                'uid' => (int)$existing['uid'],
            ]
        );
    }

    public function markCancelled(string $jobId, string $message = ''): void
    {
        $existing = $this->findScanByJobId($jobId);
        if (!is_array($existing)) {
            return;
        }

        $now = time();

        $this->getConnection(Tables::REMOTE_SCAN)->update(
            Tables::REMOTE_SCAN,
            [
                'status' => 'cancelled',
                'finished_at' => (int)($existing['finished_at'] ?? 0) > 0 ? (int)$existing['finished_at'] : $now,
                'last_synced_at' => $now,
                'sync_error' => $message,
                'tstamp' => $now,
            ],
            [
                'uid' => (int)$existing['uid'],
            ]
        );
    }

    /**
     * The last step of persisting a scan: set only once all of its pages and findings are stored, so a stored
     * scan is never read as complete evidence while its rows are still being written or after an interruption.
     */
    public function markPersisted(int $remoteScanUid, int $persistedAt): void
    {
        $this->getConnection(Tables::REMOTE_SCAN)->update(
            Tables::REMOTE_SCAN,
            ['persisted_at' => $persistedAt, 'tstamp' => time()],
            ['uid' => $remoteScanUid]
        );
    }

    /**
     * NEW-01: claims the right to store a completed scan's results. Exactly one caller wins per scan while it is not
     * persisted yet: an atomic UPDATE whose WHERE re-checks persisted_at and an expired claim, so concurrent status
     * polls, Overview, toolbar and Monitoring reads in other PHP processes cannot store the same results twice. A claim
     * of a process that died expires after $ttlSeconds and the next reader persists the scan.
     */
    public function claimPersistence(int $remoteScanUid, string $token, int $now, int $ttlSeconds): bool
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN);

        return $queryBuilder
            ->update(Tables::REMOTE_SCAN)
            ->set('persist_claim_token', $token)
            ->set('persist_claim_until', $now + max(1, $ttlSeconds), true, Connection::PARAM_INT)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($remoteScanUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('persisted_at', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->lt('persist_claim_until', $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)),
            )
            ->executeStatement() === 1;
    }

    /** Ends this caller's claim (only its own: a claim taken over after expiry belongs to the next caller). */
    public function releasePersistenceClaim(int $remoteScanUid, string $token): void
    {
        $this->getConnection(Tables::REMOTE_SCAN)->update(
            Tables::REMOTE_SCAN,
            ['persist_claim_token' => '', 'persist_claim_until' => 0],
            ['uid' => $remoteScanUid, 'persist_claim_token' => $token]
        );
    }

    /** One connection for the scan, its pages, findings and nodes: the store runs in one transaction. */
    public function remoteScanConnection(): Connection
    {
        return $this->getConnection(Tables::REMOTE_SCAN);
    }

    public function isPersisted(string $jobId): bool
    {
        $existing = $this->findScanByJobId($jobId);

        return is_array($existing) && (int)($existing['persisted_at'] ?? 0) > 0;
    }

    public function findScanByJobId(string $jobId): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN);

        $row = $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_SCAN)
            ->where(
                $queryBuilder->expr()->eq(
                    'job_id',
                    $queryBuilder->createNamedParameter($jobId)
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findLatestActiveScanBySite(string $siteIdentifier, int $languageUid = -1): ?array
    {
        return $this->findRecentActiveScansBySite($siteIdentifier, $languageUid, 1)[0] ?? null;
    }

    /**
     * The newest active (or completed but not yet stored) scans of a site, newest first, at most $limit rows. Callers
     * that check each row's read access pick the first readable one (F11-04: a newer unreadable scan must not hide an
     * older readable result).
     *
     * @return list<array<string, mixed>>
     */
    public function findRecentActiveScansBySite(string $siteIdentifier, int $languageUid = -1, int $limit = 1): array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN);

        $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_SCAN)
            ->where(
                $queryBuilder->expr()->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->in(
                        'status',
                        $queryBuilder->createNamedParameter(
                            ['waiting', 'queued', 'active', 'running'],
                            Connection::PARAM_STR_ARRAY
                        )
                    ),
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->eq(
                            'status',
                            $queryBuilder->createNamedParameter('completed')
                        ),
                        $queryBuilder->expr()->eq(
                            'persisted_at',
                            $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                        )
                    )
                )
            );

        $this->addLanguageConstraint($queryBuilder, $languageUid);

        return array_values($queryBuilder
            ->orderBy('started_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative());
    }

    public function findLatestActiveSiteScanBySite(string $siteIdentifier, int $languageUid = -1): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN);

        $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_SCAN)
            ->where(
                $queryBuilder->expr()->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'scan_scope',
                    $queryBuilder->createNamedParameter('site')
                )
            )
            ->andWhere(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->in(
                        'status',
                        $queryBuilder->createNamedParameter(
                            ['waiting', 'queued', 'active', 'running'],
                            Connection::PARAM_STR_ARRAY
                        )
                    ),
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->eq(
                            'status',
                            $queryBuilder->createNamedParameter('completed')
                        ),
                        $queryBuilder->expr()->eq(
                            'persisted_at',
                            $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                        )
                    )
                )
            );

        $this->addLanguageConstraint($queryBuilder, $languageUid);

        $row = $queryBuilder
            ->orderBy('started_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findLastCompletedScanBySite(string $siteIdentifier, int $languageUid = -1): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN);

        $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_SCAN)
            ->where(
                $queryBuilder->expr()->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'status',
                    $queryBuilder->createNamedParameter('completed')
                )
            );

        $this->addLanguageConstraint($queryBuilder, $languageUid);
        $this->addPublicScanConstraint($queryBuilder);

        $row = $queryBuilder
            ->orderBy('finished_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findLastCompletedSiteScanBySite(string $siteIdentifier, int $languageUid = -1, ?bool $isFreePreview = null): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN);

        $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_SCAN)
            ->where(
                $queryBuilder->expr()->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'scan_scope',
                    $queryBuilder->createNamedParameter('site')
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'status',
                    $queryBuilder->createNamedParameter('completed')
                )
            );

        $this->addLanguageConstraint($queryBuilder, $languageUid);
        $this->addFreePreviewConstraint($queryBuilder, $isFreePreview);
        $this->addPublicScanConstraint($queryBuilder);

        $row = $queryBuilder
            ->orderBy('finished_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findLastCompletedPageScanBySite(string $siteIdentifier, int $languageUid = -1, ?bool $isFreePreview = null): ?array
    {
        return $this->findRecentCompletedPageScansBySite($siteIdentifier, $languageUid, $isFreePreview, 1)[0] ?? null;
    }

    /**
     * The newest completed page scans of a site, newest first, at most $limit rows (same scope, language, Free/paid and
     * public-scan constraints as findLastCompletedPageScanBySite()). See findRecentActiveScansBySite().
     *
     * @return list<array<string, mixed>>
     */
    public function findRecentCompletedPageScansBySite(string $siteIdentifier, int $languageUid = -1, ?bool $isFreePreview = null, int $limit = 1): array
    {
        if ($siteIdentifier === '') {
            return [];
        }

        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN);

        $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_SCAN)
            ->where(
                $queryBuilder->expr()->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'scan_scope',
                    $queryBuilder->createNamedParameter('page')
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'status',
                    $queryBuilder->createNamedParameter('completed')
                )
            );

        $this->addLanguageConstraint($queryBuilder, $languageUid);
        $this->addFreePreviewConstraint($queryBuilder, $isFreePreview);
        $this->addPublicScanConstraint($queryBuilder);

        return array_values($queryBuilder
            ->orderBy('finished_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative());
    }


    public function findLastCompletedPageScanByPage(
        string $siteIdentifier,
        int $pageUid,
        int $languageUid = -1,
        ?bool $isFreePreview = null,
    ): ?array {
        if ($siteIdentifier === '' || $pageUid <= 0) {
            return null;
        }

        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN);

        $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_SCAN)
            ->where(
                $queryBuilder->expr()->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'scan_scope',
                    $queryBuilder->createNamedParameter('page')
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'page_uid',
                    $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'status',
                    $queryBuilder->createNamedParameter('completed')
                )
            );

        $this->addLanguageConstraint($queryBuilder, $languageUid);
        $this->addFreePreviewConstraint($queryBuilder, $isFreePreview);

        $row = $queryBuilder
            ->orderBy('finished_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findLastCompletedPageScanByPageOrUrl(
        string $siteIdentifier,
        int $pageUid,
        int $languageUid = -1,
        string $url = '',
        ?bool $isFreePreview = null,
    ): ?array {
        if ($siteIdentifier === '' || ($pageUid <= 0 && trim($url) === '')) {
            return null;
        }

        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN);
        $matchExpressions = [];

        if ($pageUid > 0) {
            $matchExpressions[] = $queryBuilder->expr()->eq(
                'rs.page_uid',
                $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)
            );
        }

        $urlVariants = $this->buildUrlVariants($url);
        if ($urlVariants !== []) {
            $matchExpressions[] = $queryBuilder->expr()->in(
                'rs.start_url',
                $queryBuilder->createNamedParameter($urlVariants, Connection::PARAM_STR_ARRAY)
            );
            $matchExpressions[] = $queryBuilder->expr()->in(
                'rsp.url',
                $queryBuilder->createNamedParameter($urlVariants, Connection::PARAM_STR_ARRAY)
            );
        }

        if ($matchExpressions === []) {
            return null;
        }

        $queryBuilder
            ->select('rs.*')
            ->distinct()
            ->from(Tables::REMOTE_SCAN, 'rs')
            ->leftJoin(
                'rs',
                Tables::REMOTE_SCAN_PAGE,
                'rsp',
                $queryBuilder->expr()->eq('rsp.remote_scan', 'rs.uid')
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'rs.site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'rs.scan_scope',
                    $queryBuilder->createNamedParameter('page')
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'rs.status',
                    $queryBuilder->createNamedParameter('completed')
                )
            )
            ->andWhere($queryBuilder->expr()->or(...$matchExpressions));

        $this->addLanguageConstraint($queryBuilder, $languageUid, 'rs');
        $this->addFreePreviewConstraint($queryBuilder, $isFreePreview, 'rs');

        $row = $queryBuilder
            ->orderBy('rs.finished_at', 'DESC')
            ->addOrderBy('rs.uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findLastCompletedRelevantScan(
        string $siteIdentifier,
        int $pageUid,
        int $languageUid = -1,
        ?bool $isFreePreview = null,
    ): ?array {
        if ($pageUid > 0) {
            $pageScan = $this->findLastCompletedPageScanByPage($siteIdentifier, $pageUid, $languageUid, $isFreePreview);
            if (is_array($pageScan)) {
                return $pageScan;
            }
        }

        return $this->findLastCompletedSiteScanBySite($siteIdentifier, $languageUid, $isFreePreview);
    }

    /**
     * NEW-12: the newest scan of the site that TYPO3 itself gave up on (status checks failed for too long) within
     * $maxAgeSeconds — the crawler may have completed it since, so it is checked once more.
     */
    public function findLocallyFailedScanBySite(string $siteIdentifier, int $maxAgeSeconds): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN);
        $row = $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_SCAN)
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter('failed')),
                $queryBuilder->expr()->eq('failure_origin', $queryBuilder->createNamedParameter(self::FAILURE_ORIGIN_LOCAL_STALE)),
                $queryBuilder->expr()->gt('finished_at', $queryBuilder->createNamedParameter(time() - $maxAgeSeconds, Connection::PARAM_INT)),
            )
            ->orderBy('finished_at', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findUnpersistedCompletedScanBySite(string $siteIdentifier): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN);

        $row = $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_SCAN)
            ->where(
                $queryBuilder->expr()->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'status',
                    $queryBuilder->createNamedParameter('completed')
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'persisted_at',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->orderBy('finished_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findUnpersistedCompletedSiteScanBySite(string $siteIdentifier): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN);

        $row = $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_SCAN)
            ->where(
                $queryBuilder->expr()->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'scan_scope',
                    $queryBuilder->createNamedParameter('site')
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'status',
                    $queryBuilder->createNamedParameter('completed')
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'persisted_at',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->orderBy('finished_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findLatestRelevantActiveScan(string $siteIdentifier, int $pageUid, int $languageUid = -1): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN);

        $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_SCAN)
            ->where(
                $queryBuilder->expr()->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'scan_scope',
                    $queryBuilder->createNamedParameter('page')
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'page_uid',
                    $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->in(
                        'status',
                        $queryBuilder->createNamedParameter(
                            ['waiting', 'queued', 'active', 'running'],
                            Connection::PARAM_STR_ARRAY
                        )
                    ),
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->eq(
                            'status',
                            $queryBuilder->createNamedParameter('completed')
                        ),
                        $queryBuilder->expr()->eq(
                            'persisted_at',
                            $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                        )
                    )
                )
            );

        $this->addLanguageConstraint($queryBuilder, $languageUid);

        $row = $queryBuilder
            ->orderBy('started_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (is_array($row)) {
            return $row;
        }

        return $this->findLatestActiveSiteScanBySite($siteIdentifier, $languageUid);
    }

    /**
     * Scopes a "last completed scan" lookup to the caller's entitlement context so a
     * free-tier viewer's free preview scan can never surface as a paid user's PRO scan
     * result, and vice versa. Pass null to leave the query entitlement-agnostic
     * (existing callers that predate this distinction).
     */
    private function addFreePreviewConstraint(QueryBuilder $queryBuilder, ?bool $isFreePreview, string $alias = ''): void
    {
        if ($isFreePreview === null) {
            return;
        }

        $field = $alias !== '' ? $alias . '.is_free_preview' : 'is_free_preview';

        $queryBuilder->andWhere(
            $queryBuilder->expr()->eq(
                $field,
                $queryBuilder->createNamedParameter($isFreePreview ? 1 : 0, Connection::PARAM_INT)
            )
        );
    }

    /**
     * Site-level views (latest scan of the site, affected pages, stored site history) show public results only. A preview
     * scan (Atlas D9) belongs to its page: the page detail, its own history tab and its own pairing.
     */
    private function addPublicScanConstraint(QueryBuilder $queryBuilder, string $alias = ''): void
    {
        $queryBuilder->andWhere(
            $queryBuilder->expr()->eq(
                $alias !== '' ? $alias . '.is_page_preview' : 'is_page_preview',
                $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
            )
        );
    }

    /**
     * Which of the given job IDs are stored preview scans of this site (job ID => true). Job IDs without a stored row of
     * the site are left out: the page history treats them as public, as it did before.
     *
     * @param list<string> $jobIds
     * @return array<string, true>
     */
    public function findPagePreviewJobIds(string $siteIdentifier, array $jobIds): array
    {
        $jobIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $jobId): string => trim((string)$jobId),
            $jobIds
        ), static fn (string $jobId): bool => $jobId !== '')));
        if ($siteIdentifier === '' || $jobIds === []) {
            return [];
        }

        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN);
        $rows = $queryBuilder
            ->select('job_id')
            ->from(Tables::REMOTE_SCAN)
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->in('job_id', $queryBuilder->createNamedParameter($jobIds, Connection::PARAM_STR_ARRAY)),
                $queryBuilder->expr()->eq('is_page_preview', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchFirstColumn();

        $previewJobIds = [];
        foreach ($rows as $jobId) {
            $previewJobIds[(string)$jobId] = true;
        }

        return $previewJobIds;
    }

    private function addLanguageConstraint(QueryBuilder $queryBuilder, int $languageUid, string $alias = ''): void
    {
        if ($languageUid < 0) {
            return;
        }

        $field = $alias !== '' ? $alias . '.language_uid' : 'language_uid';

        $queryBuilder->andWhere(
            $queryBuilder->expr()->or(
                $queryBuilder->expr()->eq(
                    $field,
                    $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    $field,
                    $queryBuilder->createNamedParameter(-1, Connection::PARAM_INT)
                )
            )
        );
    }

    public function deletePagesByRemoteScan(int $remoteScanUid): void
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN_PAGE);

        $queryBuilder
            ->delete(Tables::REMOTE_SCAN_PAGE)
            ->where(
                $queryBuilder->expr()->eq(
                    'remote_scan',
                    $queryBuilder->createNamedParameter($remoteScanUid, Connection::PARAM_INT)
                )
            )
            ->executeStatement();
    }

    public function saveScanPages(
        int $remoteScanUid,
        RemoteScanSourceType $sourceType,
        array $pages,
        int $pid = 0,
    ): array {
        $connection = $this->getConnection(Tables::REMOTE_SCAN_PAGE);
        $now = time();
        $pageUidByUrl = [];

        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }

            $url = (string)($page['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $connection->insert(Tables::REMOTE_SCAN_PAGE, [
                'pid' => $pid,
                'remote_scan' => $remoteScanUid,
                'source_type' => $sourceType->value,
                'url' => $url,
                'title' => (string)($page['title'] ?? ''),
                'http_status' => (int)($page['httpStatus'] ?? 0),
                'issues_count' => (int)($page['issuesCount'] ?? 0),
                'screenshot_path' => (string)($page['screenshotPath'] ?? ''),
                'screenshot_url' => (string)($page['screenshotUrl'] ?? ''),
                'failure_reason' => (string)($page['failureReason'] ?? ''),
                'is_failed' => (int)($page['isFailed'] ?? 0),
                'external_page_id' => (string)($page['pageId'] ?? ''),
                'keyboard_summary_json' => $this->encodeJsonForColumn($this->buildKeyboardSummaryPayload($page)),
                'remediation_summary_json' => $this->encodeJsonForColumn($page['remediationSummary'] ?? $page['remediation_summary'] ?? $page['remediation'] ?? []),
                'page_recommendation_json' => $this->encodeJsonForColumn($page['pageRecommendation'] ?? $page['page_recommendation'] ?? $page['recommendation'] ?? []),
                'crdate' => $now,
                'tstamp' => $now,
            ] + ($this->pageColumnExists('screenshot_evidence_json') ? [
                // Audit C15: the crawler's screenshot evidence summary (additive; older crawlers send none).
                'screenshot_evidence_json' => $this->encodeJsonForColumn(self::screenshotEvidenceSummary($page['screenshotEvidence'] ?? null)),
            ] : []) + ($this->pageColumnExists('screenshot_state') ? [
                // Screenshot retention (additive; older crawlers send none): see RemoteScreenshotState.
                'screenshot_state' => RemoteScreenshotState::normalizeReported($page['screenshotState'] ?? null),
                'screenshot_expires_at' => RemoteScreenshotState::timestampOf($page['screenshotExpiresAt'] ?? null),
            ] : []));

            $pageUidByUrl[$url] = (int)$connection->lastInsertId();
        }

        return $pageUidByUrl;
    }


    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function buildKeyboardSummaryPayload(array $page): array
    {
        $keyboardSummary = is_array($page['keyboardSummary'] ?? null)
            ? $page['keyboardSummary']
            : (is_array($page['keyboard_summary'] ?? null) ? $page['keyboard_summary'] : []);

        if (is_array($page['keyboardDetails'] ?? null)) {
            $keyboardSummary['keyboardDetails'] = $page['keyboardDetails'];
        } elseif (is_array($page['keyboard_details'] ?? null)) {
            $keyboardSummary['keyboardDetails'] = $page['keyboard_details'];
        }

        if (is_array($page['structureDetails'] ?? null)) {
            $keyboardSummary['structureDetails'] = $page['structureDetails'];
        } elseif (is_array($page['structure_details'] ?? null)) {
            $keyboardSummary['structureDetails'] = $page['structure_details'];
        }

        return $keyboardSummary;
    }

    private function encodeJsonForColumn(mixed $value): string
    {
        if (!is_array($value) || $value === []) {
            return '';
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR) ?: '';
        } catch (\JsonException) {
            return '';
        }
    }

    public function countPagesForScan(int $remoteScanUid, bool $failed, string $search = '', string $ruleId = ''): int
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN_PAGE);

        $queryBuilder
            ->selectLiteral('COUNT(*) AS cnt')
            ->from(Tables::REMOTE_SCAN_PAGE)
            ->where(
                $queryBuilder->expr()->eq(
                    'remote_scan',
                    $queryBuilder->createNamedParameter($remoteScanUid, Connection::PARAM_INT)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'is_failed',
                    $queryBuilder->createNamedParameter($failed ? 1 : 0, Connection::PARAM_INT)
                )
            );

        $this->addRemotePageSearchConstraint($queryBuilder, $search, $failed);
        $this->addRemoteRuleConstraint($queryBuilder, $remoteScanUid, $ruleId);

        $row = $queryBuilder
            ->executeQuery()
            ->fetchAssociative();

        return (int)($row['cnt'] ?? 0);
    }

    public function findPagesForScan(int $remoteScanUid, string $search = '', string $ruleId = ''): array
    {
        $total = $this->countPagesForScan($remoteScanUid, false, $search, $ruleId);
        if ($total <= 0) {
            return [];
        }

        return $this->findPagesForScanPaginated($remoteScanUid, $total, 0, $search, $ruleId);
    }

    /**
     * @param string $ruleId limits the pages to those with an open finding of this rule in the scan
     */
    public function findPagesForScanPaginated(int $remoteScanUid, int $limit, int $offset, string $search = '', string $ruleId = ''): array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN_PAGE);

        $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_SCAN_PAGE)
            ->where(
                $queryBuilder->expr()->eq(
                    'remote_scan',
                    $queryBuilder->createNamedParameter($remoteScanUid, Connection::PARAM_INT)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'is_failed',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            );

        $this->addRemotePageSearchConstraint($queryBuilder, $search, false);
        $this->addRemoteRuleConstraint($queryBuilder, $remoteScanUid, $ruleId);

        return $queryBuilder
            ->orderBy('issues_count', 'DESC')
            ->addOrderBy('uid', 'ASC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Every stored page of a scan, scanned or failed.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllPagesForScan(int $remoteScanUid): array
    {
        if ($remoteScanUid <= 0) {
            return [];
        }

        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN_PAGE);

        return array_values($queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_SCAN_PAGE)
            ->where(
                $queryBuilder->expr()->eq(
                    'remote_scan',
                    $queryBuilder->createNamedParameter($remoteScanUid, Connection::PARAM_INT)
                )
            )
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative());
    }

    public function findFailedPagesForScan(int $remoteScanUid, string $search = ''): array
    {
        $total = $this->countPagesForScan($remoteScanUid, true, $search);
        if ($total <= 0) {
            return [];
        }

        return $this->findFailedPagesForScanPaginated($remoteScanUid, $total, 0, $search);
    }

    public function findFailedPagesForScanPaginated(int $remoteScanUid, int $limit, int $offset, string $search = ''): array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN_PAGE);

        $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_SCAN_PAGE)
            ->where(
                $queryBuilder->expr()->eq(
                    'remote_scan',
                    $queryBuilder->createNamedParameter($remoteScanUid, Connection::PARAM_INT)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'is_failed',
                    $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)
                )
            );

        $this->addRemotePageSearchConstraint($queryBuilder, $search, true);

        return $queryBuilder
            ->orderBy('http_status', 'DESC')
            ->addOrderBy('uid', 'ASC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();
    }


    public function countCompletedPageScanPagesForSite(
        string $siteIdentifier,
        int $languageUid = -1,
        bool $failed = false,
        string $search = ''
    ): int {
        return count($this->findLatestCompletedPageScanPagesForSite($siteIdentifier, $languageUid, $failed, $search));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findCompletedPageScanPagesForSitePaginated(
        string $siteIdentifier,
        int $limit,
        int $offset,
        int $languageUid = -1,
        bool $failed = false,
        string $search = ''
    ): array {
        $rows = $this->findLatestCompletedPageScanPagesForSite($siteIdentifier, $languageUid, $failed, $search);

        return array_slice($rows, max(0, $offset), max(1, $limit));
    }

    /**
     * Returns the latest completed single-page remote result per page identity.
     *
     * The overview must behave like the local overview: one page equals one row.
     * Older scan runs of the same TYPO3 page / normalized URL are deliberately
     * hidden here. They remain accessible through their direct detail URLs when
     * needed, but they must not create duplicate overview rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function findLatestCompletedPageScanPagesForSite(
        string $siteIdentifier,
        int $languageUid,
        bool $failed,
        string $search
    ): array {
        if ($siteIdentifier === '') {
            return [];
        }

        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN_PAGE);

        $queryBuilder
            ->select('rsp.*')
            ->addSelectLiteral(
                'rs.uid AS remote_scan_uid',
                'rs.finished_at AS remote_scan_finished_at',
                'rs.started_at AS remote_scan_started_at',
                'rs.page_uid AS typo3_page_uid',
                'rs.language_uid AS remote_scan_language_uid',
                'rs.scan_scope AS remote_scan_scope'
            )
            ->from(Tables::REMOTE_SCAN_PAGE, 'rsp')
            ->innerJoin(
                'rsp',
                Tables::REMOTE_SCAN,
                'rs',
                $queryBuilder->expr()->eq('rs.uid', 'rsp.remote_scan')
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'rs.site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'rs.scan_scope',
                    $queryBuilder->createNamedParameter('page')
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'rs.status',
                    $queryBuilder->createNamedParameter('completed')
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'rsp.is_failed',
                    $queryBuilder->createNamedParameter($failed ? 1 : 0, Connection::PARAM_INT)
                )
            );

        $this->addLanguageConstraint($queryBuilder, $languageUid);
        // Site-level coverage: a preview scan of a page visitors cannot open is no public result of the site.
        $this->addPublicScanConstraint($queryBuilder, 'rs');

        $rows = $queryBuilder
            ->orderBy('rs.finished_at', 'DESC')
            ->addOrderBy('rs.uid', 'DESC')
            ->addOrderBy('rsp.uid', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $latestByPageIdentity = [];
        foreach ($rows as $row) {
            $identity = $this->buildRemoteOverviewPageIdentity($row);
            if ($identity === '') {
                continue;
            }

            if (!isset($latestByPageIdentity[$identity])) {
                $latestByPageIdentity[$identity] = $row;
            }
        }

        $deduplicatedRows = array_values($latestByPageIdentity);
        $search = trim($search);
        if ($search !== '') {
            $deduplicatedRows = array_values(array_filter(
                $deduplicatedRows,
                fn(array $row): bool => $this->remoteOverviewPageMatchesSearch($row, $search, $failed)
            ));
        }

        usort(
            $deduplicatedRows,
            static function (array $a, array $b) use ($failed): int {
                $issuesCompare = $failed
                    ? ((int)($b['http_status'] ?? 0) <=> (int)($a['http_status'] ?? 0))
                    : ((int)($b['issues_count'] ?? 0) <=> (int)($a['issues_count'] ?? 0));
                if ($issuesCompare !== 0) {
                    return $issuesCompare;
                }

                $finishedCompare = (int)($b['remote_scan_finished_at'] ?? 0) <=> (int)($a['remote_scan_finished_at'] ?? 0);
                if ($finishedCompare !== 0) {
                    return $finishedCompare;
                }

                return (int)($b['uid'] ?? 0) <=> (int)($a['uid'] ?? 0);
            }
        );

        return $deduplicatedRows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildRemoteOverviewPageIdentity(array $row): string
    {
        $languageUid = (int)($row['remote_scan_language_uid'] ?? -1);
        $pageUid = (int)($row['typo3_page_uid'] ?? 0);
        if ($pageUid > 0) {
            return 'page:' . $pageUid . ':language:' . $languageUid;
        }

        $normalizedUrl = $this->normalizeRemoteOverviewUrl((string)($row['url'] ?? ''));
        if ($normalizedUrl !== '') {
            return 'url:' . $normalizedUrl . ':language:' . $languageUid;
        }

        return 'remote-page:' . (int)($row['uid'] ?? 0) . ':language:' . $languageUid;
    }

    private function normalizeRemoteOverviewUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $url = rtrim($url, '/');
        if ($url === '') {
            return '';
        }

        return mb_strtolower($url);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function remoteOverviewPageMatchesSearch(array $row, string $search, bool $includeFailureReason): bool
    {
        $needle = mb_strtolower(trim($search));
        if ($needle === '') {
            return true;
        }

        $haystacks = [
            (string)($row['title'] ?? ''),
            (string)($row['url'] ?? ''),
            (string)($row['http_status'] ?? ''),
            (string)($row['uid'] ?? ''),
            (string)($row['remote_scan_uid'] ?? ''),
            (string)($row['typo3_page_uid'] ?? ''),
            (string)($row['external_page_id'] ?? ''),
        ];

        if ($includeFailureReason) {
            $haystacks[] = (string)($row['failure_reason'] ?? '');
        }

        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }


    public function findPageForScanByUrl(int $remoteScanUid, string $url): ?array
    {
        if ($remoteScanUid <= 0 || trim($url) === '') {
            return null;
        }

        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN_PAGE);

        $queryBuilder
            ->select('rsp.*')
            ->from(Tables::REMOTE_SCAN_PAGE, 'rsp')
            ->where(
                $queryBuilder->expr()->eq(
                    'rsp.remote_scan',
                    $queryBuilder->createNamedParameter($remoteScanUid, Connection::PARAM_INT)
                )
            );

        $this->addUrlVariantConstraint($queryBuilder, $url);

        $row = $queryBuilder
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * Finds the locally persisted remote page report for one concrete API history row.
     *
     * This method intentionally does not fall back to the latest/current page report. A
     * history row must only link to a TYPO3 report when the exact API jobId and URL can
     * be mapped to a persisted tx_a11y_remote_scan_page row.
     */
    public function findPageForJobIdAndUrl(
        string $jobId,
        string $url,
        string $siteIdentifier = '',
        string $sourceType = ''
    ): ?array {
        $jobId = trim($jobId);
        $url = trim($url);
        $siteIdentifier = trim($siteIdentifier);
        $sourceType = strtolower(trim($sourceType));
        if ($jobId === '' || $url === '') {
            return null;
        }

        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN_PAGE);

        $queryBuilder
            ->select(
                'rsp.*',
                'rs.uid AS remote_scan_uid',
                'rs.job_id AS remote_scan_job_id',
                'rs.site_identifier AS remote_scan_site_identifier',
                'rs.page_uid AS remote_scan_page_uid',
                'rs.language_uid AS remote_scan_language_uid',
                'rs.finished_at AS remote_scan_finished_at',
                'rs.started_at AS remote_scan_started_at',
                'rs.scan_scope AS remote_scan_scope',
                'rs.source_type AS remote_scan_source_type'
            )
            ->from(Tables::REMOTE_SCAN_PAGE, 'rsp')
            ->innerJoin(
                'rsp',
                Tables::REMOTE_SCAN,
                'rs',
                $queryBuilder->expr()->eq('rs.uid', 'rsp.remote_scan')
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'rs.job_id',
                    $queryBuilder->createNamedParameter($jobId)
                )
            );

        if ($siteIdentifier !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'rs.site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                )
            );
        }

        if ($sourceType !== '') {
            $allowedSourceTypes = match ($sourceType) {
                'single_page', 'page' => ['single_page', 'page'],
                'site', 'sitemap', 'crawl' => ['site', 'sitemap', 'crawl'],
                default => [$sourceType],
            };
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in(
                    'rs.source_type',
                    $queryBuilder->createNamedParameter($allowedSourceTypes, Connection::PARAM_STR_ARRAY)
                )
            );
        }

        $this->addUrlVariantConstraint($queryBuilder, $url);

        $row = $queryBuilder
            ->orderBy('rs.finished_at', 'DESC')
            ->addOrderBy('rsp.uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return null;
        }

        if ((string)($row['remote_scan_job_id'] ?? '') !== $jobId) {
            return null;
        }

        return $row;
    }

    public function findLatestPageByUrl(string $url, string $siteIdentifier, ?bool $isFreePreview = null): ?array
    {
        return $this->findRecentPagesByUrl($url, $siteIdentifier, $isFreePreview, 1)[0] ?? null;
    }

    /**
     * STATE-01: the newest completed result pages of a URL, newest first (findLatestPageByUrl() is the first). A reader who
     * may not read the newest one (a reused URL, another owner) is shown the newest one they may read.
     *
     * @return list<array<string, mixed>>
     */
    public function findRecentPagesByUrl(string $url, string $siteIdentifier, ?bool $isFreePreview = null, int $limit = 10): array
    {
        if ($url === '' || $siteIdentifier === '') {
            return [];
        }

        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN_PAGE);

        $queryBuilder
            ->select('rsp.*')
            ->addSelectLiteral('rs.finished_at AS remote_scan_finished_at', 'rs.is_page_preview AS remote_scan_is_page_preview')
            ->from(Tables::REMOTE_SCAN_PAGE, 'rsp')
            ->innerJoin(
                'rsp',
                Tables::REMOTE_SCAN,
                'rs',
                $queryBuilder->expr()->eq('rs.uid', 'rsp.remote_scan')
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'rs.site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'rs.status',
                    $queryBuilder->createNamedParameter('completed')
                )
            );

        $this->addUrlVariantConstraint($queryBuilder, $url);
        $this->addFreePreviewConstraint($queryBuilder, $isFreePreview, 'rs');

        return array_values($queryBuilder
            ->orderBy('rs.finished_at', 'DESC')
            ->addOrderBy('rs.uid', 'DESC')
            ->addOrderBy('rsp.uid', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative());
    }


    public function findLatestPageForCompletedPageScan(
        string $siteIdentifier,
        int $pageUid,
        int $languageUid = -1,
        string $url = '',
        ?bool $isFreePreview = null,
    ): ?array {
        if ($siteIdentifier === '' || $pageUid <= 0) {
            return null;
        }

        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN_PAGE);

        $queryBuilder
            ->select('rsp.*')
            ->addSelectLiteral('rs.finished_at AS remote_scan_finished_at', 'rs.is_page_preview AS remote_scan_is_page_preview')
            ->from(Tables::REMOTE_SCAN_PAGE, 'rsp')
            ->innerJoin(
                'rsp',
                Tables::REMOTE_SCAN,
                'rs',
                $queryBuilder->expr()->eq('rs.uid', 'rsp.remote_scan')
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'rs.site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'rs.scan_scope',
                    $queryBuilder->createNamedParameter('page')
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'rs.page_uid',
                    $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->eq(
                    'rs.status',
                    $queryBuilder->createNamedParameter('completed')
                )
            );

        $this->addLanguageConstraint($queryBuilder, $languageUid);
        $this->addUrlVariantConstraint($queryBuilder, $url);
        $this->addFreePreviewConstraint($queryBuilder, $isFreePreview, 'rs');

        $row = $queryBuilder
            ->orderBy('rs.finished_at', 'DESC')
            ->addOrderBy('rs.uid', 'DESC')
            ->addOrderBy('rsp.uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    private function addUrlVariantConstraint(QueryBuilder $queryBuilder, string $url): void
    {
        $variants = $this->buildUrlVariants($url);
        if ($variants === []) {
            return;
        }

        $queryBuilder->andWhere(
            $queryBuilder->expr()->in(
                'rsp.url',
                $queryBuilder->createNamedParameter($variants, Connection::PARAM_STR_ARRAY)
            )
        );
    }

    /**
     * @return list<string>
     */
    private function buildUrlVariants(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return [];
        }

        $variants = [$url];

        $withoutTrailingSlash = rtrim($url, '/');
        if ($withoutTrailingSlash !== '') {
            $variants[] = $withoutTrailingSlash;
            $variants[] = $withoutTrailingSlash . '/';
        }

        return array_values(array_unique(array_filter($variants, static fn (string $variant): bool => $variant !== '')));
    }

    /**
     * The documented scalar fields of `/crawl/results` `pages[].screenshotEvidence` (version 1); anything else is dropped.
     *
     * @return array<string, int|bool|string>
     */
    public static function screenshotEvidenceSummary(mixed $evidence): array
    {
        if (!is_array($evidence) || (int)($evidence['version'] ?? 0) < 1) {
            return [];
        }

        return [
            'version' => (int)$evidence['version'],
            'originalAvailable' => (bool)($evidence['originalAvailable'] ?? false),
            'annotationsAvailable' => (bool)($evidence['annotationsAvailable'] ?? false),
            'imageWidth' => max(0, (int)($evidence['imageWidth'] ?? 0)),
            'imageHeight' => max(0, (int)($evidence['imageHeight'] ?? 0)),
            'findingsTotal' => max(0, (int)($evidence['findingsTotal'] ?? 0)),
            'findingsDrawn' => max(0, (int)($evidence['findingsDrawn'] ?? 0)),
            'regionsDrawn' => max(0, (int)($evidence['regionsDrawn'] ?? 0)),
            'issueTypesTotal' => max(0, (int)($evidence['issueTypesTotal'] ?? 0)),
            'recommendedPrimaryVariant' => ($evidence['recommendedPrimaryVariant'] ?? '') === 'original' ? 'original' : 'highlighted',
        ];
    }

    private function pageColumnExists(string $column): bool
    {
        if ($this->remoteScanPageColumns === null) {
            try {
                $this->remoteScanPageColumns = [];
                foreach ($this->getConnection(Tables::REMOTE_SCAN_PAGE)->createSchemaManager()->listTableColumns(Tables::REMOTE_SCAN_PAGE) as $definition) {
                    $this->remoteScanPageColumns[strtolower($definition->getName())] = true;
                }
            } catch (\Throwable) {
                $this->remoteScanPageColumns = [];
            }
        }

        return isset($this->remoteScanPageColumns[strtolower($column)]);
    }

    public function findPageByUid(int $pageUid): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN_PAGE);

        $row = $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_SCAN_PAGE)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findScanByUid(int $scanUid): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN);

        $row = $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_SCAN)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($scanUid, Connection::PARAM_INT)
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * Completed scans with the same scope, type, language and provenance, newest first. The caller still
     * checks start URL identity (RemoteScanPairingService::isCompatible()); language -1 only matches -1.
     *
     * @return list<array<string, mixed>>
     */
    public function findCompletedScansForPairing(
        string $siteIdentifier,
        string $scanScope,
        string $sourceType,
        int $languageUid,
        bool $isFreePreview,
        int $finishedBefore,
        int $excludeUid,
        int $limit,
        bool $isPagePreview = false,
    ): array {
        if ($siteIdentifier === '' || $scanScope === '') {
            return [];
        }

        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN);
        $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_SCAN)
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('scan_scope', $queryBuilder->createNamedParameter($scanScope)),
                $queryBuilder->expr()->eq('source_type', $queryBuilder->createNamedParameter($sourceType)),
                $queryBuilder->expr()->eq('language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('is_free_preview', $queryBuilder->createNamedParameter($isFreePreview ? 1 : 0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('is_page_preview', $queryBuilder->createNamedParameter($isPagePreview ? 1 : 0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter('completed')),
                $queryBuilder->expr()->neq('uid', $queryBuilder->createNamedParameter($excludeUid, Connection::PARAM_INT))
            )
            ->orderBy('finished_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(max(1, $limit));

        if ($finishedBefore > 0) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->lte('finished_at', $queryBuilder->createNamedParameter($finishedBefore, Connection::PARAM_INT))
            );
        }

        return array_values($queryBuilder->executeQuery()->fetchAllAssociative());
    }

    /**
     * Completed scans of a site, newest first. `$offset` pages through the whole stored history (Overview › Frontend scan
     * history › older scans); a page holds at most 100 rows, so no request loads the history at once.
     *
     * @param list<string> $sourceTypes
     * @return list<array<string, mixed>>
     */
    public function findCompletedHistoryScansBySiteAndSourceTypes(
        string $siteIdentifier,
        array $sourceTypes,
        int $limit = 10,
        int $languageUid = -1,
        int $offset = 0,
    ): array {
        $queryBuilder = $this->completedHistoryQuery($siteIdentifier, $sourceTypes, $languageUid);
        if ($queryBuilder === null) {
            return [];
        }

        $rows = $queryBuilder
            ->select('*')
            ->orderBy('finished_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->executeQuery()
            ->fetchAllAssociative();

        return is_array($rows) ? $rows : [];
    }

    /** @param list<string> $sourceTypes */
    public function countCompletedHistoryScansBySiteAndSourceTypes(string $siteIdentifier, array $sourceTypes, int $languageUid = -1): int
    {
        $queryBuilder = $this->completedHistoryQuery($siteIdentifier, $sourceTypes, $languageUid);

        return $queryBuilder === null ? 0 : (int)$queryBuilder->count('uid')->executeQuery()->fetchOne();
    }

    /** @param list<string> $sourceTypes */
    private function completedHistoryQuery(string $siteIdentifier, array $sourceTypes, int $languageUid): ?QueryBuilder
    {
        $siteIdentifier = trim($siteIdentifier);
        $sourceTypes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $sourceType): string => strtolower(trim((string)$sourceType)),
            $sourceTypes
        ))));

        if ($siteIdentifier === '' || $sourceTypes === []) {
            return null;
        }

        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN);
        $queryBuilder
            ->from(Tables::REMOTE_SCAN)
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter('completed')),
                $queryBuilder->expr()->in('source_type', $queryBuilder->createNamedParameter($sourceTypes, Connection::PARAM_STR_ARRAY))
            );
        $this->addLanguageConstraint($queryBuilder, $languageUid);
        $this->addPublicScanConstraint($queryBuilder);

        return $queryBuilder;
    }

    /** NEW-12: why a scan was marked failed. Only a failure TYPO3 decided on its own may later be revisited. */
    public const FAILURE_ORIGIN_CRAWLER = 'crawler';
    public const FAILURE_ORIGIN_MISSING = 'missing';
    public const FAILURE_ORIGIN_LOCAL_STALE = 'local_stale';

    public function markFailed(string $jobId, string $message, string $origin = self::FAILURE_ORIGIN_CRAWLER): void
    {
        $existing = $this->findScanByJobId($jobId);
        if (!is_array($existing)) {
            return;
        }

        $now = time();

        $this->getConnection(Tables::REMOTE_SCAN)->update(
            Tables::REMOTE_SCAN,
            [
                'status' => 'failed',
                'finished_at' => $now,
                'last_synced_at' => $now,
                'sync_error' => $message,
                'failure_origin' => $origin,
                'tstamp' => $now,
            ],
            [
                'uid' => (int)$existing['uid'],
            ]
        );
    }

    /**
     * Keeps the pages of the scan on which the rule has a finding that is not ignored — the pages a
     * "View affected pages" link for that rule refers to.
     */
    private function addRemoteRuleConstraint(QueryBuilder $queryBuilder, int $remoteScanUid, string $ruleId): void
    {
        if ($ruleId === '') {
            return;
        }

        $issues = $this->getQueryBuilder(Tables::REMOTE_ISSUE);
        $issues
            ->select('ri.remote_scan_page')
            ->from(Tables::REMOTE_ISSUE, 'ri')
            ->where(
                $issues->expr()->eq('ri.remote_scan', $queryBuilder->createNamedParameter($remoteScanUid, Connection::PARAM_INT)),
                $issues->expr()->eq('ri.rule_id', $queryBuilder->createNamedParameter($ruleId)),
                $issues->expr()->neq('ri.status', $queryBuilder->createNamedParameter('ignored')),
            );

        $queryBuilder->andWhere($queryBuilder->expr()->in('uid', $issues->getSQL()));
    }

    private function addRemotePageSearchConstraint(QueryBuilder $qb, string $search, bool $includeFailureReason, string $alias = ''): void
    {
        $search = trim($search);
        if ($search === '') {
            return;
        }

        $prefix = $alias !== '' ? $alias . '.' : '';
        $like = $qb->createNamedParameter('%' . mb_strtolower($search) . '%');

        $conditions = [
            'LOWER(COALESCE(' . $prefix . 'title, \'\')) LIKE ' . $like,
            'LOWER(COALESCE(' . $prefix . 'url, \'\')) LIKE ' . $like,
            'LOWER(CONCAT(COALESCE(' . $prefix . 'http_status, 0), \'\')) LIKE ' . $like,
        ];

        if ($includeFailureReason) {
            $conditions[] = 'LOWER(COALESCE(' . $prefix . 'failure_reason, \'\')) LIKE ' . $like;
        }

        $qb->andWhere('(' . implode(' OR ', $conditions) . ')');
    }
}
