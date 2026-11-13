<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Settings\AiSettingsCopy;

final class AiSettingsTemplateTest extends TestCase
{
    #[Test]
    public function modelIsShownOnceAsProjectFilteredAdminSelection(): void
    {
        $template = file_get_contents(
            dirname(__DIR__, 3) . '/Resources/Private/Partials/Settings/TabAi.html',
        );
        self::assertIsString($template);

        // UX Atlas 01 C: one radio row per model this key may use, with its automatic price, and "Use this model".
        self::assertStringContainsString('data-aqg-ai-model-list="true"', $template);
        self::assertStringContainsString('name="aqg-ai-model"', $template);
        self::assertStringContainsString('data-aqg-ai-model-option="true"', $template);
        self::assertStringContainsString('data-action="aqg-ai-use-model"', $template);
        self::assertStringContainsString('{aiConfigurationStatus.availableModels}', $template);
        self::assertStringContainsString('{aiConfigurationStatus.unsupportedModels}', $template);
        self::assertStringContainsString('{model.price.inputLabel}', $template);
        self::assertStringContainsString('Refresh available models', $template);
        // Without models the list names the site's provider ("Select an OpenAI model" / "Select a Claude model").
        self::assertStringContainsString('data-aqg-ai-copy="selectModel">{aiCopy.selectModel}', $template);
        self::assertStringNotContainsString('aqg-ai-model-readonly', $template);
        self::assertStringNotContainsString('{aiModelName}', $template);
        self::assertStringNotContainsString('{aiModelId}', $template);
        self::assertStringNotContainsString('Recommended', $template);
        self::assertStringNotContainsString('Preferred', $template);
        self::assertStringNotContainsString('fixed AQG model', $template);
        self::assertStringNotContainsString('<select id="aqg-ai-model"', $template);
    }

    #[Test]
    public function keyModelAndConnectionActionsHaveSeparateEndpoints(): void
    {
        $template = file_get_contents(
            dirname(__DIR__, 3) . '/Resources/Private/Partials/Settings/TabAi.html',
        );
        self::assertIsString($template);

        self::assertStringContainsString('data-save-url="{aiSettingsSaveUrl}"', $template);
        self::assertStringContainsString('data-refresh-models-url="{aiSettingsRefreshModelsUrl}"', $template);
        self::assertStringContainsString('data-select-model-url="{aiSettingsSelectModelUrl}"', $template);
        self::assertStringContainsString('data-test-url="{aiSettingsTestUrl}"', $template);
        // Key requirements are provider wording: OpenAI keeps its 2.x help text (Models endpoint, Responses).
        self::assertStringContainsString('{aiCopy.keyHelp}', $template);
        self::assertSame('settings.ai.keyHelp', AiSettingsCopy::keysFor('openai')['keyHelp']);
        self::assertSame('settings.ai.anthropic.keyHelp', AiSettingsCopy::keysFor('anthropic')['keyHelp']);
    }

    #[Test]
    public function providerChoiceIsOneRadioGroupWithSeparateSwitchAndPrivacyEndpoints(): void
    {
        $template = file_get_contents(
            dirname(__DIR__, 3) . '/Resources/Private/Partials/Settings/TabAi.html',
        );
        self::assertIsString($template);

        self::assertStringContainsString('<fieldset class="aqg-ai-providers"', $template);
        self::assertStringContainsString('name="aqg-ai-provider"', $template);
        self::assertStringContainsString('data-select-provider-url="{aiSettingsSelectProviderUrl}"', $template);
        self::assertStringContainsString('data-privacy-url="{aiSettingsPrivacyUrl}"', $template);
        self::assertStringContainsString('data-action="aqg-ai-select-provider"', $template);
        self::assertStringContainsString('data-aqg-ai-allow-images="true"', $template);
        self::assertStringContainsString('data-aqg-ai-allow-page-title="true"', $template);
        self::assertStringContainsString('data-aqg-ai-readiness="true"', $template);
        self::assertStringContainsString('{aiConfigurationStatus.usage.rows}', $template);
    }

    #[Test]
    public function siteIdentifierIsRenderedForSitesWithoutAProviderToo(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/Resources/Private/Partials/Settings/TabAi.html');
        $siteInput = strpos($template, 'data-aqg-ai-site="true"');
        $providerCondition = strpos($template, 'data-aqg-ai-provider-config="true"');

        // Regression: the input lived inside the "provider chosen" branch, so saving the first provider sent no site.
        self::assertNotFalse($siteInput);
        self::assertNotFalse($providerCondition);
        self::assertLessThan($providerCondition, $siteInput);
        self::assertSame(1, substr_count($template, 'data-aqg-ai-site="true"'));
    }

    #[Test]
    public function providerWordingCanFollowAnInPlaceSwitch(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/Resources/Private/Partials/Settings/TabAi.html');

        // Regression: the key form stayed bound to the previous provider after a card was chosen. Every provider-bound
        // text is now a copy slot, and the configuration area is always rendered so a confirmed switch needs no reload.
        self::assertStringContainsString('data-provider-copy="{aiProviderCopyJson}"', $template);
        foreach (['projectKey', 'keyHelp', 'selectModel', 'modelHelp', 'unsupportedModels', 'privacy'] as $slot) {
            self::assertStringContainsString('data-aqg-ai-copy="' . $slot . '"', $template, $slot);
        }
        self::assertStringContainsString('data-aqg-ai-copy-attr="placeholder:keyPlaceholder"', $template);
        self::assertStringContainsString('data-aqg-ai-provider-config="true"', $template);
        self::assertStringNotContainsString("<f:if condition=\"{aiConfigurationStatus.source} != 'environment'\">", $template);
        self::assertStringNotContainsString('data-reload-url', $template);
    }

    #[Test]
    public function templateNeverRendersAStoredKeyAndOffersNoPriceEntry(): void
    {
        $template = file_get_contents(
            dirname(__DIR__, 3) . '/Resources/Private/Partials/Settings/TabAi.html',
        );
        self::assertIsString($template);

        self::assertStringNotContainsString('encrypted_api_key', $template);
        self::assertStringNotContainsString('apiKey}', $template);
        self::assertStringNotContainsString('value="{aiConfigurationStatus.keyHint}"', $template);
        // Prices are automatic and read only (AQG 3.0): every amount comes from the server's pricing state, the template
        // contains no literal price, and there is no way to enter, edit or remove one. ("$1" is the minimum budget in
        // the validation message, not a price.)
        self::assertDoesNotMatchRegularExpression('/\$\s?\d+[.,]\d/', $template);
        self::assertStringNotContainsString('€', $template);
        self::assertStringContainsString('{aiConfigurationStatus.pricing.diagnostics.providers}', $template);
        self::assertStringContainsString('{aiConfigurationStatus.pricing.diagnostics.catalogue.source}', $template);
        foreach (['aqg-ai-check-prices', 'pricing-refresh-url', 'pricingRefresh', 'data-aqg-ai-pricing-due'] as $control) {
            self::assertStringNotContainsString($control, $template, 'Prices come from the AQG service; the page offers no price check: ' . $control);
        }
        foreach (['priceTable', 'aqg-ai-price-edit', 'aqg-ai-price-save', 'aqg-ai-price-remove', 'data-aqg-ai-price-form', 'price-save-url', 'settings.ai.prices.'] as $manual) {
            self::assertStringNotContainsString($manual, $template, $manual);
        }
        self::assertStringContainsString('settings.ai.usage.invoiceNote', $template);
        self::assertStringNotContainsString('settings.ai.usage.noCost', $template);
    }

    #[Test]
    public function everyProviderCopyKeyHasEnglishAndGermanLabels(): void
    {
        $english = (string)file_get_contents(dirname(__DIR__, 3) . '/Resources/Private/Language/locallang.xlf');
        $german = (string)file_get_contents(dirname(__DIR__, 3) . '/Resources/Private/Language/de.locallang.xlf');

        foreach (['openai', 'anthropic'] as $provider) {
            foreach (AiSettingsCopy::keysFor($provider) as $slot => $key) {
                self::assertStringContainsString('<trans-unit id="' . $key . '"', $english, $provider . ':' . $slot);
                self::assertStringContainsString('<trans-unit id="' . $key . '"', $german, $provider . ':' . $slot);
            }
        }
    }

    #[Test]
    public function summaryExposesOneSharedAjaxUiStateContract(): void
    {
        $template = file_get_contents(
            dirname(__DIR__, 3) . '/Resources/Private/Partials/Settings/TabAi.html',
        );
        self::assertIsString($template);

        self::assertStringContainsString('data-connection-status="{aiConfigurationStatus.status}"', $template);
        self::assertStringContainsString('data-error-code="{aiConfigurationStatus.errorCode}"', $template);
        self::assertStringContainsString('data-aqg-ai-status-badge="true"', $template);
        self::assertStringContainsString('data-aqg-ai-last-tested="true"', $template);
        self::assertStringContainsString('data-aqg-ai-last-verified="true"', $template);
        self::assertStringContainsString('data-aqg-ai-persistent-status="true"', $template);
        self::assertStringContainsString('data-aqg-ai-unsupported-models="true"', $template);
        self::assertStringContainsString('data-aqg-ai-unsupported-count="true"', $template);
        self::assertStringContainsString('data-aqg-ai-unsupported-list="true"', $template);
        self::assertStringContainsString('aiConfigurationStatus.actions.testConnectionEnabled', $template);
    }

    #[Test]
    public function onePageInFourGroupsWithAnInPageNavigationAndNoNestedTabs(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/Resources/Private/Partials/Settings/TabAi.html');

        // 3.0.0 usage & length pass: anchor navigation to four labelled groups, in this order; no tablist inside the tab.
        preg_match_all('/<section class="aqg-ai-group" id="aqg-ai-section-([a-z]+)"/', $template, $groups);
        self::assertSame(['provider', 'privacy', 'usage', 'diagnostics'], $groups[1]);
        foreach ($groups[1] as $group) {
            self::assertStringContainsString('href="#aqg-ai-section-' . $group . '"', $template);
            self::assertStringContainsString('<h3 class="aqg-ai-group__title" id="aqg-ai-group-' . $group . '-title">', $template);
        }
        self::assertStringContainsString('<nav class="aqg-ai-sectionnav"', $template);
        self::assertStringNotContainsString('role="tablist"', $template);
        self::assertStringNotContainsString('role="tab"', $template);
        // Card titles are h4 under the group h3; only group titles are h3.
        self::assertSame(4, substr_count($template, '<h3 '));
        // Critical state stays visible: the acknowledgement, the budget form and failure states are not in a disclosure.
        foreach (['data-aqg-ai-ack-checkbox', 'id="aqg-ai-budget"', 'id="aqg-ai-hard-stop-on"', 'data-aqg-ai-persistent-status="true"', 'id="aqg-ai-allow-images"'] as $needle) {
            $position = strpos($template, $needle);
            self::assertNotFalse($position, $needle);
            $before = substr($template, 0, $position);
            self::assertSame(substr_count($before, '</details>'), substr_count($before, '<details'), $needle . ' must not sit inside a closed <details>.');
        }
        // The per-person table is open (primary answer), the feature/model table and the matrix are disclosures.
        self::assertStringContainsString('<div class="aqg-ai-people" data-aqg-ai-usage-people="true">', $template);
        self::assertStringContainsString('<details class="aqg-ai-details" data-aqg-ai-usage-features="true">', $template);
        self::assertStringContainsString('<details class="aqg-ai-details" data-aqg-ai-matrix-details="true">', $template);
        // Every new control is labelled.
        self::assertStringContainsString('<label class="aqg-ai-monthpick__label" for="aqg-ai-usage-month">', $template);
        self::assertStringContainsString('<select id="aqg-ai-usage-month"', $template);
        self::assertStringNotContainsString('&amp;&amp;', $template);
    }
}
