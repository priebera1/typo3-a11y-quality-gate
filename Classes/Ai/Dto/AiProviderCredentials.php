<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Dto;

final readonly class AiProviderCredentials
{
    private string $apiKey;

    public function __construct(
        public string $provider,
        #[\SensitiveParameter]
        string $apiKey,
        public string $source,
        public string $keyFingerprint,
    ) {
        $this->apiKey = $apiKey;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function __debugInfo(): array
    {
        return [
            'provider' => $this->provider,
            'apiKey' => '[redacted]',
            'source' => $this->source,
            'keyFingerprint' => $this->keyFingerprint !== '' ? '[fingerprint]' : '',
        ];
    }
}
