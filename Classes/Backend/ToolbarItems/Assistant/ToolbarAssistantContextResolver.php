<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * What the toolbar assistant analyses, from the identifiers the browser sends: the record open in the editing form,
 * else a file selected in the File list, else the page of the module or page tree. Only allowlisted identifiers are
 * read (never field values); everything else is loaded here and checked against the user's TYPO3 permissions. A
 * record, file or page the user may not read resolves exactly like one that does not exist.
 */
final class ToolbarAssistantContextResolver
{
    /** Records whose findings AQG stores (tt_content) or that are pages; file metadata becomes a file context. */
    public const EDITABLE_TABLES = [Tables::TT_CONTENT, Tables::PAGES, 'sys_file_metadata'];
    public const MAX_CHANGED_FIELDS = 30;

    public function __construct(
        private readonly ScopeAccessService $scopeAccessService,
        private readonly BackendUserService $backendUserService,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly ResourceFactory $resourceFactory,
    ) {
    }

    public function resolve(ServerRequestInterface $request): ToolbarAssistantContext
    {
        $query = $request->getQueryParams();
        $backendUser = $this->backendUserService->getBackendUser();
        $workspace = $backendUser instanceof BackendUserAuthentication ? (int)$backendUser->workspace : 0;
        $unsaved = ($query['unsaved'] ?? '') === '1';
        $changedFields = self::changedFields((string)($query['changed'] ?? ''));
        $requestedLanguage = self::nonNegativeInt($query['language'] ?? null);
        if (!$backendUser instanceof BackendUserAuthentication) {
            return ToolbarAssistantContext::none($workspace);
        }

        $editTable = (string)($query['editTable'] ?? '');
        $editUid = self::positiveInt($query['editUid'] ?? null);
        if ($editUid > 0 && in_array($editTable, self::EDITABLE_TABLES, true)) {
            return match ($editTable) {
                Tables::TT_CONTENT => $this->contentElement($editUid, $backendUser, $workspace, $unsaved, $changedFields),
                Tables::PAGES => $this->editedPage($editUid, $backendUser, $workspace, $unsaved, $changedFields),
                default => $this->fileOfMetadata($editUid, $backendUser, $workspace, $unsaved),
            };
        }

        $fileUid = self::positiveInt($query['fileUid'] ?? null);
        if ($fileUid > 0) {
            return $this->file($fileUid, $backendUser, $workspace, false);
        }

        if (($query['folder'] ?? '') === '1') {
            return ToolbarAssistantContext::folder($workspace);
        }

        $pageUid = self::positiveInt($query['id'] ?? null);
        if ($pageUid > 0) {
            return $this->page($pageUid, $requestedLanguage, $backendUser, $workspace, false, false, []);
        }

        return ToolbarAssistantContext::none($workspace);
    }

    /**
     * @param list<string> $changedFields
     */
    private function contentElement(int $uid, BackendUserAuthentication $backendUser, int $workspace, bool $unsaved, array $changedFields): ToolbarAssistantContext
    {
        $row = $this->readableRecord(Tables::TT_CONTENT, $uid, $backendUser, $workspace);
        // Findings are stored for the live record; a workspace version of it stands for its live original.
        $uid = (int)($row['t3ver_oid'] ?? 0) > 0 ? (int)$row['t3ver_oid'] : $uid;
        $pageUid = (int)($row['pid'] ?? 0);
        $languageUid = (int)($row['sys_language_uid'] ?? 0);
        $siteIdentifier = $row !== null ? $this->siteResolutionService->resolveSiteIdentifierForPageId($pageUid) : '';
        if ($row === null || $pageUid <= 0 || $siteIdentifier === '' || !$this->languageAllowed($backendUser, $languageUid)) {
            return ToolbarAssistantContext::unavailable($workspace);
        }

        return new ToolbarAssistantContext(
            type: ToolbarAssistantContext::TYPE_RECORD,
            workspace: $workspace,
            siteIdentifier: $siteIdentifier,
            pageUid: $pageUid,
            languageUid: max(0, $languageUid),
            table: Tables::TT_CONTENT,
            recordUid: $uid,
            recordTitle: trim((string)BackendUtility::getRecordTitle(Tables::TT_CONTENT, $row)),
            recordType: (string)($row['CType'] ?? ''),
            pageTitle: $this->pageTitle($pageUid),
            unsaved: $unsaved,
            changedFields: $changedFields,
        );
    }

    /**
     * @param list<string> $changedFields
     */
    private function page(int $pageUid, ?int $requestedLanguage, BackendUserAuthentication $backendUser, int $workspace, bool $editing, bool $unsaved, array $changedFields): ToolbarAssistantContext
    {
        $siteIdentifier = $this->scopeAccessService->canReadPage($pageUid)
            ? $this->siteResolutionService->resolveSiteIdentifierForPageId($pageUid)
            : '';
        if ($siteIdentifier === '') {
            return ToolbarAssistantContext::unavailable($workspace);
        }
        // A language the user may not use falls back to the default language only if that one is allowed.
        $languageUid = $requestedLanguage ?? 0;
        if (!$this->languageAllowed($backendUser, $languageUid)) {
            if ($languageUid === 0 || !$this->languageAllowed($backendUser, 0)) {
                return ToolbarAssistantContext::unavailable($workspace);
            }
            $languageUid = 0;
        }
        // A page translation has its own page permissions (Sol QA-04 review): a refused translation answers like a
        // missing page, without falling back to another language.
        if ($languageUid > 0 && !$this->scopeAccessService->canReadPageInLanguage($pageUid, $languageUid)) {
            return ToolbarAssistantContext::unavailable($workspace);
        }

        return new ToolbarAssistantContext(
            type: ToolbarAssistantContext::TYPE_PAGE,
            workspace: $workspace,
            siteIdentifier: $siteIdentifier,
            pageUid: $pageUid,
            languageUid: $languageUid,
            table: $editing ? Tables::PAGES : '',
            recordUid: $editing ? $pageUid : 0,
            pageTitle: $this->pageTitle($pageUid),
            unsaved: $editing && $unsaved,
            changedFields: $editing ? $changedFields : [],
        );
    }

    /**
     * The page record open in the editing form: a translation stands for its default-language page in that language
     * (findings are stored per page and language), a workspace version for its live page.
     *
     * @param list<string> $changedFields
     */
    private function editedPage(int $uid, BackendUserAuthentication $backendUser, int $workspace, bool $unsaved, array $changedFields): ToolbarAssistantContext
    {
        $row = BackendUtility::getRecord(Tables::PAGES, $uid, 'uid,pid,sys_language_uid,l10n_parent,t3ver_wsid,t3ver_oid');
        if (!is_array($row) || !self::inUsersWorkspace($row, $workspace)) {
            return ToolbarAssistantContext::unavailable($workspace);
        }
        $languageUid = max(0, (int)($row['sys_language_uid'] ?? 0));
        $pageUid = (int)($row['t3ver_oid'] ?? 0) > 0 ? (int)$row['t3ver_oid'] : $uid;
        if ($languageUid > 0) {
            if (!$this->languageAllowed($backendUser, $languageUid)) {
                return ToolbarAssistantContext::unavailable($workspace);
            }
            $pageUid = (int)($row['l10n_parent'] ?? 0);
        }
        if ($pageUid <= 0) {
            return ToolbarAssistantContext::unavailable($workspace);
        }

        return $this->page($pageUid, $languageUid, $backendUser, $workspace, true, $unsaved, $changedFields);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function inUsersWorkspace(array $row, int $workspace): bool
    {
        $recordWorkspace = (int)($row['t3ver_wsid'] ?? 0);

        return $recordWorkspace === 0 || $recordWorkspace === $workspace;
    }

    private function fileOfMetadata(int $metadataUid, BackendUserAuthentication $backendUser, int $workspace, bool $unsaved): ToolbarAssistantContext
    {
        $row = BackendUtility::getRecord('sys_file_metadata', $metadataUid, 'uid,file');
        if (!is_array($row) || !$backendUser->check('tables_select', 'sys_file_metadata')) {
            return ToolbarAssistantContext::unavailable($workspace);
        }

        return $this->file((int)($row['file'] ?? 0), $backendUser, $workspace, $unsaved, $metadataUid);
    }

    private function file(int $fileUid, BackendUserAuthentication $backendUser, int $workspace, bool $unsaved, int $metadataUid = 0): ToolbarAssistantContext
    {
        try {
            $file = $fileUid > 0 ? $this->resourceFactory->getFileObject($fileUid) : null;
        } catch (\Throwable) {
            $file = null;
        }
        // FAL decides: the storage must be accessible to the user and the file inside their file mounts.
        if (!$file instanceof File || $file->isMissing() || !$file->getStorage()->isBrowsable() || !$file->checkActionPermission('read')) {
            return ToolbarAssistantContext::unavailable($workspace);
        }

        return new ToolbarAssistantContext(
            type: ToolbarAssistantContext::TYPE_FILE,
            workspace: $workspace,
            fileUid: (int)$file->getUid(),
            fileName: $file->getName(),
            fileIsImage: $file->isImage(),
            fileAlternative: trim((string)$file->getProperty('alternative')),
            metadataUid: $metadataUid > 0 ? $metadataUid : (int)($file->getMetaData()->offsetGet('uid') ?? 0),
            // FAL's own metadata right (storage, file mounts) plus the table permission of the editing form.
            metadataEditable: $file->checkActionPermission('editMeta') && $backendUser->check('tables_modify', 'sys_file_metadata'),
            unsaved: $unsaved,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readableRecord(string $table, int $uid, BackendUserAuthentication $backendUser, int $workspace): ?array
    {
        if (!$backendUser->check('tables_select', $table)) {
            return null;
        }
        // A version or new placeholder of another workspace resolves like a missing record.
        $row = BackendUtility::getRecord($table, $uid);
        if (!is_array($row) || !self::inUsersWorkspace($row, $workspace) || !$this->scopeAccessService->canReadPage((int)($row['pid'] ?? 0))) {
            return null;
        }

        return $row;
    }

    private function languageAllowed(BackendUserAuthentication $backendUser, int $languageUid): bool
    {
        return $languageUid < 0 || $backendUser->checkLanguageAccess($languageUid);
    }

    private function pageTitle(int $pageUid): string
    {
        $page = BackendUtility::getRecord(Tables::PAGES, $pageUid, 'uid,title');

        return trim((string)($page['title'] ?? ''));
    }

    /**
     * Field names only (the editing form's changed fields), never their values.
     *
     * @return list<string>
     */
    private static function changedFields(string $value): array
    {
        $fields = [];
        foreach (explode(',', $value) as $field) {
            $field = trim($field);
            if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $field) === 1) {
                $fields[$field] = true;
            }
            if (count($fields) >= self::MAX_CHANGED_FIELDS) {
                break;
            }
        }

        return array_keys($fields);
    }

    private static function positiveInt(mixed $value): int
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) === 1 ? (int)$value : 0;
    }

    private static function nonNegativeInt(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^(0|[1-9][0-9]{0,4})$/D', $value) === 1 ? (int)$value : null;
    }
}
