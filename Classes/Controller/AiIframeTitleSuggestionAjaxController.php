<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Ai\Contract\AiIframeTitleSuggestionServiceInterface;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Exception\AiIframeTitleSuggestionException;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Exception\AiRateLimitException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;

#[AsController]
final class AiIframeTitleSuggestionAjaxController
{
    public function __construct(
        private readonly AiIframeTitleSuggestionServiceInterface $service,
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
                'message' => 'Review the suggested iframe title and update the template, plugin or content source manually. Nothing was changed automatically.',
            ]);
        } catch (AiIframeTitleSuggestionException $exception) {
            return match ($exception->safeCode) {
                'permission_denied' => $this->error('permission_denied', 403),
                'unsupported_rule' => $this->error('unsupported_context', 200),
                'finding_not_found' => $this->error('finding_not_found', 404),
                default => $this->error('unsupported_context', 200),
            };
        } catch (AiConfigurationException $exception) {
            return match ($exception->getCode()) {
                1771002941 => $this->error('ai_iframe_title_disabled', 422),
                1771002001, 1771002002, 1771002003, 1771002004 => $this->error('ai_not_configured', 422),
                1771002940 => $this->error('ai_feature_unavailable', 403),
                default => $this->error('ai_not_configured', 422),
            };
        } catch (AiRateLimitException) {
            return $this->error('rate_limited', 429);
        } catch (AiProviderException) {
            return $this->error('provider_error', 502);
        } catch (\InvalidArgumentException) {
            return $this->error('invalid_input', 422);
        } catch (\Throwable) {
            return $this->error('provider_error', 502);
        }
    }

    private function error(string $code, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'code' => $code,
        ], $status);
    }
}
