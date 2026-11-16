<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp\Remediation;

/**
 * How the approval page shows one exact agent change (remediations:apply-approved, post-3.0.0): the changed part of the
 * stored value BEFORE and AFTER, with the element around it, so the person sees what changes without searching a whole
 * rich-text field. Display only: it never decides what is written (that is the binding of the complete values).
 *
 * The parts are plain strings (escaped by Fluid); the page marks `removed` and `added` with <del>/<ins>.
 */
final class McpChangePresentation
{
    /** Characters of surrounding text shown on each side of a change outside a tag. */
    private const CONTEXT = 80;
    /** The longest tag shown whole; a longer one is cut to CONTEXT characters on each side. */
    private const TAG_MAX = 400;

    /**
     * @return array{lead: string, removed: string, added: string, trail: string, leadCut: bool, trailCut: bool, attribute: string, inTag: bool}
     */
    public static function change(string $before, string $after): array
    {
        $beforeLength = strlen($before);
        $afterLength = strlen($after);
        $shorter = min($beforeLength, $afterLength);

        $prefix = 0;
        while ($prefix < $shorter && $before[$prefix] === $after[$prefix]) {
            $prefix++;
        }
        // Never split a UTF-8 character: step back to the start of the character the difference falls into.
        while ($prefix > 0 && self::isContinuationByte($before, $prefix)) {
            $prefix--;
        }
        $suffix = 0;
        while ($suffix < $shorter - $prefix && $before[$beforeLength - 1 - $suffix] === $after[$afterLength - 1 - $suffix]) {
            $suffix++;
        }
        while ($suffix > 0 && self::isContinuationByte($before, $beforeLength - $suffix)) {
            $suffix--;
        }

        $head = substr($before, 0, $prefix);
        $tail = substr($before, $beforeLength - $suffix);
        $removed = substr($before, $prefix, $beforeLength - $suffix - $prefix);
        $added = substr($after, $prefix, $afterLength - $suffix - $prefix);

        // Inside a tag (an attribute added or changed): show that whole tag, before and after.
        $tagStart = strrpos($head, '<');
        $inTag = $tagStart !== false && !str_contains(substr($head, $tagStart), '>');
        $tagEnd = $inTag ? strpos($tail, '>') : false;
        if ($inTag && $tagEnd !== false && ($prefix - $tagStart) + $tagEnd + 1 <= self::TAG_MAX) {
            $lead = substr($head, $tagStart);
            $trail = substr($tail, 0, $tagEnd + 1);

            return [
                'lead' => $lead, 'removed' => $removed, 'added' => $added, 'trail' => $trail,
                'leadCut' => $tagStart > 0, 'trailCut' => $tagEnd + 1 < strlen($tail),
                'attribute' => self::attribute($lead, $added), 'inTag' => true,
            ];
        }

        $leadCut = mb_strlen($head) > self::CONTEXT;
        $trailCut = mb_strlen($tail) > self::CONTEXT;

        return [
            'lead' => $leadCut ? mb_substr($head, -self::CONTEXT) : $head,
            'removed' => $removed,
            'added' => $added,
            'trail' => $trailCut ? mb_substr($tail, 0, self::CONTEXT) : $tail,
            'leadCut' => $leadCut,
            'trailCut' => $trailCut,
            'attribute' => $inTag ? self::attribute($head, $added) : '',
            'inTag' => $inTag,
        ];
    }

    /** The attribute whose value changes: the one the change falls into, or the one it adds. */
    private static function attribute(string $lead, string $added): string
    {
        if (preg_match('/([A-Za-z_:][-\w:.]*)\s*=\s*"[^"]*$/', $lead, $match) === 1) {
            return strtolower($match[1]);
        }
        if (preg_match('/^\s*([A-Za-z_:][-\w:.]*)\s*=/', $added, $match) === 1) {
            return strtolower($match[1]);
        }

        return '';
    }

    private static function isContinuationByte(string $value, int $offset): bool
    {
        return $offset < strlen($value) && (ord($value[$offset]) & 0xC0) === 0x80;
    }
}
