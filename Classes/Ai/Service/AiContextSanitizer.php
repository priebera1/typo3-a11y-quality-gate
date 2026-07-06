<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

final class AiContextSanitizer
{
    private const GENERIC_VALUES = [
        'image',
        'picture',
        'photo',
        'text',
        'hero',
        'hero image',
        'media',
        'content',
    ];

    public function sanitizeNullable(mixed $value, int $maxLength, bool $dropGenericValue = true): ?string
    {
        if (!is_scalar($value) || $maxLength <= 0) {
            return null;
        }

        $normalized = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = strip_tags($normalized);
        $normalized = (string)preg_replace('/[\x00-\x1F\x7F]/u', ' ', $normalized);
        $normalized = (string)preg_replace('/\s+/u', ' ', $normalized);
        $normalized = trim($normalized);

        if ($normalized === '') {
            return null;
        }

        if ($dropGenericValue && in_array(mb_strtolower($normalized), self::GENERIC_VALUES, true)) {
            return null;
        }

        return mb_substr($normalized, 0, $maxLength);
    }
}
