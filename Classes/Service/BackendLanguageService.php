<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use TYPO3\CMS\Core\Localization\LanguageService;

final class BackendLanguageService
{
    public function getLanguageService(): ?LanguageService
    {
        $languageService = $GLOBALS['LANG'] ?? null;

        return $languageService instanceof LanguageService ? $languageService : null;
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
