<?php

declare(strict_types=1);

// TYPO3 busts import-map URLs with a hash of the installed extension versions. A path or VCS install (`dev-main`) and
// files updated without a new installed version keep that hash, so browsers kept running earlier AQG scripts next to
// new templates and CSS (a row scan rendered the old "Scanning..." button text). Every AQG module therefore gets its own
// content version; the prefix entry stays for specifiers that carry their own query (BackendJavaScriptModuleService).
$imports = [
    '@priebera/a11y-quality-gate/' => 'EXT:a11y_quality_gate/Resources/Public/JavaScript/',
];

$moduleDirectory = dirname(__DIR__) . '/Resources/Public/JavaScript/';
if (is_dir($moduleDirectory)) {
    $moduleFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($moduleDirectory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($moduleFiles as $moduleFile) {
        if (!$moduleFile instanceof SplFileInfo || !$moduleFile->isFile() || $moduleFile->getExtension() !== 'js') {
            continue;
        }
        $relativePath = str_replace('\\', '/', substr($moduleFile->getPathname(), strlen($moduleDirectory)));
        $contentHash = hash_file('sha256', $moduleFile->getPathname());
        if (!is_string($contentHash)) {
            continue;
        }
        $imports['@priebera/a11y-quality-gate/' . $relativePath]
            = 'EXT:a11y_quality_gate/Resources/Public/JavaScript/' . $relativePath . '?v=' . substr($contentHash, 0, 12);
    }
}

return [
    'dependencies' => [
        'backend',
    ],
    'tags' => [
        'backend.form',
    ],
    'imports' => $imports,
];
