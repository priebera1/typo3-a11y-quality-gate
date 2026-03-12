<?php

declare(strict_types=1);

defined('TYPO3') or die();

$ll = 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:';

return [
    'ctrl' => [
        'title' => $ll . 'tx_a11y_issue',
        'label' => 'rule_id',
        'label_alt' => 'message',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'rootLevel' => -1,
        'adminOnly' => true,
        'hideTable' => true,
        'searchFields' => 'rule_id,message,context_snippet,source_table,source_field,fingerprint',
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
            'label' => $ll . 'tx_a11y_issue.field.site_identifier',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'page_uid' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.page_uid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'source_lang_uid' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.source_lang_uid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'source_table' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.source_table',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'source_uid' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.source_uid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'source_field' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.source_field',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'rule_id' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.rule_id',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'severity' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.severity',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => $ll . 'tx_a11y_issue.severity.critical', 'value' => 1],
                    ['label' => $ll . 'tx_a11y_issue.severity.warning', 'value' => 2],
                    ['label' => $ll . 'tx_a11y_issue.severity.info', 'value' => 3],
                ],
                'readOnly' => true,
            ],
        ],
        'message' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.message',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'readOnly' => true,
            ],
        ],
        'hint' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.hint',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'readOnly' => true,
            ],
        ],
        'context_snippet' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.context_snippet',
            'config' => [
                'type' => 'text',
                'rows' => 4,
                'readOnly' => true,
            ],
        ],
        'context_path' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.context_path',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'fingerprint' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.fingerprint',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'status' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => $ll . 'tx_a11y_issue.status.open', 'value' => 0],
                    ['label' => $ll . 'tx_a11y_issue.status.resolved', 'value' => 1],
                    ['label' => $ll . 'tx_a11y_issue.status.ignored', 'value' => 2],
                    ['label' => $ll . 'tx_a11y_issue.status.muted', 'value' => 3],
                ],
                'readOnly' => true,
            ],
        ],
        'ignored_reason' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.ignored_reason',
            'config' => [
                'type' => 'text',
                'rows' => 2,
                'readOnly' => true,
            ],
        ],
        'ignored_by' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.ignored_by',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'ignored_at' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.ignored_at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'resolved_by' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.resolved_by',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'resolved_at' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.resolved_at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'first_seen_scan_uid' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.first_seen_scan_uid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'last_seen_scan_uid' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_issue.field.last_seen_scan_uid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
    ],
    'types' => [
        0 => [
            'showitem' => '
                site_identifier, page_uid, source_lang_uid,
                --div--;' . $ll . 'tx_a11y_issue.tab.source,
                    source_table, source_uid, source_field,
                --div--;' . $ll . 'tx_a11y_issue.tab.rule,
                    rule_id, severity, message, hint,
                --div--;' . $ll . 'tx_a11y_issue.tab.context,
                    context_snippet, context_path, fingerprint,
                --div--;' . $ll . 'tx_a11y_issue.tab.status,
                    status, ignored_reason, ignored_by, ignored_at, resolved_by, resolved_at,
                --div--;' . $ll . 'tx_a11y_issue.tab.scan,
                    crdate, tstamp, first_seen_scan_uid, last_seen_scan_uid
            ',
        ],
    ],
];
