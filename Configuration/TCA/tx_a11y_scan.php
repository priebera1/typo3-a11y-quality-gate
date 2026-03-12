<?php

declare(strict_types=1);

defined('TYPO3') or die();

$ll = 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:';

return [
    'ctrl' => [
        'title' => $ll . 'tx_a11y_scan',
        'label' => 'site_identifier',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'readOnly' => true,
        'rootLevel' => -1,
        'hideTable' => true,
        'searchFields' => 'site_identifier,scope,status',
        'iconfile' => 'EXT:a11y_quality_gate/Resources/Public/Icons/Extension.svg',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'pid' => [
            'label' => 'pid',
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'crdate' => [
            'label' => 'crdate',
            'config' => [
                'type' => 'datetime',
            ],
        ],
        'tstamp' => [
            'label' => 'tstamp',
            'config' => [
                'type' => 'datetime',
            ],
        ],
        'site_identifier' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_scan.field.site_identifier',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'root_pid' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_scan.field.root_pid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'language_uid' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_scan.field.language_uid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'scope' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_scan.field.scope',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'status' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_scan.field.status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    [
                        'label' => $ll . 'tx_a11y_scan.status.pending',
                        'value' => 0,
                    ],
                    [
                        'label' => $ll . 'tx_a11y_scan.status.running',
                        'value' => 1,
                    ],
                    [
                        'label' => $ll . 'tx_a11y_scan.status.done',
                        'value' => 2,
                    ],
                    [
                        'label' => $ll . 'tx_a11y_scan.status.failed',
                        'value' => 3,
                    ],
                ],
                'readOnly' => true,
            ],
        ],
        'started_at' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_scan.field.started_at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'finished_at' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_scan.field.finished_at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'pages_scanned' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_scan.field.pages_scanned',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'records_scanned' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_scan.field.records_scanned',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'issues_new' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_scan.field.issues_new',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'issues_resolved' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_scan.field.issues_resolved',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'issues_ignored' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_scan.field.issues_ignored',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
    ],
    'types' => [
        0 => [
            'showitem' => '
                --div--;' . $ll . 'tx_a11y_scan.tab.general,
                    site_identifier, status, scope, root_pid, language_uid,
                --div--;' . $ll . 'tx_a11y_scan.tab.timing,
                    started_at, finished_at,
                --div--;' . $ll . 'tx_a11y_scan.tab.result,
                    pages_scanned, records_scanned, issues_new, issues_resolved, issues_ignored,
                --div--;' . $ll . 'tx_a11y_scan.tab.system,
                    crdate, tstamp
            ',
        ],
    ],
];
