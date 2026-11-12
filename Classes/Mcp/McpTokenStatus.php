<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

/**
 * Status of an agent connection as the backend shows it, from the times the Gateway reports. "Expiring soon" means at
 * most a quarter of the granted lifetime, and never more than 7 days, is left – the rule of the Gateway
 * (aqg-api services/mcp/token-status.ts). A fixed 7-day window made every new 7-day connection (the preselected
 * lifetime since the public hardening, P1-5) "expiring soon" the moment it was created; re-deriving it here keeps the
 * display right also against a Gateway that still reports the old window. Revoked and expired come from the Gateway.
 */
final class McpTokenStatus
{
    public const EXPIRING_SOON_MAX_SECONDS = 7 * 86400;
    public const EXPIRING_SOON_SHARE = 0.25;

    public static function resolve(string $gatewayStatus, string $createdAt, string $expiresAt, ?int $now = null): string
    {
        if (!in_array($gatewayStatus, ['active', 'expiring_soon'], true)) {
            return in_array($gatewayStatus, ['expired', 'revoked'], true) ? $gatewayStatus : 'expired';
        }
        $created = strtotime($createdAt);
        $expires = strtotime($expiresAt);
        if ($created === false || $expires === false || $createdAt === '' || $expiresAt === '') {
            return $gatewayStatus;
        }
        $now ??= time();
        if ($expires <= $now) {
            return 'expired';
        }
        $window = min(self::EXPIRING_SOON_MAX_SECONDS, max(0, $expires - $created) * self::EXPIRING_SOON_SHARE);

        return $expires - $now <= $window ? 'expiring_soon' : 'active';
    }
}
