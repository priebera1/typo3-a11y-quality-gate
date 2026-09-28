<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\FreePreview\FreePreviewException;
use Priebera\A11yQualityGate\FreePreview\FreeRemotePreviewService;
use Priebera\A11yQualityGate\FreePreview\FreeSubmitIntentService;
use Priebera\A11yQualityGate\Pro\Enum\FeatureFlag;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Service\ProCapabilityService;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanInputResolver;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanAccessSettingsService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanErrorPresenter;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanPersistenceService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanRecoveryService;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\DateTimeService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\FixVerificationService;
use Priebera\A11yQualityGate\Service\FrontendPageUrlService;
use Priebera\A11yQualityGate\Service\RemoteScanResponseService;
use Priebera\A11yQualityGate\Service\RemotePageScanTargetResolver;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteLanguageService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsController]
final class ProCrawlerAjaxController extends AbstractApiController
{
    public function __construct(
        private readonly ProCrawlerService $proCrawlerService,
        private readonly AccessControlService $accessControlService,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly RemoteScanPersistenceService $remoteScanPersistenceService,
        private readonly RemoteScanInputResolver $remoteScanInputResolver,
        private readonly ProCapabilityService $proCapabilityService,
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly ExtensionContextService $extensionContextService,
        private readonly DateTimeService $dateTimeService,
        private readonly RemoteScanResponseService $remoteScanResponseService,
        private readonly RemoteScanRecoveryService $remoteScanRecoveryService,
        private readonly SiteLanguageService $siteLanguageService,
        private readonly RequestParameterService $requestParameterService,
        private readonly FreeRemotePreviewService $freeRemotePreviewService,
        private readonly FreeSubmitIntentService $freeSubmitIntentService,
        private readonly FrontendPageUrlService $frontendPageUrlService,
        private readonly ScopeAccessService $scopeAccessService,
        private readonly RemotePageScanTargetResolver $remotePageScanTargetResolver,
        private readonly RemoteScanErrorPresenter $remoteScanErrorPresenter,
        private readonly FixVerificationService $fixVerificationService,
        private readonly RemoteScanAccessSettingsService $remoteScanAccessSettingsService,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        BackendUserService $backendUserService,
    ) {
        parent::__construct($responseFactory, $streamFactory, $backendUserService);
    }

    public function submitSiteAction(ServerRequestInterface $request): ResponseInterface
    {
        // The scope decides the permission: a paid site crawl needs scanAll, the Free Remote Preview scans
        // one page and needs scanNow. Both are checked once the entitlement is known.
        $accessResponse = $this->ensureBackendUserAccess($this->accessControlService);
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        $requestId = $this->buildRemoteSubmitRequestId($request);
        $rootPid = (int)($data['rootPid'] ?? 0);
        $requestedRootPid = $rootPid;
        $requestedPageUid = (int)($data['pageUid'] ?? $data['id'] ?? 0);
        $requestedSiteIdentifier = trim((string)($data['siteIdentifier'] ?? ''));
        $maxPages = max(1, min(1000, (int)($data['maxPages'] ?? 200)));
        $axeLocale = trim((string)($data['axeLocale'] ?? 'en'));
        $cookieDismiss = array_key_exists('cookieDismiss', $data)
            ? (bool)$data['cookieDismiss']
            : true;
        $languageUid = $this->requestParameterService->getLanguageUidFromParameters($data);
        $submitLock = null;

        if ($rootPid <= 0) {
            return $this->badRequestResponse('Missing rootPid');
        }

        try {
            $siteFromRootPid = $this->siteResolutionService->resolveSiteByPageId($rootPid);
            $site = null;

            if ($requestedSiteIdentifier !== '') {
                $site = $this->siteResolutionService->resolveSiteByIdentifier($requestedSiteIdentifier);
                if (!$site instanceof Site) {
                    return $this->badRequestResponse('Unknown siteIdentifier', [
                        'code' => 'unknown_site_identifier',
                        'requestId' => $requestId,
                    ]);
                }

                if (
                    $siteFromRootPid instanceof Site
                    && $siteFromRootPid->getIdentifier() !== $site->getIdentifier()
                ) {
                    $this->logRemoteSubmitDebug('submit-site:explicit-site-overrides-root-pid', [
                        'requestId' => $requestId,
                        'requestRootPid' => $requestedRootPid,
                        'requestPageId' => $requestedPageUid,
                        'requestSiteIdentifier' => $requestedSiteIdentifier,
                        'siteFromRootPidIdentifier' => $siteFromRootPid->getIdentifier(),
                        'siteFromRootPidRootPid' => (int)$siteFromRootPid->getRootPageId(),
                        'explicitSiteIdentifier' => $site->getIdentifier(),
                        'explicitSiteRootPid' => (int)$site->getRootPageId(),
                    ]);
                }
            }

            $site ??= $siteFromRootPid;
            if (!$site instanceof Site) {
                return $this->badRequestResponse('Unknown site context for rootPid', [
                    'code' => 'unknown_site_context',
                    'requestId' => $requestId,
                ]);
            }

            $rootPid = (int)$site->getRootPageId();
            if ($rootPid <= 0) {
                $rootPid = $requestedRootPid;
            }

            $proStatus = $this->resolveProStatus(
                $this->resolveDomainFromSiteBase((string)$site->getBase())
            );
            $isFreePreview = !$this->hasPaidCrawlerAccess($proStatus);
            $freePageUrl = '';
            $freeLanguageUid = max(0, $languageUid);

            $scopeAccessResponse = $this->ensureBackendUserAccess(
                $this->accessControlService,
                $isFreePreview ? 'scanNow' : 'scanAll'
            );
            if ($scopeAccessResponse !== null) {
                return $scopeAccessResponse;
            }

            if ($isFreePreview) {
                if ($requestedPageUid <= 0) {
                    return $this->badRequestResponse('Missing pageUid', [
                        'code' => 'missing_page_uid',
                        'requestId' => $requestId,
                    ]);
                }

                $pageSite = $this->siteResolutionService->resolveSiteByPageId($requestedPageUid);
                if (!$pageSite instanceof Site || $pageSite->getIdentifier() !== $site->getIdentifier()) {
                    return $this->badRequestResponse('Selected page does not belong to the requested site', [
                        'code' => 'invalid_page_context',
                        'requestId' => $requestId,
                    ]);
                }

                if (!$this->scopeAccessService->canEditPage($requestedPageUid)) {
                    return $this->forbiddenResponse();
                }

                // The selected language's public URL: a translated page is scanned as translated, and an
                // untranslated one is refused instead of silently scanning the default language.
                $freePageUrl = trim($this->frontendPageUrlService->resolvePublicForPage($site, $requestedPageUid, $freeLanguageUid));
                if ($freePageUrl === '') {
                    return $this->badRequestResponse('Unable to resolve selected page URL', [
                        'code' => 'unresolved_page_url',
                        'requestId' => $requestId,
                    ]);
                }
            } elseif (!$this->scopeAccessService->canEditSite($site)) {
                return $this->forbiddenResponse();
            }

            $languageContext = $this->siteLanguageService->resolveLanguageContext(
                $site,
                $isFreePreview ? $freeLanguageUid : $languageUid
            );
            $resolved = $isFreePreview
                ? $this->remoteScanInputResolver->resolveForFreePreview($site, $freePageUrl)
                : ($languageContext !== null
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
                ));
            $languageCode = $this->siteLanguageService->resolveLanguageCode($languageContext);

            $this->logRemoteSubmitDebug('submit-site:resolved-context', [
                'requestId' => $requestId,
                'requestedRootPid' => $requestedRootPid,
                'requestPageId' => $requestedPageUid,
                'requestedSiteIdentifier' => $requestedSiteIdentifier,
                'resolvedSiteIdentifier' => $resolved->siteIdentifier,
                'resolvedRootPid' => (int)$site->getRootPageId(),
                'resolvedBaseUrl' => (string)$site->getBase(),
                'resolvedStartUrl' => $resolved->startUrl,
                'resolvedSitemapUrl' => (string)($resolved->sitemapUrl ?? ''),
                'resolvedDomain' => $resolved->domain,
                'apiPayloadSiteId' => $resolved->siteIdentifier,
                'apiPayloadStartUrl' => $resolved->startUrl,
                'apiPayloadSourceType' => $resolved->sourceType->value,
                'languageUid' => $languageContext !== null ? (int)$languageContext['languageId'] : -1,
                'languageCode' => $languageCode,
            ]);

            if (
                $resolved->domain === ''
                || $resolved->siteIdentifier === ''
                || $resolved->startUrl === ''
            ) {
                return $this->badRequestResponse('Missing site configuration');
            }

            $submitLock = $this->acquireRemoteSubmitLock($resolved->siteIdentifier);
            if (!$submitLock instanceof LockingStrategyInterface) {
                return $this->buildRemoteSubmitLockConflictResponse($resolved->siteIdentifier);
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

            $captureScreenshot = !$isFreePreview && $this->canCaptureScreenshot($proStatus);
            $remoteAccessSettings = $isFreePreview
                ? $this->emptyRemoteAccessSettings()
                : $this->buildRemoteAccessSettingsForCrawl($resolved->siteIdentifier);
            $scannerPreviewToken = $remoteAccessSettings['scannerPreviewToken'];

            $this->logRemoteSubmitDebug('submit-site:outbound', [
                'requestId' => $requestId,
                'requestedRootPid' => $requestedRootPid,
                'requestPageId' => $requestedPageUid,
                'requestedSiteIdentifier' => $requestedSiteIdentifier,
                'resolvedRulesetUid' => $remoteAccessSettings['resolvedRulesetUid'],
                'resolvedRulesetSiteIdentifier' => $remoteAccessSettings['resolvedRulesetSiteIdentifier'],
                'scannerTokenExists' => $remoteAccessSettings['scannerPreviewToken'] !== '',
                'scannerTokenLength' => $remoteAccessSettings['scannerTokenLength'],
                'scannerTokenSent' => $scannerPreviewToken !== '',
                'licenceValid' => (bool)($proStatus->valid ?? false),
                'licencePlan' => (string)($proStatus->plan ?? ''),
                'remoteCapability' => (bool)($proStatus->hasCrawler ?? false),
                'pageUid' => $isFreePreview ? $requestedPageUid : 0,
                'pageUrl' => $isFreePreview ? $freePageUrl : '',
                'startUrl' => $resolved->startUrl,
                'targetDomain' => $resolved->domain,
                'siteIdentifier' => $resolved->siteIdentifier,
                'rulesetSite' => $resolved->siteIdentifier,
                'languageUid' => $languageContext !== null ? (int)$languageContext['languageId'] : -1,
                'languageCode' => $languageCode,
                'axeLocale' => $resolved->axeLocale,
                'sourceType' => $resolved->sourceType->value,
                'captureScreenshot' => $captureScreenshot,
                'cookieDismiss' => $cookieDismiss,
                'cookieSelectorsConfigured' => $remoteAccessSettings['cookieSelectors'] !== [],
                'outboundEndpoint' => '/crawl/submit',
                'outboundPayloadKeys' => $this->buildCrawlerSubmitPayloadKeys(
                    $resolved->sourceType,
                    $resolved->sitemapUrl,
                    $scannerPreviewToken,
                    $remoteAccessSettings,
                    $languageContext !== null ? (int)$languageContext['languageId'] : null,
                    $languageCode
                ),
            ]);

            $result = $isFreePreview
                ? $this->freeRemotePreviewService->submit(
                    siteUrl: rtrim((string)$site->getBase(), '/') . '/',
                    siteIdentifier: $resolved->siteIdentifier,
                    startUrl: $resolved->startUrl,
                    version: $this->extensionContextService->getExtensionVersion(),
                    idempotencyKey: $this->freeSubmitIntentService->buildIdempotencyKey(
                        trim((string)($data['freeSubmitIntent'] ?? '')),
                        $resolved->siteIdentifier,
                        $requestedPageUid,
                        $freeLanguageUid,
                    ),
                )
                : $this->proCrawlerService->submit(
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
                scannerPreviewToken: $scannerPreviewToken,
                httpAuthUser: $remoteAccessSettings['httpAuthUser'],
                httpAuthPass: $remoteAccessSettings['httpAuthPass'],
                excludedPatterns: $remoteAccessSettings['excludedPatterns'],
                priorityUrls: $remoteAccessSettings['priorityUrls'],
                cookieSelectors: $remoteAccessSettings['cookieSelectors'],
                languageId: $languageContext !== null ? (int)$languageContext['languageId'] : null,
                languageCode: $languageCode,
            );

            $existingSubmittedScan = $this->remoteScanRepository->findScanByJobId($result->jobId);
            if (!is_array($existingSubmittedScan) || (int)($existingSubmittedScan['persisted_at'] ?? 0) <= 0) {
                $this->remoteScanRepository->markSubmitted(
                    siteIdentifier: $resolved->siteIdentifier,
                    jobId: $result->jobId,
                    sourceType: $resolved->sourceType,
                    startUrl: $resolved->startUrl,
                    sitemapUrl: $resolved->sitemapUrl,
                    status: $result->status,
                    scanScope: $isFreePreview ? 'page' : 'site',
                    pageUid: $isFreePreview ? $requestedPageUid : 0,
                    languageUid: $languageContext !== null ? (int)$languageContext['languageId'] : -1,
                    isFreePreview: $isFreePreview,
                );
            }

            $persistedScan = $this->remoteScanRepository->findScanByJobId($result->jobId);

            $this->logRemoteSubmitDebug('submit-site:persisted-local-scan', [
                'requestId' => $requestId,
                'jobId' => $result->jobId,
                'sourceType' => $resolved->sourceType->value,
                'siteIdentifier' => $resolved->siteIdentifier,
                'startUrl' => $resolved->startUrl,
                'sitemapUrl' => (string)($resolved->sitemapUrl ?? ''),
                'scanScope' => $isFreePreview ? 'page' : 'site',
                'pageUid' => $isFreePreview ? $requestedPageUid : 0,
                'languageUid' => $languageContext !== null ? (int)$languageContext['languageId'] : -1,
                'localPersistedSiteIdentifier' => is_array($persistedScan) ? (string)($persistedScan['site_identifier'] ?? '') : '',
                'localPersistedStartUrl' => is_array($persistedScan) ? (string)($persistedScan['start_url'] ?? '') : '',
                'localPersistedScanScope' => is_array($persistedScan) ? (string)($persistedScan['scan_scope'] ?? '') : '',
                'localPersistedSourceType' => is_array($persistedScan) ? (string)($persistedScan['source_type'] ?? '') : '',
            ]);

            return $this->jsonResponse([
                'success' => true,
                'requestId' => $requestId,
                'jobId' => $result->jobId,
                'status' => $result->status,
                'siteIdentifier' => $resolved->siteIdentifier,
                'startUrl' => $resolved->startUrl,
                'sourceType' => $resolved->sourceType->value,
                'sitemapUrl' => $resolved->sitemapUrl,
                'languageId' => $languageContext !== null ? (int)$languageContext['languageId'] : null,
                'languageUid' => $languageContext !== null ? (int)$languageContext['languageId'] : -1,
                'languageCode' => $languageCode,
                'scanScope' => $isFreePreview ? 'page' : 'site',
                'pageUid' => $isFreePreview ? $requestedPageUid : 0,
                'captureScreenshot' => $captureScreenshot,
                'cookieDismiss' => $cookieDismiss,
                'cookieSelectorsConfigured' => $remoteAccessSettings['cookieSelectors'] !== [],
                'cookieSelectorsCount' => count($remoteAccessSettings['cookieSelectors']),
                'freePreview' => $isFreePreview,
                'maxPages' => $resolved->maxPages,
            ]);
        } catch (FreePreviewException $exception) {
            return $this->buildFreePreviewExceptionResponse($exception);
        } catch (\InvalidArgumentException) {
            return $this->buildSimpleErrorResponse(
                message: $this->translate('proCrawler.freeIntentExpired.message', 'Reload the page and start the Free Remote Preview again.'),
                status: 400,
                code: 'invalid_free_submit_intent',
                title: $this->translate('proCrawler.freeIntentExpired.title', 'Free Remote Preview request expired'),
            );
        } catch (TokenRefreshException $exception) {
            return $this->buildTokenRefreshExceptionResponse(
                $exception,
                'Remote crawler scan failed.',
                $request
            );
        } catch (\Throwable $exception) {
            return $this->buildCrawlerExceptionResponse(
                $exception,
                'Remote crawler scan failed.',
                $request
            );
        } finally {
            if ($submitLock instanceof LockingStrategyInterface) {
                $this->releaseRemoteSubmitLock($submitLock);
            }
        }
    }

    public function submitPageAction(ServerRequestInterface $request): ResponseInterface
    {
        // A single-page scan is "Scan this page", not a site crawl.
        $accessResponse = $this->ensureBackendUserAccess($this->accessControlService, 'scanNow');
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        $pageUid = (int)($data['pageUid'] ?? 0);
        $remotePageUid = (int)($data['remotePageUid'] ?? 0);
        $requestedSiteIdentifier = trim((string)($data['siteIdentifier'] ?? ''));
        $axeLocale = trim((string)($data['axeLocale'] ?? 'en'));
        $cookieDismiss = array_key_exists('cookieDismiss', $data)
            ? (bool)$data['cookieDismiss']
            : true;
        $languageUid = $this->requestParameterService->getLanguageUidFromParameters($data);

        if ($pageUid <= 0) {
            return $this->badRequestResponse('Missing pageUid');
        }

        if (!$this->scopeAccessService->canEditPage($pageUid)) {
            return $this->forbiddenResponse();
        }

        try {
            $target = $this->resolveSinglePageScanTarget($pageUid, $remotePageUid, $requestedSiteIdentifier, $languageUid);
            if ($target instanceof ResponseInterface) {
                return $target;
            }

            $submission = $this->submitBoundSinglePageScan(
                site: $target['site'],
                pageUrl: $target['pageUrl'],
                pageUid: $pageUid,
                languageUid: $target['languageUid'],
                axeLocale: $axeLocale,
                cookieDismiss: $cookieDismiss,
                requireProOrAgency: false,
            );

            return $submission instanceof ResponseInterface
                ? $submission
                : $this->jsonResponse($submission['payload']);
        } catch (\InvalidArgumentException) {
            return $this->badRequestResponse(
                $this->translate('proCrawler.pageOutsideSite.message', 'This page URL does not belong to the configured TYPO3 site.'),
                ['code' => 'page_url_outside_site']
            );
        } catch (TokenRefreshException $exception) {
            return $this->buildTokenRefreshExceptionResponse(
                $exception,
                'Remote page scan failed.',
                $request
            );
        } catch (\Throwable $exception) {
            return $this->buildCrawlerExceptionResponse(
                $exception,
                'Remote page scan failed.',
                $request
            );
        }
    }

    /**
     * "Verify fix": scans the finding's frontend URL again, so the remediation workflow can say whether the
     * finding is gone. The finding is named by its id only; its URL, site, language and page are read from
     * the stored scan and bound to the user's page permissions exactly like "Scan this page".
     */
    public function verifyFixAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureBackendUserAccess($this->accessControlService, 'scanNow');
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $body = $request->getParsedBody();
        $findingId = (int)((is_array($body) ? $body : [])['findingId'] ?? 0);
        $finding = $findingId > 0 ? $this->fixVerificationService->resolveFinding($findingId) : null;
        if ($finding === null) {
            return $this->notFoundResponse(
                $this->translate('verifyFix.error.findingNotFound', 'This finding no longer exists. Open the latest scan of the page.'),
                ['code' => 'finding_not_found']
            );
        }

        $site = $this->siteResolutionService->resolveSiteByIdentifier((string)($finding['scan']['site_identifier'] ?? ''));
        if (!$site instanceof Site) {
            return $this->badRequestResponse('Unknown site context', ['code' => 'unknown_site_context']);
        }

        $scanPageUid = $this->remotePageScanTargetResolver->resolveScanPageUid($finding['page'], $finding['scan'], $site);
        if (!$this->scopeAccessService->canEditPage($scanPageUid)) {
            return $this->forbiddenResponse();
        }

        try {
            $scanLanguageUid = (int)($finding['scan']['language_uid'] ?? -1);
            $submission = $this->submitBoundSinglePageScan(
                site: $site,
                pageUrl: (string)($finding['page']['url'] ?? ''),
                pageUid: $scanPageUid,
                languageUid: $scanLanguageUid >= 0 ? $scanLanguageUid : 0,
                axeLocale: 'en',
                cookieDismiss: true,
                requireProOrAgency: true,
            );
            if ($submission instanceof ResponseInterface) {
                return $submission;
            }

            $verificationUid = $this->fixVerificationService->recordRequest(
                finding: $finding,
                pageUid: $scanPageUid,
                verificationJobId: $submission['jobId'],
                requestedBy: $this->getBackendUserUid(),
            );

            return $this->jsonResponse($submission['payload'] + [
                'verificationUid' => $verificationUid,
                'outcome' => 'pending',
            ]);
        } catch (\InvalidArgumentException) {
            return $this->badRequestResponse(
                $this->translate('proCrawler.pageOutsideSite.message', 'This page URL does not belong to the configured TYPO3 site.'),
                ['code' => 'page_url_outside_site']
            );
        } catch (TokenRefreshException $exception) {
            return $this->buildTokenRefreshExceptionResponse($exception, 'Fix verification scan failed.', $request);
        } catch (\Throwable $exception) {
            return $this->buildCrawlerExceptionResponse($exception, 'Fix verification scan failed.', $request);
        }
    }

    /**
     * The outcome of a verification once its scan has been saved: Resolved, Still present or Not verified.
     */
    public function verifyFixResultAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureAnyScanPermission();
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $verificationUid = (int)($request->getQueryParams()['verificationUid'] ?? 0);
        $verification = $verificationUid > 0 ? $this->fixVerificationService->findVerification($verificationUid) : null;
        if ($verification === null) {
            return $this->notFoundResponse('Unknown verification', ['code' => 'verification_not_found']);
        }

        $baselineScan = $this->remoteScanRepository->findScanByUid((int)($verification['baseline_scan'] ?? 0));
        if (!is_array($baselineScan) || !$this->scopeAccessService->canReadRemoteScan($baselineScan)) {
            return $this->forbiddenResponse();
        }

        return $this->jsonResponse(['success' => true] + $this->fixVerificationService->presentOutcome(
            $this->fixVerificationService->evaluate($verification),
            fn (int $remotePageUid): string => $this->buildRemotePageDetailUrl($remotePageUid, $verification),
        ));
    }

    /**
     * @param array<string, mixed> $verification
     */
    private function buildRemotePageDetailUrl(int $remotePageUid, array $verification): string
    {
        if ($remotePageUid <= 0) {
            return '';
        }

        try {
            return (string)GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Routing\UriBuilder::class)->buildUriFromRoute(
                'web_a11y.remotePageDetail',
                [
                    'remotePageUid' => $remotePageUid,
                    'site' => (string)($verification['site_identifier'] ?? ''),
                    'id' => (int)($verification['page_uid'] ?? 0),
                ]
            );
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Submits a single-page scan for a target already bound to the user's page: entitlement, the per-site
     * submit lock and the one-active-scan-per-site rule, the site's access settings, and the local scan row.
     *
     * @return array{payload:array<string, mixed>,jobId:string}|ResponseInterface
     */
    private function submitBoundSinglePageScan(
        Site $site,
        string $pageUrl,
        int $pageUid,
        int $languageUid,
        string $axeLocale,
        bool $cookieDismiss,
        bool $requireProOrAgency,
    ): array|ResponseInterface {
        $siteIdentifier = $site->getIdentifier();
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
        $crawlerAccessResponse = $requireProOrAgency
            ? $this->ensureProOrAgencyCrawlerAccess($proStatus)
            : $this->ensureCrawlerAccess($proStatus);
        if ($crawlerAccessResponse !== null) {
            return $crawlerAccessResponse;
        }

        $submitLock = $this->acquireRemoteSubmitLock($resolved->siteIdentifier);
        if (!$submitLock instanceof LockingStrategyInterface) {
            return $this->buildRemoteSubmitLockConflictResponse($resolved->siteIdentifier);
        }

        try {
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
            $scannerPreviewToken = $remoteAccessSettings['scannerPreviewToken'];

            $this->logRemoteSubmitDebug('submit-page:outbound', [
                'resolvedRulesetUid' => $remoteAccessSettings['resolvedRulesetUid'],
                'resolvedRulesetSiteIdentifier' => $remoteAccessSettings['resolvedRulesetSiteIdentifier'],
                'scannerTokenExists' => $remoteAccessSettings['scannerPreviewToken'] !== '',
                'scannerTokenLength' => $remoteAccessSettings['scannerTokenLength'],
                'scannerTokenSent' => $scannerPreviewToken !== '',
                'licenceValid' => (bool)($proStatus->valid ?? false),
                'licencePlan' => (string)($proStatus->plan ?? ''),
                'remoteCapability' => (bool)($proStatus->hasCrawler ?? false),
                'pageUid' => $pageUid,
                'pageUrl' => $pageUrl,
                'startUrl' => $resolved->startUrl,
                'targetDomain' => $resolved->domain,
                'siteIdentifier' => $siteIdentifier,
                'rulesetSite' => $resolved->siteIdentifier,
                'languageUid' => $languageContext !== null ? (int)$languageContext['languageId'] : -1,
                'languageCode' => $languageCode,
                'axeLocale' => $resolved->axeLocale,
                'sourceType' => RemoteScanSourceType::SinglePage->value,
                'captureScreenshot' => $captureScreenshot,
                'cookieDismiss' => $cookieDismiss,
                'cookieSelectorsConfigured' => $remoteAccessSettings['cookieSelectors'] !== [],
                'outboundEndpoint' => '/crawl/submit',
                'outboundPayloadKeys' => $this->buildCrawlerSubmitPayloadKeys(
                    RemoteScanSourceType::SinglePage,
                    null,
                    $scannerPreviewToken,
                    $remoteAccessSettings,
                    $languageContext !== null ? (int)$languageContext['languageId'] : null,
                    $languageCode
                ),
            ]);

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
                scannerPreviewToken: $scannerPreviewToken,
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

            return [
                'jobId' => $result->jobId,
                'payload' => [
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
                ],
            ];
        } finally {
            $this->releaseRemoteSubmitLock($submitLock);
        }
    }

    public function cancelSiteAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureAnyScanPermission();
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

            $validatedScan = $this->resolveValidatedLocalScanJob($jobId, $siteIdentifier, $site);
            if ($validatedScan instanceof ResponseInterface) {
                return $validatedScan;
            }

            // Cancelling a site crawl is a site-wide action; a page scan only needs "Scan this page".
            $scopeAccessResponse = $this->ensureBackendUserAccess(
                $this->accessControlService,
                (string)($validatedScan['scan_scope'] ?? '') === 'page' ? 'scanNow' : 'scanAll'
            );
            if ($scopeAccessResponse !== null) {
                return $scopeAccessResponse;
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
                'Remote crawler cancel request failed.',
                $request
            );
        } catch (\Throwable $exception) {
            return $this->buildCrawlerExceptionResponse(
                $exception,
                'Remote crawler cancel request failed.',
                $request
            );
        }
    }

    public function statusAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureAnyScanPermission();
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

            $validatedScan = $this->resolveValidatedLocalScanJob($jobId, $siteIdentifier, $site);
            if ($validatedScan instanceof ResponseInterface) {
                return $validatedScan;
            }

            $domain = $this->resolveDomainFromSiteBase((string)$site->getBase());

            $result = $this->scanUsesFreeChannel($validatedScan)
                ? $this->freeRemotePreviewService->getStatus(
                    siteUrl: rtrim((string)$site->getBase(), '/') . '/',
                    siteIdentifier: $siteIdentifier,
                    version: $this->extensionContextService->getExtensionVersion(),
                    jobId: $jobId,
                )
                : $this->proCrawlerService->getStatus(
                    domain: $domain,
                    version: $this->extensionContextService->getExtensionVersion(),
                    jobId: $jobId,
                );

            if (!$this->remoteJobIdMatches($result->jobId, $jobId)) {
                return $this->buildRemoteJobResponseMismatchResponse();
            }

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
        } catch (FreePreviewException $exception) {
            return $this->buildFreePreviewExceptionResponse($exception);
        } catch (TokenRefreshException $exception) {
            return $this->buildTokenRefreshExceptionResponse(
                $exception,
                'Remote crawler status request failed.',
                $request
            );
        } catch (\Throwable $exception) {
            return $this->buildCrawlerExceptionResponse(
                $exception,
                'Remote crawler status request failed.',
                $request
            );
        }
    }

    public function summaryAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureAnyScanPermission();
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

            $validatedScan = $this->resolveValidatedLocalScanJob($jobId, $siteIdentifier, $site);
            if ($validatedScan instanceof ResponseInterface) {
                return $validatedScan;
            }

            if ($this->remoteScanRepository->isPersisted($jobId)) {
                return $this->jsonResponse([
                    'success' => true,
                    'saved' => true,
                    'jobId' => $jobId,
                    'alreadyPersisted' => true,
                ]);
            }

            $domain = $this->resolveDomainFromSiteBase((string)$site->getBase());

            $isFreePreview = $this->scanUsesFreeChannel($validatedScan);
            $summaryResult = $isFreePreview
                ? $this->freeRemotePreviewService->getSummary(
                    siteUrl: rtrim((string)$site->getBase(), '/') . '/',
                    siteIdentifier: $siteIdentifier,
                    version: $this->extensionContextService->getExtensionVersion(),
                    jobId: $jobId,
                )
                : $this->proCrawlerService->getSummary(
                    domain: $domain,
                    version: $this->extensionContextService->getExtensionVersion(),
                    jobId: $jobId,
                );

            if (!$this->remoteJobIdMatches($summaryResult->jobId, $jobId)) {
                return $this->buildRemoteJobResponseMismatchResponse();
            }

            if (!$this->remoteSiteIdentifierMatches($summaryResult->siteId, $siteIdentifier)) {
                return $this->buildRemoteJobResponseMismatchResponse();
            }

            $resultsResult = $isFreePreview
                ? $this->freeRemotePreviewService->getResults(
                    siteUrl: rtrim((string)$site->getBase(), '/') . '/',
                    siteIdentifier: $siteIdentifier,
                    version: $this->extensionContextService->getExtensionVersion(),
                    jobId: $jobId,
                )
                : $this->proCrawlerService->getResults(
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
                'contrast' => $summaryResult->contrast,
                'contrastDetails' => $summaryResult->contrastDetails,
                'score' => $summaryResult->score,
                'keyboardSummary' => $summaryResult->keyboardSummary,
                'structureSummary' => $summaryResult->structureSummary,
                'remediationSummary' => $summaryResult->remediationSummary,
                'wcagSummary' => $summaryResult->wcagSummary,
                'priorityFixes' => $summaryResult->priorityFixes,
                'reportSummary' => $summaryResult->reportSummary,
                'manualReviewChecklist' => $summaryResult->manualReviewChecklist,
                'reportingGroups' => $summaryResult->reportingGroups,
                'startedAt' => $summaryResult->startedAt,
                'finishedAt' => $summaryResult->finishedAt,
            ]);
        } catch (FreePreviewException $exception) {
            return $this->buildFreePreviewExceptionResponse($exception);
        } catch (TokenRefreshException $exception) {
            return $this->buildTokenRefreshExceptionResponse(
                $exception,
                'Remote crawler summary request failed.',
                $request
            );
        } catch (\Throwable $exception) {
            return $this->buildCrawlerExceptionResponse(
                $exception,
                'Remote crawler summary request failed.',
                $request
            );
        }
    }

    /**
     * Derives what a single-page scan may target from the page the user may edit.
     *
     * The site is the page's site; a site identifier from the browser may only agree with it. The URL is
     * resolved here — from the page and language, or from a stored frontend page whose URL is bound to
     * that page (a URL without a mapped page belongs to the site root). The browser never names the URL,
     * so it cannot point the scan, and the site's HTTP credentials and scanner token, at another site.
     *
     * @return array{site:Site,pageUrl:string,languageUid:int}|ResponseInterface
     */
    private function resolveSinglePageScanTarget(
        int $pageUid,
        int $remotePageUid,
        string $requestedSiteIdentifier,
        int $languageUid,
    ): array|ResponseInterface {
        $site = $this->siteResolutionService->resolveSiteByPageId($pageUid);
        if (!$site instanceof Site) {
            return $this->badRequestResponse('Unknown site context for pageUid', ['code' => 'unknown_site_context']);
        }

        if ($requestedSiteIdentifier !== '' && $requestedSiteIdentifier !== $site->getIdentifier()) {
            return $this->badRequestResponse(
                $this->translate('proCrawler.sitePageMismatch.message', 'The selected page does not belong to the requested site.'),
                ['code' => 'site_page_mismatch']
            );
        }

        if ($remotePageUid > 0) {
            $remotePage = $this->remoteScanRepository->findPageByUid($remotePageUid);
            $remoteScan = is_array($remotePage)
                ? $this->remoteScanRepository->findScanByUid((int)($remotePage['remote_scan'] ?? 0))
                : null;
            if (!is_array($remotePage) || !is_array($remoteScan)
                || (string)($remoteScan['site_identifier'] ?? '') !== $site->getIdentifier()) {
                return $this->badRequestResponse(
                    $this->translate('proCrawler.sitePageMismatch.message', 'The selected page does not belong to the requested site.'),
                    ['code' => 'site_page_mismatch']
                );
            }

            if ($this->remotePageScanTargetResolver->resolveScanPageUid($remotePage, $remoteScan, $site) !== $pageUid) {
                return $this->forbiddenResponse();
            }

            $scanLanguageUid = (int)($remoteScan['language_uid'] ?? -1);

            return [
                'site' => $site,
                'pageUrl' => trim((string)($remotePage['url'] ?? '')),
                'languageUid' => $scanLanguageUid >= 0 ? $scanLanguageUid : max(0, $languageUid),
            ];
        }

        $languageUid = max(0, $languageUid);
        $pageUrl = trim($this->frontendPageUrlService->resolveForPage($site, $pageUid, $languageUid));
        if ($pageUrl === '') {
            return $this->badRequestResponse(
                $this->translate('overview.remote.notTranslated', 'This page is not translated in the selected site language.'),
                ['code' => 'unresolved_page_url']
            );
        }

        return ['site' => $site, 'pageUrl' => $pageUrl, 'languageUid' => $languageUid];
    }

    /**
     * @return array<string, mixed>|ResponseInterface
     */
    /**
     * A scan is followed through the channel that submitted it, recorded on the scan: a Free scan keeps its
     * Free token and quota identity after an upgrade, and a paid scan never falls back to the Free channel
     * when the paid entitlement changes while it runs — it then fails with the licence state instead.
     *
     * @param array<string, mixed> $scan
     */
    private function scanUsesFreeChannel(array $scan): bool
    {
        return (int)($scan['is_free_preview'] ?? 0) === 1;
    }

    private function resolveValidatedLocalScanJob(string $jobId, string $siteIdentifier, Site $site): array|ResponseInterface
    {
        $scan = $this->remoteScanRepository->findScanByJobId($jobId);
        if (!is_array($scan)) {
            return $this->buildSimpleErrorResponse(
                message: $this->translate('proCrawler.unknownJob.message', 'Unknown remote scan job.'),
                status: 404,
                code: 'unknown_remote_scan_job',
                title: $this->translate('proCrawler.unknownJob.title', 'Unknown remote scan job')
            );
        }

        if ((string)($scan['site_identifier'] ?? '') !== $siteIdentifier) {
            return $this->buildSimpleErrorResponse(
                message: $this->translate('proCrawler.jobSiteMismatch.message', 'The remote scan job does not belong to the requested site.'),
                status: 403,
                code: 'job_site_mismatch',
                title: $this->translate('proCrawler.jobSiteMismatch.title', 'Remote scan job site mismatch')
            );
        }

        if (!$this->scopeAccessService->canEditRemoteScan($scan)) {
            return $this->buildSimpleErrorResponse(
                message: $this->translate('proCrawler.jobAccessDenied.message', 'Access denied for this remote scan job.'),
                status: 403,
                code: 'job_access_denied',
                title: $this->translate('proCrawler.jobAccessDenied.title', 'Access denied')
            );
        }

        return $scan;
    }

    /**
     * Following a scan (status, results, cancel) is open to anyone who may start one of either kind;
     * the job itself is then bound to the user's page or site by resolveValidatedLocalScanJob().
     */
    private function ensureAnyScanPermission(): ?ResponseInterface
    {
        $accessResponse = $this->ensureBackendUserAccess($this->accessControlService);
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $backendUser = $this->getBackendUser();

        return $this->accessControlService->canShowScanNow($backendUser)
            || $this->accessControlService->canShowScanAll($backendUser)
            ? null
            : $this->forbiddenResponse();
    }

    private function remoteJobIdMatches(?string $responseJobId, string $requestJobId): bool
    {
        $responseJobId = trim((string)$responseJobId);

        return $responseJobId === '' || hash_equals($requestJobId, $responseJobId);
    }

    private function remoteSiteIdentifierMatches(?string $responseSiteIdentifier, string $siteIdentifier): bool
    {
        $responseSiteIdentifier = trim((string)$responseSiteIdentifier);

        return $responseSiteIdentifier === '' || hash_equals($siteIdentifier, $responseSiteIdentifier);
    }

    private function buildRemoteJobResponseMismatchResponse(): ResponseInterface
    {
        return $this->buildSimpleErrorResponse(
            message: $this->translate('proCrawler.responseMismatch.message', 'Remote crawler response did not match the requested scan job.'),
            status: 409,
            code: 'remote_job_response_mismatch',
            title: $this->translate('proCrawler.responseMismatch.title', 'Remote scan response mismatch')
        );
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
            message: $this->translate('proCrawler.licenceRequired.message', 'The remote crawler requires a valid Trial, PRO or Agency licence.'),
            status: 403,
            code: 'pro_crawler_required',
            title: $this->translate('proCrawler.licenceRequired.title', 'Remote-scanning licence required')
        );
    }

    /**
     * Fix verification is a PRO/Agency remediation feature; a trial keeps its scan budget for scanning.
     */
    private function ensureProOrAgencyCrawlerAccess(object $proStatus): ?ResponseInterface
    {
        if ((bool)($proStatus->valid ?? false) && (bool)($proStatus->hasCrawler ?? false) && !(bool)($proStatus->isTrial ?? false)) {
            return null;
        }

        return $this->buildSimpleErrorResponse(
            message: $this->translate('verifyFix.error.planRequired', 'Verify fix is part of AQG PRO and Agency.'),
            status: 403,
            code: 'pro_plan_required',
            title: $this->translate('remoteScanError.notAllowed.title', 'Remote scan not allowed')
        );
    }

    private function hasPaidCrawlerAccess(object $proStatus): bool
    {
        return (bool)($proStatus->valid ?? false) && (bool)($proStatus->hasCrawler ?? false);
    }

    /**
     * @return array{scannerPreviewToken:string,scannerTokenLength:int,resolvedRulesetUid:int,resolvedRulesetSiteIdentifier:string,httpAuthUser:string,httpAuthPass:string,excludedPatterns:list<string>,priorityUrls:list<string>,cookieSelectors:list<string>}
     */
    private function emptyRemoteAccessSettings(): array
    {
        return [
            'scannerPreviewToken' => '',
            'scannerTokenLength' => 0,
            'resolvedRulesetUid' => 0,
            'resolvedRulesetSiteIdentifier' => '',
            'httpAuthUser' => '',
            'httpAuthPass' => '',
            'excludedPatterns' => [],
            'priorityUrls' => [],
            'cookieSelectors' => [],
        ];
    }


    /**
     * @return array{scannerPreviewToken:string,scannerTokenLength:int,resolvedRulesetUid:int,resolvedRulesetSiteIdentifier:string,httpAuthUser:string,httpAuthPass:string,excludedPatterns:list<string>,priorityUrls:list<string>,cookieSelectors:list<string>}
     */
    private function buildRemoteAccessSettingsForCrawl(string $siteIdentifier = ''): array
    {
        return $this->remoteScanAccessSettingsService->buildForSite($siteIdentifier);
    }

    /**
     * @param array{scannerPreviewToken:string,httpAuthUser:string,httpAuthPass:string,excludedPatterns:list<string>,priorityUrls:list<string>,cookieSelectors:list<string>} $remoteAccessSettings
     * @return list<string>
     */
    private function buildCrawlerSubmitPayloadKeys(
        RemoteScanSourceType $sourceType,
        ?string $sitemapUrl,
        string $scannerPreviewToken,
        array $remoteAccessSettings,
        ?int $languageId,
        string $languageCode,
    ): array {
        $keys = [
            'siteId',
            'sourceType',
            'startUrl',
            'sitemapUrl',
            'maxPages',
            'followLinks',
            'axeLocale',
            'captureScreenshot',
            'cookieDismiss',
        ];

        if ($scannerPreviewToken !== '') {
            $keys[] = 'scannerToken';
        }
        if ($remoteAccessSettings['httpAuthUser'] !== '' && $remoteAccessSettings['httpAuthPass'] !== '') {
            $keys[] = 'httpAuth';
        }
        if ($remoteAccessSettings['excludedPatterns'] !== []) {
            $keys[] = 'excludedPatterns';
        }
        if ($remoteAccessSettings['priorityUrls'] !== []) {
            $keys[] = 'priorityUrls';
        }
        if ($remoteAccessSettings['cookieSelectors'] !== []) {
            $keys[] = 'cookieSelectors';
        }
        if ($languageId !== null) {
            $keys[] = 'languageId';
        }
        if (trim($languageCode) !== '') {
            $keys[] = 'languageCode';
        }

        return $keys;
    }

    private function buildRemoteSubmitRequestId(ServerRequestInterface $request): string
    {
        return substr(sha1((string)microtime(true) . '-' . spl_object_id($request)), 0, 12);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logRemoteSubmitDebug(string $event, array $context): void
    {
        try {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(__CLASS__)
                ->debug('AQG remote crawler submit ' . $event, $this->sanitizeLogContext($context));
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitizeLogContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if ($this->isSensitiveLogContextKey((string)$key)) {
                if (is_int($value) || is_bool($value) || $value === null) {
                    continue;
                }
                $context[$key] = $value === '' ? '' : '***';
                continue;
            }
            if (is_array($value)) {
                $context[$key] = $this->sanitizeLogContext($value);
            }
        }

        return $context;
    }

    private function isSensitiveLogContextKey(string $key): bool
    {
        $normalizedKey = strtolower(str_replace(['-', '_'], '', $key));

        foreach ([
            'token',
            'password',
            'licencekey',
            'authorization',
            'auth',
            'apikey',
            'secret',
            'cookie',
            'setcookie',
            'bearer',
            'clientsecret',
            'installationid',
        ] as $sensitiveKey) {
            if (str_contains($normalizedKey, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }

    private function acquireRemoteSubmitLock(string $siteIdentifier): ?LockingStrategyInterface
    {
        try {
            $lockFactory = GeneralUtility::makeInstance(LockFactory::class);
            $lock = $lockFactory->createLocker(
                'aqg_remote_scan:' . sha1($siteIdentifier),
                LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE
            );

            return $lock->acquire() ? $lock : null;
        } catch (\Throwable $exception) {
            $this->logRemoteSubmitDebug('submit:lock-unavailable', [
                'siteIdentifier' => $siteIdentifier,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }


    private function buildRemoteSubmitLockConflictResponse(string $siteIdentifier): ResponseInterface
    {
        return $this->jsonResponse([
            'success' => false,
            'error' => 'A remote scan submit is already in progress for this site.',
            'message' => $this->translate('proCrawler.submitInProgress', 'A remote scan submit is already in progress for this site. Please wait a moment and try again.'),
            'code' => 'remote_scan_submit_in_progress',
            'siteIdentifier' => $siteIdentifier,
        ], 409);
    }

    private function releaseRemoteSubmitLock(LockingStrategyInterface $lock): void
    {
        try {
            $lock->release();
        } catch (\Throwable) {
        }
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
        ?ServerRequestInterface $request = null,
    ): ResponseInterface {
        return $this->buildCrawlerExceptionResponse($exception, $fallbackMessage, $request);
    }

    private function buildFreePreviewExceptionResponse(FreePreviewException $exception): ResponseInterface
    {
        $payload = [
            'success' => false,
            'code' => $exception->errorCode,
            'state' => $exception->state,
            'title' => $exception->isRateLimited()
                ? $this->translate('freePreview.error.rateLimited.title', 'Too many free scan requests')
                : match ($exception->state) {
                    'FREE_LIMIT_REACHED' => $this->translate('freePreview.error.limitReached.title', 'Free scan limit reached'),
                    'FEATURE_NOT_AVAILABLE' => $this->translate('freePreview.error.featureUnavailable.title', 'Not included in the Free Remote Preview'),
                    'PROOF_ERROR' => $this->translate('freePreview.error.proof.title', 'Free Remote Preview proof could not be verified'),
                    'IDEMPOTENCY_CONFLICT' => $this->translate('freePreview.error.idempotency.title', 'Free Remote Preview submit conflict'),
                    'TOKEN_ERROR' => $this->translate('freePreview.error.token.title', 'Free Remote Preview authentication failed'),
                    'MISSING_INSTALLATION_ID' => $this->translate('freePreview.error.missingInstallation.title', 'Free Remote Preview installation identity missing'),
                    'INSTALLATION_IDENTITY_MISMATCH' => $this->translate('freePreview.error.installationMismatch.title', 'Free Remote Preview installation identity mismatch'),
                    'SITE_IDENTITY_MISMATCH' => $this->translate('freePreview.error.siteMismatch.title', 'Free Remote Preview site identity mismatch'),
                    'INVALID_SITE' => $this->translate('freePreview.error.invalidSite.title', 'Free Remote Preview site configuration invalid'),
                    'ENDPOINT_NOT_FOUND' => $this->translate('freePreview.error.endpoint.title', 'Free Remote Preview API route unavailable'),
                    'TOKEN_CONTRACT_ERROR', 'API_CONTRACT_ERROR' => $this->translate('freePreview.error.contract.title', 'Free Remote Preview API contract rejected'),
                    default => $this->translate('freePreview.error.unavailable.title', 'Free Remote Preview unavailable'),
                },
            'message' => $exception->getMessage(),
            'status' => $exception->httpStatus,
        ];
        if ($exception->freeDaily !== []) {
            $payload['freeDaily'] = $exception->freeDaily;
        }

        return $this->jsonResponse($payload, $exception->httpStatus);
    }

    private function buildSimpleErrorResponse(
        string $message,
        int $status,
        string $code,
        string $title,
        array $details = [],
    ): ResponseInterface {
        $payload = [
            'success' => false,
            'code' => $code,
            'title' => $title,
            'message' => $message,
            'status' => $status,
            'error' => $message,
        ];

        if ($details !== []) {
            $payload['details'] = $details;
        }

        return $this->jsonResponse($payload, $status);
    }

    private function buildCrawlerExceptionResponse(
        \Throwable $exception,
        string $fallbackMessage,
        ?ServerRequestInterface $request = null,
    ): ResponseInterface {
        $payload = $this->buildCrawlerExceptionPayload($exception, $fallbackMessage, $request);
        $response = $this->jsonResponse($payload, (int)$payload['status']);

        return isset($payload['retryAfter'])
            ? $response->withHeader('Retry-After', (string)$payload['retryAfter'])
            : $response;
    }

    /**
     * The browser gets a bounded code and a translated message; the exception text — crawler URL, payload
     * keys, transport or database errors — goes to the log only. Administrators can ask for the crawler's
     * sanitized details with debug=1.
     *
     * @return array<string, mixed>
     */
    private function buildCrawlerExceptionPayload(
        \Throwable $exception,
        string $fallbackMessage,
        ?ServerRequestInterface $request = null,
    ): array {
        $presented = $this->remoteScanErrorPresenter->present(
            $exception,
            $exception instanceof TokenRefreshException ? 'token_refresh_failed' : 'remote_crawler_request_failed'
        );

        $payload = [
            'success' => false,
            'code' => $presented['code'],
            'title' => $presented['title'],
            'message' => $presented['message'],
            'status' => $presented['status'],
            'error' => $presented['message'],
        ];
        if ($presented['retryAfter'] !== null && $presented['retryAfter'] > 0) {
            $payload['retryAfter'] = $presented['retryAfter'];
        }

        $apiException = $this->findApiRequestFailedException($exception);
        $details = $apiException instanceof ApiRequestFailedException ? $apiException->details : [];
        $debug = $this->extractCrawlerDebugFromRawMessage($apiException?->getMessage() ?? '');

        $this->logRemoteCrawlerError($exception, [
            'context' => $fallbackMessage,
            'code' => $presented['code'],
            'status' => $presented['status'],
            'details' => $details,
            'debug' => $debug,
        ]);

        if ($this->shouldExposeCrawlerDebug($request)) {
            if ($details !== []) {
                $payload['details'] = $this->sanitizeLogContext($details);
            }
            if ($debug !== []) {
                $payload['debug'] = $debug;
            }
        }

        return $payload;
    }

    private function findApiRequestFailedException(\Throwable $exception): ?ApiRequestFailedException
    {
        $current = $exception;
        do {
            if ($current instanceof ApiRequestFailedException) {
                return $current;
            }
            $current = $current->getPrevious();
        } while ($current instanceof \Throwable);

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logRemoteCrawlerError(
        \Throwable $exception,
        array $context,
    ): void {
        try {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(__CLASS__)
                ->warning('AQG remote crawler request failed', $this->sanitizeLogContext(array_merge(
                    $context,
                    [
                        'exceptionClass' => $exception::class,
                        'exceptionMessage' => $exception->getMessage(),
                    ]
                )));
        } catch (\Throwable) {
        }
    }

    private function shouldExposeCrawlerDebug(?ServerRequestInterface $request): bool
    {
        if ($request === null) {
            return false;
        }

        $params = array_merge($request->getQueryParams(), is_array($request->getParsedBody()) ? $request->getParsedBody() : []);
        $debugRequested = (string)($params['debug'] ?? $params['aqgDebug'] ?? '') === '1';
        if (!$debugRequested) {
            return false;
        }

        return $this->accessControlService->canManageAdminOnlySettings($this->getBackendUser());
    }

    /**
     * @return array<string, mixed>
     */
    private function extractCrawlerDebugFromRawMessage(string $rawMessage): array
    {
        $debug = [];

        if (preg_match('/\|\s*url=([^|]+)/', $rawMessage, $matches) === 1) {
            $debug['url'] = trim($matches[1]);
        }
        if (preg_match('/\|\s*method=([^|]+)/', $rawMessage, $matches) === 1) {
            $debug['method'] = trim($matches[1]);
        }
        if (preg_match('/\|\s*payload=(\{.*?\})(?:\s*\|\s*auth=|\s*\|\s*body=|$)/s', $rawMessage, $matches) === 1) {
            $decoded = json_decode(trim($matches[1]), true);
            if (is_array($decoded)) {
                $debug['payloadKeys'] = array_keys($decoded);
                $debug['payload'] = $this->sanitizeLogContext($decoded);
            }
        }
        if (preg_match('/\|\s*auth=(\{.*?\})(?:\s*\|\s*body=|$)/s', $rawMessage, $matches) === 1) {
            $decoded = json_decode(trim($matches[1]), true);
            if (is_array($decoded)) {
                $debug['auth'] = $this->sanitizeLogContext($decoded);
            }
        }
        if (preg_match('/\|\s*body=(\{.*\})\s*$/s', $rawMessage, $matches) === 1) {
            $decoded = json_decode(trim($matches[1]), true);
            if (is_array($decoded)) {
                $debug['responseBody'] = $this->sanitizeLogContext($decoded);
            }
        }

        return $debug;
    }
}
