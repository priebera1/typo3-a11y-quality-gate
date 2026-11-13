<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Mcp\McpPairingService;

/**
 * "Open AQG account" (Settings › Agent access while a code waits) links the account page the Gateway names for this
 * environment (`confirmUrl`): first activation 2026-11-09 it was typo3.priebera.sk/app/agent-access (404), fixed at
 * the source in aqg-api (legacy /portal/agent-access, redirected to the app). TYPO3 renders it as a link, so only an
 * absolute http(s) URL is kept.
 */
final class McpAccountUrlTest extends TestCase
{
    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function urls(): iterable
    {
        yield 'app' => ['https://aqg.priebera.sk/app/agent-access', 'https://aqg.priebera.sk/app/agent-access'];
        yield 'legacy portal, redirected by the website' => ['https://typo3.priebera.sk/portal/agent-access', 'https://typo3.priebera.sk/portal/agent-access'];
        yield 'local development' => ['http://localhost:3002/portal/agent-access', 'http://localhost:3002/portal/agent-access'];
        yield 'script' => ['javascript:alert(1)', ''];
        yield 'data' => ['data:text/html,x', ''];
        yield 'relative' => ['/app/agent-access', ''];
        yield 'credentials' => ['https://user:pass@aqg.example/app/agent-access', ''];
        yield 'whitespace' => ["https://aqg.example/app/agent-access\n", ''];
        yield 'missing' => [null, ''];
        yield 'not a string' => [['https://aqg.example'], ''];
    }

    #[Test]
    #[DataProvider('urls')]
    public function onlyAnAbsoluteWebUrlIsKept(mixed $value, string $expected): void
    {
        self::assertSame($expected, McpPairingService::accountUrl($value));
    }

    #[Test]
    public function theButtonIsShownOnlyWithAUrl(): void
    {
        $template = (string)file_get_contents(__DIR__ . '/../../../Resources/Private/Partials/Settings/TabMcp.html');
        self::assertMatchesRegularExpression('/<f:if condition="\{mcp\.connection\.confirmUrl\}">\s*<a class="btn btn-primary btn-sm" href="\{mcp\.connection\.confirmUrl\}"/', $template);
    }
}
