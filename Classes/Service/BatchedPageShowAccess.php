<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * BackendUtility::readPageAccess($uid, $user->getPagePermsClause(Permission::PAGE_SHOW)) for many pages at once
 * (AQG 3.0.0 large-installation fix). readPageAccess() answers true when the page row (not deleted) matches the
 * perms clause and BackendUserAuthentication::isInWebMount() finds a web mount on its rootline. That rootline is
 * BackendUtility::BEgetRootLine() with the same perms clause: it starts at the page — a workspace version is mapped
 * to its live record (t3ver_oid), a translation to its default page (l10n_parent) — and stops at the first
 * ancestor that does not match the clause.
 *
 * The same walk is done here level by level for all pages together: one query per chunk and tree level, instead of
 * 2–3 queries per page (16,000 pages of an 80-site installation were 32,000 queries per request).
 *
 * Only for the LIVE workspace: in a workspace, BEgetRootLine() overlays moved pages, which this walk does not model.
 * There (and for administrators) it returns null and callers keep the per-page check.
 */
final class BatchedPageShowAccess
{
    private const CHUNK = 500;
    private const MAX_DEPTH = 100;

    /**
     * @param list<int> $pageUids
     * @return array<int, bool>|null pageUid => may show; null = not applicable, use readPageAccess() per page
     */
    public static function readableMap(BackendUserAuthentication $backendUser, ConnectionPool $connectionPool, array $pageUids): ?array
    {
        if ($backendUser->isAdmin() || (int)($backendUser->workspace ?? 0) !== 0) {
            return null;
        }
        $pageUids = array_values(array_unique(array_filter(array_map('intval', $pageUids), static fn (int $uid): bool => $uid > 0)));
        if ($pageUids === []) {
            return [];
        }
        $permsClause = (string)$backendUser->getPagePermsClause(Permission::PAGE_SHOW);
        $mounts = array_fill_keys(array_map('intval', $backendUser->getWebmounts()), true);

        // 1. The pages themselves: row present, not deleted, matching the perms clause (readPageAccess's getRecord()).
        $own = self::rows($connectionPool, $pageUids, $permsClause, ['uid', 'pid', 't3ver_oid', 'sys_language_uid', 'l10n_parent']);
        // 2. Where isInWebMount() starts its rootline.
        $start = [];
        foreach ($pageUids as $uid) {
            $row = $own[$uid] ?? null;
            if ($row === null) {
                continue;
            }
            $id = $uid;
            if ((int)$row['t3ver_oid'] > 0) {
                $id = (int)$row['t3ver_oid'];
            }
            if ((int)$row['sys_language_uid'] !== 0 && (int)$row['l10n_parent'] !== 0) {
                $id = (int)$row['l10n_parent'];
            }
            $start[$uid] = $id;
        }
        // 3. The rootline rows (perms clause + not deleted), one query per chunk and level. false = row stops the walk.
        $parent = [];
        $frontier = array_values(array_unique(array_values($start)));
        for ($depth = 0; $depth < self::MAX_DEPTH && $frontier !== []; $depth++) {
            $rows = self::rows($connectionPool, $frontier, $permsClause, ['uid', 'pid']);
            $next = [];
            foreach ($frontier as $uid) {
                if (!isset($rows[$uid])) {
                    $parent[$uid] = false;
                    continue;
                }
                $pid = (int)$rows[$uid]['pid'];
                $parent[$uid] = $pid;
                if ($pid > 0 && !isset($mounts[$uid]) && !array_key_exists($pid, $parent)) {
                    $next[$pid] = $pid;
                }
            }
            $frontier = array_values($next);
        }

        $result = [];
        foreach ($pageUids as $uid) {
            $result[$uid] = isset($start[$uid]) && self::reachesMount($start[$uid], $parent, $mounts);
        }

        return $result;
    }

    /**
     * @param array<int, int|false> $parent
     * @param array<int, true> $mounts
     */
    private static function reachesMount(int $id, array $parent, array $mounts): bool
    {
        for ($steps = 0; $steps < self::MAX_DEPTH && $id > 0; $steps++) {
            if (!array_key_exists($id, $parent) || $parent[$id] === false) {
                // Row missing, deleted or not matching the perms clause: BEgetRootLine() stops here.
                return false;
            }
            if (isset($mounts[$id])) {
                return true;
            }
            $id = (int)$parent[$id];
        }

        return false;
    }

    /**
     * @param list<int> $uids
     * @param list<string> $fields
     * @return array<int, array<string, mixed>>
     */
    private static function rows(ConnectionPool $connectionPool, array $uids, string $permsClause, array $fields): array
    {
        $rows = [];
        foreach (array_chunk($uids, self::CHUNK) as $chunk) {
            $queryBuilder = $connectionPool->getQueryBuilderForTable(Tables::PAGES);
            $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
            $queryBuilder->select(...$fields)
                ->from(Tables::PAGES)
                ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)));
            if (trim($permsClause) !== '') {
                $queryBuilder->andWhere(QueryHelper::stripLogicalOperatorPrefix($permsClause));
            }
            foreach ($queryBuilder->executeQuery()->iterateAssociative() as $row) {
                $rows[(int)$row['uid']] = $row;
            }
        }

        return $rows;
    }
}
