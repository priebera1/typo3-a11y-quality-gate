<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Scheduler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Monitoring\InvalidMonitoringTargetException;
use Priebera\A11yQualityGate\Monitoring\MonitoringTargetResolver;
use Priebera\A11yQualityGate\Monitoring\MonitoringTaskValidator;
use Priebera\A11yQualityGate\Scheduler\MonitoringTask;
use Priebera\A11yQualityGate\Service\RemoteMonitoringService;
use Priebera\A11yQualityGate\Tests\Unit\Monitoring\MonitoringFixtures;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The monitoring Scheduler task stores its site and language, restores them, and on every run resolves them
 * against the site configuration before it calls the same monitoring service as `a11y:monitor`.
 */
final class MonitoringTaskTest extends TestCase
{
    use MonitoringFixtures;

    /** @var list<array{site:string,language:int,maxPages:int,maxWait:int,recipients:list<string>,backendUrl:string}> */
    private array $runs = [];
    private string $outcome = RemoteMonitoringService::OUTCOME_CLEAR;

    protected function setUp(): void
    {
        parent::setUp();
        $resolver = $this->resolverFor();
        $monitoring = $this->createMock(RemoteMonitoringService::class);
        $monitoring->method('run')->willReturnCallback(function (Site $site, int $language, int $maxPages, int $maxWait, array $recipients, string $backendUrl): array {
            $this->runs[] = ['site' => $site->getIdentifier(), 'language' => $language, 'maxPages' => $maxPages, 'maxWait' => $maxWait, 'recipients' => $recipients, 'backendUrl' => $backendUrl];
            return ['outcome' => $this->outcome, 'notified' => false, 'summary' => [], 'runUid' => 1, 'jobId' => ''];
        });
        $services = [
            MonitoringTargetResolver::class => $resolver,
            MonitoringTaskValidator::class => new MonitoringTaskValidator($resolver),
            RemoteMonitoringService::class => $monitoring,
        ];
        GeneralUtility::setContainer(new class ($services) implements ContainerInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services) {}

            public function get(string $id): object
            {
                return $this->services[$id] ?? throw new \RuntimeException('No service ' . $id);
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        });
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(GeneralUtility::class, 'container'))->setValue(null, null);
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function theSavedConfigurationIsRestored(): void
    {
        $saved = self::task();
        $saved->setTaskParameters([
            MonitoringTask::PARAM_SITE => 'main',
            MonitoringTask::PARAM_LANGUAGE => '2',
            MonitoringTask::PARAM_RECIPIENTS => 'qa@example.org, web@example.org',
            MonitoringTask::PARAM_MAX_PAGES => '50',
            MonitoringTask::PARAM_MAX_WAIT => '0',
            MonitoringTask::PARAM_BACKEND_URL => 'https://cms.example.org',
        ]);

        // TYPO3 14 stores the parameters as columns and restores them with setTaskParameters().
        $restored = self::task();
        $restored->setTaskParameters($saved->getTaskParameters());

        self::assertSame('main', $restored->siteIdentifier);
        self::assertSame(2, $restored->languageUid);
        self::assertSame('qa@example.org, web@example.org', $restored->recipients);
        self::assertSame(50, $restored->maxPages);
        self::assertSame(0, $restored->maxWait);
        self::assertSame('https://cms.example.org', $restored->backendUrl);
        self::assertSame($saved->getTaskParameters(), $restored->getTaskParameters());
    }

    #[Test]
    public function aRunMonitorsTheConfiguredSiteLanguageWithTheCommandsService(): void
    {
        $task = self::configured('main', 2);
        $task->maxPages = 5000;
        $task->maxWait = -5;

        self::assertTrue($task->execute());
        self::assertSame([[
            'site' => 'main',
            'language' => 2,
            'maxPages' => 1000,
            'maxWait' => 0,
            'recipients' => ['qa@example.org', 'web@example.org'],
            'backendUrl' => 'https://cms.example.org',
        ]], $this->runs);
    }

    #[Test]
    public function aRemovedSiteOrLanguageFailsTheRunClearlyAndScansNothing(): void
    {
        foreach ([['retired', 0, 'The site "retired" is not configured'], ['main', 1, 'Language 1 is not an enabled language of the site "main"'], ['', 0, 'Choose the site to monitor.']] as [$site, $language, $message]) {
            try {
                self::configured($site, $language)->execute();
                self::fail('A run of an unknown target must fail.');
            } catch (InvalidMonitoringTargetException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }

        self::assertSame([], $this->runs);
    }

    #[Test]
    public function aSiteWithoutAValidLicenceFailsTheRun(): void
    {
        $this->outcome = RemoteMonitoringService::OUTCOME_NOT_ENTITLED;

        $this->expectExceptionMessage('Monitoring needs a valid AQG PRO or Agency licence for the site "main".');
        self::configured('main', 0)->execute();
    }

    #[Test]
    public function otherOutcomesAreSuccessfulRuns(): void
    {
        foreach ([RemoteMonitoringService::OUTCOME_REGRESSION, RemoteMonitoringService::OUTCOME_INCOMPLETE, RemoteMonitoringService::OUTCOME_WAITING, RemoteMonitoringService::OUTCOME_BUSY] as $outcome) {
            $this->outcome = $outcome;
            self::assertTrue(self::configured('main', 0)->execute(), $outcome);
        }
    }

    #[Test]
    public function theTaskListNamesSiteLanguageAndRecipients(): void
    {
        self::assertStringContainsString('main · Deutsch (de', self::configured('main', 2)->getAdditionalInformation());
        self::assertStringEndsWith('· 2 recipient(s)', self::configured('main', 2)->getAdditionalInformation());
        self::assertSame('Site "retired" is no longer configured', self::configured('retired', 0)->getAdditionalInformation());
        self::assertStringContainsString('language 1 is not enabled', self::configured('main', 1)->getAdditionalInformation());
    }

    #[Test]
    public function choosingTheTaskTypeIsNotBlockedBeforeTheFieldsExist(): void
    {
        self::assertTrue(self::task()->validateTaskParameters(['tasktype' => MonitoringTask::class, 'description' => '']));
        self::assertSame([], $this->flashMessages());
    }

    #[Test]
    public function aSaveWithAnInvalidRecipientOrUnknownSiteIsRefusedWithAMessage(): void
    {
        $stored = self::configured('main', 0);

        self::assertFalse($stored->validateTaskParameters([MonitoringTask::PARAM_RECIPIENTS => 'qa@example.org, nobody']));
        self::assertSame(['"nobody" is not a valid e-mail address.'], $this->flashMessages());

        self::assertFalse(self::task()->validateTaskParameters([MonitoringTask::PARAM_SITE => 'retired']));
        self::assertSame(['The site "retired" is not configured in this TYPO3 installation. Choose a configured site.'], $this->flashMessages());
    }

    #[Test]
    public function switchingTheSiteIsNotBlockedByTheLanguageOfThePreviousSite(): void
    {
        // The form saves on the site change while language 2 of "main" is still selected; the hook then resets it.
        self::assertTrue(self::configured('main', 2)->validateTaskParameters([MonitoringTask::PARAM_SITE => 'shop', MonitoringTask::PARAM_LANGUAGE => '2']));
        self::assertSame([], $this->flashMessages());
    }

    private static function configured(string $site, int $language): MonitoringTask
    {
        $task = self::task();
        $task->siteIdentifier = $site;
        $task->languageUid = $language;
        $task->recipients = 'qa@example.org, web@example.org';
        $task->backendUrl = 'https://cms.example.org';

        return $task;
    }

    private static function task(): MonitoringTask
    {
        /** @var MonitoringTask $task */
        $task = (new \ReflectionClass(MonitoringTask::class))->newInstanceWithoutConstructor();

        return $task;
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
