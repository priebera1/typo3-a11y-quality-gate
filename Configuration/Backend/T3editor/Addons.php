<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;

return [
    'a11y-quality-gate/html-markers' => [
        // Keep this addon intentionally passive: it only adds a small CodeMirror extension
        // which listens for AQG custom events. Existing TYPO3 CodeMirror behaviour stays untouched.
        'module' => JavaScriptModuleInstruction::create(
            '@priebera/a11y-quality-gate/codemirror/a11y-html-addon.js',
            'a11yHtmlAddon'
        )->invoke(),
        'cssFiles' => [
            'EXT:a11y_quality_gate/Resources/Public/Css/ckeditor.css',
        ],
    ],
];
