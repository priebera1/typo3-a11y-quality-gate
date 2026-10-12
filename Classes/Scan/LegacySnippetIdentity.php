<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scan;

/**
 * Snippets stored by AQG before 1.9.9 differ from the ones 1.9.9 writes for the same element in two ways:
 *
 * - rich text was read as ISO-8859-1, so every non-ASCII character became one entity per UTF-8 byte
 *   ("Predvolen&#xC3;&#xBD;" instead of "Predvolen&#xFD;");
 * - rendered snippets carried AQG's record markers (`data-aqg-*` attributes, `div.aqg-marker` wrappers), and a page-level
 *   element (`html`, `body`) carried its whole content instead of its opening tag.
 *
 * The snippet is part of a finding's fingerprint, so without this the first 1.9.9 scan reported each such finding as
 * resolved and again as new, and an ignored one came back open. upgrade() rewrites a stored snippet the way 1.9.9 writes
 * it, so the scan can recognise the stored finding and keep its identity, status and history.
 */
final class LegacySnippetIdentity
{
    private const MARKER_ATTRIBUTE = '/\s+data-aqg-[\w-]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+))?/i';
    private const MARKER_WRAPPER = '/<div\b[^>]*\bclass\s*=\s*"[^"]*\baqg-marker\b[^"]*"[^>]*>/i';

    /**
     * The snippet as 1.9.9 writes it, or null when the stored snippet has no legacy form.
     */
    public static function upgrade(string $snippet): ?string
    {
        if ($snippet === '') {
            return null;
        }

        $upgraded = self::repairByteEntities(self::stripRecordMarkers($snippet));

        return $upgraded !== $snippet ? $upgraded : null;
    }

    private static function stripRecordMarkers(string $snippet): string
    {
        if (stripos($snippet, 'data-aqg-') === false && stripos($snippet, 'aqg-marker') === false) {
            return $snippet;
        }

        // A page-level element is represented by its opening tag only.
        if (preg_match('/^\s*<(?:html|body)\b[^>]*>/i', $snippet, $match) === 1) {
            return trim((string)preg_replace(self::MARKER_ATTRIBUTE, '', $match[0]));
        }

        $withoutWrappers = (string)preg_replace(self::MARKER_WRAPPER, '', $snippet);

        return (string)preg_replace(self::MARKER_ATTRIBUTE, '', $withoutWrappers);
    }

    /**
     * Runs of `&#xHH;` entities (0x80-0xFF) that form valid UTF-8 byte sequences become one entity per character, in
     * the uppercase hex form libxml writes. Single Latin-1 characters (`&#xE1;`, `&#xA0;`) that are not part of a valid
     * multi-byte sequence stay as they are.
     */
    private static function repairByteEntities(string $snippet): string
    {
        if (stripos($snippet, '&#x') === false) {
            return $snippet;
        }

        return (string)preg_replace_callback(
            '/(?:&#x[89A-F][0-9A-F];)+/i',
            static fn (array $match): string => self::repairRun($match[0]),
            $snippet
        );
    }

    private static function repairRun(string $run): string
    {
        preg_match_all('/&#x([0-9A-F]{2});/i', $run, $matches);
        $bytes = array_map(static fn (string $hex): int => (int)hexdec($hex), $matches[1]);
        $result = '';
        $count = count($bytes);

        for ($index = 0; $index < $count;) {
            $length = self::sequenceLength($bytes, $index);
            if ($length === 0) {
                $result .= sprintf('&#x%X;', $bytes[$index]);
                $index++;
                continue;
            }

            $character = implode('', array_map('chr', array_slice($bytes, $index, $length)));
            $result .= sprintf('&#x%X;', mb_ord($character, 'UTF-8'));
            $index += $length;
        }

        return $result;
    }

    /**
     * @param list<int> $bytes
     * @return int the length of the valid multi-byte UTF-8 sequence starting at $index, 0 when there is none
     */
    private static function sequenceLength(array $bytes, int $index): int
    {
        $lead = $bytes[$index];
        $length = match (true) {
            $lead >= 0xC2 && $lead <= 0xDF => 2,
            $lead >= 0xE0 && $lead <= 0xEF => 3,
            $lead >= 0xF0 && $lead <= 0xF4 => 4,
            default => 0,
        };
        if ($length === 0 || $index + $length > count($bytes)) {
            return 0;
        }

        $character = implode('', array_map('chr', array_slice($bytes, $index, $length)));

        return mb_check_encoding($character, 'UTF-8') ? $length : 0;
    }
}
