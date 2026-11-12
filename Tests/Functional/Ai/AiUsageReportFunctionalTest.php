<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Ai;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Service\AiSettingsUiStateBuilder;
use Priebera\A11yQualityGate\Ai\Settings\AiBackendUserNames;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageSummaryService;
use Priebera\A11yQualityGate\Controller\AiSettingsAjaxController;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\AiUsageRepository;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Priebera\A11yQualityGate\Utility\BackendLocaleFormatter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use Priebera\A11yQualityGate\Controller\SettingsController;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * The administrator's AI usage report against the real tx_a11y_ai_usage table: only the selected site and month are
 * read, deleted and disabled accounts keep their historical usage with a name, the per-person rows add up to the site
 * total, and an editor without administrator rights receives no usage from the AI settings endpoints.
 */
final class AiUsageReportFunctionalTest extends AbstractFunctionalTestCase
{
    private const ADMIN = 1;
    private const EDITOR = 3;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 7, 'title' => 'Accessibility editors', 'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '1',
            'TSconfig' => 'options.a11y_quality_gate.showSettings = 1',
        ]);
        $users = $pool->getConnectionForTable('be_users');
        $users->insert('be_users', ['uid' => self::ADMIN, 'username' => 'ada', 'realName' => 'Ada Admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $users->insert('be_users', ['uid' => self::EDITOR, 'username' => 'eva', 'realName' => 'Eva Editor', 'admin' => 0, 'usergroup' => '7', 'disable' => 0, 'deleted' => 0]);
        $users->insert('be_users', ['uid' => 4, 'username' => 'gone', 'realName' => 'Greta Gone', 'admin' => 0, 'disable' => 0, 'deleted' => 1]);
        $users->insert('be_users', ['uid' => 5, 'username' => 'paused', 'realName' => '', 'admin' => 0, 'disable' => 1, 'deleted' => 0]);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://example.test/');

        $usage = $pool->getConnectionForTable(Tables::AI_USAGE);
        $november = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0)->modify('+2 days')->getTimestamp();
        $earlier = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0)->modify('-10 days')->getTimestamp();
        foreach ([
            ['main', self::EDITOR, 'success', 120_000, 'reported', 0, $november],
            ['main', self::EDITOR, 'failed_1771002330', 900_000, 'bound', 0, $november],
            ['main', self::ADMIN, 'pending', 0, 'pending', 2_000_000, $november],
            ['main', 4, 'success', 100_000, 'reported', 0, $november],
            ['main', 5, 'success', 50_000, 'reported', 0, $november],
            ['main', 0, 'failed_1771002310', 0, 'none', 0, $november],
            ['other-site', self::EDITOR, 'success', 7_000_000, 'reported', 0, $november],
            ['main', self::EDITOR, 'success', 300_000, 'reported', 0, $earlier],
        ] as [$site, $user, $outcome, $cost, $basis, $reserved, $crdate]) {
            $usage->insert(Tables::AI_USAGE, [
                'pid' => 0, 'crdate' => $crdate, 'site_identifier' => $site, 'be_user' => $user, 'feature' => 'alt_text',
                'provider' => 'openai', 'model' => 'gpt-5.4-mini', 'outcome' => $outcome,
                'input_tokens' => $basis === 'reported' ? 1000 : 0, 'output_tokens' => $basis === 'reported' ? 100 : 0,
                'usage_reported' => $basis === 'reported' ? 1 : 0, 'estimated_cost_micro_usd' => $cost, 'cost_known' => 1,
                'cost_basis' => $basis, 'reserved_cost_micro_usd' => $reserved,
            ]);
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function theReportReadsOnlyTheSelectedSiteAndMonthAndReconciles(): void
    {
        $report = $this->get(AiUsageSummaryService::class)->month('main');

        self::assertSame(6, $report['totals']['requests']);
        self::assertSame(2, $report['totals']['failed']);
        self::assertSame(1, $report['totals']['pending']);
        self::assertSame(1_170_000, $report['totals']['estimatedMicroUsd'], 'No other-site row, no earlier-month row, no reservation.');
        self::assertSame(2_000_000, $report['totals']['reservedMicroUsd']);
        self::assertSame($report['totals']['estimatedMicroUsd'], array_sum(array_column($report['people'], 'estimatedMicroUsd')));
        self::assertSame($report['totals']['requests'], array_sum(array_column($report['people'], 'requests')));
        self::assertSame($report['totals']['reservedMicroUsd'], array_sum(array_column($report['people'], 'reservedMicroUsd')));

        $earlier = $this->get(AiUsageSummaryService::class)->month('main', (new \DateTimeImmutable('first day of last month'))->format('Y-m'));
        self::assertSame(1, $earlier['totals']['requests']);
        self::assertSame(300_000, $earlier['totals']['estimatedMicroUsd']);

        self::assertSame([], $this->get(AiUsageRepository::class)->breakdown('', 0, PHP_INT_MAX));
        self::assertSame([], $this->get(AiUsageRepository::class)->breakdown('main', 10, 10));
    }

    #[Test]
    public function deletedDisabledAndRemovedAccountsKeepANameAndAState(): void
    {
        $names = $this->get(AiBackendUserNames::class);

        self::assertSame(['name' => 'Eva Editor', 'username' => 'eva', 'state' => 'active'], $names->describe(self::EDITOR));
        self::assertSame(['name' => 'Greta Gone', 'username' => 'gone', 'state' => 'deleted'], $names->describe(4));
        self::assertSame(['name' => 'paused', 'username' => 'paused', 'state' => 'disabled'], $names->describe(5));
        self::assertSame(['name' => '#99', 'username' => '', 'state' => 'missing'], $names->describe(99));

        $this->setUpBackendUser(self::ADMIN);
        $people = $this->get(AiSettingsUiStateBuilder::class)->build('main', new BackendLocaleFormatter('en'))['usage']['people'];
        self::assertSame(['Ada Admin', 'Eva Editor', 'Greta Gone', 'paused', ''], array_column($people, 'name'));
        self::assertSame(['active', 'active', 'deleted', 'disabled', 'none'], array_column($people, 'accountState'));
        foreach ($people as $person) {
            self::assertArrayNotHasKey('apiKey', $person);
            self::assertArrayNotHasKey('conversation_ref', $person);
        }
    }

    #[Test]
    public function anEditorReceivesNoUsageFromTheAiSettingsEndpoints(): void
    {
        $this->setUpBackendUser(self::EDITOR);
        $request = (new ServerRequest('https://example.test/typo3/ajax/a11y/ai-settings/privacy', 'POST'))
            ->withParsedBody(['siteIdentifier' => 'main', 'allowImageInput' => '1', 'allowPageTitle' => '1'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $controller = $this->get(AiSettingsAjaxController::class);

        foreach (['savePrivacyAction', 'saveBudgetAction', 'testAction', 'refreshModelsAction'] as $action) {
            $response = $controller->{$action}($request);
            $body = json_decode((string)$response->getBody(), true);
            self::assertSame(403, $response->getStatusCode(), $action);
            self::assertSame(['responseStatus' => 403], $body['uiState'], $action . ': no usage, budget or names for an editor.');
            self::assertStringNotContainsString('Greta', (string)$response->getBody());
        }
    }

    #[Test]
    public function theCsvExportIsForAdministratorsOnlyAndReconcilesWithTheReport(): void
    {
        $this->setUpBackendUser(self::EDITOR);
        $denied = $this->get(SettingsController::class)->aiUsageExportAction($this->exportRequest(['site' => 'main']));
        self::assertSame(403, $denied->getStatusCode());
        self::assertStringNotContainsString('Greta', (string)$denied->getBody());
        self::assertStringStartsWith('text/plain', $denied->getHeaderLine('Content-Type'));

        $this->setUpBackendUser(self::ADMIN);
        $controller = $this->get(SettingsController::class);
        self::assertSame(404, $controller->aiUsageExportAction($this->exportRequest(['site' => 'other-site']))->getStatusCode(), 'Usage rows exist, but no such TYPO3 site.');
        self::assertSame(404, $controller->aiUsageExportAction($this->exportRequest([]))->getStatusCode());
        foreach (['2020-01', '2099-01', '11-2026', 'x'] as $month) {
            self::assertSame(400, $controller->aiUsageExportAction($this->exportRequest(['site' => 'main', 'month' => $month]))->getStatusCode(), $month);
        }

        $response = $controller->aiUsageExportAction($this->exportRequest(['site' => 'main']));
        $body = (string)$response->getBody();
        $month = (new \DateTimeImmutable('first day of this month'))->format('Y-m');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/csv; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="aqg-ai-usage-main-' . $month . '.csv"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertStringStartsWith("\xEF\xBB\xBF", $body);
        $lines = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), array_values(array_filter(explode("\n", substr($body, 3)))));
        self::assertSame('Site', $lines[0][0]);
        self::assertSame('Estimated cost USD (settled, empty = unknown)', $lines[0][13]);
        $total = end($lines);
        self::assertSame(['Site total', '6', '1.170000', '2.000000'], [$total[3], $total[6], $total[13], $total[18]]);
        self::assertCount(7, $lines, 'Header, five people, total — no other-site or earlier-month row.');
        self::assertStringContainsString('Greta Gone', $body);
        self::assertStringContainsString(',deleted,', $body);
        self::assertStringContainsString(',disabled,', $body);
        self::assertStringContainsString(',,"No backend user",,none,', $body);
        self::assertStringNotContainsString('7.000000', $body);

        $earlier = (new \DateTimeImmutable('first day of last month'))->format('Y-m');
        $previous = (string)$controller->aiUsageExportAction($this->exportRequest(['site' => 'main', 'month' => $earlier]))->getBody();
        self::assertStringContainsString(',' . $earlier . ',', $previous);
        self::assertStringContainsString('0.300000', $previous);
        self::assertStringNotContainsString('1.170000', $previous);
    }

    /** @param array<string,string> $query */
    private function exportRequest(array $query): ServerRequest
    {
        $request = (new ServerRequest('https://example.test/typo3/module/web/a11y/settings/ai-usage-export', 'GET'))
            ->withQueryParams($query)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }
}
