<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask;

/**
 * Recognises an explicit request to repair content ("Fix this element", "Behebe die Probleme", "Oprav mi túto stránku")
 * without any AI request, so the decision is predictable and free: such a request opens Ask AQG's repair workflow
 * (preflight → Prepare fixes → AI Fixes review) instead of a text answer.
 *
 * Deliberately conservative: a question that asks for information ("How do I fix this?", "What should I fix first?",
 * "Wo kann ich das beheben?", "Čo mám opraviť?") stays an information request, and so does a negated request
 * ("Don't fix anything"). English and German are supported fully; ordinary Slovak and Czech requests are tolerated.
 * The result also names what the wording points at (element, page, website) and which kinds of problems it narrows to
 * (alt text, iframe title, button label, link text, heading), so the toolbar can make the target explicit.
 */
final class AskAqgRepairIntent
{
    public const MENTION_ELEMENT = 'element';
    public const MENTION_PAGE = 'page';
    public const MENTION_SITE = 'site';

    public const KIND_IMAGE = 'image_alt';
    public const KIND_IFRAME = 'iframe_title';
    public const KIND_BUTTON = 'button_label';
    public const KIND_LINK = 'link_text';
    public const KIND_HEADING = 'heading_text';

    /** Politeness and "can you" lead-ins removed before the first word is looked at (normalised, without diacritics). */
    private const LEAD_INS = [
        'please', 'pls', 'kindly', 'bitte', 'prosim', 'prosimte', 'hey', 'hi', 'hallo', 'ahoj', 'aqg', 'ok', 'okay', 'now', 'jetzt', 'teraz', 'nyni', 'ted',
        'can you', 'could you', 'would you', 'will you', 'can u', 'could u', 'go ahead and', 'i want you to', 'i need you to', 'i would like you to',
        'kannst du', 'konnen sie', 'konntest du', 'konnten sie', 'wurdest du', 'wurden sie', 'kannst du mir', 'konnen sie mir', 'ich mochte dass du', 'ich will dass du',
        'mozes', 'mozete', 'mohol by si', 'mohla by si', 'mohli by ste', 'vies', 'viete', 'chcem aby si', 'potrebujem aby si', 'mi', 'mne',
        'muzes', 'muzete', 'mohl bys', 'mohla bys', 'mohli byste', 'chci abys',
        'and', 'und', 'a',
    ];

    /** First words that ask for information: such a sentence is never treated as a repair request by its first word. */
    private const QUESTION_WORDS = [
        'what', 'which', 'where', 'why', 'how', 'when', 'who', 'whom', 'should', 'shall', 'is', 'are', 'does', 'do', 'did', 'explain', 'tell', 'show', 'list',
        'was', 'welche', 'welcher', 'welches', 'wo', 'warum', 'wieso', 'weshalb', 'wie', 'wann', 'wer', 'sollte', 'soll', 'ist', 'sind', 'erklare', 'erklar', 'zeige', 'zeig',
        'co', 'ktore', 'ktory', 'ktora', 'kde', 'preco', 'ako', 'kedy', 'kto', 'je', 'su', 'vysvetli', 'ukaz', 'mam', 'treba',
        'proc', 'jak', 'kdy', 'ktery', 'jsou', 'vysvetli',
    ];

    /** Words that make the sentence a refusal of work. */
    private const NEGATIONS = ['dont', 'don t', 'do not', 'never', 'nothing', 'nicht', 'nichts', 'niemals', 'nerob', 'nedelej', 'nikdy', 'nic'];

    /**
     * Repair verbs as normalised word prefixes. Checked against the first word after the lead-ins, and anywhere in the
     * sentence when an object of the repair follows (see OBJECT_PATTERN).
     */
    private const VERB_PREFIXES = [
        // English
        'fix', 'repair', 'remediate', 'correct', 'resolve', 'solve',
        // German
        'behebe', 'beheb', 'repariere', 'reparier', 'korrigiere', 'korrigier', 'berichtige', 'berichtig', 'beseitige', 'beseitig', 'lose', 'fixe',
        // Slovak / Czech (oprav, opravte, opravit, opravíš; naprav; vyrieš / vyřeš; uprav; doplň)
        'oprav', 'naprav', 'vyries', 'vyres', 'uprav', 'dopln',
    ];

    /** Verb + object phrases that are repair requests wherever they occur (unless the sentence asks for information). */
    private const OBJECT_PATTERN = '/\b(?:fix|repair|correct|resolve|remediate)\s+(?:it|this|that|them|these|those|everything|all|the\s+(?:issues?|problems?|errors?|findings?|element|page|images?|iframes?|buttons?|links?)|this\s+\w+|all\s+\w+|my\s+\w+)\b'
        . '|\bprepare\s+(?:the\s+|all\s+)?(?:fix|fixes|corrections?|suggestions?|ai\s+fix(?:es)?)\b'
        . '|\b(?:fixes|korrekturen|korrektur|vorschlage|vorschlag)\s+vorbereiten\b'
        . '|\b(?:beheb\w*|reparier\w*|korrigier\w*|beseitig\w*)\s+(?:das|die|den|dies\w*|alle\w*|es|sie|bitte|mir|uns)\b'
        . '|\b(?:das|die|den|dies\w*|alle\w*|es|sie)\s+(?:\w+\s+){0,5}(?:beheben|reparieren|korrigieren|beseitigen)\b'
        . '|\b(?:oprav|naprav|vyries|vyres|uprav|dopln)\w*\b'
        . '|\b(?:priprav|připrav)\w*\s+(?:opravy|navrhy)\b'
        . '|\badd\s+(?:the\s+)?(?:missing\s+)?(?:alt|alternative\s+text|titles?|labels?|names?)\b'
        . '|\b(?:erganze|erganz)\w*\b/u';

    /**
     * @return array{repair:bool,mentions:string,kinds:list<string>}
     */
    public static function detect(string $question): array
    {
        $text = self::normalise($question);
        $result = ['repair' => false, 'mentions' => self::mentions($text), 'kinds' => self::kinds($text)];
        if ($text === '') {
            return $result;
        }
        $padded = ' ' . $text . ' ';
        foreach (self::NEGATIONS as $negation) {
            if (str_contains($padded, ' ' . $negation . ' ')) {
                return $result;
            }
        }
        $rest = self::withoutLeadIns($text);
        $words = explode(' ', $rest);
        $first = $words[0] ?? '';
        if (in_array($first, self::QUESTION_WORDS, true)) {
            return $result;
        }
        foreach (self::VERB_PREFIXES as $prefix) {
            if ($first !== '' && str_starts_with($first, $prefix)) {
                // "fix" also starts "fixed"/"fixes" ("fixes for this?" is a request; "fixed?" alone is a status question).
                if (in_array($first, ['fixed', 'repaired', 'corrected', 'resolved', 'opravene', 'opravena', 'opraveny', 'behoben', 'korrigiert', 'repariert', 'losen'], true)) {
                    return $result;
                }
                $result['repair'] = true;

                return $result;
            }
        }
        $result['repair'] = preg_match(self::OBJECT_PATTERN, $rest) === 1;

        return $result;
    }

    /** Lowercase, without diacritics and punctuation, single spaces. */
    public static function normalise(string $question): string
    {
        $text = mb_strtolower($question);
        $text = strtr($text, [
            'á' => 'a', 'ä' => 'a', 'à' => 'a', 'â' => 'a', 'č' => 'c', 'ç' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'è' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ĺ' => 'l', 'ľ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ò' => 'o', 'ŕ' => 'r', 'ř' => 'r',
            'š' => 's', 'ß' => 'ss', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ù' => 'u', 'ý' => 'y', 'ž' => 'z', '’' => "'",
        ]);
        $text = str_replace("'", ' ', $text);
        $text = (string)preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    private static function withoutLeadIns(string $text): string
    {
        $leadIns = self::LEAD_INS;
        usort($leadIns, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        do {
            $before = $text;
            foreach ($leadIns as $leadIn) {
                if ($text === $leadIn) {
                    return '';
                }
                if (str_starts_with($text, $leadIn . ' ')) {
                    $text = substr($text, strlen($leadIn) + 1);
                    break;
                }
            }
        } while ($text !== $before);

        return $text;
    }

    private static function mentions(string $text): string
    {
        $padded = ' ' . $text . ' ';

        return match (true) {
            preg_match('/ (website|websites|site|whole site|entire site|all pages|every page|webseite|webseiten|website|internetseite|gesamte\w* (seite|website|auftritt)|alle seiten|web|webstranka|webstranku|cely web|celu stranku|celom webe|vsetky stranky|vsechny stranky|cely web) /u', $padded) === 1 => self::MENTION_SITE,
            preg_match('/ (element|elements|content element|this block|block|inhaltselement|inhaltselemente|element|prvok|prvku|obsahovy prvok|obsahovy element|blok|prvek) /u', $padded) === 1 => self::MENTION_ELEMENT,
            preg_match('/ (page|pages|this page|seite|diese seite|stranka|stranku|stranke|strankou|stranky) /u', $padded) === 1 => self::MENTION_PAGE,
            default => '',
        };
    }

    /** @return list<string> */
    private static function kinds(string $text): array
    {
        $padded = ' ' . $text . ' ';
        $kinds = [];
        $patterns = [
            self::KIND_IMAGE => '/ (alt|alts|alt text|alt texts|alternative text|alternativtext\w*|image|images|img|bild|bilder|bildern|obrazok|obrazky|obrazku|obrazkov|obrazek|obrazky) /u',
            self::KIND_IFRAME => '/ (iframe|iframes|embed|embeds|video|videos|frame) /u',
            self::KIND_BUTTON => '/ (button|buttons|schaltflache|schaltflachen|knopf|tlacidlo|tlacidla|tlacidlu|tlacitko|tlacitka) /u',
            self::KIND_LINK => '/ (link|links|link text|linktext\w*|odkaz|odkazy|odkazu) /u',
            self::KIND_HEADING => '/ (heading|headings|uberschrift|uberschriften|nadpis|nadpisy) /u',
        ];
        foreach ($patterns as $kind => $pattern) {
            if (preg_match($pattern, $padded) === 1) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }
}
