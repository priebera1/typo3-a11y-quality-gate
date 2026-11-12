<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Command\McpConnectorCommand;

/**
 * Sol post-remediation review: the connector's local error log never carries credentials, page markup or unbounded
 * upstream text from a failed tool call.
 */
final class McpConnectorLogRedactionTest extends TestCase
{
    #[Test]
    public function credentialsMarkupAndLongSecretsNeverReachTheLog(): void
    {
        $message = McpConnectorCommand::loggableMessage(
            'Gateway said no for aqgc_' . str_repeat('A', 43) . ' and aqgm_' . str_repeat('b', 43)
            . ' with Authorization: Bearer abcdefghijklmnop key=' . str_repeat('Z9', 30)
            . ' while rendering <div class="secret">PRIVATE_PAGE_TEXT</div> after that'
        );

        self::assertStringNotContainsString('aqgc_AAAA', $message);
        self::assertStringNotContainsString('aqgm_bbbb', $message);
        self::assertStringNotContainsString('abcdefghijklmnop', $message);
        self::assertStringNotContainsString('Z9Z9Z9Z9', $message);
        self::assertStringNotContainsString('PRIVATE_PAGE_TEXT', $message);
        self::assertStringNotContainsString('<div', $message);
        self::assertStringContainsString('[markup removed]', $message);
        self::assertStringStartsWith('Gateway said no for aqg*_[redacted]', $message);
    }

    #[Test]
    public function theMessageIsBounded(): void
    {
        self::assertSame(300, mb_strlen(McpConnectorCommand::loggableMessage(str_repeat('word ', 500))));
    }
}
