<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Contract\BackendRecordAccessServiceInterface;
use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class BackendRecordAccessService implements BackendRecordAccessServiceInterface
{
    public function __construct(
        private readonly BackendUserService $backendUserService,
    ) {
    }

    public function canEditRecord(string $table, int $uid): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication || $table === '' || $uid <= 0) {
            return false;
        }

        if (!$backendUser->check('tables_modify', $table)) {
            return false;
        }

        $record = BackendUtility::getRecord($table, $uid, 'uid,pid,deleted,t3ver_oid');
        if (!is_array($record)
            || (int)($record['uid'] ?? 0) <= 0
            || (int)($record['deleted'] ?? 0) === 1) {
            return false;
        }

        if ($table === Tables::PAGES) {
            $page = BackendUtility::readPageAccess($uid, $backendUser->getPagePermsClause(2));
            return is_array($page) && $page !== [];
        }

        $pageUid = $this->resolveRecordPagePid($table, $record);
        return $pageUid > 0 && $this->canEditContentOnPage($pageUid, $table);
    }

    public function canEditRecordFields(string $table, int $uid, array $fields = []): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication
            || $table === ''
            || $uid <= 0
            || !isset($GLOBALS['TCA'][$table])) {
            return false;
        }

        $record = BackendUtility::getRecord($table, $uid, '*');
        if (!is_array($record)
            || (int)($record['uid'] ?? 0) <= 0
            || (int)($record['deleted'] ?? 0) === 1) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        if (!$backendUser->check('tables_modify', $table)
            || !$this->hasFieldAccess($backendUser, $table, $fields)
            || !$this->hasRecordInternalAccess($backendUser, $table, $record)) {
            return false;
        }

        if ($table === Tables::PAGES) {
            return $this->hasPageAccess($backendUser, $record, 2);
        }

        $pageUid = $this->resolveRecordPagePid($table, $record);
        if ($pageUid <= 0) {
            return false;
        }

        $page = BackendUtility::getRecord(Tables::PAGES, $pageUid, '*');
        return is_array($page)
            && (int)($page['deleted'] ?? 0) !== 1
            && $this->hasPageAccess($backendUser, $page, 16);
    }

    public function isRecordOnPage(string $table, int $uid, int $pageUid): bool
    {
        if ($table === '' || $uid <= 0 || $pageUid <= 0) {
            return false;
        }

        $record = BackendUtility::getRecord($table, $uid, '*');
        if (!is_array($record) || (int)($record['deleted'] ?? 0) === 1) {
            return false;
        }

        if ($table === Tables::PAGES) {
            return (int)($record['uid'] ?? 0) === $pageUid;
        }

        return $this->resolveRecordPagePid($table, $record) === $pageUid;
    }

    public function canEditContentOnPage(int $pageUid, string $table = Tables::TT_CONTENT): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication || $pageUid <= 0 || $table === '') {
            return false;
        }

        if (!$backendUser->check('tables_modify', $table)) {
            return false;
        }

        $page = BackendUtility::readPageAccess($pageUid, $backendUser->getPagePermsClause(16));
        return is_array($page) && $page !== [];
    }

    public function recordExists(string $table, int $uid): bool
    {
        if ($table === '' || $uid <= 0) {
            return false;
        }

        $record = BackendUtility::getRecord($table, $uid, 'uid,deleted');

        return is_array($record)
            && (int)($record['uid'] ?? 0) > 0
            && (int)($record['deleted'] ?? 0) !== 1;
    }

    /** @param array<string, mixed> $page */
    private function hasPageAccess(
        BackendUserAuthentication $backendUser,
        array $page,
        int $permission,
    ): bool {
        return $backendUser->isInWebMount($page) !== null
            && $backendUser->doesUserHaveAccess($page, $permission);
    }

    /** @param list<string> $fields */
    private function hasFieldAccess(
        BackendUserAuthentication $backendUser,
        string $table,
        array $fields,
    ): bool {
        foreach (array_values(array_unique($fields)) as $field) {
            if ($field === '' || !isset($GLOBALS['TCA'][$table]['columns'][$field])) {
                return false;
            }

            if (($GLOBALS['TCA'][$table]['columns'][$field]['exclude'] ?? false)
                && !$backendUser->check('non_exclude_fields', $table . ':' . $field)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $record */
    private function hasRecordInternalAccess(
        BackendUserAuthentication $backendUser,
        string $table,
        array $record,
    ): bool {
        if (method_exists($backendUser, 'checkRecordEditAccess')) {
            $result = $backendUser->checkRecordEditAccess($table, $record);
            return is_object($result) && (bool)($result->isAllowed ?? false);
        }

        return $backendUser->recordEditAccessInternals($table, $record);
    }

    /** @param array<string, mixed> $record */
    private function resolveRecordPagePid(string $table, array $record): int
    {
        $pid = (int)($record['pid'] ?? 0);
        if ($pid > 0) {
            return $pid;
        }

        $versionedOriginalUid = (int)($record['t3ver_oid'] ?? 0);
        if ($pid === -1 && $versionedOriginalUid > 0) {
            $original = BackendUtility::getRecord($table, $versionedOriginalUid, '*');
            if (is_array($original) && (int)($original['deleted'] ?? 0) !== 1) {
                return $this->resolveRecordPagePid($table, $original);
            }
        }

        if ($pid < -1) {
            $anchorRecord = BackendUtility::getRecord($table, abs($pid), '*');
            if (is_array($anchorRecord) && (int)($anchorRecord['deleted'] ?? 0) !== 1) {
                return $this->resolveRecordPagePid($table, $anchorRecord);
            }
        }

        return 0;
    }
}
