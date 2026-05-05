<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Dto;

final class AccessTokenResult
{
    /**
     * @param list<string> $features
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly int $expiresIn,
        public readonly int $issuedAt,
        public readonly string $plan,
        public readonly array $features,
    ) {
    }

    public static function fromResponseDto(AccessTokenResponseDto $dto): self
    {
        return new self(
            accessToken: (string)$dto->accessToken,
            expiresIn: $dto->expiresIn,
            issuedAt: time(),
            plan: $dto->plan,
            features: $dto->features,
        );
    }

    public function expiresAt(): int
    {
        return $this->issuedAt + $this->expiresIn;
    }

    public function isExpiringSoon(int $marginSeconds): bool
    {
        return time() >= ($this->expiresAt() - $marginSeconds);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'accessToken' => $this->accessToken,
            'expiresIn' => $this->expiresIn,
            'issuedAt' => $this->issuedAt,
            'plan' => $this->plan,
            'features' => $this->features,
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
            accessToken: trim((string)($payload['accessToken'] ?? '')),
            expiresIn: max(0, (int)($payload['expiresIn'] ?? 0)),
            issuedAt: max(0, (int)($payload['issuedAt'] ?? 0)),
            plan: trim((string)($payload['plan'] ?? '')),
            features: $features,
        );
    }
}