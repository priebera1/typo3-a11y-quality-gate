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
            errorCode: isset($error['code']) ? (string)$error['code'] : null,
            errorMessage: isset($error['message']) ? (string)$error['message'] : null,
            entitlement: trim((string)($payload['entitlement'] ?? '')),
            capabilities: $capabilities,
        );
    }
}
