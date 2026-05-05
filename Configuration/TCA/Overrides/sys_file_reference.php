<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die();

ExtensionManagementUtility::addTCAcolumns('sys_file_reference', [
    'tx_a11y_is_decorative' => [
        'exclude' => 1,
        'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_a11y_is_decorative',
        'description' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_a11y_is_decorative.description',
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 0,
        ],
    ],
]);

ExtensionManagementUtility::addToAllTCAtypes(
    'sys_file_reference',
    'tx_a11y_is_decorative',
    '',
    'after:alternative'
);