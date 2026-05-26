<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class BackendRecordAccessService
{
    public function __construct(
        private readonly BackendUserService $backendUserService,
    ) {
    }

    public function canEditRecord(string $table, int $uid): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }

        if ($table === '' || $uid <= 0) {
            return false;
        }

        if (!$backendUser->check('tables_modify', $table)) {
            return false;
        }

        $record = BackendUtility::getRecord($table, $uid, 'uid,pid,deleted,t3ver_oid');
        if (!is_array($record) || (int)($record['uid'] ?? 0) <= 0) {
            return false;
        }

        if ((int)($record['deleted'] ?? 0) === 1) {
            return false;
        }

        if ($table === Tables::PAGES) {
            $page = BackendUtility::readPageAccess(
                $uid,
                $backendUser->getPagePermsClause(2)
            );

            return is_array($page) && !empty($page);
        }

        $pid = $this->resolveRecordPagePid($table, $record);
        if ($pid <= 0) {
            return false;
        }

        return $this->canEditContentOnPage($pid, $table);
    }

    public function canEditContentOnPage(int $pageUid, string $table = Tables::TT_CONTENT): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }

        if ($pageUid <= 0 || $table === '') {
            return false;
        }

        if (!$backendUser->check('tables_modify', $table)) {
            return false;
        }

        $page = BackendUtility::readPageAccess(
            $pageUid,
            $backendUser->getPagePermsClause(16)
        );

        return is_array($page) && !empty($page);
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

    /**
     * @param array<string, mixed> $record
     */
    private function resolveRecordPagePid(string $table, array $record): int
    {
        $pid = (int)($record['pid'] ?? 0);
        if ($pid > 0) {
            return $pid;
        }

        $versionedOriginalUid = (int)($record['t3ver_oid'] ?? 0);
        if ($pid === -1 && $versionedOriginalUid > 0) {
            $original = BackendUtility::getRecord($table, $versionedOriginalUid, 'uid,pid,deleted,t3ver_oid');
            if (is_array($original) && (int)($original['deleted'] ?? 0) !== 1) {
                return $this->resolveRecordPagePid($table, $original);
            }
        }

        if ($pid < -1) {
            $anchorRecord = BackendUtility::getRecord($table, abs($pid), 'uid,pid,deleted,t3ver_oid');
            if (is_array($anchorRecord) && (int)($anchorRecord['deleted'] ?? 0) !== 1) {
                return $this->resolveRecordPagePid($table, $anchorRecord);
            }
        }

        return 0;
    }
}
