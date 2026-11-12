<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Editor;

use Priebera\A11yQualityGate\Ai\Task\AiTask;

/**
 * The live-check rules an editor AI action exists for, and the one change each may make. A rule that is not listed
 * gets no AI action at all (no wand, no Explain): there is no safe operation for it.
 *
 * Operations are deliberately narrow, matching the Atlas range contract: set one attribute value on the target
 * element, or replace the text of a target element that has no child elements. Heading levels are a rule-based fix
 * (no AI request).
 */
final class EditorAiRuleCatalog
{
    public const KIND_LINK_TEXT = 'link_text';
    public const KIND_IMAGE_ALT = 'image_alt';
    public const KIND_BUTTON_LABEL = 'button_label';
    public const KIND_IFRAME_TITLE = 'iframe_title';
    public const KIND_HEADING_TEXT = 'heading_text';
    public const KIND_HEADING_LEVEL = 'heading_level';

    public const OPERATION_TEXT = 'text';
    public const OPERATION_ATTRIBUTE = 'attribute';
    public const OPERATION_HEADING_LEVEL = 'heading_level';

    /**
     * @var array<string,array{kind:string,tag:string,operation:string,attribute:string,childTag:string}>
     */
    private const RULES = [
        'rte.non_descriptive_link' => ['kind' => self::KIND_LINK_TEXT, 'tag' => 'a', 'operation' => self::OPERATION_TEXT, 'attribute' => '', 'childTag' => ''],
        'rte.link_text_is_url_or_filename' => ['kind' => self::KIND_LINK_TEXT, 'tag' => 'a', 'operation' => self::OPERATION_TEXT, 'attribute' => '', 'childTag' => ''],
        'rte.empty_link' => ['kind' => self::KIND_LINK_TEXT, 'tag' => 'a', 'operation' => self::OPERATION_TEXT, 'attribute' => '', 'childTag' => ''],
        'rte.img_alt_missing' => ['kind' => self::KIND_IMAGE_ALT, 'tag' => 'img', 'operation' => self::OPERATION_ATTRIBUTE, 'attribute' => 'alt', 'childTag' => ''],
        'rte.img_alt_is_filename' => ['kind' => self::KIND_IMAGE_ALT, 'tag' => 'img', 'operation' => self::OPERATION_ATTRIBUTE, 'attribute' => 'alt', 'childTag' => ''],
        'rte.img_alt_redundant_phrase' => ['kind' => self::KIND_IMAGE_ALT, 'tag' => 'img', 'operation' => self::OPERATION_ATTRIBUTE, 'attribute' => 'alt', 'childTag' => ''],
        'rte.img_alt_too_long' => ['kind' => self::KIND_IMAGE_ALT, 'tag' => 'img', 'operation' => self::OPERATION_ATTRIBUTE, 'attribute' => 'alt', 'childTag' => ''],
        // The rule reports the link; the fix is the alt text of its only image, which names the link.
        'rte.image_in_link_missing_alt' => ['kind' => self::KIND_IMAGE_ALT, 'tag' => 'a', 'operation' => self::OPERATION_ATTRIBUTE, 'attribute' => 'alt', 'childTag' => 'img'],
        'rte.button_label_missing' => ['kind' => self::KIND_BUTTON_LABEL, 'tag' => 'button', 'operation' => self::OPERATION_ATTRIBUTE, 'attribute' => 'aria-label', 'childTag' => ''],
        'rte.iframe_missing_title' => ['kind' => self::KIND_IFRAME_TITLE, 'tag' => 'iframe', 'operation' => self::OPERATION_ATTRIBUTE, 'attribute' => 'title', 'childTag' => ''],
        'rte.empty_heading' => ['kind' => self::KIND_HEADING_TEXT, 'tag' => 'h*', 'operation' => self::OPERATION_TEXT, 'attribute' => '', 'childTag' => ''],
        'rte.heading_hierarchy_jump' => ['kind' => self::KIND_HEADING_LEVEL, 'tag' => 'h*', 'operation' => self::OPERATION_HEADING_LEVEL, 'attribute' => '', 'childTag' => ''],
    ];

    /** Value limits per kind: the longest value AQG offers (alt stays below rte.img_alt_too_long's 120). */
    private const MAX_LENGTH = [
        self::KIND_LINK_TEXT => 120,
        self::KIND_IMAGE_ALT => 120,
        self::KIND_BUTTON_LABEL => 80,
        self::KIND_IFRAME_TITLE => 150,
        self::KIND_HEADING_TEXT => 150,
    ];

    public static function supports(string $ruleId): bool
    {
        return isset(self::RULES[$ruleId]);
    }

    /** @return array{kind:string,tag:string,operation:string,attribute:string,childTag:string}|null */
    public static function entry(string $ruleId): ?array
    {
        return self::RULES[$ruleId] ?? null;
    }

    /** @return list<string> */
    public static function ruleIds(): array
    {
        return array_keys(self::RULES);
    }

    /** Whether the fix itself needs an AI request; heading levels are rule-based. */
    public static function hasAiFix(string $ruleId): bool
    {
        $entry = self::entry($ruleId);

        return $entry !== null && $entry['kind'] !== self::KIND_HEADING_LEVEL;
    }

    public static function task(string $kind): string
    {
        return match ($kind) {
            self::KIND_LINK_TEXT => AiTask::LINK_TEXT,
            self::KIND_IMAGE_ALT => AiTask::ALT_TEXT,
            self::KIND_IFRAME_TITLE => AiTask::IFRAME_TITLE,
            self::KIND_BUTTON_LABEL => AiTask::BUTTON_LABEL,
            self::KIND_HEADING_TEXT => AiTask::HEADING_TEXT,
            default => throw new \InvalidArgumentException('No AI fix task for this kind.'),
        };
    }

    public static function maxLength(string $kind): int
    {
        return self::MAX_LENGTH[$kind] ?? 120;
    }
}
