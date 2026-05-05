<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addStaticFile(
    'a11y_quality_gate',
    'Configuration/TypoScript',
    'Accessibility Quality Gate'
);