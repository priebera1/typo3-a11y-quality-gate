<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Task;

use Priebera\A11yQualityGate\Ai\Service\AiPromptDefinition;

/**
 * A provider-independent request for one structured answer. Instructions are AQG's own, fixed text; everything
 * derived from site content travels in $inputText behind AiPromptDefinition::CONTEXT_WRAPPER_PREFIX and is
 * declared untrusted data. The answer must match $outputSchema exactly (AiStructuredOutputContract).
 */
final readonly class AiStructuredRequest
{
    /**
     * Prefer withUntrustedContext(), which detects a page title itself. A caller using this constructor directly must
     * set $includesPageTitle when $inputText contains a page title, or the site's "Allow page titles" switch cannot
     * refuse the request in AiStructuredTaskRunner.
     *
     * @param array<string,mixed> $outputSchema JSON schema of the answer object (type object, additionalProperties false)
     */
    public function __construct(
        public string $task,
        public string $promptVersion,
        public string $instructions,
        public string $inputText,
        public string $schemaName,
        public array $outputSchema,
        public int $maxOutputTokens,
        public ?AiImageInput $image = null,
        public bool $includesPageTitle = false,
    ) {
        if (!AiTask::isKnown($task)) {
            throw new \InvalidArgumentException('Unknown AI task.');
        }
        if (preg_match('/^[a-z0-9_]{1,64}$/', $schemaName) !== 1) {
            throw new \InvalidArgumentException('Invalid structured-output schema name.');
        }
        if ($maxOutputTokens < 16 || $maxOutputTokens > 4096) {
            throw new \InvalidArgumentException('The output token limit is out of range.');
        }
    }

    /**
     * @param array<string,mixed> $untrustedContext site-derived values; encoded as JSON data, never as instructions.
     *        A "page_title" key marks the request as carrying a page title, which the runner checks against the site.
     * @param array<string,mixed> $outputFormat an AQG structured-output format (type, name, strict, schema)
     */
    public static function withUntrustedContext(
        string $task,
        string $promptVersion,
        string $instructions,
        array $untrustedContext,
        array $outputFormat,
        int $maxOutputTokens,
        ?AiImageInput $image = null,
    ): self {
        try {
            $contextJson = json_encode(
                $untrustedContext,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The AI request context could not be encoded.', 0, $exception);
        }

        return new self(
            task: $task,
            promptVersion: $promptVersion,
            instructions: $instructions,
            inputText: AiPromptDefinition::CONTEXT_WRAPPER_PREFIX . "\n" . $contextJson,
            schemaName: (string)($outputFormat['name'] ?? ''),
            outputSchema: is_array($outputFormat['schema'] ?? null) ? $outputFormat['schema'] : [],
            maxOutputTokens: $maxOutputTokens,
            image: $image,
            includesPageTitle: array_key_exists('page_title', $untrustedContext),
        );
    }
}
