<?php

declare(strict_types=1);

use Priebera\A11yQualityGate\Scheduler\A11yScanTaskAdditionalFieldProvider;

defined('TYPO3') || die();

(static function (): void {
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
})();
