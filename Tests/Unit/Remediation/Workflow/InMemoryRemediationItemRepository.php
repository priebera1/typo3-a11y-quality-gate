<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation\Workflow;

use Priebera\A11yQualityGate\Domain\Repository\Contract\RemediationItemRepositoryInterface;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationWorkflowService;

/** The repository contract in memory, including its compare-and-set transition. */
final class InMemoryRemediationItemRepository implements RemediationItemRepositoryInterface
{
    /** @var array<int,array<string,mixed>> */
    public array $items = [];
    /** @var list<array<string,mixed>> */
    public array $events = [];
    /** @var array<string,string> */
    public array $owners = [];
    private int $next = 1;

    public function insert(array $row): int
    {
        $uid = $this->next++;
        $this->items[$uid] = $row + [
            'uid' => $uid, 'crdate' => time(), 'tstamp' => time(), 'row_version' => 0, 'proposal_version' => 0, 'attempts' => 0,
            'status_reason' => '', 'explanation' => '', 'provider' => '', 'model' => '', 'prompt_version' => '', 'input_tokens' => 0,
            'output_tokens' => 0, 'reviewed_by' => 0, 'reviewed_at' => 0, 'applied_by' => 0, 'applied_at' => 0, 'staged_at' => 0,
            'saved_at' => 0, 'checked_at' => 0, 'verified_at' => 0, 'edited_value' => '', 'proposed_value' => '', 'batch_id' => '',
        ];

        return $uid;
    }

    public function findByUid(int $uid): ?array
    {
        return $this->items[$uid] ?? null;
    }

    public function findActiveForFinding(int $findingUid, array $activeStatuses): ?array
    {
        return $this->findActiveForFindings([$findingUid], $activeStatuses)[0] ?? null;
    }

    public function findActiveForFindings(array $findingUids, array $activeStatuses): array
    {
        return array_values(array_filter($this->items, static fn (array $item): bool => in_array((int)$item['finding_uid'], $findingUids, true) && in_array($item['status'], $activeStatuses, true)));
    }

    public function transition(int $uid, int $expectedRowVersion, array $fromStatuses, string $toStatus, array $fields = []): bool
    {
        $item = $this->items[$uid] ?? null;
        if ($item === null || (int)$item['row_version'] !== $expectedRowVersion || !in_array($item['status'], $fromStatuses, true)) {
            return false;
        }
        unset($fields['uid'], $fields['row_version'], $fields['status']);
        $this->items[$uid] = ['status' => $toStatus, 'row_version' => $expectedRowVersion + 1, 'tstamp' => time()] + $fields + $item;

        return true;
    }

    public function claimGeneration(int $uid, int $expectedRowVersion, int $runningSince, array $fields = []): bool
    {
        $item = $this->items[$uid] ?? null;
        if ($item === null || (int)$item['row_version'] !== $expectedRowVersion || $item['status'] !== RemediationStatus::PREPARING
            || (($item['status_reason'] ?? '') === RemediationWorkflowService::GENERATING && (int)$item['tstamp'] >= $runningSince)) {
            return false;
        }
        unset($fields['uid'], $fields['row_version'], $fields['status'], $fields['status_reason'], $fields['tstamp']);
        $this->items[$uid] = ['status_reason' => RemediationWorkflowService::GENERATING, 'row_version' => $expectedRowVersion + 1, 'tstamp' => time()] + $fields + $item;

        return true;
    }

    public function addEvent(array $row): void
    {
        $this->events[] = $row + ['crdate' => time()];
    }

    public function findEvents(int $itemUid, int $limit = 50): array
    {
        return array_values(array_filter($this->events, static fn (array $event): bool => (int)$event['item_uid'] === $itemUid));
    }

    public function hasSeen(int $itemUid, int $beUser, int $proposalVersion): bool
    {
        foreach ($this->events as $event) {
            if ((int)$event['item_uid'] === $itemUid && (int)$event['be_user'] === $beUser && $event['action'] === 'viewed' && (int)$event['proposal_version'] === $proposalVersion) {
                return true;
            }
        }

        return false;
    }

    public function findForQueue(array $filters, int $limit, int $offset = 0): array
    {
        $rows = array_values(array_filter($this->items, static fn (array $item): bool => self::matchesQueue($item, $filters)));
        usort($rows, static fn (array $a, array $b): int => [(int)$b['tstamp'], (int)$b['uid']] <=> [(int)$a['tstamp'], (int)$a['uid']]);

        return array_slice($rows, max(0, $offset), max(1, min(1000, $limit)));
    }

    public function countForQueue(array $filters): array
    {
        $counts = [];
        foreach ($this->items as $item) {
            if (self::matchesQueue($item, $filters)) {
                $counts[(string)$item['status']] = ($counts[(string)$item['status']] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function findQueueTargets(array $filters): array
    {
        return array_values(array_filter($this->items, static fn (array $item): bool => self::matchesQueue($item, $filters)));
    }

    public function findQueuePageUids(array $filters): array
    {
        $pages = [];
        foreach ($this->items as $item) {
            if (self::matchesQueue($item, $filters)) {
                $pages[(int)$item['page_uid']] = true;
            }
        }

        return array_keys($pages);
    }

    /** @param array<string,mixed> $filters */
    private static function matchesQueue(array $item, array $filters): bool
    {
        return (!isset($filters['sites']) || in_array($item['site_identifier'], $filters['sites'], true))
            && (!isset($filters['pageUids']) || in_array((int)$item['page_uid'], $filters['pageUids'], true))
            && (!isset($filters['excludeUids']) || !in_array((int)($item['uid'] ?? 0), $filters['excludeUids'], true))
            && (($filters['pageUid'] ?? 0) <= 0 || (int)$item['page_uid'] === (int)$filters['pageUid'])
            && (($filters['languageUid'] ?? -1) < 0 || (int)($item['language_uid'] ?? 0) === (int)$filters['languageUid'])
            && (($filters['workspaceId'] ?? -1) < 0 || (int)($item['workspace_id'] ?? 0) === (int)$filters['workspaceId'])
            && (($filters['statuses'] ?? []) === [] || in_array($item['status'], $filters['statuses'], true))
            && (($filters['excludeStatuses'] ?? []) === [] || !in_array($item['status'], $filters['excludeStatuses'], true))
            && (($filters['owner'] ?? '') === '' || (string)($item['owner'] ?? '') === $filters['owner'])
            && (($filters['origin'] ?? '') === '' || (string)($item['origin'] ?? '') === $filters['origin'])
            && (($filters['severity'] ?? -1) < 0 || (int)($item['severity'] ?? 0) === (int)$filters['severity'])
            && (($filters['createdBy'] ?? 0) <= 0 || (int)($item['created_by'] ?? 0) === (int)$filters['createdBy'])
            && (($filters['batchId'] ?? '') === '' || (string)($item['batch_id'] ?? '') === $filters['batchId'])
            && (($filters['savedSince'] ?? 0) <= 0 || (int)($item['saved_at'] ?? 0) >= (int)$filters['savedSince']);
    }

    public function countByStatusForPage(string $siteIdentifier, int $pageUid, int $languageUid): array
    {
        return [];
    }

    public function countByStatusForSite(string $siteIdentifier, int $since = 0): array
    {
        return [];
    }

    public function findOwnerAssignment(string $siteIdentifier, int $pageUid, string $ruleId): ?string
    {
        return $this->owners[$siteIdentifier . '|' . $pageUid . '|' . $ruleId] ?? null;
    }

    public function findOwnerAssignmentsForPage(string $siteIdentifier, int $pageUid): array
    {
        $owners = [];
        foreach ($this->owners as $key => $owner) {
            [$site, $page, $ruleId] = explode('|', $key, 3);
            if ($site === $siteIdentifier && (int)$page === $pageUid) {
                $owners[$ruleId] = $owner;
            }
        }

        return $owners;
    }

    public function findOwnerAssignmentsForRules(string $siteIdentifier, array $ruleIds): array
    {
        $rows = [];
        foreach ($this->owners as $key => $owner) {
            [$site, $pageUid, $ruleId] = explode('|', $key, 3);
            if ($site === $siteIdentifier && (int)$pageUid > 0 && in_array($ruleId, $ruleIds, true)) {
                $rows[] = ['page_uid' => (int)$pageUid, 'rule_id' => $ruleId, 'owner' => $owner];
            }
        }

        return $rows;
    }

    public function saveOwnerAssignment(string $siteIdentifier, int $pageUid, string $ruleId, string $owner, int $beUser): void
    {
        $this->owners[$siteIdentifier . '|' . $pageUid . '|' . $ruleId] = $owner;
    }

    /** @return list<string> the actions recorded for one item, in order */
    public function actions(int $itemUid): array
    {
        return array_map(static fn (array $event): string => (string)$event['action'], $this->findEvents($itemUid));
    }

    /** Findings are not modelled here: the database query is covered by RemediationWorkflowFunctionalTest. */
    public function findInStatusesWithClosedFinding(array $statuses, int $limit = 200): array
    {
        return [];
    }
}
