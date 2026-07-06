<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionRequest;
use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionResult;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Remediation\ImageAltTextValidator;

final class AiAltSuggestionValidator
{
    private const IMAGE_FILENAME_PATTERN = '#^(?:.*[\\\\/])?[^\\\\/\s]+\.(?:jpe?g|png|webp|gif|svg|bmp|tiff?)$#iuD';

    public function __construct(private readonly ImageAltTextValidator $altTextValidator) {}

    public function validate(
        AiAltSuggestionResult $result,
        AiAltSuggestionRequest $request,
    ): AiAltSuggestionResult {
        if ($request->isLinked === true && trim((string)$request->linkPurpose) === '') {
            return new AiAltSuggestionResult(
                status: AiAltSuggestionResult::STATUS_NEEDS_REVIEW,
                suggestion: '',
                provider: $result->provider,
                model: $result->model,
                promptVersion: $result->promptVersion,
            );
        }

        if ($result->status === AiAltSuggestionResult::STATUS_NEEDS_REVIEW) {
            if (trim($result->suggestion) !== '') {
                throw new AiProviderException('The AI provider returned invalid needs_review output.', 1771002601);
            }

            return new AiAltSuggestionResult(
                status: AiAltSuggestionResult::STATUS_NEEDS_REVIEW,
                suggestion: '',
                provider: $result->provider,
                model: $result->model,
                promptVersion: $result->promptVersion,
            );
        }

        if ($result->status !== AiAltSuggestionResult::STATUS_SUGGESTION) {
            throw new AiProviderException('The AI provider returned an unsupported suggestion status.', 1771002602);
        }

        $raw = $result->suggestion;
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $raw) === 1) {
            throw new AiProviderException('The AI provider returned unsafe control characters.', 1771002603);
        }

        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strip_tags($decoded) !== $decoded) {
            throw new AiProviderException('The AI provider returned HTML instead of plain alternative text.', 1771002604);
        }

        $normalized = (string)preg_replace('/\s+/u', ' ', trim($decoded));
        try {
            $normalized = $this->altTextValidator->validate($normalized);
        } catch (\InvalidArgumentException $exception) {
            throw new AiProviderException('The AI provider returned invalid alternative text.', 1771002607, $exception);
        }

        if ($this->isFilenameOrUrl($normalized)) {
            throw new AiProviderException('The AI provider returned a filename or URL instead of useful alternative text.', 1771002605);
        }

        if ($request->findingType === 'quality'
            && is_string($request->currentAlt)
            && $this->normalizedComparable($normalized) === $this->normalizedComparable($request->currentAlt)) {
            throw new AiProviderException('The AI provider repeated the current problematic alternative text.', 1771002606);
        }

        return new AiAltSuggestionResult(
            status: AiAltSuggestionResult::STATUS_SUGGESTION,
            suggestion: $normalized,
            provider: $result->provider,
            model: $result->model,
            promptVersion: $result->promptVersion,
        );
    }

    private function isFilenameOrUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return true;
        }

        $match = preg_match(self::IMAGE_FILENAME_PATTERN, $value);
        if ($match === false) {
            throw new \LogicException('Unable to validate an AI suggestion against the filename pattern.', 1771002608);
        }

        return $match === 1;
    }

    private function normalizedComparable(string $value): string
    {
        return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $value)));
    }
}
