<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Scheduler;

use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Command\MonitorCommand;
use Priebera\A11yQualityGate\Scheduler\A11yScanTask;
use Priebera\A11yQualityGate\Scheduler\MonitoringTask;
use Priebera\A11yQualityGate\Scheduler\MonitoringTaskAdditionalFieldProvider;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Scheduler\Domain\Repository\SchedulerTaskRepository;
use TYPO3\CMS\Scheduler\Service\TaskService;
use TYPO3\CMS\Scheduler\Task\ExecuteSchedulableCommandTask;

/**
 * `a11y:monitor` in the real container, and the monitoring task in the Scheduler of the running TYPO3 version.
 */
final class MonitoringSchedulerFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pool = $this->get(ConnectionPool::class);
        foreach ([[1, 'Example'], [20, 'Shop']] as [$uid, $title]) {
            $pool->getConnectionForTable('pages')->insert('pages', ['uid' => $uid, 'pid' => 0, 'title' => $title, 'doktype' => 1, 'is_siteroot' => 1]);
        }
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1]);

        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write('main', [
            'rootPageId' => 1,
            'base' => 'https://example.org/',
            'websiteTitle' => 'Example',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'enabled' => true],
                ['languageId' => 2, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'enabled' => true],
            ],
        ]);
        $siteWriter->write('shop', [
            'rootPageId' => 20,
            'base' => 'https://shop.example.org/',
            'websiteTitle' => 'Shop',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_GB.UTF-8', 'base' => '/', 'enabled' => true],
            ],
        ]);
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    #[Test]
    public function theMonitoringCommandIsListedAndResolvesWithItsDependencies(): void
    {
        $registry = $this->get(CommandRegistry::class);

        $listed = $registry->filter('a11y');
        self::assertArrayHasKey('a11y:monitor', $listed, '`typo3 list` shows a11y:monitor.');
        self::assertArrayHasKey('a11y:scan', $listed, '`typo3 list` shows a11y:scan.');
        self::assertNull($listed['a11y:monitor']['aliasFor']);
        self::assertContains('a11y:monitor', $registry->getNamespaces()['a11y']['commands'] ?? []);
        self::assertInstanceOf(MonitorCommand::class, $registry->get('a11y:monitor'), '`typo3 help a11y:monitor` builds the command without DI errors.');
        self::assertFalse($listed['a11y:monitor']['schedulable'], 'Scheduled through the monitoring task, not as a raw command.');
    }

    #[Test]
    public function theMonitoringCommandRefusesABackendUrlTheSchedulerWouldRefuse(): void
    {
        $command = $this->get(CommandRegistry::class)->get('a11y:monitor');
        $siteIdentifier = array_key_first($this->get(SiteFinder::class)->getAllSites(false));
        self::assertIsString($siteIdentifier);

        foreach (['javascript:alert(1)', 'https://cms.example.org/typo3?x=1', 'https://user:pass@cms.example.org', 'ftp://cms.example.org'] as $backendUrl) {
            $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
            $exitCode = $tester->execute(['--site' => [$siteIdentifier], '--backend-url' => $backendUrl]);

            self::assertSame(\Symfony\Component\Console\Command\Command::INVALID, $exitCode, $backendUrl);
            self::assertStringContainsString('--backend-url', $tester->getDisplay(), $backendUrl);
        }
    }

    #[Test]
    public function theSchedulerOffersTheMonitoringTaskWithSiteAndLanguageFields(): void
    {
        if ((new Typo3Version())->getMajorVersion() < 14) {
            self::assertSame(
                MonitoringTaskAdditionalFieldProvider::class,
                $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][MonitoringTask::class]['additionalFields'] ?? null
            );
            return;
        }

        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        $taskTypes = $this->get(TaskService::class)->getAllTaskTypes();

        self::assertTrue($taskTypes[MonitoringTask::class]['isNativeTask'] ?? false);
        self::assertSame(
            [MonitoringTask::PARAM_SITE, MonitoringTask::PARAM_LANGUAGE, MonitoringTask::PARAM_RECIPIENTS, MonitoringTask::PARAM_MAX_PAGES, MonitoringTask::PARAM_MAX_WAIT, MonitoringTask::PARAM_BACKEND_URL],
            $taskTypes[MonitoringTask::class]['additionalFields']
        );
        self::assertSame('selectSingle', $GLOBALS['TCA']['tx_scheduler_task']['columns'][MonitoringTask::PARAM_SITE]['config']['renderType']);
        self::assertSame('reload', $GLOBALS['TCA']['tx_scheduler_task']['columns'][MonitoringTask::PARAM_SITE]['onChange']);
        self::assertSame('selectSingle', $GLOBALS['TCA']['tx_scheduler_task']['columns'][MonitoringTask::PARAM_LANGUAGE]['config']['renderType']);
        self::assertArrayNotHasKey('a11y:monitor', $taskTypes, 'No raw command task with a typed language number.');
    }

    #[Test]
    public function aTaskSavedInTheTypo3FourteenFormIsRestoredAndKeepsItsLanguageWithinItsSite(): void
    {
        if ((new Typo3Version())->getMajorVersion() < 14) {
            self::markTestSkipped('TYPO3 13 stores the task through MonitoringTaskAdditionalFieldProvider (unit tested).');
        }

        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        $execution = ['start' => time(), 'frequency' => '0 3 * * *', 'runningType' => 2, 'multiple' => 0];

        [$uid] = $this->save('NEW1', [
            'tasktype' => MonitoringTask::class, 'description' => 'Nightly monitoring', 'execution_details' => $execution, 'disable' => 1,
            MonitoringTask::PARAM_SITE => 'main', MonitoringTask::PARAM_LANGUAGE => 2, MonitoringTask::PARAM_RECIPIENTS => 'qa@example.org, web@example.org',
            MonitoringTask::PARAM_MAX_PAGES => 50, MonitoringTask::PARAM_MAX_WAIT => 0, MonitoringTask::PARAM_BACKEND_URL => 'https://cms.example.org',
        ]);
        $task = $this->get(SchedulerTaskRepository::class)->findByUid($uid);
        self::assertInstanceOf(MonitoringTask::class, $task);
        self::assertSame(['main', 2, 'qa@example.org, web@example.org', 50, 0, 'https://cms.example.org'], [$task->siteIdentifier, $task->languageUid, $task->recipients, $task->maxPages, $task->maxWait, $task->backendUrl]);

        // Switching to a site without language 2 saves (reloads) with the previous language still selected.
        [, $errors] = $this->save($uid, ['tasktype' => MonitoringTask::class, 'execution_details' => $execution, MonitoringTask::PARAM_SITE => 'shop', MonitoringTask::PARAM_LANGUAGE => 2]);
        self::assertSame([], $errors);
        $task = $this->get(SchedulerTaskRepository::class)->findByUid($uid);
        self::assertSame(['shop', 0], [$task->siteIdentifier, $task->languageUid]);

        [, $errors] = $this->save($uid, ['tasktype' => MonitoringTask::class, 'execution_details' => $execution, MonitoringTask::PARAM_RECIPIENTS => 'nobody']);
        self::assertStringContainsString('"nobody" is not a valid e-mail address.', implode(' ', $errors));
        self::assertSame('qa@example.org, web@example.org', $this->get(SchedulerTaskRepository::class)->findByUid($uid)->recipients, 'A refused save changes nothing.');
    }

    /**
     * Regression (2.0.2, AQG-202-11): TYPO3 14 listed `a11y:scan` in a separate "A11y" category of the New task wizard.
     * It is listed with the AQG tasks now, and stays a registered task type, so an existing `a11y:scan` task (created in
     * the TYPO3 14 form or migrated from TYPO3 13) is still restored as the console command task.
     */
    #[Test]
    public function theScanCommandIsListedWithTheAqgTasksAndExistingCommandTasksStayRegistered(): void
    {
        if ((new Typo3Version())->getMajorVersion() < 14) {
            self::assertTrue(
                $this->get(CommandRegistry::class)->filter('a11y')['a11y:scan']['schedulable'] ?? false,
                'TYPO3 13 "Execute console commands" keeps offering a11y:scan.'
            );
            return;
        }

        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        $taskService = $this->get(TaskService::class);
        $categories = $taskService->getCategorizedTaskTypes();

        self::assertArrayNotHasKey('a11y', $categories);
        self::assertEqualsCanonicalizing(
            [A11yScanTask::class, MonitoringTask::class, 'a11y:scan'],
            array_keys($categories['Accessibility Quality Gate'] ?? [])
        );
        self::assertSame('Accessibility Quality Gate: Content scan command (a11y:scan)', $categories['Accessibility Quality Gate']['a11y:scan']['title']);
        self::assertTrue($taskService->isTaskTypeRegistered('a11y:scan'));

        // As stored by the TYPO3 14 form for "a11y:scan --page-uid=1".
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_scheduler_task')->insert('tx_scheduler_task', [
            'uid' => 77,
            'tasktype' => 'a11y:scan',
            'description' => 'Existing command task',
            'parameters' => [
                'optionValues' => ['root-pid' => '', 'depth' => '99', 'page-uid' => '1', 'language' => 'all', 'changed-only' => false],
                'options' => ['page-uid' => true, 'root-pid' => false, 'depth' => false, 'language' => false, 'changed-only' => false],
                'commandIdentifier' => 'a11y:scan',
                'arguments' => [],
            ],
            'execution_details' => ['runningType' => '1', 'multiple' => false, 'start' => time(), 'end' => 0, 'frequency' => '0', 'interval' => 0, 'cronCmd' => '', 'isNewSingleExecution' => false],
            'nextexecution' => time(),
            'disable' => 1,
        ], ['parameters' => Types::JSON, 'execution_details' => Types::JSON]);
        $task = $this->get(SchedulerTaskRepository::class)->findByUid(77);
        self::assertInstanceOf(ExecuteSchedulableCommandTask::class, $task);
        self::assertSame('a11y:scan', $task->getTaskType());
        self::assertSame('a11y:scan --page-uid=1', $task->getAdditionalInformation());
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{0:int,1:list<string>}
     */
    private function save(int|string $id, array $fields): array
    {
        $dataHandler = $this->get(DataHandler::class);
        $dataHandler->start(['tx_scheduler_task' => [$id => $fields]], []);
        $dataHandler->process_datamap();

        return [
            (int)($dataHandler->substNEWwithIDs[$id] ?? $id),
            array_values(array_map('strval', $dataHandler->errorLog)),
        ];
    }
}
