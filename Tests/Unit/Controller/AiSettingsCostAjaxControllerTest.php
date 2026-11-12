<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationManagerInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiModelDiscoveryServiceInterface;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Service\AiSettingsUiStateBuilder;
use Priebera\A11yQualityGate\Contract\AccessControlServiceInterface;
use Priebera\A11yQualityGate\Contract\BackendContextServiceInterface;
use Priebera\A11yQualityGate\Contract\SiteResolutionServiceInterface;
use Priebera\A11yQualityGate\Controller\AiSettingsAjaxController;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;

/** Monthly budget and processing acknowledgement: administrators only, safe codes; no price entry and no price check. */
final class AiSettingsCostAjaxControllerTest extends TestCase
{
    #[Test]
    public function nonAdministratorsCannotChangeBudgetsOrTheAcknowledgement(): void
    {
        $manager = $this->createMock(AiConfigurationManagerInterface::class);
        $manager->expects(self::never())->method('saveBudget');
        $manager->expects(self::never())->method('acknowledgeProcessing');
        $controller = $this->controller($manager, admin: false);
        $body = ['siteIdentifier' => 'main', 'provider' => 'openai', 'budget' => '50', 'hardStop' => '1', 'confirmed' => '1'];

        foreach (['saveBudgetAction', 'acknowledgeProcessingAction'] as $action) {
            $response = $controller->{$action}($this->post($body));
            self::assertSame(403, $response->getStatusCode(), $action);
            self::assertSame('permission_denied', $this->body($response)['code'], $action);
            self::assertSame(['responseStatus' => 403], $this->body($response)['uiState'], $action);
        }
    }

    #[Test]
    public function thereIsNoEndpointToEnterRemoveOrRefreshPrices(): void
    {
        foreach (['savePriceAction', 'removePriceAction', 'refreshPricingAction'] as $action) {
            self::assertFalse(method_exists(AiSettingsAjaxController::class, $action), $action);
        }
        $routes = require __DIR__ . '/../../../Configuration/Backend/AjaxRoutes.php';
        foreach (['a11y_ai_settings_price_save', 'a11y_ai_settings_price_remove', 'a11y_ai_settings_pricing_refresh'] as $route) {
            self::assertArrayNotHasKey($route, $routes, 'Prices come from the AQG service; the installation has no price controls.');
        }
    }

    #[Test]
    public function budgetValidationCodesPassThroughAndOthersStayGeneric(): void
    {
        $manager = $this->createMock(AiConfigurationManagerInterface::class);
        $manager->method('saveBudget')->willReturnCallback(static function (string $site, string $budget): void {
            throw new \InvalidArgumentException($budget === '50' ? 'hard_stop_required' : 'The selected TYPO3 site does not exist.');
        });
        $controller = $this->controller($manager);

        self::assertSame('hard_stop_required', $this->body($controller->saveBudgetAction($this->post(['siteIdentifier' => 'main', 'budget' => '50'])))['code']);
        self::assertSame('invalid_configuration', $this->body($controller->saveBudgetAction($this->post(['siteIdentifier' => 'main', 'budget' => '7'])))['code']);
        self::assertSame('site_not_found', $this->body($controller->saveBudgetAction($this->post(['siteIdentifier' => 'foreign', 'budget' => '50'])))['code']);
    }

    #[Test]
    public function theAcknowledgementNeedsTheTickAndTheActiveProvider(): void
    {
        $manager = $this->createMock(AiConfigurationManagerInterface::class);
        $manager->expects(self::once())->method('acknowledgeProcessing')->with('main', 'anthropic', 42)
            ->willThrowException(new AiConfigurationException('The site uses another AI provider now.', 1771002010));
        $controller = $this->controller($manager);

        self::assertSame('acknowledgement_required', $this->body($controller->acknowledgeProcessingAction($this->post(['siteIdentifier' => 'main', 'provider' => 'anthropic'])))['code']);
        $changed = $controller->acknowledgeProcessingAction($this->post(['siteIdentifier' => 'main', 'provider' => 'anthropic', 'confirmed' => '1']));
        self::assertSame(409, $changed->getStatusCode());
        self::assertSame('provider_changed', $this->body($changed)['code']);
    }

    private function controller(
        ?AiConfigurationManagerInterface $manager = null,
        bool $admin = true,
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
        $resolver->method('status')->willReturn(['providerChosen' => true]);

        return new AiSettingsAjaxController(
            $access,
            $context,
            $manager ?? $this->createMock(AiConfigurationManagerInterface::class),
            $resolver,
            new AiSettingsUiStateBuilder($resolver),
            $this->createMock(AiConfigurationRepositoryInterface::class),
            $sites,
            $this->createMock(AiModelDiscoveryServiceInterface::class),
            [],
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
