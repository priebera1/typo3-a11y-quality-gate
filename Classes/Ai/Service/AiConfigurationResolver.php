<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderCredentials;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Contract\SecretEncryptionServiceInterface;
use Priebera\A11yQualityGate\Contract\SiteResolutionServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;

final class AiConfigurationResolver implements AiConfigurationResolverInterface
{
    public function __construct(
        private readonly AiConfigurationRepositoryInterface $repository,
        private readonly SecretEncryptionServiceInterface $encryptionService,
        private readonly SiteResolutionServiceInterface $siteResolutionService,
        private readonly AiKeyFingerprintService $fingerprintService,
        private readonly AiModelCompatibilityRegistry $registry,
        private readonly AiModelCacheCodec $cacheCodec,
    ) {}

    public function resolveCredentials(string $siteIdentifier): AiProviderCredentials
    {
        $this->assertSiteExists($siteIdentifier);

        $environmentKey = trim((string)getenv('AQG_OPENAI_API_KEY'));
        if ($environmentKey !== '') {
            return new AiProviderCredentials(
                'openai',
                $environmentKey,
                'environment',
                $this->fingerprintService->fingerprint($environmentKey),
            );
        }

        $row = $this->repository->findBySiteIdentifier($siteIdentifier);
        if (!is_array($row) || (int)($row['enabled'] ?? 0) !== 1) {
            throw new AiConfigurationException('OpenAI is not configured for this site.', 1771002001);
        }

        $apiKey = $this->encryptionService->decrypt((string)($row['encrypted_api_key'] ?? ''));
        if ($apiKey === '') {
            throw new AiConfigurationException('The configured OpenAI key could not be decrypted.', 1771002002);
        }

        return new AiProviderCredentials(
            'openai',
            $apiKey,
            'site',
            $this->fingerprintService->fingerprint($apiKey),
        );
    }

    public function resolve(string $siteIdentifier): AiProviderConfiguration
    {
        $credentials = $this->resolveCredentials($siteIdentifier);
        $row = $this->repository->findBySiteIdentifier($siteIdentifier) ?? [];
        $modelId = trim((string)($row['selected_model_id'] ?? ''));
        if ($modelId === '') {
            throw new AiConfigurationException('Select an OpenAI model before using AI suggestions.', 1771002003);
        }
        $this->registry->require($modelId);

        $cache = $this->cacheCodec->decode((string)($row['discovered_models_cache'] ?? ''));
        $availableIds = array_column($cache['supported'], 'id');
        $cacheMatchesKey = $cache['valid']
            && hash_equals($credentials->keyFingerprint, $cache['keyFingerprint']);
        if (!$cacheMatchesKey || !in_array($modelId, $availableIds, true)) {
            throw new AiConfigurationException(
                'Refresh the OpenAI model list and select an available AQG-compatible model.',
                1771002004,
            );
        }

        return new AiProviderConfiguration(
            $credentials->provider,
            $credentials->apiKey(),
            $modelId,
            $credentials->source,
            $credentials->keyFingerprint,
        );
    }

    /** @return array<string,mixed> */
    public function status(string $siteIdentifier): array
    {
        if ($siteIdentifier === '' || $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier) === null) {
            return $this->emptyStatus();
        }

        $row = $this->repository->findBySiteIdentifier($siteIdentifier) ?? [];
        $environmentKey = trim((string)getenv('AQG_OPENAI_API_KEY'));
        $environmentOverride = $environmentKey !== '';
        $siteConfigured = (int)($row['enabled'] ?? 0) === 1
            && trim((string)($row['encrypted_api_key'] ?? '')) !== '';
        $configured = $environmentOverride || $siteConfigured;
        $source = $environmentOverride ? 'environment' : ($siteConfigured ? 'site' : 'none');

        $cache = $this->cacheCodec->decode((string)($row['discovered_models_cache'] ?? ''));
        $selectedModelId = trim((string)($row['selected_model_id'] ?? ''));
        $selectedProfile = $this->registry->find($selectedModelId);

        $currentFingerprint = '';
        if ($configured) {
            try {
                if ($environmentOverride) {
                    $currentFingerprint = $this->fingerprintService->fingerprint($environmentKey);
                } else {
                    $decryptedKey = $this->encryptionService->decrypt((string)($row['encrypted_api_key'] ?? ''));
                    $currentFingerprint = $this->fingerprintService->fingerprint($decryptedKey);
                }
            } catch (\Throwable) {
                $currentFingerprint = '';
            }
        }

        $cacheMatchesKey = $configured
            && $cache['valid']
            && $currentFingerprint !== ''
            && hash_equals($currentFingerprint, $cache['keyFingerprint']);
        $availableIds = $cacheMatchesKey ? array_column($cache['supported'], 'id') : [];
        $selectedAvailable = $selectedModelId !== ''
            && $selectedProfile !== null
            && in_array($selectedModelId, $availableIds, true);

        $storedLastVerifiedAt = (int)($row['last_verified_at'] ?? 0);
        $verificationMatches = $configured
            && $selectedAvailable
            && $storedLastVerifiedAt > 0
            && hash_equals($currentFingerprint, (string)($row['verified_key_fingerprint'] ?? ''))
            && hash_equals($selectedModelId, (string)($row['verified_model_id'] ?? ''))
            && hash_equals(AiPromptDefinition::AI_PROMPT_VERSION, (string)($row['verified_prompt_version'] ?? ''))
            && hash_equals(AiPromptDefinition::CONNECTION_TEST_VERSION, (string)($row['verified_connection_contract_version'] ?? ''));

        $lastTestErrorCode = trim((string)($row['last_test_error_code'] ?? ''));
        $connectionStatus = 'not_configured';
        if ($configured) {
            $connectionStatus = $verificationMatches
                ? 'connected'
                : ($lastTestErrorCode !== '' ? 'connection_failed' : 'not_verified');
        }

        return [
            'configured' => $configured,
            'source' => $source,
            'keyHint' => $environmentOverride ? '' : (string)($row['key_hint'] ?? ''),
            'model' => $selectedModelId,
            'selectedModelId' => $selectedModelId,
            'selectedModelLabel' => (string)($selectedProfile['label'] ?? ''),
            'selectedModelAvailable' => $selectedAvailable,
            'availableModels' => $cacheMatchesKey ? $cache['supported'] : [],
            'unsupportedModels' => $cacheMatchesKey ? $cache['unsupported'] : [],
            'modelsCacheValid' => $cacheMatchesKey,
            'modelsDiscoveredAt' => (int)($row['discovered_models_at'] ?? 0),
            'lastTestedAt' => (int)($row['last_tested_at'] ?? 0),
            'lastVerifiedAt' => $verificationMatches ? $storedLastVerifiedAt : 0,
            'lastTestErrorCode' => $lastTestErrorCode,
            'connectionStatus' => $connectionStatus,
            'environmentOverride' => $environmentOverride,
            'linkTextSuggestionsEnabled' => (int)($row['link_text_suggestions_enabled'] ?? 0) === 1,
        ];
    }

    private function assertSiteExists(string $siteIdentifier): void
    {
        if ($this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier) === null) {
            throw new AiConfigurationException('The TYPO3 site no longer exists.', 1771002000);
        }
    }

    /** @return array<string,mixed> */
    private function emptyStatus(): array
    {
        return [
            'configured' => false,
            'source' => 'none',
            'keyHint' => '',
            'model' => '',
            'selectedModelId' => '',
            'selectedModelLabel' => '',
            'selectedModelAvailable' => false,
            'availableModels' => [],
            'unsupportedModels' => [],
            'modelsCacheValid' => false,
            'modelsDiscoveredAt' => 0,
            'lastTestedAt' => 0,
            'lastVerifiedAt' => 0,
            'lastTestErrorCode' => '',
            'connectionStatus' => 'not_configured',
            'environmentOverride' => false,
            'linkTextSuggestionsEnabled' => false,
        ];
    }
}
