<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository\Contract;

interface AiConfigurationRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function findBySiteIdentifier(string $siteIdentifier): ?array;

    public function saveKey(
        string $siteIdentifier,
        string $encryptedApiKey,
        string $keyHint,
        bool $enabled = true,
    ): void;

    public function saveDiscovery(
        string $siteIdentifier,
        string $normalizedCacheJson,
        int $discoveredAt,
        string $selectedModelId,
    ): void;

    public function markDiscoveryFailed(string $siteIdentifier, string $safeErrorCode, bool $invalidateSelection): void;

    public function selectModel(string $siteIdentifier, string $modelId): void;

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
    ): void;
}
