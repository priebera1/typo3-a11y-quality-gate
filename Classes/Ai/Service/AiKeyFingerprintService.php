<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

final class AiKeyFingerprintService
{
    public function fingerprint(#[\SensitiveParameter] string $apiKey): string
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return '';
        }

        $encryptionKey = trim((string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? ''));
        if ($encryptionKey === '') {
            throw new \RuntimeException('TYPO3 encryptionKey is required for AI key fingerprints.', 1771002600);
        }

        $hmacKey = hash('sha256', 'a11y_quality_gate:openai:key_fingerprint:v1:' . $encryptionKey, true);

        return hash_hmac('sha256', $apiKey, $hmacKey);
    }
}
