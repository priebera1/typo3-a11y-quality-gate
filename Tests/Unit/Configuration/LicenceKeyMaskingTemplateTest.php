<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Licence tab shows the saved key masked and starts the key field empty, so the key stays out of the page
 * source, screen shares and support screenshots. Revalidate, replacement and removal keep working without it.
 */
final class LicenceKeyMaskingTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../Resources/Private/Partials/Settings/TabLicence.html';

    #[Test]
    public function theSavedKeyIsNeverRenderedIntoThePage(): void
    {
        $template = (string)file_get_contents(self::TEMPLATE);

        self::assertStringNotContainsString('{licenceKey}', $template);
        self::assertMatchesRegularExpression('/name="licenceKey"\s+value=""/', $template, 'The key field starts empty.');
        self::assertStringContainsString('{maskedLicenceKey}', $template);
    }

    #[Test]
    public function theMaskedKeyReadsAsItsEndingNotAsBulletCharacters(): void
    {
        $template = (string)file_get_contents(self::TEMPLATE);

        self::assertMatchesRegularExpression('/<span aria-hidden="true">\{maskedLicenceKey\}<\/span>/', $template);
        self::assertStringContainsString('settings.licence.savedKeyEnding', $template);
    }

    #[Test]
    public function revalidateAndExplicitRemovalAreOfferedForASavedKey(): void
    {
        $template = (string)file_get_contents(self::TEMPLATE);

        self::assertStringContainsString('data-action="a11y-validate-licence"', $template);
        self::assertMatchesRegularExpression('/<input class="form-check-input" type="checkbox" id="aqg-licence-remove" name="licenceKeyRemove" value="1"/', $template);
        self::assertStringContainsString('<label class="form-check-label" for="aqg-licence-remove">', $template);
    }
}
