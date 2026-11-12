<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Mcp\Http\McpGatewayClient;
use Priebera\A11yQualityGate\Mcp\McpAuditRepository;
use Priebera\A11yQualityGate\Mcp\McpConsentRefusal;

/**
 * U-07 (2026-11-05 company acceptance): a user whose group may not connect agents read "You may not connect agents",
 * but the agent never learned it and waited for its timeout. The refusal is decided at the Gateway like a "Deny", so
 * the browser can return to the agent with the OAuth error; it is audited with its reason.
 */
final class McpConsentRefusalTest extends TestCase
{
    #[Test]
    public function aPendingRequestIsDeniedAtTheGatewayAndAudited(): void
    {
        $gateway = $this->createMock(McpGatewayClient::class);
        $gateway->method('describeAuthorizationRequest')->willReturn(['status' => 'pending', 'clientName' => 'Claude']);
        $gateway->expects(self::once())->method('decideAuthorizationRequest')
            ->with(['request' => 'aqgr_x', 'decision' => 'deny'])
            ->willReturn(['redirect' => 'https://agent.example/callback?error=access_denied&state=s']);
        $audit = $this->createMock(McpAuditRepository::class);
        $audit->expects(self::once())->method('record')
            ->with('consent', 'denied', 'backend', ['beUserUid' => 4, 'beUserName' => 'Cara', 'clientName' => 'Claude', 'reason' => 'not_allowed']);

        $url = (new McpConsentRefusal($gateway, $audit))->refuse('aqgr_x', ['beUserUid' => 4, 'beUserName' => 'Cara'], 'not_allowed');

        self::assertSame('https://agent.example/callback?error=access_denied&state=s', $url);
    }

    #[Test]
    public function anAnsweredOrUnreachableRequestIsLeftAlone(): void
    {
        $gateway = $this->createMock(McpGatewayClient::class);
        $gateway->method('describeAuthorizationRequest')->willReturnOnConsecutiveCalls(['status' => 'decided'], self::throwException(new \RuntimeException('down')));
        $gateway->expects(self::never())->method('decideAuthorizationRequest');
        $audit = $this->createMock(McpAuditRepository::class);
        $audit->expects(self::never())->method('record');
        $refusal = new McpConsentRefusal($gateway, $audit);

        self::assertSame('', $refusal->refuse('aqgr_x', [], 'not_allowed'));
        self::assertSame('', $refusal->refuse('aqgr_x', [], 'not_allowed'));
    }
}
