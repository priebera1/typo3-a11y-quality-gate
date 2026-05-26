<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Normalizer;

final class PhraseMatcher
{
    public static function normalize(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($text, Normalizer::FORM_C);
            if ($normalized !== false) {
                $text = $normalized;
            }
        }

        $text = mb_strtolower($text, 'UTF-8');
        $text = (string)preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        $text = rtrim($text, ".,;:!?()\"'\u{2026}");

        return trim($text);
    }

    /**
     * @param list<string> $normalizedPhrases
     */
    public static function isExactMatch(string $normalizedText, array $normalizedPhrases): bool
    {
        return in_array($normalizedText, $normalizedPhrases, true);
    }

    /**
     * @param list<string> $normalizedPhrases
     */
    public static function isPrefixMatch(string $normalizedText, array $normalizedPhrases): bool
    {
        foreach ($normalizedPhrases as $phrase) {
            if ($phrase !== '' && ($normalizedText === $phrase || str_starts_with($normalizedText, $phrase . ' '))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $normalizedPhrases
     */
    public static function isWordBoundaryMatch(string $normalizedText, array $normalizedPhrases): bool
    {
        if ($normalizedText === '' || $normalizedPhrases === []) {
            return false;
        }

        $phrases = array_values(array_filter(
            $normalizedPhrases,
            static fn(string $phrase): bool => $phrase !== ''
        ));

        if ($phrases === []) {
            return false;
        }

        usort($phrases, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $escaped = array_map(
            static fn(string $phrase): string => preg_quote($phrase, '/'),
            $phrases
        );

        $pattern = '/(?<!\p{L})(' . implode('|', $escaped) . ')(?!\p{L})/u';

        return preg_match($pattern, $normalizedText) === 1;
    }
}
