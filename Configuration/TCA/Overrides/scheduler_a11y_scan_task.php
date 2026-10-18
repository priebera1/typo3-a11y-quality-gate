<?php

declare(strict_types=1);

use Priebera\A11yQualityGate\Scheduler\A11yScanTask;
use Priebera\A11yQualityGate\Scheduler\A11yScanTaskTcaItems;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

if ((new Typo3Version())->getMajorVersion() < 14 || !isset($GLOBALS['TCA']['tx_scheduler_task'])) {
    return;
}

ExtensionManagementUtility::addTCAcolumns(
    'tx_scheduler_task',
    [
        A11yScanTask::PARAM_PAGE_UID => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.field.pageUid',
            'description' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.field.pageUid.help',
            'onChange' => 'reload',
            'config' => [
                'type' => 'number',
                'default' => 0,
                'range' => [
                    'lower' => 0,
                ],
            ],
        ],
        A11yScanTask::PARAM_ROOT_PID => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.field.rootPid',
            'description' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.field.rootPid.help',
            'onChange' => 'reload',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'default' => 0,
                'items' => [
                    [
                        'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.field.rootPid.placeholder',
                        'value' => 0,
                    ],
                ],
                'itemsProcFunc' => A11yScanTaskTcaItems::class . '->addRootPageItems',
            ],
        ],
        A11yScanTask::PARAM_DEPTH => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.field.depth',
            'config' => [
                'type' => 'number',
                'default' => 99,
                'range' => [
                    'lower' => 1,
                ],
            ],
        ],
        A11yScanTask::PARAM_LANGUAGE_UID => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.field.languageUid',
            'description' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.field.languageUid.help',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'default' => -1,
                'items' => [
                    [
                        'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.field.languageUid.allLanguages',
                        'value' => -1,
                    ],
                    [
                        'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.field.languageUid.defaultLanguage',
                        'value' => 0,
                    ],
                ],
                'itemsProcFunc' => A11yScanTaskTcaItems::class . '->addLanguageItems',
            ],
        ],
        A11yScanTask::PARAM_CHANGED_ONLY => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.field.changedOnly',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
            ],
        ],
    ]
);

ExtensionManagementUtility::addRecordType(
    [
        'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.task.title',
        'description' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.task.description',
        'value' => A11yScanTask::class,
        'icon' => 'mimetypes-x-tx_scheduler_task_group',
        'iconOverlay' => 'content-clock',
        'group' => 'Accessibility Quality Gate',
    ],
    '
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
            tasktype,
            task_group,
            description,
            ' . A11yScanTask::PARAM_PAGE_UID . ',
            ' . A11yScanTask::PARAM_ROOT_PID . ',
            ' . A11yScanTask::PARAM_DEPTH . ',
            ' . A11yScanTask::PARAM_LANGUAGE_UID . ',
            ' . A11yScanTask::PARAM_CHANGED_ONLY . ',
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

// TYPO3 14 lists every schedulable console command as a task type of its own, grouped by the command namespace, so
// `a11y:scan` appeared in a separate "A11y" category next to the AQG tasks. Core adds these items before the TCA
// overrides run. The command stays schedulable and keeps its task type: persisted `a11y:scan` tasks (also those
// migrated from TYPO3 13) are resolved through this item and fail as "not registered" without it. It is only listed
// in the AQG category, under a name that tells it apart from the Site Scan task.
foreach ($GLOBALS['TCA']['tx_scheduler_task']['columns']['tasktype']['config']['items'] ?? [] as $index => $item) {
    if (is_array($item) && ($item['value'] ?? null) === 'a11y:scan') {
        $GLOBALS['TCA']['tx_scheduler_task']['columns']['tasktype']['config']['items'][$index] = array_replace($item, [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.command.scan.title',
            'description' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.command.scan.description',
            'group' => 'Accessibility Quality Gate',
        ]);
    }
}
