<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Media;

/**
 * The fields AI media may suggest for a file's metadata record: alternative text, title and description. AQG never
 * writes them (UX Atlas open decision #1 is not taken); a suggestion only fills TYPO3's metadata form and TYPO3 Save
 * stores it. Every suggested value passes the same plain-text check before it is offered: no markup, no control
 * characters, no URLs, bounded length.
 */
final class MediaAiFieldPolicy
{
    public const FIELD_ALTERNATIVE = 'alternative';
    public const FIELD_TITLE = 'title';
    public const FIELD_DESCRIPTION = 'description';

    /** sys_file_metadata fields AQG may suggest. Nothing else, ever. */
    public const METADATA_FIELDS = [self::FIELD_ALTERNATIVE, self::FIELD_TITLE, self::FIELD_DESCRIPTION];

    public const VALID = '';

    /** Length limits of a reviewed value (characters). */
    private const MAX_LENGTH = [
        self::FIELD_ALTERNATIVE => 250,
        self::FIELD_TITLE => 255,
        self::FIELD_DESCRIPTION => 2000,
    ];

    /** Recommended maximum of a suggestion, sent to the model and shown as the character count. */
    public const RECOMMENDED_LENGTH = [
        self::FIELD_ALTERNATIVE => 150,
        self::FIELD_TITLE => 100,
        self::FIELD_DESCRIPTION => 1000,
    ];

    public static function isMetadataField(string $field): bool
    {
        return in_array($field, self::METADATA_FIELDS, true);
    }

    public static function maxLength(string $field): int
    {
        return self::MAX_LENGTH[$field] ?? 0;
    }

    /**
     * @param list<mixed> $fields
     * @return list<string> the requested fields that are on the allow-list, in allow-list order
     */
    public static function filterFields(array $fields): array
    {
        $requested = array_map('strval', array_filter($fields, 'is_scalar'));

        return array_values(array_filter(self::METADATA_FIELDS, static fn (string $field): bool => in_array($field, $requested, true)));
    }

    /**
     * @return array{0:string,1:string} the normalised value and '' or a rejection code
     */
    public function validate(string $field, string $value): array
    {
        if (!self::isMetadataField($field)) {
            return ['', 'field_not_allowed'];
        }
        $value = $this->normalize($value, $field === self::FIELD_DESCRIPTION);
        if ($value === '') {
            return ['', 'empty'];
        }
        if (preg_match('/[<>]|[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            return ['', 'markup'];
        }
        if (mb_strlen($value) > self::maxLength($field)) {
            return ['', 'too_long'];
        }
        if (preg_match('~(^|\s)(https?://|www\.|javascript:|data:)\S*~i', $value) === 1) {
            return ['', 'url'];
        }

        return [$value, self::VALID];
    }

    private function normalize(string $value, bool $keepLineBreaks): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = $keepLineBreaks
            ? (string)preg_replace('/[^\S\n]+/u', ' ', $value)
            : (string)preg_replace('/\s+/u', ' ', $value);
        $value = trim((string)preg_replace('/\n{3,}/', "\n\n", $value));
        // Models sometimes wrap the value in quotes although told not to.
        if (preg_match('/^(["\'“„»«])(.*)(["\'”“«»])$/us', $value, $match) === 1) {
            $value = trim($match[2]);
        }

        return $value;
    }
}
