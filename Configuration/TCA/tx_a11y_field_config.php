<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:settings.record.title',
        'label' => 'field_label',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'hideTable' => true,
        'rootLevel' => 1,
        'iconfile' => 'EXT:a11y_quality_gate/Resources/Public/Icons/module.svg',
    ],
    'columns' => [
        'hidden' => [
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
        'table_name' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:settings.field.table',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'field_name' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:settings.field.field',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'field_type' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:settings.field.type',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'field_label' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:settings.field.label',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'is_enabled' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:settings.field.enabled',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
        'is_auto_detected' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:settings.field.autoDetected',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'readOnly' => true,
            ],
        ],
    ],
    'types' => [
        '1' => [
            'showitem' => 'table_name, field_name, field_type, field_label, is_enabled, is_auto_detected, hidden',
        ],
    ],
];
