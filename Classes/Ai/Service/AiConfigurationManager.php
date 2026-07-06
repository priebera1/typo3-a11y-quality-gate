<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationManagerInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Contract\SecretEncryptionServiceInterface;
use Priebera\A11yQualityGate\Contract\SiteResolutionServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;

final class AiConfigurationManager implements AiConfigurationManagerInterface
{
    public function __construct(
        private readonly AiConfigurationRepositoryInterface $repository,
        private readonly SecretEncryptionServiceInterface $encryptionService,
        private readonly SiteResolutionServiceInterface $siteResolutionService,
        private readonly AiConfigurationResolverInterface $configurationResolver,
        private readonly AiModelCompatibilityRegistry $registry,
        private readonly AiModelCacheCodec $cacheCodec,
    ) {}

    public function save(string $siteIdentifier, #[\SensitiveParameter] string $apiKey): void
    {
        $apiKey = trim($apiKey);
        if ($siteIdentifier === '' || $apiKey === '') {
            throw new \InvalidArgumentException('Site and OpenAI project key are required.');
        }
        $this->assertSiteExists($siteIdentifier);

        $this->repository->saveKey(
            $siteIdentifier,
            $this->encryptionService->encrypt($apiKey),
            $this->mask($apiKey),
            true,
        );
    }

    public function selectModel(string $siteIdentifier, string $modelId): void
    {
        $this->assertSiteExists($siteIdentifier);
        $modelId = trim($modelId);
        $this->registry->require($modelId);

        $credentials = $this->configurationResolver->resolveCredentials($siteIdentifier);
        $row = $this->repository->findBySiteIdentifier($siteIdentifier);
        $cache = $this->cacheCodec->decode((string)($row['discovered_models_cache'] ?? ''));
        $availableIds = array_column($cache['supported'], 'id');
        $cacheMatchesKey = $cache['valid']
            && hash_equals($credentials->keyFingerprint, $cache['keyFingerprint']);
        if (!$cacheMatchesKey || !in_array($modelId, $availableIds, true)) {
            throw new \InvalidArgumentException('The selected model is not available to this OpenAI project.');
        }

        $this->repository->selectModel($siteIdentifier, $modelId);
    }

    private function assertSiteExists(string $siteIdentifier): void
    {
        if ($siteIdentifier === '' || $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier) === null) {
            throw new \InvalidArgumentException('The selected TYPO3 site does not exist.');
        }
    }

    private function mask(string $key): string
    {
        return strlen($key) <= 8 ? 'configured' : substr($key, 0, 7) . '…' . substr($key, -4);
    }
}
