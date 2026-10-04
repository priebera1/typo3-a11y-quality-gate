<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;

final class BackendJavaScriptModuleService
{
    private const MODULE_PREFIX = '@priebera/a11y-quality-gate/';

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
        'verifyFix.',
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

    /**
     * The module specifier with a content version, `…/page-module-indicator.js?v=<hash>`, resolved by the import
     * map's `@priebera/a11y-quality-gate/` prefix.
     *
     * TYPO3 busts import-map URLs with a hash of the installed extension versions. An installation that updates AQG's
     * files without a new installed version — a path or VCS package (`dev-main`), or files copied without
     * `composer update` — keeps that hash, and browsers reuse the previous file for as long as the web server allows,
     * often a week. The Page module then kept running an indicator script without the running-scan follower. The
     * content version changes with the file itself.
     *
     * Only for modules without relative imports, which are a single file and imported by nothing else; a module that
     * imports siblings would still load those through the plain import map.
     */
    public function versionedModule(string $specifier): string
    {
        if (!str_starts_with($specifier, self::MODULE_PREFIX)) {
            return $specifier;
        }

        $relativePath = substr($specifier, strlen(self::MODULE_PREFIX));
        if ($relativePath === '' || str_contains($relativePath, '..') || !str_ends_with($relativePath, '.js')) {
            return $specifier;
        }

        $file = dirname(__DIR__, 2) . '/Resources/Public/JavaScript/' . $relativePath;
        $hash = is_file($file) ? hash_file('sha256', $file) : false;

        return is_string($hash) ? $specifier . '?v=' . substr($hash, 0, 12) : $specifier;
    }

    public function loadVersionedModule(PageRenderer $pageRenderer, string $specifier): void
    {
        $pageRenderer->loadJavaScriptModule($this->versionedModule($specifier));
    }

    public function registerJavaScriptLabels(PageRenderer $pageRenderer): void
    {
        foreach (self::JAVASCRIPT_LABEL_PREFIXES as $prefix) {
            $pageRenderer->addInlineLanguageLabelFile(self::LANGUAGE_FILE, $prefix);
        }
    }
}
