<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Contract\LocalFindingSourceAccessInterface;
use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Current read access to the source record of a stored local finding (`tx_a11y_issue`).
 *
 * A finding is stored with the page it was found on, but it shows content of its source record (snippet, context
 * path, field). That historical page attribution is no authority: a non-administrator sees a finding only while
 * its source record exists, is live, lies in a table the user may read (`tables_select`), in a language the user
 * may use, on a page the user may show (web mount + page permissions, translations by their own row). A finding
 * whose source is unknown (no table, no uid, a table without TCA) is withheld. Administrators are unrestricted.
 *
 * Page findings (S-02): a rendered-HTML finding (`__rendered_html`) of a default-language page row is stored with the
 * language it was rendered in (`source_lang_uid`); that language and the page's live translation in it decide. Any
 * other finding of a default-language page row must be stored with language 0. A finding whose source row is
 * itself a page translation is decided by that row's CURRENT language: the row needs the user's language access, and
 * a stored `source_lang_uid` other than the row's language is inconsistent provenance and withheld — historical
 * finding metadata never replaces the current language of a translated source row.
 *
 * Every native reader that renders or counts findings for a person (Overview, Page Detail, exports, toolbar, Page
 * module, notifications, AI queue entry points) applies the same set through {@see constraint()} so that lists,
 * counts and files agree. Gate checks (publish/workspace quality gate) deliberately stay unfiltered.
 */
final class LocalFindingSourceAccessService implements LocalFindingSourceAccessInterface
{
    private const CHUNK = 1000;
    /** The field of findings of a page's rendered HTML, stored with the default page and the rendered language. */
    private const RENDERED_FIELD = '__rendered_html';

    /** @var array<int, bool> page readability within one evaluation */
    private array $readablePages = [];

    /**
     * Evaluations reused only within one backend request (same request object and same user object): a permission
     * change applies to the next request, and callers without a request object always get a fresh evaluation.
     *
     * @var \WeakMap<object, array<string, array<string, mixed>>>|null
     */
    private ?\WeakMap $requestCache = null;

    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function isUnrestricted(): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();

        return $backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin();
    }

    /**
     * SQL condition limiting `tx_a11y_issue` rows (alias `$issueAlias`, '' = unqualified) to readable sources.
     * '' = no restriction (administrator); "1=0" without a backend user or without any readable source.
     */
    public function constraint(QueryBuilder $qb, string $issueAlias, string $siteIdentifier): string
    {
        $readable = $this->readableSources($siteIdentifier);
        if ($readable === null) {
            return '';
        }

        if ($readable['languages'] === [] || $readable['sources'] === []) {
            return '1=0';
        }

        $prefix = $issueAlias !== '' ? $issueAlias . '.' : '';
        // Integer lists are written as literals (every value is cast to int): a site with many source records must
        // not exceed the database's bound-parameter limit (65,535 placeholders on MySQL).
        $intList = static fn (array $values): string => implode(',', array_map('intval', $values));
        $perTable = [];
        foreach ($readable['sources'] as $table => $uids) {
            $perTable[] = $qb->expr()->and(
                $qb->expr()->eq($prefix . 'source_table', $qb->createNamedParameter($table)),
                $qb->expr()->in($prefix . 'source_uid', $intList($uids)),
            );
        }

        $parts = [
            $qb->expr()->in($prefix . 'source_lang_uid', $intList($readable['languages'])),
            $qb->expr()->or(...$perTable),
        ];
        // A page translation has its own page permissions: findings of page P in language L (rendered checks store
        // the default page with the language) need the live translation of P in L to be readable when it exists.
        foreach ($readable['deniedPageLanguages'] as $languageUid => $pageUids) {
            $parts[] = 'NOT (' . $qb->expr()->and(
                $qb->expr()->eq($prefix . 'source_table', $qb->createNamedParameter(Tables::PAGES)),
                $qb->expr()->eq($prefix . 'source_lang_uid', (int)$languageUid),
                $qb->expr()->in($prefix . 'source_uid', $intList($pageUids)),
            ) . ')';
        }
        // S-02: a translated page row only carries findings stored in its own current language.
        $translatedUids = [];
        foreach ($readable['translatedPages'] as $languageUid => $pageUids) {
            $translatedUids = array_merge($translatedUids, $pageUids);
            $parts[] = 'NOT (' . $qb->expr()->and(
                $qb->expr()->eq($prefix . 'source_table', $qb->createNamedParameter(Tables::PAGES)),
                $qb->expr()->neq($prefix . 'source_lang_uid', (int)$languageUid),
                $qb->expr()->in($prefix . 'source_uid', $intList($pageUids)),
            ) . ')';
        }
        // A default-language page row carries another language only as a rendered-HTML finding of the page in that
        // language; a record-field finding stored with another language, or a page finding of "all languages" (-1), is
        // inconsistent provenance (isReadableIn() applies the same rule).
        $inconsistent = [
            $qb->expr()->lt($prefix . 'source_lang_uid', 0),
            $qb->expr()->and(
                $qb->expr()->neq($prefix . 'source_lang_uid', 0),
                $qb->expr()->neq($prefix . 'source_field', $qb->createNamedParameter(self::RENDERED_FIELD)),
                ...($translatedUids !== [] ? [$qb->expr()->notIn($prefix . 'source_uid', $intList($translatedUids))] : []),
            ),
        ];
        $parts[] = 'NOT (' . $qb->expr()->and(
            $qb->expr()->eq($prefix . 'source_table', $qb->createNamedParameter(Tables::PAGES)),
            $qb->expr()->or(...$inconsistent),
        ) . ')';

        return (string)$qb->expr()->and(...$parts);
    }

    /**
     * Current read access to one record (table, existence, live version, language, page), the same predicate as for
     * finding sources. Administrators: any existing record.
     */
    public function canReadRecord(string $table, int $uid): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication || $table === '' || $uid <= 0) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        $this->readablePages = [];

        // A single record has no rendered-language context: its own current language decides, a default-language
        // page row included.
        return $this->readableRecordUids($backendUser, $table, [$uid], false)['uids'] === [$uid];
    }

    /** @param array<string, mixed> $issue a `tx_a11y_issue` row */
    public function canReadFinding(array $issue): bool
    {
        $readable = $this->readableSources((string)($issue['site_identifier'] ?? ''));
        if ($readable === null) {
            return true;
        }

        return self::isReadableIn($readable, $issue);
    }

    /**
     * Keeps the findings whose source the user may read; one evaluation per site of the list.
     *
     * @param list<array<string, mixed>> $issues
     * @return list<array<string, mixed>>
     */
    public function filter(array $issues): array
    {
        $bySite = [];
        $kept = [];
        foreach ($issues as $issue) {
            $site = (string)($issue['site_identifier'] ?? '');
            if (!array_key_exists($site, $bySite)) {
                $bySite[$site] = $this->readableSources($site);
            }
            if ($bySite[$site] === null || self::isReadableIn($bySite[$site], $issue)) {
                $kept[] = $issue;
            }
        }

        return $kept;
    }

    /**
     * @param array{languages: list<int>, sources: array<string, list<int>>, deniedPageLanguages: array<int, list<int>>, translatedPages: array<int, list<int>>} $readable
     * @param array<string, mixed> $issue
     */
    private static function isReadableIn(array $readable, array $issue): bool
    {
        $table = (string)($issue['source_table'] ?? '');
        $uid = (int)($issue['source_uid'] ?? 0);
        $languageUid = (int)($issue['source_lang_uid'] ?? 0);

        if ($table === Tables::PAGES) {
            $translated = false;
            foreach ($readable['translatedPages'] as $rowLanguageUid => $pageUids) {
                if (in_array($uid, $pageUids, true)) {
                    if ($rowLanguageUid !== $languageUid) {
                        return false;
                    }
                    $translated = true;
                }
            }
            if ($languageUid < 0
                || (!$translated && $languageUid !== 0 && (string)($issue['source_field'] ?? '') !== self::RENDERED_FIELD)) {
                return false;
            }
        }

        return in_array($languageUid, $readable['languages'], true)
            && in_array($uid, $readable['sources'][$table] ?? [], true)
            && !($table === Tables::PAGES && in_array($uid, $readable['deniedPageLanguages'][$languageUid] ?? [], true));
    }

    /**
     * @return array{languages: list<int>, sources: array<string, list<int>>, deniedPageLanguages: array<int, list<int>>, translatedPages: array<int, list<int>>}|null
     *         null = unrestricted
     */
    private function readableSources(string $siteIdentifier): ?array
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if ($backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin()) {
            return null;
        }
        if (!$backendUser instanceof BackendUserAuthentication) {
            return ['languages' => [], 'sources' => [], 'deniedPageLanguages' => [], 'translatedPages' => []];
        }

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $cacheKey = spl_object_id($backendUser) . ':' . (int)($backendUser->workspace ?? 0) . ':' . $siteIdentifier;
        if (is_object($request)) {
            $this->requestCache ??= new \WeakMap();
            if (isset($this->requestCache[$request][$cacheKey])
                && ($this->requestCache[$request][$cacheKey]['user'] ?? null) === $backendUser) {
                return $this->requestCache[$request][$cacheKey]['value'];
            }
        }

        $this->readablePages = [];
        $candidates = $this->candidateSources($siteIdentifier);
        $languages = array_values(array_filter(
            $candidates['languages'],
            static fn (int $languageUid): bool => $backendUser->checkLanguageAccess($languageUid),
        ));

        $sources = [];
        $translatedPages = [];
        foreach ($candidates['sources'] as $table => $uids) {
            $result = $this->readableRecordUids($backendUser, $table, $uids, true);
            if ($result['uids'] !== []) {
                $sources[$table] = $result['uids'];
            }
            if ($table === Tables::PAGES) {
                $translatedPages = $result['translatedPages'];
            }
        }

        $deniedPageLanguages = [];
        $translatedPageUids = array_fill_keys(array_merge([], ...array_values($translatedPages)), true);
        foreach ($candidates['pageLanguages'] as $pageUid => $pageLanguages) {
            // Only a default-language row has translations of its own; a translated row is decided by its language.
            if (!in_array($pageUid, $sources[Tables::PAGES] ?? [], true) || isset($translatedPageUids[$pageUid])) {
                continue;
            }
            foreach ($pageLanguages as $languageUid) {
                $translationUid = $this->liveTranslationUid($pageUid, $languageUid);
                if ($translationUid > 0 && !$this->canShowPage($backendUser, $translationUid)) {
                    $deniedPageLanguages[$languageUid][] = $pageUid;
                }
            }
        }

        $value = ['languages' => $languages, 'sources' => $sources, 'deniedPageLanguages' => $deniedPageLanguages, 'translatedPages' => $translatedPages];
        if (is_object($request) && $this->requestCache !== null) {
            $entries = $this->requestCache[$request] ?? [];
            $entries[$cacheKey] = ['user' => $backendUser, 'value' => $value];
            $this->requestCache[$request] = $entries;
        }

        return $value;
    }

    private function liveTranslationUid(int $pageUid, int $languageUid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);
        $qb->getRestrictions()->removeAll();

        return (int)$qb->select('uid')
            ->from(Tables::PAGES)
            ->where(
                $qb->expr()->eq('l10n_parent', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('t3ver_wsid', 0),
            )
            ->orderBy('uid')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    /** @return array{languages: list<int>, sources: array<string, list<int>>, pageLanguages: array<int, list<int>>} */
    private function candidateSources(string $siteIdentifier): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();
        $qb->select('source_table', 'source_uid', 'source_lang_uid')
            ->distinct()
            ->from(Tables::ISSUE)
            ->where(
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->gt('source_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->neq('source_table', $qb->createNamedParameter('')),
            );
        if ($siteIdentifier !== '') {
            $qb->andWhere($qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)));
        }

        $languages = [];
        $sources = [];
        $pageLanguages = [];
        foreach ($qb->executeQuery()->iterateAssociative() as $row) {
            $languages[(int)$row['source_lang_uid']] = true;
            $sources[(string)$row['source_table']][(int)$row['source_uid']] = true;
            if ((string)$row['source_table'] === Tables::PAGES && (int)$row['source_lang_uid'] > 0) {
                $pageLanguages[(int)$row['source_uid']][(int)$row['source_lang_uid']] = true;
            }
        }

        return [
            'languages' => array_map('intval', array_keys($languages)),
            'sources' => array_map(static fn (array $uids): array => array_map('intval', array_keys($uids)), $sources),
            'pageLanguages' => array_map(static fn (array $langs): array => array_map('intval', array_keys($langs)), $pageLanguages),
        ];
    }

    /**
     * Readable records among `$uids`, by their CURRENT row. For `pages` as finding sources ($findingSources), a
     * default-language row's language is decided per finding (source_lang_uid, constraint()); a translated row needs
     * its own current language, and is returned in `translatedPages` (row language => uids) so that findings stored
     * with another language are withheld (S-02). A page row whose language/translation parent is inconsistent (a
     * translation without a parent, a negative page language) is never readable.
     *
     * @param list<int> $uids
     * @return array{uids: list<int>, translatedPages: array<int, list<int>>}
     */
    private function readableRecordUids(BackendUserAuthentication $backendUser, string $table, array $uids, bool $findingSources): array
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? null;
        if (!is_array($ctrl) || !$backendUser->check('tables_select', $table)) {
            return ['uids' => [], 'translatedPages' => []];
        }

        $deleteField = (string)($ctrl['delete'] ?? '');
        $languageField = (string)($ctrl['languageField'] ?? '');
        $parentField = (string)($ctrl['transOrigPointerField'] ?? '');
        $versioned = (bool)($ctrl['versioningWS'] ?? false);

        $readable = [];
        $translatedPages = [];
        foreach (array_chunk($uids, self::CHUNK) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $qb->getRestrictions()->removeAll();
            $qb->select('*')
                ->from($table)
                ->where($qb->expr()->in('uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)));
            foreach ($qb->executeQuery()->iterateAssociative() as $record) {
                if ($deleteField !== '' && (int)($record[$deleteField] ?? 0) !== 0) {
                    continue;
                }
                if ($versioned && ((int)($record['t3ver_wsid'] ?? 0) !== 0 || (int)($record['t3ver_oid'] ?? 0) !== 0)) {
                    continue;
                }
                $rowLanguageUid = $languageField !== '' ? (int)($record[$languageField] ?? 0) : 0;
                if ($table === Tables::PAGES && $languageField !== '') {
                    if ($rowLanguageUid < 0 || ($rowLanguageUid > 0 && $parentField !== '' && (int)($record[$parentField] ?? 0) <= 0)) {
                        // Inconsistent page provenance: fail closed.
                        continue;
                    }
                    if ($rowLanguageUid > 0) {
                        // A translated page row: its current language decides, never a finding's stored language (S-02).
                        if (!$backendUser->checkLanguageAccess($rowLanguageUid)) {
                            continue;
                        }
                    } elseif (!$findingSources && !$backendUser->checkLanguageAccess(0)) {
                        // A default-language page row as a record of its own (no rendered-language context).
                        continue;
                    }
                    // A default-language row as a finding source: the finding's rendered language and the page's live
                    // translation in it decide (constraint()), not the default row's language 0.
                } elseif ($languageField !== '' && !$backendUser->checkLanguageAccess($rowLanguageUid)) {
                    continue;
                }
                $pageUid = $table === Tables::PAGES ? (int)$record['uid'] : (int)($record['pid'] ?? 0);
                if (!$this->canShowPage($backendUser, $pageUid)) {
                    continue;
                }
                $readable[] = (int)$record['uid'];
                if ($table === Tables::PAGES && $rowLanguageUid > 0) {
                    $translatedPages[$rowLanguageUid][] = (int)$record['uid'];
                }
            }
        }
        sort($readable);
        foreach ($translatedPages as $languageUid => $pageUids) {
            sort($pageUids);
            $translatedPages[$languageUid] = $pageUids;
        }

        return ['uids' => $readable, 'translatedPages' => $translatedPages];
    }

    private function canShowPage(BackendUserAuthentication $backendUser, int $pageUid): bool
    {
        if ($pageUid <= 0) {
            return false;
        }

        return $this->readablePages[$pageUid] ??= (static function () use ($backendUser, $pageUid): bool {
            $page = BackendUtility::readPageAccess($pageUid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));

            return is_array($page) && $page !== [];
        })();
    }
}
