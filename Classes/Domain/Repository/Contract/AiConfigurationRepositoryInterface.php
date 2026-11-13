<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository\Contract;

use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;

/**
 * Per-site, per-provider AI configuration. The provider argument defaults to OpenAI, the only provider before 3.0.
 */
interface AiConfigurationRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function findBySiteIdentifier(string $siteIdentifier, string $provider = AiProviderCatalog::OPENAI): ?array;

    public function saveKey(
        string $siteIdentifier,
        string $encryptedApiKey,
        string $keyHint,
        bool $enabled = true,
        string $provider = AiProviderCatalog::OPENAI,
    ): void;

    public function saveDiscovery(
        string $siteIdentifier,
        string $normalizedCacheJson,
        int $discoveredAt,
        string $selectedModelId,
        string $provider = AiProviderCatalog::OPENAI,
    ): void;

    public function markDiscoveryFailed(
        string $siteIdentifier,
        string $safeErrorCode,
        bool $invalidateSelection,
        string $provider = AiProviderCatalog::OPENAI,
    ): void;

    public function selectModel(string $siteIdentifier, string $modelId, string $provider = AiProviderCatalog::OPENAI): void;

    public function setLinkTextSuggestionsEnabled(string $siteIdentifier, bool $enabled): void;

    public function isLinkTextSuggestionsEnabled(string $siteIdentifier): bool;

    public function markTested(
        string $siteIdentifier,
        bool $verified,
        string $keyFingerprint,
        string $modelId,
        string $promptVersion,
        string $connectionContractVersion,
        string $safeErrorCode = '',
        string $provider = AiProviderCatalog::OPENAI,
    ): void;

    public function resetVerification(string $siteIdentifier, string $provider): void;
}
