<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Contract\LocalFindingSourceAccessInterface;
use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * G-01: who may ignore or unignore a stored local finding (`tx_a11y_issue`).
 *
 * An ignored finding stops counting for the publish Quality Gate of its stored page and language, so changing the
 * ignore state needs current edit authority over the finding's actual TYPO3 target, decided from the stored row and
 * the current records, never from request parameters. A non-administrator needs all of:
 *
 * - read access to the finding's source today (LocalFindingSourceAccessService::canReadFinding(): record exists, is
 *   live, `tables_select`, language access to the stored language, page show access, consistent page provenance);
 * - language access to the finding's stored language (`source_lang_uid`; "all languages" (-1) only for record sources, as TYPO3 grants it);
 * - edit access to the target record as TYPO3 decides it (BackendRecordAccessService::canEditRecordFields() without
 *   fields: `tables_modify`, record internals incl. the record's language, authMode and editlock, page permissions,
 *   writable in the current workspace) and, in a workspace, to the record's version there (stage);
 *   - a page finding targets the page row itself; a finding of a default-language page in language L (rendered HTML)
 *     targets the page's live translation in L, or the default row while no translation exists;
 *   - any other record finding also needs content edit access to the page the finding is stored for (the page whose
 *     Quality Gate it counts for), so a moved record cannot carry findings across pages;
 * - live workspace access while working in a workspace: the ignore state is the live finding's state.
 *
 * A stored page that does not match the page source (stale or mismatched provenance) is refused. Administrators keep
 * their unrestricted workflow.
 */
final class FindingIgnoreAccessService
{
    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly BackendRecordAccessService $recordAccessService,
        private readonly LocalFindingSourceAccessInterface $sourceAccessService,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /** @param array<string, mixed> $issue a `tx_a11y_issue` row (uid, site_identifier, page_uid, source_*) */
    public function canChangeIgnoreState(array $issue): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication
            || (int)($issue['uid'] ?? 0) <= 0
            || (int)($issue['deleted'] ?? 0) !== 0) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }

        $table = trim((string)($issue['source_table'] ?? ''));
        $uid = (int)($issue['source_uid'] ?? 0);
        $languageUid = (int)($issue['source_lang_uid'] ?? 0);
        $pageUid = (int)($issue['page_uid'] ?? 0);
        // A record of "all languages" (-1) carries that language; TYPO3 grants it and the record's internals decide.
        // A page row is never "all languages": a page finding of -1 is inconsistent provenance.
        if ($table === '' || $uid <= 0 || $pageUid <= 0 || $languageUid < -1
            || ($languageUid === -1 && $table === Tables::PAGES)
            || !$backendUser->checkLanguageAccess($languageUid)
            || !$this->mayChangeLiveState($backendUser)
            || !$this->sourceAccessService->canReadFinding($issue)) {
            return false;
        }

        if ($table === Tables::PAGES) {
            $targetUid = $this->pageTargetUid($uid, $languageUid, $pageUid);

            return $targetUid > 0 && $this->canEditTarget($backendUser, Tables::PAGES, $targetUid);
        }

        return $this->recordAccessService->canEditContentOnPage($pageUid, $table)
            && $this->canEditTarget($backendUser, $table, $uid);
    }

    /**
     * Uids of the given finding rows whose ignore state the current user may change.
     *
     * @param iterable<array<string, mixed>> $issues
     * @return list<int>
     */
    public function filterChangeable(iterable $issues): array
    {
        $allowed = [];
        foreach ($issues as $issue) {
            if ($this->canChangeIgnoreState($issue)) {
                $allowed[] = (int)$issue['uid'];
            }
        }

        return $allowed;
    }

    /**
     * Ignoring a finding of a record that has no stored row yet (live editor validation): the record itself, in its
     * own language, on the page the finding is stored for.
     */
    public function canIgnoreRecordFinding(string $table, int $uid, int $pageUid): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        if ($table === '' || $table === Tables::PAGES || $uid <= 0 || $pageUid <= 0
            || !$this->mayChangeLiveState($backendUser)
            || !$this->sourceAccessService->canReadRecord($table, $uid)
            || !$this->recordAccessService->isRecordOnPage($table, $uid, $pageUid)) {
            return false;
        }

        return $this->recordAccessService->canEditContentOnPage($pageUid, $table)
            && $this->canEditTarget($backendUser, $table, $uid);
    }

    private function mayChangeLiveState(BackendUserAuthentication $backendUser): bool
    {
        $workspace = (int)($backendUser->workspace ?? 0);

        return $workspace === 0 || $backendUser->checkWorkspace(0) !== false;
    }

    private function canEditTarget(BackendUserAuthentication $backendUser, string $table, int $uid): bool
    {
        if (!$this->recordAccessService->canEditRecordFields($table, $uid, [])) {
            return false;
        }

        $workspace = (int)($backendUser->workspace ?? 0);
        if ($workspace <= 0 || empty($GLOBALS['TCA'][$table]['ctrl']['versioningWS'])) {
            return true;
        }

        // The record's version in the current workspace (its stage, its own internals) decides as well.
        $version = BackendUtility::getWorkspaceVersionOfRecord($workspace, $table, $uid, 'uid');
        if (!is_array($version) || (int)($version['uid'] ?? 0) <= 0) {
            return true;
        }

        return $this->recordAccessService->canEditRecordFields($table, (int)$version['uid'], []);
    }

    /**
     * The page row a page finding targets, 0 for inconsistent provenance: the stored page must be the source's
     * default-language page; a finding of a default row in language L targets the live translation in L when one exists.
     */
    private function pageTargetUid(int $sourceUid, int $languageUid, int $storedPageUid): int
    {
        $row = BackendUtility::getRecord(Tables::PAGES, $sourceUid, 'uid,deleted,sys_language_uid,l10n_parent');
        if (!is_array($row) || (int)($row['deleted'] ?? 0) !== 0) {
            return 0;
        }

        $rowLanguageUid = (int)($row['sys_language_uid'] ?? 0);
        $defaultPageUid = $rowLanguageUid > 0 ? (int)($row['l10n_parent'] ?? 0) : $sourceUid;
        if ($defaultPageUid <= 0 || $defaultPageUid !== $storedPageUid) {
            return 0;
        }
        if ($rowLanguageUid > 0) {
            return $rowLanguageUid === $languageUid ? $sourceUid : 0;
        }
        if ($languageUid === 0) {
            return $sourceUid;
        }

        $translationUid = $this->liveTranslationUid($sourceUid, $languageUid);

        return $translationUid > 0 ? $translationUid : $sourceUid;
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
}
