<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Ai;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Service\AiSettingsUiStateBuilder;
use Priebera\A11yQualityGate\Ai\Settings\AiBackendUserNames;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageSummaryService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Usage\AiUsageRepositoryDouble;
use Priebera\A11yQualityGate\Utility\BackendLocaleFormatter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * The AI tab renders the readiness line with acknowledgement and budget, the acknowledgement block, the budget states,
 * the limits form without a preselected hard-stop choice and the price table without shipped prices (UX Atlas 01 A, E,
 * F, K).
 */
final class AiSettingsTabRenderingTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->getConnectionPool()->getConnectionForTable('be_users')
            ->insert('be_users', ['uid' => 1, 'username' => 'ada', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.test/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    protected function tearDown(): void
    {
        // The request without normalizedParams must not reach later tests (icon rendering reads it).
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function aVerifiedSiteWithoutAcknowledgementShowsAutomaticPricesAndWhyAiIsOff(): void
    {
        $html = $this->render($this->tabStatus(['status' => 'connected']));

        self::assertStringContainsString('data-ready-item="acknowledgement" data-state="missing"', $html);
        self::assertStringContainsString('Acknowledge privacy for OpenAI', $html);
        self::assertStringContainsString('AI not active for editors', $html, 'A verified connection is not "ready for editors".');
        self::assertMatchesRegularExpression('/data-aqg-ai-not-ready-note="true"(?![^>]*hidden)/', $html);
        self::assertStringContainsString('AI cannot be activated for this site', $html);
        self::assertStringContainsString('I confirm that a data processing agreement (DPA) with OpenAI covers this site, or that sending this content is otherwise lawful.', $html);
        // Automatic, read-only pricing: the model row and the selected model carry their price; nothing to enter.
        self::assertStringContainsString('$0.75 in / $4.50 out', $html);
        self::assertStringContainsString('GPT-5.4 mini: $0.75 input, $4.50 output, $0.075 cached input per 1M tokens · checked 2 Nov 2026', $html);
        self::assertStringContainsString('Prices from the list shipped with AQG (2 Nov 2026) until the AQG service is reached.', $html);
        self::assertStringContainsString('Automatic. The AQG service checks the official OpenAI and Anthropic pricing pages every day', $html);
        self::assertStringContainsString('Not asked yet; AQG asks it automatically when prices are needed.', $html);
        // A consumer of the central catalogue: no price check, no schedule, no pricing endpoint in the page.
        foreach (['aqg-ai-check-prices', 'pricing-refresh', 'pricing_refresh', 'a11y:ai:prices', 'Scheduler'] as $control) {
            self::assertStringNotContainsString($control, $html, $control);
        }
        self::assertDoesNotMatchRegularExpression('/<input id="aqg-ai-budget"[^>]*disabled/', $html, 'A budget never waits for a price.');
        self::assertStringNotContainsString('Add price', $html);
        self::assertStringNotContainsString('price/save', $html);
        self::assertStringNotContainsString('maintained by administrators', $html);
        self::assertStringContainsString('Provider invoices can differ (caching, discounts, taxes). AQG always shows cost as an estimate.', $html);
        // Open owner decision 01#4: neither hard-stop choice is preselected.
        self::assertDoesNotMatchRegularExpression('/id="aqg-ai-hard-stop-(on|off)"[^>]*checked/', $html);
    }

    #[Test]
    public function theModelPricesCardShowsWhereThePricesComeFrom(): void
    {
        $catalogue = ['source' => 'central', 'version' => 12, 'refreshedLabel' => '2 Nov 2026 14:00', 'publishedLabel' => '2 Nov 2026 13:45', 'lastAttemptLabel' => '2 Nov 2026 14:00', 'failing' => false, 'endpointMissing' => false, 'answerRefused' => false, 'errorReason' => '', 'serviceUrl' => ''];
        $status = $this->tabStatus(['status' => 'connected']);
        $status['pricing']['diagnostics']['catalogue'] = $catalogue;
        $html = $this->render($status);
        self::assertStringContainsString('Prices from the AQG service, updated 2 Nov 2026 14:00.', $html);
        self::assertStringContainsString('AQG service, catalogue version 12 (published 2 Nov 2026 13:45)', $html);
        self::assertStringContainsString('Reached at 2 Nov 2026 14:00', $html);
        self::assertStringNotContainsString('The last verified prices stay in use', $html);

        // An AQG service without the endpoint (extension upgraded first): bundled prices, one calm note, no error tone.
        $status['pricing']['diagnostics']['catalogue'] = ['source' => 'bundled', 'version' => 0, 'refreshedLabel' => '', 'failing' => true, 'endpointMissing' => true, 'errorReason' => 'endpoint_missing', 'lastAttemptLabel' => '2 Nov 2026 14:05'] + $catalogue;
        $status['pricing']['diagnostics']['anyFailed'] = true;
        $html = $this->render($status);
        self::assertStringContainsString('Prices from the list shipped with AQG (2 Nov 2026) until the AQG service is reached. The last verified prices stay in use; AQG tries again automatically.', preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
        self::assertStringContainsString('Does not offer the price catalogue yet (checked 2 Nov 2026 14:05); AQG asks again daily.', $html);
        self::assertStringContainsString('Last prices kept', $html);

        // Reached, but an older catalogue than the copy: refused, said as such — not an outage.
        $status['pricing']['diagnostics']['catalogue'] = ['source' => 'central', 'version' => 12, 'failing' => true, 'endpointMissing' => false, 'answerRefused' => true, 'errorReason' => 'version_regressed', 'lastAttemptLabel' => '2 Nov 2026 15:00'] + $catalogue;
        $html = $this->render($status);
        self::assertStringContainsString('Reached at 2 Nov 2026 15:00, but its answer was not used (version_regressed); AQG tries again within the hour.', $html);
        self::assertStringNotContainsString('Not reached at', $html);
    }

    #[Test]
    public function aModelWithoutACompletePriceUnderAHardStopExplainsTheRefusal(): void
    {
        $status = $this->tabStatus();
        $status['budget'] = ['state' => 'no_price', 'blocking' => true, 'hasBudget' => true, 'hardStop' => true, 'priceKnown' => false, 'modelLabel' => 'gpt-9-preview'] + $status['budget'];
        $status['pricing']['selected'] = ['known' => false, 'state' => 'none'];

        $html = $this->render($status);

        self::assertStringContainsString('Budget not enforceable: no complete price for gpt-9-preview', $html);
        self::assertStringContainsString('No complete automatic price for gpt-9-preview', $html);
        self::assertStringContainsString('The hard stop keeps AI paused for editors until a supported model is selected.', $html);
        self::assertStringContainsString('AQG has no automatic price for gpt-9-preview', $html);
    }

    #[Test]
    public function aReachedHardStopBudgetAndAConfirmedAcknowledgementAreShown(): void
    {
        $html = $this->render($this->tabStatus([
            'readiness' => ['licence' => 'ok', 'provider' => 'ok', 'key' => 'ok', 'model' => 'ok', 'acknowledgement' => 'ok', 'budget' => 'missing'],
            'acknowledgement' => ['confirmed' => true, 'provider' => 'openai', 'providerLabel' => 'OpenAI', 'byName' => 'Ada Admin', 'at' => 1, 'atDate' => '2 Oct 2026'],
            'budget' => ['state' => 'reached', 'hasBudget' => true, 'budgetLabel' => '$50.00', 'budgetInput' => '50', 'warningPercent' => 80, 'warningInput' => '80', 'hardStop' => true, 'committedLabel' => '$50.00', 'percent' => 100, 'barPercent' => 100, 'priceKnown' => true, 'blockCode' => 'budget_reached', 'blocking' => true, 'resumeDate' => '1 Nov 2026', 'modelLabel' => 'GPT-5.4 mini', 'modelSelected' => true],
            'usage' => $this->usage([$this->usageRow(1, 'alt_text', 'success', 900, 30, 50_000_000, 1, 'reported')]),
        ]));

        self::assertStringContainsString('Confirmed by Ada Admin on 2 Oct 2026 · applies to OpenAI on this site only.', $html);
        self::assertStringContainsString('Monthly budget reached — AI is paused for editors', $html);
        self::assertStringContainsString('AI is available again from 1 Nov 2026.', $html);
        self::assertStringContainsString('$50.00 of $50.00 · 100 %', $html);
        self::assertStringContainsString('aria-label="100 % of budget used"', $html);
        self::assertMatchesRegularExpression('/id="aqg-ai-hard-stop-on"[^>]*checked="checked"/', $html);
        self::assertStringContainsString('Cost (estimated)', $html);
        self::assertStringContainsString('data-aqg-ai-ack-blocked="true" hidden="hidden"', $html);
        self::assertMatchesRegularExpression('/<input id="aqg-ai-ack-confirm"[^>]*checked="checked" disabled="disabled"/', $html);
    }

    #[Test]
    public function aWarningBudgetInformsWithoutBlocking(): void
    {
        $html = $this->render($this->tabStatus([
            'readiness' => ['licence' => 'ok', 'provider' => 'ok', 'key' => 'ok', 'model' => 'ok', 'acknowledgement' => 'ok', 'budget' => 'warn'],
            'budget' => ['state' => 'warning', 'hasBudget' => true, 'budgetLabel' => '$50.00', 'budgetInput' => '50', 'warningPercent' => 80, 'warningInput' => '80', 'hardStop' => true, 'committedLabel' => '$41.20', 'percent' => 82, 'barPercent' => 82, 'priceKnown' => true, 'blockCode' => '', 'blocking' => false, 'resumeDate' => '1 Nov 2026', 'modelLabel' => 'GPT-5.4 mini', 'modelSelected' => true],
        ]));

        self::assertStringContainsString('data-ready-item="budget" data-state="warn"', $html);
        self::assertStringContainsString('Budget: 82 % of $50.00', $html);
        self::assertStringContainsString('82 % of the monthly budget used', $html);
        self::assertStringContainsString('Estimated $41.20 of $50.00. At 100 % AI pauses for editors until 1 Nov 2026.', $html);
        self::assertStringNotContainsString('%%', strip_tags($html));
    }

    #[Test]
    public function theLimitsFormShowsTheBudgetAlertAddressAndItsEffectiveRecipient(): void
    {
        $budget = ['state' => 'under', 'hasBudget' => true, 'budgetLabel' => '$50.00', 'budgetInput' => '50', 'warningPercent' => 80, 'warningInput' => '80', 'hardStop' => true, 'committedLabel' => '$1.00', 'percent' => 2, 'barPercent' => 2, 'priceKnown' => true, 'blockCode' => '', 'blocking' => false, 'resumeDate' => '1 Dec 2026', 'modelLabel' => 'GPT-5.4 mini', 'modelSelected' => true];

        $site = $this->render($this->tabStatus(['budget' => $budget + ['alertEmailInput' => 'alerts@example.com', 'alertRecipient' => 'alerts@example.com', 'alertRecipientSource' => 'site', 'alertActive' => true]]));
        self::assertMatchesRegularExpression('/<label class="aqg-field__label" for="aqg-ai-budget-alert-email">Budget alert e-mail<\/label>/', $site);
        self::assertMatchesRegularExpression('/<input id="aqg-ai-budget-alert-email"[^>]*type="email"[^>]*value="alerts@example.com"/', $site);
        self::assertStringContainsString('Alerts go to alerts@example.com.', $site);
        self::assertStringNotContainsString('An alert needs a monthly budget and a warning threshold.', $site);
        self::assertStringContainsString('data-message-invalid-alert-email="Enter one valid e-mail address, or leave the field empty."', $site);

        $typo3 = $this->render($this->tabStatus(['budget' => $budget + ['alertEmailInput' => '', 'alertRecipient' => 'ops@example.org', 'alertRecipientSource' => 'typo3', 'alertActive' => true]]));
        self::assertStringContainsString('Alerts go to ops@example.org (TYPO3 warning e-mail address, [BE][warning_email_addr]).', $typo3);

        $none = $this->render($this->tabStatus());
        self::assertStringContainsString('No recipient: no budget alert e-mail is sent. An alert needs a monthly budget and a warning threshold.', $none);
    }

    #[Test]
    public function aCostBelowOneCentCarriesOneQualifierAndCountsUseTheLocaleSeparator(): void
    {
        // Atlas audit F-27 / F-16: "≈ < $0.01" was a double qualifier; German counts read "1.332", not "1,332".
        $rows = [];
        for ($i = 0; $i < 1332; $i++) {
            $rows[] = $this->usageRow(1, 'alt_text', 'success', 1, 0, $i === 0 ? 4_000 : 0, 1, 'reported');
        }
        $html = $this->render($this->tabStatus(['thousandsSeparator' => '.', 'usage' => $this->usage($rows, 'de')]));

        self::assertStringContainsString("<dd>< 0,01\u{00A0}$</dd>", html_entity_decode($html));
        self::assertStringNotContainsString('≈ <', html_entity_decode($html));
        self::assertStringContainsString('>1.332<', $html);
        self::assertStringNotContainsString('1,332', $html);

        $html = $this->render($this->tabStatus(['usage' => $this->usage([$this->usageRow(1, 'alt_text', 'success', 1332, 30, 5_950_000, 1, 'reported')])]));
        self::assertStringContainsString('≈ $5.95', $html);
        self::assertStringContainsString('>1,332<', $html);
    }

    #[Test]
    public function usageIsBrokenDownByPersonAndReconcilesWithTheSiteTotal(): void
    {
        $users = $this->getConnectionPool()->getConnectionForTable('be_users');
        $users->insert('be_users', ['uid' => 2, 'username' => 'eva', 'realName' => 'Eva Editor', 'admin' => 0, 'disable' => 0, 'deleted' => 0]);
        $users->insert('be_users', ['uid' => 3, 'username' => 'gone', 'realName' => 'Greta Gone', 'admin' => 0, 'disable' => 0, 'deleted' => 1]);
        $users->insert('be_users', ['uid' => 4, 'username' => 'paused', 'realName' => '', 'admin' => 0, 'disable' => 1, 'deleted' => 0]);
        $html = $this->render($this->tabStatus(['usage' => $this->usage([
            $this->usageRow(2, 'alt_text', 'success', 1000, 100, 120_000, 1, 'reported'),
            $this->usageRow(2, 'alt_text', 'failed_1771002330', 0, 0, 900_000, 1, 'bound'),
            $this->usageRow(2, 'assistant', 'pending', 0, 0, 0, 1, 'pending', 2_000_000),
            $this->usageRow(3, 'explain', 'success', 800, 80, 100_000, 1, 'reported'),
            $this->usageRow(4, 'writing', 'success', 400, 40, 50_000, 1, 'reported'),
            $this->usageRow(0, 'connection_test', 'failed_1771002310', 0, 0, 0, 1, 'none'),
            $this->usageRow(2, 'iframe_title', 'success', 700, 70, 0, 0, 'unknown'),
        ])]));
        $text = html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');

        self::assertStringContainsString('By person 4 people', $text);
        self::assertStringContainsString('Eva Editor eva · #2 · Alt text 2, Ask AQG in the toolbar 1, Iframe titles 1 4 1 1 1,700 170 ≈ $1.02 + $2.00 reserved 1 without price', $text);
        self::assertStringContainsString('Greta Gone Deleted user gone · #3 · Explanations in editors 1 1 0 0 800 80 ≈ $0.10', $text);
        self::assertStringContainsString('paused Disabled #4 · Writing help 1 1 0 0 400 40 ≈ $0.05', $text);
        self::assertStringContainsString('No backend user Connection tests 1 1 1 0 0 0 $0.00 rejected before processing', $text);
        self::assertStringContainsString('Site total 7 2 1 2,900 290 ≈ $1.17 + $2.00 reserved 1 without price', $text);
        self::assertStringContainsString('Estimated spend ≈ $1.17 USD · settled requests', $text);
        self::assertStringContainsString('4 succeeded · 2 failed · 1 pending', $text);
        self::assertStringContainsString('Reserved for pending $2.00 Worst case, not spend', $text);
        self::assertStringContainsString('$0.90 of the estimate is the worst case of 1 request without usage data', $text);
        self::assertStringContainsString('1 request was rejected before processing', $text);
        self::assertStringContainsString('1 request used a model without an automatic price', $text);
        self::assertStringContainsString('1 request was sent but is not settled yet. $2.00 is reserved', $text);
        self::assertStringContainsString('By feature and model', $text);
        self::assertStringContainsString('≈ $1.17 · 7 requests', $text, 'The section navigation carries the month\'s spend.');
        self::assertMatchesRegularExpression('/<div class="aqg-ai-tablewrap aqg-ai-tablewrap--scroll" role="region" tabindex="0" aria-labelledby="aqg-ai-usage-people-title">/', $html);
    }

    #[Test]
    public function perRowRoundingIsExplainedWithBothFigures(): void
    {
        $html = $this->render($this->tabStatus(['usage' => $this->usage([
            $this->usageRow(1, 'alt_text', 'success', 10, 1, 6_000, 1, 'reported'),
            $this->usageRow(0, 'alt_text', 'success', 10, 1, 6_000, 1, 'reported'),
        ])]));
        $text = html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');

        self::assertStringContainsString('Each cost is rounded to the cent: the rows add up to $0.02, the site total $0.01 is rounded once from the exact amounts. Nothing is missing; the CSV export lists the exact amounts.', $text);
        self::assertStringContainsString('data-aqg-ai-usage-rounding="estimated"', $html);

        $consistent = $this->render($this->tabStatus(['usage' => $this->usage([$this->usageRow(1, 'alt_text', 'success', 10, 1, 50_000, 1, 'reported')])]));
        self::assertStringNotContainsString('data-aqg-ai-usage-rounding', $consistent);
    }

    #[Test]
    public function theUsageCardOffersTheCsvExportAndCarriesTheLiveStateHooks(): void
    {
        $html = $this->render($this->tabStatus(['usage' => $this->usage([$this->usageRow(1, 'alt_text', 'success', 10, 1, 50_000, 1, 'reported')])]), ['aiUsageExportUrl' => '/typo3/module/web/a11y/settings/ai-usage-export?token=t&site=main&month=2026-11']);

        self::assertMatchesRegularExpression('#<a class="btn btn-default btn-sm aqg-ai-people__export" href="/typo3/module/web/a11y/settings/ai-usage-export\?token=t&amp;site=main&amp;month=2026-11" download="" data-aqg-ai-usage-export="true">Export CSV<span class="visually-hidden"> – AI usage by person in \w+ \d{4}</span></a>#', $html);
        self::assertMatchesRegularExpression('/data-aqg-ai-usage="true" data-usage-month="\d{4}-\d{2}" data-usage-requests="1" data-usage-current="1"/', $html);
        self::assertMatchesRegularExpression('/data-aqg-ai-usage-stale="true" hidden="hidden"/', $html);
        foreach (['privacy', 'usage', 'diagnostics'] as $part) {
            self::assertStringContainsString('data-aqg-ai-nav-state="' . $part . '"', $html);
        }
        self::assertStringContainsString('data-label-terms-confirmed="Terms confirmed"', $html);
        self::assertStringContainsString('data-label-budget-warning="%s %% of budget"', $html);

        $noUrl = $this->render($this->tabStatus(['usage' => $this->usage([$this->usageRow(1, 'alt_text', 'success', 10, 1, 50_000, 1, 'reported')])]));
        self::assertStringNotContainsString('data-aqg-ai-usage-export', $noUrl, 'No export link without the server-built URL (non-admins get none).');
    }

    #[Test]
    public function onlyRejectedRequestsReadAsZeroByRuleNotAsFreeEstimate(): void
    {
        $rows = array_fill(0, 6, $this->usageRow(1, 'alt_text', 'failed_1771002310', 0, 0, 0, 1, 'none'));
        $text = html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($this->render($this->tabStatus(['usage' => $this->usage($rows)])))) ?? '');

        self::assertStringContainsString('Estimated spend $0.00', $text);
        self::assertStringNotContainsString('≈ $0.00', $text);
        self::assertStringContainsString('0 succeeded · 6 failed · 0 pending', $text);
        self::assertStringContainsString('$0 by rule 6 requests were rejected before processing', $text);
        self::assertStringNotContainsString('AQG does not estimate them', $text);
    }

    #[Test]
    public function aMonthWithoutUsageAndAFailedLoadAreTold(): void
    {
        $empty = html_entity_decode(strip_tags($this->render($this->tabStatus(['usage' => $this->usage([])]))));
        self::assertMatchesRegularExpression('/No AI requests from this site in \w+ \d{4}\./', $empty);

        $failed = $this->render($this->tabStatus(['usage' => ['loadFailed' => true, 'monthLabel' => 'November 2026', 'totals' => ['requests' => 0, 'costState' => 'zero'], 'people' => [], 'rows' => []]]));
        self::assertStringContainsString('AI usage could not be loaded', $failed);
        self::assertStringNotContainsString('No AI requests from this site', $failed);
    }

    /** @return array<string,int|string> */
    private function usageRow(int $user, string $feature, string $outcome, int $in, int $out, int $cost, int $known, string $basis, int $reserved = 0): array
    {
        return [
            'site_identifier' => 'main', 'be_user' => $user, 'feature' => $feature, 'provider' => 'openai', 'model' => 'gpt-5.4-mini',
            'outcome' => $outcome, 'input_tokens' => $in, 'output_tokens' => $out, 'usage_reported' => $basis === 'reported' || $basis === 'unknown' ? 1 : 0,
            'estimated_cost_micro_usd' => $cost, 'cost_known' => $known, 'cost_basis' => $basis, 'reserved_cost_micro_usd' => $reserved, 'crdate' => time(),
        ];
    }

    /**
     * The usage state as the Settings controller gets it: the real summary service over the given rows, localized by
     * the real UI state builder with the real backend user names.
     *
     * @param list<array<string,int|string>> $rows
     * @return array<string,mixed>
     */
    private function usage(array $rows, string $locale = 'en'): array
    {
        $repository = new AiUsageRepositoryDouble();
        foreach ($rows as $row) {
            $repository->add($row);
        }
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('status')->willReturn([]);
        $builder = new AiSettingsUiStateBuilder(
            $resolver,
            new AiUsageSummaryService($repository, new AiModelCompatibilityRegistry()),
            null,
            null,
            $this->get(AiBackendUserNames::class),
        );

        return $builder->build('main', new BackendLocaleFormatter($locale))['usage'];
    }

    /** @param array<string,mixed> $overrides */
    private function tabStatus(array $overrides = []): array
    {
        return array_replace([
            'provider' => 'openai',
            'providerLabel' => 'OpenAI',
            'providerChosen' => true,
            'configured' => true,
            'verified' => true,
            'licenceAllowsAi' => true,
            'selectedModelId' => 'gpt-5.4-mini',
            'selectedModelLabel' => 'GPT-5.4 mini',
            'providers' => [],
            'unsupportedModels' => [],
            'actions' => ['refreshModelsEnabled' => true, 'testConnectionEnabled' => true],
            'readiness' => ['licence' => 'ok', 'provider' => 'ok', 'key' => 'ok', 'model' => 'ok', 'acknowledgement' => 'missing', 'budget' => 'ok'],
            'acknowledgement' => ['confirmed' => false, 'provider' => '', 'providerLabel' => '', 'byName' => '', 'at' => 0, 'atDate' => ''],
            'budget' => ['state' => 'none', 'hasBudget' => false, 'budgetLabel' => '$0.00', 'budgetInput' => '', 'warningPercent' => 0, 'warningInput' => '', 'hardStop' => false, 'committedLabel' => '$0.00', 'percent' => 0, 'barPercent' => 0, 'priceKnown' => false, 'blockCode' => '', 'blocking' => false, 'resumeDate' => '1 Nov 2026', 'modelLabel' => 'GPT-5.4 mini', 'modelSelected' => true],
            'availableModels' => [
                ['id' => 'gpt-5.4-mini', 'label' => 'GPT-5.4 mini', 'price' => ['known' => true, 'inputLabel' => '$0.75', 'outputLabel' => '$4.50', 'cachedInputLabel' => '$0.075']],
            ],
            'modelSelected' => true,
            'pricing' => [
                'selected' => ['known' => true, 'state' => 'current', 'inputLabel' => '$0.75', 'outputLabel' => '$4.50', 'cachedInputLabel' => '$0.075', 'verifiedLabel' => '2 Nov 2026', 'longContext' => false],
                'diagnostics' => [
                    'catalogue' => ['source' => 'bundled', 'version' => 0, 'refreshedLabel' => '', 'publishedLabel' => '', 'lastAttemptLabel' => '', 'failing' => false, 'endpointMissing' => false, 'answerRefused' => false, 'errorReason' => '', 'serviceUrl' => 'https://api.example.test/ai/pricing/v1/catalogue'],
                    'providers' => [
                        ['id' => 'openai', 'label' => 'OpenAI', 'sourceUrl' => 'https://developers.openai.com/api/docs/pricing.md', 'lastVerifiedLabel' => '2 Nov 2026', 'catalogue' => []],
                    ],
                    'legacy' => [],
                    'anyFailed' => false,
                    'bundledLabel' => '2 Nov 2026',
                ],
            ],
            'usage' => ['periodStartDate' => '2026-10-01', 'rows' => [], 'totals' => []],
            'thousandsSeparator' => ',',
        ], $overrides);
    }

    /** @param array<string,mixed> $status */
    private function render(array $status, array $extra = []): string
    {
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Partials/')],
            templatePathAndFilename: GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Partials/Settings/TabAi.html'),
        ));
        $view->assignMultiple([
            'aiConfigurationStatus' => $status,
            'aiSiteIdentifier' => 'main',
            'aiCopy' => [],
            'aiSiteOptions' => [],
        ] + $extra);

        return $view->render();
    }
}
