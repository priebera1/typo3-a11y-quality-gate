<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Ai;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetAlertNotifier;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetAlertRecipient;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetAlertService;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetService;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder;
use Priebera\A11yQualityGate\Controller\AiSettingsAjaxController;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\AiBudgetAlertRepository;
use Priebera\A11yQualityGate\Domain\Repository\AiBudgetRepository;
use Priebera\A11yQualityGate\Domain\Repository\AiSiteSettingRepository;
use Priebera\A11yQualityGate\Domain\Repository\AiUsageRepository;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiBudgetAlertRepositoryInterface;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Mime\RawMessage;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * F-39 budget warning e-mail with the real database and DI container: the unique key decides concurrent claims, the
 * conditional reclaim lets one retry win, recorded usage rows drive the threshold, and the settings route validates
 * and shows the recipient. A mocked mailer: no real mail is sent.
 */
final class AiBudgetAlertFunctionalTest extends AbstractFunctionalTestCase
{
    /** @var list<MailMessage> */
    private array $messages = [];
    private mixed $warningEmailAddress = null;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->getConnectionPool()->getConnectionForTable('be_users');
        foreach ([[1, 'ada', 'Ada Admin', 1], [3, 'eva', 'Eva Editor', 0]] as [$uid, $username, $realName, $admin]) {
            $connection->insert('be_users', ['uid' => $uid, 'username' => $username, 'realName' => $realName, 'admin' => $admin, 'disable' => 0, 'deleted' => 0]);
        }
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://example.test/');
        $this->get(SiteWriter::class)->createNewBasicSite('other', 2, 'https://other.example.test/');
        $this->warningEmailAddress = $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] = '';
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] = $this->warningEmailAddress;
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function theUniqueKeyLetsOnlyOneConcurrentClaimWin(): void
    {
        $subject = $this->get(AiBudgetAlertRepository::class);
        $now = time();

        $first = $subject->claim('main', '2026-11', 80, 50_000_000, $now);
        self::assertIsInt($first);
        self::assertNull($subject->claim('main', '2026-11', 80, 50_000_000, $now), 'The second settlement loses on the unique key.');
        // Every other key is its own alert.
        self::assertIsInt($subject->claim('main', '2026-12', 80, 50_000_000, $now));
        self::assertIsInt($subject->claim('main', '2026-11', 90, 50_000_000, $now));
        self::assertIsInt($subject->claim('main', '2026-11', 80, 30_000_000, $now));
        self::assertIsInt($subject->claim('other', '2026-11', 80, 50_000_000, $now));
        self::assertSame(5, $this->countRows(AiBudgetAlertRepository::TABLE));

        $row = $subject->find('main', '2026-11', 80, 50_000_000);
        self::assertSame(['uid' => $first, 'status' => 'pending', 'attempts' => 1, 'last_attempt_at' => $now], $row);
        $subject->markFailed($first, $now);
        $failed = $subject->find('main', '2026-11', 80, 50_000_000);
        // Two retries that read the same failed row: the conditional update lets one win.
        self::assertTrue($subject->reclaim($first, $failed['status'], $failed['attempts'], $failed['last_attempt_at'], $now + 300));
        self::assertFalse($subject->reclaim($first, $failed['status'], $failed['attempts'], $failed['last_attempt_at'], $now + 300));
        $subject->markSent($first, $now + 301);
        $subject->markFailed($first, $now + 302);
        $sent = $subject->find('main', '2026-11', 80, 50_000_000);
        self::assertSame(AiBudgetAlertRepositoryInterface::STATUS_SENT, $sent['status'], 'A sent alert is never downgraded.');
        self::assertSame(2, $sent['attempts']);
    }

    #[Test]
    public function recordedUsageAboveTheThresholdSendsOneLocalisedMailPerSite(): void
    {
        $settings = $this->get(AiSiteSettingRepository::class);
        $settings->saveBudget('main', 10_000_000, 80, true, 1);
        $settings->saveBudgetAlertEmail('main', 'alerts@example.com');
        $settings->saveBudget('other', 10_000_000, 80, false, 1);
        $settings->saveBudgetAlertEmail('other', 'other@example.com');
        [, $from] = AiBudgetService::period();
        $usage = $this->getConnectionPool()->getConnectionForTable(Tables::AI_USAGE);
        $usage->insert(Tables::AI_USAGE, ['site_identifier' => 'main', 'crdate' => $from + 10, 'estimated_cost_micro_usd' => 7_000_000, 'cost_known' => 1]);
        $usage->insert(Tables::AI_USAGE, ['site_identifier' => 'main', 'crdate' => $from + 20, 'estimated_cost_micro_usd' => 1_000_000, 'cost_known' => 1]);
        $usage->insert(Tables::AI_USAGE, ['site_identifier' => 'main', 'crdate' => $from + 30, 'estimated_cost_micro_usd' => 0, 'cost_known' => 0]);
        // Another site and last month never count.
        $usage->insert(Tables::AI_USAGE, ['site_identifier' => 'other', 'crdate' => $from + 10, 'estimated_cost_micro_usd' => 1_000_000, 'cost_known' => 1]);
        $usage->insert(Tables::AI_USAGE, ['site_identifier' => 'other', 'crdate' => $from - 10, 'estimated_cost_micro_usd' => 9_000_000, 'cost_known' => 1]);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('de');
        $service = $this->service();

        self::assertSame(AiBudgetAlertService::OUTCOME_SENT, $service->afterUsageRecorded('main'));
        self::assertSame(AiBudgetAlertService::OUTCOME_ALREADY_SENT, $service->afterUsageRecorded('main'));
        self::assertSame(AiBudgetAlertService::OUTCOME_BELOW, $service->afterUsageRecorded('other'));

        self::assertCount(1, $this->messages);
        $message = $this->messages[0];
        self::assertSame(['alerts@example.com'], array_map(static fn ($address): string => $address->getAddress(), $message->getTo()));
        self::assertSame('[AQG] KI-Budgetwarnung: main liegt bei 80 % des Monatsbudgets', $message->getSubject());
        $text = (string)$message->getTextBody();
        self::assertStringContainsString('Warnschwelle: 80 %', $text);
        self::assertStringContainsString('1 Anfragen in diesem Monat haben unbekannte Kosten', $text);
        self::assertStringNotContainsString('sk-', $text);
        $row = $this->getConnectionPool()->getConnectionForTable(AiBudgetAlertRepository::TABLE)
            ->select(['site_identifier', 'threshold_percent', 'budget_micro_usd', 'status', 'attempts'], AiBudgetAlertRepository::TABLE)->fetchAllAssociative();
        self::assertSame([['site_identifier' => 'main', 'threshold_percent' => 80, 'budget_micro_usd' => 10_000_000, 'status' => 'sent', 'attempts' => 1]], array_map(
            static fn (array $r): array => ['site_identifier' => $r['site_identifier'], 'threshold_percent' => (int)$r['threshold_percent'], 'budget_micro_usd' => (int)$r['budget_micro_usd'], 'status' => $r['status'], 'attempts' => (int)$r['attempts']],
            $row,
        ));
    }

    #[Test]
    public function theSettlementPathIsWiredToTheAlertCheck(): void
    {
        $recorder = $this->get(AiUsageRecorder::class);
        $property = new \ReflectionProperty(AiUsageRecorder::class, 'budgetAlertService');

        self::assertInstanceOf(AiBudgetAlertService::class, $property->getValue($recorder));
    }

    #[Test]
    public function theSettingsRouteValidatesStoresAndShowsTheAlertRecipient(): void
    {
        $controller = $this->get(AiSettingsAjaxController::class);

        $this->setUpBackendUser(3);
        self::assertSame(403, $controller->saveBudgetAction($this->post(['siteIdentifier' => 'main', 'budget' => '50', 'warningPercent' => '80', 'hardStop' => '1', 'alertEmail' => 'eva@example.com']))->getStatusCode());
        self::assertSame(0, $this->countRows(Tables::AI_SITE_SETTING));

        $this->setUpBackendUser(1);
        $invalid = $controller->saveBudgetAction($this->post(['siteIdentifier' => 'main', 'budget' => '50', 'warningPercent' => '80', 'hardStop' => '1', 'alertEmail' => 'a@example.com, b@example.com']));
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame('invalid_alert_email', json_decode((string)$invalid->getBody(), true, 64, JSON_THROW_ON_ERROR)['code']);
        self::assertSame(0, $this->countRows(Tables::AI_SITE_SETTING), 'An invalid address saves nothing.');

        $saved = $controller->saveBudgetAction($this->post(['siteIdentifier' => 'main', 'budget' => '50', 'warningPercent' => '80', 'hardStop' => '1', 'alertEmail' => 'alerts@example.com']));
        self::assertSame(200, $saved->getStatusCode());
        $budget = json_decode((string)$saved->getBody(), true, 64, JSON_THROW_ON_ERROR)['uiState']['budget'];
        self::assertSame('alerts@example.com', $budget['alertEmailInput']);
        self::assertSame('alerts@example.com', $budget['alertRecipient']);
        self::assertSame(AiBudgetAlertRecipient::SOURCE_SITE, $budget['alertRecipientSource']);
        self::assertTrue($budget['alertActive']);

        $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] = 'ops@example.org';
        $cleared = $controller->saveBudgetAction($this->post(['siteIdentifier' => 'main', 'budget' => '50', 'warningPercent' => '', 'hardStop' => '1', 'alertEmail' => '']));
        $budget = json_decode((string)$cleared->getBody(), true, 64, JSON_THROW_ON_ERROR)['uiState']['budget'];
        self::assertSame('', $budget['alertEmailInput']);
        self::assertSame('ops@example.org', $budget['alertRecipient']);
        self::assertSame(AiBudgetAlertRecipient::SOURCE_TYPO3, $budget['alertRecipientSource']);
        self::assertFalse($budget['alertActive'], 'Without a warning threshold no alert is sent.');
    }

    private function service(): AiBudgetAlertService
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (RawMessage $message): void {
            self::assertInstanceOf(MailMessage::class, $message);
            $this->messages[] = $message;
        });

        return new AiBudgetAlertService(
            $this->get(AiBudgetAlertRepository::class),
            $this->get(AiBudgetRepository::class),
            $this->get(AiUsageRepository::class),
            $this->get(AiSiteSettingsService::class),
            new AiBudgetAlertNotifier($this->get(UriBuilder::class), $this->get(SiteFinder::class), static fn (): MailerInterface => $mailer),
        );
    }

    /** @param array<string,string> $body */
    private function post(array $body): ServerRequest
    {
        return (new ServerRequest('https://example.test/typo3/ajax/a11y/settings/ai', 'POST'))->withParsedBody($body);
    }
}
