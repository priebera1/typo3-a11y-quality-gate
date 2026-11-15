<?php

declare(strict_types=1);

/**
 * Router for `php -S` in TranslatedPageRenderedScanFunctionalTest: a TYPO3-like page whose content element `c10` has a
 * button without a name, in English at /p2 and in German at /de/p20. Synthetic content only; loopback only.
 */
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$german = str_starts_with($path, '/de/');
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="' . ($german ? 'de' : 'en') . '"><head><title>' . ($german ? 'Seite' : 'Page') . '</title></head><body>'
    . '<main><h1>' . ($german ? 'Lokalen Scan abbrechen' : 'Cancel local scan') . '</h1>'
    . '<div id="c10" class="frame"><p>' . ($german ? 'Text' : 'Text') . '</p><button type="button"></button></div>'
    . '</main></body></html>';
