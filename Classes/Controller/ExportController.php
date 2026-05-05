<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Export\IssueExporter;
use Priebera\A11yQualityGate\Export\PdfReportBuilder;
use Priebera\A11yQualityGate\Export\RemoteExportBuilder;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
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
        private readonly SiteResolutionService $siteResolutionService,
        private readonly RequestParameterService $requestParameterService,
        private readonly ProStatusResolverService $proStatusResolverService,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function csvAction(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->parseExportContext($request);

        if ($context['scope'] === 'remote') {
            return $this->buildRemoteCsvResponse(
                siteIdentifier: $context['siteIdentifier'],
                remotePageUid: $context['remotePageUid'],
            );
        }

        $csv = $this->issueExporter->toCsv(
            siteIdentifier: $context['siteIdentifier'],
            pageUid: $context['pageUid'],
            status: $context['status'],
            severity: $context['severity'],
        );

        return $this->downloadResponse(
            content: "\xEF\xBB\xBF" . $csv,
            filename: $this->buildFilename($context['siteIdentifier'], $context['pageUid'], 'csv'),
            contentType: 'text/csv; charset=UTF-8',
        );
    }

    public function pdfAction(ServerRequestInterface $request): ResponseInterface
    {
        $context = $this->parseExportContext($request);

        $site = $this->resolveSiteForExport(
            request: $request,
            siteIdentifier: $context['siteIdentifier'],
            pageUid: $context['pageUid'],
        );

        if (!$this->canExportPdf($site, $context['siteIdentifier'])) {
            return $this->downloadResponse(
                content: 'PDF export is available in AQG PRO only.',
                filename: 'aqg-pdf-export-unavailable.txt',
                contentType: 'text/plain; charset=UTF-8',
                statusCode: 403,
            );
        }

        if ($context['scope'] === 'remote') {
            return $this->buildRemotePdfResponse(
                request: $request,
                siteIdentifier: $context['siteIdentifier'],
                remotePageUid: $context['remotePageUid'],
            );
        }

        $pdf = $context['pageUid'] !== null && $context['pageUid'] > 0
            ? $this->pdfReportBuilder->buildPagePdf(
                siteIdentifier: $context['siteIdentifier'],
                pageUid: $context['pageUid'],
                status: $context['status'],
                severity: $context['severity'],
                request: $request,
            )
            : $this->pdfReportBuilder->buildOverviewPdf(
                siteIdentifier: $context['siteIdentifier'],
                status: $context['status'],
                severity: $context['severity'],
                request: $request,
            );

        return $this->downloadResponse(
            content: $pdf,
            filename: $this->buildFilename($context['siteIdentifier'], $context['pageUid'], 'pdf'),
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
     *   remotePageUid:int
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

        return [
            'siteIdentifier' => $siteIdentifier,
            'pageUid' => $pageUid,
            'status' => $status,
            'severity' => $severity,
            'scope' => $scope === 'remote' ? 'remote' : 'local',
            'remotePageUid' => $remotePageUid,
        ];
    }

    private function buildRemoteCsvResponse(string $siteIdentifier, int $remotePageUid): ResponseInterface
    {
        $csv = $remotePageUid > 0
            ? $this->remoteExportBuilder->buildPageCsv($remotePageUid)
            : $this->remoteExportBuilder->buildOverviewCsv($siteIdentifier);

        return $this->downloadResponse(
            content: "\xEF\xBB\xBF" . $csv,
            filename: $this->buildFilename(
                $siteIdentifier !== '' ? $siteIdentifier : 'remote',
                $remotePageUid > 0 ? $remotePageUid : null,
                'csv',
                'remote'
            ),
            contentType: 'text/csv; charset=UTF-8',
        );
    }

    private function buildRemotePdfResponse(
        ServerRequestInterface $request,
        string $siteIdentifier,
        int $remotePageUid,
    ): ResponseInterface {
        $pdf = $remotePageUid > 0
            ? $this->remoteExportBuilder->buildPagePdf($remotePageUid, $request)
            : $this->remoteExportBuilder->buildOverviewPdf($siteIdentifier, $request);

        return $this->downloadResponse(
            content: $pdf,
            filename: $this->buildFilename(
                $siteIdentifier !== '' ? $siteIdentifier : 'remote',
                $remotePageUid > 0 ? $remotePageUid : null,
                'pdf',
                'remote'
            ),
            contentType: 'application/pdf',
        );
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

    private function buildFilename(
        string $siteIdentifier,
        ?int $pageUid,
        string $extension,
        string $prefix = 'a11y',
    ): string {
        $parts = [$prefix, $siteIdentifier !== '' ? $siteIdentifier : 'export'];

        if ($pageUid !== null) {
            $parts[] = 'page' . $pageUid;
        }

        $parts[] = date('Y-m-d');

        return implode('-', $parts) . '.' . $extension;
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