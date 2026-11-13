<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Dto;

final class AccessTokenResponseDto
{
    /**
     * @param list<string> $features
     * @param list<string> $capabilities
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $accessToken,
        public readonly int $expiresIn,
        public readonly string $plan,
        public readonly array $features,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly string $entitlement = '',
        public readonly array $capabilities = [],
        /** M-06: the API's HTTP status of a refusal (error.status, or the rate limiter's statusCode). 0 = unknown. */
        public readonly int $httpStatus = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];

        $features = array_values(array_filter(
            array_map(
                static fn(mixed $value): string => trim((string)$value),
                is_array($payload['features'] ?? null) ? $payload['features'] : []
            ),
            static fn(string $value): bool => $value !== ''
        ));

        $capabilities = array_values(array_filter(
            array_map(
                static fn(mixed $value): string => trim((string)$value),
                is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : []
            ),
            static fn(string $value): bool => $value !== ''
        ));

        $accessToken = isset($payload['access_token']) ? trim((string)$payload['access_token']) : null;
        if ($accessToken === '') {
            $accessToken = null;
        }

        return new self(
            success: $accessToken !== null,
            accessToken: $accessToken,
            expiresIn: max(0, (int)($payload['expires_in'] ?? 0)),
            plan: trim((string)($payload['plan'] ?? '')),
            features: $features,
            // M-06: the rate limiter answers {"statusCode":429,"error":"Too Many Requests"} without an error object. A
            // missing code read as a licence refusal ("Revalidate the licence") — it is a limit to wait out.
            errorCode: isset($error['code']) ? (string)$error['code'] : (self::statusOf($payload) === 429 ? 'rate_limit_exceeded' : null),
            errorMessage: isset($error['message']) ? (string)$error['message'] : (isset($payload['message']) && is_string($payload['message']) ? $payload['message'] : null),
            entitlement: trim((string)($payload['entitlement'] ?? '')),
            capabilities: $capabilities,
            httpStatus: self::statusOf($payload),
        );
    }

    /** @param array<string, mixed> $payload */
    private static function statusOf(array $payload): int
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];

        return max(0, (int)($error['status'] ?? $payload['statusCode'] ?? 0));
    }
}
