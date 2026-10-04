<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\UpdateNoticeAjaxController;

/**
 * The update notice is part of the AQG modules only: one shared partial, a POST-only dismiss route behind the
 * module's access check and TYPO3's route token, and no live region.
 */
final class UpdateNoticeTemplateTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    #[Test]
    public function theDismissRouteIsPostOnlyTokenProtectedAndInheritsModuleAccess(): void
    {
        $routes = require self::ROOT . '/Configuration/Backend/AjaxRoutes.php';
        $route = $routes['a11y_update_notice_dismiss'] ?? null;

        self::assertIsArray($route);
        self::assertSame(UpdateNoticeAjaxController::class . '::dismissAction', $route['target']);
        self::assertSame(['POST'], $route['methods']);
        self::assertSame('web_a11y', $route['inheritAccessFromModule']);
        // A public route would skip TYPO3's route token check, the CSRF protection of every backend AJAX call.
        self::assertArrayNotHasKey('access', $route);
        self::assertArrayNotHasKey('parameters', $route);
    }

    #[Test]
    public function theNoticeIsAQuietDismissibleLine(): void
    {
        $partial = $this->read('Resources/Private/Partials/Shared/UpdateNotice.html');

        self::assertStringContainsString('<f:if condition="{updateNotice}">', $partial);
        self::assertStringNotContainsString('role="alert"', $partial);
        self::assertStringNotContainsString('role="status"', $partial);
        self::assertStringNotContainsString('aria-live', $partial);
        self::assertStringNotContainsString('autofocus', $partial);
        self::assertStringContainsString('type="button"', $partial);
        self::assertStringContainsString('data-action="a11y-dismiss-update-notice"', $partial);
        self::assertMatchesRegularExpression('/aria-label="\{f:translate\(key: \'[^\']*updateNotice\.dismiss\'/', $partial);
        self::assertSame(2, substr_count($partial, 'rel="noopener noreferrer"'));
        self::assertSame(2, substr_count($partial, 'action.opensInNewTab'));
        foreach (['updateNotice.title', 'updateNotice.installed', 'updateNotice.whatsNew', 'updateNotice.instructions'] as $key) {
            self::assertStringContainsString($key, $partial);
        }
        // No permanent "never show updates" option.
        self::assertSame(1, substr_count($partial, '<button'));
    }

    #[Test]
    public function overviewAndSettingsShareTheNotice(): void
    {
        foreach (['Templates/Overview/Index.html', 'Templates/Settings/Index.html'] as $template) {
            self::assertStringContainsString(
                '<f:render partial="Shared/UpdateNotice" arguments="{updateNotice: updateNotice}" />',
                $this->read('Resources/Private/' . $template),
                $template
            );
        }

        foreach (['OverviewController', 'SettingsController'] as $controller) {
            $source = $this->read('Classes/Controller/' . $controller . '.php');
            self::assertStringContainsString('$this->updateNoticeService?->buildForCurrentUser()', $source, $controller);
            self::assertMatchesRegularExpression(
                '/if \(\$updateNotice !== null\) \{\s*\$this->backendJavaScriptModuleService->loadVersionedModule\(\$this->pageRenderer, \'@priebera\/a11y-quality-gate\/backend\/update-notice\.js\'\);/',
                $source,
                $controller . ' loads the dismiss script only with a notice.'
            );
        }

        // Never global: no toolbar item, no page module, no backend-wide hook renders it.
        foreach (['Classes/Backend/ToolbarItems/A11yScanToolbarItem.php', 'Classes/EventListener/ModifyPageLayoutContentListener.php', 'ext_localconf.php'] as $file) {
            self::assertStringNotContainsString('UpdateNotice', $this->read($file), $file);
        }
    }

    #[Test]
    public function theDismissScriptPostsOnlyTheVersionToTheTokenizedRoute(): void
    {
        $script = $this->read('Resources/Public/JavaScript/backend/update-notice.js');

        self::assertStringContainsString("TYPO3?.settings?.ajaxUrls?.[DISMISS_ROUTE]", $script);
        self::assertStringContainsString("const DISMISS_ROUTE = 'a11y_update_notice_dismiss';", $script);
        self::assertStringContainsString('.post({version})', $script);
        self::assertStringNotContainsString('localStorage', $script);
    }

    private function read(string $path): string
    {
        $content = file_get_contents(self::ROOT . '/' . $path);
        self::assertIsString($content, $path);

        return $content;
    }
}
