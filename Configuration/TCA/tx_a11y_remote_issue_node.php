<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue_node',
        'label' => 'uid',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'hideTable' => true,
        'rootLevel' => 1,
        'iconfile' => 'EXT:a11y_quality_gate/Resources/Public/Icons/module.svg',
    ],
    'columns' => [
        'remote_issue' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue_node.field.remote_issue',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'target_json' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue_node.field.target_json',
            'config' => [
                'type' => 'text',
                'readOnly' => true,
            ],
        ],
        'html_snippet' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue_node.field.html_snippet',
            'config' => [
                'type' => 'text',
                'readOnly' => true,
            ],
        ],
        'failure_summary' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue_node.field.failure_summary',
            'config' => [
                'type' => 'text',
                'readOnly' => true,
            ],
        ],
        'screenshot_path' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue_node.field.screenshot_path',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'screenshot_url' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue_node.field.screenshot_url',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'mapped_table' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue_node.field.mapped_table',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'mapped_uid' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue_node.field.mapped_uid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'mapped_cid' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue_node.field.mapped_cid',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'mapped_ctype' => [
            'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue_node.field.mapped_ctype',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
    ],
    'types' => [
        '1' => [
            'showitem' => '
                --div--;LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue_node.tab.general,
                    remote_issue, target_json, html_snippet, failure_summary,
                --div--;LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue_node.tab.mapping,
                    mapped_table, mapped_uid, mapped_cid, mapped_ctype,
                --div--;LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:tx_a11y_remote_issue_node.tab.artifacts,
                    screenshot_path, screenshot_url
            ',
        ],
    ],
];