<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;

final class AiSettingsUiStateBuilder
{
    private const CONNECTION_STATUSES = [
        'not_configured',
        'not_verified',
        'connected',
        'connection_failed',
    ];

    public function __construct(
        private readonly AiConfigurationResolverInterface $configurationResolver,
    ) {}

    /** @return array<string,mixed> */
    public function build(string $siteIdentifier): array
    {
        $status = $this->configurationResolver->status($siteIdentifier);
        $configured = (bool)($status['configured'] ?? false);
        $selectedModelAvailable = (bool)($status['selectedModelAvailable'] ?? false);
        $connectionStatus = trim((string)($status['connectionStatus'] ?? 'not_configured'));
        if (!in_array($connectionStatus, self::CONNECTION_STATUSES, true)) {
            $connectionStatus = $configured ? 'not_verified' : 'not_configured';
        }

        $state = $status;
        $state['configured'] = $configured;
        $state['modelSelected'] = $selectedModelAvailable;
        $state['selectedModelAvailable'] = $selectedModelAvailable;
        $state['status'] = $connectionStatus;
        $state['errorCode'] = trim((string)($status['lastTestErrorCode'] ?? ''));
        $state['lastTestedAt'] = max(0, (int)($status['lastTestedAt'] ?? 0));
        $state['lastVerifiedAt'] = max(0, (int)($status['lastVerifiedAt'] ?? 0));
        $state['availableModels'] = $this->normalizeAvailableModels($status['availableModels'] ?? []);
        $state['unsupportedModels'] = $this->normalizeUnsupportedModels($status['unsupportedModels'] ?? []);
        $state['actions'] = [
            'refreshModelsEnabled' => $configured,
            'testConnectionEnabled' => $configured
                && $selectedModelAvailable
                && $connectionStatus !== 'connection_failed',
        ];

        return $state;
    }

    /** @return list<array{id:string,label:string}> */
    private function normalizeAvailableModels(mixed $models): array
    {
        if (!is_array($models)) {
            return [];
        }

        $normalized = [];
        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }
            $id = trim((string)($model['id'] ?? ''));
            $label = trim((string)($model['label'] ?? ''));
            if ($id === '' || $label === '') {
                continue;
            }
            $normalized[$id] = ['id' => $id, 'label' => $label];
        }

        return array_values($normalized);
    }

    /** @return list<string> */
    private function normalizeUnsupportedModels(mixed $models): array
    {
        if (!is_array($models)) {
            return [];
        }

        $normalized = [];
        foreach ($models as $modelId) {
            $modelId = trim((string)$modelId);
            if ($modelId !== '') {
                $normalized[$modelId] = $modelId;
            }
        }

        return array_values($normalized);
    }
}
