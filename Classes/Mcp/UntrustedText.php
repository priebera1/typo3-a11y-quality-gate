<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

/**
 * Text that reaches an agent from a scanned website or from editors (page titles, URLs, HTML snippets, record titles,
 * messages) is data, never instructions (AQG 3.0.0 public hardening, Fable P2-4). The tools put it under an `untrusted`
 * object; this helper removes what can hide or reorder text before it leaves TYPO3 — the AQG MCP Gateway applies the
 * same rule again to every relayed result (aqg-api services/mcp/untrusted.ts):
 *
 *  - C0/C1 control characters (ESC sequences, NUL …), except tab and line feed; CRLF becomes LF;
 *  - bidirectional controls (U+061C, U+200E/F, U+202A–U+202E, U+2066–U+2069);
 *  - zero-width and invisible format characters (U+200B–U+200D, U+2060–U+2064, U+FEFF, U+00AD, U+180E);
 *  - Unicode tag characters (U+E0000–U+E007F, invisible ASCII "smuggling"), variation selectors (U+FE00–U+FE0F,
 *    U+E0100–U+E01EF), the combining grapheme joiner, Hangul fillers and line/paragraph separators.
 *
 * Invalid UTF-8 is replaced, never passed on. Visible text stays as it is.
 */
final class UntrustedText
{
    private const INVISIBLE = '/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{00AD}\x{034F}\x{061C}\x{115F}\x{1160}\x{180E}\x{200B}-\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{3164}\x{FE00}-\x{FE0F}\x{FEFF}\x{E0000}-\x{E007F}\x{E0100}-\x{E01EF}]/u';

    private function __construct()
    {
    }

    public static function clean(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return (string)preg_replace(self::INVISIBLE, '', $value);
    }

    /** One line of at most `$length` characters (whitespace collapsed, an ellipsis marks a cut). */
    public static function line(string $value, int $length): string
    {
        $value = trim((string)preg_replace('/\s+/u', ' ', self::clean($value)));

        return mb_strlen($value) > $length ? rtrim(mb_substr($value, 0, $length - 1)) . '…' : $value;
    }

    /** A URL as stored, cleaned and bounded (no whitespace inside; longer than `$length` is cut, never resolved). */
    public static function url(string $value, int $length = 2048): string
    {
        $value = (string)preg_replace('/\s+/u', '', self::clean($value));

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }
}
