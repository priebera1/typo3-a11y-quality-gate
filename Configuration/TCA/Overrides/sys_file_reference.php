<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die();

ExtensionManagementUtility::addTCAcolumns('sys_file_reference', [
    'tx_a11y_is_decorative' => [
        'exclude' => true,
        'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_a11y_is_decorative',
        'description' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_a11y_is_decorative.description',
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 0,
        ],
    ],
]);

// UX Atlas 05 B: "Decorative image" sits directly under the alternative text, on its own row of every palette that
// holds that field (imageoverlayPalette, extendedBasicOverlayPalette). Types without such a palette keep the switch
// after their fields, as before. Display only: the column and the DataHandler write set are unchanged.
$altPalettes = [];
foreach ($GLOBALS['TCA']['sys_file_reference']['palettes'] ?? [] as $paletteName => $palette) {
    if (preg_match('/(?:^|,)\s*alternative\s*(?:[;,]|$)/', (string)($palette['showitem'] ?? '')) === 1) {
        ExtensionManagementUtility::addFieldsToPalette(
            'sys_file_reference',
            (string)$paletteName,
            '--linebreak--,tx_a11y_is_decorative,--linebreak--',
            'after:alternative',
        );
        $altPalettes[] = (string)$paletteName;
    }
}
$typesWithoutAltPalette = [];
foreach ($GLOBALS['TCA']['sys_file_reference']['types'] ?? [] as $type => $typeConfiguration) {
    $showitem = (string)($typeConfiguration['showitem'] ?? '');
    $usesAltPalette = array_filter($altPalettes, static fn (string $name): bool => preg_match('/--palette--;[^;,]*;' . preg_quote($name, '/') . '\b/', $showitem) === 1);
    if ($usesAltPalette === []) {
        $typesWithoutAltPalette[] = (string)$type;
    }
}
if ($typesWithoutAltPalette !== []) {
    ExtensionManagementUtility::addToAllTCAtypes(
        'sys_file_reference',
        'tx_a11y_is_decorative',
        implode(',', $typesWithoutAltPalette),
        'after:alternative',
    );
}
unset($altPalettes, $typesWithoutAltPalette, $paletteName, $palette, $type, $typeConfiguration, $showitem, $usesAltPalette);

// AI media: the wand beside the alternative text of one image use (suggestion only; TYPO3 Save stores it).
$GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['config']['fieldControl']['a11yMediaAi'] = [
    'renderType' => 'a11yMediaAiControl',
];

// The ALT status chip on the image's header follows the form while it is edited, with or without AI (a hidden
// marker only; nothing is sent or written).
$GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['config']['fieldWizard']['a11yMediaAltStatus'] = [
    'renderType' => 'a11yMediaAltStatus',
];
