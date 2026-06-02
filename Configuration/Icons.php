<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Information\Typo3Version;

$version = new Typo3Version();
$majorVersion = $version->getMajorVersion();

$moduleIcon = $majorVersion >= 14
    ? 'EXT:a11y_quality_gate/Resources/Public/Icons/module-v14.svg'
    : 'EXT:a11y_quality_gate/Resources/Public/Icons/module-v13.svg';

return [
    'a11y-quality-gate-module' => [
        'provider' => SvgIconProvider::class,
        'source' => $moduleIcon,
    ],
];
