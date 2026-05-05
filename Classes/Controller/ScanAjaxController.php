<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
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
        private readonly RemoteScanRepository $remoteScanRepository,
    ) {
        parent::__construct($responseFactory, $streamFactory, $backendUserService);
    }

    public function scanPageAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureBackendUserAccess($this->accessControlService, 'scanNow');
        if ($accessResponse !== null) {
            return $accessResponse;
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
            return $this->badRequestResponse('Missing or invalid pageUid');
        }

        $backendUser = $this->getBackendUser();
        $triggeredBy = $backendUser instanceof BackendUserAuthentication
            ? (string)($backendUser->user['username'] ?? 'unknown')
            : 'unknown';

        $scanStarted = false;

        try {
            $siteIdentifier = $this->siteResolutionService->resolveSiteIdentifierFromPageId($pageUid);

            $this->scanStatusService->markRunning(
                trigger: 'page',
                triggeredBy: $triggeredBy,
                pageUid: $pageUid,
            );
            $scanStarted = true;

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
            if ($this->isMissingSiteConfigurationException($e)) {
                return $this->missingSiteConfigurationResponse(
                    pageUid: $pageUid,
                    scope: 'page'
                );
            }

            if ($scanStarted) {
                $this->scanStatusService->markFailed($e->getMessage());
            }

            return $this->jsonResponse([
                'success' => false,
                'error' => 'Scan failed: ' . $e->getMessage(),
                'status' => $this->scanStatusService->getStatus(),
            ], 500);
        }
    }

    public function scanSiteAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureBackendUserAccess($this->accessControlService, 'scanAll');
        if ($accessResponse !== null) {
            return $accessResponse;
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
            return $this->badRequestResponse('Missing or invalid rootPid');
        }

        $backendUser = $this->getBackendUser();
        $triggeredBy = $backendUser instanceof BackendUserAuthentication
            ? (string)($backendUser->user['username'] ?? 'unknown')
            : 'unknown';

        $scanStarted = false;

        try {
            $siteIdentifier = $this->siteResolutionService->resolveSiteIdentifierFromPageId($rootPid);

            $this->scanStatusService->markRunning(
                trigger: 'site',
                triggeredBy: $triggeredBy,
                rootPid: $rootPid,
            );
            $scanStarted = true;

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
            if ($this->isMissingSiteConfigurationException($e)) {
                return $this->missingSiteConfigurationResponse(
                    pageUid: $rootPid,
                    scope: 'site'
                );
            }

            if ($scanStarted) {
                $this->scanStatusService->markFailed($e->getMessage());
            }

            return $this->jsonResponse([
                'success' => false,
                'error' => 'Scan failed: ' . $e->getMessage(),
                'status' => $this->scanStatusService->getStatus(),
            ], 500);
        }
    }

    public function scanStatusAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isBackendUserLoggedIn()) {
            return $this->unauthorizedResponse();
        }

        return $this->jsonResponse([
            'success' => true,
            'status' => $this->scanStatusService->getStatus(),
            'remoteStatus' => $this->resolveRemoteStatusForRequest($request),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRemoteStatusForRequest(ServerRequestInterface $request): ?array
    {
        $queryParams = $request->getQueryParams();
        $siteIdentifier = trim((string)($queryParams['site'] ?? ''));
        $pageUid = (int)($queryParams['pageUid'] ?? $queryParams['id'] ?? 0);

        if ($siteIdentifier === '' && $pageUid > 0) {
            try {
                $siteIdentifier = $this->siteResolutionService->resolveSiteIdentifierFromPageId($pageUid);
            } catch (\Throwable) {
                $siteIdentifier = '';
            }
        }

        if ($siteIdentifier === '') {
            return null;
        }

        $activeSiteScan = $this->remoteScanRepository->findLatestActiveSiteScanBySite($siteIdentifier);
        if (is_array($activeSiteScan)) {
            return $activeSiteScan;
        }

        if ($pageUid > 0) {
            $activePageScan = $this->remoteScanRepository->findLatestRelevantActiveScan($siteIdentifier, $pageUid);
            if (is_array($activePageScan)) {
                return $activePageScan;
            }
        }

        $activeAnyScan = $this->remoteScanRepository->findLatestActiveScanBySite($siteIdentifier);
        if (is_array($activeAnyScan)) {
            return $activeAnyScan;
        }

        $lastCompletedSiteScan = $this->remoteScanRepository->findLastCompletedSiteScanBySite($siteIdentifier);
        if (is_array($lastCompletedSiteScan)) {
            return $lastCompletedSiteScan;
        }

        $lastCompletedScan = $this->remoteScanRepository->findLastCompletedScanBySite($siteIdentifier);

        return is_array($lastCompletedScan) ? $lastCompletedScan : null;
    }

    private function isMissingSiteConfigurationException(\Throwable $exception): bool
    {
        return $exception instanceof \RuntimeException
            && $exception->getCode() === 1700000001;
    }

    private function missingSiteConfigurationResponse(int $pageUid, string $scope): ResponseInterface
    {
        $message = $scope === 'site'
            ? sprintf(
                'Cannot scan root page %d because no TYPO3 Site Configuration exists for this root page. Create it first in Site Management > Sites.',
                $pageUid
            )
            : sprintf(
                'Cannot scan page %d because no TYPO3 Site Configuration exists for its root page. Create it first in Site Management > Sites.',
                $pageUid
            );

        return $this->jsonResponse([
            'success' => false,
            'error' => $message,
            'code' => 'missing_site_configuration',
            'status' => $this->scanStatusService->getStatus(),
        ], 400);
    }
}