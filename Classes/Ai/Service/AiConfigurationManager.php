<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationManagerInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetAlertRecipient;
use Priebera\A11yQualityGate\Ai\Usage\AiMoney;
use Priebera\A11yQualityGate\Contract\SecretEncryptionServiceInterface;
use Priebera\A11yQualityGate\Contract\SiteResolutionServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiSiteSettingRepositoryInterface;

final class AiConfigurationManager implements AiConfigurationManagerInterface
{
    public function __construct(
        private readonly AiConfigurationRepositoryInterface $repository,
        private readonly SecretEncryptionServiceInterface $encryptionService,
        private readonly SiteResolutionServiceInterface $siteResolutionService,
        private readonly AiConfigurationResolverInterface $configurationResolver,
        private readonly AiModelCompatibilityRegistry $registry,
        private readonly AiModelCacheCodec $cacheCodec,
        private readonly ?AiSiteSettingsService $siteSettingsService = null,
        private readonly ?AiSiteSettingRepositoryInterface $siteSettingRepository = null,
    ) {}

    public function save(string $siteIdentifier, #[\SensitiveParameter] string $apiKey, ?string $provider = null): void
    {
        $apiKey = trim($apiKey);
        if ($siteIdentifier === '' || $apiKey === '') {
            throw new \InvalidArgumentException('Site and API key are required.');
        }
        $this->assertSiteExists($siteIdentifier);

        $activeProvider = $this->activeProvider($siteIdentifier);
        if ($provider !== null && AiProviderCatalog::normalize($provider) !== $activeProvider) {
            throw new AiConfigurationException('The site uses another AI provider now. Reload the settings.', 1771002010);
        }
        // An Anthropic key is never stored for (and sent to) OpenAI. Otherwise OpenAI keeps its 2.x behaviour: any
        // key is stored and the connection test reports a rejection. Anthropic keys get a format check that catches
        // a key pasted into the wrong provider's field.
        if ($activeProvider === AiProviderCatalog::OPENAI && str_starts_with($apiKey, 'sk-ant-')) {
            throw new AiConfigurationException('This is an Anthropic key; the site uses OpenAI.', 1771002012);
        }
        if ($activeProvider === AiProviderCatalog::ANTHROPIC && !AiProviderCatalog::isPlausibleKey($activeProvider, $apiKey)) {
            throw new AiConfigurationException('This is not an Anthropic API key.', 1771002011);
        }

        $this->repository->saveKey(
            $siteIdentifier,
            $this->encryptionService->encrypt($apiKey),
            $this->mask($apiKey),
            true,
            $activeProvider,
        );
    }

    public function selectModel(string $siteIdentifier, string $modelId): void
    {
        $this->assertSiteExists($siteIdentifier);
        $modelId = trim($modelId);

        $credentials = $this->configurationResolver->resolveCredentials($siteIdentifier);
        $provider = $credentials->provider;
        $this->registry->require($modelId, $provider);
        $row = $this->repository->findBySiteIdentifier($siteIdentifier, $provider);
        $cache = $this->cacheCodec->decode((string)($row['discovered_models_cache'] ?? ''), $provider);
        $availableIds = array_column($cache['supported'], 'id');
        $cacheMatchesKey = $cache['valid']
            && hash_equals($credentials->keyFingerprint, $cache['keyFingerprint']);
        if (!$cacheMatchesKey || !in_array($modelId, $availableIds, true)) {
            throw new \InvalidArgumentException('The selected model is not available to this provider account.');
        }

        $this->repository->selectModel($siteIdentifier, $modelId, $provider);
    }


    public function setLinkTextSuggestionsEnabled(string $siteIdentifier, bool $enabled): void
    {
        $this->assertSiteExists($siteIdentifier);
        $this->repository->setLinkTextSuggestionsEnabled($siteIdentifier, $enabled);
    }

    public function selectProvider(string $siteIdentifier, string $provider, int $backendUserUid): bool
    {
        $this->assertSiteExists($siteIdentifier);
        $provider = AiProviderCatalog::normalize($provider);
        if ($provider === '' || $this->siteSettingRepository === null) {
            throw new \InvalidArgumentException('Unsupported AI provider.');
        }

        $settings = $this->siteSettingsService?->get($siteIdentifier);
        $changed = $settings === null || !$settings->providerChosen || $settings->provider !== $provider;
        if (!$changed) {
            return false;
        }

        // One transaction in effect: provider saved (which clears the processing acknowledgement), verification of
        // the new provider cleared. AI pauses until a model passes the test and the acknowledgement is confirmed.
        $this->siteSettingRepository->saveProvider($siteIdentifier, $provider, $backendUserUid);
        $this->repository->resetVerification($siteIdentifier, $provider);

        return true;
    }

    public function savePrivacy(string $siteIdentifier, bool $allowImageInput, bool $allowPageTitle): void
    {
        $this->assertSiteExists($siteIdentifier);
        if ($this->siteSettingRepository === null) {
            throw new \InvalidArgumentException('AI site settings are unavailable.');
        }

        $this->siteSettingRepository->savePrivacy($siteIdentifier, $allowImageInput, $allowPageTitle);
    }

    public function saveBudget(string $siteIdentifier, string $budget, string $warningPercent, string $hardStop, int $backendUserUid, ?string $alertEmail = null): void
    {
        $this->assertSiteExists($siteIdentifier);
        if ($this->siteSettingRepository === null) {
            throw new \InvalidArgumentException('invalid_configuration');
        }
        // Validated before anything is stored, so an invalid address never leaves a half-saved form behind.
        $alertEmail = $alertEmail !== null ? AiBudgetAlertRecipient::normalizeInput($alertEmail) : null;
        if (trim($budget) === '') {
            $this->siteSettingRepository->saveBudget($siteIdentifier, 0, 0, false, $backendUserUid);
            if ($alertEmail !== null) {
                $this->siteSettingRepository->saveBudgetAlertEmail($siteIdentifier, $alertEmail);
            }
            return;
        }

        $budgetMicro = AiMoney::parseUsd($budget, 2);
        if ($budgetMicro === null || $budgetMicro < AiMoney::MIN_BUDGET_MICRO || $budgetMicro > AiMoney::MAX_BUDGET_MICRO) {
            throw new \InvalidArgumentException('invalid_budget');
        }
        $warningPercent = trim($warningPercent);
        if ($warningPercent !== '' && (preg_match('/^\d{1,3}$/', $warningPercent) !== 1 || (int)$warningPercent < 1 || (int)$warningPercent > 100)) {
            throw new \InvalidArgumentException('invalid_warning_percent');
        }
        // Open owner decision 01#4: no default. The administrator chooses hard stop or warning only every time.
        if (!in_array($hardStop, ['0', '1'], true)) {
            throw new \InvalidArgumentException('hard_stop_required');
        }
        // Prices are automatic (AiPriceTable): a budget never waits for an administrator to enter one. A model without a
        // complete price is refused under a hard stop at request time (budget_unenforceable), never let through.
        $this->siteSettingRepository->saveBudget($siteIdentifier, $budgetMicro, (int)$warningPercent, $hardStop === '1', $backendUserUid);
        if ($alertEmail !== null) {
            $this->siteSettingRepository->saveBudgetAlertEmail($siteIdentifier, $alertEmail);
        }
    }

    public function acknowledgeProcessing(string $siteIdentifier, string $provider, int $backendUserUid): void
    {
        $this->assertSiteExists($siteIdentifier);
        if ($this->siteSettingRepository === null) {
            throw new \InvalidArgumentException('invalid_configuration');
        }
        $activeProvider = $this->activeProvider($siteIdentifier);
        if (AiProviderCatalog::normalize($provider) !== $activeProvider) {
            throw new AiConfigurationException('The site uses another AI provider now. Reload the settings.', 1771002010);
        }

        $this->siteSettingRepository->saveAcknowledgement($siteIdentifier, $activeProvider, $backendUserUid);
    }

    private function activeProvider(string $siteIdentifier): string
    {
        return $this->siteSettingsService?->activeProvider($siteIdentifier) ?? AiProviderCatalog::LEGACY_DEFAULT;
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
