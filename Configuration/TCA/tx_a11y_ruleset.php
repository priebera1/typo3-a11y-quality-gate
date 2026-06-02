<?php

declare(strict_types=1);

defined('TYPO3') or die();

$ll = 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:';

$tca = [
    'ctrl' => [
        'title' => $ll . 'tx_a11y_ruleset',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'rootLevel' => 1,
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
        'title' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_ruleset.field.title',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'site_identifier' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_ruleset.field.site_identifier',
            'description' => $ll . 'tx_a11y_ruleset.field.site_identifier.description',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'placeholder' => $ll . 'tx_a11y_ruleset.field.site_identifier.placeholder',
            ],
        ],
        'is_default' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_ruleset.field.is_default',
            'description' => $ll . 'tx_a11y_ruleset.field.is_default.description',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
            ],
        ],
        'publish_mode' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_ruleset.field.publish_mode',
            'description' => $ll . 'tx_a11y_ruleset.field.publish_mode.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    [
                        'label' => $ll . 'tx_a11y_ruleset.publish_mode.off',
                        'value' => 0,
                    ],
                    [
                        'label' => $ll . 'tx_a11y_ruleset.publish_mode.warn',
                        'value' => 1,
                    ],
                    [
                        'label' => $ll . 'tx_a11y_ruleset.publish_mode.block',
                        'value' => 2,
                    ],
                ],
                'default' => 0,
            ],
        ],
        'threshold_critical' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_ruleset.field.threshold_critical',
            'description' => $ll . 'tx_a11y_ruleset.field.threshold_critical.description',
            'config' => [
                'type' => 'number',
                'size' => 5,
                'default' => 0,
                'range' => [
                    'lower' => 0,
                ],
            ],
        ],
        'threshold_warning' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_ruleset.field.threshold_warning',
            'description' => $ll . 'tx_a11y_ruleset.field.threshold_warning.description',
            'config' => [
                'type' => 'number',
                'size' => 5,
                'default' => -1,
                'range' => [
                    'lower' => -1,
                ],
            ],
        ],
        'rules_json' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_ruleset.field.rules_json',
            'config' => [
                'type' => 'text',
                'rows' => 10,
            ],
        ],

        'scanner_token' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'http_auth_user' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_ruleset.field.http_auth_user',
            'description' => $ll . 'tx_a11y_ruleset.field.http_auth_user.description',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
            ],
        ],
        'http_auth_pass' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_ruleset.field.http_auth_pass',
            'description' => $ll . 'tx_a11y_ruleset.field.http_auth_pass.description',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'readOnly' => true,
            ],
        ],
        'excluded_patterns' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_ruleset.field.excluded_patterns',
            'description' => $ll . 'tx_a11y_ruleset.field.excluded_patterns.description',
            'config' => [
                'type' => 'text',
                'rows' => 6,
            ],
        ],
        'cookie_accept_selectors' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_ruleset.field.cookie_accept_selectors',
            'description' => $ll . 'tx_a11y_ruleset.field.cookie_accept_selectors.description',
            'config' => [
                'type' => 'text',
                'rows' => 4,
            ],
        ],
        'is_global' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_ruleset.field.is_global',
            'description' => $ll . 'tx_a11y_ruleset.field.is_global.description',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 1,
            ],
        ],
        'crawl_priority_urls' => [
            'exclude' => true,
            'label' => $ll . 'tx_a11y_ruleset.field.crawl_priority_urls',
            'description' => $ll . 'tx_a11y_ruleset.field.crawl_priority_urls.description',
            'config' => [
                'type' => 'text',
                'rows' => 6,
            ],
        ],
    ],
    'types' => [
        0 => [
            'showitem' => '
                --div--;' . $ll . 'tx_a11y_ruleset.tab.general,
                    title, is_default,
                --div--;' . $ll . 'tx_a11y_ruleset.tab.site,
                    site_identifier,
                --div--;' . $ll . 'tx_a11y_ruleset.tab.quality_gate,
                    publish_mode, threshold_critical, threshold_warning,
                --div--;' . $ll . 'tx_a11y_ruleset.tab.remote_scan_access,
                    http_auth_user, http_auth_pass, excluded_patterns, cookie_accept_selectors, is_global, crawl_priority_urls,
                --div--;' . $ll . 'tx_a11y_ruleset.tab.rules,
                    rules_json,
                --div--;' . $ll . 'tx_a11y_ruleset.tab.system,
                    crdate, tstamp
            ',
        ],
    ],
];

if ((new \TYPO3\CMS\Core\Information\Typo3Version())->getMajorVersion() < 14) {
    // TYPO3 v13 still uses ctrl['searchFields']; TYPO3 v14 logs a deprecation for it.
    $tca['ctrl']['searchFields'] = 'title,site_identifier';
}

return $tca;
