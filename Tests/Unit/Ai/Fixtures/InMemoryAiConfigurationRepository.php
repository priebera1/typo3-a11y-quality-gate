<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures;

use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;

/**
 * In-memory twin of AiConfigurationRepository: one row per site and provider, with the same reset rules.
 */
final class InMemoryAiConfigurationRepository implements AiConfigurationRepositoryInterface
{
    /** @var array<string,array<string,mixed>> */
    public array $rows = [];

    public bool $linkTextSuggestionsEnabled = false;

    public function findBySiteIdentifier(string $siteIdentifier, string $provider = AiProviderCatalog::OPENAI): ?array
    {
        return $this->rows[$siteIdentifier . '|' . $provider] ?? null;
    }

    public function saveKey(string $siteIdentifier, string $encryptedApiKey, string $keyHint, bool $enabled = true, string $provider = AiProviderCatalog::OPENAI): void
    {
        $existing = $this->findBySiteIdentifier($siteIdentifier, $provider) ?? [];
        $this->rows[$siteIdentifier . '|' . $provider] = [
            'encrypted_api_key' => $encryptedApiKey,
            'key_hint' => $keyHint,
            'enabled' => $enabled ? 1 : 0,
            'selected_model_id' => (string)($existing['selected_model_id'] ?? ''),
            'discovered_models_cache' => '',
            'discovered_models_at' => 0,
            'last_test_error_code' => '',
            'revalidation_required' => (int)($existing['revalidation_required'] ?? 0),
        ] + $this->resetValues();
    }

    public function saveDiscovery(string $siteIdentifier, string $normalizedCacheJson, int $discoveredAt, string $selectedModelId, string $provider = AiProviderCatalog::OPENAI): void
    {
        $row = $this->findBySiteIdentifier($siteIdentifier, $provider) ?? ['enabled' => 0, 'encrypted_api_key' => ''];
        if (($row['selected_model_id'] ?? '') !== $selectedModelId) {
            $row = $this->resetValues() + $row;
        }
        $row['discovered_models_cache'] = $normalizedCacheJson;
        $row['discovered_models_at'] = $discoveredAt;
        $row['selected_model_id'] = $selectedModelId;
        $row['last_test_error_code'] = '';
        $this->rows[$siteIdentifier . '|' . $provider] = $row;
    }

    public function markDiscoveryFailed(string $siteIdentifier, string $safeErrorCode, bool $invalidateSelection, string $provider = AiProviderCatalog::OPENAI): void
    {
        $row = $this->findBySiteIdentifier($siteIdentifier, $provider) ?? [];
        $row['last_test_error_code'] = $safeErrorCode;
        if ($invalidateSelection) {
            $row = ['selected_model_id' => '', 'discovered_models_cache' => '', 'discovered_models_at' => 0] + $this->resetValues() + $row;
        }
        $this->rows[$siteIdentifier . '|' . $provider] = $row;
    }

    public function selectModel(string $siteIdentifier, string $modelId, string $provider = AiProviderCatalog::OPENAI): void
    {
        $row = $this->findBySiteIdentifier($siteIdentifier, $provider) ?? [];
        if (($row['selected_model_id'] ?? '') !== $modelId) {
            $row = ['selected_model_id' => $modelId, 'last_test_error_code' => ''] + $this->resetValues() + $row;
        }
        $this->rows[$siteIdentifier . '|' . $provider] = $row;
    }

    public function setLinkTextSuggestionsEnabled(string $siteIdentifier, bool $enabled): void
    {
        $this->linkTextSuggestionsEnabled = $enabled;
    }

    public function isLinkTextSuggestionsEnabled(string $siteIdentifier): bool
    {
        return $this->linkTextSuggestionsEnabled;
    }

    public function markTested(string $siteIdentifier, bool $verified, string $keyFingerprint, string $modelId, string $promptVersion, string $connectionContractVersion, string $safeErrorCode = '', string $provider = AiProviderCatalog::OPENAI): void
    {
        $row = $this->findBySiteIdentifier($siteIdentifier, $provider) ?? [];
        $row['last_tested_at'] = time();
        if ($verified) {
            $row = [
                'last_verified_at' => time(),
                'verified_key_fingerprint' => $keyFingerprint,
                'verified_model_id' => $modelId,
                'verified_prompt_version' => $promptVersion,
                'verified_connection_contract_version' => $connectionContractVersion,
                'last_test_error_code' => '',
                'revalidation_required' => 0,
            ] + $row;
        } else {
            $row = ['last_test_error_code' => $safeErrorCode] + $this->resetValues() + $row;
        }
        $this->rows[$siteIdentifier . '|' . $provider] = $row;
    }

    public function resetVerification(string $siteIdentifier, string $provider): void
    {
        $row = $this->findBySiteIdentifier($siteIdentifier, $provider);
        if ($row !== null) {
            $this->rows[$siteIdentifier . '|' . $provider] = ['revalidation_required' => 1, 'last_test_error_code' => ''] + $this->resetValues() + $row;
        }
    }

    /** @return array<string,int|string> */
    private function resetValues(): array
    {
        return [
            'verified_key_fingerprint' => '',
            'verified_model_id' => '',
            'verified_prompt_version' => '',
            'verified_connection_contract_version' => '',
            'last_verified_at' => 0,
        ];
    }
}
