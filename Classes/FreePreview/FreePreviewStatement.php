<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\FreePreview;

/**
 * The declaration an administrator accepts to activate the FREE Remote Preview: one combined confirmation that names both
 * complete documents with their versions — the AQG FREE Remote Preview Terms and the AQG FREE Remote Preview Data
 * Processing Terms — and the authority to act for the organisation named in the acceptance, including the authority to
 * appoint PPPriebera as a further processor where the organisation acts for a client (Libra, 2026-10-22; the first
 * version, `free-statement-2026-10`, named only the data processing terms).
 *
 * It is contract text, not an interface label: it is sent to the AQG service with its SHA-256, and the service records
 * the acceptance only when the hash is one it publishes for the terms version (`statements` of GET
 * /free-preview/terms). It therefore lives here, verbatim, and not in the translation files, where an edit would
 * silently change what was accepted. Changing it — also for a new version of either document — means a new VERSION and
 * the matching text on the AQG service (aqg-api `FREE_STATEMENT`).
 */
final class FreePreviewStatement
{
    public const VERSION = 'free-statement-2026-10-v2';

    public const TEXT = [
        'en' => 'I accept the AQG FREE Remote Preview Terms, version 1.0, and the AQG FREE Remote Preview Data Processing Terms, version 1.0. I confirm that I am authorised to act for the organisation named in this acceptance and, where the organisation acts for a client, that it is authorised to appoint PPPriebera as a further processor.',
        'de' => 'Ich akzeptiere die AQG-Nutzungsbedingungen für FREE Remote Preview, Version 1.0, und die AQG-Datenschutzbedingungen für FREE Remote Preview, Version 1.0. Ich bestätige, dass ich berechtigt bin, für die in dieser Annahme bezeichnete Organisation zu handeln, und, wenn die Organisation für einen Auftraggeber handelt, dass sie berechtigt ist, PPPriebera als weiteren Auftragsverarbeiter zu beauftragen.',
    ];

    /** The statement language for a backend language: German for `de`, English otherwise. */
    public static function languageFor(string $backendLanguage): string
    {
        return strtolower(substr(trim($backendLanguage), 0, 2)) === 'de' ? 'de' : 'en';
    }

    public static function text(string $language): string
    {
        return self::TEXT[$language] ?? self::TEXT['en'];
    }

    public static function sha256(string $language): string
    {
        return hash('sha256', self::text($language));
    }
}
