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
