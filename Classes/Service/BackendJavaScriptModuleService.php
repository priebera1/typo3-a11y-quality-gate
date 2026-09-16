<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;

final class BackendJavaScriptModuleService
{
    public const LANGUAGE_FILE = 'EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf';

    /**
     * Label prefixes the AQG backend JavaScript reads from TYPO3.lang. Every JavaScript label keeps an
     * English fallback, so a key outside these prefixes silently stays English in every other language
     * (guarded by TranslationParityTest).
     *
     * @var list<string>
     */
    public const JAVASCRIPT_LABEL_PREFIXES = [
        'action.',
        'freePreview.',
        'ignore.',
        'js.',
        'module.remotePageDetail.',
        'notification.',
        'overview.localScan.',
        'overview.progress.',
        'overview.scan.',
        'pageDetail.bulk.',
        'pageModuleIndicator.',
        'settings.licence.',
    ];

    public function __construct(
    ) {
    }

    public function loadBackendModule(PageRenderer $pageRenderer, ?Site $site): void
    {
        $pageRenderer->addCssFile('EXT:a11y_quality_gate/Resources/Public/Css/backend.css');
        $this->registerJavaScriptLabels($pageRenderer);

        if ($site === null) {
            $pageRenderer->loadJavaScriptModule(
                '@priebera/a11y-quality-gate/backend/module.free.js'
            );
            return;
        }

        $pageRenderer->loadJavaScriptModule(
            '@priebera/a11y-quality-gate/backend/module.pro.js'
        );
    }

    public function registerJavaScriptLabels(PageRenderer $pageRenderer): void
    {
        foreach (self::JAVASCRIPT_LABEL_PREFIXES as $prefix) {
            $pageRenderer->addInlineLanguageLabelFile(self::LANGUAGE_FILE, $prefix);
        }
    }
}
