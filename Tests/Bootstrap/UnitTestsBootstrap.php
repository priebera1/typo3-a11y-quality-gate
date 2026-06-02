<?php

declare(strict_types=1);

$projectAutoload = dirname(__DIR__, 4) . '/vendor/autoload.php';

if (!is_file($projectAutoload)) {
    throw new RuntimeException('Composer autoload not found at ' . $projectAutoload);
}

require_once $projectAutoload;

if (class_exists(\DG\BypassFinals::class)) {
    \DG\BypassFinals::enable();
}
