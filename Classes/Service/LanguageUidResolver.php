<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

final class LanguageUidResolver
{
    private const LANGUAGE_KEYS = [
        'language',
        'languageUid',
        'L',
        'sys_language_uid',
    ];

    public function fromParameters(
        array $primaryParameters,
        array $fallbackParameters = [],
        ?int $default = 0,
        bool $allowAllLanguages = true,
    ): ?int {
        foreach (self::LANGUAGE_KEYS as $key) {
            if (array_key_exists($key, $primaryParameters)) {
                $resolved = $this->fromValue($primaryParameters[$key], null, $allowAllLanguages);
                if ($resolved !== null) {
                    return $resolved;
                }
            }

            if (array_key_exists($key, $fallbackParameters)) {
                $resolved = $this->fromValue($fallbackParameters[$key], null, $allowAllLanguages);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return $default;
    }

    public function hasLanguageParameter(array $parameters): bool
    {
        foreach (self::LANGUAGE_KEYS as $key) {
            if (array_key_exists($key, $parameters)) {
                return true;
            }
        }

        return false;
    }

    public function fromRecord(array $record, ?int $default = 0): ?int
    {
        return $this->fromValue($record['sys_language_uid'] ?? null, $default, false);
    }

    public function fromRteData(array $data, int $default = 0): int
    {
        $record = $data['databaseRow'] ?? [];
        if (is_array($record)) {
            $fromRecord = $this->fromRecord($record, null);
            if ($fromRecord !== null) {
                return $fromRecord;
            }
        }

        return $this->fromParameters($data, [], $default, false) ?? $default;
    }

    public function fromValue(mixed $value, ?int $default = 0, bool $allowAllLanguages = true): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (!is_scalar($value)) {
            return $default;
        }

        $raw = trim((string)$value);
        if (preg_match('/^-?\d+$/', $raw) !== 1) {
            return $default;
        }

        $languageUid = (int)$raw;
        if (!$allowAllLanguages && $languageUid < 0) {
            return $default;
        }

        return $languageUid;
    }
}
