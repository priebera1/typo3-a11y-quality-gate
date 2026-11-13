<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Task;

/**
 * The AI tasks AQG runs. The id is the usage "feature" and the label a request carries in logs; it never contains
 * content.
 */
final class AiTask
{
    public const ALT_TEXT = 'alt_text';
    public const LINK_TEXT = 'link_text';
    public const IFRAME_TITLE = 'iframe_title';
    public const BUTTON_LABEL = 'button_label';
    public const HEADING_TEXT = 'heading_text';
    public const EXPLAIN = 'explain';
    public const WRITING = 'writing';
    public const TRANSLATION = 'translation';
    public const FILE_METADATA = 'file_metadata';
    /** "Ask AQG" in the toolbar: one bounded question about the stored findings of a page or site. */
    public const ASSISTANT = 'assistant';
    public const CONNECTION_TEST = 'connection_test';

    private const ALL = [
        self::ALT_TEXT,
        self::LINK_TEXT,
        self::IFRAME_TITLE,
        self::BUTTON_LABEL,
        self::HEADING_TEXT,
        self::EXPLAIN,
        self::WRITING,
        self::TRANSLATION,
        self::FILE_METADATA,
        self::ASSISTANT,
        self::CONNECTION_TEST,
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return self::ALL;
    }

    public static function isKnown(string $task): bool
    {
        return in_array($task, self::ALL, true);
    }
}
