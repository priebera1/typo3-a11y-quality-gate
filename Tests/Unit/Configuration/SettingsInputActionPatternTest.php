<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression: "Revalidate" (licence key) and "Copy" (Scanner Token) took most of their field. Both rows used a
 * three-column grid (`auto minmax(0, 1fr) auto`) for two children, so the button landed in the flexible column. They
 * now share one input + action pattern: the value takes the space, the button only its label and target size.
 */
final class SettingsInputActionPatternTest extends TestCase
{
    private const PARTIALS = __DIR__ . '/../../../Resources/Private/Partials/Settings/';
    private const SCSS = __DIR__ . '/../../../Resources/Private/Scss/views/_settings.scss';

    #[Test]
    public function revalidateAndCopyUseTheSharedInputActionPattern(): void
    {
        $licence = (string)file_get_contents(self::PARTIALS . 'TabLicence.html');
        $access = (string)file_get_contents(self::PARTIALS . 'TabRemoteScanAccess.html');

        self::assertMatchesRegularExpression('/<div class="aqg-inputgroup">\s*<span class="[^"]*aqg-inputgroup__value[^"]*"/', $licence);
        self::assertMatchesRegularExpression('/<button type="button" class="btn btn-default btn-sm aqg-inputgroup__btn" data-action="a11y-validate-licence"/', $licence);
        self::assertMatchesRegularExpression('/<div class="aqg-inputgroup" role="group" aria-labelledby="aqg-scanner-token-label">\s*<span class="[^"]*aqg-inputgroup__value[^"]*"/', $access);
        self::assertMatchesRegularExpression('/<button type="button" class="btn btn-default btn-sm aqg-inputgroup__btn js-aqg-copy-token"/', $access);
        self::assertStringNotContainsString('aqg-token-row__field', $access);
    }

    #[Test]
    public function theActionKeepsItsOwnWidthAtEveryModuleWidth(): void
    {
        $scss = (string)file_get_contents(self::SCSS);

        self::assertDoesNotMatchRegularExpression('/\.aqg-inputgroup \{[^}]*grid-template-columns/', $scss, 'No column template that can hand the flexible column to the button.');
        self::assertMatchesRegularExpression('/\.aqg-inputgroup \{[^}]*display: flex;/', $scss);
        self::assertMatchesRegularExpression('/\.aqg-inputgroup__value \{[^}]*flex: 1 1 auto;[^}]*min-width: 0;/', $scss);
        self::assertMatchesRegularExpression('/\.aqg-inputgroup__btn \{[^}]*flex: 0 0 auto;/', $scss);
        self::assertDoesNotMatchRegularExpression('/\.aqg-inputgroup__btn \{[^}]*width: 100%;/', $scss, 'The action is never stretched to the full width.');
    }
}
