<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use TYPO3\CMS\Backend\Routing\UriBuilder;

final class ExportUrlBuilderService
{
    public function __construct(
        private readonly UriBuilder $uriBuilder,
    ) {
    }

    public function buildOverviewCsvUrl(string $siteIdentifier, bool $remote = false): string
    {
        return $this->buildRouteUrl('web_a11y.exportCsv', $this->buildOverviewParameters($siteIdentifier, $remote));
    }

    public function buildOverviewPdfUrl(string $siteIdentifier, bool $remote = false): string
    {
        return $this->buildRouteUrl('web_a11y.exportPdf', $this->buildOverviewParameters($siteIdentifier, $remote));
    }

    public function buildLocalPageCsvUrl(
        string $siteIdentifier,
        int $pageUid,
        string $status,
        string $severity,
    ): string {
        return $this->buildRouteUrl('web_a11y.exportCsv', [
            'site' => $siteIdentifier,
            'pageUid' => $pageUid,
            'status' => $status,
            'severity' => $severity,
        ]);
    }

    public function buildLocalPagePdfUrl(
        string $siteIdentifier,
        int $pageUid,
        string $status,
        string $severity,
    ): string {
        return $this->buildRouteUrl('web_a11y.exportPdf', [
            'site' => $siteIdentifier,
            'pageUid' => $pageUid,
            'id' => $pageUid,
            'status' => $status,
            'severity' => $severity,
        ]);
    }

    public function buildRemotePageCsvUrl(string $siteIdentifier, int $remotePageUid): string
    {
        return $this->buildRouteUrl('web_a11y.exportCsv', [
            'site' => $siteIdentifier,
            'scope' => 'remote',
            'remotePageUid' => $remotePageUid,
        ]);
    }

    public function buildRemotePagePdfUrl(string $siteIdentifier, int $remotePageUid): string
    {
        return $this->buildRouteUrl('web_a11y.exportPdf', [
            'site' => $siteIdentifier,
            'scope' => 'remote',
            'remotePageUid' => $remotePageUid,
        ]);
    }

    private function buildOverviewParameters(string $siteIdentifier, bool $remote): array
    {
        $parameters = [
            'site' => $siteIdentifier,
        ];

        if ($remote) {
            $parameters['scope'] = 'remote';
        }

        return $parameters;
    }

    private function buildRouteUrl(string $route, array $parameters): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute($route, $parameters);
    }
}