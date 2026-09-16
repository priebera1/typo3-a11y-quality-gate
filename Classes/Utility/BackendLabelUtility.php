<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Utility;

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Translates AQG labels where no language service is injected (AJAX controllers).
 *
 * Falls back to the given English text when no backend language is initialised (CLI, unit tests)
 * or when the key is missing, so a response never carries a raw translation key.
 */
final class BackendLabelUtility
{
    private const LANGUAGE_FILE = 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:';

    public static function translate(string $key, string $fallback): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            return $fallback;
        }

        $translated = (string)$languageService->sL(self::LANGUAGE_FILE . $key);

        return $translated !== '' ? $translated : $fallback;
    }
}
