<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Settings;

use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;

/**
 * Provider-specific wording of the AI settings tab: slot => label key per provider. OpenAI keeps its 2.x labels;
 * Anthropic has its own keys. Provider-neutral labels stay in the template.
 */
final class AiSettingsCopy
{
    /** @var array<string,array{openai:string,anthropic:string}> */
    public const SLOTS = [
        // The card intro is shown before a provider is chosen, so it names every supported provider.
        'description' => ['openai' => 'settings.ai.description', 'anthropic' => 'settings.ai.description'],
        'summaryAria' => ['openai' => 'settings.ai.summaryAria', 'anthropic' => 'settings.ai.anthropic.summaryAria'],
        'privacy' => ['openai' => 'settings.ai.privacy', 'anthropic' => 'settings.ai.anthropic.privacy'],
        'saved' => ['openai' => 'settings.ai.saved', 'anthropic' => 'settings.ai.anthropic.saved'],
        'projectKey' => ['openai' => 'settings.ai.projectKey', 'anthropic' => 'settings.ai.anthropic.projectKey'],
        'keyHelp' => ['openai' => 'settings.ai.keyHelp', 'anthropic' => 'settings.ai.anthropic.keyHelp'],
        'savingKey' => ['openai' => 'settings.ai.savingKey', 'anthropic' => 'settings.ai.anthropic.savingKey'],
        'selectModel' => ['openai' => 'settings.ai.selectModel', 'anthropic' => 'settings.ai.anthropic.selectModel'],
        'modelHelp' => ['openai' => 'settings.ai.modelHelp', 'anthropic' => 'settings.ai.anthropic.modelHelp'],
        'unsupportedModels' => ['openai' => 'settings.ai.unsupportedModels', 'anthropic' => 'settings.ai.anthropic.unsupportedModels'],
        'modelsRefreshed' => ['openai' => 'settings.ai.modelsRefreshed', 'anthropic' => 'settings.ai.anthropic.modelsRefreshed'],
        'refreshingModels' => ['openai' => 'settings.ai.refreshingModels', 'anthropic' => 'settings.ai.anthropic.refreshingModels'],
        'apiKeyRejected' => ['openai' => 'settings.ai.apiKeyRejected', 'anthropic' => 'settings.ai.anthropic.apiKeyRejected'],
        'insufficientQuota' => ['openai' => 'settings.ai.insufficientQuota', 'anthropic' => 'settings.ai.anthropic.billingError'],
        'billingError' => ['openai' => 'settings.ai.insufficientQuota', 'anthropic' => 'settings.ai.anthropic.billingError'],
        'modelUnavailable' => ['openai' => 'settings.ai.modelUnavailable', 'anthropic' => 'settings.ai.anthropic.modelUnavailable'],
        'modelNotPermitted' => ['openai' => 'settings.ai.modelNotPermitted', 'anthropic' => 'settings.ai.anthropic.modelNotPermitted'],
        'modelNotSelected' => ['openai' => 'settings.ai.modelNotSelected', 'anthropic' => 'settings.ai.anthropic.modelNotSelected'],
        'invalidModelSelection' => ['openai' => 'settings.ai.invalidModelSelection', 'anthropic' => 'settings.ai.anthropic.invalidModelSelection'],
        'modelsPermissionDenied' => ['openai' => 'settings.ai.modelsPermissionDenied', 'anthropic' => 'settings.ai.anthropic.modelsPermissionDenied'],
        'modelsRateLimited' => ['openai' => 'settings.ai.modelsRateLimited', 'anthropic' => 'settings.ai.anthropic.modelsRateLimited'],
        'modelsInvalidResponse' => ['openai' => 'settings.ai.modelsInvalidResponse', 'anthropic' => 'settings.ai.anthropic.modelsInvalidResponse'],
        'modelsRequestFailed' => ['openai' => 'settings.ai.modelsRequestFailed', 'anthropic' => 'settings.ai.anthropic.modelsRequestFailed'],
        'noSupportedModels' => ['openai' => 'settings.ai.noSupportedModels', 'anthropic' => 'settings.ai.anthropic.noSupportedModels'],
        'connectionRateLimited' => ['openai' => 'settings.ai.connectionRateLimited', 'anthropic' => 'settings.ai.anthropic.connectionRateLimited'],
        'invalidProviderRequest' => ['openai' => 'settings.ai.invalidProviderRequest', 'anthropic' => 'settings.ai.anthropic.invalidProviderRequest'],
        'transportFailure' => ['openai' => 'settings.ai.transportFailure', 'anthropic' => 'settings.ai.anthropic.transportFailure'],
        'serviceFailure' => ['openai' => 'settings.ai.openAiServiceFailure', 'anthropic' => 'settings.ai.anthropic.serviceFailure'],
    ];

    /** @return array<string,string> slot => label key */
    public static function keysFor(string $provider): array
    {
        $provider = AiProviderCatalog::normalize($provider) ?: AiProviderCatalog::OPENAI;
        $keys = [];
        foreach (self::SLOTS as $slot => $providerKeys) {
            $keys[$slot] = $providerKeys[$provider];
        }

        return $keys;
    }

    public static function keyPlaceholder(string $provider): string
    {
        return $provider === AiProviderCatalog::ANTHROPIC ? 'sk-ant-…' : 'sk-proj-…';
    }
}
