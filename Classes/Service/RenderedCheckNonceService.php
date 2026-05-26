<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Psr\Http\Message\ServerRequestInterface;

final class RenderedCheckNonceService
{
    private const NONCE_TTL_SECONDS = 60;
    private const PURPOSE = 'aqg-rendered';

    public function generate(int $pageUid, int $languageUid): string
    {
        if ($pageUid <= 0 || $languageUid < 0 || $this->getEncryptionKey() === '') {
            return '';
        }

        return $this->createNonce($pageUid, $languageUid, $this->getWindow());
    }

    public function isValidRequest(ServerRequestInterface $request): bool
    {
        $queryParams = $request->getQueryParams();

        return $this->isValidParameters(
            (int)($queryParams['_aqg_page'] ?? $queryParams['id'] ?? 0),
            (int)($queryParams['_aqg_lang'] ?? $queryParams['L'] ?? 0),
            trim((string)($queryParams['_aqg_nonce'] ?? ''))
        );
    }

    public function isValidParameters(int $pageUid, int $languageUid, string $receivedNonce): bool
    {
        $receivedNonce = trim($receivedNonce);
        if ($receivedNonce === '' || $pageUid <= 0 || $languageUid < 0 || $this->getEncryptionKey() === '') {
            return false;
        }

        $window = $this->getWindow();
        foreach ([$window, $window - 1] as $candidateWindow) {
            $expectedNonce = $this->createNonce($pageUid, $languageUid, $candidateWindow);
            if ($expectedNonce !== '' && hash_equals($expectedNonce, $receivedNonce)) {
                return true;
            }
        }

        return false;
    }

    private function createNonce(int $pageUid, int $languageUid, int $window): string
    {
        $encryptionKey = $this->getEncryptionKey();
        if ($encryptionKey === '') {
            return '';
        }

        return hash_hmac(
            'sha256',
            self::PURPOSE . ':' . $pageUid . ':' . $languageUid . ':' . $window,
            $encryptionKey
        );
    }

    private function getWindow(): int
    {
        return intdiv(time(), self::NONCE_TTL_SECONDS);
    }

    private function getEncryptionKey(): string
    {
        return trim((string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? ''));
    }
}
