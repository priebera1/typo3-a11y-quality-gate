<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

final class SecretEncryptionService
{
    private const PREFIX = 'sodium:v1:';

    public function encrypt(string $plainText): string
    {
        $plainText = trim($plainText);
        if ($plainText === '') {
            return '';
        }

        if (str_starts_with($plainText, self::PREFIX)) {
            return $plainText;
        }

        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \RuntimeException(
                'sodium extension is required for AQG HTTP auth encryption. Enable ext-sodium in PHP.',
                1763630001
            );
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipherText = sodium_crypto_secretbox($plainText, $nonce, $this->getKey());

        return self::PREFIX . base64_encode($nonce . $cipherText);
    }

    public function decrypt(string $encryptedValue): string
    {
        $encryptedValue = trim($encryptedValue);
        if ($encryptedValue === '') {
            return '';
        }

        if (!str_starts_with($encryptedValue, self::PREFIX)) {
            return $encryptedValue;
        }

        if (!function_exists('sodium_crypto_secretbox_open')) {
            throw new \RuntimeException(
                'sodium extension is required for AQG HTTP auth decryption. Enable ext-sodium in PHP.',
                1763630002
            );
        }

        $payload = base64_decode(substr($encryptedValue, strlen(self::PREFIX)), true);
        if (!is_string($payload) || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipherText = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plainText = sodium_crypto_secretbox_open($cipherText, $nonce, $this->getKey());

        return is_string($plainText) ? $plainText : '';
    }

    private function getKey(): string
    {
        $encryptionKey = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');

        return hash('sha256', 'a11y_quality_gate:remote_scan_access:' . $encryptionKey, true);
    }
}
