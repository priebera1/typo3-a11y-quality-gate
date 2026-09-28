<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Scheduler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Monitoring\MonitoringTaskValidator;
use Priebera\A11yQualityGate\Scheduler\MonitoringTask;
use Priebera\A11yQualityGate\Scheduler\MonitoringTaskAdditionalFieldProvider as Provider;
use Priebera\A11yQualityGate\Tests\Unit\Monitoring\MonitoringFixtures;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Controller\SchedulerModuleController;

/**
 * TYPO3 13 Scheduler form of the monitoring task: sites and languages come from the site configuration,
 * never from a typed number, and a stored task opens with its values.
 */
final class MonitoringTaskAdditionalFieldProviderTest extends TestCase
{
    use MonitoringFixtures;

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function theSiteSelectOffersTheConfiguredSites(): void
    {
        $taskInfo = [];
        $site = $this->provider()->getAdditionalFields($taskInfo, null, $this->module())[Provider::FIELD_SITE];

        self::assertSame('select', $site['type']);
        self::assertSame('LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.monitoring.field.site', $site['label']);
        self::assertStringContainsString('id="aqg_monitor_site" name="tx_scheduler[aqg_monitor_site]"', $site['code']);
        self::assertStringContainsString('<option value="" selected="selected">Choose a site</option>', $site['code']);
        self::assertStringContainsString('<option value="main">Example (main), root page 1</option>', $site['code']);
        self::assertStringContainsString('<option value="shop">Shop (shop), root page 20</option>', $site['code']);
        self::assertStringNotContainsString('type="number"', $site['code']);
    }

    #[Test]
    public function theLanguageSelectHoldsEachSitesOwnLanguagesWithTheirRealIds(): void
    {
        $taskInfo = [];
        $code = $this->provider()->getAdditionalFields($taskInfo, null, $this->module())[Provider::FIELD_LANGUAGE]['code'];

        self::assertStringContainsString('<optgroup label="Example" data-site="main">', $code);
        self::assertMatchesRegularExpression('#<option value="0" data-site="main" data-default="1">English \(en[^)]*\) – default language</option>#', $code);
        self::assertMatchesRegularExpression('#<option value="2" data-site="main">Deutsch \(de[^)]*\)</option>#', $code);
        self::assertStringNotContainsString('value="3"', $code, 'A disabled language is not offered.');
        self::assertMatchesRegularExpression('#<optgroup label="Shop" data-site="shop"><option value="0" data-site="shop" data-default="1">[^<]+</option></optgroup>#', $code, 'A one-language site offers that language only.');
    }

    #[Test]
    public function aStoredTaskOpensWithItsSiteAndLanguage(): void
    {
        $task = (new \ReflectionClass(MonitoringTask::class))->newInstanceWithoutConstructor();
        $task->setTaskParameters([
            MonitoringTask::PARAM_SITE => 'main',
            MonitoringTask::PARAM_LANGUAGE => 2,
            MonitoringTask::PARAM_RECIPIENTS => 'qa@example.org',
            MonitoringTask::PARAM_MAX_PAGES => 40,
            MonitoringTask::PARAM_MAX_WAIT => 60,
            MonitoringTask::PARAM_BACKEND_URL => 'https://cms.example.org',
        ]);

        $taskInfo = [];
        $fields = $this->provider()->getAdditionalFields($taskInfo, $task, $this->module());

        self::assertStringContainsString('<option value="main" selected="selected">', $fields[Provider::FIELD_SITE]['code']);
        self::assertMatchesRegularExpression('#<option value="2" data-site="main" selected="selected">#', $fields[Provider::FIELD_LANGUAGE]['code']);
        self::assertDoesNotMatchRegularExpression('#data-site="shop"[^>]*selected#', $fields[Provider::FIELD_LANGUAGE]['code'], 'Language 0 of another site is not the stored choice.');
        self::assertStringContainsString('value="qa@example.org"', $fields[Provider::FIELD_RECIPIENTS]['code']);
        self::assertStringContainsString('value="40" min="1" max="1000"', $fields[Provider::FIELD_MAX_PAGES]['code']);
        self::assertStringContainsString('value="60" min="0" max="3600"', $fields[Provider::FIELD_MAX_WAIT]['code']);
        self::assertStringContainsString('value="https://cms.example.org"', $fields[Provider::FIELD_BACKEND_URL]['code']);
    }

    #[Test]
    public function valuesOfAFailedSaveAreShownAgainInsteadOfTheStoredOnes(): void
    {
        $task = (new \ReflectionClass(MonitoringTask::class))->newInstanceWithoutConstructor();
        $task->siteIdentifier = 'main';
        $taskInfo = [Provider::FIELD_SITE => 'shop', Provider::FIELD_RECIPIENTS => 'typo@'];

        $fields = $this->provider()->getAdditionalFields($taskInfo, $task, $this->module());

        self::assertStringContainsString('<option value="shop" selected="selected">', $fields[Provider::FIELD_SITE]['code']);
        self::assertStringContainsString('value="typo@"', $fields[Provider::FIELD_RECIPIENTS]['code']);
    }

    #[Test]
    public function aNewTaskStartsOnTheOnlySiteAndItsDefaultLanguage(): void
    {
        $taskInfo = [];
        $fields = $this->provider(['shop' => self::sites()['shop']])->getAdditionalFields($taskInfo, null, $this->module());

        self::assertStringContainsString('<option value="shop" selected="selected">', $fields[Provider::FIELD_SITE]['code']);
        self::assertSame(0, $taskInfo[Provider::FIELD_LANGUAGE]);
        self::assertSame(MonitoringTaskValidator::MAX_PAGES_DEFAULT, $taskInfo[Provider::FIELD_MAX_PAGES]);
        self::assertSame(MonitoringTaskValidator::MAX_WAIT_DEFAULT, $taskInfo[Provider::FIELD_MAX_WAIT]);
    }

    #[Test]
    public function theFormLoadsTheScriptThatShowsOnlyTheSelectedSitesLanguages(): void
    {
        $pageRenderer = $this->createMock(PageRenderer::class);
        $pageRenderer->expects(self::once())->method('loadJavaScriptModule')->with('@priebera/a11y-quality-gate/backend/scheduler-monitoring-task.js');
        $taskInfo = [];

        (new Provider($resolver = $this->resolverFor(), new MonitoringTaskValidator($resolver), $pageRenderer))
            ->getAdditionalFields($taskInfo, null, $this->module());
    }

    #[Test]
    public function aSubmitIsValidatedAgainstTheSiteConfiguration(): void
    {
        $submitted = [Provider::FIELD_SITE => 'shop', Provider::FIELD_LANGUAGE => '2', Provider::FIELD_RECIPIENTS => '', Provider::FIELD_MAX_PAGES => '500', Provider::FIELD_MAX_WAIT => '1200', Provider::FIELD_BACKEND_URL => ''];

        self::assertFalse($this->provider()->validateAdditionalFields($submitted, $this->module()));
        self::assertSame([
            'Language 2 is not an enabled language of the site "shop". Choose one of the site\'s languages.',
            'Enter at least one e-mail address that receives the notifications.',
        ], $this->flashMessages());

        $submitted[Provider::FIELD_SITE] = 'main';
        $submitted[Provider::FIELD_RECIPIENTS] = 'qa@example.org';
        self::assertTrue($this->provider()->validateAdditionalFields($submitted, $this->module()));
        self::assertSame([], $this->flashMessages());
    }

    #[Test]
    public function savingStoresTheChosenValuesOnTheTask(): void
    {
        $task = (new \ReflectionClass(MonitoringTask::class))->newInstanceWithoutConstructor();

        $this->provider()->saveAdditionalFields([
            Provider::FIELD_SITE => 'main',
            Provider::FIELD_LANGUAGE => '2',
            Provider::FIELD_RECIPIENTS => "qa@example.org;\nweb@example.org",
            Provider::FIELD_MAX_PAGES => '25',
            Provider::FIELD_MAX_WAIT => '600',
            Provider::FIELD_BACKEND_URL => ' https://cms.example.org ',
        ], $task);

        self::assertSame('main', $task->siteIdentifier);
        self::assertSame(2, $task->languageUid);
        self::assertSame('qa@example.org, web@example.org', $task->recipients);
        self::assertSame(25, $task->maxPages);
        self::assertSame(600, $task->maxWait);
        self::assertSame('https://cms.example.org', $task->backendUrl);
    }

    private function provider(?array $sites = null): Provider
    {
        $resolver = $this->resolverFor($sites);

        return new Provider($resolver, new MonitoringTaskValidator($resolver), $this->createMock(PageRenderer::class));
    }

    private function module(): SchedulerModuleController
    {
        return $this->createMock(SchedulerModuleController::class);
    }

    /**
     * @return list<string>
     */
    private function flashMessages(): array
    {
        return array_map(
            static fn($message): string => $message->getMessage(),
            GeneralUtility::makeInstance(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessagesAndFlush()
        );
    }
}
