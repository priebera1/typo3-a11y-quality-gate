<?php

declare(strict_types=1);

use Priebera\A11yQualityGate\FormEngine\FieldWizard\PlainHtmlA11yWizard;
use Priebera\A11yQualityGate\Scheduler\A11yScanTaskAdditionalFieldProvider;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die();

(static function (): void {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['a11y_quality_gate_pro'] ??= [];

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1778769000] = [
        'nodeName' => 'a11yPlainHtmlWizard',
        'priority' => 40,
        'class' => PlainHtmlA11yWizard::class,
    ];

    if ((new Typo3Version())->getMajorVersion() < 14) {
        // TYPO3 13 uses the classic Scheduler registration. TYPO3 14 uses
        // Configuration/TCA/Overrides/scheduler_a11y_scan_task.php instead.
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks']
        [\Priebera\A11yQualityGate\Scheduler\A11yScanTask::class] = [
            'extension' => 'a11y_quality_gate',
            'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.task.title',
            'description' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.task.description',
            'additionalFields' => A11yScanTaskAdditionalFieldProvider::class,
        ];
    }

    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
        = \Priebera\A11yQualityGate\Hook\PublishHook::class;
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
        = \Priebera\A11yQualityGate\Hook\FileReferenceDecorativeHook::class;

    // Rendered page checks use internal short-lived AQG parameters. Keep only AQG-owned
    // parameters out of cHash validation and avoid changing TYPO3's global cache-disabling handling.
    foreach ([
        'aqgDebug',
        'aqgh',
        'tx_aqg_rendered_check',
        '_aqg_page',
        '_aqg_lang',
        '_aqg_nonce',
    ] as $parameterName) {
        if (!in_array($parameterName, $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'] ?? [], true)) {
            $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = $parameterName;
        }
    }

    ExtensionManagementUtility::addTypoScript(
        'a11y_quality_gate',
        'setup',
        '
        @import "EXT:a11y_quality_gate/Configuration/TypoScript/setup.typoscript"
    ',
    );
})();
