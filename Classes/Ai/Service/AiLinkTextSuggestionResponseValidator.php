<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Dto\AiLinkTextSuggestionRequest;
use Priebera\A11yQualityGate\Ai\Dto\AiLinkTextSuggestionResult;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Service\PhraseMatcher;

final class AiLinkTextSuggestionResponseValidator
{
    private const MAX_SUGGESTION_LENGTH = 120;
    private const MAX_REASON_LENGTH = 280;

    private const NON_DESCRIPTIVE_TEXTS = [
        'click',
        'click here',
        'here',
        'read more',
        'more',
        'learn more',
        'show more',
        'see more',
        'details',
        'this link',
        'link',
        'download',
        'open',
        'go',
    ];

    public function validate(
        AiLinkTextSuggestionResult $result,
        AiLinkTextSuggestionRequest $request,
    ): AiLinkTextSuggestionResult {
        $status = trim($result->status);
        if (!in_array($status, [
            AiLinkTextSuggestionResult::STATUS_SUGGESTION,
            AiLinkTextSuggestionResult::STATUS_NEEDS_REVIEW,
            AiLinkTextSuggestionResult::STATUS_UNSUPPORTED_CONTEXT,
            AiLinkTextSuggestionResult::STATUS_REFUSAL,
        ], true)) {
            throw new AiProviderException('The AI provider returned an unsupported link-text suggestion status.', 1771002810);
        }

        if (trim($result->suggestedLinkText) !== '' && $this->containsUnsafePlainText($result->suggestedLinkText)) {
            throw new AiProviderException('The AI provider returned an unsafe link-text suggestion.', 1771002811);
        }

        if (trim($result->reason) !== '' && $this->containsUnsafePlainText($result->reason)) {
            throw new AiProviderException('The AI provider returned an unsafe link-text suggestion reason.', 1771002812);
        }

        $suggestion = $this->normalizeText($result->suggestedLinkText, self::MAX_SUGGESTION_LENGTH + 40);
        $reason = $this->normalizeText($result->reason, self::MAX_REASON_LENGTH);
        if ($reason === '') {
            $reason = $status === AiLinkTextSuggestionResult::STATUS_SUGGESTION
                ? 'Review the suggested link text before using it.'
                : 'AQG could not generate a reliable suggestion for this link.';
        }

        if ($status !== AiLinkTextSuggestionResult::STATUS_SUGGESTION) {
            return new AiLinkTextSuggestionResult(
                status: $status,
                suggestedLinkText: '',
                reason: $reason,
                needsReview: true,
                provider: $result->provider,
                model: $result->model,
                promptVersion: $result->promptVersion,
            );
        }

        if ($suggestion === ''
            || mb_strlen($suggestion) < 3
            || mb_strlen($suggestion) > self::MAX_SUGGESTION_LENGTH
            || $this->containsUnsafePlainText($suggestion)
            || $this->isUrlLike($suggestion)
            || $this->sameNormalizedText($suggestion, $request->currentLinkText)
            || $this->isKnownNonDescriptiveText($suggestion)) {
            throw new AiProviderException('The AI provider returned an unsafe link-text suggestion.', 1771002811);
        }

        return new AiLinkTextSuggestionResult(
            status: AiLinkTextSuggestionResult::STATUS_SUGGESTION,
            suggestedLinkText: $suggestion,
            reason: $reason,
            needsReview: true,
            provider: $result->provider,
            model: $result->model,
            promptVersion: $result->promptVersion,
        );
    }

    private function normalizeText(string $value, int $maxLength): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));

        return mb_strlen($value) <= $maxLength ? $value : mb_substr($value, 0, $maxLength);
    }

    private function containsUnsafePlainText(string $value): bool
    {
        $value = trim($value);
        $decodedValue = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_match('/\R/u', $value) === 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1
            || preg_match('/[<>]/u', $decodedValue) === 1;
    }

    private function isUrlLike(string $value): bool
    {
        return preg_match('~^(?:https?://|www\.|mailto:|tel:|/|#)~i', trim($value)) === 1;
    }

    private function sameNormalizedText(string $left, string $right): bool
    {
        return PhraseMatcher::normalize($left) === PhraseMatcher::normalize($right);
    }

    private function isKnownNonDescriptiveText(string $value): bool
    {
        $normalized = PhraseMatcher::normalize($value);

        return in_array($normalized, self::NON_DESCRIPTIVE_TEXTS, true);
    }
}
