<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan',
        'label' => 'job_id',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'hideTable' => true,
        'rootLevel' => 1,
        'iconfile' => 'EXT:a11y_quality_gate/Resources/Public/Icons/module.svg',
    ],
    'columns' => [
        'site_identifier' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan.field.site_identifier',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'job_id' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan.field.job_id',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'source_type' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan.field.source_type',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'scan_scope' => [
            'label' => 'Scan scope',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'page_uid' => [
            'label' => 'Page UID',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'start_url' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan.field.start_url',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'sitemap_url' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan.field.sitemap_url',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'status' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan.field.status',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'pages_scanned' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan.field.pages_scanned',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'pages_total' => [
            'label' => 'Pages total',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'pages_failed' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan.field.pages_failed',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'issues_total' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan.field.issues_total',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'issues_new' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan.field.issues_new',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'issues_resolved' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan.field.issues_resolved',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'started_at' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan.field.started_at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
                'format' => 'datetime',
            ],
        ],
        'finished_at' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_scan.field.finished_at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
                'format' => 'datetime',
            ],
        ],
        'last_synced_at' => [
            'label' => 'Last synced at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
                'format' => 'datetime',
            ],
        ],
        'persisted_at' => [
            'label' => 'Persisted at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
                'format' => 'datetime',
            ],
        ],
        'sync_error' => [
            'label' => 'Sync error',
            'config' => [
                'type' => 'text',
                'readOnly' => true,
                'rows' => 4,
            ],
        ],
    ],
    'types' => [
        '1' => [
            'showitem' => '
                --div--;General,
                    site_identifier, job_id, source_type, scan_scope, page_uid, status, start_url, sitemap_url,
                --div--;Result,
                    pages_scanned, pages_total, pages_failed, issues_total, issues_new, issues_resolved,
                --div--;Timing,
                    started_at, finished_at, last_synced_at, persisted_at,
                --div--;Debug,
                    sync_error
            ',
        ],
    ],
];