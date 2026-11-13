<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

/**
 * Alt text that only names the medium ("image", "Bild", "photo 1", "placeholder") describes nothing. Only the whole alt
 * text counts: a description that contains one of these words ("Photo booth at the summer party") is never a placeholder.
 */
final class PlaceholderAltText
{
    private const WORDS = [
        // English
        'image', 'picture', 'pic', 'photo', 'img', 'graphic', 'illustration', 'placeholder', 'alt', 'alt text', 'alt tag',
        'alternative text', 'untitled', 'no description', 'thumbnail', 'banner',
        // German
        'bild', 'foto', 'grafik', 'abbildung', 'platzhalter', 'alternativtext', 'alt-text', 'ohne titel', 'vorschaubild',
    ];

    public static function isPlaceholder(string $alt): bool
    {
        $text = mb_strtolower(trim(PhraseMatcher::normalize($alt)));
        // Trailing numbering and separators ("photo 1", "img_02", "Bild-3", "image.") do not make it a description.
        $text = (string)preg_replace('/[\s._\-#:]*\d*[\s.!]*$/u', '', $text);
        $text = (string)preg_replace('/\s+/u', ' ', str_replace('_', ' ', $text));

        return $text !== '' && in_array($text, self::WORDS, true);
    }
}
