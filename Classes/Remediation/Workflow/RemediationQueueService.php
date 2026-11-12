<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

use Priebera\A11yQualityGate\Domain\Repository\Contract\RemediationItemRepositoryInterface;

/**
 * The queue list. Visibility is the rule of every row (page show access, the current workspace, own requests without
 * the queue permission), applied before counting and paging: the workspace and own-request parts in SQL, page access
 * once per page of the scope, which then becomes part of the query. So counts never reveal items on pages the person
 * cannot open, and no amount of completed history hides open work.
 */
final class RemediationQueueService
{
    public const PER_PAGE = 25;
    /** Rows read per query where every row of a scope is needed (open work, a search). */
    private const BATCH_SIZE = 500;

    public const TABS = ['open', 'mine', 'done'];

    public function __construct(
        private readonly RemediationItemRepositoryInterface $items,
        private readonly RemediationAccessService $access,
    ) {}

    /** Longest search text taken from a request; longer input is cut, never an error. */
    public const QUERY_MAX_LENGTH = 100;

    /**
     * @param list<string> $sites readable site identifiers in scope
     * @param array{tab?:string,status?:string,owner?:string,pageUid?:int,languageUid?:int,severity?:int,origin?:string,batchId?:string,page?:int,query?:string} $filters
     * @param (callable(array<string,mixed>):string)|null $searchText the text a row is searched in (page title, finding,
     *        values); search runs only on rows the person may see, so a match never reveals another row
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,perPage:int,counts:array<string,int>}
     */
    public function list(array $sites, array $filters, ?callable $searchText = null): array
    {
        $tab = in_array($filters['tab'] ?? 'open', self::TABS, true) ? (string)($filters['tab'] ?? 'open') : 'open';
        $counts = ['open' => 0, 'mine' => 0, 'done' => 0];
        $scope = $this->visibleScope($sites, [
            'pageUid' => (int)($filters['pageUid'] ?? 0),
            'languageUid' => (int)($filters['languageUid'] ?? -1),
            'severity' => (int)($filters['severity'] ?? -1),
            'batchId' => (string)($filters['batchId'] ?? ''),
        ]);
        if ($scope === null) {
            return $this->page([], 0, 1, $counts);
        }

        foreach ($this->items->countForQueue($scope) as $status => $count) {
            $counts[RemediationStatus::isDone((string)$status) ? 'done' : 'open'] += $count;
        }
        // Open work is read in full (it is bounded by the open findings); "mine" depends on each row's apply rights.
        $open = $this->allRows($scope + ['excludeStatuses' => RemediationStatus::DONE]);
        foreach ($open as $item) {
            if ($this->isMine($item)) {
                $counts['mine']++;
            }
        }

        $status = (string)($filters['status'] ?? '');
        $owner = (string)($filters['owner'] ?? '');
        $origin = (string)($filters['origin'] ?? '');
        $query = self::normalizeQuery((string)($filters['query'] ?? ''));
        $requestedPage = max(1, (int)($filters['page'] ?? 1));
        $matches = function (array $item) use ($tab, $status, $owner, $origin, $query, $searchText): bool {
            if ($tab === 'mine' && !$this->isMine($item)) {
                return false;
            }
            if ($status !== '' && (string)$item['status'] !== $status) {
                return false;
            }
            if ($owner !== '' && (string)$item['owner'] !== $owner) {
                return false;
            }
            if ($origin !== '' && (string)$item['origin'] !== $origin) {
                return false;
            }

            return $query === '' || $searchText === null || str_contains(mb_strtolower($searchText($item)), $query);
        };

        if ($tab !== 'done') {
            return $this->paginate(array_values(array_filter($open, $matches)), $requestedPage, $counts);
        }
        if ($status !== '' && !RemediationStatus::isDone($status)) {
            return $this->page([], 0, 1, $counts);
        }
        $done = $scope + array_filter([
            'statuses' => $status !== '' ? [$status] : RemediationStatus::DONE,
            'owner' => $owner,
            'origin' => $origin,
        ]);
        if ($query !== '' && $searchText !== null) {
            return $this->paginate(array_values(array_filter($this->allRows($done), $matches)), $requestedPage, $counts);
        }
        // Completed history can be long: counted and paged by the database.
        $total = array_sum($this->items->countForQueue($done));
        $page = min(max(1, (int)ceil($total / self::PER_PAGE)), $requestedPage);

        return $this->page($this->items->findForQueue($done, self::PER_PAGE, ($page - 1) * self::PER_PAGE), $total, $page, $counts);
    }

    /**
     * Item counts per status of the rows this person may see in the sites (the Overview's AI activity line).
     *
     * @param list<string> $sites
     * @param array{statuses?:list<string>,savedSince?:int} $filters
     * @return array<string,int>
     */
    public function visibleCounts(array $sites, array $filters = []): array
    {
        $scope = $this->visibleScope($sites, []);

        return $scope === null ? [] : $this->items->countForQueue($scope + $filters);
    }

    /**
     * The repository filters that select exactly the rows RemediationAccessService::canSee() allows, or null when there
     * are none. Page access is checked once per page of the scope.
     *
     * @param list<string> $sites
     * @param array<string,mixed> $filters
     * @return array<string,mixed>|null
     */
    private function visibleScope(array $sites, array $filters): ?array
    {
        if ($sites === []) {
            return null;
        }
        $scope = ['sites' => $sites, 'workspaceId' => $this->access->currentWorkspace()] + $filters;
        if (!$this->access->mayUseBulk()) {
            $userUid = $this->access->userUid();
            if ($userUid <= 0) {
                return null;
            }
            $scope['createdBy'] = $userUid;
        }
        $pages = array_values(array_filter(
            $this->items->findQueuePageUids($scope),
            fn (int $pageUid): bool => $this->access->canReadPage($pageUid),
        ));

        if ($pages === []) {
            return null;
        }
        $scope += ['pageUids' => $pages];
        // canSee() also needs current read access to each item's record (table, language, live version, page): the
        // rows whose record the person may not read are left out of lists and counts alike.
        if (!$this->access->isAdmin()) {
            $refused = [];
            foreach ($this->items->findQueueTargets($scope) as $row) {
                if (!$this->access->canReadTargetRecord($row)) {
                    $refused[] = (int)$row['uid'];
                }
            }
            if ($refused !== []) {
                $scope['excludeUids'] = $refused;
            }
        }

        return $scope;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function allRows(array $filters): array
    {
        $rows = [];
        for ($offset = 0; ; $offset += self::BATCH_SIZE) {
            $batch = $this->items->findForQueue($filters, self::BATCH_SIZE, $offset);
            array_push($rows, ...$batch);
            if (count($batch) < self::BATCH_SIZE) {
                return $rows;
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,int> $counts
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,perPage:int,counts:array<string,int>}
     */
    private function paginate(array $rows, int $requestedPage, array $counts): array
    {
        $total = count($rows);
        $page = min(max(1, (int)ceil($total / self::PER_PAGE)), $requestedPage);

        return $this->page(array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE), $total, $page, $counts);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param array<string,int> $counts
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,perPage:int,counts:array<string,int>}
     */
    private function page(array $items, int $total, int $page, array $counts): array
    {
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / self::PER_PAGE)),
            'perPage' => self::PER_PAGE,
            'counts' => $counts,
        ];
    }

    /** Case-insensitive, trimmed, whitespace collapsed and capped: the search text as the rows are compared with it. */
    public static function normalizeQuery(string $query): string
    {
        $query = trim((string)preg_replace('/\s+/u', ' ', $query));

        return mb_strtolower(mb_substr($query, 0, self::QUERY_MAX_LENGTH));
    }

    /**
     * "My queue" is computed, not assigned (Atlas 08·D): what this person requested, plus what they may apply now.
     *
     * @param array<string,mixed> $item
     */
    public function isMine(array $item): bool
    {
        if ((int)$item['created_by'] === $this->access->userUid()) {
            return true;
        }

        return in_array((string)$item['status'], [RemediationStatus::PENDING_REVIEW, RemediationStatus::APPROVED, RemediationStatus::IN_EDITOR, RemediationStatus::CONFLICT, RemediationStatus::FAILED], true)
            && $this->access->applyRefusal($item, false) === '';
    }
}
