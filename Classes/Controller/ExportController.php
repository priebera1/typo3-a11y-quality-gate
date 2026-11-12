<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Service\RemoteResultAuthorizationService;
use Priebera\A11yQualityGate\Service\RemoteResultsTooLargeException;
use Priebera\A11yQualityGate\Export\AcceptanceEvidenceBuilder;
use Priebera\A11yQualityGate\Export\ExportFilename;
use Priebera\A11yQualityGate\Export\IssueExporter;
use Priebera\A11yQualityGate\Export\PdfReportBuilder;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Export\RemoteExportBuilder;
use Priebera\A11yQualityGate\Export\PdfGenerator;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\FrontendPageUrlService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\RemoteScanPairingService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Utility\BackendLabelUtility;
use Priebera\A11yQualityGate\Utility\FilterValueUtility;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Site\Entity\Site;

#[AsController]
final class ExportController
{
    public function __construct(
        private readonly IssueExporter $issueExporter,
        private readonly PdfReportBuilder $pdfReportBuilder,
        private readonly RemoteExportBuilder $remoteExportBuilder,
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly BackendRecordAccessService $backendRecordAccessService,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly RequestParameterService $requestParameterService,
        private readonly ProStatusResolverService $proStatusResolverService,
        private readonly RemoteScanPairingService $remoteScanPairingService,
        private readonly ScopeAccessService $scopeAccessService,
        private readonly AcceptanceEvidenceBuilder $acceptanceEvidenceBuilder,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?FrontendPageUrlService $frontendPageUrlService = null,
        private readonly ?RemoteResultAuthorizationService $remoteResultAuthorizationService = null,
    ) {
    }

    public function csvAction(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->parseExportContext($request);

        if ($context['scope'] === 'remote') {
            $ownerSiteIdentifier = $this->resolveRemoteExportOwnerSiteIdentifier(
                $context['siteIdentifier'],
                $context['remotePageUid'],
                $context['remoteScanUid']
            );

            $target = $ownerSiteIdentifier !== '' && $context['remotePageUid'] <= 0
                ? $this->authorizedOverviewTarget($ownerSiteIdentifier, $context['remoteScanUid'], $context['pageUid'], (int)$context['languageUid'])
                : null;
            if (
                $ownerSiteIdentifier === ''
                || ($context['remotePageUid'] <= 0 && $target === null)
                || !$this->canAccessRemoteExport($context['siteIdentifier'], $context['remotePageUid'], $context['remoteScanUid'], (int)($context['pageUid'] ?? 0), (int)$context['languageUid'])
                || !$this->hasPaidRemoteAccess($ownerSiteIdentifier)
            ) {
                return $this->downloadResponse(
                    content: BackendLabelUtility::translate('module.accessDenied', 'Access denied.'),
                    filename: 'aqg-content-export-access-denied.txt',
                    contentType: 'text/plain; charset=UTF-8',
                    statusCode: 403,
                );
            }

            return $this->buildRemoteCsvResponse(
                siteIdentifier: $ownerSiteIdentifier,
                remotePageUid: $context['remotePageUid'],
                target: $target['scan'] ?? null,
            );
        }

        if (!$this->canAccessLocalExport($request, $context['siteIdentifier'], $context['pageUid'])) {
            return $this->downloadResponse(
                content: BackendLabelUtility::translate('module.accessDenied', 'Access denied.'),
                filename: 'aqg-content-export-access-denied.txt',
                contentType: 'text/plain; charset=UTF-8',
                statusCode: 403,
            );
        }

        $csv = $this->issueExporter->toCsv(
            siteIdentifier: $context['siteIdentifier'],
            pageUid: $context['pageUid'],
            status: $context['status'],
            severity: $context['severity'],
            languageUid: $context['localLanguageUid'],
            // A site export lists only pages the user may show, like the Overview it is started from.
            readablePagesClause: $this->scopeAccessService->readablePagesClause(),
        );

        return $this->downloadResponse(
            content: "\xEF\xBB\xBF" . $csv,
            filename: $this->buildFilename('content', $context['siteIdentifier'], $context['pageUid'], 'csv'),
            contentType: 'text/csv; charset=UTF-8',
        );
    }

    public function pdfAction(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->parseExportContext($request);

        if ($context['scope'] === 'remote') {
            $ownerSiteIdentifier = $this->resolveRemoteExportOwnerSiteIdentifier(
                $context['siteIdentifier'],
                $context['remotePageUid'],
                $context['remoteScanUid']
            );

            $target = $ownerSiteIdentifier !== '' && $context['remotePageUid'] <= 0
                ? $this->authorizedOverviewTarget($ownerSiteIdentifier, $context['remoteScanUid'], $context['pageUid'], (int)$context['languageUid'])
                : null;
            if (
                $ownerSiteIdentifier === ''
                || ($context['remotePageUid'] <= 0 && $target === null)
                || !$this->canAccessRemoteExport($context['siteIdentifier'], $context['remotePageUid'], $context['remoteScanUid'], (int)($context['pageUid'] ?? 0), (int)$context['languageUid'])
            ) {
                return $this->downloadResponse(
                    content: BackendLabelUtility::translate('module.accessDenied', 'Access denied.'),
                    filename: 'aqg-pdf-export-access-denied.txt',
                    contentType: 'text/plain; charset=UTF-8',
                    statusCode: 403,
                );
            }

            // Capability is resolved from the site that owns the scan, never from the request.
            $site = $this->siteResolutionService->resolveSiteByIdentifier($ownerSiteIdentifier);

            if (!$this->canExportPdf($site, $ownerSiteIdentifier)) {
                return $this->downloadResponse(
                    content: BackendLabelUtility::translate('export.pdf.paidOnly', 'PDF export needs a PRO or Agency licence.'),
                    filename: 'aqg-pdf-export-unavailable.txt',
                    contentType: 'text/plain; charset=UTF-8',
                    statusCode: 403,
                );
            }

            if (!PdfGenerator::isAvailable()) {
                return $this->pdfLibraryMissingResponse();
            }

            return $this->buildRemotePdfResponse(
                request: $request,
                siteIdentifier: $ownerSiteIdentifier,
                remotePageUid: $context['remotePageUid'],
                target: $target['scan'] ?? null,
            );
        }

        if (!$this->canAccessLocalExport($request, $context['siteIdentifier'], $context['pageUid'])) {
            return $this->downloadResponse(
                content: BackendLabelUtility::translate('module.accessDenied', 'Access denied.'),
                filename: 'aqg-pdf-export-access-denied.txt',
                contentType: 'text/plain; charset=UTF-8',
                statusCode: 403,
            );
        }

        $site = $this->resolveSiteForExport(
            request: $request,
            siteIdentifier: $context['siteIdentifier'],
            pageUid: $context['pageUid'],
        );

        if (!$this->canExportPdf($site, $context['siteIdentifier'])) {
            return $this->downloadResponse(
                content: BackendLabelUtility::translate('export.pdf.paidOnly', 'PDF export needs a PRO or Agency licence.'),
                filename: 'aqg-pdf-export-unavailable.txt',
                contentType: 'text/plain; charset=UTF-8',
                statusCode: 403,
            );
        }

        if (!PdfGenerator::isAvailable()) {
            return $this->pdfLibraryMissingResponse();
        }

        $pdf = $context['pageUid'] !== null && $context['pageUid'] > 0
            ? $this->pdfReportBuilder->buildPagePdf(
                siteIdentifier: $context['siteIdentifier'],
                pageUid: $context['pageUid'],
                status: $context['status'],
                severity: $context['severity'],
                request: $request,
                languageUid: $context['localLanguageUid'],
                readablePagesClause: $this->scopeAccessService->readablePagesClause(),
            )
            : $this->pdfReportBuilder->buildOverviewPdf(
                siteIdentifier: $context['siteIdentifier'],
                status: $context['status'],
                severity: $context['severity'],
                request: $request,
                languageUid: $context['localLanguageUid'],
                readablePagesClause: $this->scopeAccessService->readablePagesClause(),
            );

        return $this->downloadResponse(
            content: $pdf,
            filename: $this->buildFilename('content', $context['siteIdentifier'], $context['pageUid'], 'pdf'),
            contentType: 'application/pdf',
        );
    }

    /**
     * @return array{
     *   siteIdentifier:string,
     *   pageUid:int|null,
     *   status:string,
     *   severity:string,
     *   scope:string,
     *   remotePageUid:int,
     *   remoteScanUid:int,
     *   languageUid:int,
     *   localLanguageUid:int
     * }
     */
    private function parseExportContext(ServerRequestInterface $request): array
    {
        $pageUid = $this->requestParameterService->getPageUid($request);
        $status = $this->requestParameterService->getStatus($request, '');

        if (
            !$this->requestParameterService->hasQueryParam($request, 'status')
            && $this->requestParameterService->getString($request, 'all') === '1'
        ) {
            $status = 'all';
        }

        $status = $status === ''
            ? FilterValueUtility::normalizeStatus('')
            : FilterValueUtility::normalizeStatus($status);

        $severity = FilterValueUtility::normalizeSeverity(
            $this->requestParameterService->getSeverity($request, 'all')
        );

        $siteIdentifier = $this->siteResolutionService->resolveSiteIdentifierForBackendRequest(
            $request,
            $pageUid
        );

        $scope = trim((string)($request->getQueryParams()['scope'] ?? 'local'));
        $remotePageUid = (int)($request->getQueryParams()['remotePageUid'] ?? 0);
        $remoteScanUid = (int)($request->getQueryParams()['remoteScanUid'] ?? 0);
        $languageUid = $this->requestParameterService->getLanguageUid($request, 0);
        // Content scan exports cover the language the view shows (its links always name it); -1 ("all") exports every
        // language explicitly, and so does a link without a language (exports made before the parameter existed).
        $localLanguageUid = $this->requestParameterService->hasLanguageParameter($request->getQueryParams())
            ? $this->requestParameterService->getLanguageUid($request, -1)
            : -1;

        return [
            'siteIdentifier' => $siteIdentifier,
            'pageUid' => $pageUid,
            'status' => $status,
            'severity' => $severity,
            'scope' => $scope === 'remote' ? 'remote' : 'local',
            'remotePageUid' => $remotePageUid,
            'remoteScanUid' => $remoteScanUid,
            'languageUid' => $languageUid,
            'localLanguageUid' => $localLanguageUid,
        ];
    }

    /** @param array<string, mixed>|null $target the authorized scan (authorizedOverviewTarget()), null for none */
    private function buildRemoteCsvResponse(
        string $siteIdentifier,
        int $remotePageUid,
        ?array $target,
    ): ResponseInterface {
        $csv = $remotePageUid > 0
            ? $this->remoteExportBuilder->buildPageCsv($remotePageUid)
            : $this->remoteExportBuilder->buildOverviewCsvForTarget($target);

        return $this->downloadResponse(
            content: "\xEF\xBB\xBF" . $csv,
            filename: $remotePageUid > 0
                ? ExportFilename::build('frontend', 'detail', $this->frontendDetailSubject($remotePageUid, $siteIdentifier), time(), 'csv')
                : ExportFilename::build('frontend', 'overview', $siteIdentifier, time(), 'csv'),
            contentType: 'text/csv; charset=UTF-8',
        );
    }

    private function buildRemotePdfResponse(
        ServerRequestInterface $request,
        string $siteIdentifier,
        int $remotePageUid,
        ?array $target,
    ): ResponseInterface {
        $pdf = $remotePageUid > 0
            ? $this->remoteExportBuilder->buildPagePdf($remotePageUid, $request)
            : $this->remoteExportBuilder->buildOverviewPdfForTarget($siteIdentifier, $target, $request);

        return $this->downloadResponse(
            content: $pdf,
            filename: $remotePageUid > 0
                ? ExportFilename::build('frontend', 'detail', $this->frontendDetailSubject($remotePageUid, $siteIdentifier), time(), 'pdf')
                : ExportFilename::build('frontend', 'overview', $siteIdentifier, time(), 'pdf'),
            contentType: 'application/pdf',
        );
    }

    /**
     * Baseline → current acceptance evidence (PDF or CSV) for two compatible stored scans of a site.
     *
     * The job IDs come from the comparison link; they must be two readable, compatible scans of the named
     * site (RemoteScanPairingService), the user needs the same edit access as for any remote export, and the
     * site needs a PRO or Agency licence.
     */
    public function acceptanceAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $siteIdentifier = trim((string)($params['site'] ?? ''));
        $format = strtolower(trim((string)($params['format'] ?? 'pdf'))) === 'csv' ? 'csv' : 'pdf';
        $pair = $this->remoteScanPairingService->resolveComparePair(
            $siteIdentifier,
            (string)($params['fromJobId'] ?? ''),
            (string)($params['toJobId'] ?? ''),
        );
        $site = $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier);

        // Sol R05: acceptance evidence compares every page of both scans; only administrators read all of them.
        $restricted = $this->remoteResultAuthorizationService === null || !$this->remoteResultAuthorizationService->isUnrestrictedUser();
        if ($restricted || $pair === null || !$site instanceof Site || !$this->scopeAccessService->canEditRemoteScan($pair['to'])) {
            return $this->downloadResponse(
                content: BackendLabelUtility::translate('module.accessDenied', 'Access denied.'),
                filename: 'aqg-acceptance-evidence-access-denied.txt',
                contentType: 'text/plain; charset=UTF-8',
                statusCode: 403,
            );
        }

        if (!$this->canExportPdf($site, $siteIdentifier)) {
            return $this->downloadResponse(
                content: BackendLabelUtility::translate('export.acceptance.paidOnly', 'Acceptance evidence needs a PRO or Agency licence.'),
                filename: 'aqg-acceptance-evidence-unavailable.txt',
                contentType: 'text/plain; charset=UTF-8',
                statusCode: 403,
            );
        }

        if ($format === 'pdf' && !PdfGenerator::isAvailable()) {
            return $this->pdfLibraryMissingResponse();
        }

        $evidence = $this->acceptanceEvidenceBuilder->build($site, $pair['from'], $pair['to']);
        $filename = ExportFilename::build('frontend', 'acceptance', $siteIdentifier, time(), $format);

        return $format === 'csv'
            ? $this->downloadResponse($this->acceptanceEvidenceBuilder->renderCsv($evidence), $filename, 'text/csv; charset=UTF-8')
            : $this->downloadResponse($this->acceptanceEvidenceBuilder->renderPdf($evidence, $request), $filename, 'application/pdf');
    }

    private function canAccessLocalExport(
        ServerRequestInterface $request,
        string $siteIdentifier,
        ?int $pageUid,
    ): bool {
        if ($pageUid !== null && $pageUid > 0) {
            return $this->backendRecordAccessService->canEditRecord(Tables::PAGES, $pageUid);
        }

        $site = $this->resolveSiteForExport($request, $siteIdentifier, $pageUid);
        $rootPageId = $site !== null ? (int)$site->getRootPageId() : 0;

        return $rootPageId > 0
            && $this->backendRecordAccessService->canEditRecord(Tables::PAGES, $rootPageId);
    }

    private function canAccessRemoteExport(string $siteIdentifier, int $remotePageUid, int $remoteScanUid = 0, int $pageUid = 0, int $languageUid = 0): bool
    {
        if ($remotePageUid > 0) {
            $remotePage = $this->remoteScanRepository->findPageByUid($remotePageUid);
            if (!is_array($remotePage)) {
                return false;
            }

            $remoteScanUid = (int)($remotePage['remote_scan'] ?? 0);
            $remoteScan = $remoteScanUid > 0
                ? $this->remoteScanRepository->findScanByUid($remoteScanUid)
                : null;

            return is_array($remoteScan) && $this->canAccessRemoteScan($remoteScan)
                // Sol R05: a result page of a readable scan is still refused when its own page or record rights fail,
                // answered like a page that does not exist.
                && $this->isRemotePageVisible($remoteScan, $remotePageUid);
        }

        // F4 final review: without a result page the export may pick the latest scan of the requested page and language
        // itself (also when the named scan is not completed, RemoteExportBuilder::resolveOverviewExportScan()). A
        // translation record's uid (I-01 legacy rows stored it as language 0) is no export context in any case.
        if ($pageUid > 0 && $this->scopeAccessService->isTranslationPageRecord($pageUid)) {
            return false;
        }

        if ($remoteScanUid > 0) {
            $remoteScan = $this->remoteScanRepository->findScanByUid($remoteScanUid);
            return is_array($remoteScan) && $this->canAccessRemoteScan($remoteScan);
        }

        if ($siteIdentifier === '') {
            return false;
        }
        // Without a scan, a language the user may not use would name a scan the user may not read; its dates and scope
        // would reach the export.
        if ($languageUid >= 0 && !$this->scopeAccessService->canUseLanguage($languageUid)) {
            return false;
        }

        $site = $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier);
        $rootPageId = $site !== null ? (int)$site->getRootPageId() : 0;

        return $rootPageId > 0
            && $this->backendRecordAccessService->canEditRecord(Tables::PAGES, $rootPageId);
    }

    /**
     * Server-owned site identifier for a remote export request.
     *
     * Authorization must follow the record that will actually be exported, so the entitlement is
     * resolved from the scan's own site_identifier. A caller-supplied identifier may only agree
     * with that owner; it can never be used to borrow another site's entitlement. Returns an empty
     * string when the request cannot be bound to a stored scan.
     */
    private function resolveRemoteExportOwnerSiteIdentifier(
        string $requestedSiteIdentifier,
        int $remotePageUid,
        int $remoteScanUid,
    ): string {
        $remoteScan = null;

        if ($remotePageUid > 0) {
            $remotePage = $this->remoteScanRepository->findPageByUid($remotePageUid);
            if (!is_array($remotePage)) {
                return '';
            }

            $scanUid = (int)($remotePage['remote_scan'] ?? 0);
            $remoteScan = $scanUid > 0 ? $this->remoteScanRepository->findScanByUid($scanUid) : null;
            if (!is_array($remoteScan)) {
                return '';
            }
        } elseif ($remoteScanUid > 0) {
            $remoteScan = $this->remoteScanRepository->findScanByUid($remoteScanUid);
            if (!is_array($remoteScan)) {
                return '';
            }
        }

        if (is_array($remoteScan)) {
            $ownerSiteIdentifier = trim((string)($remoteScan['site_identifier'] ?? ''));
            if ($ownerSiteIdentifier === '') {
                return '';
            }

            $requestedSiteIdentifier = trim($requestedSiteIdentifier);
            if ($requestedSiteIdentifier !== '' && $requestedSiteIdentifier !== $ownerSiteIdentifier) {
                return '';
            }

            return $ownerSiteIdentifier;
        }

        return trim($requestedSiteIdentifier);
    }

    /**
     * @param array<string, mixed> $remoteScan
     */
    private function isRemotePageVisible(array $remoteScan, int $remotePageUid): bool
    {
        if ($this->remoteResultAuthorizationService === null) {
            return false;
        }
        if ($this->remoteResultAuthorizationService->isUnrestrictedUser()) {
            return true;
        }
        try {
            return $this->remoteResultAuthorizationService->authorize($remoteScan)->isResultPageVisible($remotePageUid);
        } catch (RemoteResultsTooLargeException) {
            return false;
        }
    }

    private function canAccessRemoteScan(array $remoteScan): bool
    {
        // Sol R03: a page scan needs the page itself, in the scan's site and language; never the site root for a page scan
        // whose page was deleted or moved away. AUTH-03: a site scan needs edit access to the site root AND the scan's
        // language (canEditRemoteScan()), the same rule as reading it — root rights alone never open a site scan of a
        // language the user may not use.
        return $this->scopeAccessService->canEditRemoteScan($remoteScan);
    }

    /**
     * AUTH-03: resolve the one report target of a frontend overview export and authorize exactly that row.
     *
     * - A named scan that does not fit (other site, not completed, Free Remote Preview, other page, wrong scope) is
     *   refused; no other scan replaces it.
     * - A named or fallback scan the user may not export (canEditRemoteScan(): page/translation/language or root/language
     *   rights) is refused; a fallback never moves on to another scan, so no metadata of a refused scan is rendered.
     * - No stored scan at all: the "no completed scan" report, for users with edit access to the site root and the
     *   requested language.
     *
     * @return array{scan: array<string, mixed>|null}|null null when the export is refused
     */
    private function authorizedOverviewTarget(string $ownerSiteIdentifier, int $remoteScanUid, ?int $pageUid, int $languageUid): ?array
    {
        if ($pageUid !== null && $pageUid > 0 && $this->scopeAccessService->isTranslationPageRecord($pageUid)) {
            return null;
        }
        $target = $this->remoteExportBuilder->resolveOverviewExportTarget($ownerSiteIdentifier, $remoteScanUid, $pageUid, $languageUid);
        if ($target['explicitInvalid'] || ($target['resultsUnsaved'] ?? false)) {
            // F12-R1: an unsaved scan has no report to export (RemoteExportBuilder::resolveOverviewExportTarget()).
            return null;
        }
        $scan = $target['scan'];
        if (is_array($scan)) {
            return (string)($scan['site_identifier'] ?? '') === $ownerSiteIdentifier && $this->canAccessRemoteScan($scan)
                ? ['scan' => $scan]
                : null;
        }
        if (!$this->scopeAccessService->canUseScanLanguage($languageUid)) {
            return null;
        }
        $site = $this->siteResolutionService->resolveSiteByIdentifier($ownerSiteIdentifier);
        $rootPageId = $site !== null ? (int)$site->getRootPageId() : 0;

        return $rootPageId > 0 && $this->backendRecordAccessService->canEditRecord(Tables::PAGES, $rootPageId)
            ? ['scan' => null]
            : null;
    }

    private function canExportPdf(?Site $site, string $siteIdentifier): bool
    {
        $proStatus = $site !== null
            ? $this->proStatusResolverService->resolveForSite($site)
            : $this->proStatusResolverService->resolveForSiteIdentifier($siteIdentifier);

        return $proStatus->valid
            && !$proStatus->isTrial
            && $proStatus->hasExportPdf;
    }

    private function hasPaidRemoteAccess(string $siteIdentifier): bool
    {
        $proStatus = $this->proStatusResolverService->resolveForSiteIdentifier($siteIdentifier);

        return (bool)($proStatus->valid ?? false) && (bool)($proStatus->hasCrawler ?? false);
    }

    private function resolveSiteForExport(
        ServerRequestInterface $request,
        string $siteIdentifier,
        ?int $pageUid,
    ): ?Site {
        if ($pageUid !== null && $pageUid > 0) {
            $site = $this->siteResolutionService->resolveSiteForBackendRequest($request, $pageUid);
            if ($site !== null) {
                return $site;
            }
        }

        if ($siteIdentifier !== '') {
            return $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier);
        }

        return null;
    }

    private function buildFilename(string $source, string $siteIdentifier, ?int $pageUid, string $extension): string
    {
        return $pageUid !== null && $pageUid > 0
            ? ExportFilename::build($source, 'detail', (string)$pageUid, time(), $extension)
            : ExportFilename::build($source, 'overview', $siteIdentifier, time(), $extension);
    }

    /**
     * A Frontend detail export is named after the TYPO3 page, not the internal ID of the stored frontend page record:
     * the page of a page scan, else the page the URL resolves to. An unmapped URL keeps a recognisable `r{uid}`.
     */
    private function frontendDetailSubject(int $remotePageUid, string $siteIdentifier): string
    {
        $remotePage = $this->remoteScanRepository->findPageByUid($remotePageUid) ?? [];
        $scan = $this->remoteScanRepository->findScanByUid((int)($remotePage['remote_scan'] ?? 0)) ?? [];
        $pageUid = (string)($scan['scan_scope'] ?? '') === 'page' ? (int)($scan['page_uid'] ?? 0) : 0;
        if ($pageUid <= 0 && isset($this->frontendPageUrlService)) {
            $site = $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier !== '' ? $siteIdentifier : (string)($scan['site_identifier'] ?? ''));
            if ($site instanceof Site) {
                $pageUid = $this->frontendPageUrlService->resolvePageUidForUrl($site, (string)($remotePage['url'] ?? ''), (int)($scan['language_uid'] ?? -1));
            }
        }

        return $pageUid > 0 ? (string)$pageUid : 'r' . $remotePageUid;
    }

    private function pdfLibraryMissingResponse(): ResponseInterface
    {
        return $this->downloadResponse(
            content: BackendLabelUtility::translate('export.pdf.libraryMissing', 'PDF export needs the mPDF library (mpdf/mpdf), which this TYPO3 installation does not provide. CSV export keeps working.'),
            filename: 'aqg-pdf-export-unavailable.txt',
            contentType: 'text/plain; charset=UTF-8',
            statusCode: 503,
        );
    }

    private function downloadResponse(
        string $content,
        string $filename,
        string $contentType,
        int $statusCode = 200,
    ): ResponseInterface {
        $stream = $this->streamFactory->createStream($content);

        return $this->responseFactory
            ->createResponse($statusCode)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string)mb_strlen($content, '8bit'))
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withBody($stream);
    }
}
