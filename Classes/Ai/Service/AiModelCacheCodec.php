<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use GuzzleHttp\Utils;

final class AiModelCacheCodec
{
    /**
     * @param list<array{id:string,label:string}> $supported
     * @param list<string> $unsupported
     */
    public function encode(array $supported, array $unsupported, string $keyFingerprint): string
    {
        return Utils::jsonEncode([
            'registry_version' => AiModelCompatibilityRegistry::VERSION,
            'key_fingerprint' => $this->normalizeFingerprint($keyFingerprint),
            'supported' => array_values($supported),
            'unsupported' => array_values($unsupported),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{
     *     valid:bool,
     *     keyFingerprint:string,
     *     supported:list<array{id:string,label:string}>,
     *     unsupported:list<string>
     * }
     */
    public function decode(string $cacheJson): array
    {
        $cacheJson = trim($cacheJson);
        if ($cacheJson === '') {
            return $this->empty(false);
        }

        try {
            $decoded = Utils::jsonDecode($cacheJson, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->empty(false);
        }

        $keyFingerprint = $this->normalizeFingerprint((string)($decoded['key_fingerprint'] ?? ''));
        if (!is_array($decoded)
            || ($decoded['registry_version'] ?? null) !== AiModelCompatibilityRegistry::VERSION
            || $keyFingerprint === ''
            || !is_array($decoded['supported'] ?? null)
            || !is_array($decoded['unsupported'] ?? null)) {
            return $this->empty(false);
        }

        $supported = [];
        $seen = [];
        foreach ($decoded['supported'] as $model) {
            if (!is_array($model)) {
                return $this->empty(false);
            }
            $id = trim((string)($model['id'] ?? ''));
            $label = trim((string)($model['label'] ?? ''));
            if (!$this->isSafeModelId($id) || $label === '' || isset($seen[$id])) {
                return $this->empty(false);
            }
            $seen[$id] = true;
            $supported[] = ['id' => $id, 'label' => substr($label, 0, 160)];
        }

        $unsupported = [];
        foreach ($decoded['unsupported'] as $modelId) {
            $modelId = trim((string)$modelId);
            if ($this->isSafeModelId($modelId)) {
                $unsupported[$modelId] = true;
            }
        }
        $unsupportedIds = array_keys($unsupported);
        sort($unsupportedIds, SORT_STRING);

        return [
            'valid' => true,
            'keyFingerprint' => $keyFingerprint,
            'supported' => $supported,
            'unsupported' => array_slice($unsupportedIds, 0, 200),
        ];
    }

    /**
     * @return array{
     *     valid:bool,
     *     keyFingerprint:string,
     *     supported:list<array{id:string,label:string}>,
     *     unsupported:list<string>
     * }
     */
    private function empty(bool $valid): array
    {
        return [
            'valid' => $valid,
            'keyFingerprint' => '',
            'supported' => [],
            'unsupported' => [],
        ];
    }

    private function isSafeModelId(string $modelId): bool
    {
        return $modelId !== ''
            && strlen($modelId) <= 100
            && preg_match('/^[a-zA-Z0-9._:-]+$/', $modelId) === 1;
    }

    private function normalizeFingerprint(string $fingerprint): string
    {
        $fingerprint = strtolower(trim($fingerprint));

        return preg_match('/^[a-f0-9]{64}$/', $fingerprint) === 1 ? $fingerprint : '';
    }
}
