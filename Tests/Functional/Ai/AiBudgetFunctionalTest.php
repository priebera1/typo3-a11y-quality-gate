<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Ai;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Pricing\AiBundledPriceCatalogue;
use Priebera\A11yQualityGate\Ai\Pricing\AiPriceCatalogueService;
use Priebera\A11yQualityGate\Ai\Pricing\AiPricingStatusService;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetService;
use Priebera\A11yQualityGate\Ai\Usage\AiCostBound;
use Priebera\A11yQualityGate\Ai\Usage\AiPrice;
use Priebera\A11yQualityGate\Ai\Usage\AiPriceTable;
use Priebera\A11yQualityGate\Ai\Usage\AiUsage;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageMeter;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageSummaryService;
use Priebera\A11yQualityGate\Controller\AiSettingsAjaxController;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\AiBudgetRepository;
use Priebera\A11yQualityGate\Domain\Repository\AiPriceCatalogueRepository;
use Priebera\A11yQualityGate\Domain\Repository\AiPriceRepository;
use Priebera\A11yQualityGate\Domain\Repository\AiSiteSettingRepository;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * Automatic price catalogue, monthly budget and processing acknowledgement with the real database, DI container and
 * backend users (UX Atlas 01 E, F): integer money, the local copy of the central catalogue and its refresh claim, the per-site-month lock and reservations, upgrade
 * safety of pre-3.0 prices and usage, and administrator-only routes without any price entry.
 */
final class AiBudgetFunctionalTest extends AbstractFunctionalTestCase
{
    private string|false $apiBaseUrl = false;

    protected function setUp(): void
    {
        parent::setUp();
        // A closed local port: a due catalogue refresh fails at once and never reaches a real AQG service.
        $this->apiBaseUrl = getenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL');
        putenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL=http://127.0.0.1:9');
        $connection = $this->getConnectionPool()->getConnectionForTable('be_users');
        foreach ([[1, 'ada', 'Ada Admin', 1], [3, 'eva', 'Eva Editor', 0]] as [$uid, $username, $realName, $admin]) {
            $connection->insert('be_users', ['uid' => $uid, 'username' => $username, 'realName' => $realName, 'admin' => $admin, 'disable' => 0, 'deleted' => 0]);
        }
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://example.test/');
    }

    protected function tearDown(): void
    {
        putenv($this->apiBaseUrl === false ? 'A11Y_QUALITY_GATE_PRO_API_BASE_URL' : 'A11Y_QUALITY_GATE_PRO_API_BASE_URL=' . $this->apiBaseUrl);
        parent::tearDown();
    }

    #[Test]
    public function theCentralCatalogueCopyIsWrittenOnlyUnderItsClaimAndAsOneTransaction(): void
    {
        $subject = $this->get(AiPriceCatalogueRepository::class);
        $now = time();
        $lease = AiPriceCatalogueService::LEASE_SECONDS;

        $token = $subject->claim($now, $lease);
        self::assertIsString($token);
        self::assertNull($subject->claim($now + 10, $lease), 'A second request waits for the first.');

        $rates = (string)json_encode(['standard' => ['input' => 800_000, 'output' => 4_800_000, 'cached_input' => 80_000, 'cache_write' => 800_000, 'cache_write_extended' => 800_000], 'long_context' => null]);
        $mini = ['provider' => 'openai', 'model' => 'gpt-5.4-mini', 'label' => 'gpt-5.4-mini', 'rates' => $rates, 'source_url' => AiBundledPriceCatalogue::OPENAI_SOURCE_URL, 'verified_at' => $now - 60, 'listed' => true];
        self::assertFalse($subject->storeCatalogue('stale-token', 4, '"v4"', $now, [$mini], $now));
        self::assertSame(0, $this->countRows(Tables::AI_MODEL_PRICE), 'A refresh that lost its claim writes nothing.');

        self::assertTrue($subject->storeCatalogue($token, 4, '"aqg-ai-prices-4"', $now - 60, [$mini], $now));
        $subject->releaseClaim($token);
        $price = $this->get(AiPriceTable::class)->find('openai', 'gpt-5.4-mini-2026-03-17');
        self::assertSame(800_000, $price?->rates->input);
        self::assertSame(AiPrice::ORIGIN_CENTRAL, $price?->origin);
        self::assertTrue($price?->complete);
        $state = $subject->findState();
        self::assertSame([4, '"aqg-ai-prices-4"', $now, 0], [(int)$state['catalogue_version'], $state['etag'], (int)$state['refreshed_at'], (int)$state['consecutive_failures']]);

        // The next catalogue no longer lists gpt-5.4-mini; then two refreshes fail.
        $token = (string)$subject->claim($now + 100, $lease);
        $nano = ['model' => 'gpt-5.4-nano', 'label' => 'gpt-5.4-nano', 'rates' => (string)json_encode(['standard' => ['input' => 200_000, 'output' => 1_250_000, 'cached_input' => 20_000, 'cache_write' => 200_000, 'cache_write_extended' => 200_000], 'long_context' => null])] + $mini;
        self::assertTrue($subject->storeCatalogue($token, 5, '"aqg-ai-prices-5"', $now, [$nano], $now + 100));
        $subject->storeFailure($token, $now + 200, 'catalogue_unavailable');
        $subject->storeFailure($token, $now + 300, 'transport_error');
        $subject->storeFailure('stale-token', $now + 301, 'ignored');
        $subject->releaseClaim($token);

        $row = $subject->findPrice('openai', 'gpt-5.4-mini');
        self::assertSame(0, (int)$row['listed']);
        self::assertSame($now - 60, (int)$row['verified_at'], 'A model missing from the catalogue keeps its last verified price.');
        self::assertSame($rates, $row['rates']);
        self::assertSame(1, (int)$subject->findPrice('openai', 'gpt-5.4-nano')['listed']);
        $state = $subject->findState();
        self::assertSame(2, (int)$state['consecutive_failures']);
        self::assertSame($now + 100, (int)$state['refreshed_at']);
        self::assertSame('transport_error', $state['error_reason']);
        self::assertSame('', $state['claim_token']);
        self::assertSame(1, $this->countRows(Tables::AI_PRICE_CATALOGUE));
    }

    #[Test]
    public function aFirstInstallationThatCannotReachTheServicePricesWithTheBundledCatalogue(): void
    {
        $table = $this->get(AiPriceTable::class);

        $price = $table->find('anthropic', 'claude-sonnet-5-5');
        self::assertSame(AiPrice::ORIGIN_BUNDLED, $price?->origin);
        self::assertTrue($price?->complete, 'A hard-stop budget still works with the reviewed bundled prices.');
        $state = $this->get(AiPriceCatalogueRepository::class)->findState();
        self::assertSame('transport_error', $state['error_reason']);
        self::assertSame(1, (int)$state['consecutive_failures']);
        self::assertSame('', $state['claim_token']);
        self::assertFalse(AiPriceCatalogueService::isDue($state, time() + 60), 'The next attempt waits an hour.');
        $catalogue = $this->get(AiPricingStatusService::class)->diagnostics()['catalogue'];
        self::assertSame(['bundled', true, false, false, 'transport_error'], [$catalogue['source'], $catalogue['failing'], $catalogue['endpointMissing'], $catalogue['answerRefused'], $catalogue['errorReason']]);

        // An answer that was refused (older than the copy) is reported as such, not as an outage.
        $repository = $this->get(AiPriceCatalogueRepository::class);
        $token = (string)$repository->claim(time() + 7200, AiPriceCatalogueService::LEASE_SECONDS);
        $repository->storeFailure($token, time() + 7200, 'version_regressed');
        $repository->releaseClaim($token);
        self::assertTrue($this->get(AiPricingStatusService::class)->diagnostics()['catalogue']['answerRefused']);
    }

    #[Test]
    public function anUpgradedInstallationKeepsItsManualPricesAndHistoryWhileAutomaticPricesTakeOver(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(AiPriceRepository::TABLE);
        // A 3.0 RC installation: an administrator-entered price for a supported and for an unsupported model.
        $connection->insert(AiPriceRepository::TABLE, ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'input_micro_usd' => 3_000_000, 'output_micro_usd' => 15_000_000, 'price_as_of' => 1_759_276_800, 'source' => 'pricing page', 'entered_by' => 1]);
        $connection->insert(AiPriceRepository::TABLE, ['provider' => 'openai', 'model' => 'gpt-4o', 'input_micro_usd' => 2_500_000, 'output_micro_usd' => 10_000_000, 'price_as_of' => 1_759_276_800, 'source' => 'pricing page', 'entered_by' => 1]);
        [, $from] = AiBudgetService::period();
        $usage = $this->getConnectionPool()->getConnectionForTable(Tables::AI_USAGE);
        // Rows recorded before 3.0: OpenAI input_tokens still include cached tokens; one row without a price.
        $usage->insert(Tables::AI_USAGE, ['site_identifier' => 'main', 'crdate' => $from + 1, 'feature' => 'alt_text', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'outcome' => 'success', 'input_tokens' => 1_000, 'output_tokens' => 100, 'usage_reported' => 1, 'estimated_cost_micro_usd' => 4_500, 'cost_known' => 1, 'cost_basis' => 'reported']);
        $usage->insert(Tables::AI_USAGE, ['site_identifier' => 'main', 'crdate' => $from + 2, 'feature' => 'alt_text', 'provider' => 'openai', 'model' => 'gpt-5.4-mini', 'outcome' => 'success', 'input_tokens' => 2_000, 'output_tokens' => 50, 'usage_reported' => 1, 'estimated_cost_micro_usd' => 0, 'cost_known' => 0, 'cost_basis' => 'unknown']);

        $table = $this->get(AiPriceTable::class);
        self::assertSame(2_000_000, $table->find('anthropic', 'claude-sonnet-5-5')?->rates->input, 'The automatic price replaces the old manual one.');
        self::assertSame(AiPrice::ORIGIN_MANUAL, $table->find('openai', 'gpt-4o')?->origin);
        self::assertSame(2, $this->countRows(AiPriceRepository::TABLE), 'Manual prices are kept, never deleted.');

        $summary = $this->get(AiUsageSummaryService::class)->currentMonth('main');
        self::assertSame(3_000, $summary['totals']['inputTokens']);
        self::assertSame(4_500, $summary['totals']['costMicroUsd'], 'Recorded cost is never repriced.');
        self::assertSame(1, $summary['totals']['costUnknown'], 'A request without a price stays unknown.');
    }

    #[Test]
    public function reservationsAreCheckedAgainstRecordedCostAndOpenReservationsOfTheMonth(): void
    {
        [$period, $from, $until] = AiBudgetService::period();
        $usage = $this->getConnectionPool()->getConnectionForTable(Tables::AI_USAGE);
        $usage->insert(Tables::AI_USAGE, ['site_identifier' => 'main', 'crdate' => $from + 10, 'estimated_cost_micro_usd' => 600, 'cost_known' => 1]);
        // Last month and another site never count.
        $usage->insert(Tables::AI_USAGE, ['site_identifier' => 'main', 'crdate' => $from - 10, 'estimated_cost_micro_usd' => 900, 'cost_known' => 1]);
        $usage->insert(Tables::AI_USAGE, ['site_identifier' => 'other', 'crdate' => $from + 10, 'estimated_cost_micro_usd' => 900, 'cost_known' => 1]);
        $subject = $this->get(AiBudgetRepository::class);

        self::assertSame(600, $subject->spentMicroUsd('main', $from, $until));
        $first = $subject->reserve('main', $period, $from, $until, 1_000, 300);
        self::assertIsInt($first);
        self::assertSame(300, $subject->reservedMicroUsd('main', $period));
        // 600 recorded + 300 reserved + 200 would exceed the 1,000 budget: the second request does not fit.
        self::assertNull($subject->reserve('main', $period, $from, $until, 1_000, 200));
        self::assertIsInt($subject->reserve('main', $period, $from, $until, 1_000, 100), 'Exactly the budget still fits.');

        $subject->release($first);
        self::assertSame(100, $subject->reservedMicroUsd('main', $period));
        self::assertSame(1, $this->countRows(AiBudgetRepository::LOCK_TABLE, ['site_identifier' => 'main', 'period' => $period]));
    }

    #[Test]
    public function aNewMonthStartsWithoutTheLeftoverReservationsOfTheLastOne(): void
    {
        $subject = $this->get(AiBudgetRepository::class);
        self::assertIsInt($subject->reserve('main', 202609, 0, 1, 1_000, 900));

        self::assertIsInt($subject->reserve('main', 202610, 0, 1, 1_000, 900));
        self::assertSame(0, $subject->reservedMicroUsd('main', 202609));
        self::assertSame(0, $this->countRows(AiBudgetRepository::LOCK_TABLE, ['site_identifier' => 'main', 'period' => 202609]));
    }

    #[Test]
    public function aHardStopBudgetRefusesBeforeTheRequestAndSettlesTheActualCost(): void
    {
        // No price setup: Claude Sonnet 5.5 is priced by the bundled catalogue ($2 input, $2.50 cache write, $10 output).
        $settings = $this->get(AiSiteSettingRepository::class);
        $settings->saveProvider('main', 'anthropic', 1);
        // $0.05: the worst case of a small request (1,100 input at $2.50 + 2,148 output at $10 ≈ $0.024) fits, a huge one does not.
        $settings->saveBudget('main', 50_000, 0, true, 1);
        $meter = $this->get(AiUsageMeter::class);
        $recorder = $this->get(AiUsageRecorder::class);
        $configuration = new AiProviderConfiguration('anthropic', 'sk-ant-test', 'claude-sonnet-5-5', 'site', 'fp');

        $recorder->track('main', 'explain', $configuration, static function () use ($meter): string {
            $meter->report(new AiUsage(1_000, 100, true, 400));

            return 'ok';
        }, new AiCostBound(1_100, 2_148));

        $row = $this->getConnectionPool()->getConnectionForTable(Tables::AI_USAGE)
            ->select(['estimated_cost_micro_usd', 'cost_known', 'cost_basis', 'cache_read_tokens', 'price_origin', 'price_verified_at'], Tables::AI_USAGE, ['site_identifier' => 'main'])->fetchAssociative();
        // 1,000 × $2 + 400 cache hits × $0.10 + 100 × $10.
        self::assertSame(2_000 + 40 + 1_000, (int)$row['estimated_cost_micro_usd']);
        self::assertSame('reported', $row['cost_basis']);
        self::assertSame(400, (int)$row['cache_read_tokens']);
        self::assertSame('bundled', $row['price_origin']);
        self::assertSame(AiBundledPriceCatalogue::VERIFIED_AT, (int)$row['price_verified_at']);
        self::assertSame(0, $this->countRows(AiBudgetRepository::RESERVATION_TABLE), 'The reservation is released after settlement.');

        $sent = false;
        try {
            $recorder->track('main', 'explain', $configuration, static function () use (&$sent): string {
                $sent = true;

                return 'ok';
            }, new AiCostBound(200_000, 4_096));
            self::fail('The worst case exceeds the budget.');
        } catch (AiConfigurationException $exception) {
            self::assertSame(AiBudgetService::CODE_BUDGET_REACHED, $exception->getCode());
        }
        self::assertFalse($sent);
        self::assertSame(1, $this->countRows(Tables::AI_USAGE));
    }

    #[Test]
    public function switchingTheProviderClearsTheStoredAcknowledgement(): void
    {
        $settings = $this->get(AiSiteSettingRepository::class);
        $settings->saveAcknowledgement('main', 'openai', 1);
        $row = $settings->findBySiteIdentifier('main');
        self::assertSame('openai', $row['processing_ack_provider']);
        self::assertSame(1, (int)$row['processing_ack_by']);
        self::assertGreaterThan(0, (int)$row['processing_ack_at']);

        $settings->saveProvider('main', 'anthropic', 1);
        $row = $settings->findBySiteIdentifier('main');
        self::assertSame('', $row['processing_ack_provider']);
        self::assertSame(0, (int)$row['processing_ack_at']);
    }

    #[Test]
    public function onlyAdministratorsChangeBudgetsOrTheAcknowledgement(): void
    {
        $controller = $this->get(AiSettingsAjaxController::class);

        $this->setUpBackendUser(3);
        self::assertSame(403, $controller->saveBudgetAction($this->post(['siteIdentifier' => 'main', 'budget' => '50', 'hardStop' => '1']))->getStatusCode());
        self::assertSame(403, $controller->acknowledgeProcessingAction($this->post(['siteIdentifier' => 'main', 'provider' => 'openai', 'confirmed' => '1']))->getStatusCode());
        self::assertSame(0, $this->countRows(Tables::AI_SITE_SETTING));

        $this->setUpBackendUser(1);
        // A budget no longer needs any price entry first.
        self::assertSame(200, $controller->saveBudgetAction($this->post(['siteIdentifier' => 'main', 'budget' => '50', 'warningPercent' => '80', 'hardStop' => '1']))->getStatusCode());
        $acknowledged = $controller->acknowledgeProcessingAction($this->post(['siteIdentifier' => 'main', 'provider' => 'openai', 'confirmed' => '1']));
        self::assertSame(200, $acknowledged->getStatusCode());
        $state = json_decode((string)$acknowledged->getBody(), true, 64, JSON_THROW_ON_ERROR)['uiState'];
        self::assertTrue($state['acknowledgement']['confirmed']);
        self::assertSame('Ada Admin', $state['acknowledgement']['byName']);
        self::assertSame('ok', $state['readiness']['acknowledgement']);
        self::assertSame(['openai', 'anthropic'], array_column($state['pricing']['diagnostics']['providers'], 'id'));
        self::assertSame('bundled', $state['pricing']['diagnostics']['catalogue']['source'], 'No copy of the central catalogue yet: the bundled prices apply.');
        self::assertArrayNotHasKey('due', $state['pricing']['diagnostics'], 'The settings screen never asks for a price check.');
    }

    #[Test]
    public function theSettingsRoutesArePostOnlyInheritTheModuleAccessAndOfferNoPriceEntry(): void
    {
        $router = $this->get(Router::class);
        foreach (['ajax_a11y_ai_settings_budget', 'ajax_a11y_ai_settings_acknowledge'] as $name) {
            $route = $router->getRoute($name);
            self::assertIsObject($route, $name);
            self::assertSame(['POST'], $route->getMethods(), $name);
            self::assertNotSame('public', $route->getOption('access'), $name);
            self::assertSame('web_a11y', $route->getOption('inheritAccessFromModule'), $name);
        }
        self::assertFalse($router->hasRoute('ajax_a11y_ai_settings_price_save'));
        self::assertFalse($router->hasRoute('ajax_a11y_ai_settings_price_remove'));
        self::assertFalse($router->hasRoute('ajax_a11y_ai_settings_pricing_refresh'), 'Prices come from the AQG service; there is no price check to start.');
    }

    /** @param array<string,string> $body */
    private function post(array $body): ServerRequest
    {
        return (new ServerRequest('https://example.test/typo3/ajax/a11y/settings/ai', 'POST'))->withParsedBody($body);
    }
}
