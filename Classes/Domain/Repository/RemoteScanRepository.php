<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class RemoteScanRepository extends AbstractRepository
{
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
        int $pid = 0,
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

    public function markSubmitted(
        string $siteIdentifier,
        string $jobId,
        RemoteScanSourceType $sourceType,
        string $startUrl,
        ?string $sitemapUrl,
        string $status,
        string $scanScope = 'site',
        int $pageUid = 0,
        int $pid = 0,
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
            pid: $pid,
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

    public function findLatestActiveScanBySite(string $siteIdentifier): ?array
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
            )
            ->orderBy('started_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findLatestActiveSiteScanBySite(string $siteIdentifier): ?array
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
            )
            ->orderBy('started_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findLastCompletedScanBySite(string $siteIdentifier): ?array
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
            ->orderBy('finished_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findLastCompletedSiteScanBySite(string $siteIdentifier): ?array
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
            ->orderBy('finished_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findLastCompletedRelevantScan(string $siteIdentifier, int $pageUid): ?array
    {
        if ($pageUid > 0) {
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
                )
                ->orderBy('finished_at', 'DESC')
                ->addOrderBy('uid', 'DESC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if (is_array($row)) {
                return $row;
            }
        }

        return $this->findLastCompletedSiteScanBySite($siteIdentifier);
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

    public function findLatestRelevantActiveScan(string $siteIdentifier, int $pageUid): ?array
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
            )
            ->orderBy('started_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (is_array($row)) {
            return $row;
        }

        return $this->findLatestActiveSiteScanBySite($siteIdentifier);
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
                'crdate' => $now,
                'tstamp' => $now,
            ]);

            $pageUidByUrl[$url] = (int)$connection->lastInsertId();
        }

        return $pageUidByUrl;
    }

    public function countPagesForScan(int $remoteScanUid, bool $failed, string $search = ''): int
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

        $row = $queryBuilder
            ->executeQuery()
            ->fetchAssociative();

        return (int)($row['cnt'] ?? 0);
    }

    public function findPagesForScan(int $remoteScanUid, string $search = ''): array
    {
        $total = $this->countPagesForScan($remoteScanUid, false, $search);
        if ($total <= 0) {
            return [];
        }

        return $this->findPagesForScanPaginated($remoteScanUid, $total, 0, $search);
    }

    public function findPagesForScanPaginated(int $remoteScanUid, int $limit, int $offset, string $search = ''): array
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

        return $queryBuilder
            ->orderBy('issues_count', 'DESC')
            ->addOrderBy('uid', 'ASC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();
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

    public function findPageForScanByUrl(int $remoteScanUid, string $url): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN_PAGE);

        $row = $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_SCAN_PAGE)
            ->where(
                $queryBuilder->expr()->eq(
                    'remote_scan',
                    $queryBuilder->createNamedParameter($remoteScanUid, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'url',
                    $queryBuilder->createNamedParameter($url)
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findLatestPageByUrl(string $url, string $siteIdentifier): ?array
    {
        if ($url === '' || $siteIdentifier === '') {
            return null;
        }

        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_SCAN_PAGE);

        $row = $queryBuilder
            ->select('rsp.*')
            ->from(Tables::REMOTE_SCAN_PAGE, 'rsp')
            ->innerJoin(
                'rsp',
                Tables::REMOTE_SCAN,
                'rs',
                $queryBuilder->expr()->eq('rs.uid', 'rsp.remote_scan')
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'rsp.url',
                    $queryBuilder->createNamedParameter($url)
                )
            )
            ->andWhere(
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
            )
            ->orderBy('rs.finished_at', 'DESC')
            ->addOrderBy('rs.uid', 'DESC')
            ->addOrderBy('rsp.uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
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

    public function markFailed(string $jobId, string $message): void
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
                'tstamp' => $now,
            ],
            [
                'uid' => (int)$existing['uid'],
            ]
        );
    }

    private function addRemotePageSearchConstraint(QueryBuilder $qb, string $search, bool $includeFailureReason): void
    {
        $search = trim($search);
        if ($search === '') {
            return;
        }

        $like = $qb->createNamedParameter('%' . mb_strtolower($search) . '%');

        $conditions = [
            'LOWER(COALESCE(title, \'\')) LIKE ' . $like,
            'LOWER(COALESCE(url, \'\')) LIKE ' . $like,
            'LOWER(CONCAT(COALESCE(http_status, 0), \'\')) LIKE ' . $like,
        ];

        if ($includeFailureReason) {
            $conditions[] = 'LOWER(COALESCE(failure_reason, \'\')) LIKE ' . $like;
        }

        $qb->andWhere('(' . implode(' OR ', $conditions) . ')');
    }
}