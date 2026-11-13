<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Media;

use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiException;
use Priebera\A11yQualityGate\Ai\Service\AiRateLimiter;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredTaskRunner;
use Priebera\A11yQualityGate\Ai\Task\AiTask;
use Priebera\A11yQualityGate\Contract\BackendRecordAccessServiceInterface;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Rule\RuleRegistry;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Contextual alternative text for one image use (sys_file_reference) inside a content element, requested from the
 * wand beside the reference's "Alternative text" field.
 *
 * The suggestion is for this use only: AQG never writes here. The editor applies it into the form field, and TYPO3
 * Save stores it on exactly this reference with the normal permissions, history and workspace handling; the file
 * default in sys_file_metadata and every other reference of the same file stay untouched. The reference, its file,
 * its parent record and field are resolved and cross-checked server-side; the browser only names them.
 */
final class ReferenceAltAiService
{
    private const MAX_OUTPUT_TOKENS = 400;
    private const PARENT_TABLES = [Tables::TT_CONTENT];

    public function __construct(
        private readonly MediaAiFileGuard $fileGuard,
        private readonly MediaAiImageLoader $imageLoader,
        private readonly MediaAiSiteResolver $siteResolver,
        private readonly FileMetadataRecordResolver $metadataResolver,
        private readonly MediaAiFieldPolicy $fieldPolicy,
        private readonly EditorAiAccessService $accessService,
        private readonly AiStructuredTaskRunner $taskRunner,
        private readonly AiSiteSettingsService $siteSettingsService,
        private readonly BackendRecordAccessServiceInterface $recordAccessService,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly BackendUserService $backendUserService,
        private readonly RuleRegistry $ruleRegistry,
        private readonly AiRateLimiter $rateLimiter,
    ) {}

    /**
     * @param array{parentTable:string,parentUid:int,fieldName:string,referenceUid:int,fileUid:int,currentAlt:string,mode:string} $input
     * @return array<string,mixed>
     */
    public function suggest(array $input): array
    {
        $target = $this->resolveTarget($input);
        $site = $target['site'];
        $this->accessService->assertReady($site->getIdentifier(), EditorAiAccessService::FLAG_IMAGES);
        [$locale, $languageTitle] = $this->siteResolver->locale($site, max(0, $target['languageUid']));
        if ($locale === '') {
            [$locale, $languageTitle] = $this->siteResolver->locale($site, 0);
        }
        $currentAlt = mb_substr(trim((string)$input['currentAlt']), 0, 500);
        $defaultAlt = $target['defaultAlt'];
        $base = [
            'referenceUid' => $target['referenceUid'],
            'fileUid' => $target['file']->uid,
            'languageUid' => $target['languageUid'],
            'languageTitle' => $languageTitle,
            'defaultAlt' => $defaultAlt,
            'workspace' => (int)($this->backendUserService->getBackendUser()?->workspace ?? 0),
        ];

        if ($input['mode'] === 'translate') {
            return $base + $this->translate($site, $target, $locale);
        }

        $settings = $this->siteSettingsService->get($site->getIdentifier());
        if (!$settings->allowImageInput) {
            throw new EditorAiException('privacy_images', 'This site does not allow sending images to AI providers.', 422);
        }
        $image = $this->imageLoader->load($target['file']);
        $parent = $target['parent'];
        $untrusted = [
            'target_locale' => $locale,
            'file_name' => $target['file']->name,
            'element_header' => MediaAiPayload::plain($parent['header'] ?? '', 200),
            'element_text' => MediaAiPayload::excerpt($parent['bodytext'] ?? '', 600),
            'file_default_alternative' => mb_substr($defaultAlt, 0, 500),
            'current_alternative' => $currentAlt,
        ];
        $sent = ['image', 'elementHeader', 'elementText', 'fileDefault', 'language'];
        if ($settings->allowPageTitle && $target['pageTitle'] !== '') {
            $untrusted['page_title'] = MediaAiPayload::plain($target['pageTitle'], 200);
            $sent[] = 'pageTitle';
        }
        [$linkTarget, $linkHasPageTitle] = $this->linkTarget((string)($target['reference']['link'] ?? ''), $settings->allowPageTitle);
        if ($linkTarget !== '') {
            $untrusted['link_target'] = $linkTarget;
            $sent[] = 'linkTarget';
        }
        $this->rateLimiter->assertAllowed(FileMetadataAiService::MEDIA_RATE_SCOPE, null, FileMetadataAiService::MEDIA_RATE_SCOPE);

        $response = $this->taskRunner->run($site->getIdentifier(), MediaAiPayload::request(
            AiTask::ALT_TEXT,
            MediaAiPrompts::REFERENCE_PROMPT_VERSION,
            MediaAiPrompts::referenceInstructions(),
            $untrusted,
            MediaAiPrompts::referenceOutputFormat(),
            self::MAX_OUTPUT_TOKENS,
            $image,
            $linkHasPageTitle || array_key_exists('page_title', $untrusted),
        ));

        $payload = $base + MediaAiPayload::provenance($response, MediaAiPrompts::REFERENCE_PROMPT_VERSION, $sent) + [
            'mode' => 'suggest',
            'value' => '',
            'reason' => MediaAiPayload::plain($response->data['reason'] ?? '', 400),
        ];
        $status = (string)($response->data['status'] ?? '');
        if ($status === MediaAiPrompts::STATUS_DEFAULT_FITS) {
            return ['status' => $defaultAlt !== '' ? MediaAiPrompts::STATUS_DEFAULT_FITS : MediaAiPrompts::STATUS_NEEDS_REVIEW] + $payload;
        }
        if ($status !== MediaAiPrompts::STATUS_SUGGESTION) {
            return ['status' => in_array($status, [MediaAiPrompts::STATUS_DECORATIVE, MediaAiPrompts::STATUS_NEEDS_REVIEW], true) ? $status : 'invalid_response'] + $payload;
        }
        [$value, $rejection] = $this->fieldPolicy->validate(MediaAiFieldPolicy::FIELD_ALTERNATIVE, (string)($response->data['alternative'] ?? ''));
        if ($rejection !== MediaAiFieldPolicy::VALID) {
            return ['status' => 'invalid_response', 'rejection' => $rejection, 'reason' => ''] + $payload;
        }
        $payload['value'] = $value;
        $payload['unchanged'] = $value === $currentAlt;

        return ['status' => MediaAiPrompts::STATUS_SUGGESTION] + $payload;
    }

    /**
     * After TYPO3 Save: AQG's structured alternative-text check of the stored references. A reference older than its
     * suggestion was not saved; in a workspace the check would read the live record, so it waits for publishing.
     *
     * @param list<array{referenceUid:int,suggestedAt:int}> $items
     * @return array{items:list<array{referenceUid:int,status:string}>}
     */
    public function verify(string $parentTable, int $parentUid, string $fieldName, array $items): array
    {
        if (!in_array($parentTable, self::PARENT_TABLES, true) || $parentUid <= 0 || !$this->isFileField($parentTable, $fieldName)
            || !$this->recordAccessService->canEditRecordFields($parentTable, $parentUid, [$fieldName])) {
            throw new EditorAiException('permission_denied', 'You may not edit this field.', 403);
        }
        $workspace = (int)($this->backendUserService->getBackendUser()?->workspace ?? 0);
        $rules = array_values(array_filter(array_map(fn (string $id) => $this->ruleRegistry->getById($id), FileMetadataAiService::VERIFY_RULE_IDS)));
        $parent = BackendUtility::getRecord($parentTable, $parentUid, 'uid,pid,sys_language_uid');
        $violations = $rules !== [] && is_array($parent) && $workspace === 0
            ? FileMetadataAiService::checkField($rules, (int)($parent['pid'] ?? 0), (int)($parent['sys_language_uid'] ?? 0), $parentTable, $parentUid, $fieldName)
            : [];

        $results = [];
        foreach (array_slice($items, 0, 20) as $item) {
            $referenceUid = (int)$item['referenceUid'];
            $reference = $referenceUid > 0 ? BackendUtility::getRecord(Tables::SYS_FILE_REFERENCE, $referenceUid, 'uid,uid_foreign,tablenames,fieldname,tstamp,deleted') : null;
            if (!is_array($reference) || (int)$reference['deleted'] === 1 || (int)$reference['uid_foreign'] !== $parentUid
                || (string)$reference['tablenames'] !== $parentTable || (string)$reference['fieldname'] !== $fieldName) {
                $results[] = ['referenceUid' => $referenceUid, 'status' => 'not_saved'];
                continue;
            }
            $status = match (true) {
                (int)$reference['tstamp'] < (int)$item['suggestedAt'] => 'not_saved',
                $workspace > 0 => 'workspace',
                $rules === [] => 'not_verified',
                FileMetadataAiService::reportsReference($violations, $referenceUid, $parentTable, $parentUid, $fieldName) => 'still_reported',
                default => 'verified',
            };
            $results[] = ['referenceUid' => $referenceUid, 'status' => $status];
        }

        return ['items' => $results];
    }

    /**
     * @param array{parentTable:string,parentUid:int,fieldName:string,referenceUid:int,fileUid:int,currentAlt:string,mode:string} $input
     * @return array{site:Site,parent:array<string,mixed>,file:MediaAiFile,referenceUid:int,languageUid:int,defaultAlt:string,pageTitle:string,reference:array<string,mixed>}
     */
    private function resolveTarget(array $input): array
    {
        $parentTable = $input['parentTable'];
        $parentUid = $input['parentUid'];
        $fieldName = $input['fieldName'];
        if (!in_array($parentTable, self::PARENT_TABLES, true) || !$this->isFileField($parentTable, $fieldName)) {
            throw new EditorAiException('invalid_input', 'Unsupported record or field.', 422);
        }
        if ($parentUid <= 0) {
            throw new EditorAiException('record_unsaved', 'Save the content element once before asking for a suggestion.', 422);
        }
        $parent = BackendUtility::getRecordWSOL($parentTable, $parentUid);
        if (!is_array($parent) || (int)($parent['deleted'] ?? 0) === 1) {
            throw new EditorAiException('record_not_found', 'The content element could not be found.', 404);
        }
        if (!$this->recordAccessService->canEditRecordFields($parentTable, $parentUid, [$fieldName])) {
            throw new EditorAiException('permission_denied', 'You may not edit this field.', 403);
        }
        if (!$this->canEditReferenceAlternative($input['referenceUid'])) {
            throw new EditorAiException('permission_denied', 'You may not edit the alternative text of this image.', 403);
        }

        $reference = [];
        $fileUid = $input['fileUid'];
        if ($input['referenceUid'] > 0) {
            $reference = BackendUtility::getRecordWSOL(Tables::SYS_FILE_REFERENCE, $input['referenceUid']) ?? [];
            $foreign = (int)($reference['uid_foreign'] ?? 0);
            $ownsReference = $foreign === $parentUid || $foreign === (int)($parent['_ORIG_uid'] ?? 0) || $foreign === (int)($parent['t3ver_oid'] ?? -1);
            if ($reference === [] || (int)($reference['deleted'] ?? 0) === 1 || !$ownsReference
                || (string)($reference['tablenames'] ?? '') !== $parentTable || (string)($reference['fieldname'] ?? '') !== $fieldName) {
                throw new EditorAiException('reference_mismatch', 'This image does not belong to this content element.', 409);
            }
            $storedFileUid = (int)($reference['uid_local'] ?? 0);
            if ($fileUid > 0 && $fileUid !== $storedFileUid) {
                // The form shows another file than the stored reference (file replaced but not saved yet).
                throw new EditorAiException('stale_reference', 'The image changed in the form. Save the record first.', 409);
            }
            $fileUid = $storedFileUid;
        }

        $mediaFile = $this->fileGuard->inspect($fileUid, $input['referenceUid'] > 0);
        if (!$mediaFile->isEligible()) {
            throw new EditorAiException(match ($mediaFile->status) {
                MediaAiFile::STATUS_UNSUPPORTED => 'unsupported_file',
                MediaAiFile::STATUS_TOO_LARGE => 'image_too_large',
                default => 'image_unavailable',
            }, 'The image cannot be used for a suggestion.', 422);
        }

        $pageUid = (int)($parent['pid'] ?? 0) > 0 ? (int)$parent['pid'] : (int)(BackendUtility::getRecord($parentTable, (int)($parent['t3ver_oid'] ?? 0), 'pid')['pid'] ?? 0);
        $site = $this->siteResolutionService->resolveSiteByPageId($pageUid);
        if (!$site instanceof Site) {
            throw new EditorAiException('no_site', 'The record is not inside a TYPO3 site.', 422);
        }
        $languageUid = (int)($parent['sys_language_uid'] ?? 0);
        $metadata = $this->metadataResolver->find($mediaFile->uid, max(0, $languageUid))
            ?? ($languageUid > 0 ? $this->metadataResolver->find($mediaFile->uid, 0) : null);
        $page = BackendUtility::getRecordWSOL(Tables::PAGES, $pageUid);

        return [
            'site' => $site,
            'parent' => $parent,
            'file' => $mediaFile,
            'referenceUid' => $input['referenceUid'],
            'languageUid' => $languageUid,
            'defaultAlt' => trim((string)($metadata['values']['alternative'] ?? '')),
            'pageTitle' => is_array($page) ? (string)($page['title'] ?? '') : '',
            'reference' => $reference,
        ];
    }

    /**
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private function translate(Site $site, array $target, string $locale): array
    {
        $reference = $target['reference'];
        $parentReferenceUid = (int)($reference['l10n_parent'] ?? 0);
        if ($target['languageUid'] <= 0 || $parentReferenceUid <= 0) {
            throw new EditorAiException('nothing_to_translate', 'Translations are drafted inside a translated content element.', 422);
        }
        $source = BackendUtility::getRecordWSOL(Tables::SYS_FILE_REFERENCE, $parentReferenceUid);
        $sourceAlt = is_array($source) ? trim((string)($source['alternative'] ?? '')) : '';
        if ($sourceAlt === '' && is_array($source)) {
            $sourceAlt = trim((string)($this->metadataResolver->find((int)($source['uid_local'] ?? 0), 0)['values']['alternative'] ?? ''));
        }
        if ($sourceAlt === '' || (int)($source['tx_a11y_is_decorative'] ?? 0) === 1) {
            throw new EditorAiException('nothing_to_translate', 'The default language has no alternative text for this image.', 422);
        }
        [$sourceLocale] = $this->siteResolver->locale($site, 0);
        $response = $this->taskRunner->run($site->getIdentifier(), MediaAiPayload::request(
            AiTask::TRANSLATION,
            MediaAiPrompts::TRANSLATION_PROMPT_VERSION,
            MediaAiPrompts::translationInstructions(),
            [
                'target_locale' => $locale,
                'source_locale' => $sourceLocale,
                'requested_fields' => [MediaAiFieldPolicy::FIELD_ALTERNATIVE],
                'source_alternative' => mb_substr($sourceAlt, 0, 500),
            ],
            MediaAiPrompts::translationOutputFormat(),
            self::MAX_OUTPUT_TOKENS,
        ));

        $payload = MediaAiPayload::provenance($response, MediaAiPrompts::TRANSLATION_PROMPT_VERSION, ['defaultLanguageText', 'language']) + [
            'mode' => 'translate',
            'source' => $sourceAlt,
            'value' => '',
            'reason' => MediaAiPayload::plain($response->data['notes'] ?? '', 400),
        ];
        if (($response->data['status'] ?? '') !== MediaAiPrompts::STATUS_SUGGESTION) {
            return ['status' => MediaAiPrompts::STATUS_NEEDS_REVIEW] + $payload;
        }
        [$value, $rejection] = $this->fieldPolicy->validate(MediaAiFieldPolicy::FIELD_ALTERNATIVE, (string)($response->data['alternative'] ?? ''));
        if ($rejection !== MediaAiFieldPolicy::VALID) {
            return ['status' => 'invalid_response', 'rejection' => $rejection] + $payload;
        }
        $payload['value'] = $value;

        return ['status' => MediaAiPrompts::STATUS_SUGGESTION] + $payload;
    }

    /** The reference's alternative text must be editable for this user (table, exclude field, record access). */
    private function canEditReferenceAlternative(int $referenceUid): bool
    {
        if ($referenceUid > 0) {
            return $this->recordAccessService->canEditRecordFields(Tables::SYS_FILE_REFERENCE, $referenceUid, [MediaAiFieldPolicy::FIELD_ALTERNATIVE]);
        }
        $backendUser = $this->backendUserService->getBackendUser();
        if ($backendUser === null) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        $excluded = (bool)($GLOBALS['TCA'][Tables::SYS_FILE_REFERENCE]['columns']['alternative']['exclude'] ?? false);

        return $backendUser->check('tables_modify', Tables::SYS_FILE_REFERENCE)
            && (!$excluded || $backendUser->check('non_exclude_fields', Tables::SYS_FILE_REFERENCE . ':alternative'));
    }

    private function isFileField(string $table, string $field): bool
    {
        if (preg_match('/^[a-z0-9_]{1,64}$/', $field) !== 1) {
            return false;
        }
        $config = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? [];

        return ($config['type'] ?? '') === 'file'
            || (($config['type'] ?? '') === 'inline' && ($config['foreign_table'] ?? '') === Tables::SYS_FILE_REFERENCE);
    }

    /**
     * What a linked image's link does, without sending a full URL: a page title (only when the site allows page titles
     * and the user may read that page) or a host name.
     *
     * @return array{0:string,1:bool} the description and whether it contains a page title
     */
    private function linkTarget(string $link, bool $allowPageTitle): array
    {
        $link = trim($link);
        if ($link === '') {
            return ['', false];
        }
        if (preg_match('/^t3:\/\/page\?uid=(\d+)/', $link, $match) === 1 || preg_match('/^(\d+)$/', $link, $match) === 1) {
            $backendUser = $this->backendUserService->getBackendUser();
            $page = $allowPageTitle && $backendUser !== null ? BackendUtility::readPageAccess((int)$match[1], $backendUser->getPagePermsClause(1)) : null;
            $title = is_array($page) ? MediaAiPayload::plain($page['title'] ?? '', 120) : '';

            return $title !== '' ? ['Page: ' . $title, true] : ['A page of this site', false];
        }
        if (str_starts_with($link, 't3://file')) {
            return ['A file download', false];
        }
        $host = parse_url($link, PHP_URL_HOST);

        return [is_string($host) && $host !== '' ? 'External site: ' . $host : 'A link', false];
    }
}
