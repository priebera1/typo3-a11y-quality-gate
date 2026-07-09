<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Exception\AiLinkTextSuggestionException;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Exception\AiRateLimitException;
use Priebera\A11yQualityGate\Ai\Contract\AiLinkTextSuggestionServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;

#[AsController]
final class AiLinkTextSuggestionAjaxController
{
    public function __construct(
        private readonly AiLinkTextSuggestionServiceInterface $service,
    ) {}

    public function suggestAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $findingId = (int)($body['findingId'] ?? 0);
        if ($findingId <= 0) {
            return $this->error('invalid_input', 422);
        }

        try {
            $result = $this->service->suggest($findingId);

            return new JsonResponse([
                'success' => true,
                'code' => $result->status,
                ...$result->toResponsePayload(),
                'reviewOnly' => true,
                'message' => 'Review the suggested link text and update the RTE content manually. Nothing was changed automatically.',
            ]);
        } catch (AiLinkTextSuggestionException $exception) {
            return match ($exception->safeCode) {
                'permission_denied' => $this->error('permission_denied', 403),
                'unsupported_rule' => $this->error('unsupported_context', 200),
                'finding_not_found' => $this->error('finding_not_found', 404),
                default => $this->error('unsupported_context', 200),
            };
        } catch (AiConfigurationException $exception) {
            return match ($exception->getCode()) {
                1771002841 => $this->error('ai_link_text_disabled', 422),
                1771002001, 1771002002, 1771002003, 1771002004 => $this->error('ai_not_configured', 422),
                1771002840 => $this->error('ai_feature_unavailable', 403),
                default => $this->error('ai_not_configured', 422),
            };
        } catch (AiRateLimitException) {
            return $this->error('rate_limited', 429);
        } catch (AiProviderException) {
            return $this->error('provider_error', 200);
        } catch (\InvalidArgumentException) {
            return $this->error('invalid_input', 422);
        } catch (\Throwable) {
            return $this->error('unsupported_context', 200);
        }
    }

    private function error(string $code, int $status): JsonResponse
    {
        $payload = [
            'success' => false,
            'code' => $code,
            'status' => $code,
            'suggestedLinkText' => '',
            'reason' => $this->safeReasonForCode($code),
            'needsReview' => true,
            'reviewOnly' => true,
        ];

        return new JsonResponse($payload, $status);
    }

    private function safeReasonForCode(string $code): string
    {
        return match ($code) {
            'unsupported_context', 'unsupported_rule', 'finding_not_found' => 'AQG could not safely identify one exact link in this finding.',
            'permission_denied' => 'You do not have permission to request an AI suggestion for this finding.',
            'ai_link_text_disabled' => 'AI link-text suggestions are disabled for this site.',
            'ai_not_configured' => 'AI link-text suggestions are not configured for this site.',
            'ai_feature_unavailable' => 'AI is not available for this site.',
            'rate_limited' => 'Please wait before requesting another AI suggestion.',
            'provider_error' => 'The AI provider could not return a safe link-text suggestion.',
            default => 'The AI link-text suggestion request could not be handled safely.',
        };
    }
}
