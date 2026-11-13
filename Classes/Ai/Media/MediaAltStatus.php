<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Media;

/**
 * The alternative-text state of one image use (UX Atlas 05: "ALT missing / ALT own / ALT inherited / Decorative"),
 * decided like FileReferenceAltRule decides it: a string value of the use (also an empty one) overrides the file
 * default, NULL inherits it, and "Decorative image" needs no text. The same states are kept live in the form by
 * media-ai-field.js; this class only describes the stored record.
 */
final class MediaAltStatus
{
    public const MISSING = 'missing';
    public const OWN = 'own';
    public const INHERITED = 'inherited';
    public const DECORATIVE = 'decorative';

    private const LABEL_KEYS = [
        self::MISSING => 'altMissing',
        self::OWN => 'altOwn',
        self::INHERITED => 'altInherited',
        self::DECORATIVE => 'altDecorative',
    ];

    public static function of(mixed $referenceAlternative, bool $decorative, string $fileDefault): string
    {
        if ($decorative) {
            return self::DECORATIVE;
        }
        if (is_string($referenceAlternative)) {
            return trim($referenceAlternative) !== '' ? self::OWN : self::MISSING;
        }

        return trim($fileDefault) !== '' ? self::INHERITED : self::MISSING;
    }

    /**
     * The chip texts the form needs to keep it current (media-alt-status.js), "Filled in, unsaved" included, and the
     * advice it shows under the field while a use has no alternative text.
     */
    private const LIVE_LABEL_KEYS = [
        'altMissing', 'altOwn', 'altInherited', 'altDecorative', 'stepFilled',
        'altAdviceHidesDefault', 'altAdviceNoDefault', 'altAdviceDescribe', 'altAdviceDecorative',
    ];

    /**
     * The chip on the image's header; text and shape carry the state, never colour alone. TYPO3's own badge classes
     * style it when media-ai.css is not on the page yet (an image added or expanded by AJAX); AQG's chip rules refine it.
     * With the form input name of the use's alternative text, it also carries what the form needs to keep it current
     * without AI (media-alt-status.js): the input, whether the file has a default text to inherit and the stored
     * "Decorative image" flag (the switch is an exclude field: a user without it still sees the stored decision).
     */
    public static function chipHtml(string $status, string $inputName = '', bool $hasFileDefault = false): string
    {
        $status = isset(self::LABEL_KEYS[$status]) ? $status : self::MISSING;
        $badge = [self::MISSING => 'badge-danger', self::OWN => 'badge-success', self::INHERITED => 'badge-info'][$status] ?? 'badge-default';
        $binding = '';
        if ($inputName !== '') {
            $labels = array_intersect_key(MediaAiLabels::translated(), array_flip(self::LIVE_LABEL_KEYS));
            $binding = ' data-aqg-alt-input="' . htmlspecialchars($inputName) . '"'
                . ' data-aqg-alt-file-default="' . ($hasFileDefault ? '1' : '0') . '"'
                . ' data-aqg-alt-decorative="' . ($status === self::DECORATIVE ? '1' : '0') . '"'
                . ' data-aqg-alt-labels="' . htmlspecialchars((string)json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
        }

        return '<span class="aqg-media-altchip aqg-media-altchip--' . $status . ' badge ' . $badge . '" data-aqg-alt-chip="' . $status . '"' . $binding . '>'
            . htmlspecialchars(MediaAiLabels::label(self::LABEL_KEYS[$status])) . '</span>';
    }
}
