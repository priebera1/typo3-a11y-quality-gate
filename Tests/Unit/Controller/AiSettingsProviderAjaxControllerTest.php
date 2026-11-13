<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationManagerInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiModelDiscoveryServiceInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiProviderInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Service\AiRateLimiter;
use Priebera\A11yQualityGate\Ai\Service\AiSettingsUiStateBuilder;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageMeter;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder;
use Priebera\A11yQualityGate\Contract\AccessControlServiceInterface;
use Priebera\A11yQualityGate\Contract\BackendContextServiceInterface;
use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;
use Priebera\A11yQualityGate\Contract\SiteResolutionServiceInterface;
use Priebera\A11yQualityGate\Controller\AiSettingsAjaxController;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiRateLimitRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiUsageRepositoryInterface;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Usage\AiUsageRepositoryDouble;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Provider switch, privacy settings, Anthropic error mapping, rate limits and usage of the AI settings endpoints.
 */
final class AiSettingsProviderAjaxControllerTest extends TestCase
{
    #[Test]
    public function nonAdministratorsCannotSwitchProviderOrChangePrivacy(): void
    {
        $manager = $this->createMock(AiConfigurationManagerInterface::class);
        $manager->expects(self::never())->method('selectProvider');
        $manager->expects(self::never())->method('savePrivacy');
        $controller = $this->controller(manager: $manager, admin: false);

        self::assertSame(403, $controller->selectProviderAction($this->post(['siteIdentifier' => 'main', 'provider' => 'anthropic']))->getStatusCode());
        self::assertSame(403, $controller->savePrivacyAction($this->post(['siteIdentifier' => 'main', 'allowImageInput' => '0']))->getStatusCode());
    }

    #[Test]
    public function providerSwitchIsRecordedForTheCurrentAdministrator(): void
    {
        $manager = $this->createMock(AiConfigurationManagerInterface::class);
        $manager->expects(self::once())->method('selectProvider')->with('main', 'anthropic', 42)->willReturn(true);

        $response = $this->controller(manager: $manager)->selectProviderAction($this->post(['siteIdentifier' => 'main', 'provider' => 'anthropic']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('provider_selected', $this->body($response)['code']);
    }

    #[Test]
    public function unknownSiteOrProviderIsRejected(): void
    {
        $manager = $this->createMock(AiConfigurationManagerInterface::class);
        $manager->method('selectProvider')->willThrowException(new \InvalidArgumentException('Unsupported AI provider.'));
        $controller = $this->controller(manager: $manager);

        self::assertSame('site_not_found', $this->body($controller->selectProviderAction($this->post(['siteIdentifier' => 'foreign', 'provider' => 'anthropic'])))['code']);
        self::assertSame('invalid_provider', $this->body($controller->selectProviderAction($this->post(['siteIdentifier' => 'main', 'provider' => 'ollama'])))['code']);
    }

    #[Test]
    public function privacySwitchesAreReadStrictly(): void
    {
        $manager = $this->createMock(AiConfigurationManagerInterface::class);
        $manager->expects(self::once())->method('savePrivacy')->with('main', false, true);

        $response = $this->controller(manager: $manager)->savePrivacyAction($this->post([
            'siteIdentifier' => 'main',
            'allowImageInput' => 'true',
            'allowPageTitle' => '1',
        ]));

        self::assertSame('privacy_saved', $this->body($response)['code']);
    }

    #[Test]
    public function keyForAnotherProviderAndMalformedKeysAreRejectedWithSpecificCodes(): void
    {
        $manager = $this->createMock(AiConfigurationManagerInterface::class);
        $manager->method('save')->willReturnCallback(static function (string $site, string $key, ?string $provider): void {
            if ($provider === 'openai') {
                throw new AiConfigurationException('mismatch', 1771002010);
            }
            throw new AiConfigurationException('format', 1771002011);
        });
        $controller = $this->controller(manager: $manager);

        $mismatch = $controller->saveAction($this->post(['siteIdentifier' => 'main', 'provider' => 'openai', 'apiKey' => 'sk-proj-x']));
        $format = $controller->saveAction($this->post(['siteIdentifier' => 'main', 'provider' => 'anthropic', 'apiKey' => 'sk-proj-x']));

        self::assertSame([409, 'provider_changed'], [$mismatch->getStatusCode(), $this->body($mismatch)['code']]);
        self::assertSame([422, 'invalid_key_format'], [$format->getStatusCode(), $this->body($format)['code']]);
        self::assertStringNotContainsString('sk-proj-x', (string)$format->getBody());
    }

    #[Test]
    public function anthropicBillingAndOverloadUseProviderNeutralCodes(): void
    {
        foreach ([1771002323 => [402, 'provider_billing_error'], 1771002324 => [502, 'provider_service_failure']] as $code => [$status, $safeCode]) {
            $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
            $repository->expects(self::once())->method('markTested')
                ->with('main', false, 'fp', 'claude-sonnet-5-5', self::anything(), self::anything(), $safeCode, 'anthropic');
            $provider = $this->provider(new AiProviderException('provider message', $code));

            $response = $this->controller(repository: $repository, providers: [$provider])->testAction($this->post(['siteIdentifier' => 'main']));

            self::assertSame($status, $response->getStatusCode());
            self::assertSame($safeCode, $this->body($response)['code']);
        }
    }

    #[Test]
    public function connectionTestIsRateLimitedAndRecordedAsUsage(): void
    {
        $rows = [];
        $usage = new AiUsageRepositoryDouble(static function (array $row) use (&$rows): void {
            $rows[] = $row;
        });
        $calls = 0;
        $limits = $this->createMock(AiRateLimitRepositoryInterface::class);
        $limits->method('consume')->willReturnCallback(static function () use (&$calls): array {
            $calls++;
            return $calls === 1 ? ['allowed' => true, 'retryAfter' => 0] : ['allowed' => false, 'retryAfter' => 37];
        });
        $controller = $this->controller(providers: [$this->provider(null)], rateLimits: $limits, usage: $usage);

        $first = $controller->testAction($this->post(['siteIdentifier' => 'main']));
        $second = $controller->testAction($this->post(['siteIdentifier' => 'main']));

        self::assertSame('connection_successful', $this->body($first)['code']);
        self::assertSame(429, $second->getStatusCode());
        self::assertSame('settings_rate_limited', $this->body($second)['code']);
        self::assertSame('37', $second->getHeaderLine('Retry-After'));
        self::assertCount(1, $rows);
        self::assertSame(['connection_test', 'anthropic', 'claude-sonnet-5-5', 'success'], [$rows[0]['feature'], $rows[0]['provider'], $rows[0]['model'], $rows[0]['outcome']]);
    }

    #[Test]
    public function settingsActionsUseTheirOwnRateLimitBucket(): void
    {
        $buckets = [];
        $limits = $this->createMock(AiRateLimitRepositoryInterface::class);
        $limits->method('consume')->willReturnCallback(static function (string $bucket) use (&$buckets): array {
            $buckets[] = $bucket;
            return ['allowed' => true, 'retryAfter' => 0];
        });
        $controller = $this->controller(providers: [$this->provider(null)], rateLimits: $limits);

        $controller->testAction($this->post(['siteIdentifier' => 'main']));
        $controller->saveAction($this->post(['siteIdentifier' => 'main', 'apiKey' => 'sk-ant-api03-x']));

        self::assertSame([hash('sha256', 'main:42:settings'), hash('sha256', 'main:42:settings')], $buckets);
        self::assertNotSame(hash('sha256', 'main:42'), $buckets[0], 'Editor suggestions keep the 2.x bucket.');
    }

    private function provider(?\Throwable $failure): AiProviderInterface
    {
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->method('supports')->willReturnCallback(static fn (string $id): bool => $id === 'anthropic');
        if ($failure !== null) {
            $provider->method('testConnection')->willThrowException($failure);
        }

        return $provider;
    }

    /** @param list<AiProviderInterface> $providers */
    private function controller(
        ?AiConfigurationManagerInterface $manager = null,
        ?AiConfigurationRepositoryInterface $repository = null,
        array $providers = [],
        bool $admin = true,
        ?AiRateLimitRepositoryInterface $rateLimits = null,
        ?AiUsageRepositoryInterface $usage = null,
    ): AiSettingsAjaxController {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 42];
        $access = $this->createMock(AccessControlServiceInterface::class);
        $access->method('canManageAdminOnlySettings')->willReturn($admin);
        $context = $this->createMock(BackendContextServiceInterface::class);
        $context->method('getBackendUser')->willReturn($backendUser);
        $sites = $this->createMock(SiteResolutionServiceInterface::class);
        $sites->method('resolveSiteByIdentifier')->willReturnCallback(fn (string $id): ?Site => $id === 'main' ? $this->createMock(Site::class) : null);
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('resolveForConnectionTest')->willReturn(new AiProviderConfiguration('anthropic', 'sk-ant-secret', 'claude-sonnet-5-5', 'site', 'fp'));
        $users = $this->createMock(BackendUserServiceInterface::class);
        $users->method('getBackendUserUid')->willReturn(42);
        $discovery = $this->createMock(AiModelDiscoveryServiceInterface::class);
        $discovery->method('discover')->willReturn(['supported' => [], 'unsupported' => [], 'selectedModelId' => '', 'discoveredAt' => 0]);
        if ($rateLimits === null) {
            $rateLimits = $this->createMock(AiRateLimitRepositoryInterface::class);
            $rateLimits->method('consume')->willReturn(['allowed' => true, 'retryAfter' => 0]);
        }

        return new AiSettingsAjaxController(
            $access,
            $context,
            $manager ?? $this->createMock(AiConfigurationManagerInterface::class),
            $resolver,
            new AiSettingsUiStateBuilder($resolver),
            $repository ?? $this->createMock(AiConfigurationRepositoryInterface::class),
            $sites,
            $discovery,
            $providers,
            new AiRateLimiter($rateLimits, $users),
            new AiUsageRecorder($usage ?? new AiUsageRepositoryDouble(), new AiUsageMeter(), $users),
        );
    }

    /** @param array<string,string> $body */
    private function post(array $body): ServerRequest
    {
        return (new ServerRequest('https://example.test/', 'POST'))->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function body(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
    }
}
