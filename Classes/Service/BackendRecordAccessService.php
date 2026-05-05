<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

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

        $record = BackendUtility::getRecord($table, $uid, 'uid,pid,deleted');
        if (!is_array($record) || (int)($record['uid'] ?? 0) <= 0) {
            return false;
        }

        if ((int)($record['deleted'] ?? 0) === 1) {
            return false;
        }

        if ($table === 'pages') {
            $page = BackendUtility::readPageAccess(
                $uid,
                $backendUser->getPagePermsClause(2)
            );

            return is_array($page) && !empty($page);
        }

        $pid = (int)($record['pid'] ?? 0);
        if ($pid <= 0) {
            return false;
        }

        $page = BackendUtility::readPageAccess(
            $pid,
            $backendUser->getPagePermsClause(16)
        );

        return is_array($page) && !empty($page);
    }
}