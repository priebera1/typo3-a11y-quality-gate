<?php

declare(strict_types=1);

$projectAutoload = dirname(__DIR__, 4) . '/vendor/autoload.php';

if (!is_file($projectAutoload)) {
    throw new RuntimeException('Composer autoload not found at ' . $projectAutoload);
}

require_once $projectAutoload;

// The host project does not load the extension's autoload-dev: shared test fixtures load from Tests/ here,
// as in FunctionalTestsBootstrap.php.
$extensionRoot = dirname(__DIR__, 2);
spl_autoload_register(
    static function (string $className) use ($extensionRoot): void {
        $prefix = 'Priebera\\A11yQualityGate\\Tests\\';

        if (!str_starts_with($className, $prefix)) {
            return;
        }

        $file = $extensionRoot . '/Tests/' . str_replace('\\', '/', substr($className, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
);

if (class_exists(\DG\BypassFinals::class)) {
    \DG\BypassFinals::enable();
}
