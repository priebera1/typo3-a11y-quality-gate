<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems;

use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanRecoveryService;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;

final class A11yScanToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    private ServerRequestInterface $request;

    public function __construct(
        private readonly BackendViewFactory $backendViewFactory,
        private readonly AccessControlService $accessControlService,
        private readonly ScanStatusService $scanStatusService,
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly ProStatusResolverService $proStatusResolverService,
        private readonly RemoteScanRecoveryService $remoteScanRecoveryService,
        private readonly RequestParameterService $requestParameterService,
        private readonly SiteResolutionService $siteResolutionService,
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

        $localStatus = $this->languageAwareLocalStatus($this->scanStatusService->getStatus());
        $showRemoteSection = $this->shouldShowRemoteSection();
        $remoteStatus = $showRemoteSection ? $this->resolveRemoteStatus() : [];
        $toolbarState = $this->buildToolbarState($localStatus, $remoteStatus, $showRemoteSection);

        $view = $this->createView();
        $view->assignMultiple([
            'toolbarIcon' => $this->buildToolbarIcon($toolbarState),
        ]);

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

        $localStatus = $this->languageAwareLocalStatus($this->scanStatusService->getStatus());
        $showRemoteSection = $this->shouldShowRemoteSection();
        $remoteStatus = $showRemoteSection ? $this->resolveRemoteStatus() : [];
        $toolbarState = $this->buildToolbarState($localStatus, $remoteStatus, $showRemoteSection);

        $view = $this->createView();
        $view->assignMultiple([
            'localScanStatus' => $localStatus,
            'remoteScanStatus' => $remoteStatus,
            'showRemoteSection' => $showRemoteSection,
            'toolbarRunning' => $toolbarState['running'],
            'toolbarFootnote' => $toolbarState['footnote'],
            'toolbarState' => $toolbarState,
            'toolbarAriaLabel' => $this->buildToolbarAriaLabel($toolbarState),
            'currentLanguageUid' => $this->requestParameterService->getLanguageUid($this->request),
            'localToolbarCard' => $this->buildLocalCard($localStatus),
            'remoteToolbarCard' => $showRemoteSection ? $this->buildRemoteCard($remoteStatus) : [],
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
     * @return array<string, mixed>
     */
    private function languageAwareLocalStatus(array $status): array
    {
        if (!(bool)($status['running'] ?? false)) {
            return $status;
        }

        $currentLanguageUid = $this->requestParameterService->getLanguageUid($this->request);
        $runningLanguageUid = (int)($status['languageUid'] ?? -1);
        if ($currentLanguageUid >= 0 && $runningLanguageUid >= 0 && $currentLanguageUid !== $runningLanguageUid) {
            $status['running'] = false;
        }

        return $status;
    }

    /**
     * @param array<string, mixed> $localStatus
     * @param array<string, mixed> $remoteStatus
     * @return array{state: string, tone: string, count: int, running: bool, footnote: string}
     */
    private function buildToolbarState(array $localStatus, array $remoteStatus, bool $showRemoteSection): array
    {
        $localRunning = (bool)($localStatus['running'] ?? false);
        $remoteStatusValue = (string)($remoteStatus['status'] ?? '');
        $remoteRunning = $showRemoteSection && $this->isRemoteRunningStatus($remoteStatusValue);
        $hasError = !empty($localStatus['error']) || $remoteStatusValue === 'failed' || !empty($remoteStatus['sync_error']);

        $localNew = 0;
        if (isset($localStatus['summary']) && is_array($localStatus['summary'])) {
            $localNew = (int)($localStatus['summary']['issuesNew'] ?? 0);
        }
        $remoteNew = $showRemoteSection ? (int)($remoteStatus['issues_new'] ?? 0) : 0;
        $newIssues = $localNew + $remoteNew;

        $hasAnyScan = !empty($localStatus['finishedAt']) || !empty($remoteStatus['finished_at']) || !empty($remoteStatus['uid']);

        if ($localRunning || $remoteRunning) {
            return [
                'state' => 'running',
                'tone' => 'info',
                'count' => 0,
                'running' => true,
                'footnote' => $remoteRunning ? $this->buildRemoteProgressLabel($remoteStatus) : 'Local scan is running',
            ];
        }

        if ($hasError) {
            return [
                'state' => 'failed',
                'tone' => 'error',
                'count' => 0,
                'running' => false,
                'footnote' => 'Last scan needs attention',
            ];
        }

        if ($newIssues > 0) {
            return [
                'state' => 'issues',
                'tone' => $newIssues >= 3 ? 'critical' : 'warning',
                'count' => $newIssues,
                'running' => false,
                'footnote' => sprintf('%d new issue%s found', $newIssues, $newIssues === 1 ? '' : 's'),
            ];
        }

        if (!$hasAnyScan) {
            return [
                'state' => 'not-scanned',
                'tone' => 'none',
                'count' => 0,
                'running' => false,
                'footnote' => 'No scan result yet',
            ];
        }

        return [
            'state' => 'ok',
            'tone' => 'ok',
            'count' => 0,
            'running' => false,
            'footnote' => 'No new issues in the latest scan',
        ];
    }

    /**
     * @param array{state: string, tone: string, count: int, running: bool, footnote: string} $state
     */
    private function buildToolbarIcon(array $state): string
    {
        $stateClass = htmlspecialchars($state['state'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $label = htmlspecialchars($this->buildToolbarAriaLabel($state), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $status = '<span class="aqt-toolbar-dot aqt-toolbar-dot--' . htmlspecialchars($state['tone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></span>';

        return <<<HTML
<span class="t3js-icon aqt-toolbar-trigger aqt-toolbar-trigger--{$stateClass}" role="img" aria-label="{$label}">
    <span class="aqt-toolbar-icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 18 18" focusable="false">
            <circle cx="9" cy="9" r="8" fill="none" stroke="currentColor" stroke-width="1.4" />
            <circle cx="9" cy="4.6" r="1.05" fill="currentColor" />
            <path d="M3.6 6.6 L14.4 6.6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" />
            <path d="M9 6.6 L9 10 L6.5 14.4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" fill="none" />
            <path d="M9 10 L11.5 14.4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" fill="none" />
        </svg>
        {$status}
    </span>
</span>
HTML;
    }

    /**
     * @param array{state: string, tone: string, count: int, running: bool, footnote: string} $state
     */
    private function buildToolbarAriaLabel(array $state): string
    {
        return match ($state['state']) {
            'running' => 'Accessibility scan is running',
            'failed' => 'Accessibility scan failed',
            'issues' => sprintf('Accessibility: %d new issue%s', $state['count'], $state['count'] === 1 ? '' : 's'),
            'ok' => 'Accessibility: no new issues',
            default => 'Accessibility: not scanned yet',
        };
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function buildLocalCard(array $status): array
    {
        $summary = isset($status['summary']) && is_array($status['summary']) ? $status['summary'] : [];
        $running = (bool)($status['running'] ?? false);
        $hasError = !empty($status['error']);
        $newIssues = (int)($summary['issuesNew'] ?? 0);
        $resolved = (int)($summary['issuesResolved'] ?? 0);
        $ignored = (int)($summary['issuesIgnored'] ?? 0);
        $pages = isset($summary['pagesScanned']) ? (string)(int)$summary['pagesScanned'] : '—';

        $tone = $this->resolveCardTone($running, $hasError, $newIssues, !empty($status['finishedAt']));

        return [
            'label' => 'Local',
            'description' => 'Checks TYPO3 content records.',
            'tone' => $tone,
            'statusTone' => $running ? 'info' : ($hasError ? 'error' : ($newIssues > 0 ? 'warning' : (!empty($status['finishedAt']) ? 'ok' : 'none'))),
            'statusLabel' => $running ? 'Running' : ($hasError ? 'Failed' : ($newIssues > 0 ? sprintf('Idle · %d new', $newIssues) : (!empty($status['finishedAt']) ? 'Idle · clean' : 'Not run yet'))),
            'statusIcon' => $this->statusIcon($running ? 'info' : ($hasError ? 'error' : ($newIssues > 0 ? 'warning' : (!empty($status['finishedAt']) ? 'ok' : 'none')))),
            'running' => $running,
            'progressWidth' => null,
            'meta' => [
                [
                    'label' => $running ? 'Started' : 'Last finished',
                    'value' => $this->formatTimestamp((int)($running ? ($status['startedAt'] ?? 0) : ($status['finishedAt'] ?? 0))),
                    'muted' => empty($running ? ($status['startedAt'] ?? 0) : ($status['finishedAt'] ?? 0)),
                ],
                [
                    'label' => 'Pages',
                    'value' => $pages,
                    'muted' => $pages === '—',
                ],
            ],
            'results' => [
                $this->resultItem('New', $running ? '—' : $newIssues, $newIssues > 0 ? 'critical' : ''),
                $this->resultItem('Resolved', $running ? '—' : $resolved, $resolved > 0 ? 'ok' : ''),
                $this->resultItem('Ignored', $running ? '—' : $ignored),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function buildRemoteCard(array $status): array
    {
        $statusValue = (string)($status['status'] ?? '');
        $running = $this->isRemoteRunningStatus($statusValue);
        $hasError = $statusValue === 'failed' || !empty($status['sync_error']);
        $hasResult = !empty($status['uid']);
        $newIssues = (int)($status['issues_new'] ?? 0);
        $resolved = (int)($status['issues_resolved'] ?? 0);
        $total = (int)($status['issues_total'] ?? 0);
        $pagesScanned = (int)($status['pages_scanned'] ?? 0);
        $pagesTotal = (int)($status['pages_total'] ?? 0);
        $pagesLabel = $this->buildPagesLabel($pagesScanned, $pagesTotal, $hasResult || $running);
        $progressWidth = $running && $pagesTotal > 0 ? (int)min(100, max(1, round(($pagesScanned / $pagesTotal) * 100))) : null;

        $statusTone = $running ? 'info' : ($hasError ? 'error' : ($statusValue === 'completed' ? ($newIssues > 0 ? 'warning' : 'ok') : 'none'));

        return [
            'label' => 'Remote',
            'description' => 'Checks published frontend pages.',
            'tone' => $this->resolveCardTone($running, $hasError, $newIssues, $hasResult),
            'statusTone' => $statusTone,
            'statusLabel' => $this->remoteStatusLabel($statusValue, $hasResult, $newIssues),
            'statusIcon' => $this->statusIcon($statusTone),
            'running' => $running,
            'progressWidth' => $progressWidth,
            'meta' => [
                [
                    'label' => $running ? ($statusValue === 'queued' ? 'Queued since' : 'Started') : 'Last finished',
                    'value' => $this->formatTimestamp((int)($running ? ($status['started_at'] ?? 0) : ($status['finished_at'] ?? 0))),
                    'muted' => empty($running ? ($status['started_at'] ?? 0) : ($status['finished_at'] ?? 0)),
                ],
                [
                    'label' => 'Pages',
                    'value' => $pagesLabel,
                    'muted' => $pagesLabel === '—',
                ],
            ],
            'results' => [
                $this->resultItem('New', $running || !$hasResult ? '—' : $newIssues, $newIssues > 0 ? 'critical' : ''),
                $this->resultItem('Resolved', $running || !$hasResult ? '—' : $resolved, $resolved > 0 ? 'ok' : ''),
                $this->resultItem('Total found', $running || !$hasResult ? '—' : $total, $total > 0 ? 'warning' : ''),
            ],
        ];
    }

    private function resolveCardTone(bool $running, bool $hasError, int $newIssues, bool $hasResult): string
    {
        if ($running) {
            return 'info';
        }
        if ($hasError) {
            return 'error';
        }
        if ($newIssues > 0) {
            return 'warning';
        }

        return $hasResult ? 'ok' : 'none';
    }

    /**
     * @return array{label: string, value: string|int, toneClass: string, muted: bool}
     */
    private function resultItem(string $label, string|int $value, string $tone = ''): array
    {
        $isMuted = $value === '—' || $value === 0 || $value === '0';

        return [
            'label' => $label,
            'value' => $value,
            'toneClass' => $tone !== '' && !$isMuted ? 'tone-' . $tone : '',
            'muted' => $isMuted,
        ];
    }

    private function remoteStatusLabel(string $status, bool $hasResult, int $newIssues): string
    {
        return match ($status) {
            'running', 'active', 'processing', 'in_progress', 'in-progress', 'started' => 'Running',
            'queued', 'waiting' => 'Queued',
            'completed' => $newIssues > 0 ? sprintf('Completed · %d new', $newIssues) : 'Completed',
            'failed' => 'Failed',
            default => $hasResult ? ($status !== '' ? ucfirst($status) : 'Completed') : 'Not run yet',
        };
    }

    private function isRemoteRunningStatus(string $status): bool
    {
        $status = strtolower(trim($status));

        return in_array($status, ['waiting', 'queued', 'running', 'active', 'processing', 'in_progress', 'in-progress', 'started'], true);
    }

    private function buildPagesLabel(int $pagesScanned, int $pagesTotal, bool $hasResult): string
    {
        if (!$hasResult && $pagesScanned === 0 && $pagesTotal === 0) {
            return '—';
        }

        return $pagesTotal > 0 ? sprintf('%d / %d', $pagesScanned, $pagesTotal) : (string)$pagesScanned;
    }

    /**
     * @param array<string, mixed> $status
     */
    private function buildRemoteProgressLabel(array $status): string
    {
        $pagesScanned = (int)($status['pages_scanned'] ?? 0);
        $pagesTotal = (int)($status['pages_total'] ?? 0);

        if ($pagesTotal > 0) {
            return sprintf('Remote scan is running · %d/%d pages', $pagesScanned, $pagesTotal);
        }

        return 'Remote scan is running';
    }

    private function formatTimestamp(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '—';
        }

        return date('d.m. · H:i', $timestamp);
    }

    private function statusIcon(string $tone): string
    {
        return match ($tone) {
            'ok' => '<svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><path d="M2 5.2 L4.2 7.2 L8 3.4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" /></svg>',
            'warning' => '<svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><path d="M5 1.4 L9 8.6 L1 8.6 Z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round" /><path d="M5 4 L5 6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" /><circle cx="5" cy="7.4" r="0.6" fill="currentColor" /></svg>',
            'error' => '<svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><circle cx="5" cy="5" r="3.8" fill="none" stroke="currentColor" stroke-width="1.3" /><path d="M3.6 3.6 L6.4 6.4 M6.4 3.6 L3.6 6.4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" /></svg>',
            default => '<svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><circle cx="5" cy="5" r="3.6" fill="none" stroke="currentColor" stroke-opacity="0.6" stroke-width="1.2" stroke-dasharray="2 2" /></svg>',
        };
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

        $languageUid = $this->requestParameterService->getLanguageUid($this->request);
        $siteBase = $this->siteResolutionService->resolveSiteBaseByIdentifier($siteIdentifier);

        $activeSiteScan = $this->remoteScanRepository->findLatestActiveSiteScanBySite($siteIdentifier, $languageUid);
        if (is_array($activeSiteScan)) {
            return $siteBase !== ''
                ? ($this->remoteScanRecoveryService->recoverScanIfNeeded($activeSiteScan, $siteBase) ?? $activeSiteScan)
                : $activeSiteScan;
        }

        $lastSiteScan = $this->remoteScanRepository->findLastCompletedSiteScanBySite($siteIdentifier, $languageUid);
        if (is_array($lastSiteScan)) {
            return $lastSiteScan;
        }

        $activeAnyScan = $this->remoteScanRepository->findLatestActiveScanBySite($siteIdentifier, $languageUid);
        if (is_array($activeAnyScan)) {
            return $siteBase !== ''
                ? ($this->remoteScanRecoveryService->recoverScanIfNeeded($activeAnyScan, $siteBase) ?? $activeAnyScan)
                : $activeAnyScan;
        }

        $lastAnyScan = $this->remoteScanRepository->findLastCompletedScanBySite($siteIdentifier, $languageUid);

        return is_array($lastAnyScan) ? $lastAnyScan : [];
    }


    private function resolveSiteIdentifierFromRequest(): string
    {
        return $this->siteResolutionService->resolveSiteIdentifierForBackendRequest($this->request);
    }
}
