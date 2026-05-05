<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan_page',
        'label' => 'url',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'hideTable' => true,
        'rootLevel' => 1,
        'iconfile' => 'EXT:a11y_quality_gate/Resources/Public/Icons/module.svg',
    ],
    'columns' => [
        'remote_scan' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan_page.field.remote_scan',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'source_type' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan_page.field.source_type',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'url' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan_page.field.url',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'title' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan_page.field.title',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'http_status' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan_page.field.http_status',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'issues_count' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan_page.field.issues_count',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'screenshot_path' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan_page.field.screenshot_path',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'screenshot_url' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan_page.field.screenshot_url',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'failure_reason' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan_page.field.failure_reason',
            'config' => [
                'type' => 'text',
                'readOnly' => true,
            ],
        ],
        'is_failed' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan_page.field.is_failed',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'readOnly' => true,
            ],
        ],
    ],
    'types' => [
        '1' => [
            'showitem' => '
                --div--;LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan_page.tab.general,
                    remote_scan, source_type, url, title,
                --div--;LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan_page.tab.result,
                    http_status, issues_count, is_failed, failure_reason,
                --div--;LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan_page.tab.artifacts,
                    screenshot_path, screenshot_url
            ',
        ],
    ],
];