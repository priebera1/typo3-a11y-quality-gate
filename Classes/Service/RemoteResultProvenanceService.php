<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Which TYPO3 page (and translation) a crawled result URL belonged to when the scan was stored (Sol R01).
 *
 * The owner is resolved once, when the crawler's results are persisted, and stored on the result page
 * (`tx_a11y_remote_scan_page.owner_*`). Reading a result later checks the current rights on that stored owner, never on
 * whatever page carries the URL's slug today: a slug moved to another page does not move old results with it.
 *
 * Resolution is exact or nothing: the site language whose base matches host and path (longest match), then either a
 * numeric TYPO3 page id in the query (`?id=3`, which TYPO3 routes over the path) or the page whose slug equals the rest
 * of the path. Translated slugs resolve to their default-language page and keep the translation's uid. Unknown hosts,
 * unknown or ambiguous slugs, route-enhancer paths and non-numeric ids stay unresolved: such results are shown to
 * administrators only. A page scan's result is never attributed to the scanned page merely because it was a page scan.
 */
final class RemoteResultProvenanceService
{
    public const STATE_LEGACY = 0;
    public const STATE_RESOLVED = 1;
    public const STATE_UNRESOLVED = 2;

    private const CHUNK = 500;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteResolutionService $siteResolutionService,
    ) {
    }

    /**
     * Resolves and stores the owner of every result page of a scan. Called by the persistence of crawler results.
     */
    public function stampScan(int $remoteScanUid, string $siteIdentifier): void
    {
        if ($remoteScanUid <= 0 || !$this->hasOwnerColumns()) {
            return;
        }
        $site = $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(Tables::REMOTE_SCAN_PAGE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder->select('uid', 'url')
            ->from(Tables::REMOTE_SCAN_PAGE)
            ->where($queryBuilder->expr()->eq('remote_scan', $queryBuilder->createNamedParameter($remoteScanUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAllAssociative();
        $urls = array_column($rows, 'url', 'uid');
        $owners = $site instanceof Site ? $this->resolve($site, array_map('strval', $urls)) : [];

        $connection = $this->connectionPool->getConnectionForTable(Tables::REMOTE_SCAN_PAGE);
        foreach (array_keys($urls) as $resultPageUid) {
            $owner = $owners[(int)$resultPageUid] ?? null;
            $resolved = is_array($owner) && $owner['pageUid'] > 0;
            $connection->update(Tables::REMOTE_SCAN_PAGE, [
                'owner_state' => $resolved ? self::STATE_RESOLVED : self::STATE_UNRESOLVED,
                'owner_page_uid' => $resolved ? $owner['pageUid'] : 0,
                'owner_language_uid' => $resolved ? $owner['languageUid'] : -1,
                'owner_translation_uid' => $resolved ? $owner['translationUid'] : 0,
            ], ['uid' => (int)$resultPageUid]);
        }
    }

    /**
     * @param array<int, string> $urls result page uid → URL
     * @return array<int, array{pageUid: int, languageUid: int, translationUid: int}> only resolved ones
     */
    public function resolve(Site $site, array $urls): array
    {
        $bases = [];
        foreach ($site->getLanguages() as $language) {
            $base = $language->getBase();
            $bases[] = [
                'languageUid' => $language->getLanguageId(),
                'host' => strtolower($base->getHost() !== '' ? $base->getHost() : $site->getBase()->getHost()),
                'path' => rtrim($base->getPath(), '/'),
            ];
        }

        $owners = [];
        $lookups = [];
        $idLookups = [];
        foreach ($urls as $resultPageUid => $url) {
            $parts = \Priebera\A11yQualityGate\Utility\UrlAuthority::parseUrl(trim($url));
            if (!is_array($parts) || !isset($parts['host'])) {
                continue;
            }
            $host = strtolower((string)$parts['host']);
            $path = '/' . ltrim(rawurldecode((string)($parts['path'] ?? '/')), '/');
            $match = null;
            foreach ($bases as $base) {
                // A base without host (TYPO3 allows "/") answers on every host.
                if ($base['host'] !== '' && $base['host'] !== $host) {
                    continue;
                }
                if ($base['path'] !== '' && $path !== $base['path'] && !str_starts_with($path, $base['path'] . '/')) {
                    continue;
                }
                if ($match === null || strlen($base['path']) > strlen($match['path'])) {
                    $match = $base;
                }
            }
            if ($match === null) {
                continue;
            }
            $languageUid = (int)$match['languageUid'];
            if (($parts['query'] ?? '') !== '') {
                parse_str((string)$parts['query'], $query);
                // Exactly `id`, as TYPO3 reads it: `ID=` or `Id=` route by path like any other parameter.
                if (array_key_exists('id', $query)) {
                    $id = $query['id'];
                    if (is_string($id) && preg_match('/^[1-9][0-9]{0,9}$/', $id) === 1) {
                        $idLookups[(int)$id][] = [$resultPageUid, $languageUid];
                    }
                    // A page alias or anything else TYPO3 might resolve is never guessed.
                    continue;
                }
            }
            $slug = substr($path, strlen($match['path']));
            $slug = (string)preg_replace('#/+#', '/', '/' . ltrim($slug, '/'));
            $slug = $slug === '/' ? '/' : rtrim($slug, '/');
            if ($slug === '/') {
                $owners[$resultPageUid] = ['pageUid' => (int)$site->getRootPageId(), 'languageUid' => $languageUid, 'translationUid' => 0];
                continue;
            }
            $lookups[$languageUid][$slug][] = $resultPageUid;
        }

        foreach ($idLookups as $pageUid => $targets) {
            if (!$this->isPageInSite($pageUid, $site) || !$this->isDefaultLanguagePage($pageUid)) {
                continue;
            }
            foreach ($targets as [$resultPageUid, $languageUid]) {
                $owners[$resultPageUid] = ['pageUid' => $pageUid, 'languageUid' => $languageUid, 'translationUid' => 0];
            }
        }

        foreach ($lookups as $languageUid => $slugs) {
            foreach (array_chunk(array_keys($slugs), self::CHUNK) as $chunk) {
                $variants = [];
                foreach ($chunk as $slug) {
                    $variants[] = (string)$slug;
                    $variants[] = $slug . '/';
                }
                foreach ($this->pagesBySlug($variants, (int)$languageUid) as $slug => $candidates) {
                    $inSite = array_values(array_filter($candidates, fn (array $candidate): bool => $this->isPageInSite($candidate['pageUid'], $site)));
                    // Exactly one page of this site, or nothing: an ambiguous slug has no owner.
                    if (count(array_unique(array_column($inSite, 'pageUid'))) !== 1) {
                        continue;
                    }
                    foreach ($slugs[$slug] ?? [] as $resultPageUid) {
                        $owners[$resultPageUid] = ['pageUid' => $inSite[0]['pageUid'], 'languageUid' => (int)$languageUid, 'translationUid' => $inSite[0]['translationUid']];
                    }
                }
            }
        }

        return $owners;
    }

    public function hasOwnerColumns(): bool
    {
        static $columns = null;
        if ($columns === null) {
            try {
                $columns = array_change_key_case($this->connectionPool->getConnectionForTable(Tables::REMOTE_SCAN_PAGE)->createSchemaManager()->listTableColumns(Tables::REMOTE_SCAN_PAGE));
            } catch (\Throwable) {
                $columns = [];
            }
        }

        return isset($columns['owner_state']);
    }

    /**
     * @param list<string> $slugs
     * @return array<string, list<array{pageUid: int, translationUid: int}>>
     */
    private function pagesBySlug(array $slugs, int $languageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('uid', 'l10n_parent', 'slug')
            ->from(Tables::PAGES)
            ->where(
                $queryBuilder->expr()->in('slug', $queryBuilder->createNamedParameter($slugs, Connection::PARAM_STR_ARRAY)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', 0),
            );
        if (($GLOBALS['TCA'][Tables::PAGES]['ctrl']['versioningWS'] ?? false)) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('t3ver_wsid', 0));
        }

        $pages = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            $slug = (string)$row['slug'];
            $slug = $slug === '/' ? '/' : rtrim($slug, '/');
            $pageUid = $languageUid > 0 ? (int)$row['l10n_parent'] : (int)$row['uid'];
            if ($pageUid > 0) {
                $pages[$slug][] = ['pageUid' => $pageUid, 'translationUid' => $languageUid > 0 ? (int)$row['uid'] : 0];
            }
        }

        return $pages;
    }

    private function isDefaultLanguagePage(int $pageUid): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder->count('uid')
            ->from(Tables::PAGES)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', 0),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchOne() === 1;
    }

    private function isPageInSite(int $pageUid, Site $site): bool
    {
        try {
            $pageSite = $this->siteResolutionService->resolveSiteByPageId($pageUid);
        } catch (\Throwable) {
            return false;
        }

        return $pageSite instanceof Site && $pageSite->getIdentifier() === $site->getIdentifier();
    }
}
