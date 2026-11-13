<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiModelCatalogProviderInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiModelDiscoveryProviderInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiModelDiscoveryServiceInterface;
use Priebera\A11yQualityGate\Ai\Exception\AiModelDiscoveryException;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;

final class AiModelDiscoveryService implements AiModelDiscoveryServiceInterface
{
    private const CACHE_TTL = 86400;

    /** @param iterable<AiModelDiscoveryProviderInterface> $providers */
    public function __construct(
        private readonly AiConfigurationResolverInterface $configurationResolver,
        private readonly AiConfigurationRepositoryInterface $repository,
        private readonly AiModelCompatibilityRegistry $registry,
        private readonly AiModelCacheCodec $cacheCodec,
        private readonly iterable $providers,
    ) {}

    /**
     * @return array{supported:list<array{id:string,label:string}>,unsupported:list<string>,selectedModelId:string,discoveredAt:int}
     */
    public function discover(string $siteIdentifier): array
    {
        $credentials = $this->configurationResolver->resolveCredentials($siteIdentifier);
        $provider = $credentials->provider;
        $existingRow = $this->repository->findBySiteIdentifier($siteIdentifier, $provider) ?? [];
        $existingCache = $this->cacheCodec->decode((string)($existingRow['discovered_models_cache'] ?? ''), $provider);
        $existingCacheMatchesKey = $existingCache['valid']
            && hash_equals($credentials->keyFingerprint, $existingCache['keyFingerprint']);

        try {
            $availableModelIds = null;
            $reportedCapabilities = [];
            foreach ($this->providers as $discoveryProvider) {
                if (!$discoveryProvider->supports($provider)) {
                    continue;
                }
                if ($discoveryProvider instanceof AiModelCatalogProviderInterface) {
                    $availableModelIds = [];
                    foreach ($discoveryProvider->listModels($credentials) as $model) {
                        $availableModelIds[] = $model->id;
                        $reportedCapabilities[$model->id] = [
                            'imageInput' => $model->imageInput,
                            'structuredOutputs' => $model->structuredOutputs,
                        ];
                    }
                } else {
                    $availableModelIds = $discoveryProvider->listModelIds($credentials);
                }
                break;
            }
            if (!is_array($availableModelIds)) {
                throw new AiModelDiscoveryException(
                    'models_provider_unavailable',
                    'The configured AI provider cannot discover available models.',
                    1771002710,
                );
            }

            $filtered = $this->registry->filterAvailable($availableModelIds, $provider, $reportedCapabilities);
            $row = $this->repository->findBySiteIdentifier($siteIdentifier, $provider) ?? [];
            $selectedModelId = trim((string)($row['selected_model_id'] ?? ''));
            if ($selectedModelId === '') {
                $selectedModelId = trim((string)($row['model'] ?? ''));
            }

            $supportedIds = array_column($filtered['supported'], 'id');
            if (!in_array($selectedModelId, $supportedIds, true)) {
                $selectedModelId = '';
            }
            if ($selectedModelId === '' && count($supportedIds) === 1) {
                $selectedModelId = (string)$supportedIds[0];
            }

            $discoveredAt = time();
            $cacheJson = $this->cacheCodec->encode(
                $filtered['supported'],
                $filtered['unsupported'],
                $credentials->keyFingerprint,
                $provider,
            );
            $this->repository->saveDiscovery(
                $siteIdentifier,
                $cacheJson,
                $discoveredAt,
                $selectedModelId,
                $provider,
            );

            if ($filtered['supported'] === []) {
                $this->repository->markTested(
                    $siteIdentifier,
                    false,
                    $credentials->keyFingerprint,
                    '',
                    AiPromptDefinition::AI_PROMPT_VERSION,
                    AiPromptDefinition::CONNECTION_TEST_VERSION,
                    'no_supported_models',
                    $provider,
                );
                throw new AiModelDiscoveryException(
                    'no_supported_models',
                    'No model available to this provider account is currently supported by AQG.',
                    1771002711,
                );
            }

            return [
                'supported' => $filtered['supported'],
                'unsupported' => $filtered['unsupported'],
                'selectedModelId' => $selectedModelId,
                'discoveredAt' => $discoveredAt,
            ];
        } catch (AiModelDiscoveryException $exception) {
            if ($exception->safeCode !== 'no_supported_models') {
                $this->repository->markDiscoveryFailed(
                    $siteIdentifier,
                    $exception->safeCode,
                    !$existingCacheMatchesKey,
                    $provider,
                );
            }
            throw $exception;
        }
    }

    public function ensureFresh(string $siteIdentifier): void
    {
        $credentials = $this->configurationResolver->resolveCredentials($siteIdentifier);
        $row = $this->repository->findBySiteIdentifier($siteIdentifier, $credentials->provider);
        $cache = $this->cacheCodec->decode((string)($row['discovered_models_cache'] ?? ''), $credentials->provider);
        $discoveredAt = (int)($row['discovered_models_at'] ?? 0);
        $fingerprintMatches = $cache['valid']
            && hash_equals($credentials->keyFingerprint, $cache['keyFingerprint']);
        $fresh = $fingerprintMatches
            && $discoveredAt > 0
            && $discoveredAt >= time() - self::CACHE_TTL;

        if (!$fresh) {
            $this->discover($siteIdentifier);
        }
    }
}
