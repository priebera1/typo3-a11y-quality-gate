<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Doctrine\DBAL\Exception;
use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;

final class ScanRepository extends AbstractRepository
{
    private const STATUS_RUNNING = 1;
    private const STATUS_COMPLETED = 2;
    private const STATUS_CANCELLED = 4;
    private const SCOPE_PAGE = 'page';
    private const SCOPE_SUBTREE = 'subtree';

    /** @var array<string, bool>|null */
    private ?array $scanColumns = null;

    public function createScanRun(
        string $siteIdentifier,
        int $rootPid,
        int $languageUid,
        string $scope,
    ): int {
        $now = time();
        $connection = $this->getConnection(Tables::SCAN);

        $connection->insert(Tables::SCAN, [
            'site_identifier' => $siteIdentifier,
            'root_pid' => $rootPid,
            'language_uid' => $languageUid,
            'scope' => $scope,
            'status' => 1,
            'started_at' => $now,
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        return (int)$connection->lastInsertId();
    }

    public function finishScanRun(
        int $scanUid,
        int $pagesScanned,
        int $recordsScanned,
        int $issuesNew,
        int $issuesResolved,
        int $issuesIgnored,
        array $renderedCoverage = [],
    ): void {
        $now = time();

        $row = [
            'status' => self::STATUS_COMPLETED,
            'finished_at' => $now,
            'tstamp' => $now,
            'pages_scanned' => $pagesScanned,
            'records_scanned' => $recordsScanned,
            'issues_new' => $issuesNew,
            'issues_resolved' => $issuesResolved,
            'issues_ignored' => $issuesIgnored,
        ];
        if ($renderedCoverage !== []) {
            // Audit C13: which pages had their rendered HTML checked. Written only once the columns exist (database
            // compare after an update), so a scan never fails on a missing column.
            $row += [
                'rendered_checked' => (int)($renderedCoverage['checked'] ?? 0),
                'rendered_skipped' => (int)($renderedCoverage['skipped'] ?? 0),
                'rendered_skipped_json' => (string)json_encode(
                    array_values((array)($renderedCoverage['skippedPages'] ?? [])),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ),
            ];
        }

        $this->getConnection(Tables::SCAN)->update(Tables::SCAN, $this->withExistingColumns($row), [
            'uid' => $scanUid,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withExistingColumns(array $row): array
    {
        if ($this->scanColumns === null) {
            try {
                $this->scanColumns = [];
                foreach ($this->getConnection(Tables::SCAN)->createSchemaManager()->listTableColumns(Tables::SCAN) as $column) {
                    $this->scanColumns[strtolower($column->getName())] = true;
                }
            } catch (\Throwable) {
                $this->scanColumns = [];
            }
        }
        if ($this->scanColumns === []) {
            return $row;
        }

        return array_filter($row, fn (string $column): bool => isset($this->scanColumns[strtolower($column)]), ARRAY_FILTER_USE_KEY);
    }

    public function failScanRun(int $scanUid): void
    {
        $now = time();

        $this->getConnection(Tables::SCAN)->update(Tables::SCAN, [
            'status' => 3,
            'finished_at' => $now,
            'tstamp' => $now,
        ], [
            'uid' => $scanUid,
        ]);
    }

    public function cancelScanRun(
        int $scanUid,
        int $pagesScanned,
        int $recordsScanned,
        int $issuesNew,
        int $issuesResolved,
        int $issuesIgnored,
    ): void {
        $now = time();

        $this->getConnection(Tables::SCAN)->update(Tables::SCAN, [
            'status' => self::STATUS_CANCELLED,
            'finished_at' => $now,
            'tstamp' => $now,
            'pages_scanned' => $pagesScanned,
            'records_scanned' => $recordsScanned,
            'issues_new' => $issuesNew,
            'issues_resolved' => $issuesResolved,
            'issues_ignored' => $issuesIgnored,
        ], [
            'uid' => $scanUid,
        ]);
    }


    public function requestScanCancellation(int $scanUid): void
    {
        $now = time();

        $this->getConnection(Tables::SCAN)->update(Tables::SCAN, [
            'status' => self::STATUS_CANCELLED,
            'tstamp' => $now,
        ], [
            'uid' => $scanUid,
            'status' => self::STATUS_RUNNING,
        ]);
    }

    public function isScanCancellationRequested(int $scanUid): bool
    {
        if ($scanUid <= 0) {
            return false;
        }

        $qb = $this->getQueryBuilder(Tables::SCAN);
        $status = $qb
            ->select('status')
            ->from(Tables::SCAN)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($scanUid, Connection::PARAM_INT))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return (int)$status === self::STATUS_CANCELLED;
    }


    /**
     * Returns the newest running local scan for toolbar status purposes.
     *
     * If no site identifier is provided, the newest running scan across all
     * sites is returned. This is intentional for the backend toolbar, because
     * the topbar request does not always carry a page or site context.
     *
     * @return array<string, mixed>|null
     * @throws Exception
     */
    public function findLatestRunningScan(string $siteIdentifier = '', int $languageUid = -1): ?array
    {
        $qb = $this->getQueryBuilder(Tables::SCAN);

        $qb
            ->select('*')
            ->from(Tables::SCAN)
            ->where(
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_RUNNING, Connection::PARAM_INT))
            );

        $this->addSiteConstraint($qb, $siteIdentifier);
        $this->addLanguageConstraint($qb, $languageUid);

        $row = $qb
            ->orderBy('started_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    /**
     * Returns the newest completed local scan for toolbar status purposes.
     *
     * This intentionally includes page and subtree scans. The toolbar is a
     * global backend indicator and should show the latest completed local scan,
     * regardless of whether it was started from the module, Scheduler or CLI.
     *
     * If no site identifier is provided, the newest completed scan across all
     * sites is returned as a fallback for topbar requests without page context.
     *
     * @return array<string, mixed>|null
     * @throws Exception
     */
    public function findLastCompletedLocalScan(string $siteIdentifier = '', int $languageUid = -1): ?array
    {
        $qb = $this->getQueryBuilder(Tables::SCAN);

        $qb
            ->select('*')
            ->from(Tables::SCAN)
            ->where(
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_COMPLETED, Connection::PARAM_INT))
            );

        $this->addSiteConstraint($qb, $siteIdentifier);
        $this->addLanguageConstraint($qb, $languageUid);

        $row = $qb
            ->orderBy('finished_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     * @throws Exception
     */
    public function findLatestRunningPageScan(string $siteIdentifier, int $pageUid, int $languageUid = -1): ?array
    {
        $qb = $this->getQueryBuilder(Tables::SCAN);

        $row = $qb
            ->select('*')
            ->from(Tables::SCAN)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('root_pid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_RUNNING, Connection::PARAM_INT)),
                $qb->expr()->eq('scope', $qb->createNamedParameter(self::SCOPE_PAGE)),
            );

        $this->addLanguageConstraint($qb, $languageUid);

        $row = $qb
            ->orderBy('started_at', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    /**
     * Used by the toolbar to detect Scheduler/CLI scans while they are running.
     * Backend module scans still use the live registry snapshot.
     *
     * @return array<string, mixed>|null
     * @throws Exception
     */
    public function findLatestRunningSubtreeScan(string $siteIdentifier, int $languageUid = -1): ?array
    {
        $qb = $this->getQueryBuilder(Tables::SCAN);

        $row = $qb
            ->select('*')
            ->from(Tables::SCAN)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_RUNNING, Connection::PARAM_INT)),
                $qb->expr()->eq('scope', $qb->createNamedParameter(self::SCOPE_SUBTREE)),
            );

        $this->addLanguageConstraint($qb, $languageUid);

        $row = $qb
            ->orderBy('started_at', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     * @throws Exception
     */
    public function findLastCompletedScan(string $siteIdentifier, int $languageUid = -1): ?array
    {
        $qb = $this->getQueryBuilder(Tables::SCAN);

        $row = $qb
            ->select('*')
            ->from(Tables::SCAN)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_COMPLETED, Connection::PARAM_INT)),
            );

        $this->addLanguageConstraint($qb, $languageUid);

        $row = $qb
            ->orderBy('finished_at', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    /**
     * The newest completed subtree scan started on this page or one of its ancestors, which checked this page too.
     *
     * @return array<string, mixed>|null
     */
    public function findLastCompletedSubtreeScanCoveringPage(string $siteIdentifier, int $pageUid, int $languageUid = -1): ?array
    {
        if ($siteIdentifier === '' || $pageUid <= 0) {
            return null;
        }

        $rootlinePageUids = array_values(array_filter(
            array_map(static fn (array $page): int => (int)($page['uid'] ?? 0), BackendUtility::BEgetRootLine($pageUid)),
            static fn (int $uid): bool => $uid > 0
        ));
        if ($rootlinePageUids === []) {
            return null;
        }

        $qb = $this->getQueryBuilder(Tables::SCAN);
        $qb
            ->select('*')
            ->from(Tables::SCAN)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->in('root_pid', $qb->createNamedParameter($rootlinePageUids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_COMPLETED, Connection::PARAM_INT)),
                $qb->expr()->eq('scope', $qb->createNamedParameter(self::SCOPE_SUBTREE)),
            );

        // A site scan only checked the pages that existed when it started.
        $pageCreatedAt = (int)(BackendUtility::getRecord('pages', $pageUid, 'crdate')['crdate'] ?? 0);
        if ($pageCreatedAt > 0) {
            $qb->andWhere($qb->expr()->gte('started_at', $qb->createNamedParameter($pageCreatedAt, Connection::PARAM_INT)));
        }

        $this->addLanguageConstraint($qb, $languageUid);

        $row = $qb
            ->orderBy('finished_at', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     * @throws Exception
     */
    public function findLastCompletedPageScan(string $siteIdentifier, int $pageUid, int $languageUid = -1): ?array
    {
        $qb = $this->getQueryBuilder(Tables::SCAN);

        $row = $qb
            ->select('*')
            ->from(Tables::SCAN)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('root_pid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_COMPLETED, Connection::PARAM_INT)),
                $qb->expr()->eq('scope', $qb->createNamedParameter(self::SCOPE_PAGE)),
            );

        $this->addLanguageConstraint($qb, $languageUid);

        $row = $qb
            ->orderBy('finished_at', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    /**
     * Used by Overview to show only the last site-level scan,
     * not page-level scans triggered by content changes.
     *
     * @return array<string, mixed>|null
     * @throws Exception
     */
    public function findLastCompletedSubtreeScan(string $siteIdentifier, int $languageUid = -1): ?array
    {
        $qb = $this->getQueryBuilder(Tables::SCAN);

        $row = $qb
            ->select('*')
            ->from(Tables::SCAN)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_COMPLETED, Connection::PARAM_INT)),
                $qb->expr()->eq('scope', $qb->createNamedParameter(self::SCOPE_SUBTREE)),
            );

        $this->addLanguageConstraint($qb, $languageUid);

        $row = $qb
            ->orderBy('finished_at', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }
    private function addSiteConstraint(\TYPO3\CMS\Core\Database\Query\QueryBuilder $qb, string $siteIdentifier): void
    {
        $siteIdentifier = trim($siteIdentifier);
        if ($siteIdentifier === '') {
            return;
        }

        $qb->andWhere(
            $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier))
        );
    }

    private function addLanguageConstraint(\TYPO3\CMS\Core\Database\Query\QueryBuilder $qb, int $languageUid): void
    {
        if ($languageUid < 0) {
            return;
        }

        $qb->andWhere(
            $qb->expr()->or(
                $qb->expr()->eq('language_uid', $qb->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('language_uid', $qb->createNamedParameter(-1, Connection::PARAM_INT))
            )
        );
    }
}
