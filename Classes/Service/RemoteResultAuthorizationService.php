<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Which stored frontend (crawler) results of a scan the current backend user may see — one policy for the module
 * (Overview, page detail, CSV/PDF export, toolbar) and for agent access (MCP). Sol R01/R02/R05.
 *
 * Reading a scan (ScopeAccessService) does not open everything it found. For users other than administrators, with
 * the user's rights at the moment of the request:
 *
 *   - Result page: its owner (RemoteResultProvenanceService, resolved when the results were stored) must be known and
 *     readable — page show access within the web mounts — and its language allowed (allowed_languages, see below,
 *     the default language 0 included); a result in another language also needs read access to the page's current
 *     live translation record when one exists (IA-MCP-01). A translation recorded with the result only identifies the
 *     owner: when it is no longer a live translation of that page in that language (deleted, moved, re-parented,
 *     replaced) the result is withheld, and while it is, it must stay readable too.
 *     Results stored before owners were recorded, and results whose owner could not be resolved, are shown to
 *     administrators only: an unknown owner never defaults to readable.
 *   - Element (an HTML node of a finding): shown only when the crawler mapped it to a record and that record exists in
 *     the live workspace, its table is readable (tables_select), its page is readable and its language is allowed.
 *     Elements without a record mapping (template markup, plugin output, crawls without mapping markers) can come from
 *     any record the page renders — a storage folder or a table the user may not read — so they are withheld.
 *   - Finding: shown when its result page is, and either at least one of its elements is shown, or it is a page-level
 *     finding (no elements at all, e.g. the document title). Its occurrence count is the number of shown elements.
 *   - Everything derived — totals, impact breakdown, top issue types, page lists, failed pages, pagination, exports,
 *     toolbar counts — must be computed from this result, never from the raw scan.
 *
 * Language policy: TYPO3's allowed_languages restricts editing; AQG additionally treats it as a read restriction for
 * crawled results (as the Page module only shows the user's languages), a deliberately stricter rule. It applies to
 * every concrete language, the default language 0 included (S-01); -1 ("all languages") is never a concrete one.
 *
 * Administrators read every page and record natively: nothing is withheld. Rights are evaluated per request and never
 * cached across requests. The work is bounded; a scan beyond the bounds is refused instead of being checked partially.
 */
final class RemoteResultAuthorizationService
{
    public const MAX_RESULT_PAGES = 10000;
    public const MAX_FINDINGS = 50000;
    public const MAX_ELEMENTS = 200000;
    public const MAX_DISTINCT_PAGES = 5000;
    private const CHUNK = 500;

    /** @var array<int, bool> */
    private array $pageReadable = [];
    /** @var array<string, int> page:language → live translation uid (0 = none), per request */
    private array $currentTranslation = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ScopeAccessService $scopeAccessService,
        private readonly BackendUserService $backendUserService,
    ) {
    }

    /** Administrators see every stored result; nothing needs to be checked for them. */
    public function isUnrestrictedUser(): bool
    {
        $user = $this->backendUserService->getBackendUser();

        return $user instanceof BackendUserAuthentication && $user->isAdmin();
    }

    /**
     * @param array<string, mixed> $scan a scan the user may read (ScopeAccessService::canReadRemoteScan)
     * @throws RemoteResultsTooLargeException
     */
    public function authorize(array $scan): RemoteResultAuthorization
    {
        $this->pageReadable = [];
        $this->currentTranslation = [];
        $scanUid = (int)($scan['uid'] ?? 0);
        $user = $this->backendUserService->getBackendUser();
        $resultPages = $this->resultPages($scanUid);
        $findings = $this->findingRows($scanUid);
        if ($user instanceof BackendUserAuthentication && $user->isAdmin()) {
            $visible = [];
            foreach ($findings as $finding) {
                if (isset($resultPages[$finding['resultPage']])) {
                    $finding['occurrences'] = $finding['nodesCount'];
                    $visible[] = $finding;
                }
            }

            return new RemoteResultAuthorization(true, array_fill_keys(array_keys($resultPages), true), $visible, null);
        }
        if (!$user instanceof BackendUserAuthentication) {
            return new RemoteResultAuthorization(false, [], [], []);
        }

        $allowedPages = [];
        foreach ($resultPages as $resultPageUid => $owner) {
            if ($this->ownerReadable($user, $owner)) {
                $allowedPages[$resultPageUid] = true;
            }
        }
        $candidates = [];
        foreach ($findings as $finding) {
            if (isset($allowedPages[$finding['resultPage']])) {
                $candidates[$finding['uid']] = $finding;
            }
        }
        $ownerLanguageByFinding = [];
        foreach ($candidates as $findingUid => $finding) {
            $ownerLanguageByFinding[$findingUid] = $resultPages[$finding['resultPage']]['languageUid'];
        }
        [$shownElements, $storedByFinding] = $this->shownElements($user, $ownerLanguageByFinding);

        $visible = [];
        $shownByFinding = [];
        foreach ($shownElements as $elementUid => $findingUid) {
            $shownByFinding[$findingUid] = ($shownByFinding[$findingUid] ?? 0) + 1;
        }
        foreach ($candidates as $findingUid => $finding) {
            $stored = $storedByFinding[$findingUid] ?? 0;
            $shown = $shownByFinding[$findingUid] ?? 0;
            if ($shown === 0 && !($stored === 0 && $finding['nodesCount'] === 0)) {
                continue;
            }
            $finding['occurrences'] = $shown;
            $visible[] = $finding;
        }

        return new RemoteResultAuthorization(false, $allowedPages, $visible, array_fill_keys(array_keys($shownElements), true));
    }

    /**
     * @param array{state: int, pageUid: int, languageUid: int, translationUid: int} $owner
     */
    private function ownerReadable(BackendUserAuthentication $user, array $owner): bool
    {
        if ($owner['state'] !== RemoteResultProvenanceService::STATE_RESOLVED || $owner['pageUid'] <= 0 || !$this->canReadPage($owner['pageUid'])) {
            return false;
        }
        // A resolved owner always has the concrete site language of its URL. Every concrete language needs the user's
        // language access — the default language 0 too (S-01); a resolved owner without one is inconsistent.
        if ($owner['languageUid'] < 0 || !$user->checkLanguageAccess($owner['languageUid'])) {
            return false;
        }
        if ($owner['languageUid'] > 0) {
            // The translation record has its own permissions, and the one that applies is the page's translation as it
            // is now (ScopeAccessService::canReadPageInLanguage(), IA-MCP-01). The translation recorded with the result
            // (when its slug was translated) only identifies the owner: it still has to be a live translation of that
            // page in that language — not deleted, moved to another page or language — and stays readable itself,
            // or the result is withheld. Without a recorded one (language root, ?id= in a language) only the
            // current translation decides; a page without a translation in the language is the owner page itself.
            $currentTranslationUid = $this->currentTranslationUid($owner['pageUid'], $owner['languageUid']);
            // The current translation is live by definition; any other recorded one is checked on its own.
            if ($owner['translationUid'] > 0 && $owner['translationUid'] !== $currentTranslationUid
                && (!$this->isLiveTranslationOf($owner['translationUid'], $owner['pageUid'], $owner['languageUid'])
                    || !$this->canReadPage($owner['translationUid']))) {
                return false;
            }
            if ($currentTranslationUid > 0 && !$this->canReadPage($currentTranslationUid)) {
                return false;
            }
        }

        return true;
    }

    private function currentTranslationUid(int $pageUid, int $languageUid): int
    {
        return $this->currentTranslation[$pageUid . ':' . $languageUid] ??= $this->scopeAccessService->liveTranslationUid($pageUid, $languageUid);
    }

    /** Whether `$translationUid` is currently a live (default workspace, not deleted) translation of the page in the language. */
    private function isLiveTranslationOf(int $translationUid, int $pageUid, int $languageUid): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->count('uid')
            ->from(Tables::PAGES)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($translationUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', 0),
            );
        if (($GLOBALS['TCA'][Tables::PAGES]['ctrl']['versioningWS'] ?? false)) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('t3ver_wsid', 0));
        }

        return (int)$queryBuilder->executeQuery()->fetchOne() === 1;
    }

    /**
     * @return array<int, array{state: int, pageUid: int, languageUid: int, translationUid: int}>
     */
    private function resultPages(int $scanUid): array
    {
        $withOwner = $this->hasOwnerColumns();
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(Tables::REMOTE_SCAN_PAGE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('uid')
            ->from(Tables::REMOTE_SCAN_PAGE)
            ->where(
                $queryBuilder->expr()->eq('remote_scan', $queryBuilder->createNamedParameter($scanUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->setMaxResults(self::MAX_RESULT_PAGES + 1);
        if ($withOwner) {
            $queryBuilder->addSelect('owner_state', 'owner_page_uid', 'owner_language_uid', 'owner_translation_uid');
        }
        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        if (count($rows) > self::MAX_RESULT_PAGES) {
            throw new RemoteResultsTooLargeException();
        }

        $pages = [];
        foreach ($rows as $row) {
            $pages[(int)$row['uid']] = [
                'state' => (int)($row['owner_state'] ?? RemoteResultProvenanceService::STATE_LEGACY),
                'pageUid' => (int)($row['owner_page_uid'] ?? 0),
                'languageUid' => (int)($row['owner_language_uid'] ?? -1),
                'translationUid' => (int)($row['owner_translation_uid'] ?? 0),
            ];
        }

        return $pages;
    }

    /**
     * @return list<array{uid: int, ruleId: string, impact: string, nodesCount: int, resultPage: int}>
     */
    private function findingRows(int $scanUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(Tables::REMOTE_ISSUE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder->select('uid', 'rule_id', 'impact', 'nodes_count', 'remote_scan_page')
            ->from(Tables::REMOTE_ISSUE)
            ->where(
                $queryBuilder->expr()->eq('remote_scan', $queryBuilder->createNamedParameter($scanUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->setMaxResults(self::MAX_FINDINGS + 1)
            ->executeQuery()
            ->fetchAllAssociative();
        if (count($rows) > self::MAX_FINDINGS) {
            throw new RemoteResultsTooLargeException();
        }

        return array_map(static fn (array $row): array => [
            'uid' => (int)$row['uid'],
            'ruleId' => (string)$row['rule_id'],
            'impact' => (string)$row['impact'],
            'nodesCount' => max(0, (int)$row['nodes_count']),
            'resultPage' => (int)$row['remote_scan_page'],
        ], $rows);
    }

    /**
     * Elements of the candidate findings that may be shown, and how many elements each finding has stored.
     *
     * @param array<int, int> $ownerLanguageByFinding finding uid → language of its (readable) result page
     * @return array{0: array<int, int>, 1: array<int, int>} element uid → finding uid; finding uid → stored elements
     */
    private function shownElements(BackendUserAuthentication $user, array $ownerLanguageByFinding): array
    {
        $findingUids = array_keys($ownerLanguageByFinding);
        $rows = [];
        foreach (array_chunk($findingUids, self::CHUNK) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(Tables::REMOTE_ISSUE_NODE);
            $queryBuilder->getRestrictions()->removeAll();
            foreach ($queryBuilder->select('uid', 'remote_issue', 'mapped_table', 'mapped_uid')
                ->from(Tables::REMOTE_ISSUE_NODE)
                ->where(
                    $queryBuilder->expr()->in('remote_issue', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('deleted', 0),
                )
                ->executeQuery()
                ->fetchAllAssociative() as $row) {
                $rows[] = $row;
                if (count($rows) > self::MAX_ELEMENTS) {
                    throw new RemoteResultsTooLargeException();
                }
            }
        }

        $records = [];
        $stored = [];
        foreach ($rows as $row) {
            $stored[(int)$row['remote_issue']] = ($stored[(int)$row['remote_issue']] ?? 0) + 1;
            $table = trim((string)$row['mapped_table']);
            $uid = (int)$row['mapped_uid'];
            if ($table !== '' && $uid > 0) {
                $records[$table][$uid][$ownerLanguageByFinding[(int)$row['remote_issue']] ?? 0] = true;
            }
        }
        $readable = $this->readableRecords($user, $records);

        $shown = [];
        foreach ($rows as $row) {
            $table = trim((string)$row['mapped_table']);
            $language = $ownerLanguageByFinding[(int)$row['remote_issue']] ?? 0;
            if ($table !== '' && ($readable[$table][(int)$row['mapped_uid']][$language] ?? false)) {
                $shown[(int)$row['uid']] = (int)$row['remote_issue'];
            }
        }

        return [$shown, $stored];
    }

    /**
     * The record-read policy: table in TCA and readable (tables_select), record present in the live workspace, its page
     * readable (the page itself for `pages`), and its language allowed. Records on page 0 are for administrators.
     *
     * Decided per result-page language: TYPO3's language overlay renders a translated record under its default-language
     * uid (connected/strict translations), so the crawler maps it to the default row. On a result page in language L > 0,
     * a mapped default-language row with a live translation in L is decided by that translation (its language, page,
     * existence); without one, the default row itself is what was rendered and is decided as it is (language 0).
     *
     * @param array<string, array<int, array<int, true>>> $records table → uid → result-page languages
     * @return array<string, array<int, array<int, bool>>> table → uid → result-page language → readable
     */
    private function readableRecords(BackendUserAuthentication $user, array $records): array
    {
        $readable = [];
        foreach ($records as $table => $uidLanguages) {
            $uids = array_keys($uidLanguages);
            foreach ($uidLanguages as $uid => $languages) {
                $readable[$table][$uid] = array_fill_keys(array_keys($languages), false);
            }
            $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? null;
            if (!is_array($ctrl) || !$user->check('tables_select', $table)) {
                continue;
            }
            $current = $this->currentRecordReadability($user, $table, $ctrl, $uids);
            $parentField = (string)($ctrl['transOrigPointerField'] ?? '');
            $languageField = (string)($ctrl['languageField'] ?? '');
            $translations = [];
            if ($parentField !== '' && $languageField !== '') {
                $byLanguage = [];
                foreach ($uidLanguages as $uid => $languages) {
                    if (($current[$uid]['language'] ?? null) !== 0) {
                        continue;
                    }
                    foreach (array_keys($languages) as $language) {
                        if ($language > 0) {
                            $byLanguage[$language][] = $uid;
                        }
                    }
                }
                foreach ($byLanguage as $language => $parentUids) {
                    $translations[$language] = $this->liveTranslations($user, $table, $ctrl, $parentUids, (int)$language);
                }
            }
            foreach ($uidLanguages as $uid => $languages) {
                foreach (array_keys($languages) as $language) {
                    $translation = $translations[$language][$uid] ?? null;
                    $readable[$table][$uid][$language] = $translation !== null
                        ? $translation
                        : (bool)($current[$uid]['readable'] ?? false);
                }
            }
        }

        return $readable;
    }

    /**
     * Current readability of live rows by uid, with their language (null for a table without language field).
     *
     * @param array<string, mixed> $ctrl
     * @param list<int> $uids
     * @return array<int, array{readable: bool, language: int|null}>
     */
    private function currentRecordReadability(BackendUserAuthentication $user, string $table, array $ctrl, array $uids, string $parentField = '', int $translationLanguage = -1): array
    {
        $current = [];
        $deleteField = (string)($ctrl['delete'] ?? '');
        $languageField = (string)($ctrl['languageField'] ?? '');
        $versioned = (bool)($ctrl['versioningWS'] ?? false);
        foreach (array_chunk($uids, self::CHUNK) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder->select('uid', 'pid')
                ->from($table)
                ->where($parentField !== ''
                    ? $queryBuilder->expr()->in($parentField, $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY))
                    : $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)));
            if ($parentField !== '') {
                $queryBuilder->addSelect($parentField)
                    ->andWhere($queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($translationLanguage, Connection::PARAM_INT)))
                    ->orderBy('uid');
            }
            if ($languageField !== '') {
                $queryBuilder->addSelect($languageField);
            }
            if ($deleteField !== '') {
                $queryBuilder->andWhere($queryBuilder->expr()->eq($deleteField, 0));
            }
            if ($versioned) {
                $queryBuilder->andWhere($queryBuilder->expr()->eq('t3ver_wsid', 0));
            }
            foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
                $key = $parentField !== '' ? (int)$row[$parentField] : (int)$row['uid'];
                if (isset($current[$key])) {
                    continue;
                }
                $pageUid = $table === Tables::PAGES ? (int)$row['uid'] : (int)$row['pid'];
                $language = $languageField !== '' ? (int)($row[$languageField] ?? 0) : null;
                // The record's current language, the default language 0 included (S-01). -1 ("all languages")
                // passes TYPO3's own check; a table without a language field is not language-bound.
                $current[$key] = [
                    'readable' => $pageUid > 0
                        && $this->canReadPage($pageUid)
                        && ($language === null || $user->checkLanguageAccess($language)),
                    'language' => $language,
                ];
            }
        }

        return $current;
    }

    /**
     * Readability of the live translations in `$language` of default-language rows, by default uid; rows without a
     * live translation are absent.
     *
     * @param array<string, mixed> $ctrl
     * @param list<int> $parentUids
     * @return array<int, bool>
     */
    private function liveTranslations(BackendUserAuthentication $user, string $table, array $ctrl, array $parentUids, int $language): array
    {
        return array_map(
            static fn (array $state): bool => $state['readable'],
            $this->currentRecordReadability($user, $table, $ctrl, $parentUids, (string)$ctrl['transOrigPointerField'], $language)
        );
    }

    private function canReadPage(int $pageUid): bool
    {
        if (!isset($this->pageReadable[$pageUid])) {
            if (count($this->pageReadable) >= self::MAX_DISTINCT_PAGES) {
                throw new RemoteResultsTooLargeException();
            }
            $this->pageReadable[$pageUid] = $this->scopeAccessService->canReadPage($pageUid);
        }

        return $this->pageReadable[$pageUid];
    }

    private function hasOwnerColumns(): bool
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
}
