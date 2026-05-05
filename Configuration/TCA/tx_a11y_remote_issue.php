<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue',
        'label' => 'rule_id',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'hideTable' => true,
        'rootLevel' => 1,
        'iconfile' => 'EXT:a11y_quality_gate/Resources/Public/Icons/module.svg',
    ],
    'columns' => [
        'remote_scan' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.field.remote_scan',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'remote_scan_page' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.field.remote_scan_page',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'rule_id' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.field.rule_id',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'impact' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.field.impact',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'help' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.field.help',
            'config' => [
                'type' => 'text',
                'readOnly' => true,
            ],
        ],
        'help_url' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.field.help_url',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'nodes_count' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.field.nodes_count',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'fingerprint' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.field.fingerprint',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'status' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.field.status',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
    ],
    'types' => [
        '1' => [
            'showitem' => '
                --div--;LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.tab.general,
                    remote_scan, remote_scan_page,
                --div--;LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.tab.rule,
                    rule_id, impact, help, help_url, nodes_count, fingerprint,
                --div--;LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.tab.state,
                    status
            ',
        ],
    ],
];