<?php

declare(strict_types=1);

defined('TYPO3') || die();

return [
    'ctrl' => [
        'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue',
        'label' => 'rule_id',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'hideTable' => true,
        'rootLevel' => 1,
        'iconfile' => 'EXT:a11y_quality_gate/Resources/Public/Icons/Extension.svg',
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
        'guidance_why_it_matters' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.field.guidance_why_it_matters',
            'config' => [
                'type' => 'text',
                'readOnly' => true,
            ],
        ],
        'guidance_how_to_fix' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.field.guidance_how_to_fix',
            'config' => [
                'type' => 'text',
                'readOnly' => true,
            ],
        ],
        'who_should_fix' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.field.who_should_fix',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'fix_type' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.field.fix_type',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'confidence' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.field.confidence',
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
                --div--;LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.tab.guidance,
                    guidance_why_it_matters, guidance_how_to_fix, who_should_fix, fix_type, confidence,
                --div--;LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue.tab.state,
                    status
            ',
        ],
    ],
];
