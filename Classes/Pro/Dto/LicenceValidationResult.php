<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Dto;

use Priebera\A11yQualityGate\Pro\Enum\FeatureFlag;

final class LicenceValidationResult
{
    /**
     * @param list<string> $features
     */
    public function __construct(
        public readonly bool $valid,
        public readonly string $plan = '',
        public readonly array $features = [],
        public readonly ?string $expiresAt = null,
        public readonly ?string $reason = null,
        public readonly bool $isTrial = false,
        public readonly ?string $trialExpiresAt = null,
        public readonly ?string $trialStartedAt = null,
    ) {
    }

    /**
     * @param list<string> $features
     */
    public static function invalid(
        string $reason,
        string $plan = '',
        array $features = [],
        ?string $expiresAt = null,
        bool $isTrial = false,
        ?string $trialExpiresAt = null,
        ?string $trialStartedAt = null,
    ): self {
        return new self(
            valid: false,
            plan: $plan,
            features: $features,
            expiresAt: $expiresAt,
            reason: $reason,
            isTrial: $isTrial,
            trialExpiresAt: $trialExpiresAt,
            trialStartedAt: $trialStartedAt,
        );
    }

    public static function fromResponseDto(LicenceValidationResponseDto $dto): self
    {
        $isTrial = $dto->plan === 'trial'
            || str_starts_with((string)($dto->reason ?? ''), 'trial_');

        if (!$dto->success || !$dto->valid) {
            return self::invalid(
                reason: $dto->reason ?? 'invalid',
                plan: $dto->plan,
                features: $dto->features,
                expiresAt: $dto->expiresAt,
                isTrial: $isTrial,
                trialExpiresAt: $dto->trialExpiresAt,
                trialStartedAt: $dto->trialStartedAt,
            );
        }

        return new self(
            valid: true,
            plan: $dto->plan,
            features: $dto->features,
            expiresAt: $dto->expiresAt,
            reason: null,
            isTrial: $isTrial,
            trialExpiresAt: $dto->trialExpiresAt,
            trialStartedAt: $dto->trialStartedAt,
        );
    }

    public function hasFeature(FeatureFlag $featureFlag): bool
    {
        return in_array($featureFlag->value, $this->features, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'plan' => $this->plan,
            'features' => $this->features,
            'expiresAt' => $this->expiresAt,
            'reason' => $this->reason,
            'isTrial' => $this->isTrial,
            'trialExpiresAt' => $this->trialExpiresAt,
            'trialStartedAt' => $this->trialStartedAt,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromCacheArray(array $payload): self
    {
        $features = array_values(array_filter(
            array_map(
                static fn(mixed $value): string => trim((string)$value),
                is_array($payload['features'] ?? null) ? $payload['features'] : []
            ),
            static fn(string $value): bool => $value !== ''
        ));

        return new self(
            valid: (bool)($payload['valid'] ?? false),
            plan: trim((string)($payload['plan'] ?? '')),
            features: $features,
            expiresAt: isset($payload['expiresAt']) ? (string)$payload['expiresAt'] : null,
            reason: isset($payload['reason']) ? (string)$payload['reason'] : null,
            isTrial: (bool)($payload['isTrial'] ?? false),
            trialExpiresAt: isset($payload['trialExpiresAt']) ? (string)$payload['trialExpiresAt'] : null,
            trialStartedAt: isset($payload['trialStartedAt']) ? (string)$payload['trialStartedAt'] : null,
        );
    }
}