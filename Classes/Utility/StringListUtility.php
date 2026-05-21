<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Utility;

final class StringListUtility
{
    public static function normalize(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string)$value),
            $values
        ), static fn (string $value): bool => $value !== '')));
    }

    public static function decodeJsonList(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? self::normalize($decoded) : [];
    }
}
