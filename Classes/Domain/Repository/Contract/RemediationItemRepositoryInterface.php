<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository\Contract;

interface RemediationItemRepositoryInterface
{
    /** @param array<string,mixed> $row */
    public function insert(array $row): int;

    /** @return array<string,mixed>|null */
    public function findByUid(int $uid): ?array;

    /**
     * The item that currently holds a finding (one per finding while it is active).
     *
     * @param list<string> $activeStatuses
     * @return array<string,mixed>|null
     */
    public function findActiveForFinding(int $findingUid, array $activeStatuses): ?array;

    /**
     * Compare-and-set transition: only succeeds while the row still has $expectedRowVersion and one of $fromStatuses.
     * This is what makes concurrent approvals, double submissions and stale screens harmless.
     *
     * @param list<string> $fromStatuses
     * @param array<string,mixed> $fields
     */
    public function transition(int $uid, int $expectedRowVersion, array $fromStatuses, string $toStatus, array $fields = []): bool;

    /**
     * The generation claim: Preparing at exactly this row version and no generation running (status_reason
     * "generating" set at or after $runningSince). Sets "generating" with a fresh tstamp, so a second client that read
     * the new row version still cannot start a second request until this one ends or its lease runs out.
     *
     * @param array<string,mixed> $fields
     */
    public function claimGeneration(int $uid, int $expectedRowVersion, int $runningSince, array $fields = []): bool;

    /** @param array<string,mixed> $row */
    public function addEvent(array $row): void;

    /** @return list<array<string,mixed>> */
    public function findEvents(int $itemUid, int $limit = 50): array;

    /**
     * Whether $beUser has seen exactly this proposal version (a "viewed" event), so a bulk approval only covers
     * changes the person displayed.
     */
    public function hasSeen(int $itemUid, int $beUser, int $proposalVersion): bool;

    /**
     * Queue rows matching the filters, newest change first. Visibility (readable pages) is the caller's: it passes
     * the readable page uids as `pageUids`, so pagination and counts never cut off older rows.
     *
     * @param array{sites?:list<string>,pageUids?:list<int>,pageUid?:int,languageUid?:int,workspaceId?:int,statuses?:list<string>,excludeStatuses?:list<string>,owner?:string,origin?:string,severity?:int,createdBy?:int,batchId?:string,savedSince?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function findForQueue(array $filters, int $limit, int $offset = 0): array;

    /**
     * Row counts per status for the same filters as findForQueue().
     *
     * @param array<string,mixed> $filters
     * @return array<string,int>
     */
    public function countForQueue(array $filters): array;

    /**
     * The distinct page uids of the rows matching the filters (to check page access once per page).
     *
     * @param array<string,mixed> $filters
     * @return list<int>
     */
    public function findQueuePageUids(array $filters): array;

    /**
     * Item counts per status for findings of one page and language, for Page Detail, Overview and the Page module.
     *
     * @return array<string,int>
     */
    public function countByStatusForPage(string $siteIdentifier, int $pageUid, int $languageUid): array;

    /** @return list<array<string,mixed>> active items of the given findings, keyed by nothing (caller indexes) */
    public function findActiveForFindings(array $findingUids, array $activeStatuses): array;

    /**
     * U-09: items in one of the given states whose finding is no longer open or no longer exists.
     *
     * @param list<string> $statuses
     * @return list<array<string,mixed>>
     */
    public function findInStatusesWithClosedFinding(array $statuses, int $limit = 200): array;

    /** @return array<string,int> counts per status for one site (Overview activity line) */
    public function countByStatusForSite(string $siteIdentifier, int $since = 0): array;

    public function findOwnerAssignment(string $siteIdentifier, int $pageUid, string $ruleId): ?string;

    /** @return array<string,string> rule id => owner for one page */
    public function findOwnerAssignmentsForPage(string $siteIdentifier, int $pageUid): array;

    /**
     * Every assignment of the given rules on any page of one site (Overview "What to fix first"). The caller filters
     * the pages by read access.
     *
     * @param list<string> $ruleIds
     * @return list<array{page_uid:int,rule_id:string,owner:string}>
     */
    public function findOwnerAssignmentsForRules(string $siteIdentifier, array $ruleIds): array;

    public function saveOwnerAssignment(string $siteIdentifier, int $pageUid, string $ruleId, string $owner, int $beUser): void;
}
