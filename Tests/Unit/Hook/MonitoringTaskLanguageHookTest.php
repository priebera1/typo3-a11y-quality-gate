<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Hook;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Hook\MonitoringTaskLanguageHook;
use Priebera\A11yQualityGate\Scheduler\A11yScanTask;
use Priebera\A11yQualityGate\Scheduler\MonitoringTask;
use Priebera\A11yQualityGate\Tests\Unit\Monitoring\MonitoringFixtures;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * TYPO3 14: switching the site of a monitoring task reloads (saves) the form with the previous site's language
 * still selected. The saved language is then the new site's default, never a language that site lacks.
 */
final class MonitoringTaskLanguageHookTest extends TestCase
{
    use MonitoringFixtures;

    #[Test]
    public function aLanguageTheNewSiteLacksBecomesItsDefaultLanguage(): void
    {
        $fields = $this->process(['tasktype' => MonitoringTask::class, MonitoringTask::PARAM_SITE => 'shop', MonitoringTask::PARAM_LANGUAGE => '2']);

        self::assertSame(0, $fields[MonitoringTask::PARAM_LANGUAGE]);
    }

    #[Test]
    public function aLanguageOfTheSiteIsKept(): void
    {
        $fields = $this->process(['tasktype' => MonitoringTask::class, MonitoringTask::PARAM_SITE => 'main', MonitoringTask::PARAM_LANGUAGE => '2']);

        self::assertSame('2', $fields[MonitoringTask::PARAM_LANGUAGE]);
    }

    #[Test]
    public function otherTasksTablesAndUnknownSitesAreLeftAlone(): void
    {
        $scanTask = ['tasktype' => A11yScanTask::class, MonitoringTask::PARAM_SITE => 'shop', MonitoringTask::PARAM_LANGUAGE => '2'];
        self::assertSame($scanTask, $this->process($scanTask));

        $unknownSite = ['tasktype' => MonitoringTask::class, MonitoringTask::PARAM_SITE => 'retired', MonitoringTask::PARAM_LANGUAGE => '2'];
        self::assertSame($unknownSite, $this->process($unknownSite), 'Validation reports an unknown site; nothing to reset to.');

        $pages = ['tasktype' => MonitoringTask::class, MonitoringTask::PARAM_SITE => 'shop', MonitoringTask::PARAM_LANGUAGE => '2'];
        self::assertSame($pages, $this->process($pages, 'pages'));

        $rejected = false;
        $this->hook()->processDatamap_preProcessFieldArray($rejected, 'tx_scheduler_task', 'NEW1', $this->createMock(DataHandler::class));
        self::assertFalse($rejected, 'A save the Scheduler already refused stays refused.');
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function process(array $fields, string $table = 'tx_scheduler_task'): array
    {
        $this->hook()->processDatamap_preProcessFieldArray($fields, $table, 'NEW1', $this->createMock(DataHandler::class));

        return $fields;
    }

    private function hook(): MonitoringTaskLanguageHook
    {
        return new MonitoringTaskLanguageHook($this->resolverFor());
    }
}
