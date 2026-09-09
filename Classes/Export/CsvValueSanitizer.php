<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Export;

/**
 * Single policy for neutralising spreadsheet formula injection in AQG CSV exports.
 *
 * Exported cells carry editor- and API-controlled text (page titles, finding messages, selectors,
 * snippets, URLs, ignore reasons). A cell that begins with one of the formula prefixes is executed
 * by Excel, LibreOffice and Google Sheets when the file is opened, so such values are prefixed with
 * a single quote. Genuine numbers are left untouched so numeric columns stay usable.
 */
final class CsvValueSanitizer
{
    /**
     * Characters a spreadsheet treats as the start of a formula, plus the control characters that
     * can be used to smuggle one past a naive first-character check.
     */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r", "\n"];

    /**
     * @param array<int, mixed> $row
     * @return array<int, mixed>
     */
    public static function sanitizeRow(array $row): array
    {
        return array_map(static fn (mixed $value): mixed => self::sanitize($value), $row);
    }

    public static function sanitize(mixed $value): mixed
    {
        if (!is_string($value)) {
            // Ints, floats, bools and null cannot carry a formula prefix.
            return $value;
        }

        if ($value === '' || is_numeric($value)) {
            // "-5" and "-1.5" are numbers, not formulas; keep numeric columns numeric.
            return $value;
        }

        if (!in_array($value[0], self::FORMULA_PREFIXES, true)) {
            return $value;
        }

        return "'" . $value;
    }
}
