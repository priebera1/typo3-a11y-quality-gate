<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Contract\BackendContextServiceInterface;
use Priebera\A11yQualityGate\UpdateNotice\SemanticVersion;
use Priebera\A11yQualityGate\UpdateNotice\UpdateNoticeDismissalStore;
use Priebera\A11yQualityGate\UpdateNotice\UpdateNoticeService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Dismisses the update notice of one release for the current backend user. The route is POST-only, needs the
 * backend route token TYPO3 adds to every AJAX URL and inherits access from the AQG module; only a user who
 * sees update notices may dismiss one. The request names the release version, nothing else.
 */
#[AsController]
final class UpdateNoticeAjaxController
{
    public function __construct(
        private readonly UpdateNoticeService $updateNoticeService,
        private readonly UpdateNoticeDismissalStore $dismissalStore,
        private readonly BackendContextServiceInterface $backendContextService,
    ) {
    }

    public function dismissAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->backendContextService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->json(['success' => false, 'code' => 'not_authenticated'], 401);
        }

        if (!$this->updateNoticeService->canSeeUpdateNotices($backendUser)) {
            return $this->json(['success' => false, 'code' => 'access_denied'], 403);
        }

        $body = $request->getParsedBody();
        $rawVersion = is_array($body) && is_string($body['version'] ?? null) ? $body['version'] : '';
        $version = SemanticVersion::tryParse($rawVersion);
        if ($version === null) {
            return $this->json(['success' => false, 'code' => 'invalid_version'], 400);
        }

        $this->dismissalStore->dismiss($backendUser, $version);

        return $this->json(['success' => true, 'version' => $version->toString()]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }
}
