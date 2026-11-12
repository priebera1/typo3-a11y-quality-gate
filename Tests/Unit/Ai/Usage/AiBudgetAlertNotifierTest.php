<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Usage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettings;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetAlertNotifier;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetAlertRecipient;
use Priebera\A11yQualityGate\Utility\BackendLocaleFormatter;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\RawMessage;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Site\SiteFinder;

/** F-39: content, link and delivery of the budget warning e-mail (a mocked mailer; never real mail). */
final class AiBudgetAlertNotifierTest extends TestCase
{
    private const ALERT = [
        'siteIdentifier' => 'main',
        'month' => '2026-11',
        'thresholdPercent' => 80,
        'budgetMicroUsd' => 50_000_000,
        'spentMicroUsd' => 41_234_567,
        'unknownCostRequests' => 0,
        'hardStop' => true,
    ];

    private mixed $mailConfiguration = null;
    private mixed $warningEmailAddress = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailConfiguration = $GLOBALS['TYPO3_CONF_VARS']['MAIL'] ?? null;
        $this->warningEmailAddress = $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] = 'typo3@example.net';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromName'] = 'TYPO3';
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = $this->mailConfiguration;
        if ($this->warningEmailAddress === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] = $this->warningEmailAddress;
        }
        parent::tearDown();
    }

    #[Test]
    public function theMailStatesSiteThresholdBudgetAndEstimatedUsageWithAnAbsoluteSettingsLink(): void
    {
        [$subject, $text] = $this->notifier('https://cms.example.com/typo3/module/web/a11y/settings?tab=ai&rulesetSite=main')
            ->compose(self::ALERT, AiBudgetAlertRecipient::SOURCE_SITE, new BackendLocaleFormatter('en'));

        self::assertSame('[AQG] AI budget warning: main is at 82% of its monthly budget', $subject);
        self::assertStringContainsString('Site: main', $text);
        self::assertStringContainsString('Month: 2026-11 (server time)', $text);
        // English writes the percent sign without a space (German keeps "80 %", see the functional test).
        self::assertStringContainsString('Warning threshold: 80%', $text);
        self::assertStringContainsString('Monthly budget: $50.00', $text);
        self::assertStringContainsString('Recorded usage this month (estimated): $41.23 (82% of the budget)', $text);
        self::assertStringContainsString('Amounts are estimates in USD', $text);
        self::assertStringContainsString('AI pauses for editors', $text);
        self::assertStringContainsString('AI settings of this site: https://cms.example.com/typo3/module/web/a11y/settings?tab=ai&rulesetSite=main', $text);
        self::assertStringContainsString('budget alert recipient of this site', $text);
        self::assertStringNotContainsString('unknown cost', $text);
    }

    /** U-05 (2026-11-05 company acceptance): the mail said "reached 80 %" while the site was at 112 %. */
    #[Test]
    public function anOverrunNamesTheActualShareNotOnlyTheThreshold(): void
    {
        [$subject, $text] = $this->notifier()->compose(['spentMicroUsd' => 56_000_000] + self::ALERT, AiBudgetAlertRecipient::SOURCE_SITE, new BackendLocaleFormatter('en'));

        self::assertSame('[AQG] AI budget warning: main is at 112% of its monthly budget', $subject);
        self::assertStringContainsString('has reached the warning threshold of 80% of its monthly budget: it is at 112%.', $text);
    }

    #[Test]
    public function unknownCostRequestsAreNamedAndTheRealSpendingMayBeHigher(): void
    {
        [, $text] = $this->notifier()->compose(['unknownCostRequests' => 4, 'hardStop' => false] + self::ALERT, AiBudgetAlertRecipient::SOURCE_TYPO3, new BackendLocaleFormatter('en'));

        self::assertStringContainsString('4 requests this month have an unknown cost', $text);
        self::assertStringContainsString('the real spending may be higher', $text);
        self::assertStringContainsString('This budget only warns', $text);
        self::assertStringContainsString('[BE][warning_email_addr]', $text);
    }

    #[Test]
    public function theBackendRouteTokenOfTheTriggeringSessionNeverLeavesByMail(): void
    {
        [, $text] = $this->notifier('https://cms.example.com/typo3/module/web/a11y/settings?token=0123456789abcdef&tab=ai&rulesetSite=main')
            ->compose(self::ALERT, AiBudgetAlertRecipient::SOURCE_SITE, new BackendLocaleFormatter('en'));

        self::assertStringNotContainsString('token', $text);
        self::assertStringNotContainsString('0123456789abcdef', $text);
        self::assertStringContainsString('AI settings of this site: https://cms.example.com/typo3/module/web/a11y/settings?tab=ai&rulesetSite=main', $text);
    }

    #[Test]
    public function withoutAnAbsoluteBackendUrlTheLinkIsLeftOut(): void
    {
        [, $relative] = $this->notifier('/typo3/module/web/a11y/settings?tab=ai')->compose(self::ALERT, AiBudgetAlertRecipient::SOURCE_SITE, new BackendLocaleFormatter('en'));
        [, $failing] = $this->notifier(null)->compose(self::ALERT, AiBudgetAlertRecipient::SOURCE_SITE, new BackendLocaleFormatter('en'));

        self::assertStringNotContainsString('AI settings of this site', $relative);
        self::assertStringNotContainsString('/typo3/', $relative);
        self::assertStringNotContainsString('AI settings of this site', $failing);
    }

    #[Test]
    public function theMessageGoesOnlyToTheResolvedRecipient(): void
    {
        $messages = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willReturnCallback(static function (RawMessage $message, ?Envelope $envelope = null) use (&$messages): void {
            $messages[] = $message;
        });
        $recipient = AiBudgetAlertRecipient::resolve(new AiSiteSettings('openai', true, true, true, budgetAlertEmail: 'alerts@example.com'));

        $this->notifier(mailer: $mailer)->send($recipient, self::ALERT);

        self::assertCount(1, $messages);
        self::assertInstanceOf(MailMessage::class, $messages[0]);
        self::assertSame(['alerts@example.com'], array_map(static fn ($address): string => $address->getAddress(), $messages[0]->getTo()));
        self::assertSame('typo3@example.net', $messages[0]->getFrom()[0]->getAddress());
        self::assertStringStartsWith('[AQG] AI budget warning', (string)$messages[0]->getSubject());
    }

    #[Test]
    public function aMailerFailureIsThrownToTheServiceWhichRecordsIt(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(new \RuntimeException('SMTP down'));
        $recipient = AiBudgetAlertRecipient::resolve(new AiSiteSettings('openai', true, true, true, budgetAlertEmail: 'alerts@example.com'));

        $this->expectException(\RuntimeException::class);
        $this->notifier(mailer: $mailer)->send($recipient, self::ALERT);
    }

    #[Test]
    public function aBrokenMailConfigurationFailsOnlyTheSendNeverTheConstruction(): void
    {
        $uris = $this->createMock(UriBuilder::class);
        $uris->method('buildUriFromRoute')->willThrowException(new \RuntimeException('no request'));
        $sites = $this->createMock(SiteFinder::class);
        $sites->method('getSiteByIdentifier')->willThrowException(new \RuntimeException('no site configuration'));
        // TYPO3's Mailer throws from its constructor on an invalid transport configuration (Exception 1291068569).
        $notifier = new AiBudgetAlertNotifier($uris, $sites, static function (): MailerInterface {
            throw new \RuntimeException('Invalid mail transport configuration', 1291068569);
        });

        $this->expectException(\RuntimeException::class);
        $notifier->send(AiBudgetAlertRecipient::resolve(new AiSiteSettings('openai', true, true, true, budgetAlertEmail: 'alerts@example.com')), self::ALERT);
    }

    #[Test]
    public function recipientInputIsOneValidAddressOrEmpty(): void
    {
        self::assertSame('', AiBudgetAlertRecipient::normalizeInput('  '));
        self::assertSame('alerts@example.com', AiBudgetAlertRecipient::normalizeInput(' alerts@example.com '));
        foreach (['not-an-address', 'a@example.com, b@example.com', 'a@example.com;b@example.com', 'Name <a@example.com>', str_repeat('a', 250) . '@example.com'] as $invalid) {
            try {
                AiBudgetAlertRecipient::normalizeInput($invalid);
                self::fail('Accepted: ' . $invalid);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('invalid_alert_email', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function theSiteAddressWinsOverTypo3sWarningAddress(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] = 'ops@example.org';
        $site = AiBudgetAlertRecipient::resolve(new AiSiteSettings('openai', true, true, true, budgetAlertEmail: 'alerts@example.com'));
        $fallback = AiBudgetAlertRecipient::resolve(new AiSiteSettings('openai', true, true, true));
        $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] = '';
        $none = AiBudgetAlertRecipient::resolve(new AiSiteSettings('openai', true, true, true));

        self::assertSame(['alerts@example.com', AiBudgetAlertRecipient::SOURCE_SITE], [$site->address, $site->source]);
        self::assertSame(['ops@example.org', AiBudgetAlertRecipient::SOURCE_TYPO3], [$fallback->address, $fallback->source]);
        self::assertSame(['', AiBudgetAlertRecipient::SOURCE_NONE], [$none->address, $none->source]);
        self::assertFalse($none->exists());
    }

    private function notifier(?string $link = '', ?MailerInterface $mailer = null): AiBudgetAlertNotifier
    {
        $uris = $this->createMock(UriBuilder::class);
        if ($link === null) {
            $uris->method('buildUriFromRoute')->willThrowException(new \RuntimeException('no request'));
        } else {
            $uris->method('buildUriFromRoute')->willReturn(new Uri($link));
        }
        $sites = $this->createMock(SiteFinder::class);
        $sites->method('getSiteByIdentifier')->willThrowException(new \RuntimeException('no site configuration'));

        $mailer ??= $this->createMock(MailerInterface::class);

        return new AiBudgetAlertNotifier($uris, $sites, static fn (): MailerInterface => $mailer);
    }
}
