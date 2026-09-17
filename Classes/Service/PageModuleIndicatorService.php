<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\SourceStateRepository;
use Priebera\A11yQualityGate\FreePreview\FreeRemotePreviewService;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanRecoveryService;
use Priebera\A11yQualityGate\Utility\BackendTimeUtility;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

final class PageModuleIndicatorService
{
    public function __construct(
        private readonly IssueRepository $issueRepository,
        private readonly SourceStateRepository $sourceStateRepository,
        private readonly ScanRepository $scanRepository,
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly ProStatusResolverService $proStatusResolverService,
        private readonly RemoteScanRecoveryService $remoteScanRecoveryService,
        private readonly ScanStatusService $scanStatusService,
        private readonly BackendContextService $backendContextService,
        private readonly UriBuilder $uriBuilder,
        private readonly ViewFactoryInterface $viewFactory,
        private readonly FrontendPageUrlService $frontendPageUrlService,
        private readonly FreeRemotePreviewService $freeRemotePreviewService,
    ) {
    }

    public function buildForPage(int $pageUid, ?Site $site, int $languageUid = 0): string
    {
        $variables = $this->buildViewData($pageUid, $site, $languageUid);

        return $variables === null ? '' : $this->renderTemplate($variables);
    }

    /**
     * @return array<string, mixed>|null null when the page has no site context to report on
     */
    public function buildViewData(int $pageUid, ?Site $site, int $languageUid = 0): ?array
    {
        if ($pageUid <= 0 || !$site instanceof Site) {
            return null;
        }

        $siteIdentifier = trim($site->getIdentifier());
        if ($siteIdentifier === '') {
            return null;
        }

        $proStatus = $this->proStatusResolverService->resolveForSite($site);
        $hasRemoteScanCapability = (bool)($proStatus->valid ?? false) && (bool)($proStatus->hasCrawler ?? false);
        $currentPageUrl = $hasRemoteScanCapability ? $this->frontendPageUrlService->resolveForPage($site, $pageUid, $languageUid) : '';
        $counts = $this->issueRepository->countOpenBySeverity($pageUid, $siteIdentifier, $languageUid);
        $latestPageScan = $this->scanRepository->findLastCompletedPageScan($siteIdentifier, $pageUid, $languageUid);
        $latestLocalSourceScanAt = $this->sourceStateRepository->findLatestScanTimestampForPage($siteIdentifier, $pageUid, $languageUid);
        $latestLocalScanAt = max($latestLocalSourceScanAt, (int)($latestPageScan['finished_at'] ?? 0));
        $hasLocalScanState = $latestLocalScanAt > 0 || $this->hasOpenFindings($counts);
        $scanStatus = $this->scanStatusService->getStatus();
        $remoteActiveScan = $this->remoteScanRepository->findLatestActiveScanBySite($siteIdentifier);
        if (is_array($remoteActiveScan)) {
            $remoteActiveScan = $this->remoteScanRecoveryService->recoverScanIfNeeded(
                $remoteActiveScan,
                (string)$site->getBase(),
            );
        }

        // Free and paid results are never interchangeable: a licensed installation reads licensed scans
        // only, a Free one reads its Free Remote Preview results for this page only.
        if ($hasRemoteScanCapability) {
            $remoteCompletedScan = $this->remoteScanRepository->findLastCompletedRelevantScan($siteIdentifier, $pageUid, $languageUid, false);
            $remotePage = $currentPageUrl !== ''
                ? $this->remoteScanRepository->findLatestPageForCompletedPageScan($siteIdentifier, $pageUid, $languageUid, $currentPageUrl, false)
                : null;
            if (!is_array($remotePage) && $currentPageUrl !== '') {
                $remotePage = $this->remoteScanRepository->findLatestPageByUrl($currentPageUrl, $siteIdentifier, false);
            }
        } else {
            $remoteCompletedScan = $this->remoteScanRepository->findLastCompletedPageScanByPageOrUrl(
                $siteIdentifier,
                $pageUid,
                $languageUid,
                $this->frontendPageUrlService->resolvePublicForPage($site, $pageUid, $languageUid),
                true
            );
            $remotePage = null;
        }

        $isLocalScanRunning = (bool)($scanStatus['running'] ?? false)
            && $this->matchesLanguage((int)($scanStatus['languageUid'] ?? -1), $languageUid)
            && (
                (int)($scanStatus['pageUid'] ?? 0) === $pageUid
                || ((int)($scanStatus['rootPid'] ?? 0) > 0 && (int)($scanStatus['rootPid'] ?? 0) === (int)$site->getRootPageId())
            );

        $isRemoteScanRunning = $hasRemoteScanCapability
            && is_array($remoteActiveScan)
            && in_array((string)($remoteActiveScan['status'] ?? ''), ['waiting', 'queued', 'active', 'running'], true);

        $aqgPageUrl = (string)$this->uriBuilder->buildUriFromRoute('web_a11y.pageDetail', [
            'id' => $pageUid,
            'pageUid' => $pageUid,
            'site' => $siteIdentifier,
            'language' => $languageUid,
        ]);

        $overviewUrl = (string)$this->uriBuilder->buildUriFromRoute('web_a11y', [
            'id' => $pageUid,
            'site' => $siteIdentifier,
            'language' => $languageUid,
        ]);

        $localState = $this->resolveState($counts, $hasLocalScanState, $isLocalScanRunning);
        $remoteState = $this->resolveRemoteState($remoteCompletedScan, $remotePage, $isRemoteScanRunning);
        $hasRemoteScanRun = $this->hasRemoteScanRun($remoteCompletedScan, $remotePage);
        $overallState = $this->resolveOverallState($localState, $remoteState, $hasRemoteScanRun);
        $meta = $this->buildMeta($overallState, $scanStatus, $remoteActiveScan);
        $actions = $this->buildActions($overallState, $aqgPageUrl, $overviewUrl);
        $progress = $this->buildProgress($overallState, $remoteActiveScan);
        $headline = $this->buildPanelHeadline($overallState);
        $body = $this->buildBody($overallState, $isRemoteScanRunning);
        $remoteScanEnabled = $hasRemoteScanCapability && $currentPageUrl !== '';
        $scanMode = $remoteScanEnabled ? 'combined' : 'local';
        // Content (TYPO3 records) and frontend (rendered page) results are separate sources: each row names
        // its own source, count unit and scan time, and neither is presented as the other.
        $rows = [
            [
                'source' => 'content',
                'label' => $this->translate('pageModuleIndicator.row.local', 'Content scan'),
                'state' => $localState,
                'headline' => $this->buildHeadline($localState, $counts),
                'meta' => $this->buildRowMeta($localState, $latestLocalScanAt),
            ],
            [
                'source' => 'frontend',
                'label' => $this->translate('pageModuleIndicator.row.remote', 'Frontend scan'),
                'state' => $remoteState,
                'headline' => $this->buildRemoteHeadline($remoteState, $remoteCompletedScan, $remotePage),
                'meta' => $this->buildRowMeta($remoteState, $this->resolveRemoteScanTimestamp($remoteCompletedScan, $remotePage)),
            ],
        ];

        if ($hasRemoteScanCapability) {
            $remoteHint = $hasRemoteScanRun ? null : [
                'tag' => 'PRO',
                'text' => $this->translate('pageModuleIndicator.proHint.remoteScanAvailable', 'Frontend scan available — run a frontend scan'),
                'linkLabel' => '',
                'linkUrl' => '',
            ];
        } else {
            $remoteHint = $this->buildFreeRemoteHint($site, $siteIdentifier, $pageUid, $languageUid);
        }

        return [
            'title' => $this->translate('pageModuleIndicator.title', 'Accessibility Quality'),
            'overallState' => $overallState,
            'localState' => $localState,
            'remoteState' => $remoteState,
            'statusLabel' => $this->buildStatusLabel($overallState),
            'headline' => $headline,
            'body' => $body,
            'meta' => $meta,
            'rows' => $rows,
            'actions' => $actions,
            'progress' => $progress,
            'remoteHint' => $remoteHint,
            'isRunning' => $overallState === 'running',
            'pageUid' => $pageUid,
            'siteIdentifier' => $siteIdentifier,
            'currentPageUrl' => $currentPageUrl,
            'scanMode' => $scanMode,
            'remoteScanEnabled' => $remoteScanEnabled,
            'languageUid' => $languageUid,
            'runningHeadline' => $this->translate('pageModuleIndicator.headline.running', 'Scan running'),
            'runningBody' => $this->translate('pageModuleIndicator.body.running', 'Checking this page for accessibility issues…'),
            'runningMeta' => $this->translate('pageModuleIndicator.meta.running', 'Started just now'),
            'runningStatus' => $this->translate('pageModuleIndicator.status.running', 'Scanning'),
            'loadingText' => $this->translate('action.scanning', 'Scanning...'),
        ];
    }

    /**
     * Free installations have a real remote option: a limited number of selected-page scans per day
     * (Free Remote Preview), started from the AQG module. The quota comes from the entitlement status
     * the Overview has already cached — the Page module never adds a synchronous API call — so without
     * a cached status the hint stays generic instead of guessing numbers.
     *
     * @return array{tag:string,text:string,linkLabel:string,linkUrl:string}
     */
    private function buildFreeRemoteHint(Site $site, string $siteIdentifier, int $pageUid, int $languageUid): array
    {
        $status = $this->freeRemotePreviewService->peekEntitlementStatus(
            rtrim((string)$site->getBase(), '/') . '/',
            $siteIdentifier,
        );
        $state = (string)($status['state'] ?? '');
        $jobsLimit = max(0, (int)($status['jobsLimit'] ?? 0));
        $scansRemaining = max(0, (int)($status['scansRemaining'] ?? 0));

        if ($status === null || ($state === 'FREE_AVAILABLE' && $jobsLimit === 0)) {
            $text = $this->translate(
                'pageModuleIndicator.freeHint.available',
                'Free Remote Preview: check this page in a real browser from the AQG module — a limited number of free scans per day.'
            );
        } elseif ($state === 'FREE_AVAILABLE') {
            $text = sprintf(
                $this->translate('pageModuleIndicator.freeHint.remaining', 'Free Remote Preview: %1$d of %2$d free scans left today.'),
                $scansRemaining,
                $jobsLimit
            );
        } elseif ($state === 'FREE_USED_TODAY' || $state === 'FREE_LIMIT_REACHED') {
            $text = $this->translate('pageModuleIndicator.freeHint.limitReached', 'Free scan limit reached for today.');
            $resetsAt = strtotime((string)($status['resetsAt'] ?? '')) ?: 0;
            if ($resetsAt > 0) {
                $text .= ' ' . sprintf(
                    $this->translate('pageModuleIndicator.freeHint.nextScans', 'Next free scans: %s.'),
                    BackendTimeUtility::formatDateTime($resetsAt)
                );
            }
        } else {
            $text = $this->translate('pageModuleIndicator.freeHint.unavailable', 'Free Remote Preview status is temporarily unavailable.');
        }

        return [
            'tag' => 'FREE',
            'text' => $text,
            'linkLabel' => $this->translate('pageModuleIndicator.freeHint.open', 'Open frontend scan'),
            'linkUrl' => (string)$this->uriBuilder->buildUriFromRoute('web_a11y', [
                'id' => $pageUid,
                'site' => $siteIdentifier,
                'language' => $languageUid,
                'aqgSource' => 'remote',
            ]),
        ];
    }

    private function matchesLanguage(int $runningLanguageUid, int $currentLanguageUid): bool
    {
        if ($currentLanguageUid < 0 || $runningLanguageUid < 0) {
            return true;
        }

        return $runningLanguageUid === $currentLanguageUid;
    }

    /**
     * @param array{critical:int,warning:int,info:int,needs_review?:int} $counts
     */
    private function resolveState(array $counts, bool $hasLocalScanState, bool $isRunning): string
    {
        if ($isRunning) {
            return 'running';
        }

        if (!$hasLocalScanState) {
            return 'none';
        }

        if (($counts['critical'] ?? 0) > 0) {
            return 'error';
        }

        if (($counts['warning'] ?? 0) > 0 || ($counts['info'] ?? 0) > 0 || ($counts['needs_review'] ?? 0) > 0) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * @param array{critical:int,warning:int,info:int,needs_review?:int} $counts
     */
    private function hasOpenFindings(array $counts): bool
    {
        return ((int)($counts['critical'] ?? 0) + (int)($counts['warning'] ?? 0) + (int)($counts['info'] ?? 0) + (int)($counts['needs_review'] ?? 0)) > 0;
    }

    /**
     * @param array<string,mixed>|null $remoteCompletedScan
     * @param array<string,mixed>|null $remotePage
     */
    private function resolveRemoteState(?array $remoteCompletedScan, ?array $remotePage, bool $isRunning): string
    {
        if ($isRunning) {
            return 'running';
        }

        if (!$this->hasRemoteScanRun($remoteCompletedScan, $remotePage)) {
            return 'none';
        }

        $issuesTotal = $this->getRemoteIssueCount($remoteCompletedScan, $remotePage);
        return $issuesTotal > 0 ? 'error' : 'ok';
    }

    private function resolveOverallState(string $localState, string $remoteState, bool $hasRemoteScanRun): string
    {
        if ($localState === 'running' || $remoteState === 'running') {
            return 'running';
        }

        $states = [$localState];
        if ($hasRemoteScanRun) {
            $states[] = $remoteState;
        }

        if (in_array('error', $states, true)) {
            return 'error';
        }

        if (in_array('warning', $states, true)) {
            return 'warning';
        }

        if (in_array('ok', $states, true)) {
            return 'ok';
        }

        return 'none';
    }

    /**
     * @param array<string,mixed>|null $remoteCompletedScan
     * @param array<string,mixed>|null $remotePage
     */
    private function hasRemoteScanRun(?array $remoteCompletedScan, ?array $remotePage): bool
    {
        return is_array($remotePage)
            || (
                is_array($remoteCompletedScan)
                && (string)($remoteCompletedScan['scan_scope'] ?? '') === 'page'
                && (int)($remoteCompletedScan['finished_at'] ?? 0) > 0
            );
    }

    /**
     * @param array{critical:int,warning:int,info:int,needs_review?:int} $counts
     */
    private function buildHeadline(string $state, array $counts): string
    {
        if ($state === 'running') {
            return $this->translate('pageModuleIndicator.headline.running', 'Scan running');
        }

        if ($state === 'none') {
            return $this->translate('pageModuleIndicator.headline.none', 'Not scanned yet');
        }

        if ($state === 'ok') {
            return $this->translate('pageModuleIndicator.headline.ok', 'No issues found');
        }

        $critical = (int)($counts['critical'] ?? 0);
        $warning = (int)($counts['warning'] ?? 0);
        $info = (int)($counts['info'] ?? 0);
        $needsReview = (int)($counts['needs_review'] ?? 0);

        $parts = [];
        if ($critical > 0) {
            $parts[] = sprintf($this->translate('pageModuleIndicator.metric.critical', '%d critical'), $critical);
        }
        if ($warning > 0) {
            $parts[] = sprintf($this->translate('pageModuleIndicator.metric.warnings', '%d warnings'), $warning);
        }
        if ($info > 0) {
            $parts[] = sprintf($this->translate('pageModuleIndicator.metric.notes', '%d notes'), $info);
        }
        if ($needsReview > 0) {
            $parts[] = sprintf($this->translate('pageModuleIndicator.metric.needsReview', '%d needs review'), $needsReview);
        }

        return $parts !== [] ? implode(' · ', $parts) : $this->translate('pageModuleIndicator.headline.ok', 'No issues found');
    }

    /**
     * @param array<string,mixed>|null $remoteCompletedScan
     * @param array<string,mixed>|null $remotePage
     */
    private function buildRemoteHeadline(string $state, ?array $remoteCompletedScan, ?array $remotePage): string
    {
        if ($state === 'running') {
            return $this->translate('pageModuleIndicator.headline.running', 'Scan running');
        }

        if ($state === 'none') {
            return $this->translate('pageModuleIndicator.headline.none', 'Not scanned yet');
        }

        if ($state === 'ok') {
            return $this->translate('pageModuleIndicator.headline.ok', 'No issues found');
        }

        $issuesTotal = $this->getRemoteIssueCount($remoteCompletedScan, $remotePage);

        // A page row counts issue types (one per rule); a bare page scan only reports its occurrences.
        return is_array($remotePage)
            ? $this->countLabelFor($issuesTotal, 'pageModuleIndicator.metric.issueTypes', '%d issue type', '%d issue types')
            : $this->countLabelFor($issuesTotal, 'pageModuleIndicator.metric.occurrences', '%d occurrence', '%d occurrences');
    }

    /**
     * The panel headline summarises the overall state only; source-specific counts stay in their rows.
     */
    private function buildPanelHeadline(string $overallState): string
    {
        return match ($overallState) {
            'running' => $this->translate('pageModuleIndicator.headline.running', 'Scan running'),
            'none' => $this->translate('pageModuleIndicator.headline.none', 'Not scanned yet'),
            'error' => $this->translate('pageModuleIndicator.headline.issues', 'Accessibility issues found'),
            'warning' => $this->translate('pageModuleIndicator.headline.attention', 'Some checks need attention'),
            default => $this->translate('pageModuleIndicator.headline.ok', 'No issues found'),
        };
    }

    private function buildRowMeta(string $state, int $scannedAt): string
    {
        if ($state === 'running' || $scannedAt <= 0) {
            return '';
        }

        return sprintf(
            $this->translate('pageModuleIndicator.row.lastScan', 'Last scan %s'),
            BackendTimeUtility::formatDateTime($scannedAt)
        );
    }

    /**
     * @param array<string,mixed>|null $remoteCompletedScan
     * @param array<string,mixed>|null $remotePage
     */
    private function resolveRemoteScanTimestamp(?array $remoteCompletedScan, ?array $remotePage): int
    {
        if (is_array($remotePage) && (int)($remotePage['remote_scan_finished_at'] ?? 0) > 0) {
            return (int)$remotePage['remote_scan_finished_at'];
        }

        if (is_array($remoteCompletedScan) && (string)($remoteCompletedScan['scan_scope'] ?? '') === 'page') {
            return max(0, (int)($remoteCompletedScan['finished_at'] ?? 0));
        }

        return 0;
    }

    private function countLabelFor(int $count, string $prefix, string $singular, string $plural): string
    {
        return $count === 1
            ? sprintf($this->translate($prefix . '.singular', $singular), $count)
            : sprintf($this->translate($prefix . '.plural', $plural), $count);
    }

    /**
     * @param array<string,mixed>|null $remoteCompletedScan
     * @param array<string,mixed>|null $remotePage
     */
    private function getRemoteIssueCount(?array $remoteCompletedScan, ?array $remotePage): int
    {
        if (is_array($remotePage)) {
            return max(0, (int)($remotePage['issues_count'] ?? 0));
        }

        if (is_array($remoteCompletedScan) && (string)($remoteCompletedScan['scan_scope'] ?? '') === 'page') {
            return max(0, (int)(
                $remoteCompletedScan['issues_total']
                ?? $remoteCompletedScan['issues_found']
                ?? $remoteCompletedScan['issues_count']
                ?? 0
            ));
        }

        return 0;
    }

    private function buildBody(string $state, bool $isRemoteScanRunning): string
    {
        if ($state === 'running') {
            return $isRemoteScanRunning
                ? $this->translate('pageModuleIndicator.body.runningRemote', 'Checking this page for accessibility issues…')
                : $this->translate('pageModuleIndicator.body.running', 'Checking this page for accessibility issues…');
        }

        if ($state === 'none') {
            return $this->translate('pageModuleIndicator.body.none', 'Run a scan to check this page for accessibility issues.');
        }

        if ($state === 'ok') {
            return $this->translate('pageModuleIndicator.body.noOpenIssues', 'Automated checks found no open issues. Manual review may still be required.');
        }

        if ($state === 'warning') {
            return $this->translate('pageModuleIndicator.body.warning', 'Some accessibility checks need attention before publishing.');
        }

        return $this->translate('pageModuleIndicator.body.issues', 'This page has accessibility issues that should be reviewed before publishing.');
    }

    /**
     * @return list<array{label:string,url:string,variant:string,type:string,isScan:bool}>
     */
    private function buildActions(string $state, string $aqgPageUrl, string $overviewUrl): array
    {
        $scanAction = [
            'label' => $state === 'none'
                ? $this->translate('pageModuleIndicator.action.scanThisPage', 'Scan this page')
                : $this->translate('pageModuleIndicator.action.scanAgain', 'Scan again'),
            'url' => '',
            'variant' => 'default',
            'type' => 'scan',
            'isScan' => true,
        ];

        if ($state === 'running') {
            return [[
                'label' => $this->translate('pageModuleIndicator.action.viewProgress', 'View progress'),
                'url' => $overviewUrl,
                'variant' => 'default',
                'type' => 'link',
                'isScan' => false,
            ]];
        }

        if ($state === 'none') {
            return [$scanAction];
        }

        if ($state === 'ok') {
            return [
                $scanAction,
                [
                    'label' => $this->translate('pageModuleIndicator.action.openReport', 'Open report'),
                    'url' => $aqgPageUrl,
                    'variant' => 'default',
                    'type' => 'link',
                    'isScan' => false,
                ],
            ];
        }

        return [
            [
                'label' => $this->translate('pageModuleIndicator.action.openIssues', 'Open issues'),
                'url' => $aqgPageUrl,
                'variant' => 'primary',
                'type' => 'link',
                'isScan' => false,
            ],
            $scanAction,
        ];
    }

    /**
     * @param array<string,mixed>|null $remoteActiveScan
     * @return array{percent:int,modeClass:string}|null
     */
    private function buildProgress(string $state, ?array $remoteActiveScan): ?array
    {
        if ($state !== 'running') {
            return null;
        }

        $pagesScanned = is_array($remoteActiveScan) ? (int)($remoteActiveScan['pages_scanned'] ?? 0) : 0;
        $pagesTotal = is_array($remoteActiveScan) ? (int)($remoteActiveScan['pages_total'] ?? 0) : 0;
        $percent = $pagesTotal > 0 ? max(5, min(100, (int)round(($pagesScanned / $pagesTotal) * 100))) : 42;
        $modeClass = $pagesTotal > 0 ? 'is-determinate' : 'is-indeterminate';

        return [
            'percent' => $percent,
            'modeClass' => $modeClass,
        ];
    }

    /**
     * Progress of a running scan. Scan times are shown per source in the status rows.
     *
     * @param array<string,mixed> $scanStatus
     * @param array<string,mixed>|null $remoteActiveScan
     */
    private function buildMeta(
        string $state,
        array $scanStatus,
        ?array $remoteActiveScan,
    ): string {
        if ($state === 'running') {
            if (is_array($remoteActiveScan)) {
                $pagesScanned = (int)($remoteActiveScan['pages_scanned'] ?? 0);
                $pagesTotal = (int)($remoteActiveScan['pages_total'] ?? 0);
                if ($pagesTotal > 0) {
                    return sprintf($this->translate('pageModuleIndicator.meta.remoteProgressTotal', 'Frontend scan: %d/%d pages processed.'), $pagesScanned, $pagesTotal);
                }
                if ($pagesScanned > 0) {
                    return sprintf($this->translate('pageModuleIndicator.meta.remoteProgress', 'Frontend scan: %d pages processed.'), $pagesScanned);
                }
            }

            $startedAt = (int)($scanStatus['startedAt'] ?? 0);
            if ($startedAt > 0) {
                return $this->translate('pageModuleIndicator.meta.localRunning', 'Started just now');
            }

            return $this->translate('pageModuleIndicator.meta.running', 'Started just now');
        }

        return '';
    }

    private function buildStatusLabel(string $state): string
    {
        return match ($state) {
            'ok' => $this->translate('pageModuleIndicator.status.ok', 'OK'),
            'warning' => $this->translate('pageModuleIndicator.status.warning', 'Warnings'),
            'error' => $this->translate('pageModuleIndicator.status.error', 'Issues'),
            'running' => $this->translate('pageModuleIndicator.status.running', 'Scanning'),
            default => $this->translate('pageModuleIndicator.status.none', 'Not scanned'),
        };
    }

    private function translate(string $key, string $fallback): string
    {
        $translated = $this->backendContextService->translate($key);
        return $translated !== '' ? $translated : $fallback;
    }

    /**
     * @param array<string,mixed> $variables
     */
    private function renderTemplate(array $variables): string
    {
        $view = $this->viewFactory->create(
            new ViewFactoryData(
                templateRootPaths: [
                    GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Templates/'),
                ],
                partialRootPaths: [
                    GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Partials/'),
                ],
                layoutRootPaths: [
                    GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Layouts/'),
                ],
            )
        );

        $view->assignMultiple($variables);

        return $view->render('Backend/PageModuleIndicator');
    }
}
