<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * typo3test14 (2026-11-11): two sites, Settings opened without a page. The saved, active Agency key rendered as
 * "INACTIVE — Licence could not be validated" with reason code `site_required` and a Retry that could never help.
 * Rendered with the real Fluid partials: the card asks for a site and lists the sites; nothing claims the licence is
 * invalid. A chosen site and a real refusal still render as before.
 */
final class LicenceSiteRequiredRenderingTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    #[Test]
    public function aMissingSiteRendersAChoiceAndNoVerdict(): void
    {
        $html = $this->render([
            'hasLicenceKey' => true,
            'licenceSiteRequired' => true,
            'proStatus' => ['valid' => false, 'reason' => 'site_required'],
            'licenceGuidance' => null,
            'licenceSiteOptions' => $this->options(''),
        ]);

        self::assertStringContainsString('data-aqg-licence-state="site_required"', $html);
        self::assertStringContainsString('Choose a site', $html);
        self::assertStringNotContainsString('INACTIVE', $html);
        self::assertStringNotContainsString('tone-error', $html);
        self::assertStringNotContainsString('unexpected answer', $html);
        self::assertSame(['#main', '#test'], $this->pickerLinks($html));
        self::assertStringNotContainsString('aria-current', $html, 'no site is chosen on the user\'s behalf');
    }

    #[Test]
    public function aChosenSiteIsMarkedAndAValidLicenceShowsAsActive(): void
    {
        $html = $this->render([
            'hasLicenceKey' => true,
            'licenceSiteRequired' => false,
            'proStatus' => ['valid' => true, 'hasMultiSite' => true, 'domain' => 'typo3test14.priebera.sk'],
            'licenceSiteOptions' => $this->options('main'),
        ]);

        self::assertStringContainsString('AQG Agency is active', $html);
        self::assertStringContainsString('typo3test14.priebera.sk', $html);
        self::assertMatchesRegularExpression('/<a href="#main"[^>]*aria-current="true"/', $html);
        self::assertStringNotContainsString('data-aqg-licence-state="site_required"', $html);
    }

    #[Test]
    public function aRefusedLicenceForTheChosenSiteStaysInactive(): void
    {
        $html = $this->render([
            'hasLicenceKey' => true,
            'licenceSiteRequired' => false,
            'proStatus' => ['valid' => false, 'reason' => 'expired', 'domain' => 'typo3test14.priebera.sk'],
            'licenceGuidance' => ['state' => 'expired', 'title' => 'Licence expired', 'text' => 'Renew it.', 'actions' => []],
            'licenceSiteOptions' => $this->options('main'),
        ]);

        self::assertStringContainsString('INACTIVE', $html);
        self::assertStringContainsString('data-aqg-licence-state="expired"', $html);
        self::assertStringNotContainsString('data-aqg-licence-state="site_required"', $html);
    }

    #[Test]
    public function aSingleSiteInstallationRendersNoPicker(): void
    {
        $html = $this->render([
            'hasLicenceKey' => true,
            'licenceSiteRequired' => false,
            'proStatus' => ['valid' => true, 'domain' => 'a.example'],
            'licenceSiteOptions' => [],
        ]);

        self::assertStringNotContainsString('data-aqg-licence-site-picker', $html);
    }

    /**
     * @return list<array{identifier:string,domain:string,url:string,selected:bool}>
     */
    private function options(string $selected): array
    {
        return [
            ['identifier' => 'main', 'domain' => 'typo3test14.priebera.sk', 'url' => '#main', 'selected' => $selected === 'main'],
            ['identifier' => 'test', 'domain' => 'typo314.ddev.site', 'url' => '#test', 'selected' => $selected === 'test'],
        ];
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(array $variables): string
    {
        $html = '';
        foreach (['Settings/LicenceSitePicker', 'Settings/LicenceStatus'] as $partial) {
            $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
                partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Partials/')],
                templatePathAndFilename: __DIR__ . '/../../Fixtures/Templates/RenderPartial.html',
            ));
            $view->assignMultiple(['partial' => $partial, 'arguments' => $variables]);
            $html .= $view->render();
        }

        return $html;
    }

    /**
     * @return list<string>
     */
    private function pickerLinks(string $html): array
    {
        preg_match_all('/<a href="([^"]+)"[^>]*data-aqg-licence-site-option=/', $html, $matches);

        return $matches[1];
    }
}
