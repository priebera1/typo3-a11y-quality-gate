<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Media;

use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiException;
use Priebera\A11yQualityGate\Ai\Service\AiRateLimiter;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredTaskRunner;
use Priebera\A11yQualityGate\Ai\Task\AiTask;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleRegistry;
use Priebera\A11yQualityGate\Service\BackendUserService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * AI metadata for files (the file default in sys_file_metadata): which files are eligible, suggestions for
 * alternative text, title and description, and the check after a save.
 *
 * Read-only by design: AQG has no write path into sys_file_metadata (UX Atlas open decision #1 is not taken). A
 * suggestion only fills the fields of TYPO3's metadata form in the browser; TYPO3 Save stores them with the user's
 * own permissions, history and workspace handling.
 */
final class FileMetadataAiService
{
    public const MAX_FILES_PER_REQUEST = 50;
    /** The upload follow-up checks at most this many new images (read-only pre-flight). */
    public const MAX_UPLOAD_FILES = 5;
    /** A per-user bucket on top of the per-site limit, so switching sites does not multiply the rate. */
    public const MEDIA_RATE_SCOPE = 'media';
    private const MAX_FILES_WITH_SVG_LABELS = 10;
    public const CONTEXT_SINGLE = 'single';
    public const CONTEXT_BULK = 'bulk';
    public const CONTEXT_UPLOAD = 'upload';

    private const METADATA_MAX_OUTPUT_TOKENS = 900;
    private const TRANSLATION_MAX_OUTPUT_TOKENS = 900;
    /** AQG's structured checks of image alternative text: missing text and text quality (file names, redundant phrases). */
    public const VERIFY_RULE_IDS = ['structured.file_reference_alt', 'structured.file_reference_alt_quality'];

    public function __construct(
        private readonly MediaAiFileGuard $fileGuard,
        private readonly FileMetadataRecordResolver $recordResolver,
        private readonly MediaAiSiteResolver $siteResolver,
        private readonly MediaAiImageLoader $imageLoader,
        private readonly SvgTextExtractor $svgTextExtractor,
        private readonly MediaAiFieldPolicy $fieldPolicy,
        private readonly EditorAiAccessService $accessService,
        private readonly AiStructuredTaskRunner $taskRunner,
        private readonly AiSiteSettingsService $siteSettingsService,
        private readonly BackendUserService $backendUserService,
        private readonly RuleRegistry $ruleRegistry,
        private readonly AiRateLimiter $rateLimiter,
        private readonly MediaAiUsagePrivacy $usagePrivacy,
    ) {}

    /**
     * Pre-flight without AI: eligibility, the stored values and uses of every requested file, the usable sites.
     *
     * @param list<int> $fileUids
     * @return array<string,mixed>
     */
    public function state(array $fileUids, int $languageUid, string $siteIdentifier, string $context): array
    {
        $this->assertUserFlags($context);
        $fileUids = array_values(array_unique(array_filter(array_map('intval', $fileUids), static fn (int $uid): bool => $uid > 0)));
        if ($fileUids === [] || count($fileUids) > self::MAX_FILES_PER_REQUEST || $languageUid < 0) {
            throw new EditorAiException('invalid_input', 'Select between 1 and 50 files.', 422);
        }
        if ($context === self::CONTEXT_UPLOAD && count($fileUids) > self::MAX_UPLOAD_FILES) {
            throw new EditorAiException('bulk_not_permitted', 'The upload follow-up covers at most five images.', 403);
        }

        $files = [];
        $usePages = [];
        foreach ($fileUids as $fileUid) {
            $mediaFile = $this->fileGuard->inspect($fileUid);
            $entry = $mediaFile->toArray() + ['metadata' => null, 'translationMissing' => false, 'uses' => null, 'svgLabels' => [], 'languages' => [], 'previewUrl' => ''];
            if ($mediaFile->isEligible()) {
                $entry['previewUrl'] = $this->previewUrl($mediaFile);
                $record = $this->recordResolver->find($fileUid, $languageUid);
                $entry['metadata'] = $this->metadataView($record);
                $entry['translationMissing'] = $languageUid > 0 && $record === null;
                $entry['languages'] = $this->recordResolver->languages($fileUid);
                $uses = $this->recordResolver->uses($fileUid, $languageUid);
                unset($uses['inheritingItems']);
                $entry['uses'] = $uses;
                foreach ($uses['items'] as $use) {
                    $usePages[] = $use['pageUid'];
                }
                // Labels cost a file read each; a large selection gets them when a single file is suggested.
                $entry['svgLabels'] = count($fileUids) <= self::MAX_FILES_WITH_SVG_LABELS ? $this->imageLoader->svgLabels($mediaFile, $this->svgTextExtractor) : [];
                if ($mediaFile->editableFields === []) {
                    $entry['status'] = MediaAiFile::STATUS_READ_ONLY;
                }
            }
            $files[] = $entry;
        }

        $sites = $this->siteResolver->candidates($usePages);
        $site = null;
        try {
            $site = $this->siteResolver->resolve($siteIdentifier, $sites);
        } catch (EditorAiException) {
            // The browser offers the choice (or explains that AI is not available).
        }

        return [
            'files' => $files,
            'sites' => $sites,
            'site' => $site?->getIdentifier() ?? '',
            'languages' => $site instanceof Site ? $this->siteResolver->languages($site) : [],
            'languageUid' => $languageUid,
            'bulkAllowed' => $this->accessService->userAllows(EditorAiAccessService::FLAG_BULK),
            'workspace' => (int)($this->backendUserService->getBackendUser()?->workspace ?? 0),
            'limits' => ['maxFiles' => self::MAX_FILES_PER_REQUEST, 'requestsPerMinute' => 10],
        ];
    }

    /**
     * @param list<mixed> $fields
     * @return array<string,mixed>
     */
    public function suggest(int $fileUid, int $languageUid, array $fields, string $siteIdentifier, string $mode, string $context): array
    {
        $this->assertUserFlags($context);
        $this->assertLanguage($languageUid);
        $site = $this->siteResolver->resolve($siteIdentifier);
        $this->accessService->assertReady($site->getIdentifier(), EditorAiAccessService::FLAG_IMAGES);
        $mediaFile = $this->eligibleFile($fileUid);
        $fields = array_values(array_intersect(MediaAiFieldPolicy::filterFields($fields), $mediaFile->editableFields));
        if ($fields === []) {
            throw new EditorAiException('permission_denied', 'You may not edit these metadata fields of this file.', 403);
        }
        [$locale, $languageTitle] = $this->siteResolver->locale($site, $languageUid);
        if ($locale === '') {
            throw new EditorAiException('language_unavailable', 'This language does not exist on the chosen site.', 422);
        }
        $record = $this->recordResolver->find($fileUid, $languageUid);
        if ($languageUid > 0 && $record === null) {
            throw new EditorAiException('translation_missing', 'There is no metadata record in this language yet.', 422);
        }
        $current = $record['values'] ?? array_fill_keys(MediaAiFieldPolicy::METADATA_FIELDS, '');

        if ($mode === 'translate') {
            return $this->translate($site, $mediaFile, $languageUid, $locale, $languageTitle, $fields, $current, $record);
        }

        $settings = $this->siteSettingsService->get($site->getIdentifier());
        if (!$settings->allowImageInput) {
            throw new EditorAiException('privacy_images', 'This site does not allow sending images to AI providers.', 422);
        }
        // D-08: the file default is shown on every site that uses the file, so their image switches apply as well —
        // choosing another site's settings never sends an image those sites forbid.
        $this->usagePrivacy->assertImageInputAllowed($fileUid);
        $this->rateLimiter->assertAllowed(self::MEDIA_RATE_SCOPE, null, self::MEDIA_RATE_SCOPE);
        $image = $this->imageLoader->load($mediaFile);
        $svgLabels = $this->imageLoader->svgLabels($mediaFile, $this->svgTextExtractor);

        $untrusted = [
            'target_locale' => $locale,
            'requested_fields' => $fields,
            'file_name' => $mediaFile->name,
            'folder_name' => $mediaFile->folderName,
            'image_width' => $mediaFile->width,
            'image_height' => $mediaFile->height,
        ];
        $sent = ['image', 'fileName', 'folderName', 'metadataLanguage'];
        foreach ($fields as $field) {
            $untrusted['current_' . $field] = mb_substr($current[$field] ?? '', 0, 1000);
        }
        if (array_filter(array_intersect_key($current, array_flip($fields))) !== []) {
            $sent[] = 'existingValues';
        }
        if ($svgLabels !== []) {
            $untrusted['svg_labels'] = $svgLabels;
            $sent[] = 'svgLabels';
        }

        $response = $this->taskRunner->run($site->getIdentifier(), MediaAiPayload::request(
            AiTask::FILE_METADATA,
            MediaAiPrompts::METADATA_PROMPT_VERSION,
            MediaAiPrompts::metadataInstructions(),
            $untrusted,
            MediaAiPrompts::metadataOutputFormat(),
            self::METADATA_MAX_OUTPUT_TOKENS,
            $image,
        ));

        $status = (string)($response->data['status'] ?? '');
        $imageKind = in_array($response->data['image_kind'] ?? '', MediaAiPrompts::IMAGE_KINDS, true) ? (string)$response->data['image_kind'] : 'other';
        $payload = MediaAiPayload::provenance($response, MediaAiPrompts::METADATA_PROMPT_VERSION, $sent) + [
            'fileUid' => $fileUid,
            'languageUid' => $languageUid,
            'languageTitle' => $languageTitle,
            'mode' => 'image',
            'imageKind' => $imageKind,
            'complex' => ($response->data['complex'] ?? false) === true || in_array($imageKind, MediaAiPrompts::COMPLEX_KINDS, true),
            'uncertain' => MediaAiPayload::plain($response->data['uncertain'] ?? '', 300),
            'reason' => MediaAiPayload::plain($response->data['reason'] ?? '', 400),
            'svgLabels' => $svgLabels,
        ];

        return $this->proposals($status, $fields, $current, $response->data, $record, $payload);
    }

    /**
     * Verify after a TYPO3 Save with AQG's structured alternative-text check, on the uses that inherit this file default.
     * Uses with their own text are not affected by the file default and are not counted.
     *
     * @return array<string,mixed>
     */
    public function verify(int $fileUid, int $languageUid): array
    {
        $this->assertUserFlags(self::CONTEXT_SINGLE);
        $this->assertLanguage($languageUid);
        $mediaFile = $this->fileGuard->inspect($fileUid);
        if (!$mediaFile->isEligible()) {
            throw new EditorAiException('image_unavailable', 'The file is not available.', 422);
        }
        if ((int)($this->backendUserService->getBackendUser()?->workspace ?? 0) > 0) {
            return ['status' => 'workspace', 'checked' => 0, 'reported' => 0];
        }
        $rules = array_values(array_filter(array_map(fn (string $id) => $this->ruleRegistry->getById($id), self::VERIFY_RULE_IDS)));
        $uses = $this->recordResolver->uses($fileUid, $languageUid);
        $inheriting = $uses['inheritingItems'];
        if ($rules === [] || $inheriting === []) {
            return ['status' => $uses['inheriting'] > 0 ? 'not_verified' : 'no_uses', 'checked' => 0, 'reported' => 0, 'hiddenUses' => $uses['hidden']];
        }

        $checked = 0;
        $reported = 0;
        $byParent = [];
        foreach ($inheriting as $use) {
            $byParent[$use['table'] . ':' . $use['uid'] . ':' . $use['field']][] = $use;
        }
        foreach ($byParent as $parentUses) {
            $first = $parentUses[0];
            $violations = self::checkField($rules, $first['pageUid'], $first['languageUid'], $first['table'], $first['uid'], $first['field']);
            foreach ($parentUses as $use) {
                $checked++;
                if (self::reportsReference($violations, $use['referenceUid'], $use['table'], $use['uid'], $use['field'])) {
                    $reported++;
                }
            }
        }

        return [
            'status' => $reported === 0 ? 'verified' : 'still_reported',
            'checked' => $checked,
            'reported' => $reported,
            'hiddenUses' => $uses['hidden'],
        ];
    }

    /**
     * Runs AQG's structured image checks on one file field of one record, as a content scan would.
     *
     * @param list<\Priebera\A11yQualityGate\Rule\RuleInterface> $rules
     * @return list<\Priebera\A11yQualityGate\Rule\RuleViolation>
     */
    public static function checkField(array $rules, int $pageUid, int $languageUid, string $table, int $uid, string $field): array
    {
        $context = new CheckContext(
            siteIdentifier: '',
            pageUid: $pageUid,
            sourceLangUid: max(0, $languageUid),
            sourceTable: $table,
            sourceUid: $uid,
            sourceField: $field,
            content: '',
        );
        $violations = [];
        foreach ($rules as $rule) {
            if ($rule->supports($context)) {
                array_push($violations, ...$rule->check($context));
            }
        }

        return $violations;
    }

    /**
     * Whether a structured image check result still reports this reference. A grouped result (many images in
     * one field) names only the field, so it counts as reporting every reference of that field.
     *
     * @param iterable<\Priebera\A11yQualityGate\Rule\RuleViolation> $violations
     */
    public static function reportsReference(iterable $violations, int $referenceUid, string $table, int $uid, string $field): bool
    {
        $fieldPath = sprintf('%s:%d > %s', $table, $uid, $field);
        foreach ($violations as $violation) {
            $path = (string)$violation->contextPath;
            if ($path === $fieldPath . ' > ref:' . $referenceUid || $path === $fieldPath) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $fields
     * @param array<string,string> $current
     * @param array{uid:int,languageUid:int,parentUid:int,tstamp:int,workspace:int,values:array<string,string>}|null $record
     * @return array<string,mixed>
     */
    private function translate(Site $site, MediaAiFile $mediaFile, int $languageUid, string $locale, string $languageTitle, array $fields, array $current, ?array $record): array
    {
        if ($languageUid <= 0 || $record === null) {
            throw new EditorAiException('nothing_to_translate', 'Translations are drafted inside a translated metadata record.', 422);
        }
        $source = $this->recordResolver->find($mediaFile->uid, 0);
        $sourceValues = array_filter(array_intersect_key($source['values'] ?? [], array_flip($fields)), static fn (string $value): bool => trim($value) !== '');
        if ($sourceValues === []) {
            throw new EditorAiException('nothing_to_translate', 'The default language has no text to translate.', 422);
        }
        [$sourceLocale] = $this->siteResolver->locale($site, 0);
        $this->rateLimiter->assertAllowed(self::MEDIA_RATE_SCOPE, null, self::MEDIA_RATE_SCOPE);

        $untrusted = ['target_locale' => $locale, 'source_locale' => $sourceLocale, 'requested_fields' => array_keys($sourceValues)];
        foreach ($sourceValues as $field => $value) {
            $untrusted['source_' . $field] = mb_substr($value, 0, 1500);
        }
        $response = $this->taskRunner->run($site->getIdentifier(), MediaAiPayload::request(
            AiTask::TRANSLATION,
            MediaAiPrompts::TRANSLATION_PROMPT_VERSION,
            MediaAiPrompts::translationInstructions(),
            $untrusted,
            MediaAiPrompts::translationOutputFormat(),
            self::TRANSLATION_MAX_OUTPUT_TOKENS,
        ));

        $payload = MediaAiPayload::provenance($response, MediaAiPrompts::TRANSLATION_PROMPT_VERSION, ['defaultLanguageText', 'metadataLanguage']) + [
            'fileUid' => $mediaFile->uid,
            'languageUid' => $languageUid,
            'languageTitle' => $languageTitle,
            'mode' => 'translate',
            'imageKind' => '',
            'complex' => false,
            'uncertain' => '',
            'reason' => MediaAiPayload::plain($response->data['notes'] ?? '', 400),
            'svgLabels' => [],
            'source' => $sourceValues,
        ];

        return $this->proposals((string)($response->data['status'] ?? ''), array_keys($sourceValues), $current, $response->data, $record, $payload);
    }

    /**
     * @param list<string> $fields
     * @param array<string,string> $current
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $record
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function proposals(string $status, array $fields, array $current, array $data, ?array $record, array $payload): array
    {
        $payload += $this->metadataView($record);
        $payload['proposals'] = [];
        if ($status === MediaAiPrompts::STATUS_DECORATIVE || $status === MediaAiPrompts::STATUS_NEEDS_REVIEW) {
            return ['status' => $status] + $payload;
        }
        if ($status !== MediaAiPrompts::STATUS_SUGGESTION) {
            return ['status' => 'invalid_response'] + $payload;
        }

        $valid = 0;
        foreach ($fields as $field) {
            $raw = is_string($data[$field] ?? null) ? $data[$field] : '';
            if (trim($raw) === '') {
                continue;
            }
            [$value, $rejection] = $this->fieldPolicy->validate($field, $raw);
            $currentValue = $current[$field] ?? '';
            $payload['proposals'][$field] = [
                'value' => $value,
                'rejection' => $rejection,
                'unchanged' => $rejection === MediaAiFieldPolicy::VALID && $value === trim($currentValue),
                'current' => $currentValue,
                'recommendedLength' => MediaAiFieldPolicy::RECOMMENDED_LENGTH[$field] ?? 0,
            ];
            if ($rejection === MediaAiFieldPolicy::VALID) {
                $valid++;
            }
        }

        return ['status' => $valid > 0 ? MediaAiPrompts::STATUS_SUGGESTION : 'invalid_response'] + $payload;
    }

    /**
     * The stored values as the browser may see them (read-only).
     *
     * @param array<string,mixed>|null $record
     * @return array<string,mixed>
     */
    private function metadataView(?array $record): array
    {
        return [
            'metadataUid' => (int)($record['uid'] ?? 0),
            'values' => $record['values'] ?? array_fill_keys(MediaAiFieldPolicy::METADATA_FIELDS, ''),
            'recordWorkspace' => (int)($record['workspace'] ?? 0),
        ];
    }

    /** A small thumbnail the user may see (public storages only; private files show no preview). */
    private function previewUrl(MediaAiFile $mediaFile): string
    {
        $file = $mediaFile->file;
        if (!$file instanceof File || !$file->getStorage()->isPublic()) {
            return '';
        }
        try {
            $url = $mediaFile->kind === MediaAiFile::KIND_SVG
                ? $file->getPublicUrl()
                : $file->process(ProcessedFile::CONTEXT_IMAGEPREVIEW, ['width' => 320, 'height' => 320])->getPublicUrl();
        } catch (\Throwable) {
            return '';
        }

        return is_string($url) ? $url : '';
    }

    private function eligibleFile(int $fileUid): MediaAiFile
    {
        $mediaFile = $this->fileGuard->inspect($fileUid);
        if (!$mediaFile->isEligible()) {
            throw new EditorAiException(match ($mediaFile->status) {
                MediaAiFile::STATUS_UNSUPPORTED => 'unsupported_file',
                MediaAiFile::STATUS_TOO_LARGE => 'image_too_large',
                default => 'image_unavailable',
            }, 'The file cannot be used for AI metadata.', 422);
        }
        if ($mediaFile->editableFields === []) {
            throw new EditorAiException('read_only', 'You may not edit the metadata of this file.', 403);
        }

        return $mediaFile;
    }

    private function assertLanguage(int $languageUid): void
    {
        if ($languageUid < 0) {
            throw new EditorAiException('invalid_input', 'Choose one metadata language.', 422);
        }
    }

    private function assertUserFlags(string $context): void
    {
        if (!$this->accessService->userAllows(EditorAiAccessService::FLAG_IMAGES)) {
            throw new EditorAiException(EditorAiAccessService::STATE_NO_PERMISSION, 'Image AI is not enabled for your user group.', 403);
        }
        if ($context === self::CONTEXT_BULK && !$this->accessService->userAllows(EditorAiAccessService::FLAG_BULK)) {
            throw new EditorAiException('bulk_not_permitted', 'Bulk suggestions are not enabled for your user group.', 403);
        }
    }
}
