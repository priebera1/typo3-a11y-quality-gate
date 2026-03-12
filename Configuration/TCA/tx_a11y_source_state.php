<?php

declare(strict_types=1);

defined('TYPO3') or die();

$ll = 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:';

return [
    'ctrl' => [
        'title' => $ll . 'tx_a11y_source_state',
        'label' => 'source_table',
        'label_alt' => 'source_field',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'readOnly' => true,
        'rootLevel' => -1,
        'hideTable' => true,
        'searchFields' => 'site_identifier,source_table,source_field,content_hash',
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
            'label' => $ll . 'tx_a11y_source_state.field.site_identifier',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'page_uid' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_source_state.field.page_uid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'source_lang_uid' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_source_state.field.source_lang_uid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'source_table' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_source_state.field.source_table',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'source_uid' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_source_state.field.source_uid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'source_field' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_source_state.field.source_field',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'content_hash' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_source_state.field.content_hash',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'last_scan_uid' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_source_state.field.last_scan_uid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
    ],
    'types' => [
        0 => [
            'showitem' => '
                --div--;' . $ll . 'tx_a11y_source_state.tab.source,
                    site_identifier, page_uid, source_lang_uid, source_table, source_uid, source_field,
                --div--;' . $ll . 'tx_a11y_source_state.tab.state,
                    content_hash, last_scan_uid,
                --div--;' . $ll . 'tx_a11y_source_state.tab.system,
                    crdate, tstamp
            ',
        ],
    ],
];
