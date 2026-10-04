<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Licence tab's domain manager: a compact table the browser fills from the AQG service's answer. It sits outside
 * the licence form (its controls never submit the key form), is shown to administrators with a saved key only, labels
 * every control, and gets every label from the XLIFF files so the browser shows the backend language.
 */
final class LicenceDomainManagerTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../Resources/Private/Partials/Settings/TabLicence.html';
    private const SCRIPT = __DIR__ . '/../../../Resources/Public/JavaScript/backend/settings-licence-domains.js';
    private const CONTROLLER = __DIR__ . '/../../../Classes/Controller/SettingsController.php';
    private const ROUTES = __DIR__ . '/../../../Configuration/Backend/AjaxRoutes.php';
    private const LANGUAGE_DIR = __DIR__ . '/../../../Resources/Private/Language/';

    #[Test]
    public function theManagerIsOutsideTheLicenceFormAndOnlyForAdministratorsWithAKey(): void
    {
        $template = (string)file_get_contents(self::TEMPLATE);
        $section = $this->section($template);

        self::assertGreaterThan(strpos($template, '</form>'), strpos($template, 'data-aqg-licence-domains="true"'));
        $before = substr($template, strpos($template, '</form>'), strpos($template, 'data-aqg-licence-domains="true"') - strpos($template, '</form>'));
        self::assertStringContainsString('<f:if condition="{isAdmin}">', $before);
        self::assertStringContainsString('<f:if condition="{hasLicenceKey}">', $before);
        self::assertStringContainsString('data-list-url="{licenceDomainsUrl}"', $section);
        self::assertStringContainsString('data-update-url="{licenceDomainsUpdateUrl}"', $section);
        self::assertStringNotContainsString(' name="', $section, 'no control of the manager is a form field');
    }

    #[Test]
    public function everyControlIsLabelledAndEveryButtonIsAPlainButton(): void
    {
        $section = $this->section((string)file_get_contents(self::TEMPLATE));

        preg_match_all('/<input\b[^>]*>/', $section, $inputs);
        self::assertNotEmpty($inputs[0]);
        foreach ($inputs[0] as $input) {
            self::assertMatchesRegularExpression('/\sid="([^"]+)"/', $input);
            preg_match('/\sid="([^"]+)"/', $input, $id);
            self::assertStringContainsString('for="' . $id[1] . '"', $section, $input . ' needs a <label for>');
        }

        preg_match_all('/<button\b[^>]*>/', $section, $buttons);
        self::assertNotEmpty($buttons[0]);
        foreach ($buttons[0] as $button) {
            self::assertStringContainsString('type="button"', $button);
            self::assertMatchesRegularExpression('/class="[^"]*(btn|aqg-licence-domains__filter)/', $button);
        }

        // TYPO3 sizes .form-check-input through variables set on .form-check: a bare checkbox renders at 0x0.
        preg_match_all('/<input\b[^>]*class="form-check-input"[^>]*>/', $section, $checkboxes);
        self::assertNotEmpty($checkboxes[0]);
        foreach ($checkboxes[0] as $checkbox) {
            self::assertMatchesRegularExpression(
                '/<span class="form-check aqg-licence-domains__check">\s*' . preg_quote($checkbox, '/') . '/',
                $section,
            );
        }

        self::assertStringContainsString('role="status"', $section);
        self::assertStringContainsString('<caption class="visually-hidden">', $section);
        self::assertStringContainsString('<noscript>', $section);
        foreach (['all', 'active', 'available', 'not_detected', 'unavailable'] as $filter) {
            self::assertStringContainsString('data-aqg-domains-filter="' . $filter . '"', $section);
        }
        self::assertStringContainsString('data-aqg-domains-bulk="activate-all"', $section);
    }

    #[Test]
    public function everyScriptLabelIsRenderedFromTheTranslationFiles(): void
    {
        $section = $this->section((string)file_get_contents(self::TEMPLATE));
        $script = (string)file_get_contents(self::SCRIPT);

        preg_match('/const DEFAULT_LABELS = \{(.*?)\n\};/s', $script, $defaults);
        preg_match_all('/^\s{4}([a-zA-Z]+):/m', $defaults[1] ?? '', $names);
        self::assertNotEmpty($names[1]);
        foreach ($names[1] as $name) {
            $attribute = 'data-label-' . strtolower((string)preg_replace('/[A-Z]/', '-$0', $name));
            self::assertStringContainsString($attribute . '="{f:translate(', $section, $name . ' needs ' . $attribute);
        }

        preg_match_all('/locallang\.xlf:(settings\.licence\.domains\.[A-Za-z.]+)/', $section, $keys);
        $english = $this->ids(self::LANGUAGE_DIR . 'locallang.xlf');
        $german = $this->ids(self::LANGUAGE_DIR . 'de.locallang.xlf');
        foreach (array_unique($keys[1]) as $key) {
            self::assertContains($key, $english);
            self::assertContains($key, $german);
        }
    }

    #[Test]
    public function theScriptRendersServiceDataAsTextOnly(): void
    {
        $script = (string)file_get_contents(self::SCRIPT);

        self::assertStringNotContainsString('innerHTML', $script);
        self::assertStringNotContainsString('insertAdjacentHTML', $script);
        self::assertStringContainsString("loadVersionedModule(\$this->pageRenderer, '@priebera/a11y-quality-gate/backend/settings-licence-domains.js')", (string)file_get_contents(self::CONTROLLER));
    }

    #[Test]
    public function theAjaxRoutesInheritTheModuleAccess(): void
    {
        $routes = require self::ROUTES;

        foreach (['a11y_licence_domains', 'a11y_licence_domains_update'] as $route) {
            self::assertArrayHasKey($route, $routes);
            self::assertSame('web_a11y', $routes[$route]['inheritAccessFromModule'] ?? null);
            self::assertSame(['POST'], $routes[$route]['methods'] ?? null);
        }
    }

    private function section(string $template): string
    {
        $start = strpos($template, '<section class="aqg-card aqg-licence-domains"');
        self::assertNotFalse($start);
        $end = strpos($template, '</section>', $start);

        return substr($template, $start, $end - $start);
    }

    /**
     * @return list<string>
     */
    private function ids(string $file): array
    {
        preg_match_all('/<trans-unit id="([^"]+)"/', (string)file_get_contents($file), $matches);

        return $matches[1];
    }
}
