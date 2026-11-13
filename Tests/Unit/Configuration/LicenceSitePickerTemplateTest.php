<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * D-01 follow-up (typo3test14, 2026-11-11): on an installation with several sites, Settings opened without a page
 * showed a valid Agency licence as "INACTIVE — Licence could not be validated — the licence service returned an
 * unexpected answer — Retry" with reason code `site_required`. Nothing had been validated and no control let the
 * administrator choose the site. The card now says a site is needed and offers the sites as plain links.
 */
final class LicenceSitePickerTemplateTest extends TestCase
{
    private const PARTIALS = __DIR__ . '/../../../Resources/Private/Partials/Settings/';
    private const CONTROLLER = __DIR__ . '/../../../Classes/Controller/SettingsController.php';

    #[Test]
    public function aMissingSiteIsShownAsAChoiceNotAsAnInactiveLicence(): void
    {
        $status = (string)file_get_contents(self::PARTIALS . 'LicenceStatus.html');

        $siteRequired = strpos($status, '<f:if condition="{licenceSiteRequired}">');
        $inactive = strpos($status, 'settings.licence.tag.inactive');
        self::assertNotFalse($siteRequired);
        self::assertNotFalse($inactive);
        self::assertLessThan($inactive, $siteRequired, 'the site choice is decided before the inactive card');
        // The valid branch comes first: a valid licence is never hidden behind the site choice.
        self::assertLessThan($siteRequired, strpos($status, '<f:if condition="{proStatus.valid}">'));

        $branchEnd = strpos($status, '<f:if condition="{hasLicenceKey}">', $siteRequired);
        self::assertNotFalse($branchEnd);
        $branch = substr($status, $siteRequired, $branchEnd - $siteRequired);
        self::assertStringContainsString('tone-none', $branch);
        self::assertStringNotContainsString('tone-error', $branch);
        self::assertStringNotContainsString('licenceGuidance', $branch, 'no "unexpected answer / Retry" guidance');
        self::assertStringNotContainsString('settings.licence.reasonCode', $branch);
    }

    #[Test]
    public function thePickerIsALinkListBesideTheStatusAndNoFormField(): void
    {
        $tab = (string)file_get_contents(self::PARTIALS . 'TabLicence.html');
        $picker = (string)file_get_contents(self::PARTIALS . 'LicenceSitePicker.html');

        self::assertLessThan(
            strpos($tab, 'partial="Settings/LicenceStatus"'),
            strpos($tab, 'partial="Settings/LicenceSitePicker"'),
        );
        // Outside the admin-only key card: editors see the picker for the sites they can read.
        $adminCardEnd = strpos($tab, '</f:if>', strpos($tab, 'settings.licence.adminOnly'));
        self::assertGreaterThan($adminCardEnd, strpos($tab, 'partial="Settings/LicenceSitePicker"'));

        self::assertStringContainsString('<f:if condition="{licenceSiteOptions}">', $picker);
        self::assertStringContainsString('href="{option.url}"', $picker);
        self::assertStringContainsString('aria-current="true"', $picker);
        // Labels go through an escaped variable, never as f:if then/else output (not escaped by Fluid).
        self::assertStringContainsString('>{siteLabel}</a>', $picker);
        self::assertStringNotContainsString('f:if(condition: option.domain', $picker);
        self::assertStringContainsString('aria-labelledby="aqg-licence-site-picker-title"', $picker);
        self::assertDoesNotMatchRegularExpression('/<(input|select|button|form)\b/', $picker, 'choosing a site submits nothing');
        // The key never reaches the picker.
        self::assertStringNotContainsString('licenceKey', $picker);
    }

    #[Test]
    public function siteRequiredGetsNoLicenceGuidanceAndValidateSaysChooseASite(): void
    {
        $controller = (string)file_get_contents(self::CONTROLLER);

        self::assertMatchesRegularExpression(
            "/\\\$licenceGuidance = \\\$licenceViewData\\['hasLicenceKey'\\] && !\\(bool\\)\\(\\\$proStatus->valid \\?\\? false\\)\\s*&& \\(string\\)\\(\\\$proStatus->reason \\?\\? ''\\) !== 'site_required'/",
            $controller,
        );
        // Only with a saved key: without one the Free card stays, whatever the site.
        self::assertStringContainsString("'licenceSiteRequired' => \$licenceViewData['hasLicenceKey'] && (string)(\$proStatus->reason ?? '') === 'site_required'", $controller);
        self::assertStringContainsString("'settings.licence.validation.siteRequiredTitle'", $controller);
    }
}
