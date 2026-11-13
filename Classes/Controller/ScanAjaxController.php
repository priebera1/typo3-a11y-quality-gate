<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Service\RemoteResultAuthorizationService;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\LanguageUidResolver;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Exception\ScanCancelledException;
use Priebera\A11yQualityGate\Service\ScanResultSummaryService;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsController]
final class ScanAjaxController extends AbstractApiController
{
    private const STORED_FAILURE_MESSAGE = 'The content scan failed. Details are in the TYPO3 log.';

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
        private readonly ScopeAccessService $scopeAccessService,
        private readonly ?ScanResultSummaryService $scanResultSummaryService = null,
        private readonly ?RemoteResultAuthorizationService $remoteResultAuthorizationService = null,
    ) {
        parent::__construct($responseFactory, $streamFactory, $backendUserService);
    }

    /**
     * What is open in the scanned scope after the scan, and the report that shows it (NOT-01): the notification states
     * the total, not only the change. Omitted when it cannot be read; the client then names only the change.
     *
     * @return array<string, mixed>
     */
    private function resultSummary(string $scope, string $siteIdentifier, int $uid, int $languageUid): array
    {
        if (!$this->scanResultSummaryService instanceof ScanResultSummaryService || $siteIdentifier === '') {
            return [];
        }

        try {
            $backendUser = $this->getBackendUser();

            return [
                'scope' => $scope,
                'openIssues' => $this->scanResultSummaryService->contentOpenIssues($scope, $siteIdentifier, $uid, $languageUid, $this->scopeAccessService->readablePagesClause()),
                // STATE-01: the open issues are those this user may read; only administrators read every result. From the
                // user's rights alone, so the flag says nothing about whether hidden findings exist.
                'openIssuesRestricted' => !($backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin()),
                'reportUrl' => $this->scanResultSummaryService->contentReportUrl($scope, $siteIdentifier, $uid, $languageUid),
            ];
        } catch (\Throwable $exception) {
            $this->logScanFailure($exception);
            return [];
        }
    }

    /**
     * N-18: the language a content scan checked, named in its notification when the site has more than one language
     * ("Content scan (Deutsch) completed"). Empty for single-language sites and "all languages" scans.
     */
    private function scannedLanguageLabel(string $siteIdentifier, int $languageUid): string
    {
        if ($languageUid < 0 || $siteIdentifier === '') {
            return '';
        }
        try {
            $site = $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier);
            if ($site === null || count($site->getLanguages()) < 2) {
                return '';
            }

            return $site->getLanguageById($languageUid)->getTitle();
        } catch (\Throwable) {
            return '';
        }
    }

    public function scanPageAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureBackendUserAccess($this->accessControlService, 'scanNow');
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $body = $request->getParsedBody();
        $bodyParams = is_array($body) ? $body : [];
        $pageUid = (int)($bodyParams['pageUid'] ?? 0);
        $languageUid = $this->languageUidResolver->fromParameters($bodyParams, [], 0, true) ?? 0;

        if ($pageUid <= 0) {
            return $this->badRequestResponse('Missing or invalid pageUid');
        }

        // Every authorization decision comes before the scan state is read or changed: a refused request neither starts
        // a scan nor learns about a running one, and gets the same answer whatever was refused.
        if (!$this->backendRecordAccessService->canEditRecord(Tables::PAGES, $pageUid)) {
            return $this->forbiddenResponse();
        }
        // I-01: a default-language page plus a language; a translation record's uid is no scan target (its rendered check
        // fetched the translation and stored it as a default-language scan).
        if (!$this->scopeAccessService->isDefaultLanguagePageRecord($pageUid)) {
            return $this->forbiddenResponse();
        }
        if (!$this->mayScanPageInLanguage($languageUid)) {
            return $this->forbiddenResponse();
        }
        // AUTH-01: the content collector reads the translated records of the page, so the actual translation record(s)
        // need the user's show and edit access too (every language for -1), as for a frontend or MCP scan.
        if (!$this->scopeAccessService->canScanContentPage($pageUid, $languageUid)) {
            return $this->forbiddenResponse();
        }

        $backendUser = $this->getBackendUser();
        $triggeredBy = $backendUser instanceof BackendUserAuthentication
            ? (string)($backendUser->user['username'] ?? 'unknown')
            : 'unknown';

        // NEW-06: one content scan at a time, decided atomically (a registry check followed by a write let two scans
        // start). The loser gets the same 409 as before, never a 500; the lock is released on every way out.
        $scanLock = $this->scanStatusService->acquireScanLock();
        if ($scanLock === null) {
            return $this->jsonResponse([
                'success' => false,
                'error' => $this->translate('scanAjax.alreadyRunning', 'A scan is already running.'),
                'status' => $this->presentLocalStatus($this->scanStatusService->getStatus()),
            ], 409);
        }

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
                shouldCancel: fn (int $scanUid): bool => !$this->scanStatusService->renewScanLock($scanLock)
                    || $this->scanStatusService->isCancellationRequested()
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
                'renderedCoverage' => $result->renderedCoverage(),
                'languageLabel' => $this->scannedLanguageLabel($siteIdentifier, $languageUid),
                'status' => $this->presentLocalStatus($this->scanStatusService->getStatus()),
            ] + $this->resultSummary('page', $siteIdentifier, $pageUid, $languageUid));
        } catch (ScanCancelledException) {
            $this->scanStatusService->markCancelled();

            return $this->jsonResponse([
                'success' => false,
                'code' => 'local_scan_cancelled',
                'message' => $this->translate('scanAjax.cancelled', 'Scan was cancelled.'),
                'status' => $this->presentLocalStatus($this->scanStatusService->getStatus()),
            ]);
        } catch (\Throwable $e) {
            if ($this->isMissingSiteConfigurationException($e)) {
                return $this->missingSiteConfigurationResponse(
                    pageUid: $pageUid,
                    scope: 'page'
                );
            }

            // The shared status is visible to every AQG user; the exception text stays in the log.
            $this->logScanFailure($e);
            if ($scanStarted) {
                $this->scanStatusService->markFailed(self::STORED_FAILURE_MESSAGE);
            }

            return $this->jsonResponse([
                'success' => false,
                'code' => 'local_scan_failed',
                'error' => $this->translate('scanAjax.failedGeneric', 'The content scan failed. Details are in the TYPO3 log.'),
                'status' => $this->presentLocalStatus($this->scanStatusService->getStatus()),
            ], 500);
        } finally {
            $this->scanStatusService->releaseScanLock($scanLock);
        }
    }

    public function scanSiteAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureBackendUserAccess($this->accessControlService, 'scanAll');
        if ($accessResponse !== null) {
            return $accessResponse;
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
        // I-01: the subtree starts at a default-language page. S-01: the requested language follows the same language
        // policy as a page scan (a language the user may not use, -1 only without a language restriction).
        if (!$this->scopeAccessService->isDefaultLanguagePageRecord($rootPid) || !$this->mayScanPageInLanguage($languageUid)) {
            return $this->forbiddenResponse();
        }

        $backendUser = $this->getBackendUser();
        $triggeredBy = $backendUser instanceof BackendUserAuthentication
            ? (string)($backendUser->user['username'] ?? 'unknown')
            : 'unknown';
        // AUTH-01: every page of the subtree needs the same admission as a single-page Content scan of it in the requested
        // language (edit access to the page and to its translation record(s)). Pages that fail are not checked and are
        // reported as not permitted; a subtree in which no page may be scanned is refused before any scan state changes.
        $pageFilter = $backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin()
            ? null
            : fn (int $pageUid): bool => $this->scopeAccessService->canScanContentPage($pageUid, $languageUid);
        if ($pageFilter !== null) {
            $plan = $this->scanOrchestrator->planSubtree($rootPid, 99, $languageUid, $pageFilter);
            if ((int)($plan['permittedPages'] ?? count($plan['pageUids'] ?? [])) === 0 && ($plan['notPermittedPageUids'] ?? []) !== []) {
                return $this->forbiddenResponse();
            }
        }

        // NEW-06: one content scan at a time, decided atomically (a registry check followed by a write let two scans
        // start). The loser gets the same 409 as before, never a 500; the lock is released on every way out.
        $scanLock = $this->scanStatusService->acquireScanLock();
        if ($scanLock === null) {
            return $this->jsonResponse([
                'success' => false,
                'error' => $this->translate('scanAjax.alreadyRunning', 'A scan is already running.'),
                'status' => $this->presentLocalStatus($this->scanStatusService->getStatus()),
            ], 409);
        }

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
                shouldCancel: fn (int $scanUid): bool => !$this->scanStatusService->renewScanLock($scanLock)
                    || $this->scanStatusService->isCancellationRequested()
                    || $this->scanRepository->isScanCancellationRequested($scanUid),
                onRunStarted: function (int $scanUid): void {
                    $this->scanStatusService->markScanRunStarted($scanUid);
                },
                includeRenderedPageCheck: true,
                pageFilter: $pageFilter,
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
                'renderedCoverage' => $result->renderedCoverage(),
                'languageLabel' => $this->scannedLanguageLabel($siteIdentifier, $languageUid),
                'pagesWithoutTranslation' => $result->pagesWithoutTranslation,
                // AUTH-01: pages of the subtree this user may not scan; not checked, so the result is incomplete for them.
                // Only pages the user may see are counted (review F10-3): the number of invisible pages is not theirs to learn.
                'pagesNotPermitted' => $result->notPermittedPageUids === [] ? 0 : count($this->scopeAccessService->readablePageUids($result->notPermittedPageUids)),
                'status' => $this->presentLocalStatus($this->scanStatusService->getStatus()),
            ] + $this->resultSummary('site', $siteIdentifier, $rootPid, $languageUid));
        } catch (ScanCancelledException) {
            $this->scanStatusService->markCancelled();

            return $this->jsonResponse([
                'success' => false,
                'code' => 'local_scan_cancelled',
                'message' => $this->translate('scanAjax.cancelled', 'Scan was cancelled.'),
                'status' => $this->presentLocalStatus($this->scanStatusService->getStatus()),
            ]);
        } catch (\Throwable $e) {
            if ($this->isMissingSiteConfigurationException($e)) {
                return $this->missingSiteConfigurationResponse(
                    pageUid: $rootPid,
                    scope: 'site'
                );
            }

            // The shared status is visible to every AQG user; the exception text stays in the log.
            $this->logScanFailure($e);
            if ($scanStarted) {
                $this->scanStatusService->markFailed(self::STORED_FAILURE_MESSAGE);
            }

            return $this->jsonResponse([
                'success' => false,
                'code' => 'local_scan_failed',
                'error' => $this->translate('scanAjax.failedGeneric', 'The content scan failed. Details are in the TYPO3 log.'),
                'status' => $this->presentLocalStatus($this->scanStatusService->getStatus()),
            ], 500);
        } finally {
            $this->scanStatusService->releaseScanLock($scanLock);
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
                'status' => $this->presentLocalStatus($this->scanStatusService->getStatus()),
                'message' => $this->translate('scanAjax.noScanRunning', 'No content scan is running.'),
            ]);
        }

        $status = $this->scanStatusService->getStatus();
        // One content scan runs per installation; only someone who may edit its page or site root may stop it.
        if (!$this->canActOnLocalScan($status)) {
            return $this->jsonResponse([
                'success' => false,
                'code' => 'local_scan_access_denied',
                'error' => $this->translate('scanAjax.cancelDenied', 'This content scan was started for a page you cannot edit.'),
                'status' => $this->presentLocalStatus($status),
            ], 403);
        }

        $scanUid = (int)($status['scanUid'] ?? 0);
        if ($scanUid > 0) {
            $this->scanRepository->requestScanCancellation($scanUid);
        }

        $this->scanStatusService->requestCancellation();

        return $this->jsonResponse([
            'success' => true,
            'status' => $this->presentLocalStatus($this->scanStatusService->getStatus()),
            'message' => $this->translate('scanAjax.cancellationRequested', 'Content scan cancellation was requested.'),
        ]);
    }

    public function scanStatusAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isBackendUserLoggedIn()) {
            return $this->unauthorizedResponse();
        }

        return $this->jsonResponse([
            'success' => true,
            'status' => $this->presentLocalStatus($this->scanStatusService->getStatus()),
            'remoteStatus' => $this->visibleRemoteStatus($this->resolveRemoteStatusForRequest($request)),
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
     * Sol post-review R03/R05: a scan the user may not read is not reported (a page scan of a deleted, moved or refused
     * page included), and users other than administrators get no crawler-wide counters — occurrences, new/resolved,
     * a site crawl's page counts — nor the stored synchronisation error.
     *
     * @param array<string, mixed>|null $scan
     * @return array<string, mixed>|null
     */
    private function visibleRemoteStatus(?array $scan): ?array
    {
        if ($scan === null || !$this->scopeAccessService->canReadRemoteScan($scan)) {
            return null;
        }
        if ($this->remoteResultAuthorizationService !== null && $this->remoteResultAuthorizationService->isUnrestrictedUser()) {
            return $scan;
        }
        foreach (['issues_total', 'issues_new', 'issues_resolved'] as $counter) {
            $scan[$counter] = 0;
        }
        if ((string)($scan['scan_scope'] ?? '') !== 'page') {
            foreach (['pages_scanned', 'pages_total', 'pages_failed'] as $counter) {
                $scan[$counter] = 0;
            }
        }
        unset($scan['sync_error'], $scan['reporting_summary_json'], $scan['api_summary_json']);
        $scan['countsWithheld'] = true;

        return $scan;
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

        if ($siteIdentifier === '' || !$this->scopeAccessService->canReadSiteIdentifier($siteIdentifier)) {
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

    /**
     * @param array<string, mixed> $status
     */
    private function canActOnLocalScan(array $status): bool
    {
        // AUTH-02: the same admission as starting that scan (page + translation + language, or root + language).
        return $this->scopeAccessService->canCancelLocalScan($status);
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function presentLocalStatus(array $status): array
    {
        return $this->scopeAccessService->restrictLocalScanStatus($status);
    }

    private function logScanFailure(\Throwable $exception): void
    {
        try {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(__CLASS__)
                ->error('AQG content scan failed', [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
        } catch (\Throwable) {
        }
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
            'status' => $this->presentLocalStatus($this->scanStatusService->getStatus()),
        ], 400);
    }

    /**
     * The requested language of a page scan follows AQG's language policy (S-01, ScopeAccessService::canUseLanguage()):
     * a concrete language, the default language 0 included, needs the user's language access. All languages (-1) would
     * scan and store findings for every language of the page, so it is admitted only for users whose languages are not
     * restricted (administrators and users without allowed_languages).
     */
    private function mayScanPageInLanguage(int $languageUid): bool
    {
        if ($languageUid >= 0) {
            return $this->scopeAccessService->canUseLanguage($languageUid);
        }
        $backendUser = $this->getBackendUser();

        return $backendUser instanceof BackendUserAuthentication && !$this->scopeAccessService->hasLanguageRestriction($backendUser);
    }
}
