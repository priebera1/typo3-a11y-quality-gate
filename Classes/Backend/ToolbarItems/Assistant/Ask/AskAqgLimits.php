<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask;

/**
 * The one set of "Ask AQG" limits. The server enforces every value; the toolbar receives the same values (toArray())
 * so the UI can never promise more than the server accepts.
 */
final class AskAqgLimits
{
    /** Characters of one question. */
    public const MAX_QUESTION_CHARS = 400;
    public const MIN_QUESTION_CHARS = 3;
    /** Characters of one answer shown to the editor. */
    public const MAX_ANSWER_CHARS = 1600;
    /** Earlier turns the browser may send back (it keeps the history; the server stores none). */
    public const MAX_HISTORY_TURNS = 3;
    /** Characters of one earlier answer in the history. */
    public const MAX_HISTORY_ANSWER_CHARS = 700;
    /** Characters of the whole history (questions + answers). */
    public const MAX_HISTORY_CHARS = 2400;
    /** Provider requests per question: one structured request, no tool loop, no retry, no model fallback. */
    public const PROVIDER_REQUESTS_PER_QUESTION = 1;
    /** Seconds one question may take: the provider adapters' own request timeouts (OpenAI 30 s, Anthropic 45 s). */
    public const MAX_SECONDS_PER_QUESTION = 45;
    /** AI questions per conversation; "New conversation" starts a new one. */
    public const MAX_AI_QUESTIONS_PER_CONVERSATION = 10;
    /** AI questions per backend user and site in 24 hours, across all conversations (the hard per-user limit). */
    public const MAX_AI_QUESTIONS_PER_USER_PER_DAY = 60;
    /** Estimated AI spend per conversation (micro-USD), summed from the site's own usage rows. */
    public const MAX_CONVERSATION_COST_MICRO_USD = 50_000;
    /** A conversation id is valid for this long. */
    public const CONVERSATION_TTL_SECONDS = 8 * 3600;
    /** Output tokens of one answer. */
    public const MAX_OUTPUT_TOKENS = 700;

    /** @return array<string, int> */
    public static function toArray(): array
    {
        return [
            'maxQuestionChars' => self::MAX_QUESTION_CHARS,
            'minQuestionChars' => self::MIN_QUESTION_CHARS,
            'maxAnswerChars' => self::MAX_ANSWER_CHARS,
            'maxHistoryTurns' => self::MAX_HISTORY_TURNS,
            'maxHistoryAnswerChars' => self::MAX_HISTORY_ANSWER_CHARS,
            'maxHistoryChars' => self::MAX_HISTORY_CHARS,
            'providerRequestsPerQuestion' => self::PROVIDER_REQUESTS_PER_QUESTION,
            'maxSecondsPerQuestion' => self::MAX_SECONDS_PER_QUESTION,
            'maxAiQuestionsPerConversation' => self::MAX_AI_QUESTIONS_PER_CONVERSATION,
            'maxConversationCostMicroUsd' => self::MAX_CONVERSATION_COST_MICRO_USD,
            'maxAiQuestionsPerUserPerDay' => self::MAX_AI_QUESTIONS_PER_USER_PER_DAY,
        ];
    }
}
