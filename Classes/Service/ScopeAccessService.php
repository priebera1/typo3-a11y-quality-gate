<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resource-level authorization for AQG results.
 *
 * Module access only opens the AQG screens. Every result belongs to a TYPO3 page or site, so reading it
 * needs access to that page (page scans, page findings) or to the site root (site scans, site-wide
 * lists). Acting on it — scanning, cancelling, exporting — needs edit access to the same page or root.
 * A site, scan or page identifier sent by the browser is only ever checked against these rules, never
 * trusted on its own.
 */
final class ScopeAccessService
{
    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly BackendRecordAccessService $backendRecordAccessService,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly RemoteScanRepository $remoteScanRepository,
    ) {
    }

    public function canReadPage(int $pageUid): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication || $pageUid <= 0) {
            return false;
        }

        $page = BackendUtility::readPageAccess($pageUid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));

        return is_array($page) && $page !== [];
    }

    /**
     * SQL condition on the `pages` table for the pages the current user may show, for lists that aggregate a whole
     * site (Overview page list, its counts and totals). Empty for administrators (no restriction); "1=0" without a
     * backend user. A site-wide list already requires show access to the site root (canReadSite()), so the root's
     * web mount covers every page of the site and the page permissions are what is left to apply per page.
     */
    public function readablePagesClause(): string
    {
        return $this->pagesClause(Permission::PAGE_SHOW);
    }

    /** The same for site-wide writes to findings (Ignore a rule on the site): pages whose content the user may edit. */
    public function contentEditablePagesClause(): string
    {
        return $this->pagesClause(Permission::CONTENT_EDIT);
    }

    private function pagesClause(int $permission): string
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return '1=0';
        }
        if ($backendUser->isAdmin()) {
            return '';
        }

        return trim($backendUser->getPagePermsClause($permission));
    }

    public function canEditPage(int $pageUid): bool
    {
        return $pageUid > 0 && $this->backendRecordAccessService->canEditRecord(Tables::PAGES, $pageUid);
    }

    public function canReadSite(?Site $site): bool
    {
        return $site instanceof Site && $this->canReadPage((int)$site->getRootPageId());
    }

    public function canEditSite(?Site $site): bool
    {
        return $site instanceof Site && $this->canEditPage((int)$site->getRootPageId());
    }

    public function canReadSiteIdentifier(string $siteIdentifier): bool
    {
        return $this->canReadSite($this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier));
    }

    /**
     * Whether the user may read at least one page of the site: the site root, or a web mount that lies inside the site
     * (N-09). Only for lists whose every row is checked against its own page afterwards (AI fixes queue, change log);
     * site-level results still need canReadSite().
     */
    public function canReadWithinSiteIdentifier(string $siteIdentifier): bool
    {
        $site = $siteIdentifier !== '' ? $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier) : null;
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$site instanceof Site || !$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        if ($backendUser->isAdmin() || $this->canReadSite($site)) {
            return true;
        }
        foreach ($backendUser->getWebmounts() as $mount) {
            $mount = (int)$mount;
            if ($mount > 0 && $this->isPageInSite($mount, $site) && $this->canReadPage($mount)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the page is part of the given site. Nested site roots resolve to the innermost site, the
     * same way TYPO3 routes the page.
     */
    public function isPageInSite(int $pageUid, ?Site $site): bool
    {
        if (!$site instanceof Site || $pageUid <= 0) {
            return false;
        }

        $pageSite = $this->siteResolutionService->resolveSiteByPageId($pageUid);

        return $pageSite instanceof Site && $pageSite->getIdentifier() === $site->getIdentifier();
    }

    /**
     * @param array<string, mixed> $remoteScan
     */
    public function canReadRemoteScan(array $remoteScan): bool
    {
        return $this->checkRemoteScan($remoteScan, false);
    }

    /**
     * @param array<string, mixed> $remoteScan
     */
    public function canEditRemoteScan(array $remoteScan): bool
    {
        return $this->checkRemoteScan($remoteScan, true);
    }

    /**
     * The stored scan the remote page belongs to, or null when the page does not exist or the user may
     * not read it.
     *
     * @param array<string, mixed> $remotePage
     * @return array<string, mixed>|null
     */
    public function resolveReadableScanForRemotePage(array $remotePage): ?array
    {
        $scanUid = (int)($remotePage['remote_scan'] ?? 0);
        $remoteScan = $scanUid > 0 ? $this->remoteScanRepository->findScanByUid($scanUid) : null;

        return is_array($remoteScan) && $this->canReadRemoteScan($remoteScan) ? $remoteScan : null;
    }

    /**
     * A stored scan identified by its job ID that belongs to the given site and may be read.
     *
     * @return array<string, mixed>|null
     */
    public function resolveReadableScanByJobId(string $jobId, string $siteIdentifier): ?array
    {
        $jobId = trim($jobId);
        if ($jobId === '' || $siteIdentifier === '') {
            return null;
        }

        $remoteScan = $this->remoteScanRepository->findScanByJobId($jobId);
        if (!is_array($remoteScan) || (string)($remoteScan['site_identifier'] ?? '') !== $siteIdentifier) {
            return null;
        }

        return $this->canReadRemoteScan($remoteScan) ? $remoteScan : null;
    }

    /**
     * The installation-wide content scan status as this user may see it. Everyone needs to know that a
     * scan is running — the scan buttons are disabled meanwhile — but which page it covers, who started it
     * and what it found belongs to that page's scope.
     *
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    public function restrictLocalScanStatus(array $status): array
    {
        $generic = array_intersect_key($status, array_flip([
            'running',
            'startedAt',
            'finishedAt',
            'cancelRequested',
            'cancelled',
            'interrupted',
        ]));
        $backendUser = $this->backendUserService->getBackendUser();
        if ($backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin()) {
            return $status;
        }
        // Nothing beyond the generic state is known (no scan yet): nothing to withhold.
        $describesAScan = (int)($status['pageUid'] ?? 0) > 0
            || (int)($status['rootPid'] ?? 0) > 0
            || (int)($status['scanUid'] ?? 0) > 0
            || is_array($status['summary'] ?? null)
            || (string)($status['triggeredBy'] ?? '') !== '';
        if (!$describesAScan) {
            return $status;
        }
        if (!$this->canReadLocalScanStatus($status)) {
            return $generic + ['restricted' => true];
        }
        $pageUid = (int)($status['pageUid'] ?? 0);
        if (!is_array($status['summary'] ?? null)) {
            return $status;
        }

        // AUTH-02: a scan's counters add up findings of records (and, for a site scan, pages and languages) the viewer may
        // not read, a site scan's warnings name single pages. The user who started it sees the counters of the scan they
        // were admitted to; others get them withheld (null: no number is claimed). Warnings of a site scan are kept only for
        // pages the user may read in the warning's language.
        $summary = $status['summary'];
        $languageUid = (int)($status['languageUid'] ?? -1);
        $startedByViewer = (string)($status['triggeredBy'] ?? '') !== ''
            && $backendUser instanceof BackendUserAuthentication
            && (string)($status['triggeredBy'] ?? '') === (string)($backendUser->user['username'] ?? '');
        if (!$startedByViewer) {
            foreach (['issuesNew', 'issuesResolved', 'issuesIgnored', 'pagesScanned', 'recordsScanned'] as $counter) {
                if (array_key_exists($counter, $summary)) {
                    $summary[$counter] = null;
                }
            }
            $summary['countsWithheld'] = true;
            unset($status['triggeredBy']);
        }
        if ($pageUid <= 0 && is_array($summary['warnings'] ?? null)) {
            $summary['warnings'] = array_values(array_filter($summary['warnings'], function (mixed $warning) use ($languageUid): bool {
                $context = is_array($warning) && is_array($warning['context'] ?? null) ? $warning['context'] : [];
                $warningPageUid = (int)($context['pageUid'] ?? 0);
                if ($warningPageUid <= 0) {
                    return false;
                }
                $warningLanguageUid = array_key_exists('languageUid', $context) ? (int)$context['languageUid'] : $languageUid;

                return $this->canReadPageResultsInLanguage($warningPageUid, $warningLanguageUid);
            }));
        }
        $status['summary'] = $summary;

        return $status;
    }

    /**
     * AUTH-02: who may see which page or site, language, actor and result a content scan status describes. A page scan:
     * that page's results in the scan's language (canReadPageResultsInLanguage()). A site scan: show access to its root
     * and the scan's language (canUseScanLanguage()). A status without a page or root (an unknown scope) only for
     * administrators.
     *
     * @param array<string, mixed> $status
     */
    public function canReadLocalScanStatus(array $status): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        $pageUid = (int)($status['pageUid'] ?? 0);
        $rootPid = (int)($status['rootPid'] ?? 0);
        $languageUid = (int)($status['languageUid'] ?? -1);
        if ($pageUid > 0) {
            return $this->canReadPageResultsInLanguage($pageUid, $languageUid);
        }
        if ($rootPid > 0) {
            return $this->canReadPage($rootPid) && $this->canUseScanLanguage($languageUid);
        }

        return false;
    }

    /**
     * AUTH-02: cancelling the running content scan needs the right to start that same scan: a page scan the Content scan
     * admission of its page and language (canScanContentPage()); a site scan is stopped by the editor who started it (edit
     * access to its default-language root and the scan's language still required) or an administrator. Root rights alone
     * never stop a scan of a language or page the user may not scan. Administrators stop every scan.
     *
     * @param array<string, mixed> $status
     */
    public function canCancelLocalScan(array $status): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        $pageUid = (int)($status['pageUid'] ?? 0);
        $rootPid = (int)($status['rootPid'] ?? 0);
        $languageUid = (int)($status['languageUid'] ?? -1);
        if ($pageUid > 0) {
            return $this->canScanContentPage($pageUid, $languageUid);
        }
        if ($rootPid > 0) {
            // A site scan covers pages its starter was admitted to, which another editor may not scan (an administrator's
            // scan covers every page): an editor stops only their own site scan.
            return (string)($status['triggeredBy'] ?? '') !== ''
                && (string)($status['triggeredBy'] ?? '') === (string)($backendUser->user['username'] ?? '')
                && $this->canEditPage($rootPid)
                && $this->isDefaultLanguagePageRecord($rootPid)
                && $this->canUseScanLanguage($languageUid);
        }

        return false;
    }

    /**
     * Page scans are scoped by their page, in their language; site scans by the site root. A page scan whose page was
     * deleted, moved to another site or can no longer be read is not readable at all (Sol R03): losing access to the
     * page never turns into site-root access to its URL, language, dates and counters. Administrators read every scan
     * of an existing site, also of pages deleted since.
     *
     * @param array<string, mixed> $remoteScan
     */
    private function checkRemoteScan(array $remoteScan, bool $write): bool
    {
        $site = $this->siteResolutionService->resolveSiteByIdentifier(
            trim((string)($remoteScan['site_identifier'] ?? ''))
        );
        if (!$site instanceof Site) {
            return false;
        }
        $backendUser = $this->backendUserService->getBackendUser();
        if ($backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin()) {
            return true;
        }

        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }

        $pageUid = (int)($remoteScan['page_uid'] ?? 0);
        $isPageScan = $pageUid > 0 || (string)($remoteScan['scan_scope'] ?? '') === 'page';
        $languageUid = (int)($remoteScan['language_uid'] ?? -1);
        if (!$isPageScan) {
            // A site crawl in a language the user may not use is not theirs to read either (allowed_languages), the
            // default language 0 included (S-01). -1 is an all-language crawl: its results are checked per result page
            // (RemoteResultAuthorizationService), its own counters are withheld from non-administrators.
            if ($languageUid >= 0 && !$this->canUseLanguage($languageUid)) {
                return false;
            }

            return $write ? $this->canEditSite($site) : $this->canReadSite($site);
        }
        if ($pageUid <= 0 || !$this->isPageInSite($pageUid, $site)) {
            return false;
        }
        if (!($write ? $this->canEditPage($pageUid) : $this->canReadPage($pageUid))) {
            return false;
        }
        $record = $this->pageLanguageRecord($pageUid);
        if ($record !== null && $record['language'] > 0) {
            // I-01: a row stored before the admission fix with a translation record's uid (often as language 0). Its
            // content is in the record's language: that language, the default page and the translation record itself
            // decide — never the stored language_uid.
            $parentUid = $record['parent'];

            return $this->canUseLanguage($record['language'])
                && $parentUid > 0
                && ($write ? $this->canEditPage($parentUid) : $this->canReadPage($parentUid));
        }
        if ($languageUid < 0) {
            // A page scan always has one concrete language; -1 is a legacy row whose language is unknown. Its URL,
            // dates and page counters may describe any language, so only users without a language restriction read it.
            return !$this->hasLanguageRestriction($backendUser);
        }
        if (!$this->canUseLanguage($languageUid)) {
            return false;
        }
        if ($languageUid === 0) {
            return true;
        }
        // A translation record has its own page permissions. Results describe the live site: the live translation
        // decides (the MCP history candidate query applies the same rule, McpToolExecutor::deniedTranslations()).
        $translationUid = $this->liveTranslationUid($pageUid, $languageUid);

        return $translationUid <= 0 || ($write ? $this->canEditPage($translationUid) : $this->canReadPage($translationUid));
    }

    /**
     * AQG's read (and scan) language policy for stored and crawled results: TYPO3's allowed_languages restricts editing;
     * AQG also treats it as a read restriction, for every concrete language — the default language 0 included (S-01).
     * The all-language sentinel -1 is not a concrete language and is not decided here: callers decide what an
     * all-language value may reveal. Administrators and users without a language restriction use every language.
     */
    public function canUseLanguage(int $languageUid): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        if ($languageUid < 0 || $backendUser->isAdmin()) {
            return true;
        }

        return $backendUser->checkLanguageAccess($languageUid);
    }

    /** Whether the user's allowed_languages restrict languages at all (empty = every language, TYPO3 semantics). */
    public function hasLanguageRestriction(BackendUserAuthentication $backendUser): bool
    {
        return !$backendUser->isAdmin() && trim((string)($backendUser->groupData['allowed_languages'] ?? '')) !== '';
    }

    /**
     * Show access to a page in a language: the default page, and its live translation in that language when one exists
     * (a translation record has its own page permissions). Language access itself is checked by the caller.
     */
    public function canReadPageInLanguage(int $pageUid, int $languageUid): bool
    {
        if (!$this->canReadPage($pageUid)) {
            return false;
        }
        if ($languageUid <= 0) {
            return true;
        }
        $translationUid = $this->liveTranslationUid($pageUid, $languageUid);

        return $translationUid <= 0 || $this->canReadPage($translationUid);
    }

    /**
     * Admission of a single-page scan in one concrete language (SOL Hetzner preflight P1-01/P1-02), the one rule every
     * single-page submit path applies — MCP `aqg_start_scan` (fresh scans and idempotent replays alike), "Scan this
     * page", Verify fix and the Free Remote Preview — so the scan's own results stay readable to the user who started it
     * (checkRemoteScan()). Evaluated from the database on every call; nothing is cached across requests.
     *
     * - The default page: show and edit access (scanning acts on the page).
     * - The language: AQG's language policy, the default language 0 included (canUseLanguage(), S-01).
     * - Language > 0: the page's CURRENT live translation (liveTranslationUid(): live workspace, not deleted, lowest uid)
     *   needs show and edit access of its own when one exists (every live row, if the data has duplicates). Language
     *   access alone never opens a translation. Without a live translation the frontend renders the default page,
     *   which the user may already read and edit; the URL
     *   resolver uses the same lookup (FrontendPageUrlService), so the record checked here is the one that is crawled.
     *
     * Administrators pass every check. Site, Scan now permission and agent scopes are checked by the callers.
     */
    public function canScanPageInLanguage(int $pageUid, int $languageUid): bool
    {
        if ($pageUid <= 0 || $languageUid < 0) {
            return false;
        }
        // I-01: the target is a default-language page plus a language. A translation record's uid is no scan target in any
        // language — read as language 0 it resolved to the translation's URL and was stored as a default-language scan —
        // and it is refused for everyone (administrators included), before any reservation or crawler request. A
        // translation is scanned through its default page and the language (its own rights are checked below).
        if (!$this->isDefaultLanguagePageRecord($pageUid)) {
            return false;
        }
        if (!$this->canReadPage($pageUid) || !$this->canEditPage($pageUid) || !$this->canUseLanguage($languageUid)) {
            return false;
        }
        if ($languageUid === 0) {
            return true;
        }
        // Every live translation row of the page in that language, not only the lowest uid: with duplicate rows (broken
        // data) the frontend URL resolver may pick another one than liveTranslationUid() — none may be refused.
        foreach ($this->liveTranslationUids($pageUid, $languageUid) as $translationUid) {
            if (!$this->canReadPage($translationUid) || !$this->canEditPage($translationUid)) {
                return false;
            }
        }

        return true;
    }

    /**
     * AUTH-01: admission of a native Content scan of one default-language page, in one language or in all languages (-1).
     * The content collector reads the page's translated records through the default page and the language, so the rights
     * on the actual translation record decide, as for a frontend or MCP scan:
     *
     * - A concrete language: canScanPageInLanguage() (default page show + edit, language access, every live translation
     *   row of that language show + edit).
     * - All languages (-1): only users without a language restriction, show + edit on the default page and on every live
     *   translation row of the page in any language. One refused translation refuses the all-language scan of the page:
     *   it would collect, render and resolve that translation's content too.
     *
     * Evaluated from the database on every call (revocation applies to the next request). Administrators pass.
     */
    public function canScanContentPage(int $pageUid, int $languageUid): bool
    {
        if ($languageUid >= 0) {
            return $this->canScanPageInLanguage($pageUid, $languageUid);
        }
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication || $pageUid <= 0 || !$this->isDefaultLanguagePageRecord($pageUid)) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        if ($this->hasLanguageRestriction($backendUser) || !$this->canReadPage($pageUid) || !$this->canEditPage($pageUid)) {
            return false;
        }
        foreach ($this->liveTranslationUids($pageUid, null) as $translationUid) {
            if (!$this->canReadPage($translationUid) || !$this->canEditPage($translationUid)) {
                return false;
            }
        }

        return true;
    }

    /**
     * AUTH-02: whether the user may read a page's results in a language, the all-language value -1 included: language
     * policy (canUseLanguage(); -1 only without a language restriction) plus show access to the default page and to every
     * live translation row in that language (in any language for -1).
     */
    public function canReadPageResultsInLanguage(int $pageUid, int $languageUid): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication || $pageUid <= 0) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        if ($languageUid >= 0 ? !$this->canUseLanguage($languageUid) : $this->hasLanguageRestriction($backendUser)) {
            return false;
        }
        if (!$this->canReadPage($pageUid)) {
            return false;
        }
        if ($languageUid === 0) {
            return true;
        }
        foreach ($this->liveTranslationUids($pageUid, $languageUid > 0 ? $languageUid : null) as $translationUid) {
            if (!$this->canReadPage($translationUid)) {
                return false;
            }
        }

        return true;
    }

    /**
     * AUTH-02: the language part of a site-level read or action (site scan status, cancellation of a site scan): a concrete
     * language needs language access, the all-language value -1 a user without a language restriction.
     */
    public function canUseScanLanguage(int $languageUid): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }

        return $languageUid >= 0 ? $this->canUseLanguage($languageUid) : !$this->hasLanguageRestriction($backendUser);
    }

    /**
     * @return list<int> Every live (not deleted, live workspace) translation of the page in the language (any language > 0
     *         for null), by uid.
     */
    private function liveTranslationUids(int $pageUid, ?int $languageUid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(Tables::PAGES);
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder->select('uid')
            ->from(Tables::PAGES)
            ->where(
                $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $languageUid === null
                    ? $queryBuilder->expr()->gt('sys_language_uid', 0)
                    : $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('t3ver_wsid', 0),
            );

        return array_map('intval', $queryBuilder
            ->orderBy('uid')
            ->executeQuery()
            ->fetchFirstColumn());
    }

    /**
     * NEW-04: canReadPageInLanguage() for many (page, language) pairs at once, with the same rules. Per page it is
     * BackendUtility::readPageAccess() without its display path: the page record under the user's show-permission clause
     * (deleted pages excluded), BackendUserAuthentication::isInWebMount() on that record and the workspace overlay — but
     * one record query for all pages instead of one per page, and one query for the live translations. Language access
     * (canUseLanguage()) stays the caller's check, as for canReadPageInLanguage().
     *
     * @param list<array{0:int,1:int}> $pairs [pageUid, languageUid]
     * @return array<string, bool> keyed "pageUid:languageUid"
     */
    public function readablePagesInLanguages(array $pairs): array
    {
        $result = [];
        $pageUids = [];
        $translationRequests = [];
        foreach ($pairs as [$pageUid, $languageUid]) {
            $result[(int)$pageUid . ':' . (int)$languageUid] = false;
            if ((int)$pageUid > 0) {
                $pageUids[(int)$pageUid] = true;
                if ((int)$languageUid > 0) {
                    $translationRequests[(int)$languageUid][(int)$pageUid] = true;
                }
            }
        }
        $readable = $this->readablePageUids(array_keys($pageUids));
        $translations = [];
        foreach ($translationRequests as $languageUid => $pages) {
            foreach ($this->liveTranslationUidsOfPages(array_keys($pages), $languageUid) as $pageUid => $translationUid) {
                $translations[$pageUid . ':' . $languageUid] = $translationUid;
            }
        }
        $readableTranslations = $this->readablePageUids(array_values($translations));
        foreach ($pairs as [$pageUid, $languageUid]) {
            $key = (int)$pageUid . ':' . (int)$languageUid;
            if (!isset($readable[(int)$pageUid])) {
                continue;
            }
            if ((int)$languageUid <= 0 || !isset($translations[$key])) {
                // No live translation: the frontend renders the default page, which the user may read.
                $result[$key] = true;
                continue;
            }
            $result[$key] = isset($readableTranslations[$translations[$key]]);
        }

        return $result;
    }

    /**
     * The pages of $pageUids the current user may show (readPageAccess() semantics, see readablePagesInLanguages()).
     *
     * @param list<int> $pageUids
     * @return array<int, true>
     */
    public function readablePageUids(array $pageUids): array
    {
        $backendUser = $this->backendUserService->getBackendUser();
        $pageUids = array_values(array_unique(array_filter(array_map('intval', $pageUids), static fn (int $uid): bool => $uid > 0)));
        if (!$backendUser instanceof BackendUserAuthentication || $pageUids === []) {
            return [];
        }
        $permsClause = $backendUser->getPagePermsClause(Permission::PAGE_SHOW);
        $readable = [];
        foreach (array_chunk($pageUids, 500) as $chunk) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(Tables::PAGES);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction::class));
            $queryBuilder->select('*')
                ->from(Tables::PAGES)
                ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)));
            if (trim($permsClause) !== '') {
                $queryBuilder->andWhere(\TYPO3\CMS\Core\Database\Query\QueryHelper::stripLogicalOperatorPrefix($permsClause));
            }
            foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $page) {
                if (!$backendUser->isInWebMount($page, $permsClause)) {
                    continue;
                }
                BackendUtility::workspaceOL(Tables::PAGES, $page);
                if (is_array($page)) {
                    $readable[(int)$page['uid']] = true;
                }
            }
        }

        return $readable;
    }

    /**
     * liveTranslationUid() for many pages of one language (lowest live uid per page, as there).
     *
     * @param list<int> $pageUids
     * @return array<int, int> pageUid => translation uid (pages without a live translation are absent)
     */
    public function liveTranslationUidsOfPages(array $pageUids, int $languageUid): array
    {
        $map = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $pageUids))), 500) as $chunk) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(Tables::PAGES);
            $queryBuilder->getRestrictions()->removeAll();
            $rows = $queryBuilder->select('uid', 'l10n_parent')
                ->from(Tables::PAGES)
                ->where(
                    $queryBuilder->expr()->in('l10n_parent', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('deleted', 0),
                    $queryBuilder->expr()->eq('t3ver_wsid', 0),
                )
                ->orderBy('uid')
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $map[(int)$row['l10n_parent']] ??= (int)$row['uid'];
            }
        }

        return $map;
    }

    /**
     * I-01: whether the uid names a live default-language pages record: sys_language_uid 0, not deleted, not a workspace
     * version or placeholder (t3ver_wsid 0, t3ver_oid 0). A missing row is not one.
     */
    public function isDefaultLanguagePageRecord(int $pageUid): bool
    {
        $record = $this->pageLanguageRecord($pageUid);

        return $record !== null && $record['language'] === 0 && $record['live'];
    }

    /**
     * I-01: whether the uid names a page translation record (sys_language_uid > 0), whatever its state. Views that take a
     * page uid plus a language use it to refuse a translation's own uid, whose results would otherwise be judged as
     * "language 0" (their findings filtered out, the page shown as clean).
     */
    public function isTranslationPageRecord(int $pageUid): bool
    {
        $record = $this->pageLanguageRecord($pageUid);

        return $record !== null && $record['language'] > 0;
    }

    /**
     * @return array{language: int, parent: int, live: bool}|null The record's own sys_language_uid and l10n_parent (whatever
     *         its state) and whether it is a live, non-deleted row; null when there is no row.
     */
    private function pageLanguageRecord(int $pageUid): ?array
    {
        if ($pageUid <= 0) {
            return null;
        }
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(Tables::PAGES);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder->select('sys_language_uid', 'l10n_parent', 'deleted', 't3ver_wsid', 't3ver_oid')
            ->from(Tables::PAGES)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? [
            'language' => (int)$row['sys_language_uid'],
            'parent' => (int)$row['l10n_parent'],
            'live' => (int)$row['deleted'] === 0 && (int)$row['t3ver_wsid'] === 0 && (int)$row['t3ver_oid'] === 0,
        ] : null;
    }

    public function liveTranslationUid(int $pageUid, int $languageUid): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(Tables::PAGES);
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder->select('uid')
            ->from(Tables::PAGES)
            ->where(
                $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('t3ver_wsid', 0),
            )
            ->orderBy('uid')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }
}
