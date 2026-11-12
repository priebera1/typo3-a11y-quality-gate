<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\Contract\RemediationItemRepositoryInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The module change log (Atlas 08·E): the recorded steps of remediation items, newest first. It is scoped exactly like
 * the queue — an event is listed only when the person may see its item (RemediationAccessService::canSee(): page show
 * access, the current workspace, and without the queue permission ai.bulk only their own requests) — so the log never
 * reveals an item the queue would hide. Events hold codes and value hashes only; this reads them as stored.
 */
final class RemediationChangeLogService
{
    public const PER_PAGE = 25;

    public const PERIODS = ['7', '30', 'year'];
    public const DEFAULT_PERIOD = '30';

    /** The steps a reader filters by (Atlas "Action"); every other recorded step stays in "All changes". */
    public const ACTIONS = ['approved', 'saved', 'verified', 'rejected', 'conflict', 'apply_failed', 'edited', 'staged', 'suggested', 'prepared', 'reopened'];

    /** A view only records that a change was displayed (the bulk guard reads it); it changes nothing. */
    private const NOT_LOGGED = ['viewed'];

    /** Same window as the queue: the newest items per request. */
    private const ITEM_LIMIT = 1000;
    private const EVENT_LIMIT = 2000;

    public function __construct(
        private readonly RemediationItemRepositoryInterface $items,
        private readonly RemediationAccessService $access,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<string> $sites readable, licensed site identifiers in scope
     * @param array{period?:string,person?:int,action?:string,origin?:string,page?:int} $filters
     * @return array{entries:list<array{event:array<string,mixed>,item:array<string,mixed>}>,total:int,page:int,pages:int,people:list<int>}
     */
    public function list(array $sites, array $filters, ?int $now = null): array
    {
        $empty = ['entries' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'people' => []];
        if ($sites === []) {
            return $empty;
        }
        $origin = (string)($filters['origin'] ?? '');
        $visible = [];
        foreach ($this->items->findForQueue(['sites' => $sites], self::ITEM_LIMIT) as $item) {
            if ($this->access->canSee($item) && ($origin === '' || (string)$item['origin'] === $origin)) {
                $visible[(int)$item['uid']] = $item;
            }
        }
        if ($visible === []) {
            return $empty;
        }

        $action = in_array($filters['action'] ?? '', self::ACTIONS, true) ? (string)$filters['action'] : '';
        $events = $this->findEvents(array_keys($visible), self::since((string)($filters['period'] ?? self::DEFAULT_PERIOD), $now ?? time()), $action);

        // The person filter lists everybody who appears in this scope, so it is applied after collecting them.
        $people = array_values(array_unique(array_filter(array_map(static fn (array $event): int => (int)$event['be_user'], $events), static fn (int $uid): bool => $uid > 0)));
        sort($people);
        $person = (int)($filters['person'] ?? 0);
        if ($person > 0) {
            $events = array_values(array_filter($events, static fn (array $event): bool => (int)$event['be_user'] === $person));
        }

        $total = count($events);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int)($filters['page'] ?? 1)));
        $entries = array_map(
            static fn (array $event): array => ['event' => $event, 'item' => $visible[(int)$event['item_uid']]],
            array_slice($events, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
        );

        return ['entries' => $entries, 'total' => $total, 'page' => $page, 'pages' => $pages, 'people' => $people];
    }

    /** Start of the period: the last 7 or 30 days, or this calendar year. An unknown period is the default. */
    public static function since(string $period, int $now): int
    {
        if ($period === 'year') {
            return (int)mktime(0, 0, 0, 1, 1, (int)date('Y', $now));
        }
        $days = in_array($period, self::PERIODS, true) ? (int)$period : (int)self::DEFAULT_PERIOD;

        return $now - $days * 86400;
    }

    /**
     * @param list<int> $itemUids
     * @return list<array<string,mixed>>
     */
    private function findEvents(array $itemUids, int $since, string $action): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::REMEDIATION_EVENT);
        $qb->getRestrictions()->removeAll();
        $constraints = [
            $qb->expr()->in('item_uid', $qb->createNamedParameter($itemUids, Connection::PARAM_INT_ARRAY)),
            $qb->expr()->gte('crdate', $qb->createNamedParameter($since, Connection::PARAM_INT)),
            $qb->expr()->notIn('action', $qb->createNamedParameter(self::NOT_LOGGED, Connection::PARAM_STR_ARRAY)),
        ];
        if ($action !== '') {
            $constraints[] = $qb->expr()->eq('action', $qb->createNamedParameter($action));
        }

        return $qb->select('uid', 'crdate', 'item_uid', 'be_user', 'action', 'from_status', 'to_status', 'detail', 'proposal_version', 'value_hash')
            ->from(Tables::REMEDIATION_EVENT)
            ->where(...$constraints)
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(self::EVENT_LIMIT)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
