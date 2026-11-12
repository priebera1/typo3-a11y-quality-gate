<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderCredentials;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettings;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetService;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetStatus;
use Priebera\A11yQualityGate\Contract\SecretEncryptionServiceInterface;
use Priebera\A11yQualityGate\Contract\SiteResolutionServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;

/**
 * Resolves the configuration of the site's active provider. Each provider has its own row, key, model choice and
 * verification; the inactive provider's row is read only to describe it, never to send a request.
 *
 * resolve() is the server-side gate of AiReadinessPolicy for every non-test provider request: it refuses, with a
 * distinct code, a site whose model is not verified (1771002006), whose processing acknowledgement for the active
 * provider is missing (1771002007) or whose hard-stop budget blocks (1771002008 reached, 1771002009 not
 * enforceable). resolveForConnectionTest() needs neither acknowledgement nor budget: the test sends a fixed prompt
 * and a synthetic image, never site content.
 */
final class AiConfigurationResolver implements AiConfigurationResolverInterface
{
    public const CODE_NOT_VERIFIED = 1771002006;
    public const CODE_ACKNOWLEDGEMENT_MISSING = 1771002007;

    public function __construct(
        private readonly AiConfigurationRepositoryInterface $repository,
        private readonly SecretEncryptionServiceInterface $encryptionService,
        private readonly SiteResolutionServiceInterface $siteResolutionService,
        private readonly AiKeyFingerprintService $fingerprintService,
        private readonly AiModelCompatibilityRegistry $registry,
        private readonly AiModelCacheCodec $cacheCodec,
        private readonly ?AiSiteSettingsService $siteSettingsService = null,
        private readonly ?AiBudgetService $budgetService = null,
    ) {}

    public function resolveCredentials(string $siteIdentifier): AiProviderCredentials
    {
        $this->assertSiteExists($siteIdentifier);
        $provider = $this->activeProvider($siteIdentifier);

        // An installation-wide environment key only applies to its own provider (today: AQG_OPENAI_API_KEY).
        $environmentKey = AiProviderCatalog::environmentKey($provider);
        if ($environmentKey !== '') {
            return new AiProviderCredentials(
                $provider,
                $environmentKey,
                'environment',
                $this->fingerprintService->fingerprint($environmentKey, $provider),
            );
        }

        $row = $this->repository->findBySiteIdentifier($siteIdentifier, $provider);
        if (!is_array($row) || (int)($row['enabled'] ?? 0) !== 1) {
            throw new AiConfigurationException('The AI provider is not configured for this site.', 1771002001);
        }

        $apiKey = $this->encryptionService->decrypt((string)($row['encrypted_api_key'] ?? ''));
        if ($apiKey === '') {
            throw new AiConfigurationException('The configured AI provider key could not be decrypted.', 1771002002);
        }

        return new AiProviderCredentials(
            $provider,
            $apiKey,
            'site',
            $this->fingerprintService->fingerprint($apiKey, $provider),
        );
    }

    public function resolve(string $siteIdentifier): AiProviderConfiguration
    {
        return $this->resolveConfiguration($siteIdentifier, false);
    }

    public function resolveForConnectionTest(string $siteIdentifier): AiProviderConfiguration
    {
        return $this->resolveConfiguration($siteIdentifier, true);
    }

    private function resolveConfiguration(string $siteIdentifier, bool $forConnectionTest): AiProviderConfiguration
    {
        $credentials = $this->resolveCredentials($siteIdentifier);
        $provider = $credentials->provider;
        $row = $this->repository->findBySiteIdentifier($siteIdentifier, $provider) ?? [];
        $modelId = trim((string)($row['selected_model_id'] ?? ''));
        if ($modelId === '') {
            throw new AiConfigurationException('Select an AI model before using AI suggestions.', 1771002003);
        }
        $this->registry->require($modelId, $provider);
        // After a provider switch, the earlier model choice is used again only once a connection test passed.
        if (!$forConnectionTest && (int)($row['revalidation_required'] ?? 0) === 1) {
            throw new AiConfigurationException('Test the connection before AI is used again for this site.', 1771002005);
        }

        $cache = $this->cacheCodec->decode((string)($row['discovered_models_cache'] ?? ''), $provider);
        $availableIds = array_column($cache['supported'], 'id');
        $cacheMatchesKey = $cache['valid']
            && hash_equals($credentials->keyFingerprint, $cache['keyFingerprint']);
        if (!$cacheMatchesKey || !in_array($modelId, $availableIds, true)) {
            throw new AiConfigurationException(
                'Refresh the provider model list and select an available AQG-compatible model.',
                1771002004,
            );
        }

        if (!$forConnectionTest) {
            $this->assertReadyForEditors($siteIdentifier, $provider, $modelId);
        }

        return new AiProviderConfiguration(
            $credentials->provider,
            $credentials->apiKey(),
            $modelId,
            $credentials->source,
            $credentials->keyFingerprint,
        );
    }

    /**
     * The readiness items after the key and model checks of resolveConfiguration(): verified model, acknowledgement,
     * budget (AiReadinessPolicy; the licence is checked by the caller).
     */
    private function assertReadyForEditors(string $siteIdentifier, string $provider, string $modelId): void
    {
        $settings = $this->siteSettings($siteIdentifier);
        $providerStatus = $this->providerStatus($siteIdentifier, $provider);
        try {
            $budget = $this->budgetStatus($siteIdentifier, $provider, $modelId, $settings->hasBudget() && $settings->budgetHardStop);
        } catch (\Throwable) {
            throw new AiConfigurationException('The monthly AI budget could not be checked.', AiBudgetService::CODE_BUDGET_UNENFORCEABLE);
        }
        $readiness = AiReadinessPolicy::evaluate(
            true,
            $settings->providerChosen,
            (bool)$providerStatus['configured'],
            (bool)$providerStatus['verificationMatches'] && !(bool)$providerStatus['revalidationRequired'],
            $settings->isAcknowledged(),
            $budget,
        );

        match ($readiness->firstMissing()) {
            null => null,
            AiReadinessPolicy::ITEM_PROVIDER, AiReadinessPolicy::ITEM_KEY => throw new AiConfigurationException('The AI provider is not configured for this site.', 1771002001),
            AiReadinessPolicy::ITEM_MODEL => throw new AiConfigurationException('Test the connection of the selected model before AI is used for this site.', self::CODE_NOT_VERIFIED),
            AiReadinessPolicy::ITEM_ACKNOWLEDGEMENT => throw new AiConfigurationException('An administrator must confirm the processing basis for this provider.', self::CODE_ACKNOWLEDGEMENT_MISSING),
            default => throw new AiConfigurationException(
                'The monthly AI budget blocks AI for this site.',
                $budget->blockCode() === AiBudgetStatus::BLOCK_REACHED ? AiBudgetService::CODE_BUDGET_REACHED : AiBudgetService::CODE_BUDGET_UNENFORCEABLE,
            ),
        };
    }

    /** @param bool $strict rethrow a failure instead of reporting "no budget" (enforcement must fail closed) */
    private function budgetStatus(string $siteIdentifier, string $provider, string $modelId, bool $strict = false): AiBudgetStatus
    {
        if ($this->budgetService === null) {
            return AiBudgetStatus::none();
        }
        try {
            return $this->budgetService->status($siteIdentifier, $provider, $modelId);
        } catch (\Throwable $exception) {
            if ($strict) {
                throw $exception;
            }

            return AiBudgetStatus::none();
        }
    }

    /** @return array<string,mixed> */
    public function status(string $siteIdentifier): array
    {
        if ($siteIdentifier === '' || $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier) === null) {
            return $this->emptyStatus();
        }

        $settings = $this->siteSettings($siteIdentifier);
        $provider = $settings->provider;
        $providerStatus = $this->providerStatus($siteIdentifier, $provider);
        $providers = [];
        foreach (AiProviderCatalog::ids() as $candidate) {
            $candidateStatus = $candidate === $provider ? $providerStatus : $this->providerStatus($siteIdentifier, $candidate);
            $providers[] = [
                'id' => $candidate,
                'label' => AiProviderCatalog::label($candidate),
                'active' => $candidate === $provider && $settings->providerChosen,
                'configured' => $candidateStatus['configured'],
                'source' => $candidateStatus['source'],
                'keyHint' => $candidateStatus['keyHint'],
                'selectedModelId' => $candidateStatus['selectedModelId'],
                'selectedModelLabel' => $candidateStatus['selectedModelLabel'],
            ];
        }

        $budget = $this->budgetStatus($siteIdentifier, $provider, (string)$providerStatus['selectedModelId']);
        $readiness = AiReadinessPolicy::evaluate(
            true,
            $settings->providerChosen,
            (bool)$providerStatus['configured'],
            (bool)$providerStatus['verificationMatches'] && !(bool)$providerStatus['revalidationRequired'],
            $settings->isAcknowledged(),
            $budget,
        );

        return $providerStatus + [
            'readiness' => $readiness->items,
            'acknowledgement' => [
                'confirmed' => $settings->isAcknowledged(),
                'provider' => $settings->isAcknowledged() ? $settings->acknowledgedProvider : '',
                'by' => $settings->isAcknowledged() ? $settings->acknowledgedBy : 0,
                'at' => $settings->isAcknowledged() ? $settings->acknowledgedAt : 0,
            ],
            'budget' => [
                'state' => $budget->state,
                'budgetMicroUsd' => $budget->budgetMicroUsd,
                'warningPercent' => $budget->warningPercent,
                'hardStop' => $budget->hardStop,
                'spentMicroUsd' => $budget->spentMicroUsd,
                'reservedMicroUsd' => $budget->reservedMicroUsd,
                'percent' => $budget->percent(),
                'priceKnown' => $budget->priceKnown,
                'blockCode' => $budget->blockCode(),
                'periodStart' => $budget->periodStart,
                'periodEnd' => $budget->periodEnd,
            ],
            'provider' => $provider,
            'providerLabel' => AiProviderCatalog::label($provider),
            'providerChosen' => $settings->providerChosen,
            'providerChangedAt' => $settings->providerChangedAt,
            'providers' => $providers,
            'allowImageInput' => $settings->allowImageInput,
            'allowPageTitle' => $settings->allowPageTitle,
            'linkTextSuggestionsEnabled' => $this->repository->isLinkTextSuggestionsEnabled($siteIdentifier),
        ];
    }

    /** @return array<string,mixed> */
    private function providerStatus(string $siteIdentifier, string $provider): array
    {
        $row = $this->repository->findBySiteIdentifier($siteIdentifier, $provider) ?? [];
        $environmentKey = AiProviderCatalog::environmentKey($provider);
        $environmentOverride = $environmentKey !== '';
        $siteConfigured = (int)($row['enabled'] ?? 0) === 1
            && trim((string)($row['encrypted_api_key'] ?? '')) !== '';
        $configured = $environmentOverride || $siteConfigured;
        $source = $environmentOverride ? 'environment' : ($siteConfigured ? 'site' : 'none');

        $cache = $this->cacheCodec->decode((string)($row['discovered_models_cache'] ?? ''), $provider);
        $selectedModelId = trim((string)($row['selected_model_id'] ?? ''));
        $selectedProfile = $this->registry->find($selectedModelId, $provider);

        $currentFingerprint = '';
        if ($configured) {
            try {
                if ($environmentOverride) {
                    $currentFingerprint = $this->fingerprintService->fingerprint($environmentKey, $provider);
                } else {
                    $decryptedKey = $this->encryptionService->decrypt((string)($row['encrypted_api_key'] ?? ''));
                    $currentFingerprint = $this->fingerprintService->fingerprint($decryptedKey, $provider);
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
            'verificationMatches' => $verificationMatches,
            'environmentOverride' => $environmentOverride,
            'environmentVariable' => AiProviderCatalog::environmentVariable($provider),
            // Only a model chosen before the switch needs a new test; without one there is nothing to revalidate.
            'revalidationRequired' => $configured && $selectedModelId !== '' && (int)($row['revalidation_required'] ?? 0) === 1,
        ];
    }

    private function activeProvider(string $siteIdentifier): string
    {
        return $this->siteSettings($siteIdentifier)->provider;
    }

    private function siteSettings(string $siteIdentifier): AiSiteSettings
    {
        return $this->siteSettingsService?->get($siteIdentifier)
            ?? new AiSiteSettings(AiProviderCatalog::LEGACY_DEFAULT, true, true, true);
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
            'verificationMatches' => false,
            'readiness' => [],
            'acknowledgement' => ['confirmed' => false, 'provider' => '', 'by' => 0, 'at' => 0],
            'budget' => [],
            'environmentOverride' => false,
            'environmentVariable' => '',
            'revalidationRequired' => false,
            'linkTextSuggestionsEnabled' => false,
            'provider' => '',
            'providerLabel' => '',
            'providerChosen' => false,
            'providerChangedAt' => 0,
            'providers' => [],
            'allowImageInput' => true,
            'allowPageTitle' => true,
        ];
    }
}
