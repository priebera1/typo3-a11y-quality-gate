<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp\Http;

use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;

/**
 * The one Gateway request deferred work needs (release-gate finding 2): the licence API's continuation of a call.
 * Implemented by McpGatewayClient; a separate type so the deferred path can be tested against a signing stand-in.
 */
interface McpContinuationClient
{
    /**
     * @return array{continuation: string, expiresAt: string}
     * @throws ApiRequestFailedException for every refusal, transport failure or timeout
     */
    public function continueCall(string $callId, string $attestation, string $subject, float $timeout = 2.0): array;
}
