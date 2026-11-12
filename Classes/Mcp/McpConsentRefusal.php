<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

use Priebera\A11yQualityGate\Mcp\Http\McpGatewayClient;

/**
 * A consent the user cannot give (their group may not connect agents) is answered like "Deny": the Gateway records the
 * refusal and hands back the agent's redirect URI with the OAuth error, so the agent stops waiting (U-07). Only a pending
 * request is decided; an answered, expired or unreachable one is left alone.
 */
final class McpConsentRefusal
{
    public function __construct(
        private readonly McpGatewayClient $gatewayClient,
        private readonly McpAuditRepository $auditRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $auditContext beUserUid, beUserName
     * @return string the agent's redirect URL, '' when nothing was decided
     */
    public function refuse(string $requestRef, array $auditContext, string $reason): string
    {
        try {
            $described = $this->gatewayClient->describeAuthorizationRequest($requestRef);
            if (($described['status'] ?? '') !== 'pending') {
                return '';
            }
            $outcome = $this->gatewayClient->decideAuthorizationRequest(['request' => $requestRef, 'decision' => 'deny']);
        } catch (\Throwable) {
            return '';
        }
        $this->auditRepository->record('consent', 'denied', 'backend', $auditContext + [
            'clientName' => (string)($described['clientName'] ?? ''),
            'reason' => $reason,
        ]);

        return (string)($outcome['redirect'] ?? '');
    }
}
