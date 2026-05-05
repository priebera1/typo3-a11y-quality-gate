<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Dto;

final class LicenceValidationResponseDto
{
    /**
     * @param list<string> $features
     */
    public function __construct(
        public readonly bool $success,
        public readonly bool $valid,
        public readonly string $plan,
        public readonly array $features,
        public readonly ?string $expiresAt,
        public readonly ?string $trialExpiresAt,
        public readonly ?string $trialStartedAt,
        public readonly ?string $reason,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $success = (bool)($payload['success'] ?? false);
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];

        $features = array_values(array_filter(
            array_map(
                static fn(mixed $value): string => trim((string)$value),
                is_array($payload['features'] ?? null) ? $payload['features'] : []
            ),
            static fn(string $value): bool => $value !== ''
        ));

        return new self(
            success: $success,
            valid: $success && (bool)($payload['valid'] ?? false),
            plan: trim((string)($payload['plan'] ?? '')),
            features: $features,
            expiresAt: isset($payload['expires_at'])
                ? (string)$payload['expires_at']
                : (isset($payload['expiresAt']) ? (string)$payload['expiresAt'] : null),
            trialExpiresAt: isset($payload['trial_expires_at'])
                ? (string)$payload['trial_expires_at']
                : (isset($payload['trialExpiresAt']) ? (string)$payload['trialExpiresAt'] : null),
            trialStartedAt: isset($payload['trial_started_at'])
                ? (string)$payload['trial_started_at']
                : (isset($payload['trialStartedAt']) ? (string)$payload['trialStartedAt'] : null),
            reason: isset($details['reason']) ? (string)$details['reason'] : null,
            errorCode: isset($error['code']) ? (string)$error['code'] : null,
            errorMessage: isset($error['message']) ? (string)$error['message'] : null,
        );
    }
}