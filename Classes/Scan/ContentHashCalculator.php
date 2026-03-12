<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scan;

use GuzzleHttp\Utils;

final class ContentHashCalculator
{
    public function forRteField(string $html): string
    {
        $normalized = mb_strtolower($html);
        $normalized = (string)preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);

        return sha1($normalized);
    }

    public function forStructuredField(mixed $value): string
    {
        if (is_array($value)) {
            try {
                $normalized = Utils::jsonEncode(
                    $value,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            } catch (\InvalidArgumentException) {
                $normalized = serialize($value);
            }
        } elseif (is_bool($value)) {
            $normalized = $value ? '1' : '0';
        } else {
            $normalized = (string)$value;
        }

        $normalized = trim($normalized);

        return sha1($normalized);
    }
}
