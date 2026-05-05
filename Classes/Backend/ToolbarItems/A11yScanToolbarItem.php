<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems;

use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

final class A11yScanToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    private ServerRequestInterface $request;

    /**
     * @var array<int, array<string, string>>
     */
    private array $localToolbarInformation = [];

    /**
     * @var array<int, array<string, string>>
     */
    private array $remoteToolbarInformation = [];

    public function __construct(
        private readonly BackendViewFactory $backendViewFactory,
        private readonly AccessControlService $accessControlService,
        private readonly ScanStatusService $scanStatusService,
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly SiteFinder $siteFinder,
        private readonly ProStatusResolverService $proStatusResolverService,
    ) {
    }

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function checkAccess(): bool
    {
        return $this->accessControlService->canShowToolbarItem();
    }

    public function getItem(): string
    {
        if (!$this->checkAccess()) {
            return '';
        }

        $view = $this->createView();

        return $view->render('ToolbarItems/A11yScanToolbarItem');
    }

    public function hasDropDown(): bool
    {
        return true;
    }

    public function getDropDown(): string
    {
        if (!$this->checkAccess()) {
            return '';
        }

        $localStatus = $this->scanStatusService->getStatus();
        $showRemoteSection = $this->shouldShowRemoteSection();
        $remoteStatus = $showRemoteSection ? $this->resolveRemoteStatus() : [];

        $this->collectLocalInformation($localStatus);

        if ($showRemoteSection) {
            $this->collectRemoteInformation($remoteStatus);
        } else {
            $this->remoteToolbarInformation = [];
        }

        $view = $this->createView();
        $view->assignMultiple([
            'localToolbarInformation' => $this->localToolbarInformation,
            'remoteToolbarInformation' => $this->remoteToolbarInformation,
            'localScanStatus' => $localStatus,
            'remoteScanStatus' => $remoteStatus,
            'showRemoteSection' => $showRemoteSection,
        ]);

        return $view->render('ToolbarItems/A11yScanToolbarDropDown');
    }

    public function getAdditionalAttributes(): array
    {
        return [];
    }

    public function getIndex(): int
    {
        return 60;
    }

    private function createView()
    {
        return $this->backendViewFactory->create(
            $this->request,
            ['a11y_quality_gate']
        );
    }

    private function shouldShowRemoteSection(): bool
    {
        return $this->proStatusResolverService->hasCrawlerForAnySite();
    }

    /**
     * @param array<string, mixed> $status
     */
    private function collectLocalInformation(array $status): void
    {
        $this->localToolbarInformation = [];

        $isRunning = (bool)($status['running'] ?? false);

        $this->localToolbarInformation[] = [
            'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.status',
            'value' => $isRunning
                ? 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.status.running'
                : 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.status.idle',
            'iconIdentifier' => $isRunning ? 'actions-play' : 'actions-circle',
        ];

        if (!empty($status['trigger'])) {
            $this->localToolbarInformation[] = [
                'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.trigger',
                'value' => (string)$status['trigger'],
                'iconIdentifier' => 'actions-system-extension-configure',
            ];
        }

        if (!empty($status['triggeredBy'])) {
            $this->localToolbarInformation[] = [
                'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.triggeredBy',
                'value' => (string)$status['triggeredBy'],
                'iconIdentifier' => 'actions-user',
            ];
        }

        if (!empty($status['startedAt'])) {
            $this->localToolbarInformation[] = [
                'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.startedAt',
                'value' => date('d.m.Y H:i', (int)$status['startedAt']),
                'iconIdentifier' => 'actions-clock',
            ];
        }

        if (!empty($status['finishedAt'])) {
            $this->localToolbarInformation[] = [
                'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.lastFinished',
                'value' => date('d.m.Y H:i', (int)$status['finishedAt']),
                'iconIdentifier' => 'actions-check',
            ];
        }

        if (isset($status['summary']) && is_array($status['summary'])) {
            $summary = $status['summary'];

            $this->localToolbarInformation[] = [
                'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.lastResult',
                'value' => sprintf(
                    '%d new / %d resolved / %d ignored',
                    (int)($summary['issuesNew'] ?? 0),
                    (int)($summary['issuesResolved'] ?? 0),
                    (int)($summary['issuesIgnored'] ?? 0),
                ),
                'iconIdentifier' => 'status-dialog-information',
            ];
        }

        if (!empty($status['error'])) {
            $this->localToolbarInformation[] = [
                'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.error',
                'value' => (string)$status['error'],
                'iconIdentifier' => 'actions-exclamation-circle-alt',
            ];
        }
    }

    /**
     * @param array<string, mixed> $status
     */
    private function collectRemoteInformation(array $status): void
    {
        $this->remoteToolbarInformation = [];

        $remoteStatus = (string)($status['status'] ?? '');
        $isRunning = in_array($remoteStatus, ['waiting', 'queued', 'running', 'active'], true);

        $this->remoteToolbarInformation[] = [
            'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.status',
            'value' => $remoteStatus !== '' ? $remoteStatus : '—',
            'iconIdentifier' => $isRunning ? 'actions-play' : 'actions-circle',
        ];

        if (!empty($status['started_at'])) {
            $this->remoteToolbarInformation[] = [
                'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.startedAt',
                'value' => date('d.m.Y H:i', (int)$status['started_at']),
                'iconIdentifier' => 'actions-clock',
            ];
        }

        if (!empty($status['finished_at'])) {
            $this->remoteToolbarInformation[] = [
                'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.lastFinished',
                'value' => date('d.m.Y H:i', (int)$status['finished_at']),
                'iconIdentifier' => 'actions-check',
            ];
        }

        if (!empty($status['pages_scanned']) || !empty($status['pages_total'])) {
            $pagesScanned = (int)($status['pages_scanned'] ?? 0);
            $pagesTotal = (int)($status['pages_total'] ?? 0);

            $this->remoteToolbarInformation[] = [
                'title' => 'Pages',
                'value' => $pagesTotal > 0
                    ? sprintf('%d / %d', $pagesScanned, $pagesTotal)
                    : (string)$pagesScanned,
                'iconIdentifier' => 'actions-document',
            ];
        }

        if (
            isset($status['issues_new'])
            || isset($status['issues_resolved'])
            || isset($status['issues_total'])
        ) {
            $this->remoteToolbarInformation[] = [
                'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.lastResult',
                'value' => sprintf(
                    '%d new / %d resolved / %d total',
                    (int)($status['issues_new'] ?? 0),
                    (int)($status['issues_resolved'] ?? 0),
                    (int)($status['issues_total'] ?? 0),
                ),
                'iconIdentifier' => 'status-dialog-information',
            ];
        }

        if (!empty($status['sync_error'])) {
            $this->remoteToolbarInformation[] = [
                'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.error',
                'value' => (string)$status['sync_error'],
                'iconIdentifier' => 'actions-exclamation-circle-alt',
            ];
        }

        if ($this->remoteToolbarInformation === []) {
            $this->remoteToolbarInformation[] = [
                'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.status',
                'value' => '—',
                'iconIdentifier' => 'actions-circle',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRemoteStatus(): array
    {
        $siteIdentifier = $this->resolveSiteIdentifierFromRequest();
        if ($siteIdentifier === '') {
            return [];
        }

        $activeSiteScan = $this->remoteScanRepository->findLatestActiveSiteScanBySite($siteIdentifier);
        if (is_array($activeSiteScan)) {
            return $activeSiteScan;
        }

        $lastSiteScan = $this->remoteScanRepository->findLastCompletedSiteScanBySite($siteIdentifier);
        if (is_array($lastSiteScan)) {
            return $lastSiteScan;
        }

        $activeAnyScan = $this->remoteScanRepository->findLatestActiveScanBySite($siteIdentifier);
        if (is_array($activeAnyScan)) {
            return $activeAnyScan;
        }

        $lastAnyScan = $this->remoteScanRepository->findLastCompletedScanBySite($siteIdentifier);

        return is_array($lastAnyScan) ? $lastAnyScan : [];
    }

    private function resolveSiteIdentifierFromRequest(): string
    {
        $queryParams = $this->request->getQueryParams();

        $siteIdentifier = trim((string)($queryParams['site'] ?? ''));
        if ($siteIdentifier !== '') {
            return $siteIdentifier;
        }

        $pageId = (int)($queryParams['id'] ?? $queryParams['pageUid'] ?? 0);
        if ($pageId > 0) {
            try {
                return $this->siteFinder->getSiteByPageId($pageId)->getIdentifier();
            } catch (\Throwable) {
                return '';
            }
        }

        return '';
    }
}