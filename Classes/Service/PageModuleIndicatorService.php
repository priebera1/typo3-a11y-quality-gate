<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\SourceStateRepository;
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
    ) {
    }

    public function buildForPage(int $pageUid, ?Site $site, int $languageUid = 0): string
    {
        if ($pageUid <= 0 || !$site instanceof Site) {
            return '';
        }

        $siteIdentifier = trim($site->getIdentifier());
        if ($siteIdentifier === '') {
            return '';
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

        $remoteCompletedScan = $this->remoteScanRepository->findLastCompletedRelevantScan($siteIdentifier, $pageUid, $languageUid);
        $remotePage = $currentPageUrl !== ''
            ? $this->remoteScanRepository->findLatestPageForCompletedPageScan($siteIdentifier, $pageUid, $languageUid, $currentPageUrl)
            : null;
        if (!is_array($remotePage) && $currentPageUrl !== '') {
            $remotePage = $this->remoteScanRepository->findLatestPageByUrl($currentPageUrl, $siteIdentifier);
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
        $remoteState = $hasRemoteScanCapability
            ? $this->resolveRemoteState($remoteCompletedScan, $remotePage, $isRemoteScanRunning)
            : 'none';
        $overallState = $this->resolveOverallState($localState, $hasRemoteScanCapability ? $remoteState : 'none', $hasRemoteScanCapability && $this->hasRemoteScanRun($remoteCompletedScan, $remotePage));
        $hasRemoteScanRun = $hasRemoteScanCapability && $this->hasRemoteScanRun($remoteCompletedScan, $remotePage);
        $meta = $this->buildMeta($overallState, $counts, $scanStatus, $remoteActiveScan, $remoteCompletedScan, $latestLocalScanAt);
        $actions = $this->buildActions($overallState, $aqgPageUrl, $overviewUrl);
        $progress = $this->buildProgress($overallState, $remoteActiveScan);
        $headline = $this->buildPanelHeadline($overallState, $localState, $remoteState, $counts, $remoteCompletedScan, $remotePage, $hasRemoteScanRun);
        $body = $this->buildBody($overallState, $isRemoteScanRunning, $hasRemoteScanRun);
        $remoteScanEnabled = $hasRemoteScanCapability && $currentPageUrl !== '';
        $scanMode = $remoteScanEnabled ? 'combined' : 'local';
        $rows = [[
            'label' => $this->translate('pageModuleIndicator.row.local', 'Local scan'),
            'state' => $localState,
            'headline' => $this->buildHeadline($localState, $counts),
        ]];
        if ($hasRemoteScanCapability) {
            $rows[] = [
                'label' => $this->translate('pageModuleIndicator.row.remote', 'Remote scan'),
                'state' => $remoteState,
                'headline' => $this->buildRemoteHeadline($remoteState, $remoteCompletedScan, $remotePage),
            ];
        } else {
            $rows[] = [
                'label' => $this->translate('pageModuleIndicator.row.remote', 'Remote scan'),
                'state' => 'none',
                'headline' => $this->translate('pageModuleIndicator.headline.none', 'Not scanned'),
            ];
        }

        $proHint = null;
        if (!$hasRemoteScanCapability || !$hasRemoteScanRun) {
            $proHint = $hasRemoteScanCapability
                ? $this->translate('pageModuleIndicator.proHint.remoteScanAvailable', 'Frontend scan available — run a remote scan')
                : $this->translate('pageModuleIndicator.proHint.frontendScan', 'Frontend scan available in PRO');
        }

        return $this->renderTemplate([
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
            'proHint' => $proHint,
            'isRunning' => $overallState === 'running',
            'pageUid' => $pageUid,
            'siteIdentifier' => $siteIdentifier,
            'currentPageUrl' => $currentPageUrl,
            'scanMode' => $scanMode,
            'remoteScanEnabled' => $remoteScanEnabled,
            'languageUid' => $languageUid,
            'runningHeadline' => $this->translate('pageModuleIndicator.headline.running', 'Scan running'),
            'runningBody' => $this->translate('pageModuleIndicator.body.running', 'Checking this page for accessibility issues...'),
            'runningMeta' => $this->translate('pageModuleIndicator.meta.running', 'Started just now'),
            'runningStatus' => $this->translate('pageModuleIndicator.status.running', 'Scanning'),
            'loadingText' => $this->translate('action.scanning', 'Scanning...'),
        ]);
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
            $parts[] = sprintf($this->translate('pageModuleIndicator.metric.errors', '%d errors'), $critical);
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
            return $this->translate('pageModuleIndicator.headline.running', 'Scanning...');
        }

        if ($state === 'none') {
            return $this->translate('pageModuleIndicator.headline.none', 'Not scanned');
        }

        if ($state === 'ok') {
            return $this->translate('pageModuleIndicator.headline.ok', 'No issues found');
        }

        $issuesTotal = $this->getRemoteIssueCount($remoteCompletedScan, $remotePage);
        return sprintf($this->translate('pageModuleIndicator.metric.errors', '%d errors'), $issuesTotal);
    }

    /**
     * @param array{critical:int,warning:int,info:int,needs_review?:int} $counts
     * @param array<string,mixed>|null $remoteCompletedScan
     * @param array<string,mixed>|null $remotePage
     */
    private function buildPanelHeadline(
        string $overallState,
        string $localState,
        string $remoteState,
        array $counts,
        ?array $remoteCompletedScan,
        ?array $remotePage,
        bool $hasRemoteScanRun,
    ): string {
        if ($overallState === 'running') {
            return $this->translate('pageModuleIndicator.headline.running', 'Scan running');
        }

        if ($localState === 'none' && (!$hasRemoteScanRun || $remoteState === 'none')) {
            return $this->translate('pageModuleIndicator.headline.none', 'Not scanned yet');
        }

        if ($localState === 'error' || $localState === 'warning') {
            return $this->buildHeadline($localState, $counts);
        }

        if ($hasRemoteScanRun && $remoteState === 'error') {
            return $this->buildRemoteHeadline($remoteState, $remoteCompletedScan, $remotePage);
        }

        if ($hasRemoteScanRun && $remoteState === 'warning') {
            return $this->buildRemoteHeadline($remoteState, $remoteCompletedScan, $remotePage);
        }

        return $this->translate('pageModuleIndicator.headline.ok', 'No issues found');
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

    private function buildBody(string $state, bool $isRemoteScanRunning, bool $hasRemoteCompletedScan): string
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
            return $hasRemoteCompletedScan
                ? $this->translate('pageModuleIndicator.body.okWithRemote', 'This page currently passes the configured accessibility checks. Review AQG for frontend results.')
                : $this->translate('pageModuleIndicator.body.ok', 'This page currently passes the configured accessibility checks.');
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
     * @param array{critical:int,warning:int,info:int,needs_review?:int} $counts
     * @param array<string,mixed> $scanStatus
     * @param array<string,mixed>|null $remoteActiveScan
     * @param array<string,mixed>|null $remoteCompletedScan
     */
    private function buildMeta(
        string $state,
        array $counts,
        array $scanStatus,
        ?array $remoteActiveScan,
        ?array $remoteCompletedScan,
        int $latestLocalScanAt,
    ): string {
        if ($state === 'running') {
            if (is_array($remoteActiveScan)) {
                $pagesScanned = (int)($remoteActiveScan['pages_scanned'] ?? 0);
                $pagesTotal = (int)($remoteActiveScan['pages_total'] ?? 0);
                if ($pagesTotal > 0) {
                    return sprintf($this->translate('pageModuleIndicator.meta.remoteProgressTotal', 'Remote scan: %d/%d pages processed.'), $pagesScanned, $pagesTotal);
                }
                if ($pagesScanned > 0) {
                    return sprintf($this->translate('pageModuleIndicator.meta.remoteProgress', 'Remote scan: %d pages processed.'), $pagesScanned);
                }
            }

            $startedAt = (int)($scanStatus['startedAt'] ?? 0);
            if ($startedAt > 0) {
                return $this->translate('pageModuleIndicator.meta.localRunning', 'Started just now');
            }

            return $this->translate('pageModuleIndicator.meta.running', 'Started just now');
        }

        if ($state === 'none') {
            return $this->translate('pageModuleIndicator.meta.none', 'No scan on record');
        }

        if ($latestLocalScanAt > 0) {
            return sprintf(
                $this->translate('pageModuleIndicator.meta.lastScanned', 'Last scanned %s'),
                BackendTimeUtility::formatDateTime($latestLocalScanAt)
            );
        }

        $openTotal = (int)($counts['critical'] ?? 0) + (int)($counts['warning'] ?? 0) + (int)($counts['info'] ?? 0) + (int)($counts['needs_review'] ?? 0);
        if ($openTotal > 0) {
            return sprintf($this->translate('pageModuleIndicator.meta.openTotal', '%d open findings in local checks.'), $openTotal);
        }

        if (is_array($remoteCompletedScan) && (int)($remoteCompletedScan['finished_at'] ?? 0) > 0) {
            return sprintf(
                $this->translate('pageModuleIndicator.meta.remoteCompleted', 'Frontend scan available. Last remote sync: %s.'),
                BackendTimeUtility::formatDateTime((int)$remoteCompletedScan['finished_at'])
            );
        }

        return $this->translate('pageModuleIndicator.meta.ok', 'Last scanned recently');
    }

    private function buildStatusLabel(string $state): string
    {
        return match ($state) {
            'ok' => $this->translate('pageModuleIndicator.status.ok', 'OK'),
            'warning' => $this->translate('pageModuleIndicator.status.warning', 'Warnings'),
            'error' => $this->translate('pageModuleIndicator.status.error', 'Errors'),
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
