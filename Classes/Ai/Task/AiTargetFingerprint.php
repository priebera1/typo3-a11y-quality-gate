<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Task;

/**
 * Fingerprint of the value a suggestion was generated for (record, field and the field's value at that time). Before
 * a suggestion is applied, the caller compares it with a fingerprint of the current value: a mismatch means the
 * target changed in the meantime and the suggestion is stale.
 */
final class AiTargetFingerprint
{
    /** @param list<string|int> $parts e.g. table, uid, field, current value */
    public static function of(array $parts): string
    {
        $encryptionKey = trim((string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? ''));
        if ($encryptionKey === '') {
            throw new \RuntimeException('TYPO3 encryptionKey is required for AI target fingerprints.', 1771002610);
        }

        return hash_hmac(
            'sha256',
            json_encode(array_map('strval', $parts), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            hash('sha256', 'a11y_quality_gate:ai_target:v1:' . $encryptionKey, true),
        );
    }

    public static function matches(string $expected, array $currentParts): bool
    {
        return $expected !== '' && hash_equals($expected, self::of($currentParts));
    }
}
