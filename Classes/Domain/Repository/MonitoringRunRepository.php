<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;

final class MonitoringRunRepository extends AbstractRepository
{
    public function insertSubmitted(string $siteIdentifier, int $languageUid, string $jobId): int
    {
        $now = time();
        $connection = $this->getConnection(Tables::MONITORING_RUN);
        $connection->insert(Tables::MONITORING_RUN, [
            'site_identifier' => $siteIdentifier,
            'language_uid' => $languageUid,
            'job_id' => $jobId,
            'status' => 'submitted',
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * A run that never got a scan (N-13: the crawler could not be reached or refused it): evaluated and failed at once,
     * without coverage, so it can never become a baseline.
     */
    public function insertFailed(string $siteIdentifier, int $languageUid, string $stateFingerprint): int
    {
        $now = time();
        $connection = $this->getConnection(Tables::MONITORING_RUN);
        $connection->insert(Tables::MONITORING_RUN, [
            'site_identifier' => $siteIdentifier,
            'language_uid' => $languageUid,
            'job_id' => '',
            'status' => 'evaluated',
            'outcome' => 'failed',
            'state_fingerprint' => $stateFingerprint,
            'coverage_complete' => 0,
            'evaluated_at' => $now,
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPending(string $siteIdentifier, int $languageUid): ?array
    {
        return $this->findLatest($siteIdentifier, $languageUid, 'submitted');
    }

    /**
     * The latest evaluated run before the given one, whose regression state a new run is compared with.
     *
     * @return array<string, mixed>|null
     */
    public function findPreviousEvaluated(string $siteIdentifier, int $languageUid, int $beforeUid): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::MONITORING_RUN);
        $row = $queryBuilder
            ->select('*')
            ->from(Tables::MONITORING_RUN)
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter('evaluated')),
                $queryBuilder->expr()->lt('uid', $queryBuilder->createNamedParameter($beforeUid, Connection::PARAM_INT))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * The latest evaluated run before the given one whose scan covered everything its baseline had checked:
     * the next run's baseline. A run with missing coverage never becomes one.
     *
     * @return array<string, mixed>|null
     */
    public function findLatestTrustedRun(string $siteIdentifier, int $languageUid, int $beforeUid): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::MONITORING_RUN);
        $row = $queryBuilder
            ->select('*')
            ->from(Tables::MONITORING_RUN)
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter('evaluated')),
                $queryBuilder->expr()->eq('coverage_complete', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('delivery_pending', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->lt('uid', $queryBuilder->createNamedParameter($beforeUid, Connection::PARAM_INT))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * Whether a scan was a monitoring scan evaluated with missing coverage, which must not serve as a baseline.
     */
    public function isUntrustedRunJob(string $jobId): bool
    {
        if ($jobId === '') {
            return false;
        }

        $queryBuilder = $this->getQueryBuilder(Tables::MONITORING_RUN);

        return (int)$queryBuilder
            ->count('uid')
            ->from(Tables::MONITORING_RUN)
            ->where(
                $queryBuilder->expr()->eq('job_id', $queryBuilder->createNamedParameter($jobId)),
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter('evaluated')),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('coverage_complete', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    // D-04: an undelivered regression is no baseline either.
                    $queryBuilder->expr()->eq('delivery_pending', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
                )
            )
            ->executeQuery()
            ->fetchOne() > 0;
    }

    /**
     * NEW-10: runs before $runUid whose notification about the same state ($fingerprint) failed are marked delivered by
     * $runUid. Returns how many runs were superseded.
     */
    public function supersedePendingDeliveries(string $siteIdentifier, int $languageUid, int $runUid, string $fingerprint): int
    {
        $queryBuilder = $this->getQueryBuilder(Tables::MONITORING_RUN);

        return (int)$queryBuilder
            ->update(Tables::MONITORING_RUN)
            ->set('delivery_pending', 0, true, Connection::PARAM_INT)
            ->set('delivery_superseded_by', $runUid, true, Connection::PARAM_INT)
            ->set('tstamp', time(), true, Connection::PARAM_INT)
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('delivery_pending', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('state_fingerprint', $queryBuilder->createNamedParameter($fingerprint)),
                $queryBuilder->expr()->lt('uid', $queryBuilder->createNamedParameter($runUid, Connection::PARAM_INT)),
            )
            ->executeStatement();
    }

    /**
     * @param array<string, int|string> $data
     */
    public function update(int $uid, array $data): void
    {
        $this->getConnection(Tables::MONITORING_RUN)->update(
            Tables::MONITORING_RUN,
            $data + ['tstamp' => time()],
            ['uid' => $uid]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLatest(string $siteIdentifier, int $languageUid, string $status): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::MONITORING_RUN);
        $row = $queryBuilder
            ->select('*')
            ->from(Tables::MONITORING_RUN)
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter($status))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}
