<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Ai\Contract\AiAltSuggestionServiceInterface;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Exception\AiImagePayloadException;
use Priebera\A11yQualityGate\Ai\Exception\AiRateLimitException;
use Priebera\A11yQualityGate\Remediation\ImageRemediationPermissionException;
use Priebera\A11yQualityGate\Remediation\StaleImageFindingException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsController]
final class AiAltSuggestionAjaxController
{
    public function __construct(private readonly AiAltSuggestionServiceInterface $service) {}

    public function suggestAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        try {
            $suggestion = $this->service->suggest((int)($body['findingId'] ?? 0));
            return new JsonResponse([
                'success' => true,
                'status' => $suggestion['result']->status,
                'suggestion' => $suggestion['result']->suggestion,
                'expectedVersion' => $suggestion['version'],
                'provider' => $suggestion['result']->provider,
                'model' => $suggestion['result']->model,
            ]);
        } catch (StaleImageFindingException) {
            return $this->error('stale_finding', 409);
        } catch (AiRateLimitException $exception) {
            return $this->error('rate_limited', 429)
                ->withHeader('Retry-After', (string)$exception->retryAfter);
        } catch (ImageRemediationPermissionException) {
            return $this->error('permission_denied', 403);
        } catch (AiConfigurationException) {
            return $this->error('ai_unavailable', 403);
        } catch (\InvalidArgumentException) {
            return $this->error('invalid_input', 422);
        } catch (AiImagePayloadException $exception) {
            $this->logImagePayloadFailure((int)($body['findingId'] ?? 0), $exception);
            return $this->error('image_unavailable', 422);
        } catch (AiProviderException) {
            return $this->error('provider_failure', 502);
        } catch (\Throwable) {
            return $this->error('internal_error', 500);
        }
    }

    private function logImagePayloadFailure(int $findingUid, AiImagePayloadException $exception): void
    {
        $previous = $exception->getPrevious();
        $context = array_merge([
            'findingUid' => $findingUid,
            'exceptionCode' => $exception->getCode(),
            'exceptionClass' => $exception::class,
            'previousExceptionClass' => $previous !== null ? $previous::class : null,
        ], $exception->diagnostics());

        try {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(self::class)
                ->warning('AQG could not prepare an image payload for an AI alt-text suggestion.', $context);
        } catch (\Throwable) {
            // Logging must never change the safe client response.
        }
    }

    private function error(string $code, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'code' => $code], $status);
    }
}
