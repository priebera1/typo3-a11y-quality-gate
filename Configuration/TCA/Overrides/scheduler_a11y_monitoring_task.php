<?php

declare(strict_types=1);

use Priebera\A11yQualityGate\Monitoring\MonitoringTaskValidator;
use Priebera\A11yQualityGate\Scheduler\MonitoringTask;
use Priebera\A11yQualityGate\Scheduler\MonitoringTaskTcaItems;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// TYPO3 13 registers the task in ext_localconf.php with MonitoringTaskAdditionalFieldProvider.
if ((new Typo3Version())->getMajorVersion() < 14 || !isset($GLOBALS['TCA']['tx_scheduler_task'])) {
    return;
}

$ll = 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:';

ExtensionManagementUtility::addTCAcolumns(
    'tx_scheduler_task',
    [
        MonitoringTask::PARAM_SITE => [
            'label' => $ll . 'scheduler.monitoring.field.site',
            'description' => $ll . 'scheduler.monitoring.field.site.help',
            // The language list belongs to the selected site.
            'onChange' => 'reload',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'required' => true,
                'default' => '',
                'items' => [
                    [
                        'label' => $ll . 'scheduler.monitoring.field.site.placeholder',
                        'value' => '',
                    ],
                ],
                'itemsProcFunc' => MonitoringTaskTcaItems::class . '->addSiteItems',
            ],
        ],
        MonitoringTask::PARAM_LANGUAGE => [
            'label' => $ll . 'scheduler.monitoring.field.language',
            'description' => $ll . 'scheduler.monitoring.field.language.help',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'default' => 0,
                'items' => [],
                'itemsProcFunc' => MonitoringTaskTcaItems::class . '->addLanguageItems',
            ],
        ],
        MonitoringTask::PARAM_RECIPIENTS => [
            'label' => $ll . 'scheduler.monitoring.field.recipients',
            'description' => $ll . 'scheduler.monitoring.field.recipients.help',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 1000,
                'required' => true,
                'placeholder' => 'web@example.org, qa@example.org',
                'default' => '',
            ],
        ],
        MonitoringTask::PARAM_MAX_PAGES => [
            'label' => $ll . 'scheduler.monitoring.field.maxPages',
            'description' => $ll . 'scheduler.monitoring.field.maxPages.help',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'required' => true,
                'default' => MonitoringTaskValidator::MAX_PAGES_DEFAULT,
                'range' => [
                    'lower' => MonitoringTaskValidator::MAX_PAGES_MIN,
                    'upper' => MonitoringTaskValidator::MAX_PAGES_MAX,
                ],
            ],
        ],
        MonitoringTask::PARAM_MAX_WAIT => [
            'label' => $ll . 'scheduler.monitoring.field.maxWait',
            'description' => $ll . 'scheduler.monitoring.field.maxWait.help',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'required' => true,
                'default' => MonitoringTaskValidator::MAX_WAIT_DEFAULT,
                'range' => [
                    'lower' => 0,
                    'upper' => MonitoringTaskValidator::MAX_WAIT_MAX,
                ],
            ],
        ],
        MonitoringTask::PARAM_BACKEND_URL => [
            'label' => $ll . 'scheduler.monitoring.field.backendUrl',
            'description' => $ll . 'scheduler.monitoring.field.backendUrl.help',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'placeholder' => 'https://cms.example.org',
                'default' => '',
            ],
        ],
    ]
);

ExtensionManagementUtility::addRecordType(
    [
        'label' => $ll . 'scheduler.monitoring.task.title',
        'description' => $ll . 'scheduler.monitoring.task.description',
        'value' => MonitoringTask::class,
        'icon' => 'mimetypes-x-tx_scheduler_task_group',
        'iconOverlay' => 'overlay-scheduled',
        'group' => 'Accessibility Quality Gate',
    ],
    '
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
            tasktype,
            task_group,
            description,
            ' . MonitoringTask::PARAM_SITE . ',
            ' . MonitoringTask::PARAM_LANGUAGE . ',
            ' . MonitoringTask::PARAM_RECIPIENTS . ',
            ' . MonitoringTask::PARAM_MAX_PAGES . ',
            ' . MonitoringTask::PARAM_MAX_WAIT . ',
            ' . MonitoringTask::PARAM_BACKEND_URL . ',
        --div--;LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:scheduler.form.palettes.timing,
            execution_details,
            nextexecution,
            --palette--;;lastexecution,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
            disable,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:extended,',
    [],
    '',
    'tx_scheduler_task'
);
