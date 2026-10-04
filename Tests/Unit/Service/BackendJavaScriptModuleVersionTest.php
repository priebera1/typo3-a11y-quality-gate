<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\BackendJavaScriptModuleService;

/**
 * On typo3test14 the deployed indicator script had the running-scan follower, yet the Page module stayed at "Scan
 * running": TYPO3's import-map bust is a hash of the installed extension versions, the installed version had not
 * changed since September (`dev-main`), and browsers kept the previous file for the web server's max-age of a week.
 * Standalone entry modules are now loaded with a content version, so a changed file always has a new URL.
 */
final class BackendJavaScriptModuleVersionTest extends TestCase
{
    private const JS_ROOT = __DIR__ . '/../../../Resources/Public/JavaScript/';

    #[Test]
    public function anEntryModuleCarriesTheHashOfItsCurrentContent(): void
    {
        $service = new BackendJavaScriptModuleService();
        $specifier = '@priebera/a11y-quality-gate/backend/page-module-indicator.js';

        $versioned = $service->versionedModule($specifier);

        self::assertSame(
            $specifier . '?v=' . substr(hash_file('sha256', self::JS_ROOT . 'backend/page-module-indicator.js'), 0, 12),
            $versioned,
        );
        self::assertMatchesRegularExpression('/\?v=[0-9a-f]{12}$/', $service->versionedModule('@priebera/a11y-quality-gate/backend/settings-licence-domains.js'));
    }

    #[Test]
    public function onlyExistingAqgModulesAreVersioned(): void
    {
        $service = new BackendJavaScriptModuleService();

        foreach ([
            '@typo3/core/ajax/ajax-request.js',
            '@priebera/a11y-quality-gate/backend/does-not-exist.js',
            '@priebera/a11y-quality-gate/../../../../etc/passwd.js',
            '@priebera/a11y-quality-gate/backend/',
            '@priebera/a11y-quality-gate/backend/page-module-indicator.css',
        ] as $specifier) {
            self::assertSame($specifier, $service->versionedModule($specifier));
        }
    }

    #[Test]
    public function everyStandaloneEntryModuleIsLoadedVersionedAndHasNoRelativeImports(): void
    {
        $root = __DIR__ . '/../../../';
        $callers = [
            'Classes/EventListener/ModifyPageLayoutContentListener.php',
            'Classes/Controller/SettingsController.php',
            'Classes/Controller/PageDetailController.php',
            'Classes/Controller/RemotePageDetailController.php',
        ];

        $loaded = [];
        foreach ($callers as $caller) {
            $source = (string)file_get_contents($root . $caller);
            self::assertStringNotContainsString("loadJavaScriptModule('@priebera/a11y-quality-gate/", $source, $caller . ' loads an AQG module without a content version');
            preg_match_all("/loadVersionedModule\\(\\\$this->pageRenderer, '@priebera\\/a11y-quality-gate\\/([^']+)'\\)/", $source, $matches);
            $loaded = [...$loaded, ...$matches[1]];
        }

        self::assertContains('backend/page-module-indicator.js', $loaded);
        self::assertContains('backend/settings-licence-domains.js', $loaded);
        foreach ($loaded as $module) {
            // A relative import would be loaded through the plain, version-busted import map and could stay stale.
            self::assertDoesNotMatchRegularExpression("/(?:^|\\n)\\s*import\\b[^;]*['\"]\\.\\.?\\//", (string)file_get_contents(self::JS_ROOT . $module), $module);
        }
    }
}
