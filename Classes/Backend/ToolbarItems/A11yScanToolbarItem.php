<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems;

use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Pro\Service\RemoteOccurrenceTotalsService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanRecoveryService;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\ScanResultSummaryService;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Utility\BackendTimeUtility;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;

final class A11yScanToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    private ServerRequestInterface $request;

    public function __construct(
        private readonly BackendViewFactory $backendViewFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly AccessControlService $accessControlService,
        private readonly ScanStatusService $scanStatusService,
        private readonly ScanRepository $scanRepository,
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly ProStatusResolverService $proStatusResolverService,
        private readonly RemoteScanRecoveryService $remoteScanRecoveryService,
        private readonly RequestParameterService $requestParameterService,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly BackendContextService $backendContextService,
        private readonly ScopeAccessService $scopeAccessService,
        private readonly IssueRepository $issueRepository,
        private readonly ?RemoteOccurrenceTotalsService $remoteOccurrenceTotalsService = null,
        private readonly ?ScanResultSummaryService $scanResultSummaryService = null,
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

        $summary = $this->buildSummary(false);

        $view = $this->createView();
        $view->assignMultiple([
            'toolbarIcon' => $this->buildToolbarIcon($summary['state']),
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

        $summary = $this->buildSummary(true);

        $view = $this->createView();
        $view->assignMultiple([
            'toolbarState' => $summary['state'],
            'toolbarRunning' => $summary['state']['running'],
            'contentCard' => $summary['content'],
            'frontendCard' => $summary['frontend'],
            'siteLabel' => $summary['siteLabel'],
            'moduleUrl' => (string)$this->uriBuilder->buildUriFromRoute('web_a11y'),
        ]);

        return $view->render('ToolbarItems/A11yScanToolbarDropDown');
    }

    /**
     * Both cards describe the same site: the request's, else the one of the last Content scan (the toolbar request of
     * the Dashboard and other modules carries no page). Without a readable site the Frontend card is left out instead
     * of claiming "Not scanned yet". What the last frontend scan changed is only needed in the dropdown.
     *
     * @return array{content: array<string, mixed>, frontend: array<string, mixed>|null, state: array{state: string, tone: string, count: int, running: bool, ariaLabel: string}, siteLabel: string}
     */
    private function buildSummary(bool $withFrontendChanges): array
    {
        $builder = $this->summaryBuilder();
        $localStatus = $this->resolveLocalStatus();
        $siteIdentifier = $this->resolveSiteIdentifierFromRequestOrStatus($localStatus);
        $canReadSite = $siteIdentifier !== '' && $this->scopeAccessService->canReadSiteIdentifier($siteIdentifier);

        $contentCard = $builder->contentCard($localStatus, $canReadSite ? $this->countOpenTotals($siteIdentifier) : null);
        $frontendCard = null;
        if ($canReadSite && $this->shouldShowRemoteSection()) {
            $remoteScan = $this->withStoredOccurrences($this->resolveRemoteStatus($siteIdentifier));
            $frontendCard = $builder->frontendCard(
                $remoteScan,
                $withFrontendChanges ? $this->frontendChanges($remoteScan) : null
            );
        }

        return [
            'content' => $contentCard,
            'frontend' => $frontendCard,
            'state' => $builder->toolbarState($contentCard, $frontendCard),
            'siteLabel' => $canReadSite ? $this->siteLabel($siteIdentifier) : '',
        ];
    }

    /**
     * @return array{critical:int,warning:int,info:int,needs_review:int,total:int}|null
     */
    private function countOpenTotals(string $siteIdentifier): ?array
    {
        $languageUid = $this->requestParameterService->getLanguageUid($this->request);

        try {
            return $this->issueRepository->countOpenTotalsForSite($siteIdentifier, max(0, $languageUid));
        } catch (\Throwable) {
            return null;
        }
    }

    private function siteLabel(string $siteIdentifier): string
    {
        $host = (string)parse_url($this->siteResolutionService->resolveSiteBaseByIdentifier($siteIdentifier), PHP_URL_HOST);

        return $host !== '' ? $host : $siteIdentifier;
    }

    private function summaryBuilder(): ToolbarSummaryBuilder
    {
        return new ToolbarSummaryBuilder(
            fn (string $key, string $fallback): string => $this->translate($key, $fallback),
            static fn (int $timestamp): string => BackendTimeUtility::formatDateTime($timestamp, 'd.m.Y H:i'),
            static fn (int $timestamp): string => BackendTimeUtility::formatDateTime($timestamp, 'H:i'),
        );
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
     * @return array<string, mixed>
     */
    private function resolveLocalStatus(): array
    {
        $status = $this->scopeAccessService->restrictLocalScanStatus($this->scanStatusService->getStatus());

        if ((bool)($status['running'] ?? false)) {
            return $this->languageAwareLocalStatus($status);
        }

        return $this->languageAwareLocalStatus(
            $this->mergeWithPersistedLocalScan($status)
        );
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function mergeWithPersistedLocalScan(array $status): array
    {
        $siteIdentifier = $this->resolveSiteIdentifierFromRequestOrStatus($status);
        if (!$this->scopeAccessService->canReadSiteIdentifier($siteIdentifier)) {
            return $status;
        }
        $languageUid = $this->requestParameterService->getLanguageUid($this->request);

        // The registry holds the last content scan of whichever site and language was scanned last. For another site
        // or language it says nothing about this one: that one's own last scan, or none, is shown instead.
        $statusLanguageUid = (int)($status['languageUid'] ?? -1);
        if ($this->resolveSiteIdentifierFromStatus($status) !== $siteIdentifier
            || ($languageUid >= 0 && $statusLanguageUid >= 0 && $statusLanguageUid !== $languageUid)) {
            $status = [];
        }

        try {
            $runningScan = $this->scanRepository->findLatestRunningScan($siteIdentifier, $languageUid);
            if (is_array($runningScan) && !$this->isStaleRunningScan($runningScan)) {
                return $this->buildRunningStatusFromScanRow($runningScan, $status);
            }

            $latestScan = $this->scanRepository->findLastCompletedLocalScan($siteIdentifier, $languageUid);
        } catch (\Throwable) {
            return $status;
        }

        if (!is_array($latestScan)) {
            return $status;
        }

        $persistedScanUid = (int)($latestScan['uid'] ?? 0);
        $persistedFinishedAt = (int)($latestScan['finished_at'] ?? 0);
        if ($persistedFinishedAt <= 0 && $persistedScanUid <= 0) {
            return $status;
        }

        $currentScanUid = $this->extractLocalStatusScanUid($status);
        $currentFinishedAt = (int)($status['finishedAt'] ?? 0);

        if (
            $currentScanUid > 0
            && $persistedScanUid > 0
            && $currentScanUid >= $persistedScanUid
            && is_array($status['summary'] ?? null)
        ) {
            return $status;
        }

        if (
            $currentScanUid <= 0
            && $currentFinishedAt > 0
            && $persistedFinishedAt > 0
            && $currentFinishedAt >= $persistedFinishedAt
            && is_array($status['summary'] ?? null)
        ) {
            return $status;
        }

        return $this->buildFinishedStatusFromScanRow($latestScan, $status);
    }

    /**
     * The TYPO3 toolbar is global and its request does not always include the
     * current page or site. Prefer the request context, then fall back to the
     * last registry scan context. If both are missing, repository methods will
     * intentionally fall back to the newest local scan across all sites.
     *
     * @param array<string, mixed> $status
     */
    private function resolveSiteIdentifierFromRequestOrStatus(array $status): string
    {
        $siteIdentifier = $this->resolveSiteIdentifierFromRequest();

        return $siteIdentifier !== '' ? $siteIdentifier : $this->resolveSiteIdentifierFromStatus($status);
    }

    /**
     * The site of the scanned page or root of a local scan status, '' when unknown.
     *
     * @param array<string, mixed> $status
     */
    private function resolveSiteIdentifierFromStatus(array $status): string
    {
        $pageUid = (int)($status['pageUid'] ?? 0);
        if ($pageUid > 0) {
            $siteIdentifier = $this->siteResolutionService->resolveSiteIdentifierForPageId($pageUid);
            if ($siteIdentifier !== '') {
                return $siteIdentifier;
            }
        }

        $rootPid = (int)($status['rootPid'] ?? 0);
        if ($rootPid > 0) {
            $siteIdentifier = $this->siteResolutionService->resolveSiteIdentifierForPageId($rootPid);
            if ($siteIdentifier !== '') {
                return $siteIdentifier;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $status
     */
    private function extractLocalStatusScanUid(array $status): int
    {
        $summary = $status['summary'] ?? null;
        if (is_array($summary)) {
            $scanUid = (int)($summary['scanUid'] ?? 0);
            if ($scanUid > 0) {
                return $scanUid;
            }
        }

        return (int)($status['scanUid'] ?? 0);
    }

    /**
     * @param array<string, mixed> $scanRow
     * @param array<string, mixed> $fallbackStatus
     * @return array<string, mixed>
     */
    private function buildRunningStatusFromScanRow(array $scanRow, array $fallbackStatus): array
    {
        return [
            'running' => true,
            'startedAt' => (int)($scanRow['started_at'] ?? 0) ?: null,
            'finishedAt' => null,
            'trigger' => $fallbackStatus['trigger'] ?? 'persisted-scan',
            'triggeredBy' => $fallbackStatus['triggeredBy'] ?? 'TYPO3',
            'pageUid' => (string)($scanRow['scope'] ?? '') === 'page' ? (int)($scanRow['root_pid'] ?? 0) : null,
            'rootPid' => (int)($scanRow['root_pid'] ?? 0) ?: null,
            'languageUid' => (int)($scanRow['language_uid'] ?? -1),
            'scanUid' => (int)($scanRow['uid'] ?? 0),
            'summary' => null,
            'error' => null,
            'cancelRequested' => false,
        ];
    }

    /**
     * @param array<string, mixed> $scanRow
     * @param array<string, mixed> $fallbackStatus
     * @return array<string, mixed>
     */
    private function buildFinishedStatusFromScanRow(array $scanRow, array $fallbackStatus): array
    {
        return [
            'running' => false,
            'startedAt' => (int)($scanRow['started_at'] ?? 0) ?: null,
            'finishedAt' => (int)($scanRow['finished_at'] ?? 0),
            'trigger' => $fallbackStatus['trigger'] ?? 'persisted-scan',
            'triggeredBy' => $fallbackStatus['triggeredBy'] ?? 'TYPO3',
            'pageUid' => (string)($scanRow['scope'] ?? '') === 'page' ? (int)($scanRow['root_pid'] ?? 0) : null,
            'rootPid' => (int)($scanRow['root_pid'] ?? 0) ?: null,
            'languageUid' => (int)($scanRow['language_uid'] ?? -1),
            'scanUid' => (int)($scanRow['uid'] ?? 0),
            'summary' => [
                'scanUid' => (int)($scanRow['uid'] ?? 0),
                'pagesScanned' => (int)($scanRow['pages_scanned'] ?? 0),
                'recordsScanned' => (int)($scanRow['records_scanned'] ?? 0),
                'issuesNew' => (int)($scanRow['issues_new'] ?? 0),
                'issuesResolved' => (int)($scanRow['issues_resolved'] ?? 0),
                'issuesIgnored' => (int)($scanRow['issues_ignored'] ?? 0),
                'warnings' => [],
            ],
            'error' => null,
            'cancelRequested' => false,
        ];
    }

    /**
     * @param array<string, mixed>|null $first
     * @param array<string, mixed>|null $second
     * @return array<string, mixed>|null
     */
    private function selectMostRecentLocalScan(?array $first, ?array $second, string $timestampColumn): ?array
    {
        if ($first === null) {
            return $second;
        }

        if ($second === null) {
            return $first;
        }

        return (int)($second[$timestampColumn] ?? 0) > (int)($first[$timestampColumn] ?? 0)
            ? $second
            : $first;
    }

    /**
     * @param array<string, mixed> $scanRow
     */
    private function isStaleRunningScan(array $scanRow): bool
    {
        $startedAt = (int)($scanRow['started_at'] ?? 0);

        return $startedAt > 0 && $startedAt < (time() - 86400);
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
     * @param array{state: string, tone: string, count: int, running: bool, ariaLabel: string} $state
     */
    private function buildToolbarIcon(array $state): string
    {
        $stateClass = htmlspecialchars($state['state'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $label = htmlspecialchars($state['ariaLabel'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $status = '<span class="aqt-toolbar-dot aqt-toolbar-dot--' . htmlspecialchars($state['running'] ? 'running' : $state['tone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></span>';

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

    private function translate(string $key, string $fallback): string
    {
        $translated = $this->backendContextService->translate($key);

        return $translated !== '' && $translated !== $key ? $translated : $fallback;
    }

    /**
     * The scan the Frontend card reports for a readable site: a running scan first, else the last completed paid scan
     * (site scan before page scan). Free Remote Preview results belong to the Overview's Free Remote Preview, which is
     * where an installation without crawler access sees them; the toolbar shows frontend scans only with that access.
     *
     * @return array<string, mixed>
     */
    private function resolveRemoteStatus(string $siteIdentifier): array
    {
        $languageUid = $this->requestParameterService->getLanguageUid($this->request);
        $siteBase = $this->siteResolutionService->resolveSiteBaseByIdentifier($siteIdentifier);

        $activeSiteScan = $this->remoteScanRepository->findLatestActiveSiteScanBySite($siteIdentifier, $languageUid);
        if (is_array($activeSiteScan)) {
            return $siteBase !== ''
                ? ($this->remoteScanRecoveryService->recoverScanIfNeeded($activeSiteScan, $siteBase) ?? $activeSiteScan)
                : $activeSiteScan;
        }

        $lastSiteScan = $this->remoteScanRepository->findLastCompletedSiteScanBySite($siteIdentifier, $languageUid, false);
        if (is_array($lastSiteScan)) {
            return $lastSiteScan;
        }

        $activeAnyScan = $this->remoteScanRepository->findLatestActiveScanBySite($siteIdentifier, $languageUid);
        if (is_array($activeAnyScan)) {
            return $siteBase !== ''
                ? ($this->remoteScanRecoveryService->recoverScanIfNeeded($activeAnyScan, $siteBase) ?? $activeAnyScan)
                : $activeAnyScan;
        }

        $lastPageScan = $this->remoteScanRepository->findLastCompletedPageScanBySite($siteIdentifier, $languageUid, false);

        return is_array($lastPageScan) ? $lastPageScan : [];
    }

    /**
     * New and resolved occurrences of a completed scan against the previous compatible scan, from the stored findings
     * (the scan notification's numbers). Null — no claim — while the service is unavailable.
     *
     * @param array<string, mixed> $scan
     * @return array{available: bool, new?: int, resolved?: int}|null
     */
    private function frontendChanges(array $scan): ?array
    {
        if ($this->scanResultSummaryService === null || strtolower((string)($scan['status'] ?? '')) !== 'completed') {
            return null;
        }

        try {
            return $this->scanResultSummaryService->frontendComparison($scan);
        } catch (\Throwable) {
            return null;
        }
    }


    /**
     * The toolbar reports the same occurrence count as the Overview and the exports: the stored findings of the scan.
     *
     * @param array<string, mixed> $scan
     * @return array<string, mixed>
     */
    private function withStoredOccurrences(array $scan): array
    {
        if ($scan === [] || !isset($this->remoteOccurrenceTotalsService)) {
            return $scan;
        }
        $scan['issues_total'] = $this->remoteOccurrenceTotalsService->forScan($scan)->total;

        return $scan;
    }

    private function resolveSiteIdentifierFromRequest(): string
    {
        return $this->siteResolutionService->resolveSiteIdentifierForBackendRequest($this->request);
    }
}
