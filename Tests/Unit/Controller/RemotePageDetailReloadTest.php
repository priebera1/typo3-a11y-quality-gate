<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression (1.9.9, TYPO3 14): "Scan this page" on the frontend page view reloaded the same URL, which is bound to the
 * earlier scan, so the new findings only appeared after "Open latest scan". The reload after a scan opens the new scan.
 */
final class RemotePageDetailReloadTest extends TestCase
{
    #[Test]
    public function theReloadAfterAScanOpensTheNewerScan(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../../Classes/Controller/RemotePageDetailController.php');
        $normalized = (string)preg_replace('/\s+/', ' ', $source);

        self::assertStringContainsString(
            "if (\$newerScan !== [] && array_key_exists('_aqgReload', \$request->getQueryParams())) { return new RedirectResponse((string)\$newerScan['url']); }",
            $normalized
        );
        // Only that reload: opening an earlier scan on purpose keeps showing it with the "earlier scan" note.
        self::assertSame(1, substr_count($source, "'_aqgReload'"));
        self::assertLessThan(
            strpos($source, "'_aqgReload'"),
            strpos($source, '$newerScan = $this->resolveNewerScan(')
        );
    }

    #[Test]
    public function theModuleReloadMarksItself(): void
    {
        $module = (string)file_get_contents(__DIR__ . '/../../../Resources/Public/JavaScript/backend/core/base-module.js');

        self::assertStringContainsString("url.searchParams.set('_aqgReload', String(Date.now()));", $module);
    }
}
