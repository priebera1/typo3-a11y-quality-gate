<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Repository\Contract\RemediationItemRepositoryInterface;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationWorkflowService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class RemediationItemRepository extends AbstractRepository implements RemediationItemRepositoryInterface
{
    public function insert(array $row): int
    {
        $now = time();
        $connection = $this->getConnection(Tables::REMEDIATION_ITEM);
        $connection->insert(Tables::REMEDIATION_ITEM, ['pid' => 0, 'crdate' => $now, 'tstamp' => $now] + $row);

        return (int)$connection->lastInsertId();
    }

    public function findByUid(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }
        $qb = $this->getQueryBuilder(Tables::REMEDIATION_ITEM);
        $row = $qb->select('*')
            ->from(Tables::REMEDIATION_ITEM)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findActiveForFinding(int $findingUid, array $activeStatuses): ?array
    {
        $rows = $this->findActiveForFindings([$findingUid], $activeStatuses);

        return $rows[0] ?? null;
    }

    public function findActiveForFindings(array $findingUids, array $activeStatuses): array
    {
        $findingUids = array_values(array_unique(array_filter(array_map('intval', $findingUids), static fn (int $uid): bool => $uid > 0)));
        if ($findingUids === [] || $activeStatuses === []) {
            return [];
        }
        $qb = $this->getQueryBuilder(Tables::REMEDIATION_ITEM);

        return $qb->select('*')
            ->from(Tables::REMEDIATION_ITEM)
            ->where(
                $qb->expr()->in('finding_uid', $qb->createNamedParameter($findingUids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->in('status', $qb->createNamedParameter(array_values($activeStatuses), Connection::PARAM_STR_ARRAY)),
            )
            ->orderBy('uid', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * U-09: items in one of the given (pre-apply) states whose finding is no longer open or no longer exists.
     *
     * @param list<string> $statuses
     * @return list<array<string, mixed>>
     */
    public function findInStatusesWithClosedFinding(array $statuses, int $limit = 200): array
    {
        if ($statuses === []) {
            return [];
        }
        $qb = $this->getQueryBuilder(Tables::REMEDIATION_ITEM);
        $qb->getRestrictions()->removeAll();

        return array_values($qb->select('r.*')
            ->from(Tables::REMEDIATION_ITEM, 'r')
            ->leftJoin('r', Tables::ISSUE, 'i', $qb->expr()->eq('i.uid', $qb->quoteIdentifier('r.finding_uid')))
            ->where(
                $qb->expr()->in('r.status', $qb->createNamedParameter(array_values($statuses), Connection::PARAM_STR_ARRAY)),
                $qb->expr()->or(
                    $qb->expr()->isNull('i.uid'),
                    $qb->expr()->eq('i.deleted', $qb->createNamedParameter(1, Connection::PARAM_INT)),
                    $qb->expr()->neq('i.status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
                ),
            )
            ->orderBy('r.uid', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative());
    }

    public function transition(int $uid, int $expectedRowVersion, array $fromStatuses, string $toStatus, array $fields = []): bool
    {
        if ($uid <= 0 || $fromStatuses === []) {
            return false;
        }
        unset($fields['uid'], $fields['row_version'], $fields['status']);
        $qb = $this->getQueryBuilder(Tables::REMEDIATION_ITEM);
        $qb->update(Tables::REMEDIATION_ITEM)
            ->set('status', $toStatus)
            ->set('tstamp', time())
            ->set('row_version', $expectedRowVersion + 1)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)),
                $qb->expr()->eq('row_version', $qb->createNamedParameter($expectedRowVersion, Connection::PARAM_INT)),
                $qb->expr()->in('status', $qb->createNamedParameter(array_values($fromStatuses), Connection::PARAM_STR_ARRAY)),
            );
        foreach ($fields as $column => $value) {
            $qb->set((string)$column, $value);
        }

        return $qb->executeStatement() === 1;
    }

    public function claimGeneration(int $uid, int $expectedRowVersion, int $runningSince, array $fields = []): bool
    {
        if ($uid <= 0) {
            return false;
        }
        unset($fields['uid'], $fields['row_version'], $fields['status'], $fields['status_reason'], $fields['tstamp']);
        $qb = $this->getQueryBuilder(Tables::REMEDIATION_ITEM);
        $qb->update(Tables::REMEDIATION_ITEM)
            ->set('status_reason', RemediationWorkflowService::GENERATING)
            ->set('tstamp', time())
            ->set('row_version', $expectedRowVersion + 1)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)),
                $qb->expr()->eq('row_version', $qb->createNamedParameter($expectedRowVersion, Connection::PARAM_INT)),
                $qb->expr()->eq('status', $qb->createNamedParameter(RemediationStatus::PREPARING)),
                $qb->expr()->or(
                    $qb->expr()->neq('status_reason', $qb->createNamedParameter(RemediationWorkflowService::GENERATING)),
                    $qb->expr()->lt('tstamp', $qb->createNamedParameter($runningSince, Connection::PARAM_INT)),
                ),
            );
        foreach ($fields as $column => $value) {
            $qb->set((string)$column, $value);
        }

        return $qb->executeStatement() === 1;
    }

    public function addEvent(array $row): void
    {
        $this->getConnection(Tables::REMEDIATION_EVENT)->insert(Tables::REMEDIATION_EVENT, ['pid' => 0, 'crdate' => time()] + $row);
    }

    public function findEvents(int $itemUid, int $limit = 50): array
    {
        $qb = $this->getQueryBuilder(Tables::REMEDIATION_EVENT);

        return $qb->select('*')
            ->from(Tables::REMEDIATION_EVENT)
            ->where($qb->expr()->eq('item_uid', $qb->createNamedParameter($itemUid, Connection::PARAM_INT)))
            ->orderBy('crdate', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->setMaxResults(max(1, min(200, $limit)))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function hasSeen(int $itemUid, int $beUser, int $proposalVersion): bool
    {
        $qb = $this->getQueryBuilder(Tables::REMEDIATION_EVENT);
        $count = $qb->count('uid')
            ->from(Tables::REMEDIATION_EVENT)
            ->where(
                $qb->expr()->eq('item_uid', $qb->createNamedParameter($itemUid, Connection::PARAM_INT)),
                $qb->expr()->eq('be_user', $qb->createNamedParameter($beUser, Connection::PARAM_INT)),
                $qb->expr()->eq('action', $qb->createNamedParameter('viewed')),
                $qb->expr()->eq('proposal_version', $qb->createNamedParameter($proposalVersion, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return (int)$count > 0;
    }

    public function findForQueue(array $filters, int $limit, int $offset = 0): array
    {
        $qb = $this->getQueryBuilder(Tables::REMEDIATION_ITEM);
        $qb->select('*')->from(Tables::REMEDIATION_ITEM);
        $constraints = $this->queueConstraints($qb, $filters);
        if ($constraints === null) {
            return [];
        }
        if ($constraints !== []) {
            $qb->where(...$constraints);
        }

        return $qb->orderBy('tstamp', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(max(1, min(1000, $limit)))
            ->setFirstResult(max(0, $offset))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function countForQueue(array $filters): array
    {
        $qb = $this->getQueryBuilder(Tables::REMEDIATION_ITEM);
        $constraints = $this->queueConstraints($qb, $filters);
        if ($constraints === null) {
            return [];
        }
        $qb->select('status')
            ->addSelectLiteral('COUNT(*) AS item_count')
            ->from(Tables::REMEDIATION_ITEM)
            ->groupBy('status');
        if ($constraints !== []) {
            $qb->where(...$constraints);
        }

        return $this->countsFromRows($qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Uid and target of every row the filters select (for the target-record read check of the queue scope).
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function findQueueTargets(array $filters): array
    {
        $qb = $this->getQueryBuilder(Tables::REMEDIATION_ITEM);
        $constraints = $this->queueConstraints($qb, $filters);
        if ($constraints === null) {
            return [];
        }
        $qb->select('uid', 'target_kind', 'target_table', 'target_uid', 'target_descriptor')->from(Tables::REMEDIATION_ITEM);
        if ($constraints !== []) {
            $qb->where(...$constraints);
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    public function findQueuePageUids(array $filters): array
    {
        $qb = $this->getQueryBuilder(Tables::REMEDIATION_ITEM);
        $constraints = $this->queueConstraints($qb, $filters);
        if ($constraints === null) {
            return [];
        }
        $qb->select('page_uid')->from(Tables::REMEDIATION_ITEM)->groupBy('page_uid');
        if ($constraints !== []) {
            $qb->where(...$constraints);
        }

        return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<mixed>|null null when the filters exclude every row (an empty site or page list)
     */
    private function queueConstraints(QueryBuilder $qb, array $filters): ?array
    {
        $constraints = [];
        if (isset($filters['sites'])) {
            if ($filters['sites'] === []) {
                return null;
            }
            $constraints[] = $qb->expr()->in('site_identifier', $qb->createNamedParameter(array_values($filters['sites']), Connection::PARAM_STR_ARRAY));
        }
        if (isset($filters['pageUids'])) {
            if ($filters['pageUids'] === []) {
                return null;
            }
            $constraints[] = $qb->expr()->in('page_uid', $qb->createNamedParameter(array_values(array_map('intval', $filters['pageUids'])), Connection::PARAM_INT_ARRAY));
        }
        if (is_array($filters['excludeUids'] ?? null) && $filters['excludeUids'] !== []) {
            $constraints[] = $qb->expr()->notIn('uid', implode(',', array_map('intval', $filters['excludeUids'])));
        }
        if (($filters['pageUid'] ?? 0) > 0) {
            $constraints[] = $qb->expr()->eq('page_uid', $qb->createNamedParameter((int)$filters['pageUid'], Connection::PARAM_INT));
        }
        if (($filters['languageUid'] ?? -1) >= 0) {
            $constraints[] = $qb->expr()->eq('language_uid', $qb->createNamedParameter((int)$filters['languageUid'], Connection::PARAM_INT));
        }
        if (($filters['workspaceId'] ?? -1) >= 0) {
            $constraints[] = $qb->expr()->eq('workspace_id', $qb->createNamedParameter((int)$filters['workspaceId'], Connection::PARAM_INT));
        }
        if (($filters['statuses'] ?? []) !== []) {
            $constraints[] = $qb->expr()->in('status', $qb->createNamedParameter(array_values($filters['statuses']), Connection::PARAM_STR_ARRAY));
        }
        if (($filters['excludeStatuses'] ?? []) !== []) {
            $constraints[] = $qb->expr()->notIn('status', $qb->createNamedParameter(array_values($filters['excludeStatuses']), Connection::PARAM_STR_ARRAY));
        }
        if (($filters['owner'] ?? '') !== '') {
            $constraints[] = $qb->expr()->eq('owner', $qb->createNamedParameter((string)$filters['owner']));
        }
        if (($filters['origin'] ?? '') !== '') {
            $constraints[] = $qb->expr()->eq('origin', $qb->createNamedParameter((string)$filters['origin']));
        }
        if (($filters['severity'] ?? -1) >= 0) {
            $constraints[] = $qb->expr()->eq('severity', $qb->createNamedParameter((int)$filters['severity'], Connection::PARAM_INT));
        }
        if (($filters['createdBy'] ?? 0) > 0) {
            $constraints[] = $qb->expr()->eq('created_by', $qb->createNamedParameter((int)$filters['createdBy'], Connection::PARAM_INT));
        }
        if (($filters['batchId'] ?? '') !== '') {
            $constraints[] = $qb->expr()->eq('batch_id', $qb->createNamedParameter((string)$filters['batchId']));
        }
        if (($filters['savedSince'] ?? 0) > 0) {
            $constraints[] = $qb->expr()->gte('saved_at', $qb->createNamedParameter((int)$filters['savedSince'], Connection::PARAM_INT));
        }

        return $constraints;
    }

    public function countByStatusForPage(string $siteIdentifier, int $pageUid, int $languageUid): array
    {
        $qb = $this->getQueryBuilder(Tables::REMEDIATION_ITEM);
        $constraints = [
            $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
            $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
        ];
        if ($languageUid >= 0) {
            $constraints[] = $qb->expr()->eq('language_uid', $qb->createNamedParameter($languageUid, Connection::PARAM_INT));
        }
        $rows = $qb->select('status')
            ->addSelectLiteral('COUNT(*) AS item_count')
            ->from(Tables::REMEDIATION_ITEM)
            ->where(...$constraints)
            ->groupBy('status')
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->countsFromRows($rows);
    }

    public function countByStatusForSite(string $siteIdentifier, int $since = 0): array
    {
        $qb = $this->getQueryBuilder(Tables::REMEDIATION_ITEM);
        $constraints = [$qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier))];
        if ($since > 0) {
            $constraints[] = $qb->expr()->gte('tstamp', $qb->createNamedParameter($since, Connection::PARAM_INT));
        }
        $rows = $qb->select('status')
            ->addSelectLiteral('COUNT(*) AS item_count')
            ->from(Tables::REMEDIATION_ITEM)
            ->where(...$constraints)
            ->groupBy('status')
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->countsFromRows($rows);
    }

    public function findOwnerAssignment(string $siteIdentifier, int $pageUid, string $ruleId): ?string
    {
        $qb = $this->getQueryBuilder(Tables::REMEDIATION_OWNER);
        $owner = $qb->select('owner')
            ->from(Tables::REMEDIATION_OWNER)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_string($owner) && $owner !== '' ? $owner : null;
    }

    public function findOwnerAssignmentsForPage(string $siteIdentifier, int $pageUid): array
    {
        $qb = $this->getQueryBuilder(Tables::REMEDIATION_OWNER);
        $rows = $qb->select('rule_id', 'owner')
            ->from(Tables::REMEDIATION_OWNER)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();
        $owners = [];
        foreach ($rows as $row) {
            $owners[(string)$row['rule_id']] = (string)$row['owner'];
        }

        return $owners;
    }

    public function findOwnerAssignmentsForRules(string $siteIdentifier, array $ruleIds): array
    {
        $ruleIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $ruleId): string => (string)$ruleId, $ruleIds),
            static fn (string $ruleId): bool => $ruleId !== '' && strlen($ruleId) <= 255,
        )));
        if ($siteIdentifier === '' || $ruleIds === []) {
            return [];
        }
        $qb = $this->getQueryBuilder(Tables::REMEDIATION_OWNER);
        $rows = $qb->select('page_uid', 'rule_id', 'owner')
            ->from(Tables::REMEDIATION_OWNER)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->in('rule_id', $qb->createNamedParameter($ruleIds, Connection::PARAM_STR_ARRAY)),
                $qb->expr()->gt('page_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('page_uid')
            ->addOrderBy('rule_id')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'page_uid' => (int)$row['page_uid'],
            'rule_id' => (string)$row['rule_id'],
            'owner' => (string)$row['owner'],
        ], $rows);
    }

    public function saveOwnerAssignment(string $siteIdentifier, int $pageUid, string $ruleId, string $owner, int $beUser): void
    {
        $connection = $this->getConnection(Tables::REMEDIATION_OWNER);
        $updated = $connection->update(
            Tables::REMEDIATION_OWNER,
            ['owner' => $owner, 'assigned_by' => $beUser, 'tstamp' => time()],
            ['site_identifier' => $siteIdentifier, 'page_uid' => $pageUid, 'rule_id' => $ruleId],
        );
        if ($updated === 0) {
            try {
                $connection->insert(Tables::REMEDIATION_OWNER, [
                    'pid' => 0,
                    'tstamp' => time(),
                    'site_identifier' => $siteIdentifier,
                    'page_uid' => $pageUid,
                    'rule_id' => $ruleId,
                    'owner' => $owner,
                    'assigned_by' => $beUser,
                ]);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                // A concurrent assignment created the row first: the last write wins, like an edit.
                $connection->update(
                    Tables::REMEDIATION_OWNER,
                    ['owner' => $owner, 'assigned_by' => $beUser, 'tstamp' => time()],
                    ['site_identifier' => $siteIdentifier, 'page_uid' => $pageUid, 'rule_id' => $ruleId],
                );
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function countsFromRows(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)$row['status']] = (int)$row['item_count'];
        }

        return $counts;
    }
}
