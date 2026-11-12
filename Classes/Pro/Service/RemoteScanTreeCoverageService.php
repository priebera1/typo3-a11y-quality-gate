<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Frontend scan coverage against the TYPO3 page tree (2026-11 company acceptance: a site crawl without sitemap checked
 * the 5 navigation pages of a 16-page tree, and nothing said so).
 *
 * Inventory = the site's live, visible standard pages a visitor can open (doktype 1, not hidden, no scheduling window
 * closed, no frontend group, not excluded from search) in the scan's language. Checked/failed = the scan's stored pages
 * by their recorded TYPO3 page (owner columns). Without recorded owners (scans stored before 3.0) the inventory cannot be
 * compared, and the state says so — no percentage is invented.
 *
 * States: complete · partial · unknown. Reasons: no_sitemap (links followed from the start page), page_limit (the scan
 * stopped at its page limit), failed (pages could not be loaded), outside_sitemap (pages not listed in the sitemap).
 */
final class RemoteScanTreeCoverageService
{
    public const STATE_COMPLETE = 'complete';
    public const STATE_PARTIAL = 'partial';
    public const STATE_UNKNOWN = 'unknown';

    private ?bool $hasNoIndex = null;

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
    }

    /**
     * @param array<string, mixed> $scan tx_a11y_remote_scan row (a site scan)
     * @return array{state:string,inventory:int,checked:int,failed:int,notReached:int,examples:list<array{uid:int,title:string}>,reasons:list<string>,sourceType:string,maxPages:int}|null
     *         null for scans this does not describe (single page, running, Free Remote Preview)
     */
    public function forScan(array $scan, ?Site $site): ?array
    {
        if (!$site instanceof Site || (string)($scan['scan_scope'] ?? '') !== 'site' || (string)($scan['status'] ?? '') !== 'completed'
            || (int)($scan['is_free_preview'] ?? 0) === 1) {
            return null;
        }
        $languageUid = max(0, (int)($scan['language_uid'] ?? 0));
        $inventory = $this->inventory($site, $languageUid);
        [$checked, $failed, $owned] = $this->scannedOwners((int)($scan['uid'] ?? 0));
        $sourceType = (string)($scan['source_type'] ?? '');
        $maxPages = (int)($scan['max_pages'] ?? 0);
        $pagesTotal = (int)($scan['pages_total'] ?? 0);

        if ($owned === 0) {
            return [
                'state' => self::STATE_UNKNOWN, 'inventory' => count($inventory), 'checked' => (int)($scan['pages_scanned'] ?? 0),
                'failed' => (int)($scan['pages_failed'] ?? 0), 'notReached' => 0, 'examples' => [], 'reasons' => [],
                'sourceType' => $sourceType, 'maxPages' => $maxPages,
            ];
        }

        $notReached = array_diff_key($inventory, $checked, $failed);
        $reasons = [];
        if ($notReached !== []) {
            if ($maxPages > 0 && $pagesTotal >= $maxPages) {
                $reasons[] = 'page_limit';
            }
            $reasons[] = $sourceType === 'sitemap' ? 'outside_sitemap' : 'no_sitemap';
        }
        if ($failed !== []) {
            $reasons[] = 'failed';
        }
        $examples = [];
        foreach (array_slice($notReached, 0, 8, true) as $uid => $title) {
            $examples[] = ['uid' => (int)$uid, 'title' => (string)$title];
        }

        return [
            'state' => $notReached === [] && $failed === [] ? self::STATE_COMPLETE : self::STATE_PARTIAL,
            'inventory' => count($inventory),
            'checked' => count($checked),
            'failed' => count($failed),
            'notReached' => count($notReached),
            'examples' => $examples,
            'reasons' => array_values(array_unique($reasons)),
            'sourceType' => $sourceType,
            'maxPages' => $maxPages,
        ];
    }

    /** @return array<int, string> page uid => title */
    private function inventory(Site $site, int $languageUid): array
    {
        $pages = [];
        $level = [(int)$site->getRootPageId()];
        $now = time();
        $depth = 0;
        $all = [];
        while ($level !== [] && $depth < 30) {
            $all = [...$all, ...$level];
            $qb = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);
            $qb->getRestrictions()->removeAll();
            $level = array_map('intval', $qb->select('uid')->from(Tables::PAGES)
                ->where(
                    $qb->expr()->in('pid', $qb->createNamedParameter($level, Connection::PARAM_INT_ARRAY)),
                    $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                    $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                    $qb->expr()->eq('t3ver_wsid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                    $qb->expr()->eq('is_siteroot', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->executeQuery()->fetchFirstColumn());
            $depth++;
        }

        foreach (array_chunk($all, 500) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);
            $qb->getRestrictions()->removeAll();
            $qb->select(...$this->pageColumns('uid'))->from(Tables::PAGES)
                ->where($qb->expr()->in('uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)));
            foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
                if (!$this->isPublic($row, $now)) {
                    continue;
                }
                $pages[(int)$row['uid']] = (string)$row['title'];
            }
        }
        if ($languageUid > 0 && $pages !== []) {
            $pages = $this->translatedOnly($pages, $languageUid, $now);
        }
        ksort($pages);

        return $pages;
    }

    /**
     * @param array<int, string> $pages
     * @return array<int, string>
     */
    private function translatedOnly(array $pages, int $languageUid, int $now): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select(...$this->pageColumns('l10n_parent'))->from(Tables::PAGES)
            ->where(
                $qb->expr()->in('l10n_parent', $qb->createNamedParameter(array_keys($pages), Connection::PARAM_INT_ARRAY)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('t3ver_wsid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )->executeQuery()->fetchAllAssociative();
        $translated = [];
        foreach ($rows as $row) {
            if ($this->isPublic($row, $now)) {
                $translated[(int)$row['l10n_parent']] = (string)$row['title'];
            }
        }

        return $translated;
    }

    /** `no_index` exists only with EXT:seo. @return list<string> */
    private function pageColumns(string $key): array
    {
        $this->hasNoIndex ??= array_key_exists('no_index', $this->connectionPool->getConnectionForTable(Tables::PAGES)->createSchemaManager()->listTableColumns(Tables::PAGES));

        return array_merge([$key, 'title', 'doktype', 'hidden', 'starttime', 'endtime', 'fe_group'], $this->hasNoIndex ? ['no_index'] : []);
    }

    /** @param array<string, mixed> $row */
    private function isPublic(array $row, int $now): bool
    {
        return (int)($row['doktype'] ?? 0) === 1
            && (int)($row['hidden'] ?? 0) === 0
            && ((int)($row['starttime'] ?? 0) === 0 || (int)$row['starttime'] <= $now)
            && ((int)($row['endtime'] ?? 0) === 0 || (int)$row['endtime'] > $now)
            && in_array(trim((string)($row['fe_group'] ?? '')), ['', '0'], true)
            && (int)($row['no_index'] ?? 0) === 0;
    }

    /** @return array{0: array<int, true>, 1: array<int, true>, 2: int} checked owners, failed owners, rows with an owner */
    private function scannedOwners(int $remoteScanUid): array
    {
        if ($remoteScanUid <= 0) {
            return [[], [], 0];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::REMOTE_SCAN_PAGE);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('owner_page_uid', 'is_failed')->from(Tables::REMOTE_SCAN_PAGE)
            ->where(
                $qb->expr()->eq('remote_scan', $qb->createNamedParameter($remoteScanUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )->executeQuery()->fetchAllAssociative();
        $checked = [];
        $failed = [];
        $owned = 0;
        foreach ($rows as $row) {
            $owner = (int)$row['owner_page_uid'];
            if ($owner <= 0) {
                continue;
            }
            $owned++;
            if ((int)$row['is_failed'] === 1) {
                $failed[$owner] = true;
            } else {
                $checked[$owner] = true;
            }
        }

        return [$checked, array_diff_key($failed, $checked), $owned];
    }
}
