<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scan;

use Priebera\A11yQualityGate\Database\PageDoktypes;
use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;

final class PageCollector
{
    /**
     * @var int[]
     */
    private const EXCLUDED_DOKTYPES = [
        PageDoktypes::MENU_SEPARATOR,
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return int[]
     */
    public function collectPage(int $pageUid): array
    {
        return $this->pageExists($pageUid) ? [$pageUid] : [];
    }

    /**
     * @return int[]
     */
    public function collectSubtree(int $rootPid, int $depth = 99): array
    {
        if (!$this->pageExists($rootPid)) {
            return [];
        }

        $result = [$rootPid];
        $currentLevel = [$rootPid];
        $currentDepth = 0;

        while ($currentLevel !== [] && $currentDepth < $depth) {
            $children = $this->findChildren($currentLevel);

            if ($children === []) {
                break;
            }

            $result = [...$result, ...$children];
            $currentLevel = $children;
            $currentDepth++;
        }

        return array_values(array_unique($result));
    }

    private function pageExists(int $pageUid): bool
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);
        // Live pages only: a page version or a page created in a workspace is no page of the live tree.
        $qb->getRestrictions()->removeAll()->add(new WorkspaceRestriction(0));

        $row = $qb
            ->select('uid')
            ->from(Tables::PAGES)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->notIn(
                    'doktype',
                    $qb->createNamedParameter(self::EXCLUDED_DOKTYPES, Connection::PARAM_INT_ARRAY)
                ),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false;
    }

    /**
     * @param int[] $parentUids
     * @return int[]
     */
    private function findChildren(array $parentUids): array
    {
        if ($parentUids === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);
        // Live pages only: a page version or a page created in a workspace is no page of the live tree.
        $qb->getRestrictions()->removeAll()->add(new WorkspaceRestriction(0));

        $rows = $qb
            ->select('uid')
            ->from(Tables::PAGES)
            ->where(
                $qb->expr()->in(
                    'pid',
                    $qb->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)
                ),
                // D-02: a page translation is a language of its default page, not a page of its own. Returned as a page it
                // was rendered as "page <translation uid>, language 0" and its findings landed on the wrong record and language.
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->notIn(
                    'doktype',
                    $qb->createNamedParameter(self::EXCLUDED_DOKTYPES, Connection::PARAM_INT_ARRAY)
                ),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(static fn(mixed $uid): int => (int)$uid, $rows);
    }

    /**
     * N-18: the pages of the list that exist in the language (a live, not deleted translation; hidden translations
     * count, their rendered check records them as not public). Language 0 and "all languages" keep every page.
     *
     * @param list<int> $pageUids default-language pages
     * @return list<int>
     */
    public function withTranslationIn(array $pageUids, int $languageUid): array
    {
        if ($languageUid <= 0 || $pageUids === []) {
            return array_values($pageUids);
        }
        $translated = [];
        foreach (array_chunk(array_values($pageUids), 500) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);
            $qb->getRestrictions()->removeAll()->add(new WorkspaceRestriction(0));
            $rows = $qb
                ->select('l10n_parent')
                ->from(Tables::PAGES)
                ->where(
                    $qb->expr()->in('l10n_parent', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                    $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageUid, Connection::PARAM_INT)),
                    $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->executeQuery()
                ->fetchFirstColumn();
            foreach ($rows as $parent) {
                $translated[(int)$parent] = true;
            }
        }

        return array_values(array_filter($pageUids, static fn (int $pageUid): bool => isset($translated[$pageUid])));
    }

    /**
     * Languages a default-language page is translated into (live translation rows, any visibility: the rendered check
     * reports a hidden translation as not visible to visitors instead of skipping it silently).
     *
     * @return array<int, int> translation page uid => language uid
     */
    public function translationsOf(int $pageUid): array
    {
        if ($pageUid <= 0) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);
        $qb->getRestrictions()->removeAll()->add(new WorkspaceRestriction(0));
        $rows = $qb
            ->select('uid', 'sys_language_uid')
            ->from(Tables::PAGES)
            ->where(
                $qb->expr()->eq('l10n_parent', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->gt('sys_language_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sys_language_uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $translations = [];
        foreach ($rows as $row) {
            $translations[(int)$row['uid']] = (int)$row['sys_language_uid'];
        }

        return $translations;
    }
}
