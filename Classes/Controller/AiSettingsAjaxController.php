<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationManagerInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiProviderInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Exception\AiModelDiscoveryException;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Contract\AiModelDiscoveryServiceInterface;
use Priebera\A11yQualityGate\Ai\Service\AiPromptDefinition;
use Priebera\A11yQualityGate\Ai\Service\AiSettingsUiStateBuilder;
use Priebera\A11yQualityGate\Contract\AccessControlServiceInterface;
use Priebera\A11yQualityGate\Contract\BackendContextServiceInterface;
use Priebera\A11yQualityGate\Contract\SiteResolutionServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;

#[AsController]
final class AiSettingsAjaxController
{
    /** @param iterable<AiProviderInterface> $providers */
    public function __construct(
        private readonly AccessControlServiceInterface $accessControlService,
        private readonly BackendContextServiceInterface $backendContextService,
        private readonly AiConfigurationManagerInterface $manager,
        private readonly AiConfigurationResolverInterface $resolver,
        private readonly AiSettingsUiStateBuilder $uiStateBuilder,
        private readonly AiConfigurationRepositoryInterface $repository,
        private readonly SiteResolutionServiceInterface $siteResolutionService,
        private readonly AiModelDiscoveryServiceInterface $modelDiscoveryService,
        private readonly iterable $providers,
    ) {}

    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->error('permission_denied', 403, $this->siteIdentifier($request));
        }

        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $siteIdentifier = trim((string)($body['siteIdentifier'] ?? ''));
        try {
            $this->manager->save($siteIdentifier, (string)($body['apiKey'] ?? ''));

            try {
                $discovery = $this->modelDiscoveryService->discover($siteIdentifier);
                // A saved key ends in a visible state: connected or the reason it is not, or "choose a model" when
                // several compatible models need the administrator's choice.
                try {
                    $connectionCode = $discovery['selectedModelId'] !== ''
                        ? $this->verifyConnection($siteIdentifier)[0]
                        : 'model_not_selected';
                } catch (\Throwable) {
                    // Verification never turns a saved key into a failed save.
                    $connectionCode = 'connection_test_failed';
                }

                return $this->success('configuration_saved', $siteIdentifier, [
                    'selectedModelId' => $discovery['selectedModelId'],
                    'connectionCode' => $connectionCode,
                ]);
            } catch (AiModelDiscoveryException $exception) {
                return $this->success('configuration_saved', $siteIdentifier, [
                    'warningCode' => $exception->safeCode,
                ]);
            }
        } catch (\InvalidArgumentException) {
            return $this->error('invalid_configuration', 422, $siteIdentifier);
        } catch (\Throwable) {
            return $this->error('configuration_save_failed', 500, $siteIdentifier);
        }
    }

    public function refreshModelsAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->error('permission_denied', 403, $this->siteIdentifier($request));
        }

        $siteIdentifier = $this->siteIdentifier($request);
        if (!$this->siteExists($siteIdentifier)) {
            return $this->error('site_not_found', 422, $siteIdentifier);
        }

        try {
            $discovery = $this->modelDiscoveryService->discover($siteIdentifier);
            return $this->success('models_refreshed', $siteIdentifier, [
                'selectedModelId' => $discovery['selectedModelId'],
                'modelCount' => count($discovery['supported']),
            ]);
        } catch (AiModelDiscoveryException $exception) {
            return $this->mapDiscoveryFailure($exception, $siteIdentifier);
        } catch (\Throwable) {
            return $this->error('models_request_failed', 502, $siteIdentifier);
        }
    }

    public function selectModelAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->error('permission_denied', 403, $this->siteIdentifier($request));
        }

        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        try {
            $this->manager->selectModel(
                trim((string)($body['siteIdentifier'] ?? '')),
                trim((string)($body['modelId'] ?? '')),
            );

            return $this->success('model_selected', trim((string)($body['siteIdentifier'] ?? '')));
        } catch (\InvalidArgumentException) {
            return $this->error('invalid_model_selection', 422, trim((string)($body['siteIdentifier'] ?? '')));
        } catch (\Throwable) {
            return $this->error('model_selection_failed', 500, trim((string)($body['siteIdentifier'] ?? '')));
        }
    }


    public function toggleLinkTextSuggestionsAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->error('permission_denied', 403, $this->siteIdentifier($request));
        }

        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $siteIdentifier = trim((string)($body['siteIdentifier'] ?? ''));
        $enabled = (int)($body['enabled'] ?? 0) === 1;
        try {
            $this->manager->setLinkTextSuggestionsEnabled($siteIdentifier, $enabled);

            return $this->success($enabled ? 'link_text_suggestions_enabled' : 'link_text_suggestions_disabled', $siteIdentifier);
        } catch (\InvalidArgumentException) {
            return $this->error('invalid_configuration', 422, $siteIdentifier);
        } catch (\Throwable) {
            return $this->error('configuration_save_failed', 500, $siteIdentifier);
        }
    }

    public function testAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->error('permission_denied', 403, $this->siteIdentifier($request));
        }

        $siteIdentifier = $this->siteIdentifier($request);
        if (!$this->siteExists($siteIdentifier)) {
            return $this->error('site_not_found', 422, $siteIdentifier);
        }

        [$code, $httpStatus] = $this->verifyConnection($siteIdentifier);

        return $httpStatus === 200
            ? $this->success($code, $siteIdentifier)
            : $this->error($code, $httpStatus, $siteIdentifier);
    }

    /**
     * Runs the connection contract test for the site's saved key and selected model and records the result.
     *
     * @return array{0: string, 1: int} safe result code and HTTP status
     */
    private function verifyConnection(string $siteIdentifier): array
    {
        $configuration = null;
        try {
            $this->modelDiscoveryService->ensureFresh($siteIdentifier);
            $configuration = $this->resolver->resolve($siteIdentifier);
            foreach ($this->providers as $provider) {
                if (!$provider->supports($configuration->provider)) {
                    continue;
                }

                $provider->testConnection($configuration);
                $this->markTested($siteIdentifier, true, $configuration);

                return ['connection_successful', 200];
            }

            $this->markTested($siteIdentifier, false, $configuration, 'provider_unavailable');
            return ['provider_unavailable', 502];
        } catch (AiModelDiscoveryException $exception) {
            return $this->discoveryFailure($exception);
        } catch (AiConfigurationException $exception) {
            return match ($exception->getCode()) {
                1771002003 => ['model_not_selected', 422],
                1771002004 => ['model_unavailable', 422],
                default => ['invalid_configuration', 422],
            };
        } catch (\InvalidArgumentException) {
            return ['invalid_configuration', 422];
        } catch (AiProviderException $exception) {
            if ($configuration instanceof AiProviderConfiguration) {
                $this->markTested(
                    $siteIdentifier,
                    false,
                    $configuration,
                    $this->safeProviderErrorCode($exception),
                );
            }
            return $this->providerFailure($exception);
        } catch (\Throwable) {
            if ($configuration instanceof AiProviderConfiguration) {
                $this->markTested($siteIdentifier, false, $configuration, 'connection_test_failed');
            }
            return ['connection_test_failed', 502];
        }
    }

    private function markTested(
        string $siteIdentifier,
        bool $verified,
        AiProviderConfiguration $configuration,
        string $safeErrorCode = '',
    ): void {
        $this->repository->markTested(
            $siteIdentifier,
            $verified,
            $configuration->keyFingerprint,
            $configuration->model,
            AiPromptDefinition::AI_PROMPT_VERSION,
            AiPromptDefinition::CONNECTION_TEST_VERSION,
            $safeErrorCode,
        );
    }

    /** @return array{0: string, 1: int} */
    private function providerFailure(AiProviderException $exception): array
    {
        return match ($exception->getCode()) {
            1771002310 => ['api_key_rejected', 401],
            1771002320 => ['insufficient_quota', 429],
            1771002311 => ['connection_rate_limited', 429],
            1771002317 => ['model_unavailable', 422],
            1771002321 => ['model_not_permitted', 403],
            1771002322 => ['invalid_provider_request', 422],
            1771002302 => ['transport_failure', 502],
            1771002312 => ['openai_service_failure', 502],
            1771002301,
            1771002304,
            1771002305,
            1771002313,
            1771002314,
            1771002315,
            1771002316,
            1771002318 => ['structured_output_test_failed', 502],
            default => ['connection_test_failed', 502],
        };
    }

    private function safeProviderErrorCode(AiProviderException $exception): string
    {
        return match ($exception->getCode()) {
            1771002310 => 'api_key_rejected',
            1771002320 => 'insufficient_quota',
            1771002311 => 'rate_limited',
            1771002317 => 'model_unavailable',
            1771002321 => 'model_not_permitted',
            1771002322 => 'invalid_request',
            1771002302 => 'transport_failure',
            1771002312 => 'openai_service_failure',
            1771002301,
            1771002304,
            1771002305,
            1771002313,
            1771002314,
            1771002315,
            1771002316,
            1771002318 => 'structured_output_test_failed',
            default => 'connection_test_failed',
        };
    }

    private function mapDiscoveryFailure(AiModelDiscoveryException $exception, string $siteIdentifier): JsonResponse
    {
        [$code, $httpStatus] = $this->discoveryFailure($exception);

        return $this->error($code, $httpStatus, $siteIdentifier);
    }

    /** @return array{0: string, 1: int} */
    private function discoveryFailure(AiModelDiscoveryException $exception): array
    {
        return match ($exception->safeCode) {
            'api_key_rejected' => ['api_key_rejected', 401],
            'models_permission_denied' => ['models_permission_denied', 403],
            'insufficient_quota' => ['insufficient_quota', 429],
            'models_rate_limited' => ['models_rate_limited', 429],
            'no_supported_models' => ['no_supported_models', 422],
            'models_invalid_response' => ['models_invalid_response', 502],
            'openai_service_failure' => ['openai_service_failure', 502],
            'models_transport_failure' => ['transport_failure', 502],
            default => ['models_request_failed', 502],
        };
    }

    private function siteIdentifier(ServerRequestInterface $request): string
    {
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];

        return trim((string)($body['siteIdentifier'] ?? ''));
    }

    private function siteExists(string $siteIdentifier): bool
    {
        return $siteIdentifier !== ''
            && $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier) !== null;
    }

    private function isAdmin(): bool
    {
        return $this->accessControlService->canManageAdminOnlySettings(
            $this->backendContextService->getBackendUser(),
        );
    }

    /** @param array<string,mixed> $extra */
    private function success(string $code, string $siteIdentifier, array $extra = []): JsonResponse
    {
        return $this->response(true, $code, 200, $siteIdentifier, $extra);
    }

    private function error(string $code, int $status, string $siteIdentifier = ''): JsonResponse
    {
        return $this->response(false, $code, $status, $siteIdentifier);
    }

    /** @param array<string,mixed> $extra */
    private function response(
        bool $success,
        string $code,
        int $httpStatus,
        string $siteIdentifier,
        array $extra = [],
    ): JsonResponse {
        $uiState = $this->safeUiState($siteIdentifier);
        $uiState['responseStatus'] = $httpStatus;

        return new JsonResponse([
            'success' => $success,
            'code' => $code,
            ...$extra,
            'uiState' => $uiState,
        ], $httpStatus);
    }

    /** @return array<string,mixed> */
    private function safeUiState(string $siteIdentifier): array
    {
        try {
            return $this->uiStateBuilder->build($siteIdentifier);
        } catch (\Throwable) {
            return [
                'configured' => false,
                'modelSelected' => false,
                'selectedModelAvailable' => false,
                'status' => 'not_configured',
                'errorCode' => '',
                'lastTestedAt' => 0,
                'lastVerifiedAt' => 0,
                'availableModels' => [],
                'unsupportedModels' => [],
                'linkTextSuggestionsEnabled' => false,
                'actions' => [
                    'refreshModelsEnabled' => false,
                    'testConnectionEnabled' => false,
                ],
            ];
        }
    }
}
