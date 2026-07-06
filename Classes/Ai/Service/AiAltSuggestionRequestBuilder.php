<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionRequest;
use Priebera\A11yQualityGate\Ai\Dto\AiImagePayload;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class AiAltSuggestionRequestBuilder
{
    private const PAGE_TITLE_LIMIT = 160;
    private const CONTENT_TITLE_LIMIT = 160;
    private const CAPTION_LIMIT = 240;
    private const CURRENT_ALT_LIMIT = 1024;
    private const LINK_PURPOSE_LIMIT = 200;

    private const MODEL_QUALITY_REASON_MAP = [
        'img_alt_is_filename' => 'filename_only',
        'img_alt_redundant_phrase' => 'redundant_intro',
        'img_alt_too_long' => 'too_long',
        'img_alt_quality_other' => 'other_quality_issue',
    ];

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly ConnectionPool $connectionPool,
        private readonly AiContextSanitizer $sanitizer,
        private readonly AiFindingMetadataResolver $findingMetadataResolver,
    ) {}

    public function build(ImageFindingContext $context, AiImagePayload $imagePayload): AiAltSuggestionRequest
    {
        $sourceRecord = $this->loadSourceRecord($context);
        $languageUid = $this->resolveEffectiveLanguageUid($context, $sourceRecord);
        $findingMetadata = $this->findingMetadataResolver->resolve($context);
        [$isLinked, $linkPurpose] = $this->resolveLinkedContext($context, $languageUid);

        return new AiAltSuggestionRequest(
            dataUrl: $imagePayload->dataUrl,
            mimeType: $imagePayload->mimeType,
            targetLocale: $this->resolveTargetLocale($context->siteIdentifier, $languageUid),
            findingType: $findingMetadata->findingType,
            currentAlt: $this->sanitizer->sanitizeNullable($findingMetadata->currentAlt, self::CURRENT_ALT_LIMIT, false),
            qualityReason: $this->mapQualityReason(
                $findingMetadata->findingType,
                $findingMetadata->qualityReason,
            ),
            pageTitle: $this->sanitizer->sanitizeNullable(
                $this->resolvePageTitle($context->pageUid, $languageUid),
                self::PAGE_TITLE_LIMIT,
            ),
            contentTitle: $this->sanitizer->sanitizeNullable(
                $this->resolveContentTitle($context, $sourceRecord),
                self::CONTENT_TITLE_LIMIT,
            ),
            caption: $this->sanitizer->sanitizeNullable(
                $this->resolveCaption($context, $sourceRecord),
                self::CAPTION_LIMIT,
            ),
            isLinked: $isLinked,
            linkPurpose: $this->sanitizer->sanitizeNullable($linkPurpose, self::LINK_PURPOSE_LIMIT),
        );
    }

    /** @return array<string,mixed> */
    private function loadSourceRecord(ImageFindingContext $context): array
    {
        if ($context->sourceTable === '' || $context->sourceUid <= 0) {
            return [];
        }

        try {
            $record = BackendUtility::getRecord($context->sourceTable, $context->sourceUid, '*');
        } catch (\Throwable) {
            return [];
        }

        return is_array($record) ? $record : [];
    }

    /** @param array<string,mixed> $sourceRecord */
    private function resolveEffectiveLanguageUid(ImageFindingContext $context, array $sourceRecord): int
    {
        $sourceLanguageUid = $this->integerLanguageUid($sourceRecord['sys_language_uid'] ?? null);
        if ($sourceLanguageUid > 0) {
            return $sourceLanguageUid;
        }

        if ($context->languageUid > 0) {
            return $context->languageUid;
        }

        $referenceLanguageUid = $this->integerLanguageUid($context->fileReference['sys_language_uid'] ?? null);
        if ($referenceLanguageUid > 0) {
            return $referenceLanguageUid;
        }

        return 0;
    }

    private function integerLanguageUid(mixed $value): int
    {
        return is_scalar($value) && preg_match('/^-?\d+$/', trim((string)$value)) === 1
            ? (int)$value
            : 0;
    }

    private function resolveTargetLocale(string $siteIdentifier, int $languageUid): string
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
            foreach ($site->getAllLanguages() as $language) {
                if ($language->getLanguageId() !== $languageUid) {
                    continue;
                }

                $locale = $language->getLocale();
                $fullLocale = $this->normalizeBcp47Locale($locale->getName());
                if ($fullLocale !== '') {
                    return $fullLocale;
                }

                $primaryLanguage = $this->normalizePrimaryLanguageSubtag($locale->getLanguageCode());
                if ($primaryLanguage !== '') {
                    return $primaryLanguage;
                }
            }

            $defaultLocale = $site->getDefaultLanguage()->getLocale();
            $fullDefaultLocale = $this->normalizeBcp47Locale($defaultLocale->getName());
            if ($fullDefaultLocale !== '') {
                return $fullDefaultLocale;
            }

            $primaryDefaultLanguage = $this->normalizePrimaryLanguageSubtag($defaultLocale->getLanguageCode());
            if ($primaryDefaultLanguage !== '') {
                return $primaryDefaultLanguage;
            }
        } catch (\Throwable) {
        }

        return 'en';
    }

    private function normalizeBcp47Locale(string $locale): string
    {
        $locale = str_replace('_', '-', trim($locale));
        if ($locale === '' || preg_match('/^[A-Za-z0-9-]+$/D', $locale) !== 1) {
            return '';
        }

        $subtags = explode('-', $locale);
        $primaryLanguage = strtolower((string)array_shift($subtags));
        if (preg_match('/^[a-z]{2,8}$/D', $primaryLanguage) !== 1) {
            return '';
        }

        $normalized = [$primaryLanguage];
        foreach ($subtags as $index => $subtag) {
            if ($subtag === '') {
                return '';
            }

            if ($index === 0 && preg_match('/^[A-Za-z]{4}$/D', $subtag) === 1) {
                $normalized[] = ucfirst(strtolower($subtag));
                continue;
            }

            if (preg_match('/^[A-Za-z]{2}$/D', $subtag) === 1) {
                $normalized[] = strtoupper($subtag);
                continue;
            }

            if (preg_match('/^[0-9]{3}$/D', $subtag) === 1) {
                $normalized[] = $subtag;
                continue;
            }

            if (preg_match('/^(?:[A-Za-z0-9]{5,8}|[0-9][A-Za-z0-9]{3})$/D', $subtag) === 1) {
                $normalized[] = strtolower($subtag);
                continue;
            }

            return '';
        }

        return implode('-', $normalized);
    }

    private function normalizePrimaryLanguageSubtag(string $languageCode): string
    {
        $normalized = strtolower(str_replace('_', '-', trim($languageCode)));
        if ($normalized === '') {
            return '';
        }

        $primarySubtag = explode('-', $normalized, 2)[0];

        return preg_match('/^[a-z]{2,8}$/D', $primarySubtag) === 1
            ? $primarySubtag
            : '';
    }

    private function mapQualityReason(string $findingType, ?string $qualityReason): ?string
    {
        if ($findingType !== 'quality') {
            return null;
        }

        $mappedReason = self::MODEL_QUALITY_REASON_MAP[trim((string)$qualityReason)] ?? null;
        if ($mappedReason === null) {
            throw new \InvalidArgumentException('Unsupported AI quality reason.', 1771002701);
        }

        return $mappedReason;
    }

    private function resolvePageTitle(int $pageUid, int $languageUid): string
    {
        if ($pageUid <= 0) {
            return '';
        }

        try {
            $page = BackendUtility::getRecord('pages', $pageUid, 'uid,title,sys_language_uid,l10n_parent');
        } catch (\Throwable) {
            $page = null;
        }

        if (!is_array($page)) {
            return '';
        }

        $pageLanguageUid = (int)($page['sys_language_uid'] ?? 0);
        if ($languageUid <= 0 || $pageLanguageUid === $languageUid) {
            return trim((string)($page['title'] ?? ''));
        }

        $defaultPageUid = $pageLanguageUid > 0
            ? (int)($page['l10n_parent'] ?? 0)
            : (int)($page['uid'] ?? $pageUid);
        if ($defaultPageUid <= 0) {
            $defaultPageUid = $pageUid;
        }

        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $overlay = $queryBuilder
                ->select('title')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq(
                        'l10n_parent',
                        $queryBuilder->createNamedParameter($defaultPageUid, Connection::PARAM_INT),
                    ),
                    $queryBuilder->expr()->eq(
                        'sys_language_uid',
                        $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT),
                    ),
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
            if (is_array($overlay) && trim((string)($overlay['title'] ?? '')) !== '') {
                return trim((string)$overlay['title']);
            }
        } catch (\Throwable) {
        }

        return trim((string)($page['title'] ?? ''));
    }

    /** @param array<string,mixed> $sourceRecord */
    private function resolveContentTitle(ImageFindingContext $context, array $sourceRecord): string
    {
        if ($sourceRecord === []) {
            return '';
        }

        $candidateFields = [];
        $labelField = trim((string)($GLOBALS['TCA'][$context->sourceTable]['ctrl']['label'] ?? ''));
        if ($labelField !== '') {
            $candidateFields[] = $labelField;
        }
        array_push($candidateFields, 'header', 'title', 'name');

        foreach (array_values(array_unique($candidateFields)) as $field) {
            $value = $sourceRecord[$field] ?? null;
            if (is_scalar($value) && trim((string)$value) !== '') {
                return (string)$value;
            }
        }

        return '';
    }

    /** @param array<string,mixed> $sourceRecord */
    private function resolveCaption(ImageFindingContext $context, array $sourceRecord): string
    {
        foreach (['description', 'title'] as $field) {
            $value = $context->fileReference[$field] ?? null;
            if (is_scalar($value) && trim((string)$value) !== '') {
                return (string)$value;
            }
        }

        foreach (['imagecaption', 'caption', 'media_caption'] as $field) {
            $value = $sourceRecord[$field] ?? null;
            if (is_scalar($value) && trim((string)$value) !== '') {
                return (string)$value;
            }
        }

        return '';
    }

    /** @return array{0:?bool,1:?string} */
    private function resolveLinkedContext(ImageFindingContext $context, int $languageUid): array
    {
        $rawLink = trim((string)($context->fileReference['link'] ?? ''));
        if ($rawLink === '') {
            return [null, null];
        }

        try {
            $resolved = GeneralUtility::makeInstance(LinkService::class)->resolve($rawLink);
        } catch (\Throwable) {
            return [true, null];
        }

        if (!is_array($resolved)) {
            return [true, null];
        }

        $type = strtolower(trim((string)($resolved['type'] ?? '')));
        $purpose = $type === 'page'
            ? $this->resolvePageTitle((int)($resolved['pageuid'] ?? 0), $languageUid)
            : '';

        return [true, trim($purpose) !== '' ? $purpose : null];
    }
}
