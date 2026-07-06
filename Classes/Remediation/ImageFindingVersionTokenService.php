<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation;

use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageFindingVersionTokenServiceInterface;


final class ImageFindingVersionTokenService implements ImageFindingVersionTokenServiceInterface
{
    private const VERSION = 1;

    public function __construct(private readonly BackendUserServiceInterface $backendUserService) {}

    public function create(ImageFindingContext $context): string
    {
        $payload = $this->payload($context);
        $encoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        return $encoded . '.' . $this->base64UrlEncode(hash_hmac('sha256', $encoded, $this->secret(), true));
    }

    public function assertValid(string $token, ImageFindingContext $context): void
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidImageVersionTokenException('The remediation token is invalid.', 1771001401);
        }

        [$encodedPayload, $encodedSignature] = $parts;
        $expectedSignature = $this->base64UrlEncode(hash_hmac('sha256', $encodedPayload, $this->secret(), true));
        if (!hash_equals($expectedSignature, $encodedSignature)) {
            throw new InvalidImageVersionTokenException('The remediation token is invalid.', 1771001402);
        }

        try {
            $payload = json_decode($this->base64UrlDecode($encodedPayload), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException | \UnexpectedValueException $exception) {
            throw new InvalidImageVersionTokenException('The remediation token is invalid.', 1771001403, $exception);
        }
        if (!is_array($payload)) {
            throw new InvalidImageVersionTokenException('The remediation token is invalid.', 1771001404);
        }

        if ($payload !== $this->payload($context)) {
            throw new StaleImageFindingException('The finding changed after the page was loaded. Refresh and try again.', 1771001405);
        }
    }

    /** @return array<string,int|string> */
    private function payload(ImageFindingContext $context): array
    {
        return [
            'v' => self::VERSION,
            'finding' => (int)($context->issue['uid'] ?? 0),
            'reference' => $context->fileReferenceUid,
            'fingerprint' => $context->fingerprint,
            'issueTimestamp' => $context->issueTimestamp,
            'referenceTimestamp' => $context->fileReferenceTimestamp,
            'site' => $context->siteIdentifier,
            'backendUser' => $this->backendUserUid(),
        ];
    }

    private function backendUserUid(): int
    {
        $uid = $this->backendUserService->getBackendUserUid();
        if ($uid <= 0) {
            throw new InvalidImageVersionTokenException(
                'A logged-in backend user is required for image remediation.',
                1771001407,
            );
        }

        return $uid;
    }

    private function secret(): string
    {
        $encryptionKey = trim((string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? ''));
        if ($encryptionKey === '') {
            throw new \RuntimeException('TYPO3 encryptionKey is required for remediation tokens.', 1771001406);
        }

        return hash('sha256', $encryptionKey . '|a11y-quality-gate|image-remediation|v1', true);
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
