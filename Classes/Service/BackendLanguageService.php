<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class BackendLanguageService
{
    /** @var array<string, array<string, string>> */
    private array $explicitLanguageCatalogues = [];
    public function getLanguageService(): ?LanguageService
    {
        $languageService = $GLOBALS['LANG'] ?? null;

        return $languageService instanceof LanguageService ? $languageService : null;
    }

    public function getCurrentLanguageCode(): string
    {
        $languageService = $this->getLanguageService();
        if (!$languageService instanceof LanguageService) {
            return 'en';
        }

        try {
            $locale = $languageService->getLocale();
            $code = strtolower(str_replace('_', '-', (string)$locale));
            return str_starts_with($code, 'de') ? 'de' : 'en';
        } catch (\Throwable) {
            return 'en';
        }
    }

    public function translate(string $key, string $file = 'locallang.xlf'): string
    {
        $languageService = $this->getLanguageService();
        if (!$languageService instanceof LanguageService) {
            return $key;
        }

        return (string)$languageService->sL(
            'LLL:EXT:a11y_quality_gate/Resources/Private/Language/' . $file . ':' . $key
        );
    }


    public function translateForLanguage(string $key, string $language, string $file = 'locallang.xlf'): string
    {
        $language = strtolower(str_replace('_', '-', trim($language)));
        $language = str_starts_with($language, 'de') ? 'de' : 'en';
        $catalogueKey = $language . ':' . $file;

        if (!isset($this->explicitLanguageCatalogues[$catalogueKey])) {
            $this->explicitLanguageCatalogues[$catalogueKey] = $this->loadCatalogue($language, $file);
        }

        $value = trim((string)($this->explicitLanguageCatalogues[$catalogueKey][$key] ?? ''));
        $lllReference = 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/'
            . ($language === 'de' ? 'de.' : '')
            . $file
            . ':'
            . $key;

        if ($value === '' || $value === $key || $value === $lllReference) {
            return '';
        }

        return $value;
    }

    /** @return array<string, string> */
    private function loadCatalogue(string $language, string $file): array
    {
        $fileName = $language === 'de' ? 'de.' . $file : $file;
        $path = GeneralUtility::getFileAbsFileName(
            'EXT:a11y_quality_gate/Resources/Private/Language/' . $fileName
        );
        if ($path === '' || !is_file($path)) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->load($path, LIBXML_NONET | LIBXML_NOBLANKS)) {
                return [];
            }

            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
            $catalogue = [];
            $units = $xpath->query('//x:trans-unit');
            if ($units === false) {
                return [];
            }

            foreach ($units as $unit) {
                if (!$unit instanceof \DOMElement) {
                    continue;
                }
                $id = trim($unit->getAttribute('id'));
                if ($id === '') {
                    continue;
                }

                $target = $xpath->query('./x:target', $unit)?->item(0);
                $source = $xpath->query('./x:source', $unit)?->item(0);
                $value = $language === 'de'
                    ? trim((string)($target instanceof \DOMNode ? $target->textContent : ''))
                    : trim((string)($source instanceof \DOMNode ? $source->textContent : ''));
                if ($value !== '') {
                    $catalogue[$id] = $value;
                }
            }

            return $catalogue;
        } catch (\Throwable) {
            return [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function translateRawLabel(string $label): string
    {
        $languageService = $this->getLanguageService();
        if (!$languageService instanceof LanguageService) {
            return $label;
        }

        if (!str_starts_with($label, 'LLL:')) {
            return $label;
        }

        return (string)$languageService->sL($label);
    }
}
