<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Atlas audit F-25 (axe nested-interactive, WCAG 4.1.2): the Scan fields and Rules group headers were <summary>
 * elements holding "Enable all" buttons. A summary is itself the disclosure control, so screen readers announced the
 * toggle and lost the nested button. The header is now a plain row: the group title is a disclosure button
 * (aria-expanded + aria-controls, settings-groups.js) and the bulk button is its sibling.
 */
final class SettingsGroupDisclosureTemplateTest extends TestCase
{
    private const PARTIALS = __DIR__ . '/../../../Resources/Private/Partials/Settings/';

    /** @return array<string,array{string,string,string}> */
    public static function groupTemplates(): array
    {
        return [
            'scan fields' => ['TabScanFields.html', 'aqg-fgroup', 'data-a11y-settings-enable-group="true"'],
            'rules' => ['TabRules.html', 'aqg-rgroup', 'data-a11y-rule-group-toggle="true"'],
        ];
    }

    #[Test]
    public function noSettingsSummaryContainsAControl(): void
    {
        $offenders = [];
        foreach (glob(self::PARTIALS . '*.html') ?: [] as $file) {
            $source = (string)file_get_contents($file);
            preg_match_all('/<summary\b[^>]*>(.*?)<\/summary>/s', $source, $summaries);
            foreach ($summaries[1] as $content) {
                if (preg_match('/<(?:a|button|input|select|textarea)\b|\btabindex="/', $content) === 1) {
                    $offenders[] = basename($file);
                }
            }
        }

        self::assertSame([], $offenders, 'A <summary> must not contain links, buttons or form controls (axe nested-interactive).');
    }

    #[Test]
    #[DataProvider('groupTemplates')]
    public function theGroupTitleIsADisclosureButtonAndTheBulkButtonItsSibling(string $template, string $block, string $bulkAttribute): void
    {
        $source = (string)file_get_contents(self::PARTIALS . $template);

        self::assertStringNotContainsString('<details class="' . $block, $source);
        self::assertStringNotContainsString('<summary class="' . $block . '__head', $source);
        self::assertMatchesRegularExpression(
            '/<div class="' . $block . '\b[^"]*"[^>]*data-aqg-group-disclosure="true"/',
            $source
        );
        self::assertMatchesRegularExpression(
            '/<button type="button" class="' . $block . '__title aqg-group-toggle" id="(' . $block . '-[^"]+-toggle)" aria-expanded="(?:true|false)" aria-controls="' . $block . '-[^"]+-body" data-aqg-group-toggle="true">/',
            $source
        );
        self::assertMatchesRegularExpression('/<div class="' . $block . '__body" id="' . $block . '-[^"]+-body" role="group" aria-labelledby="' . $block . '-[^"]+-toggle"/', $source);

        // The bulk button sits in the header row, outside the toggle, and names its group through aria-describedby.
        $head = substr($source, (int)strpos($source, 'class="' . $block . '__head'));
        $head = substr($head, 0, (int)strpos($head, 'class="' . $block . '__body"'));
        $toggle = substr($head, (int)strpos($head, 'data-aqg-group-toggle="true"'));
        $toggle = substr($toggle, 0, (int)strpos($toggle, '</button>'));
        self::assertStringContainsString($bulkAttribute, $head);
        self::assertStringNotContainsString($bulkAttribute, $toggle);
        self::assertDoesNotMatchRegularExpression('/<(?:a|input|select|textarea)\b/', $toggle);
        self::assertMatchesRegularExpression('/' . preg_quote($bulkAttribute, '/') . ' aria-describedby="' . $block . '-[^"]+-toggle"/', $head);
    }

    #[Test]
    public function theSettingsModuleLoadsTheDisclosureScript(): void
    {
        $controller = (string)file_get_contents(__DIR__ . '/../../../Classes/Controller/SettingsController.php');

        self::assertStringContainsString("'@priebera/a11y-quality-gate/backend/settings-groups.js'", $controller);
    }
}
