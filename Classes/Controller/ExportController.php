<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Export\IssueExporter;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Site\SiteFinder;

#[AsController]
final class ExportController
{
    public function __construct(
        private readonly IssueExporter $issueExporter,
        private readonly SiteFinder $siteFinder,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function csvAction(ServerRequestInterface $request): ResponseInterface
    {
        [$siteIdentifier, $pageUid, $onlyOpen] = $this->parseParams($request);

        $csv = $this->issueExporter->toCsv($siteIdentifier, $pageUid, $onlyOpen);
        $filename = $this->buildFilename($siteIdentifier, $pageUid, 'csv');

        return $this->downloadResponse(
            content: "\xEF\xBB\xBF" . $csv,
            filename: $filename,
            contentType: 'text/csv; charset=UTF-8',
        );
    }

    public function jsonAction(ServerRequestInterface $request): ResponseInterface
    {
        [$siteIdentifier, $pageUid, $onlyOpen] = $this->parseParams($request);

        $json = $this->issueExporter->toJson($siteIdentifier, $pageUid, $onlyOpen);
        $filename = $this->buildFilename($siteIdentifier, $pageUid, 'json');

        return $this->downloadResponse(
            content: $json,
            filename: $filename,
            contentType: 'application/json; charset=UTF-8',
        );
    }

    /**
     * @return array{0:string,1:int|null,2:bool}
     */
    private function parseParams(ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();

        $pageUid = isset($params['pageUid']) && $params['pageUid'] !== ''
            ? (int)$params['pageUid']
            : null;

        $onlyOpen = !isset($params['all']) || (string)$params['all'] !== '1';
        $siteIdentifier = $this->resolveSiteIdentifier($request, $pageUid);

        return [$siteIdentifier, $pageUid, $onlyOpen];
    }

    private function resolveSiteIdentifier(ServerRequestInterface $request, ?int $pageUid): string
    {
        $explicit = trim((string)($request->getQueryParams()['site'] ?? ''));
        if ($explicit !== '') {
            try {
                return $this->siteFinder->getSiteByIdentifier($explicit)->getIdentifier();
            } catch (\Throwable) {
            }
        }

        if ($pageUid !== null) {
            try {
                return $this->siteFinder->getSiteByPageId($pageUid)->getIdentifier();
            } catch (\Throwable) {
            }
        }

        $sites = $this->siteFinder->getAllSites();

        return $sites !== [] ? reset($sites)->getIdentifier() : '';
    }

    private function buildFilename(string $siteIdentifier, ?int $pageUid, string $extension): string
    {
        $parts = ['a11y', $siteIdentifier !== '' ? $siteIdentifier : 'export'];

        if ($pageUid !== null) {
            $parts[] = 'page' . $pageUid;
        }

        $parts[] = date('Y-m-d');

        return implode('-', $parts) . '.' . $extension;
    }

    private function downloadResponse(string $content, string $filename, string $contentType): ResponseInterface
    {
        $stream = $this->streamFactory->createStream($content);

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string)strlen($content))
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withBody($stream);
    }
}
