<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * N-17 (final customer acceptance): My issues made the module 638 px wide at a 390 px viewport. The task table now
 * scrolls inside its frame, and the frame is the keyboard-focusable named region the Overview tables use.
 */
final class MyIssuesMobileTableTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    #[Test]
    public function theTaskTableScrollsInsideItsFrame(): void
    {
        $scss = self::read('Resources/Private/Scss/views/_issues.scss');
        self::assertMatchesRegularExpression('/\.aqg-issues \.aqg-table-wrap \{[^}]*overflow-x: auto;/s', $scss);
        self::assertStringContainsString('data-aqg-scroll-labelledby="aqg-issues-caption"', self::read('Resources/Private/Templates/Issues/Index.html'));
    }

    #[Test]
    public function theScrollingFrameIsKeyboardReachable(): void
    {
        self::assertStringContainsString("'@priebera/a11y-quality-gate/backend/issues.js'", self::read('Classes/Controller/IssuesController.php'));
        self::assertStringContainsString('initializeScrollableTables(document)', self::read('Resources/Public/JavaScript/backend/issues.js'));
    }
}
