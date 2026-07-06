<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\ImageRemediationAjaxController;
use Priebera\A11yQualityGate\Remediation\Contract\ImageRemediationPermissionServiceInterface;
use Priebera\A11yQualityGate\Remediation\ImageRemediationServiceInterface;
use Priebera\A11yQualityGate\Remediation\ImageRemediationPermissionException;
use Priebera\A11yQualityGate\Remediation\InvalidImageVersionTokenException;
use Priebera\A11yQualityGate\Remediation\StaleImageFindingException;
use TYPO3\CMS\Core\Http\ServerRequest;

final class ImageRemediationAjaxControllerTest extends TestCase
{
    #[Test]
    public function tamperedApplyTokenReturnsForbidden(): void
    {
        $service = $this->createMock(ImageRemediationServiceInterface::class);
        $service->method('applyAlt')->willThrowException(
            new InvalidImageVersionTokenException('Invalid token.'),
        );
        $controller = new ImageRemediationAjaxController($service, $this->allowedPermissionService());
        $request = (new ServerRequest('https://example.test/', 'POST'))->withParsedBody([
            'findingId' => 12,
            'altText' => 'Reviewed text',
            'expectedVersion' => 'tampered-token',
        ]);

        $response = $controller->applyAltAction($request);
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('invalid_version_token', $payload['code']);
    }

    #[Test]
    public function staleApplyReturnsConflict(): void
    {
        $service = $this->createMock(ImageRemediationServiceInterface::class);
        $service->expects(self::once())
            ->method('applyAlt')
            ->with(12, 'Reviewed text', 'stale-token')
            ->willThrowException(new StaleImageFindingException('Refresh and try again.'));
        $controller = new ImageRemediationAjaxController($service, $this->allowedPermissionService());
        $request = (new ServerRequest('https://example.test/', 'POST'))
            ->withParsedBody([
                'findingId' => 12,
                'altText' => 'Reviewed text',
                'expectedVersion' => 'stale-token',
            ]);

        $response = $controller->applyAltAction($request);
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('stale_finding', $payload['code']);
    }

    #[Test]
    #[DataProvider('permissionDeniedOperationProvider')]
    public function directPostWithoutPermissionReturnsForbidden(string $action, string $serviceMethod): void
    {
        $service = $this->createMock(ImageRemediationServiceInterface::class);
        $service->method($serviceMethod)->willThrowException(
            new ImageRemediationPermissionException('permission_denied'),
        );
        $controller = new ImageRemediationAjaxController($service, $this->allowedPermissionService());
        $request = (new ServerRequest('https://example.test/', 'POST'))->withParsedBody([
            'findingId' => 12,
            'altText' => 'Reviewed text',
            'expectedVersion' => 'valid-token',
        ]);

        $response = $controller->{$action}($request);
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('permission_denied', $payload['code']);
    }

    #[Test]
    #[DataProvider('permissionDeniedOperationProvider')]
    public function controllerCapabilityGuardRejectsBeforeServiceCall(string $action, string $serviceMethod): void
    {
        $service = $this->createMock(ImageRemediationServiceInterface::class);
        $service->expects(self::never())->method($serviceMethod);
        $permissionService = $this->createMock(ImageRemediationPermissionServiceInterface::class);
        $permissionService->expects(self::once())
            ->method('assertCapability')
            ->willThrowException(new ImageRemediationPermissionException('permission_denied'));
        $controller = new ImageRemediationAjaxController($service, $permissionService);
        $request = (new ServerRequest('https://example.test/', 'POST'))->withParsedBody([
            'findingId' => 12,
            'altText' => 'Reviewed text',
            'expectedVersion' => 'valid-token',
        ]);

        $response = $controller->{$action}($request);
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('permission_denied', $payload['code']);
        self::assertSame(
            'controller-capability-v2',
            $response->getHeaderLine('X-AQG-Image-Remediation-Authorization'),
        );
    }

    private function allowedPermissionService(): ImageRemediationPermissionServiceInterface
    {
        $permissionService = $this->createMock(ImageRemediationPermissionServiceInterface::class);
        $permissionService->method('assertCapability')->willReturn(
            (new \ReflectionClass(\TYPO3\CMS\Core\Authentication\BackendUserAuthentication::class))
                ->newInstanceWithoutConstructor(),
        );

        return $permissionService;
    }

    public static function permissionDeniedOperationProvider(): iterable
    {
        yield 'mark decorative' => ['markDecorativeAction', 'markDecorative'];
        yield 'mark informative' => ['markInformativeAction', 'markInformative'];
        yield 'apply alt' => ['applyAltAction', 'applyAlt'];
    }
}
