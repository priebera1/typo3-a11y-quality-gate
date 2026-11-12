<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fable F-08: the consent page keeps every existing protection (self-declared client name, the return host or the
 * loopback note, the grant computed on the server) and says, before the choice, when to deny: dynamic registration
 * lets anyone who knows the server URL send a consent link.
 */
final class McpConsentClarityTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../';

    #[Test]
    public function theConsentFormSaysWhenToDenyBeforeTheButtons(): void
    {
        $template = (string)file_get_contents(self::ROOT . 'Resources/Private/Templates/Mcp/Consent.html');
        $warning = strpos($template, 'mcp.consent.startedByYou');
        $buttons = strpos($template, 'name="decision" value="deny"');
        self::assertIsInt($warning);
        self::assertIsInt($buttons);
        self::assertLessThan($buttons, $warning, 'read before deciding');
        foreach (['mcp.consent.clientNote', 'mcp.consent.returnsLocal', 'mcp.consent.returnsTo', '{0: redirectHost}'] as $kept) {
            self::assertStringContainsString($kept, $template, $kept . ' stays on the page');
        }
    }

    #[Test]
    public function theHintIsTranslated(): void
    {
        $english = (string)file_get_contents(self::ROOT . 'Resources/Private/Language/locallang.xlf');
        $german = (string)file_get_contents(self::ROOT . 'Resources/Private/Language/de.locallang.xlf');
        self::assertMatchesRegularExpression('#<trans-unit id="mcp\.consent\.startedByYou">\s*<source>[^<]*choose Deny\.</source>#', $english);
        self::assertMatchesRegularExpression('#<trans-unit id="mcp\.consent\.startedByYou">\s*<source>[^<]+</source>\s*<target>[^<]*Ablehnen[^<]*</target>#u', $german);
    }
}
