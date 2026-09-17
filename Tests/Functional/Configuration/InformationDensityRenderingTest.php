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
 * Renders the reworked partials with the real Fluid engine and English/German labels, and checks what a
 * browser and assistive technology receive: labelled controls, closed disclosures, one primary action.
 */
final class InformationDensityRenderingTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    #[Test]
    public function siteOverrideRowRendersLabelledFieldsBehindASummary(): void
    {
        $xpath = $this->render('Settings/QualityGateSiteRow', ['siteRuleset' => [
            'site_identifier' => 'main',
            'site_label' => 'Main site',
            'publish_mode' => 2,
            'threshold_critical' => 1,
            'threshold_warning' => 3,
        ]]);

        $controls = $xpath->query('//select | //input');
        self::assertSame(3, $controls->length);
        foreach ($controls as $control) {
            self::assertInstanceOf(\DOMElement::class, $control);
            $id = $control->getAttribute('id');
            self::assertStringStartsWith('aqg-site-main-', $id);
            self::assertSame(1, $xpath->query('//label[@for="' . $id . '"]')->length, 'No label for ' . $id);
        }

        $details = $xpath->query('//details[contains(@class, "aqg-site__details")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $details);
        self::assertFalse($details->hasAttribute('open'));
        self::assertSame('Edit mode and thresholds', trim($xpath->query('./summary', $details)->item(0)?->textContent ?? ''));

        self::assertSame('Block publish', $this->text($xpath, '//*[contains(@class, "js-aqg-mode-label")]'));
        self::assertSame('Critical issues allowed: 1', $this->text($xpath, '//*[contains(@class, "js-aqg-site-summary-critical")]'));
        self::assertSame('Warnings allowed: 3', $this->text($xpath, '//*[contains(@class, "js-aqg-site-summary-warning")]'));

        // The publishing mode shares the primary line with the site name; the second line lists the thresholds.
        $primaryLine = '//*[contains(concat(" ", normalize-space(@class), " "), " aqg-site__title ")]';
        $secondaryLine = '//*[contains(concat(" ", normalize-space(@class), " "), " aqg-site__summary ")]';
        self::assertSame(1, $xpath->query($primaryLine . '//*[contains(@class, "js-aqg-mode-pill")]')->length);
        self::assertSame(1, $xpath->query($primaryLine . '/*[contains(@class, "aqg-site__id")]')->length);
        self::assertSame(0, $xpath->query($secondaryLine . '//*[contains(@class, "js-aqg-mode-pill")]')->length);
        self::assertSame('Critical issues allowed: 1 · Warnings allowed: 3', $this->text($xpath, $secondaryLine));
        self::assertSame(
            'aqg-site-main-threshold-critical-help',
            $xpath->query('//input[@id="aqg-site-main-threshold-critical"]')->item(0)?->getAttribute('aria-describedby')
        );
    }

    #[Test]
    public function siteOverrideSummarySaysWhenWarningsAreIgnored(): void
    {
        $xpath = $this->render('Settings/QualityGateSiteRow', ['siteRuleset' => [
            'site_identifier' => 'blog',
            'site_label' => 'Blog',
            'publish_mode' => 1,
            'threshold_critical' => 0,
            'threshold_warning' => -1,
        ]]);

        self::assertSame('Warn editors', $this->text($xpath, '//*[contains(@class, "js-aqg-mode-label")]'));
        self::assertSame('Warnings ignored', $this->text($xpath, '//*[contains(@class, "js-aqg-site-summary-warning")]'));
    }

    #[Test]
    public function newLicenceKeyOffersOnlySaveAndValidate(): void
    {
        $xpath = $this->render('Settings/TabLicence', $this->licenceArguments(false));

        self::assertSame(0, $xpath->query('//*[@data-action="a11y-validate-licence"]')->length);
        $submit = $xpath->query('//button[@type="submit"]');
        self::assertSame(1, $submit->length);
        self::assertSame('Save and validate', trim($submit->item(0)?->textContent ?? ''));
    }

    #[Test]
    public function savedLicenceKeyOffersRevalidate(): void
    {
        $xpath = $this->render('Settings/TabLicence', $this->licenceArguments(true));

        $revalidate = $xpath->query('//button[@data-action="a11y-validate-licence"]');
        self::assertSame(1, $revalidate->length);
        self::assertSame('Revalidate', trim($revalidate->item(0)?->textContent ?? ''));
        $submit = $xpath->query('//button[@type="submit"]');
        self::assertSame(1, $submit->length);
        self::assertSame('Save changes', trim($submit->item(0)?->textContent ?? ''));
        self::assertSame('Save and validate', $submit->item(0)?->getAttribute('data-label-save-validate'));
    }

    #[Test]
    public function unreachableLicenceServiceIsShownAsNotCheckedWithItsRetryAction(): void
    {
        $xpath = $this->render('Settings/LicenceStatus', $this->statusArguments('api_unreachable', 'retry', 'Retry'));

        self::assertSame('NOT CHECKED', $this->text($xpath, '//*[contains(@class, "aqg-licence-status__plan-tag")]'));
        self::assertStringContainsString('Not checked', $this->text($xpath, '//dl'));
        self::assertStringNotContainsString('INACTIVE', $this->text($xpath, '//section'));
        self::assertSame('Retry', $this->text($xpath, '//a[@data-aqg-licence-action="retry"]'));
    }

    #[Test]
    public function rejectedLicenceKeepsItsReasonSpecificStateAndAction(): void
    {
        $xpath = $this->render('Settings/LicenceStatus', $this->statusArguments('expired', 'portal', 'Renew licence'));

        self::assertSame('INACTIVE', $this->text($xpath, '//*[contains(@class, "aqg-licence-status__plan-tag")]'));
        self::assertSame('expired', $xpath->query('//section')->item(0)?->getAttribute('data-aqg-licence-state'));
        self::assertSame('Renew licence', $this->text($xpath, '//a[@data-aqg-licence-action="portal"]'));
    }

    #[Test]
    public function statementAssistantRendersOptionalDetailsClosedAndMarksRequiredFieldsAccessibly(): void
    {
        $xpath = $this->render('Settings/TabStatement', [
            'statementGeneratorAvailable' => true,
            'siteOptionsWithoutDefault' => [['identifier' => 'main', 'label' => 'Main site']],
            'statementDefaultSiteIdentifier' => 'main',
        ]);

        $optional = $xpath->query('//details[contains(@class, "aqg-statement-optional")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $optional);
        self::assertFalse($optional->hasAttribute('open'));
        self::assertSame(8, $xpath->query('.//fieldset', $optional)->length);
        self::assertSame(1, $xpath->query('.//*[contains(@class, "js-aqg-statement-evaluation-url")]', $optional)->length);
        foreach (['js-aqg-statement-site', 'js-aqg-statement-organisation', 'js-aqg-statement-contact-email', 'js-aqg-statement-enforcement', 'js-aqg-statement-status-confirmed'] as $core) {
            self::assertSame(0, $xpath->query('.//*[contains(@class, "' . $core . '")]', $optional)->length, $core . ' must stay visible.');
        }

        $announced = $xpath->query('//*[contains(@class, "visually-hidden")][contains(., "Required before publishing")]');
        self::assertSame(4, $announced->length);
        self::assertSame(
            'status',
            $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " js-aqg-statement-status ")]')->item(0)?->getAttribute('role')
        );
    }

    #[Test]
    public function priorityFixesKeepTheRuleFilterLinkAndHideSecondaryMetadata(): void
    {
        $xpath = $this->render('Overview/RemotePanel', [
            'remoteScan' => ['uid' => 5, 'job_id' => 'job-5', 'issues_total' => 12, 'finished_at' => 1789200000],
            'remotePriorityFixesVisible' => [[
                'rank' => 1,
                'ruleId' => 'color-contrast',
                'displayTitle' => 'Text needs more contrast',
                'impact' => 'serious',
                'issuesTotal' => 9,
                'affectedPagesTotal' => 3,
                'shortFix' => 'Darken the text colour.',
                'whoShouldFix' => 'designer',
                'whoShouldFixLabel' => 'Designer',
                'confidence' => 'high',
                'confidenceLabel' => 'High',
                'affectedPagesUrl' => '/typo3/module/web/a11y?remoteRule=color-contrast#a11y-remote-top-pages',
            ]],
            'remoteRule' => 'color-contrast',
            'remoteRuleFilter' => ['ruleId' => 'color-contrast', 'label' => 'Text needs more contrast', 'clearUrl' => '/typo3/module/web/a11y#a11y-remote-top-pages'],
            'remotePages' => [['uid' => 7, 'title' => 'About', 'url' => 'https://example.test/about', 'issues_count' => 2, 'http_status' => 200, 'detailUrl' => '/detail']],
            'totalRemotePages' => 1,
            'remotePagination' => ['totalPages' => 1],
            'freePreview' => ['isFree' => false],
        ]);

        $link = $xpath->query('//a[@data-aqg-rule-filter="color-contrast"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $link);
        self::assertSame('/typo3/module/web/a11y?remoteRule=color-contrast#a11y-remote-top-pages', $link->getAttribute('href'));

        $card = $xpath->query('//article[contains(@class, "aqg-pfix-item")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $card);
        self::assertStringContainsString('9 occurrences', preg_replace('/\s+/', ' ', $card->textContent) ?? '');
        $details = $xpath->query('.//details[contains(@class, "aqg-pfix-item__details")]', $card)->item(0);
        self::assertInstanceOf(\DOMElement::class, $details);
        self::assertFalse($details->hasAttribute('open'));
        self::assertStringContainsString('Designer', $details->textContent);
        self::assertStringNotContainsString('Designer', str_replace($details->textContent, '', $card->textContent));

        self::assertStringContainsString('Showing pages with: Text needs more contrast', $this->text($xpath, '//*[contains(@class, "aqg-filter-note__text")]'));
        self::assertSame(1, $xpath->query('//input[@type="hidden"][@name="remoteRule"][@value="color-contrast"]')->length);
        self::assertSame(['Page', 'Issue types', 'HTTP', 'Actions'], $this->texts($xpath, '//section[@id="a11y-remote-top-pages"]//table/thead/tr/th'));
        self::assertSame(['200'], $this->texts($xpath, '//section[@id="a11y-remote-top-pages"]//table/tbody/tr/td[3]'), 'A rule-filtered list keeps the HTTP status.');
    }

    #[Test]
    public function frontendPageTablesShowTheHttpStatusOfEveryPageInItsOwnColumn(): void
    {
        $xpath = $this->render('Overview/RemotePanel', [
            'remoteScan' => ['uid' => 5, 'job_id' => 'job-5', 'issues_total' => 4, 'finished_at' => 1789200000],
            'remotePages' => [
                ['uid' => 7, 'title' => 'About', 'url' => 'https://example.test/about', 'issues_count' => 3, 'http_status' => 200, 'detailUrl' => '/detail/7'],
                ['uid' => 8, 'title' => 'Moved', 'url' => 'https://example.test/moved', 'issues_count' => 1, 'http_status' => 301, 'detailUrl' => '/detail/8'],
                ['uid' => 9, 'title' => 'Imported', 'url' => 'https://example.test/imported', 'issues_count' => 0, 'http_status' => 0, 'detailUrl' => '/detail/9'],
            ],
            'totalRemotePages' => 3,
            'remotePagination' => ['totalPages' => 1],
            'remoteFailedPages' => [
                ['uid' => 10, 'title' => 'Gone', 'url' => 'https://example.test/gone', 'http_status' => 404, 'failure_reason' => 'Not found', 'detailUrl' => '/detail/10'],
            ],
            'totalRemoteFailedPages' => 1,
            'remoteFailedPagination' => ['totalPages' => 1],
            'freePreview' => ['isFree' => false],
        ]);

        $affected = '//section[@id="a11y-remote-top-pages"]//table';
        self::assertSame(['Page', 'Issue types', 'HTTP', 'Actions'], $this->texts($xpath, $affected . '/thead/tr/th'));
        self::assertSame(['3', '1', '0'], $this->texts($xpath, $affected . '/tbody/tr/td[2]'));
        // A page without a reported status (stored as 0) shows a dash, never an invented code.
        self::assertSame(['200', '301', '—'], $this->texts($xpath, $affected . '/tbody/tr/td[3]/span[@class="aqg-http"]'));
        self::assertSame(['View findings', 'View findings', 'View findings'], $this->texts($xpath, $affected . '/tbody/tr/td[4]'));
        self::assertSame(0, $xpath->query($affected . '/tbody/tr/td[1][contains(., "HTTP")]')->length, 'The status is shown once, in its column.');

        $failed = '//section[@id="a11y-remote-failed-pages"]//table';
        self::assertSame(['Page', 'HTTP', 'Failure reason', 'Actions'], $this->texts($xpath, $failed . '/thead/tr/th'));
        self::assertSame(['404'], $this->texts($xpath, $failed . '/tbody/tr/td[2]/span[@class="aqg-http"]'));

        foreach ([$affected, $failed] as $table) {
            foreach ($xpath->query($table . '/tbody/tr') as $row) {
                self::assertSame(4, $xpath->query('./td', $row)->length, 'Every column header has a cell in ' . $table);
            }
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function render(string $partial, array $arguments): \DOMXPath
    {
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            partialRootPaths: [
                GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Partials/'),
            ],
            templatePathAndFilename: __DIR__ . '/../../Fixtures/Templates/RenderPartial.html',
        ));
        $view->assignMultiple(['partial' => $partial, 'arguments' => $arguments]);

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $view->render() . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private function text(\DOMXPath $xpath, string $query): string
    {
        $node = $xpath->query($query)->item(0);
        self::assertNotNull($node, 'Not rendered: ' . $query);

        return trim((string)preg_replace('/\s+/', ' ', $node->textContent));
    }

    /**
     * @return list<string> the whitespace-normalised text of every matching node, in document order
     */
    private function texts(\DOMXPath $xpath, string $query): array
    {
        $texts = [];
        foreach ($xpath->query($query) as $node) {
            $texts[] = trim((string)preg_replace('/\s+/', ' ', $node->textContent));
        }

        return $texts;
    }

    /**
     * @return array<string, mixed>
     */
    private function statusArguments(string $state, string $action, string $label): array
    {
        return [
            'hasLicenceKey' => true,
            'proStatus' => ['valid' => false, 'domain' => 'example.test', 'reason' => $state],
            'licenceGuidance' => [
                'state' => $state,
                'title' => 'Title for ' . $state,
                'text' => 'Text for ' . $state,
                'actions' => [['action' => $action, 'label' => $label, 'url' => '#' . $action, 'primary' => true, 'external' => false]],
            ],
            'supportUrl' => 'https://support.example',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function licenceArguments(bool $hasKey): array
    {
        return [
            'isAdmin' => true,
            'hasLicenceKey' => $hasKey,
            'licenceKey' => $hasKey ? 'aqg_live_saved' : '',
            'proStatus' => ['valid' => false],
            'licenceGuidance' => null,
            'showProHints' => true,
            'saveExtConfUrl' => '/typo3/settings/save',
            'returnParameters' => [],
            'activeTab' => 'licence',
            'portalUrl' => 'https://portal.example',
            'trialUrl' => 'https://trial.example',
            'pricingUrl' => 'https://pricing.example',
            'supportUrl' => 'https://support.example',
            'productUrl' => 'https://product.example',
            'documentationUrl' => 'https://docs.example',
        ];
    }
}
