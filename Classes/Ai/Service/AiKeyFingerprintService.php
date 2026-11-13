<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;

final class AiKeyFingerprintService
{
    public function fingerprint(#[\SensitiveParameter] string $apiKey, string $provider = AiProviderCatalog::OPENAI): string
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return '';
        }

        $encryptionKey = trim((string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? ''));
        if ($encryptionKey === '') {
            throw new \RuntimeException('TYPO3 encryptionKey is required for AI key fingerprints.', 1771002600);
        }

        // The OpenAI salt is unchanged since 2.0, so existing verifications stay valid. Each provider has its own salt:
        // the same key string never yields the same fingerprint for two providers.
        $scope = AiProviderCatalog::normalize($provider);
        if ($scope === '') {
            throw new \InvalidArgumentException('Unsupported AI provider.', 1771002601);
        }
        $hmacKey = hash('sha256', 'a11y_quality_gate:' . $scope . ':key_fingerprint:v1:' . $encryptionKey, true);

        return hash_hmac('sha256', $apiKey, $hmacKey);
    }
}
