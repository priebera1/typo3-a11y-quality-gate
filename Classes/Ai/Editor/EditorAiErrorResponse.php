<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Editor;

use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Exception\AiRateLimitException;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * The stable error answers of an editor AI request (CKEditor, Plain HTML and Page Detail "Explain with AI"): a code
 * the browser maps to its own copy. Provider messages, keys and content never reach the browser.
 */
final class EditorAiErrorResponse
{
    /** The answer for $exception, or null when it is not an AI request error (the caller logs it as internal). */
    public static function fromThrowable(\Throwable $exception, bool $isAdmin): ?JsonResponse
    {
        return match (true) {
            $exception instanceof EditorAiException => self::error(
                $exception->safeCode,
                $exception->httpStatus,
                $exception->safeCode === 'manual_only' ? ['manualReason' => $exception->getMessage()] : []
            ),
            $exception instanceof AiRateLimitException => self::error('rate_limited', 429, ['retryAfter' => max(1, $exception->retryAfter)]),
            $exception instanceof AiConfigurationException => match ($exception->getCode()) {
                1771002403 => self::error('privacy_images', 422),
                1771002405 => self::error('privacy_page_title', 422),
                1771002404 => self::error(EditorAiAccessService::STATE_NO_LICENCE, 403),
                // The attempt could not be recorded before the request, so it was not sent (fail closed).
                AiUsageRecorder::CODE_USAGE_UNAVAILABLE => self::error('usage_unavailable', 503),
                // Readiness and budget refusals (also from the locked budget check right before the request).
                default => self::error(EditorAiAccessService::stateForConfigurationCode((int)$exception->getCode()), 422),
            },
            $exception instanceof AiProviderException => self::error(
                self::providerCode((int)$exception->getCode()),
                502,
                ['providerCode' => $isAdmin ? (int)$exception->getCode() : 0]
            ),
            $exception instanceof \InvalidArgumentException => self::error('invalid_input', 422),
            default => null,
        };
    }

    /** The shared AQG provider error codes (OpenAI and Anthropic map to the same ones). */
    public static function providerCode(int $code): string
    {
        return match ($code) {
            1771002313 => 'refused',
            1771002302 => 'provider_timeout',
            1771002311 => 'provider_rate_limited',
            1771002320, 1771002323 => 'provider_credit',
            1771002310, 1771002317, 1771002321 => 'paused',
            1771002301, 1771002304, 1771002305, 1771002314, 1771002315, 1771002316, 1771002318 => 'invalid_response',
            default => 'provider_unavailable',
        };
    }

    /** @param array<string,mixed> $extra */
    public static function error(string $code, int $status, array $extra = []): JsonResponse
    {
        return new JsonResponse(['success' => false, 'status' => 'error', 'code' => $code] + $extra, $status);
    }
}
