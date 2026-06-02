<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 4);
$extensionRoot = dirname(__DIR__, 2);
$projectAutoload = $projectRoot . '/vendor/autoload.php';
$functionalBootstrap = $projectRoot . '/vendor/typo3/testing-framework/Resources/Core/Build/FunctionalTestsBootstrap.php';

if (!is_file($projectAutoload)) {
    throw new RuntimeException('Composer autoload not found at ' . $projectAutoload);
}

require_once $projectAutoload;


$requiredCorePackages = [
    'typo3/cms-rte-ckeditor' => 'EXT:rte_ckeditor is required by EXT:a11y_quality_gate. Install it in the TYPO3 test project with: composer require "typo3/cms-rte-ckeditor:^13.4 || ^14.3" -W',
];

foreach ($requiredCorePackages as $packageName => $errorMessage) {
    $packagePath = $projectRoot . '/vendor/' . $packageName . '/composer.json';
    if (!is_file($packagePath)) {
        throw new RuntimeException($errorMessage);
    }
}

spl_autoload_register(
    static function (string $className) use ($extensionRoot): void {
        $prefix = 'Priebera\\A11yQualityGate\\Tests\\';

        if (!str_starts_with($className, $prefix)) {
            return;
        }

        $relativeClassName = substr($className, strlen($prefix));
        $file = $extensionRoot . '/Tests/' . str_replace('\\', '/', $relativeClassName) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
);

if (!is_file($functionalBootstrap)) {
    throw new RuntimeException('TYPO3 functional test bootstrap not found at ' . $functionalBootstrap);
}

require $functionalBootstrap;
