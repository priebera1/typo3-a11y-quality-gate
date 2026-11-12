<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Final customer acceptance (MCP polish): the capability column read "findings:readhistory:readscans:run-page" to
 * screen readers and in copied text, and a user's own revocation was audited as an administrator change.
 */
final class McpAgentListPolishTest extends TestCase
{
    #[Test]
    public function capabilitiesAreSeparated(): void
    {
        $partial = (string)file_get_contents(__DIR__ . '/../../../Resources/Private/Partials/Settings/McpTokenTable.html');

        self::assertStringContainsString('<f:for each="{token.scopes}" as="scope" iteration="scopeIteration">', $partial);
        self::assertStringContainsString('<span class="aqg-mcp-scopes__sep visually-hidden">, </span>', $partial);
    }

    #[Test]
    public function aSelfRevocationIsAuditedAsTheUsersOwnChange(): void
    {
        $controller = (string)file_get_contents(__DIR__ . '/../../../Classes/Controller/McpConsentController.php');

        self::assertStringContainsString("record('token_revoked', 'user_change', 'backend'", $controller);
        self::assertStringNotContainsString("record('token_revoked', 'admin_change', 'backend', \$this->auditContext(", $controller);
    }
}
