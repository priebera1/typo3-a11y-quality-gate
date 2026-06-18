<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\LanguageUidResolver;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Exception\ScanCancelledException;
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
        private readonly ScanRepository $scanRepository,
        private readonly BackendRecordAccessService $backendRecordAccessService,
        private readonly LanguageUidResolver $languageUidResolver,
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
        $bodyParams = is_array($body) ? $body : [];
        $pageUid = (int)($bodyParams['pageUid'] ?? 0);
        $languageUid = $this->languageUidResolver->fromParameters($bodyParams, [], 0, true) ?? 0;

        if ($pageUid <= 0) {
            return $this->badRequestResponse('Missing or invalid pageUid');
        }

        if (!$this->backendRecordAccessService->canEditRecord(Tables::PAGES, $pageUid)) {
            return $this->forbiddenResponse();
        }

        $backendUser = $this->getBackendUser();
        $triggeredBy = $backendUser instanceof BackendUserAuthentication
            ? (string)($backendUser->user['username'] ?? 'unknown')
            : 'unknown';

        $scanStarted = false;

        try {
            $siteIdentifier = $this->siteResolutionService->resolveSiteIdentifierFromPageId($pageUid);
            $resolvedBy = $this->backendUserService->getBackendUserSnapshot();

            $this->scanStatusService->markRunning(
                trigger: 'page',
                triggeredBy: $triggeredBy,
                pageUid: $pageUid,
                languageUid: $languageUid,
            );
            $scanStarted = true;

            $this->releasePhpSessionLock();

            $result = $this->scanOrchestrator->scanPage(
                siteIdentifier: $siteIdentifier,
                pageUid: $pageUid,
                languageUid: $languageUid,
                resolvedBy: $resolvedBy,
                shouldCancel: fn (int $scanUid): bool => $this->scanStatusService->isCancellationRequested()
                    || $this->scanRepository->isScanCancellationRequested($scanUid),
                onRunStarted: function (int $scanUid): void {
                    $this->scanStatusService->markScanRunStarted($scanUid);
                },
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
                'warnings' => $result->warnings,
                'status' => $this->scanStatusService->getStatus(),
            ]);
        } catch (ScanCancelledException) {
            $this->scanStatusService->markCancelled();

            return $this->jsonResponse([
                'success' => false,
                'code' => 'local_scan_cancelled',
                'message' => 'Scan was cancelled.',
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
        $bodyParams = is_array($body) ? $body : [];
        $rootPid = (int)($bodyParams['rootPid'] ?? 0);
        $languageUid = $this->languageUidResolver->fromParameters($bodyParams, [], 0, true) ?? 0;

        if ($rootPid <= 0) {
            return $this->badRequestResponse('Missing or invalid rootPid');
        }

        if (!$this->backendRecordAccessService->canEditRecord(Tables::PAGES, $rootPid)) {
            return $this->forbiddenResponse();
        }

        $backendUser = $this->getBackendUser();
        $triggeredBy = $backendUser instanceof BackendUserAuthentication
            ? (string)($backendUser->user['username'] ?? 'unknown')
            : 'unknown';

        $scanStarted = false;

        try {
            $siteIdentifier = $this->siteResolutionService->resolveSiteIdentifierFromPageId($rootPid);
            $resolvedBy = $this->backendUserService->getBackendUserSnapshot();

            $this->scanStatusService->markRunning(
                trigger: 'site',
                triggeredBy: $triggeredBy,
                rootPid: $rootPid,
                languageUid: $languageUid,
            );
            $scanStarted = true;

            $this->releasePhpSessionLock();

            $result = $this->scanOrchestrator->scanSubtree(
                siteIdentifier: $siteIdentifier,
                rootPid: $rootPid,
                languageUid: $languageUid,
                resolvedBy: $resolvedBy,
                shouldCancel: fn (int $scanUid): bool => $this->scanStatusService->isCancellationRequested()
                    || $this->scanRepository->isScanCancellationRequested($scanUid),
                onRunStarted: function (int $scanUid): void {
                    $this->scanStatusService->markScanRunStarted($scanUid);
                },
                includeRenderedPageCheck: true,
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
                'warnings' => $result->warnings,
                'status' => $this->scanStatusService->getStatus(),
            ]);
        } catch (ScanCancelledException) {
            $this->scanStatusService->markCancelled();

            return $this->jsonResponse([
                'success' => false,
                'code' => 'local_scan_cancelled',
                'message' => 'Scan was cancelled.',
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

    public function cancelScanAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isBackendUserLoggedIn()) {
            return $this->unauthorizedResponse();
        }

        $backendUser = $this->getBackendUser();
        if (
            !$this->accessControlService->canShowScanNow($backendUser)
            && !$this->accessControlService->canShowScanAll($backendUser)
        ) {
            return $this->forbiddenResponse();
        }

        if (!$this->scanStatusService->isRunning()) {
            return $this->jsonResponse([
                'success' => true,
                'status' => $this->scanStatusService->getStatus(),
                'message' => 'No content scan is running.',
            ]);
        }

        $status = $this->scanStatusService->getStatus();
        $scanUid = (int)($status['scanUid'] ?? 0);
        if ($scanUid > 0) {
            $this->scanRepository->requestScanCancellation($scanUid);
        }

        $this->scanStatusService->requestCancellation();

        return $this->jsonResponse([
            'success' => true,
            'status' => $this->scanStatusService->getStatus(),
            'message' => 'Content scan cancellation was requested.',
        ]);
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
     * Releases the PHP session lock before the long-running local scan starts.
     *
     * Without this, a second AJAX request from the same TYPO3 backend user can be
     * blocked by the active scan request. That makes cooperative cancellation look
     * unreliable for the user who started the scan, while another backend user can
     * still cancel it because their request uses a different session.
     */
    private function releasePhpSessionLock(): void
    {
        if (PHP_SESSION_ACTIVE === session_status()) {
            session_write_close();
        }
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

        $languageUid = $this->resolveLanguageUidFromQueryParams($queryParams);

        $activeSiteScan = $this->remoteScanRepository->findLatestActiveSiteScanBySite($siteIdentifier, $languageUid);
        if (is_array($activeSiteScan)) {
            return $activeSiteScan;
        }

        if ($pageUid > 0) {
            $activePageScan = $this->remoteScanRepository->findLatestRelevantActiveScan($siteIdentifier, $pageUid, $languageUid);
            if (is_array($activePageScan)) {
                return $activePageScan;
            }
        }

        $activeAnyScan = $this->remoteScanRepository->findLatestActiveScanBySite($siteIdentifier, $languageUid);
        if (is_array($activeAnyScan)) {
            return $activeAnyScan;
        }

        $lastCompletedSiteScan = $this->remoteScanRepository->findLastCompletedSiteScanBySite($siteIdentifier, $languageUid);
        if (is_array($lastCompletedSiteScan)) {
            return $lastCompletedSiteScan;
        }

        $lastCompletedScan = $this->remoteScanRepository->findLastCompletedScanBySite($siteIdentifier, $languageUid);

        return is_array($lastCompletedScan) ? $lastCompletedScan : null;
    }


    /**
     * @param array<string, mixed> $queryParams
     */
    private function resolveLanguageUidFromQueryParams(array $queryParams): int
    {
        return $this->languageUidResolver->fromParameters($queryParams, [], 0, true) ?? 0;
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
