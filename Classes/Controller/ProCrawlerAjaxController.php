<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;
use Priebera\A11yQualityGate\Pro\Enum\FeatureFlag;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Service\ProCapabilityService;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanInputResolver;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanPersistenceService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanRecoveryService;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\DateTimeService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\RemoteScanResponseService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\SecretEncryptionService;
use Priebera\A11yQualityGate\Service\SiteLanguageService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Utility\StringListUtility;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Backend\Attribute\AsController;

#[AsController]
final class ProCrawlerAjaxController extends AbstractApiController
{
    public function __construct(
        private readonly ProCrawlerService $proCrawlerService,
        private readonly AccessControlService $accessControlService,
        private readonly BackendRecordAccessService $backendRecordAccessService,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly RemoteScanPersistenceService $remoteScanPersistenceService,
        private readonly RemoteScanInputResolver $remoteScanInputResolver,
        private readonly ProCapabilityService $proCapabilityService,
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly ExtensionContextService $extensionContextService,
        private readonly DateTimeService $dateTimeService,
        private readonly RemoteScanResponseService $remoteScanResponseService,
        private readonly RemoteScanRecoveryService $remoteScanRecoveryService,
        private readonly RulesetRepository $rulesetRepository,
        private readonly SecretEncryptionService $secretEncryptionService,
        private readonly SiteLanguageService $siteLanguageService,
        private readonly RequestParameterService $requestParameterService,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        BackendUserService $backendUserService,
    ) {
        parent::__construct($responseFactory, $streamFactory, $backendUserService);
    }

    public function submitSiteAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureBackendUserAccess($this->accessControlService, 'scanAll');
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        $rootPid = (int)($data['rootPid'] ?? 0);
        $maxPages = max(1, min(1000, (int)($data['maxPages'] ?? 200)));
        $axeLocale = trim((string)($data['axeLocale'] ?? 'en'));
        $cookieDismiss = array_key_exists('cookieDismiss', $data)
            ? (bool)$data['cookieDismiss']
            : true;
        $languageUid = $this->requestParameterService->getLanguageUidFromParameters($data);

        if ($rootPid <= 0) {
            return $this->badRequestResponse('Missing rootPid');
        }

        if (!$this->backendRecordAccessService->canEditRecord(Tables::PAGES, $rootPid)) {
            return $this->forbiddenResponse();
        }

        try {
            $site = $this->siteResolutionService->resolveSiteFromPageId($rootPid);

            $languageContext = $this->siteLanguageService->resolveLanguageContext($site, $languageUid);
            $resolved = $languageContext !== null
                ? $this->remoteScanInputResolver->resolveForOverviewLanguage(
                    site: $site,
                    language: $languageContext,
                    maxPages: $maxPages,
                    axeLocale: $axeLocale !== '' ? $axeLocale : 'en',
                )
                : $this->remoteScanInputResolver->resolveForOverview(
                    site: $site,
                    maxPages: $maxPages,
                    axeLocale: $axeLocale !== '' ? $axeLocale : 'en',
                );
            $languageCode = $this->siteLanguageService->resolveLanguageCode($languageContext);

            if (
                $resolved->domain === ''
                || $resolved->siteIdentifier === ''
                || $resolved->startUrl === ''
            ) {
                return $this->badRequestResponse('Missing site configuration');
            }

            $proStatus = $this->resolveProStatus($resolved->domain);
            $crawlerAccessResponse = $this->ensureCrawlerAccess($proStatus);
            if ($crawlerAccessResponse !== null) {
                return $crawlerAccessResponse;
            }

            $activeScan = $this->remoteScanRepository->findLatestActiveScanBySite($resolved->siteIdentifier);

            if (is_array($activeScan)) {
                $activeScan = $this->remoteScanRecoveryService->recoverScanIfNeeded(
                    $activeScan,
                    (string)$site->getBase(),
                );

                $activeStatus = trim((string)($activeScan['status'] ?? ''));

                if (in_array($activeStatus, ['waiting', 'queued', 'active', 'running'], true)) {
                    return $this->jsonResponse(
                        $this->remoteScanResponseService->buildActiveScanConflictPayload(
                            $activeScan,
                            $resolved->siteIdentifier
                        ),
                        409
                    );
                }
            }

            $captureScreenshot = $this->canCaptureScreenshot($proStatus);
            $remoteAccessSettings = $this->buildRemoteAccessSettingsForCrawl($resolved->siteIdentifier);

            $result = $this->proCrawlerService->submit(
                domain: $resolved->domain,
                version: $this->extensionContextService->getExtensionVersion(),
                siteId: $resolved->siteIdentifier,
                startUrl: $resolved->startUrl,
                sitemapUrl: $resolved->sitemapUrl,
                sourceType: $resolved->sourceType,
                maxPages: $resolved->maxPages,
                followLinks: $resolved->followLinks,
                axeLocale: $resolved->axeLocale,
                captureScreenshot: $captureScreenshot,
                cookieDismiss: $cookieDismiss,
                scannerPreviewToken: $remoteAccessSettings['scannerPreviewToken'],
                httpAuthUser: $remoteAccessSettings['httpAuthUser'],
                httpAuthPass: $remoteAccessSettings['httpAuthPass'],
                excludedPatterns: $remoteAccessSettings['excludedPatterns'],
                priorityUrls: $remoteAccessSettings['priorityUrls'],
                cookieSelectors: $remoteAccessSettings['cookieSelectors'],
                languageId: $languageContext !== null ? (int)$languageContext['languageId'] : null,
                languageCode: $languageCode,
            );

            $this->remoteScanRepository->markSubmitted(
                siteIdentifier: $resolved->siteIdentifier,
                jobId: $result->jobId,
                sourceType: $resolved->sourceType,
                startUrl: $resolved->startUrl,
                sitemapUrl: $resolved->sitemapUrl,
                status: $result->status,
                scanScope: 'site',
                pageUid: 0,
                languageUid: $languageContext !== null ? (int)$languageContext['languageId'] : -1,
            );

            return $this->jsonResponse([
                'success' => true,
                'jobId' => $result->jobId,
                'status' => $result->status,
                'siteIdentifier' => $resolved->siteIdentifier,
                'startUrl' => $resolved->startUrl,
                'sourceType' => $resolved->sourceType->value,
                'sitemapUrl' => $resolved->sitemapUrl,
                'languageId' => $languageContext !== null ? (int)$languageContext['languageId'] : null,
                'languageUid' => $languageContext !== null ? (int)$languageContext['languageId'] : -1,
                'languageCode' => $languageCode,
                'captureScreenshot' => $captureScreenshot,
                'cookieDismiss' => $cookieDismiss,
                'cookieSelectorsConfigured' => $remoteAccessSettings['cookieSelectors'] !== [],
                'cookieSelectorsCount' => count($remoteAccessSettings['cookieSelectors']),
            ]);
        } catch (TokenRefreshException $exception) {
            return $this->buildTokenRefreshExceptionResponse(
                $exception,
                'Remote crawler scan failed.'
            );
        } catch (\Throwable $exception) {
            return $this->buildCrawlerExceptionResponse(
                $exception,
                'Remote crawler scan failed.'
            );
        }
    }

    public function submitPageAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureBackendUserAccess($this->accessControlService, 'scanAll');
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        $pageUid = (int)($data['pageUid'] ?? 0);
        $pageUrl = trim((string)($data['pageUrl'] ?? ''));
        $siteIdentifier = trim((string)($data['siteIdentifier'] ?? ''));
        $axeLocale = trim((string)($data['axeLocale'] ?? 'en'));
        $cookieDismiss = array_key_exists('cookieDismiss', $data)
            ? (bool)$data['cookieDismiss']
            : true;
        $languageUid = $this->requestParameterService->getLanguageUidFromParameters($data);

        if ($pageUid <= 0 || $pageUrl === '' || $siteIdentifier === '') {
            return $this->badRequestResponse('Missing pageUid, pageUrl or siteIdentifier');
        }

        if (!$this->backendRecordAccessService->canEditRecord(Tables::PAGES, $pageUid)) {
            return $this->forbiddenResponse();
        }

        try {
            $site = $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier);
            if ($site === null) {
                return $this->badRequestResponse('Unknown siteIdentifier');
            }
            $languageContext = $this->siteLanguageService->resolveLanguageContext($site, $languageUid);
            $languageCode = $this->siteLanguageService->resolveLanguageCode($languageContext);

            $resolved = $this->remoteScanInputResolver->resolveForSinglePage(
                site: $site,
                pageUrl: $pageUrl,
                axeLocale: $axeLocale !== '' ? $axeLocale : 'en',
            );

            if (
                $resolved->domain === ''
                || $resolved->siteIdentifier === ''
                || $resolved->startUrl === ''
            ) {
                return $this->badRequestResponse('Missing page configuration');
            }

            $proStatus = $this->resolveProStatus($resolved->domain);
            $crawlerAccessResponse = $this->ensureCrawlerAccess($proStatus);

            if ($crawlerAccessResponse !== null) {
                return $crawlerAccessResponse;
            }

            $activeScan = $this->remoteScanRepository->findLatestActiveScanBySite($resolved->siteIdentifier);

            if (is_array($activeScan)) {
                $activeScan = $this->remoteScanRecoveryService->recoverScanIfNeeded(
                    $activeScan,
                    (string)$site->getBase(),
                );

                $activeStatus = trim((string)($activeScan['status'] ?? ''));

                if (in_array($activeStatus, ['waiting', 'queued', 'active', 'running'], true)) {
                    return $this->jsonResponse(
                        $this->remoteScanResponseService->buildActiveScanConflictPayload(
                            $activeScan,
                            $resolved->siteIdentifier
                        ),
                        409
                    );
                }
            }

            $captureScreenshot = $this->canCaptureScreenshot($proStatus);
            $remoteAccessSettings = $this->buildRemoteAccessSettingsForCrawl($resolved->siteIdentifier);

            $result = $this->proCrawlerService->submit(
                domain: $resolved->domain,
                version: $this->extensionContextService->getExtensionVersion(),
                siteId: $resolved->siteIdentifier,
                startUrl: $resolved->startUrl,
                sitemapUrl: null,
                sourceType: RemoteScanSourceType::SinglePage,
                maxPages: 1,
                followLinks: false,
                axeLocale: $resolved->axeLocale,
                captureScreenshot: $captureScreenshot,
                cookieDismiss: $cookieDismiss,
                scannerPreviewToken: $remoteAccessSettings['scannerPreviewToken'],
                httpAuthUser: $remoteAccessSettings['httpAuthUser'],
                httpAuthPass: $remoteAccessSettings['httpAuthPass'],
                excludedPatterns: $remoteAccessSettings['excludedPatterns'],
                priorityUrls: $remoteAccessSettings['priorityUrls'],
                cookieSelectors: $remoteAccessSettings['cookieSelectors'],
                languageId: $languageContext !== null ? (int)$languageContext['languageId'] : null,
                languageCode: $languageCode,
            );

            $this->remoteScanRepository->markSubmitted(
                siteIdentifier: $resolved->siteIdentifier,
                jobId: $result->jobId,
                sourceType: RemoteScanSourceType::SinglePage,
                startUrl: $resolved->startUrl,
                sitemapUrl: null,
                status: $result->status,
                scanScope: 'page',
                pageUid: $pageUid,
                languageUid: $languageContext !== null ? (int)$languageContext['languageId'] : -1,
            );

            return $this->jsonResponse([
                'success' => true,
                'jobId' => $result->jobId,
                'status' => $result->status,
                'siteIdentifier' => $resolved->siteIdentifier,
                'startUrl' => $resolved->startUrl,
                'sourceType' => RemoteScanSourceType::SinglePage->value,
                'sitemapUrl' => null,
                'languageId' => $languageContext !== null ? (int)$languageContext['languageId'] : null,
                'languageUid' => $languageContext !== null ? (int)$languageContext['languageId'] : -1,
                'languageCode' => $languageCode,
                'captureScreenshot' => $captureScreenshot,
                'cookieDismiss' => $cookieDismiss,
                'cookieSelectorsConfigured' => $remoteAccessSettings['cookieSelectors'] !== [],
                'cookieSelectorsCount' => count($remoteAccessSettings['cookieSelectors']),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return $this->badRequestResponse($exception->getMessage());
        } catch (TokenRefreshException $exception) {
            return $this->buildTokenRefreshExceptionResponse(
                $exception,
                'Remote page scan failed.'
            );
        } catch (\Throwable $exception) {
            return $this->buildCrawlerExceptionResponse(
                $exception,
                'Remote page scan failed.'
            );
        }
    }

    public function cancelSiteAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureBackendUserAccess($this->accessControlService, 'scanAll');
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        $jobId = trim((string)($data['jobId'] ?? ''));
        $siteIdentifier = trim((string)($data['siteIdentifier'] ?? ''));

        if ($jobId === '' || $siteIdentifier === '') {
            return $this->badRequestResponse('Missing jobId or siteIdentifier');
        }

        try {
            $site = $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier);
            if ($site === null) {
                return $this->badRequestResponse('Unknown siteIdentifier');
            }
            $domain = $this->resolveDomainFromSiteBase((string)$site->getBase());

            $proStatus = $this->resolveProStatus($domain);
            $crawlerAccessResponse = $this->ensureCrawlerAccess($proStatus);
            if ($crawlerAccessResponse !== null) {
                return $crawlerAccessResponse;
            }

            $result = $this->proCrawlerService->cancel(
                domain: $domain,
                version: $this->extensionContextService->getExtensionVersion(),
                jobId: $jobId,
            );

            $status = trim((string)($result['status'] ?? 'cancelled'));
            if ($status === '') {
                $status = 'cancelled';
            }

            if ($status === 'cancelled') {
                $this->remoteScanRepository->markCancelled($jobId, 'Cancelled by backend user.');
            } else {
                $this->remoteScanRepository->syncStatus(
                    jobId: $jobId,
                    status: $status,
                    pagesScanned: null,
                    pagesTotal: null,
                    startedAt: null,
                    finishedAt: null,
                );
            }

            return $this->jsonResponse([
                'success' => true,
                'jobId' => (string)($result['jobId'] ?? $jobId),
                'status' => $status,
                'alreadyFinished' => (bool)($result['alreadyFinished'] ?? false),
                'bullJobState' => (string)($result['bullJobState'] ?? ''),
                'bullJobRemoved' => (bool)($result['bullJobRemoved'] ?? false),
            ]);
        } catch (TokenRefreshException $exception) {
            return $this->buildTokenRefreshExceptionResponse(
                $exception,
                'Remote crawler cancel request failed.'
            );
        } catch (\Throwable $exception) {
            return $this->buildCrawlerExceptionResponse(
                $exception,
                'Remote crawler cancel request failed.'
            );
        }
    }

    public function statusAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureBackendUserAccess($this->accessControlService, 'scanAll');
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $params = $request->getQueryParams();
        $jobId = trim((string)($params['jobId'] ?? ''));
        $siteIdentifier = trim((string)($params['siteIdentifier'] ?? ''));

        if ($jobId === '' || $siteIdentifier === '') {
            return $this->badRequestResponse('Missing jobId or siteIdentifier');
        }

        try {
            $site = $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier);
            if ($site === null) {
                return $this->badRequestResponse('Unknown siteIdentifier');
            }
            $domain = $this->resolveDomainFromSiteBase((string)$site->getBase());

            $proStatus = $this->resolveProStatus($domain);
            $crawlerAccessResponse = $this->ensureCrawlerAccess($proStatus);
            if ($crawlerAccessResponse !== null) {
                return $crawlerAccessResponse;
            }

            $result = $this->proCrawlerService->getStatus(
                domain: $domain,
                version: $this->extensionContextService->getExtensionVersion(),
                jobId: $jobId,
            );

            $this->remoteScanRepository->syncStatus(
                jobId: $jobId,
                status: $result->status->value,
                pagesScanned: $result->pagesScanned,
                pagesTotal: $result->pagesTotal,
                startedAt: $this->dateTimeService->toNullableTimestamp($result->startedAt),
                finishedAt: $this->dateTimeService->toNullableTimestamp($result->finishedAt),
            );

            $scan = $this->remoteScanRepository->findScanByJobId($jobId);
            if (is_array($scan) && (string)($scan['status'] ?? '') === 'completed' && (int)($scan['persisted_at'] ?? 0) <= 0) {
                $scan = $this->remoteScanRecoveryService->recoverScanIfNeeded(
                    $scan,
                    (string)$site->getBase(),
                );
            }

            return $this->jsonResponse([
                'success' => true,
                'jobId' => $result->jobId,
                'status' => is_array($scan)
                    ? (string)($scan['status'] ?? $result->status->value)
                    : $result->status->value,
                'pagesScanned' => is_array($scan)
                    ? (int)($scan['pages_scanned'] ?? $result->pagesScanned)
                    : $result->pagesScanned,
                'pagesTotal' => is_array($scan)
                    ? ((int)($scan['pages_total'] ?? 0) > 0 ? (int)$scan['pages_total'] : $result->pagesTotal)
                    : $result->pagesTotal,
                'startedAt' => $result->startedAt,
                'finishedAt' => is_array($scan) && (int)($scan['finished_at'] ?? 0) > 0
                    ? date(DATE_ATOM, (int)$scan['finished_at'])
                    : $result->finishedAt,
                'syncError' => is_array($scan) ? (string)($scan['sync_error'] ?? '') : '',
                'persisted' => is_array($scan) && (int)($scan['persisted_at'] ?? 0) > 0,
                'languageUid' => is_array($scan) ? (int)($scan['language_uid'] ?? -1) : -1,
            ]);
        } catch (TokenRefreshException $exception) {
            return $this->buildTokenRefreshExceptionResponse(
                $exception,
                'Remote crawler status request failed.'
            );
        } catch (\Throwable $exception) {
            return $this->buildCrawlerExceptionResponse(
                $exception,
                'Remote crawler status request failed.'
            );
        }
    }

    public function summaryAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureBackendUserAccess($this->accessControlService, 'scanAll');
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $params = $request->getQueryParams();
        $jobId = trim((string)($params['jobId'] ?? ''));
        $siteIdentifier = trim((string)($params['siteIdentifier'] ?? ''));

        if ($jobId === '' || $siteIdentifier === '') {
            return $this->badRequestResponse('Missing jobId or siteIdentifier');
        }

        if ($this->remoteScanRepository->isPersisted($jobId)) {
            return $this->jsonResponse([
                'success' => true,
                'saved' => true,
                'jobId' => $jobId,
                'alreadyPersisted' => true,
            ]);
        }

        try {
            $site = $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier);
            if ($site === null) {
                return $this->badRequestResponse('Unknown siteIdentifier');
            }
            $domain = $this->resolveDomainFromSiteBase((string)$site->getBase());

            $proStatus = $this->resolveProStatus($domain);
            $crawlerAccessResponse = $this->ensureCrawlerAccess($proStatus);
            if ($crawlerAccessResponse !== null) {
                return $crawlerAccessResponse;
            }

            $summaryResult = $this->proCrawlerService->getSummary(
                domain: $domain,
                version: $this->extensionContextService->getExtensionVersion(),
                jobId: $jobId,
            );

            $resultsResult = $this->proCrawlerService->getResults(
                domain: $domain,
                version: $this->extensionContextService->getExtensionVersion(),
                jobId: $jobId,
            );

            $existingScan = $this->remoteScanRepository->findScanByJobId($jobId);

            $sourceType = $this->remoteScanResponseService->resolveSourceType(
                (string)$summaryResult->sourceType
            );

            $resultsPayload = $this->remoteScanResponseService->buildResultsPayload(
                summaryResult: $summaryResult,
                resultsResult: $resultsResult,
                existingScan: is_array($existingScan) ? $existingScan : null,
            );

            $pagesTotal = (int)($resultsPayload['pagesTotal'] ?? 0);

            $this->remoteScanPersistenceService->persistResults(
                siteIdentifier: $siteIdentifier,
                jobId: $summaryResult->jobId,
                sourceType: $sourceType,
                startUrl: $summaryResult->startUrl,
                sitemapUrl: $summaryResult->sitemapUrl,
                resultsData: $resultsPayload,
            );

            return $this->jsonResponse([
                'success' => true,
                'saved' => true,
                'jobId' => $summaryResult->jobId,
                'siteId' => $summaryResult->siteId,
                'startUrl' => $summaryResult->startUrl,
                'sitemapUrl' => $summaryResult->sitemapUrl,
                'sourceType' => $summaryResult->sourceType,
                'status' => $summaryResult->status,
                'pagesScanned' => $summaryResult->pagesScanned,
                'pagesTotal' => $pagesTotal,
                'pagesFailed' => $summaryResult->pagesFailed,
                'issuesTotal' => $summaryResult->issuesTotal,
                'issuesNew' => $summaryResult->issuesNew,
                'issuesResolved' => $summaryResult->issuesResolved,
                'topPages' => $summaryResult->topPages,
                'failedPages' => $summaryResult->failedPages,
                'topRules' => $summaryResult->topRules,
                'countsByStatus' => $summaryResult->countsByStatus,
                'startedAt' => $summaryResult->startedAt,
                'finishedAt' => $summaryResult->finishedAt,
            ]);
        } catch (TokenRefreshException $exception) {
            return $this->buildTokenRefreshExceptionResponse(
                $exception,
                'Remote crawler summary request failed.'
            );
        } catch (\Throwable $exception) {
            return $this->buildCrawlerExceptionResponse(
                $exception,
                'Remote crawler summary request failed.'
            );
        }
    }

    /**
     * @return object
     */
    private function resolveProStatus(string $domain): object
    {
        return $this->proCapabilityService->getStatus(
            $domain,
            $this->extensionContextService->getExtensionVersion()
        );
    }

    private function ensureCrawlerAccess(object $proStatus): ?ResponseInterface
    {
        if ($proStatus->valid && $proStatus->hasCrawler) {
            return null;
        }

        return $this->buildSimpleErrorResponse(
            message: 'Remote crawler is available in AQG PRO only.',
            status: 403,
            code: 'pro_crawler_required',
            title: 'AQG PRO required'
        );
    }


    /**
     * @return array{scannerPreviewToken:string,httpAuthUser:string,httpAuthPass:string,excludedPatterns:list<string>,priorityUrls:list<string>,cookieSelectors:list<string>}
     */
    private function buildRemoteAccessSettingsForCrawl(string $siteIdentifier = ''): array
    {
        $defaultRuleset = $this->rulesetRepository->findDefault();
        $siteRuleset = $siteIdentifier !== ''
            ? $this->rulesetRepository->findBySiteIdentifier($siteIdentifier)
            : null;

        if (!is_array($defaultRuleset) && !is_array($siteRuleset)) {
            return [
                'scannerPreviewToken' => '',
                'httpAuthUser' => '',
                'httpAuthPass' => '',
                'excludedPatterns' => [],
                'priorityUrls' => [],
                'cookieSelectors' => [],
            ];
        }

        $scannerToken = trim((string)($defaultRuleset['scanner_token'] ?? ''));
        $httpAuthUser = $this->firstNonEmptyRulesetValue($siteRuleset, $defaultRuleset, 'http_auth_user');
        $encryptedHttpAuthPass = $this->firstNonEmptyRulesetValue($siteRuleset, $defaultRuleset, 'http_auth_pass');
        $excludedPatterns = $this->firstNonEmptyRulesetList($siteRuleset, $defaultRuleset, 'excluded_patterns');
        $priorityUrls = $this->firstNonEmptyRulesetList($siteRuleset, $defaultRuleset, 'crawl_priority_urls');
        $cookieSelectors = $this->firstNonEmptyRulesetList($siteRuleset, $defaultRuleset, 'cookie_accept_selectors');

        $httpAuthPass = $encryptedHttpAuthPass;
        if ($httpAuthPass !== '') {
            $httpAuthPass = $this->secretEncryptionService->decrypt($httpAuthPass);
        }

        return [
            'scannerPreviewToken' => $scannerToken,
            'httpAuthUser' => $httpAuthUser,
            'httpAuthPass' => $httpAuthPass,
            'excludedPatterns' => $excludedPatterns,
            'priorityUrls' => $priorityUrls,
            'cookieSelectors' => $cookieSelectors,
        ];
    }

    /**
     * @param array<string, mixed>|null $siteRuleset
     * @param array<string, mixed>|null $defaultRuleset
     */
    private function firstNonEmptyRulesetValue(?array $siteRuleset, ?array $defaultRuleset, string $field): string
    {
        $siteValue = trim((string)($siteRuleset[$field] ?? ''));
        if ($siteValue !== '') {
            return $siteValue;
        }

        return trim((string)($defaultRuleset[$field] ?? ''));
    }

    /**
     * @param array<string, mixed>|null $siteRuleset
     * @param array<string, mixed>|null $defaultRuleset
     * @return list<string>
     */
    private function firstNonEmptyRulesetList(?array $siteRuleset, ?array $defaultRuleset, string $field): array
    {
        $siteList = StringListUtility::decodeJsonList((string)($siteRuleset[$field] ?? '[]'));
        if ($siteList !== []) {
            return $siteList;
        }

        return StringListUtility::decodeJsonList((string)($defaultRuleset[$field] ?? '[]'));
    }

    private function canCaptureScreenshot(object $proStatus): bool
    {
        return in_array(
            FeatureFlag::ScreenshotCapture->value,
            $proStatus->features,
            true
        );
    }

    private function resolveDomainFromSiteBase(string $siteBase): string
    {
        return $this->extensionContextService->getNormalizedDomainFromSiteBase($siteBase);
    }

    private function buildTokenRefreshExceptionResponse(
        TokenRefreshException $exception,
        string $fallbackMessage,
    ): ResponseInterface {
        foreach ($this->collectExceptionMessages($exception) as $message) {
            $normalized = strtolower($message);

            if (
                str_contains($normalized, 'aqg crawler http')
                || str_contains($normalized, 'aqg crawler logical error')
                || str_contains($normalized, 'remote crawler submit failed:')
                || str_contains($normalized, 'remote crawler results request failed:')
                || str_contains($normalized, 'remote crawler status request failed:')
                || str_contains($normalized, 'remote crawler summary request failed:')
                || str_contains($normalized, 'remote crawler cancel request failed:')
            ) {
                return $this->buildCrawlerExceptionResponse($exception, $fallbackMessage);
            }
        }

        return $this->buildSimpleErrorResponse(
            message: $exception->getMessage(),
            status: 403,
            code: 'token_refresh_failed',
            title: 'PRO authentication failed'
        );
    }

    /**
     * @return list<string>
     */
    private function collectExceptionMessages(\Throwable $exception): array
    {
        $messages = [];
        $current = $exception;

        do {
            $message = trim($current->getMessage());
            if ($message !== '') {
                $messages[] = $message;
            }

            $current = $current->getPrevious();
        } while ($current instanceof \Throwable);

        return $messages;
    }

    private function resolveCrawlerExceptionMessage(\Throwable $exception, string $fallbackMessage): string
    {
        $messages = $this->collectExceptionMessages($exception);

        foreach ($messages as $message) {
            $normalized = strtolower($message);

            if (
                str_contains($normalized, 'aqg crawler http')
                || str_contains($normalized, 'aqg crawler logical error')
                || str_contains($normalized, 'remote crawler submit failed:')
                || str_contains($normalized, 'remote crawler results request failed:')
                || str_contains($normalized, 'remote crawler status request failed:')
                || str_contains($normalized, 'remote crawler summary request failed:')
                || str_contains($normalized, 'remote crawler cancel request failed:')
                || str_contains($normalized, '| body=')
            ) {
                return $message;
            }
        }

        foreach ($messages as $message) {
            if ($message !== '') {
                return $message;
            }
        }

        return $fallbackMessage;
    }

    private function buildSimpleErrorResponse(
        string $message,
        int $status,
        string $code,
        string $title,
        array $details = [],
    ): ResponseInterface {
        return $this->jsonResponse([
            'success' => false,
            'code' => $code,
            'title' => $title,
            'message' => $message,
            'status' => $status,
            'details' => $details,
            'error' => $message,
        ], $status);
    }

    private function buildCrawlerExceptionResponse(
        \Throwable $exception,
        string $fallbackMessage,
    ): ResponseInterface {
        $payload = $this->buildCrawlerExceptionPayload($exception, $fallbackMessage);
        $status = (int)($payload['status'] ?? 500);

        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        return $this->jsonResponse($payload, $status);
    }

    private function buildCrawlerExceptionPayload(
        \Throwable $exception,
        string $fallbackMessage,
    ): array {
        $rawMessage = $this->resolveCrawlerExceptionMessage($exception, $fallbackMessage);
        $status = 500;
        $code = 'remote_crawler_request_failed';
        $message = $rawMessage !== '' ? $rawMessage : $fallbackMessage;
        $details = [];

        if (
            preg_match('/AQG crawler HTTP\s+(?<status>\d+):\s*(?<message>[^|]+)/i', $rawMessage, $matches) === 1
        ) {
            $status = (int)($matches['status'] ?? 500);
            $message = trim((string)($matches['message'] ?? $message));
        }

        $decodedBody = $this->decodeCrawlerErrorBody($rawMessage);
        if (is_array($decodedBody)) {
            $errorPayload = is_array($decodedBody['error'] ?? null)
                ? $decodedBody['error']
                : $decodedBody;

            $resolvedCode = trim((string)($errorPayload['code'] ?? ''));
            if ($resolvedCode !== '') {
                $code = $resolvedCode;
            }

            $resolvedMessage = trim((string)($errorPayload['message'] ?? ''));
            if ($resolvedMessage !== '') {
                $message = $resolvedMessage;
            }

            $resolvedStatus = (int)($errorPayload['status'] ?? $decodedBody['status'] ?? 0);
            if ($resolvedStatus >= 400 && $resolvedStatus <= 599) {
                $status = $resolvedStatus;
            }

            $resolvedDetails = $errorPayload['details'] ?? null;
            if (is_array($resolvedDetails)) {
                $details = $resolvedDetails;
            }
        }

        if ($message === '') {
            $message = $fallbackMessage;
        }

        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        return [
            'success' => false,
            'code' => $code,
            'title' => $this->resolveCrawlerErrorTitle($code, $status),
            'message' => $message,
            'status' => $status,
            'details' => $details,
            'error' => $message,
        ];
    }

    private function decodeCrawlerErrorBody(string $rawMessage): ?array
    {
        if (
            preg_match('/\|\s*body=(\{.*\})\s*$/s', $rawMessage, $matches) !== 1
            || !isset($matches[1])
        ) {
            return null;
        }

        $decoded = json_decode($matches[1], true);

        return is_array($decoded) ? $decoded : null;
    }

    private function resolveCrawlerErrorTitle(string $code, int $status): string
    {
        return match ($code) {
            'trial_crawl_limit' => 'Trial limit reached',
            'token_refresh_failed' => 'PRO authentication failed',
            'pro_crawler_required' => 'AQG PRO required',
            'forbidden_resource' => 'Remote scan access lost',
            default => $status === 429
                ? 'Remote scan limit reached'
                : ($status === 403 ? 'Remote scan not allowed' : 'Remote scan failed'),
        };
    }
}
