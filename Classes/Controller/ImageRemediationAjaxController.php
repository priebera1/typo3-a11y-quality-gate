<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Remediation\Contract\ImageRemediationPermissionServiceInterface;
use Priebera\A11yQualityGate\Remediation\ImageRemediationPermissionException;
use Priebera\A11yQualityGate\Remediation\ImageRemediationServiceInterface;
use Priebera\A11yQualityGate\Remediation\ImageRemediationValidationException;
use Priebera\A11yQualityGate\Remediation\ImageRemediationWriteException;
use Priebera\A11yQualityGate\Remediation\InvalidImageVersionTokenException;
use Priebera\A11yQualityGate\Remediation\StaleImageFindingException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;

#[AsController]
final class ImageRemediationAjaxController
{
    private const AUTHORIZATION_HEADER = 'controller-capability-v2';

    public function __construct(
        private readonly ImageRemediationServiceInterface $service,
        private readonly ImageRemediationPermissionServiceInterface $permissionService,
    ) {}

    public function markDecorativeAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->mutate(
            $request,
            'mark-decorative',
            fn(int $id, string $version) => $this->service->markDecorative($id, $version),
        );
    }

    public function markInformativeAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->mutate(
            $request,
            'mark-informative',
            fn(int $id, string $version) => $this->service->markInformative($id, $version),
        );
    }

    public function applyAltAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $findingId = (int)($body['findingId'] ?? 0);
        try {
            $this->permissionService->assertCapability($findingId, 'apply-alt', 'controller');
            $context = $this->service->applyAlt(
                $findingId,
                (string)($body['altText'] ?? ''),
                (string)($body['expectedVersion'] ?? ''),
            );

            return $this->success((int)$context->issue['uid']);
        } catch (StaleImageFindingException) {
            return $this->error('stale_finding', 409);
        } catch (InvalidImageVersionTokenException) {
            return $this->error('invalid_version_token', 403);
        } catch (ImageRemediationPermissionException) {
            return $this->error('permission_denied', 403);
        } catch (ImageRemediationValidationException|\InvalidArgumentException) {
            return $this->error('invalid_input', 422);
        } catch (ImageRemediationWriteException) {
            return $this->error('image_update_failed', 500);
        } catch (\Throwable) {
            return $this->error('internal_error', 500);
        }
    }

    private function mutate(ServerRequestInterface $request, string $operationName, callable $operation): ResponseInterface
    {
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $findingId = (int)($body['findingId'] ?? 0);
        try {
            $this->permissionService->assertCapability($findingId, $operationName, 'controller');
            $context = $operation($findingId, (string)($body['expectedVersion'] ?? ''));

            return $this->success((int)$context->issue['uid']);
        } catch (StaleImageFindingException) {
            return $this->error('stale_finding', 409);
        } catch (InvalidImageVersionTokenException) {
            return $this->error('invalid_version_token', 403);
        } catch (ImageRemediationPermissionException) {
            return $this->error('permission_denied', 403);
        } catch (ImageRemediationValidationException|\InvalidArgumentException) {
            return $this->error('invalid_input', 422);
        } catch (ImageRemediationWriteException) {
            return $this->error('image_update_failed', 500);
        } catch (\Throwable) {
            return $this->error('internal_error', 500);
        }
    }

    private function success(int $findingId): JsonResponse
    {
        return (new JsonResponse([
            'success' => true,
            'findingId' => $findingId,
            'requiresRescan' => true,
        ]))->withHeader('X-AQG-Image-Remediation-Authorization', self::AUTHORIZATION_HEADER);
    }

    private function error(string $code, int $status): JsonResponse
    {
        return (new JsonResponse(['success' => false, 'code' => $code], $status))
            ->withHeader('X-AQG-Image-Remediation-Authorization', self::AUTHORIZATION_HEADER);
    }
}
