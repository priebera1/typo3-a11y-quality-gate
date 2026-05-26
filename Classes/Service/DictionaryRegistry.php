<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class DictionaryRegistry
{
    private const MAX_REQUEST_CACHE_ENTRIES = 500;
    private const DICTIONARY_PATH = 'EXT:a11y_quality_gate/Resources/Private/Dictionaries/';
    private const DEFAULT_LANGUAGE_CODE = 'en';

    /**
     * @var array<string, list<string>>
     */
    private array $resolvedPhraseCache = [];

    public function __construct(
        private readonly SiteResolutionService $siteResolutionService,
        private readonly RulesetRepository $rulesetRepository,
    ) {
    }

    /**
     * Resolves normalized dictionary phrases for the given rule and scan context.
     *
     * @return list<string>
     */
    public function resolveForContext(string $ruleId, CheckContext $context): array
    {
        $site = $this->resolveSite($context);
        $rulesetDictionary = $this->resolveRulesetDictionarySettings($context->siteIdentifier);
        $mode = $this->resolveDictionaryMode($site, $rulesetDictionary);

        if ($mode === 'disable') {
            return [];
        }

        $languageCode = $mode === 'force'
            ? $this->resolveForcedLanguageCode($site, $rulesetDictionary)
            : $this->resolveLanguageCode($site, $context->sourceLangUid);

        if ($languageCode === '') {
            $languageCode = self::DEFAULT_LANGUAGE_CODE;
        }

        $cacheKey = sha1(implode('|', [
            $ruleId,
            $languageCode,
            $context->siteIdentifier,
            (string)$context->sourceLangUid,
            $site?->getIdentifier() ?? '',
            $mode,
            sha1(json_encode($rulesetDictionary, JSON_THROW_ON_ERROR)),
        ]));

        if (isset($this->resolvedPhraseCache[$cacheKey])) {
            return $this->resolvedPhraseCache[$cacheKey];
        }

        $phrases = $this->loadBuiltIn($ruleId, $languageCode);

        if ($phrases === [] && $languageCode !== self::DEFAULT_LANGUAGE_CODE) {
            $phrases = $this->loadBuiltIn($ruleId, self::DEFAULT_LANGUAGE_CODE);
        }

        $phrases = $this->applyRulesetOverrides($phrases, $ruleId, $rulesetDictionary);
        $phrases = $this->applySiteOverrides($phrases, $ruleId, $site);
        $phrases = $this->normalizePhrases($phrases);

        if (count($this->resolvedPhraseCache) >= self::MAX_REQUEST_CACHE_ENTRIES) {
            $this->resolvedPhraseCache = [];
        }

        $this->resolvedPhraseCache[$cacheKey] = $phrases;

        return $phrases;
    }

    private function resolveSite(CheckContext $context): ?Site
    {
        $site = $this->siteResolutionService->resolveSiteByIdentifier($context->siteIdentifier);
        if ($site instanceof Site) {
            return $site;
        }

        return $this->siteResolutionService->resolveSiteByPageId($context->pageUid);
    }

    /**
     * @param array<string, mixed> $rulesetDictionary
     */
    private function resolveDictionaryMode(?Site $site, array $rulesetDictionary): string
    {
        $mode = strtolower(trim((string)($rulesetDictionary['mode'] ?? '')));
        if ($mode === '') {
            $mode = strtolower(trim((string)$this->getSiteSetting(
                $site,
                'a11yQualityGate.dictionary.mode',
                'auto'
            )));
        }

        return in_array($mode, ['auto', 'force', 'disable'], true) ? $mode : 'auto';
    }

    /**
     * @param array<string, mixed> $rulesetDictionary
     */
    private function resolveForcedLanguageCode(?Site $site, array $rulesetDictionary): string
    {
        $languageCode = strtolower(trim((string)($rulesetDictionary['forceLanguage'] ?? '')));
        if ($languageCode === '') {
            $languageCode = strtolower(trim((string)$this->getSiteSetting(
                $site,
                'a11yQualityGate.dictionary.forceLanguage',
                ''
            )));
        }

        return $this->normalizeLanguageCode($languageCode);
    }

    private function resolveLanguageCode(?Site $site, int $languageUid): string
    {
        if (!$site instanceof Site) {
            return self::DEFAULT_LANGUAGE_CODE;
        }

        $languageUid = max(0, $languageUid);

        try {
            $language = $site->getLanguageById($languageUid);
            if ($language instanceof SiteLanguage) {
                return $this->normalizeLanguageCode((string)$language->getLocale());
            }
        } catch (\Throwable) {
            // Fall back to iterating languages below.
        }

        foreach ($site->getLanguages() as $language) {
            if ((int)$language->getLanguageId() !== $languageUid) {
                continue;
            }

            return $this->normalizeLanguageCode((string)$language->getLocale());
        }

        return self::DEFAULT_LANGUAGE_CODE;
    }

    private function normalizeLanguageCode(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $parts = preg_split('/[-_]/', $value);
        $code = is_array($parts) ? trim((string)($parts[0] ?? '')) : '';

        return preg_match('/^[a-z]{2}$/', $code) === 1 ? $code : '';
    }

    /**
     * @return list<string>
     */
    private function loadBuiltIn(string $ruleId, string $languageCode): array
    {
        $filePath = GeneralUtility::getFileAbsFileName(self::DICTIONARY_PATH . str_replace('.', '_', $ruleId) . '.yaml');
        if ($filePath === '' || !is_file($filePath)) {
            return [];
        }

        try {
            $data = Yaml::parseFile($filePath);
        } catch (\Throwable) {
            return [];
        }

        $phrases = $data['phrases'][$languageCode] ?? [];
        if (!is_array($phrases)) {
            return [];
        }

        return $this->sanitizePhraseList($phrases);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRulesetDictionarySettings(string $siteIdentifier): array
    {
        $ruleset = $this->rulesetRepository->findForSiteOrDefault($siteIdentifier);
        if (!is_array($ruleset)) {
            return [];
        }

        $rulesJson = trim((string)($ruleset['rules_json'] ?? ''));
        if ($rulesJson === '') {
            return [];
        }

        try {
            $configuration = json_decode($rulesJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($configuration['dictionary'] ?? null) ? $configuration['dictionary'] : [];
    }

    /**
     * @param list<string> $phrases
     * @param array<string, mixed> $rulesetDictionary
     * @return list<string>
     */
    private function applyRulesetOverrides(array $phrases, string $ruleId, array $rulesetDictionary): array
    {
        if ($ruleId !== 'rte.non_descriptive_link' || $rulesetDictionary === []) {
            return $phrases;
        }

        $additional = $this->sanitizePhraseList($rulesetDictionary['nonDescriptiveAdditional'] ?? []);
        if ($additional !== []) {
            $phrases = array_merge($phrases, $additional);
        }

        $disabled = $this->normalizePhrases($this->sanitizePhraseList($rulesetDictionary['nonDescriptiveDisabled'] ?? []));
        if ($disabled !== []) {
            $phrases = array_values(array_filter(
                $phrases,
                static fn(string $phrase): bool => !in_array(PhraseMatcher::normalize($phrase), $disabled, true)
            ));
        }

        return $phrases;
    }

    /**
     * @param list<string> $phrases
     * @return list<string>
     */
    private function applySiteOverrides(array $phrases, string $ruleId, ?Site $site): array
    {
        if (!$site instanceof Site) {
            return $phrases;
        }

        $ruleKey = str_replace('.', '_', $ruleId);
        $additional = $this->sanitizePhraseList($this->getSiteSetting(
            $site,
            'a11yQualityGate.dictionary.' . $ruleKey . '.additionalPhrases',
            []
        ));
        $disabled = $this->normalizePhrases($this->sanitizePhraseList($this->getSiteSetting(
            $site,
            'a11yQualityGate.dictionary.' . $ruleKey . '.disabledPhrases',
            []
        )));

        if ($additional !== []) {
            $phrases = array_merge($phrases, $additional);
        }

        if ($disabled !== []) {
            $phrases = array_values(array_filter(
                $phrases,
                static fn(string $phrase): bool => !in_array(PhraseMatcher::normalize($phrase), $disabled, true)
            ));
        }

        return $phrases;
    }

    private function getSiteSetting(?Site $site, string $key, mixed $default): mixed
    {
        if (!$site instanceof Site) {
            return $default;
        }

        try {
            $value = $site->getSettings()->get($key);
        } catch (\Throwable) {
            return $default;
        }

        return $value ?? $default;
    }

    /**
     * @param mixed $phrases
     * @return list<string>
     */
    private function sanitizePhraseList(mixed $phrases): array
    {
        if (is_string($phrases)) {
            $phrases = preg_split('/[\r\n,]+/', $phrases) ?: [];
        }

        if (!is_array($phrases)) {
            return [];
        }

        $result = [];
        foreach ($phrases as $phrase) {
            $phrase = trim((string)$phrase);
            if ($phrase === '' || mb_strlen($phrase) > 120) {
                continue;
            }

            $result[] = $phrase;
        }

        return array_values(array_unique($result));
    }

    /**
     * @param list<string> $phrases
     * @return list<string>
     */
    private function normalizePhrases(array $phrases): array
    {
        $result = [];
        foreach ($phrases as $phrase) {
            $normalized = PhraseMatcher::normalize($phrase);
            if ($normalized !== '') {
                $result[] = $normalized;
            }
        }

        return array_values(array_unique($result));
    }
}
