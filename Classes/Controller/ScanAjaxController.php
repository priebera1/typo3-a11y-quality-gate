<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

#[AsController]
final class ScanAjaxController extends AbstractApiController
{
    public function __construct(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        BackendUserService $backendUserService,
        private readonly ScanOrchestrator $scanOrchestrator,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly AccessControlService $accessControlService,
        private readonly ScanStatusService $scanStatusService,
    ) {
        parent::__construct($responseFactory, $streamFactory, $backendUserService);
    }

    public function scanPageAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Unauthorized',
            ], 401);
        }

        if (!$this->accessControlService->canShowScanNow($backendUser)) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Access denied',
            ], 403);
        }

        if ($this->scanStatusService->isRunning()) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'A scan is already running.',
                'status' => $this->scanStatusService->getStatus(),
            ], 409);
        }

        $body = $request->getParsedBody();
        $pageUid = (int)(is_array($body) ? ($body['pageUid'] ?? 0) : 0);

        if ($pageUid <= 0) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Missing or invalid pageUid',
            ], 400);
        }

        try {
            $siteIdentifier = $this->siteResolutionService->resolveSiteIdentifierFromPageId($pageUid);

            $this->scanStatusService->markRunning(
                trigger: 'page',
                triggeredBy: (string)($backendUser->user['username'] ?? 'unknown'),
                pageUid: $pageUid,
            );

            $result = $this->scanOrchestrator->scanPage(
                siteIdentifier: $siteIdentifier,
                pageUid: $pageUid,
            );

            $this->scanStatusService->markFinished($result);

            return $this->jsonResponse([
                'success' => true,
                'scanUid' => $result->scanUid,
                'pagesScanned' => $result->pagesScanned,
                'recordsScanned' => $result->recordsScanned,
                'issuesNew' => $result->issuesNew,
                'issuesResolved' => $result->issuesResolved,
                'issuesIgnored' => $result->issuesIgnored,
                'status' => $this->scanStatusService->getStatus(),
            ]);
        } catch (\Throwable $e) {
            $this->scanStatusService->markFailed($e->getMessage());

            return $this->jsonResponse([
                'success' => false,
                'error' => 'Scan failed: ' . $e->getMessage(),
                'status' => $this->scanStatusService->getStatus(),
            ], 500);
        }
    }

    public function scanSiteAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Unauthorized',
            ], 401);
        }

        if (!$this->accessControlService->canShowScanAll($backendUser)) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Access denied',
            ], 403);
        }

        if ($this->scanStatusService->isRunning()) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'A scan is already running.',
                'status' => $this->scanStatusService->getStatus(),
            ], 409);
        }

        $body = $request->getParsedBody();
        $rootPid = (int)(is_array($body) ? ($body['rootPid'] ?? 0) : 0);

        if ($rootPid <= 0) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Missing or invalid rootPid',
            ], 400);
        }

        try {
            $siteIdentifier = $this->siteResolutionService->resolveSiteIdentifierFromPageId($rootPid);

            $this->scanStatusService->markRunning(
                trigger: 'site',
                triggeredBy: (string)($backendUser->user['username'] ?? 'unknown'),
                rootPid: $rootPid,
            );

            $result = $this->scanOrchestrator->scanSubtree(
                siteIdentifier: $siteIdentifier,
                rootPid: $rootPid,
            );

            $this->scanStatusService->markFinished($result);

            return $this->jsonResponse([
                'success' => true,
                'scanUid' => $result->scanUid,
                'pagesScanned' => $result->pagesScanned,
                'recordsScanned' => $result->recordsScanned,
                'issuesNew' => $result->issuesNew,
                'issuesResolved' => $result->issuesResolved,
                'issuesIgnored' => $result->issuesIgnored,
                'status' => $this->scanStatusService->getStatus(),
            ]);
        } catch (\Throwable $e) {
            $this->scanStatusService->markFailed($e->getMessage());

            return $this->jsonResponse([
                'success' => false,
                'error' => 'Scan failed: ' . $e->getMessage(),
                'status' => $this->scanStatusService->getStatus(),
            ], 500);
        }
    }

    public function scanStatusAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Unauthorized',
            ], 401);
        }

        return $this->jsonResponse([
            'success' => true,
            'status' => $this->scanStatusService->getStatus(),
        ]);
    }
}
