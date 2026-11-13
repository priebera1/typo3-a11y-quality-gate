<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Editor;

use Priebera\A11yQualityGate\Contract\BackendRecordAccessServiceInterface;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Resolves and authorises the record an editor AI request is about. AI never widens what the user may do: the user
 * must be allowed to edit this field of this record (table, page, field exclude, language and edit lock checks of
 * TYPO3), exactly like saving it through FormEngine.
 */
final class EditorAiRecordContextResolver
{
    public function __construct(
        private readonly BackendRecordAccessServiceInterface $recordAccessService,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly BackendUserService $backendUserService,
    ) {}

    public function resolve(string $table, int $uid, string $field): EditorAiRecordContext
    {
        $field = trim($field);
        if ($table !== Tables::TT_CONTENT || $uid <= 0 || !$this->isTextField($table, $field)) {
            throw new EditorAiException('invalid_input', 'Unsupported record or field.', 422);
        }

        $record = BackendUtility::getRecordWSOL($table, $uid);
        if (!is_array($record) || (int)($record['deleted'] ?? 0) === 1) {
            throw new EditorAiException('record_not_found', 'The record could not be found.', 404);
        }
        if (!$this->recordAccessService->canEditRecordFields($table, $uid, [$field])) {
            throw new EditorAiException('permission_denied', 'You may not edit this field.', 403);
        }

        $pageUid = $this->pageUid($table, $record);
        $site = $pageUid > 0 ? $this->siteResolutionService->resolveSiteByPageId($pageUid) : null;
        if (!$site instanceof Site) {
            throw new EditorAiException('no_site', 'The record is not inside a TYPO3 site.', 422);
        }

        $languageUid = (int)($record['sys_language_uid'] ?? 0);
        [$targetLocale, $languageTitle] = $this->locale($site, max(0, $languageUid));
        [$defaultLocale] = $this->locale($site, 0);
        $backendUser = $this->backendUserService->getBackendUser();

        return new EditorAiRecordContext(
            table: $table,
            uid: $uid,
            field: $field,
            pageUid: $pageUid,
            siteIdentifier: $site->getIdentifier(),
            languageUid: $languageUid,
            targetLocale: $targetLocale,
            languageTitle: $languageTitle,
            defaultLocale: $defaultLocale,
            workspaceId: (int)($backendUser?->workspace ?? 0),
            isTranslation: $languageUid > 0 && (int)($record['l18n_parent'] ?? 0) > 0,
            cType: (string)($record['CType'] ?? ''),
        );
    }

    /** The stored value of the field as TYPO3 shows it to this user (workspace overlay included). */
    public function storedValue(EditorAiRecordContext $context): array
    {
        $record = BackendUtility::getRecordWSOL($context->table, $context->uid);
        if (!is_array($record)) {
            throw new EditorAiException('record_not_found', 'The record could not be found.', 404);
        }

        return [
            'html' => (string)($record[$context->field] ?? ''),
            'tstamp' => (int)($record['tstamp'] ?? 0),
        ];
    }

    private function isTextField(string $table, string $field): bool
    {
        return $field !== ''
            && preg_match('/^[a-z0-9_]{1,64}$/', $field) === 1
            && ($GLOBALS['TCA'][$table]['columns'][$field]['config']['type'] ?? '') === 'text';
    }

    /** @param array<string,mixed> $record */
    private function pageUid(string $table, array $record): int
    {
        $pid = (int)($record['pid'] ?? 0);
        if ($pid > 0) {
            return $pid;
        }
        $originalUid = (int)($record['t3ver_oid'] ?? 0);
        if ($originalUid > 0) {
            $original = BackendUtility::getRecord($table, $originalUid, 'pid');
            return is_array($original) ? max(0, (int)($original['pid'] ?? 0)) : 0;
        }

        return 0;
    }

    /** @return array{0:string,1:string} BCP 47 locale and language title */
    private function locale(Site $site, int $languageUid): array
    {
        try {
            $language = $site->getLanguageById($languageUid);
        } catch (\Throwable) {
            $language = $site->getDefaultLanguage();
        }
        $locale = str_replace('_', '-', trim($language->getLocale()->getName()));
        if (preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{2,8})*$/D', $locale) !== 1) {
            $code = strtolower(trim($language->getLocale()->getLanguageCode()));
            $locale = preg_match('/^[a-z]{2,8}$/D', $code) === 1 ? $code : 'en';
        }

        return [$locale, $language->getTitle()];
    }
}
