<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Workspace;

/**
 * What one workspace publish request (DataHandler command map, after EXT:workspaces resolved its dependencies) puts
 * live: changed versions (incl. moves) that replace their live record, records created in the workspace, and deletions.
 * Records of the workspace that are not part of the request stay drafts and do not go live with it.
 *
 * overlay() turns rows read with `WorkspaceRestriction($workspaceId, true)` into the rows as they will be live right
 * after this publish, so a check can judge the published state before anything is written.
 */
final class WorkspacePublishSet
{
    private const STATE_NEW = 1;
    private const STATE_DELETE = 2;

    /**
     * @param array<string, array<int, array<string, mixed>>> $versions table → live uid → version row
     * @param array<string, array<int, array<string, mixed>>> $created table → uid → row created in the workspace
     * @param array<string, array<int, true>> $deleted table → live uid
     */
    private function __construct(
        public readonly int $workspaceId,
        private readonly array $versions,
        private readonly array $created,
        private readonly array $deleted,
    ) {
    }

    /**
     * @param array<string, mixed> $commandMap DataHandler::$cmdmap
     * @param callable(string, int): (array<string, mixed>|null) $rawRow reads one row without any restriction
     */
    public static function fromCommandMap(array $commandMap, callable $rawRow): ?self
    {
        $workspaceId = 0;
        $versions = $created = $deleted = [];
        foreach (self::publishCommands($commandMap) as [$table, $liveUid, $swapWith]) {
            $keyRow = $rawRow($table, $liveUid);
            if (!is_array($keyRow)) {
                continue;
            }
            if ((int)($keyRow['t3ver_state'] ?? 0) === self::STATE_NEW && (int)($keyRow['t3ver_wsid'] ?? 0) > 0) {
                // A record created in the workspace is published as itself (EXT:workspaces publishNewRecord()).
                $created[$table][$liveUid] = $keyRow;
                $workspaceId = $workspaceId ?: (int)$keyRow['t3ver_wsid'];
                continue;
            }
            $version = $rawRow($table, $swapWith);
            if (!is_array($version) || (int)($version['t3ver_wsid'] ?? 0) <= 0 || (int)($version['t3ver_oid'] ?? 0) !== $liveUid) {
                continue;
            }
            $workspaceId = $workspaceId ?: (int)$version['t3ver_wsid'];
            if ((int)($version['t3ver_state'] ?? 0) === self::STATE_DELETE) {
                $deleted[$table][$liveUid] = true;
            } else {
                $versions[$table][$liveUid] = $version;
            }
        }

        return $workspaceId > 0 ? new self($workspaceId, $versions, $created, $deleted) : null;
    }

    /**
     * The publish commands of a command map: [table, live uid (or the uid of a new record), version uid].
     *
     * @param array<string, mixed> $commandMap
     * @return list<array{0: string, 1: int, 2: int}>
     */
    public static function publishCommands(array $commandMap): array
    {
        $commands = [];
        foreach ($commandMap as $table => $records) {
            if (!is_array($records)) {
                continue;
            }
            foreach ($records as $uid => $recordCommands) {
                $version = is_array($recordCommands) ? ($recordCommands['version'] ?? null) : null;
                if (is_array($version) && in_array($version['action'] ?? '', ['publish', 'swap'], true)) {
                    $commands[] = [(string)$table, (int)$uid, (int)($version['swapWith'] ?? $uid)];
                }
            }
        }

        return $commands;
    }

    /**
     * The command map without its publish commands (every other command is kept).
     *
     * @param array<string, mixed> $commandMap
     * @return array<string, mixed>
     */
    public static function withoutPublishCommands(array $commandMap): array
    {
        foreach ($commandMap as $table => $records) {
            if (!is_array($records)) {
                continue;
            }
            foreach ($records as $uid => $recordCommands) {
                $version = is_array($recordCommands) ? ($recordCommands['version'] ?? null) : null;
                if (is_array($version) && in_array($version['action'] ?? '', ['publish', 'swap'], true)) {
                    unset($commandMap[$table][$uid]['version']);
                    if ($commandMap[$table][$uid] === []) {
                        unset($commandMap[$table][$uid]);
                    }
                }
            }
            if ($commandMap[$table] === []) {
                unset($commandMap[$table]);
            }
        }

        return $commandMap;
    }

    /**
     * Pages this request makes visible (the trigger of the Quality Gate, as for a live unhide): a page created in the
     * workspace that is not hidden, or a version that is not hidden of a hidden live page. Translations included.
     *
     * @param callable(string, int): (array<string, mixed>|null) $rawRow
     * @return list<array<string, mixed>> the page rows as they will be live
     */
    public function pagesBecomingVisible(callable $rawRow): array
    {
        $pages = [];
        foreach ($this->created['pages'] ?? [] as $uid => $row) {
            if ((int)($row['hidden'] ?? 0) === 0) {
                $pages[] = self::asLive($row, $uid);
            }
        }
        foreach ($this->versions['pages'] ?? [] as $liveUid => $version) {
            $live = $rawRow('pages', $liveUid);
            if ((int)($version['hidden'] ?? 0) === 0 && is_array($live) && (int)($live['hidden'] ?? 0) !== 0) {
                $pages[] = self::asLive($version, $liveUid);
            }
        }

        return $pages;
    }

    /** Whether this publish deletes the live record (a delete placeholder of the workspace is published). */
    public function deletes(string $table, int $liveUid): bool
    {
        return isset($this->deleted[$table][$liveUid]);
    }

    /** The row a live record will have after this publish, or null when it is not changed by it. */
    public function publishedVersionOf(string $table, int $liveUid): ?array
    {
        if (isset($this->versions[$table][$liveUid])) {
            return self::asLive($this->versions[$table][$liveUid], $liveUid);
        }

        return isset($this->created[$table][$liveUid]) ? self::asLive($this->created[$table][$liveUid], $liveUid) : null;
    }

    /**
     * Rows of a query that included this workspace (`WorkspaceRestriction($workspaceId, true)`), as they will be live
     * after the publish: published versions replace their live row (under the live uid), published new records join,
     * deleted records and every other draft drop out. $keep re-applies the query's own conditions to the result, since
     * a version can differ from its live row in exactly those fields (pid after a move, hidden, a relation).
     *
     * @param list<array<string, mixed>> $rows
     * @param callable(array<string, mixed>): bool $keep
     * @return list<array<string, mixed>>
     */
    public function overlay(string $table, array $rows, callable $keep): array
    {
        $result = [];
        foreach ($rows as $row) {
            $uid = (int)($row['uid'] ?? 0);
            $workspace = (int)($row['t3ver_wsid'] ?? 0);
            if ($workspace === 0) {
                if (isset($this->deleted[$table][$uid])) {
                    continue;
                }
                $result[$uid] = isset($this->versions[$table][$uid]) ? self::asLive($this->versions[$table][$uid], $uid) : $row;
                continue;
            }
            if ($workspace !== $this->workspaceId) {
                continue;
            }
            if (isset($this->created[$table][$uid])) {
                $result[$uid] = self::asLive($row, $uid);
                continue;
            }
            // A published version whose live row the query did not return (moved here, or a relation that changed).
            $liveUid = (int)($row['t3ver_oid'] ?? 0);
            if ($liveUid > 0 && (int)(($this->versions[$table][$liveUid] ?? [])['uid'] ?? 0) === $uid && !isset($result[$liveUid])) {
                $result[$liveUid] = self::asLive($this->versions[$table][$liveUid], $liveUid);
            }
        }

        return array_values(array_filter($result, $keep));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function asLive(array $row, int $liveUid): array
    {
        return ['uid' => $liveUid, '_ORIG_uid' => (int)($row['uid'] ?? 0), 't3ver_oid' => 0, 't3ver_wsid' => 0, 't3ver_state' => 0] + $row;
    }
}
