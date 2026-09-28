<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Hook;

use Priebera\A11yQualityGate\Monitoring\MonitoringTargetResolver;
use Priebera\A11yQualityGate\Scheduler\MonitoringTask;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * TYPO3 14 monitoring task form: switching the site reloads (saves) the form while the language of the
 * previous site is still selected. That language is replaced by the new site's default language, so the
 * form shows a valid choice of the new site and a task never stores a language its site does not have.
 */
final class MonitoringTaskLanguageHook
{
    private const TABLE = 'tx_scheduler_task';

    public function __construct(
        private readonly MonitoringTargetResolver $monitoringTargetResolver,
    ) {}

    /**
     * @param array<string, mixed>|mixed $incomingFieldArray
     */
    public function processDatamap_preProcessFieldArray(&$incomingFieldArray, $table, $id, DataHandler $dataHandler): void
    {
        if ($table !== self::TABLE || !is_array($incomingFieldArray)) {
            return;
        }

        if (!array_key_exists(MonitoringTask::PARAM_LANGUAGE, $incomingFieldArray)) {
            return;
        }

        // FormEngine submits the task type and the site with the language; the stored row fills in otherwise.
        $stored = null;
        $storedValue = static function (string $field) use (&$stored, $id): string {
            $stored ??= MathUtility::canBeInterpretedAsInteger($id) ? (BackendUtility::getRecord(self::TABLE, (int)$id) ?? []) : [];

            return (string)($stored[$field] ?? '');
        };
        $taskType = (string)($incomingFieldArray['tasktype'] ?? $storedValue('tasktype'));
        if ($taskType !== MonitoringTask::class) {
            return;
        }

        $site = $this->monitoringTargetResolver->findSite((string)($incomingFieldArray[MonitoringTask::PARAM_SITE] ?? $storedValue(MonitoringTask::PARAM_SITE)));
        if (!$site instanceof Site) {
            return;
        }

        $languageUid = (int)$incomingFieldArray[MonitoringTask::PARAM_LANGUAGE];
        if (!$this->monitoringTargetResolver->isLanguageOfSite($site, $languageUid)) {
            $incomingFieldArray[MonitoringTask::PARAM_LANGUAGE] = $this->monitoringTargetResolver->defaultLanguageId($site);
        }
    }
}
