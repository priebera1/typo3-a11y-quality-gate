<?php

declare(strict_types=1);

use Priebera\A11yQualityGate\Scheduler\A11yScanTaskAdditionalFieldProvider;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die();

(static function (): void {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['a11y_quality_gate_pro'] ??= [];

    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks']
    [\Priebera\A11yQualityGate\Scheduler\A11yScanTask::class] = [
        'extension' => 'a11y_quality_gate',
        'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.task.title',
        'description' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.task.description',
        'additionalFields' => A11yScanTaskAdditionalFieldProvider::class,
    ];

    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
        = \Priebera\A11yQualityGate\Hook\PublishHook::class;

    $GLOBALS['TYPO3_CONF_VARS']['BE']['stylesheets']['a11y_quality_gate']
        = 'EXT:a11y_quality_gate/Resources/Public/Css/backend.css';

    // chash generation
    $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'aqgDebug';
    $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'aqgh';

    ExtensionManagementUtility::addTypoScript(
        'a11y_quality_gate',
        'setup',
        '
        @import "EXT:a11y_quality_gate/Configuration/TypoScript/setup.typoscript"
    ',
    );
})();
