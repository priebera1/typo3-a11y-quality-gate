<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Rules and Scan fields apply to every site, and only administrators may save them (SettingsController::saveAction(),
 * refreshAction(); GlobalSettingsAuthorizationFunctionalTest). For everyone else the tabs are read-only: every save,
 * discard, reset, refresh and bulk action is rendered only inside an {isAdmin} condition, and every editable control
 * is disabled, so the view never offers an action the server refuses.
 */
final class GlobalSettingsReadOnlyTemplateTest extends TestCase
{
    private const PARTIALS = __DIR__ . '/../../../Resources/Private/Partials/Settings/';

    /** @return array<string, array{string, list<string>, string}> */
    public static function templates(): array
    {
        return [
            'rules' => [
                'TabRules.html',
                ['type="submit"', 'data-a11y-rules-discard="true"', 'data-a11y-rules-reset-open="true"', 'data-a11y-rules-reset-confirm="true"', 'data-a11y-rule-group-toggle="true"'],
                'data-a11y-rules-input="true"',
            ],
            'scan fields' => [
                'TabScanFields.html',
                ['type="submit"', 'type="reset"', 'data-a11y-settings-enable-group="true"'],
                'data-a11y-settings-checkbox="true"',
            ],
        ];
    }

    #[Test]
    #[DataProvider('templates')]
    public function actionsAreRenderedForAdministratorsOnly(string $template, array $actions, string $input): void
    {
        $source = (string)file_get_contents(self::PARTIALS . $template);
        foreach ($actions as $action) {
            $offset = 0;
            $found = 0;
            while (($position = strpos($source, $action, $offset)) !== false) {
                $found++;
                $offset = $position + 1;
                if ($this->isInsideAdminCondition($source, $position)) {
                    continue;
                }
                // The reset panel's buttons sit inside the panel, which is itself only rendered for administrators.
                self::fail(sprintf('%s: %s at offset %d is rendered for non-administrators.', $template, $action, $position));
            }
            self::assertGreaterThan(0, $found, $template . ' has no ' . $action);
        }
        self::assertStringContainsString('data-a11y-settings-read-only="true"', $source);
    }

    #[Test]
    #[DataProvider('templates')]
    public function everyEditableControlIsDisabledForNonAdministrators(string $template, array $actions, string $input): void
    {
        $source = (string)file_get_contents(self::PARTIALS . $template);
        preg_match_all('/<(?:input|select|textarea)\b[^>]*' . preg_quote($input, '/') . '[^>]*>/s', $source, $controls);

        self::assertNotEmpty($controls[0]);
        foreach ($controls[0] as $control) {
            self::assertStringContainsString("{f:if(condition: isAdmin, else: 'disabled=\"disabled\"')}", $control, $template);
        }
    }

    private function isInsideAdminCondition(string $source, int $position): bool
    {
        preg_match_all('/<f:if\b[^>]*>|<\/f:if>/', substr($source, 0, $position), $tokens);
        $stack = [];
        foreach ($tokens[0] as $token) {
            if ($token === '</f:if>') {
                array_pop($stack);
                continue;
            }
            $stack[] = str_contains($token, 'condition="{isAdmin}"');
        }

        return in_array(true, $stack, true);
    }
}
