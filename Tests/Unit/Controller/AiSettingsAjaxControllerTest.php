<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationManagerInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiModelDiscoveryServiceInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiProviderInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Exception\AiModelDiscoveryException;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Service\AiPromptDefinition;
use Priebera\A11yQualityGate\Ai\Service\AiSettingsUiStateBuilder;
use Priebera\A11yQualityGate\Contract\AccessControlServiceInterface;
use Priebera\A11yQualityGate\Contract\BackendContextServiceInterface;
use Priebera\A11yQualityGate\Contract\SiteResolutionServiceInterface;
use Priebera\A11yQualityGate\Controller\AiSettingsAjaxController;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;

final class AiSettingsAjaxControllerTest extends TestCase
{
    #[Test]
    public function saveKeyTriggersModelDiscoveryWithoutAcceptingClientModel(): void
    {
        $manager = $this->createMock(AiConfigurationManagerInterface::class);
        $manager->expects(self::once())->method('save')->with('main', 'sk-proj-secret');
        $discovery = $this->createMock(AiModelDiscoveryServiceInterface::class);
        $discovery->expects(self::once())->method('discover')->with('main')->willReturn([
            'supported' => [['id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 mini']],
            'unsupported' => [],
            'selectedModelId' => 'gpt-4.1-mini',
            'discoveredAt' => time(),
        ]);
        $controller = $this->controller(manager: $manager, discovery: $discovery);
        $request = (new ServerRequest('https://example.test/', 'POST'))->withParsedBody([
            'siteIdentifier' => 'main',
            'apiKey' => 'sk-proj-secret',
            'model' => 'malicious-untrusted-model',
        ]);

        $response = $controller->saveAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('configuration_saved', $this->responseCode($response));
    }

    #[Test]
    public function discoveryPermissionFailureKeepsSavedKeyAndReturnsSafeWarning(): void
    {
        $manager = $this->createMock(AiConfigurationManagerInterface::class);
        $manager->expects(self::once())->method('save');
        $discovery = $this->createMock(AiModelDiscoveryServiceInterface::class);
        $discovery->method('discover')->willThrowException(new AiModelDiscoveryException(
            'models_permission_denied',
            'provider details must stay hidden',
        ));

        $response = $this->controller(manager: $manager, discovery: $discovery)
            ->saveAction((new ServerRequest('https://example.test/', 'POST'))->withParsedBody([
                'siteIdentifier' => 'main',
                'apiKey' => 'secret',
            ]));
        $body = $this->responseBody($response);

        self::assertTrue($body['success']);
        self::assertSame('configuration_saved', $body['code']);
        self::assertSame('models_permission_denied', $body['warningCode']);
        self::assertStringNotContainsString('provider details', (string)$response->getBody());
    }

    #[Test]
    public function refreshModelsUsesCurrentSavedKey(): void
    {
        $discovery = $this->createMock(AiModelDiscoveryServiceInterface::class);
        $discovery->expects(self::once())->method('discover')->with('main')->willReturn([
            'supported' => [
                ['id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 mini'],
                ['id' => 'gpt-5.4-mini', 'label' => 'GPT-5.4 mini'],
            ],
            'unsupported' => [],
            'selectedModelId' => '',
            'discoveredAt' => time(),
        ]);

        $response = $this->controller(discovery: $discovery)->refreshModelsAction($this->testRequest());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('models_refreshed', $this->responseCode($response));
        self::assertSame(2, $this->responseBody($response)['modelCount']);
    }

    #[Test]
    public function adminSelectedModelIsPersisted(): void
    {
        $manager = $this->createMock(AiConfigurationManagerInterface::class);
        $manager->expects(self::once())->method('selectModel')->with('main', 'gpt-4.1-mini');
        $request = (new ServerRequest('https://example.test/', 'POST'))->withParsedBody([
            'siteIdentifier' => 'main',
            'modelId' => 'gpt-4.1-mini',
        ]);

        $response = $this->controller(manager: $manager)->selectModelAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('model_selected', $this->responseCode($response));
    }

    #[Test]
    public function invalidSiteDoesNotCreateTestTimestampRow(): void
    {
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->expects(self::never())->method('markTested');
        $sites = $this->createMock(SiteResolutionServiceInterface::class);
        $sites->method('resolveSiteByIdentifier')->with('missing')->willReturn(null);
        $controller = $this->controller(repository: $repository, sites: $sites);
        $request = (new ServerRequest('https://example.test/', 'POST'))
            ->withParsedBody(['siteIdentifier' => 'missing']);

        self::assertSame(422, $controller->testAction($request)->getStatusCode());
    }

    #[Test]
    public function successfulContractTestIsBoundToSelectedModelAndCurrentContract(): void
    {
        $configuration = $this->configuration();
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('resolve')->with('main')->willReturn($configuration);
        $resolver->method('status')->with('main')->willReturn($this->uiStatus('connected'));
        $discovery = $this->createMock(AiModelDiscoveryServiceInterface::class);
        $discovery->expects(self::once())->method('ensureFresh')->with('main');
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('markTested')
            ->with(
                'main',
                true,
                'fingerprint',
                'gpt-4.1-mini',
                AiPromptDefinition::AI_PROMPT_VERSION,
                AiPromptDefinition::CONNECTION_TEST_VERSION,
                '',
            );
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->method('supports')->with('openai')->willReturn(true);
        $provider->expects(self::once())->method('testConnection')->with($configuration);

        $response = $this->controller(
            resolver: $resolver,
            repository: $repository,
            discovery: $discovery,
            providers: [$provider],
        )->testAction($this->testRequest());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('connection_successful', $this->responseCode($response));
        self::assertSame('connected', $this->responseBody($response)['uiState']['status']);
        self::assertTrue($this->responseBody($response)['uiState']['actions']['testConnectionEnabled']);
    }

    #[DataProvider('providerFailureProvider')]
    #[Test]
    public function providerFailuresUseSafeSpecificMessages(
        int $providerCode,
        string $expectedResponseCode,
        int $expectedHttpStatus,
        string $persistedErrorCode,
    ): void {
        $configuration = $this->configuration();
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('resolve')->willReturn($configuration);
        $resolver->method('status')->with('main')->willReturn(
            $this->uiStatus('connection_failed', $persistedErrorCode),
        );
        $discovery = $this->createMock(AiModelDiscoveryServiceInterface::class);
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('markTested')
            ->with(
                'main',
                false,
                'fingerprint',
                'gpt-4.1-mini',
                AiPromptDefinition::AI_PROMPT_VERSION,
                AiPromptDefinition::CONNECTION_TEST_VERSION,
                $persistedErrorCode,
            );
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->method('supports')->willReturn(true);
        $provider->method('testConnection')->willThrowException(
            new AiProviderException('provider secret must not be returned', $providerCode),
        );

        $response = $this->controller(
            resolver: $resolver,
            repository: $repository,
            discovery: $discovery,
            providers: [$provider],
        )->testAction($this->testRequest());

        self::assertSame($expectedHttpStatus, $response->getStatusCode());
        self::assertSame($expectedResponseCode, $this->responseCode($response));
        $body = $this->responseBody($response);
        self::assertSame('connection_failed', $body['uiState']['status']);
        self::assertSame($persistedErrorCode, $body['uiState']['errorCode']);
        self::assertFalse($body['uiState']['actions']['testConnectionEnabled']);
        self::assertStringNotContainsString('provider secret', (string)$response->getBody());
    }


    #[Test]
    public function http500ErrorStillReturnsTheSharedConnectionFailedUiState(): void
    {
        $manager = $this->createMock(AiConfigurationManagerInterface::class);
        $manager->method('selectModel')->willThrowException(new \RuntimeException('internal details'));
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('status')->with('main')->willReturn(
            $this->uiStatus('connection_failed', 'model_selection_failed'),
        );
        $request = (new ServerRequest('https://example.test/', 'POST'))->withParsedBody([
            'siteIdentifier' => 'main',
            'modelId' => 'gpt-4.1-mini',
        ]);

        $response = $this->controller(manager: $manager, resolver: $resolver)->selectModelAction($request);
        $body = $this->responseBody($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertFalse($body['success']);
        self::assertSame('connection_failed', $body['uiState']['status']);
        self::assertSame('model_selection_failed', $body['uiState']['errorCode']);
        self::assertFalse($body['uiState']['actions']['testConnectionEnabled']);
    }

    public static function providerFailureProvider(): iterable
    {
        yield 'invalid key' => [1771002310, 'api_key_rejected', 401, 'api_key_rejected'];
        yield 'quota' => [1771002320, 'insufficient_quota', 429, 'insufficient_quota'];
        yield 'rate limited' => [1771002311, 'connection_rate_limited', 429, 'rate_limited'];
        yield 'model unavailable' => [1771002317, 'model_unavailable', 422, 'model_unavailable'];
        yield 'model not permitted' => [1771002321, 'model_not_permitted', 403, 'model_not_permitted'];
        yield 'invalid request' => [1771002322, 'invalid_provider_request', 422, 'invalid_request'];
        yield 'refusal' => [1771002313, 'structured_output_test_failed', 502, 'structured_output_test_failed'];
        yield 'incomplete' => [1771002314, 'structured_output_test_failed', 502, 'structured_output_test_failed'];
        yield 'invalid JSON' => [1771002315, 'structured_output_test_failed', 502, 'structured_output_test_failed'];
        yield 'wrong contract' => [1771002318, 'structured_output_test_failed', 502, 'structured_output_test_failed'];
        yield 'transport' => [1771002302, 'transport_failure', 502, 'transport_failure'];
        yield 'service failure' => [1771002312, 'openai_service_failure', 502, 'openai_service_failure'];
    }

    private function controller(
        ?AiConfigurationManagerInterface $manager = null,
        ?AiConfigurationResolverInterface $resolver = null,
        ?AiConfigurationRepositoryInterface $repository = null,
        ?SiteResolutionServiceInterface $sites = null,
        ?AiModelDiscoveryServiceInterface $discovery = null,
        array $providers = [],
    ): AiSettingsAjaxController {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $access = $this->createMock(AccessControlServiceInterface::class);
        $access->method('canManageAdminOnlySettings')->with($backendUser)->willReturn(true);
        $context = $this->createMock(BackendContextServiceInterface::class);
        $context->method('getBackendUser')->willReturn($backendUser);

        if ($sites === null) {
            $sites = $this->createMock(SiteResolutionServiceInterface::class);
            $sites->method('resolveSiteByIdentifier')->with('main')->willReturn($this->createMock(Site::class));
        }

        return new AiSettingsAjaxController(
            $access,
            $context,
            $manager ?? $this->createMock(AiConfigurationManagerInterface::class),
            $resolver ??= $this->createMock(AiConfigurationResolverInterface::class),
            new AiSettingsUiStateBuilder($resolver),
            $repository ?? $this->createMock(AiConfigurationRepositoryInterface::class),
            $sites,
            $discovery ?? $this->createMock(AiModelDiscoveryServiceInterface::class),
            $providers,
        );
    }

    private function configuration(): AiProviderConfiguration
    {
        return new AiProviderConfiguration(
            'openai',
            'secret',
            'gpt-4.1-mini',
            'site',
            'fingerprint',
        );
    }

    private function testRequest(): ServerRequest
    {
        return (new ServerRequest('https://example.test/', 'POST'))
            ->withParsedBody(['siteIdentifier' => 'main']);
    }


    /** @return array<string,mixed> */
    private function uiStatus(string $status, string $errorCode = ''): array
    {
        return [
            'configured' => true,
            'selectedModelAvailable' => true,
            'selectedModelId' => 'gpt-4.1-mini',
            'availableModels' => [['id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 mini']],
            'unsupportedModels' => [],
            'connectionStatus' => $status,
            'lastTestErrorCode' => $errorCode,
            'lastTestedAt' => 1720000000,
            'lastVerifiedAt' => $status === 'connected' ? 1720000000 : 0,
        ];
    }

    /** @return array<string,mixed> */
    private function responseBody(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, 16, JSON_THROW_ON_ERROR);
    }

    private function responseCode(\Psr\Http\Message\ResponseInterface $response): string
    {
        return (string)($this->responseBody($response)['code'] ?? '');
    }
}
