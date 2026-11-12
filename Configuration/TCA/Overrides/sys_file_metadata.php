<?php

declare(strict_types=1);

defined('TYPO3') || die();

// AI media: the wand beside the allow-listed metadata fields of a file (the file default). It only fills the form;
// TYPO3 Save stores the values.
foreach (['alternative', 'title', 'description'] as $field) {
    if (isset($GLOBALS['TCA']['sys_file_metadata']['columns'][$field])) {
        $GLOBALS['TCA']['sys_file_metadata']['columns'][$field]['config']['fieldControl']['a11yMediaAi'] = [
            'renderType' => 'a11yMediaAiControl',
        ];
    }
}
