<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Media;

use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Service\AiPromptDefinition;
use Priebera\A11yQualityGate\Ai\Task\AiImageInput;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredRequest;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredResponse;

/**
 * Shared request and response shapes of the AI media tasks: the structured request with site content as untrusted
 * context JSON, and the provenance block every suggestion carries (provider, model, prompt version, token usage, what
 * was and was not sent, server time).
 */
final class MediaAiPayload
{
    /** What AI media never sends, listed under "What was sent?". */
    public const NOT_SENT = ['filePath', 'userNames', 'otherFiles'];

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $format
     */
    public static function request(string $task, string $promptVersion, string $instructions, array $context, array $format, int $maxOutputTokens, ?AiImageInput $image = null, bool $includesPageTitle = false): AiStructuredRequest
    {
        return new AiStructuredRequest(
            task: $task,
            promptVersion: $promptVersion,
            instructions: $instructions,
            inputText: AiPromptDefinition::CONTEXT_WRAPPER_PREFIX . "\n" . json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            schemaName: (string)$format['name'],
            outputSchema: $format['schema'],
            maxOutputTokens: $maxOutputTokens,
            image: $image,
            includesPageTitle: $includesPageTitle || array_key_exists('page_title', $context),
        );
    }

    /**
     * @param list<string> $sent
     * @return array<string,mixed>
     */
    public static function provenance(AiStructuredResponse $response, string $promptVersion, array $sent): array
    {
        return [
            'provider' => $response->provider,
            'providerLabel' => AiProviderCatalog::label($response->provider),
            'model' => $response->model,
            // The display name AI settings show ("Claude Haiku 5.5"); the technical id only when AQG does not know it.
            'modelLabel' => (string)((new AiModelCompatibilityRegistry())->find($response->model, $response->provider)['label'] ?? $response->model),
            'promptVersion' => $promptVersion,
            'usage' => [
                'inputTokens' => $response->usage->inputTokens,
                'outputTokens' => $response->usage->outputTokens,
                'reported' => $response->usage->reported,
            ],
            'sent' => $sent,
            'notSent' => self::NOT_SENT,
            'serverTime' => time(),
        ];
    }

    /** Model text shown to the editor as an explanation: plain text, bounded, never markup. */
    public static function plain(mixed $value, int $maxLength): string
    {
        $value = is_string($value) ? $value : '';
        $value = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strip_tags($value)));
        $value = (string)preg_replace('/\s+/u', ' ', $value);

        return mb_substr($value, 0, $maxLength);
    }

    /** A short plain excerpt of site text (element header or body) for the untrusted context. */
    public static function excerpt(mixed $html, int $maxLength): string
    {
        $text = is_string($html) ? html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';

        return self::plain($text, $maxLength);
    }
}
