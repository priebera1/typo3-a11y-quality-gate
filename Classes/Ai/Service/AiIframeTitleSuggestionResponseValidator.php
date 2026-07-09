<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Dto\AiIframeTitleSuggestionRequest;
use Priebera\A11yQualityGate\Ai\Dto\AiIframeTitleSuggestionResult;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Service\PhraseMatcher;

final class AiIframeTitleSuggestionResponseValidator
{
    private const MAX_TITLE_LENGTH = 80;
    private const MAX_REASON_LENGTH = 280;

    private const GENERIC_TITLES = [
        'iframe',
        'frame',
        'embedded content',
        'content',
        'external content',
        'embed',
    ];

    public function validate(
        AiIframeTitleSuggestionResult $result,
        AiIframeTitleSuggestionRequest $request,
    ): AiIframeTitleSuggestionResult {
        $status = trim($result->status);
        if (!in_array($status, [
            AiIframeTitleSuggestionResult::STATUS_SUGGESTION,
            AiIframeTitleSuggestionResult::STATUS_NEEDS_REVIEW,
            AiIframeTitleSuggestionResult::STATUS_UNSUPPORTED_CONTEXT,
            AiIframeTitleSuggestionResult::STATUS_REFUSAL,
        ], true)) {
            throw new AiProviderException('The AI provider returned an unsupported iframe-title suggestion status.', 1771002910);
        }

        if (trim($result->suggestedIframeTitle) !== '' && $this->containsUnsafePlainText($result->suggestedIframeTitle)) {
            throw new AiProviderException('The AI provider returned an unsafe iframe-title suggestion.', 1771002911);
        }

        if (trim($result->reason) !== '' && $this->containsUnsafePlainText($result->reason)) {
            throw new AiProviderException('The AI provider returned an unsafe iframe-title suggestion reason.', 1771002912);
        }

        $title = $this->normalizeText($result->suggestedIframeTitle, self::MAX_TITLE_LENGTH + 40);
        $reason = $this->normalizeText($result->reason, self::MAX_REASON_LENGTH);
        if ($reason === '') {
            $reason = $status === AiIframeTitleSuggestionResult::STATUS_SUGGESTION
                ? 'Review the suggested iframe title before using it.'
                : 'AQG could not generate a reliable title for this iframe.';
        }

        if ($status !== AiIframeTitleSuggestionResult::STATUS_SUGGESTION) {
            return new AiIframeTitleSuggestionResult(
                status: $status,
                suggestedIframeTitle: '',
                reason: $reason,
                needsReview: true,
                provider: $result->provider,
                model: $result->model,
                promptVersion: $result->promptVersion,
            );
        }

        if ($title === ''
            || mb_strlen($title) < 3
            || mb_strlen($title) > self::MAX_TITLE_LENGTH
            || $this->containsUnsafePlainText($title)
            || $this->isUrlLike($title)
            || $this->isKnownGenericTitle($title)
            || $this->sameNormalizedText($title, $request->iframeSrc)) {
            throw new AiProviderException('The AI provider returned an unsafe iframe-title suggestion.', 1771002911);
        }

        return new AiIframeTitleSuggestionResult(
            status: AiIframeTitleSuggestionResult::STATUS_SUGGESTION,
            suggestedIframeTitle: $title,
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

    private function isKnownGenericTitle(string $value): bool
    {
        $normalized = PhraseMatcher::normalize($value);

        return in_array($normalized, self::GENERIC_TITLES, true);
    }
}
