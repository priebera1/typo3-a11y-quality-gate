<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\FreePreview;

use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;

final class FreeSubmitIntentService
{
    private const VERSION = 2;
    private const MAX_AGE_SECONDS = 86400;

    public function __construct(
        private readonly BackendUserServiceInterface $backendUserService,
    ) {
    }

    public function create(string $siteIdentifier, int $pageUid): string
    {
        if ($pageUid <= 0) {
            throw new \InvalidArgumentException('A valid page UID is required for a Free Remote Preview submit intent.');
        }

        $payload = [
            'v' => self::VERSION,
            'site' => trim($siteIdentifier),
            'page' => $pageUid,
            'user' => $this->backendUserService->getBackendUserUid(),
            'issued' => time(),
            'nonce' => bin2hex(random_bytes(16)),
        ];
        $encoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        return $encoded . '.' . $this->sign($encoded);
    }

    public function buildIdempotencyKey(string $intent, string $siteIdentifier, int $pageUid): string
    {
        $payload = $this->decodeAndValidate($intent);
        if (
            (string)($payload['site'] ?? '') !== trim($siteIdentifier)
            || (int)($payload['page'] ?? 0) !== $pageUid
            || (int)($payload['user'] ?? 0) !== $this->backendUserService->getBackendUserUid()
        ) {
            throw new \InvalidArgumentException('Free Remote Preview submit intent does not match this request.');
        }

        return 'aqg-free-' . hash('sha256', $intent);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAndValidate(string $intent): array
    {
        $parts = explode('.', trim($intent), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException('Missing Free Remote Preview submit intent.');
        }

        [$encoded, $signature] = $parts;
        if (!hash_equals($this->sign($encoded), $signature)) {
            throw new \InvalidArgumentException('Invalid Free Remote Preview submit intent.');
        }

        try {
            $payload = json_decode($this->base64UrlDecode($encoded), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException | \UnexpectedValueException $exception) {
            throw new \InvalidArgumentException('Invalid Free Remote Preview submit intent.', 0, $exception);
        }

        if (!is_array($payload) || (int)($payload['v'] ?? 0) !== self::VERSION) {
            throw new \InvalidArgumentException('Invalid Free Remote Preview submit intent.');
        }

        $issued = (int)($payload['issued'] ?? 0);
        if ($issued <= 0 || $issued > time() + 60 || time() - $issued > self::MAX_AGE_SECONDS) {
            throw new \InvalidArgumentException('Expired Free Remote Preview submit intent.');
        }

        return $payload;
    }

    private function sign(string $payload): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->secret(), true));
    }

    private function secret(): string
    {
        $encryptionKey = trim((string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? ''));
        if ($encryptionKey === '') {
            throw new \RuntimeException('TYPO3 encryptionKey is required for Free Remote Preview submit intents.');
        }

        return hash('sha256', $encryptionKey . '|a11y-quality-gate|free-submit-intent|v2', true);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \UnexpectedValueException('Invalid base64url value.');
        }

        return $decoded;
    }
}
