<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Editor;

/**
 * Checks a model-proposed value before an editor ever sees it as something to apply. Model output is untrusted text:
 * markup, URLs as link text, control characters, empty or unchanged values and values over the per-kind limit are
 * rejected as an invalid AI response instead of being repaired.
 */
final class EditorAiValueValidator
{
    public const VALID = '';

    /** @return array{0:string,1:string} the normalised value and '' or a rejection code */
    public function validate(string $kind, string $value, string $currentValue): array
    {
        $value = $this->normalize($value);
        if ($value === '') {
            return ['', 'empty'];
        }
        if (preg_match('/[<>]|[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            return ['', 'markup'];
        }
        if (mb_strlen($value) > EditorAiRuleCatalog::maxLength($kind)) {
            return ['', 'too_long'];
        }
        if (preg_match('~(^|\s)(https?://|www\.)\S+~i', $value) === 1) {
            return ['', 'url'];
        }
        if (mb_strtolower($value) === mb_strtolower(EditorFindingTargetResolver::normalizeText($currentValue))) {
            return ['', 'unchanged'];
        }

        return [$value, self::VALID];
    }

    /** Model output for writing aids: plain text with the original's line breaks, never markup. */
    public function validateText(string $value, int $maxLength): array
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if ($value === '') {
            return ['', 'empty'];
        }
        if (preg_match('/[<>]|[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            return ['', 'markup'];
        }
        if (mb_strlen($value) > $maxLength) {
            return ['', 'too_long'];
        }

        return [$value, self::VALID];
    }

    private function normalize(string $value): string
    {
        $value = EditorFindingTargetResolver::normalizeText($value);
        // Models sometimes wrap the value in quotes although told not to.
        if (preg_match('/^(["\'“„»«])(.*)(["\'”“«»])$/u', $value, $match) === 1) {
            $value = trim($match[2]);
        }

        return $value;
    }
}
