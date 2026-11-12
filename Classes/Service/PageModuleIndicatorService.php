<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\FreePreview\FreePreviewActivationService;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\SourceStateRepository;
use Priebera\A11yQualityGate\FreePreview\FreeRemotePreviewService;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanRecoveryService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanSubmissionTracker;
use Priebera\A11yQualityGate\Pro\ViewModel\RemoteScanCoverage;
use Priebera\A11yQualityGate\Utility\BackendTimeUtility;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

final class PageModuleIndicatorService
{
    private const RUNNING_REMOTE_STATUSES = ['waiting', 'queued', 'active', 'running'];

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
        private readonly ?AccessControlService $accessControlService = null,
        private readonly ?RemoteScanSubmissionTracker $remoteScanSubmissionTracker = null,
        private readonly ?ScanPermissionService $scanPermissionService = null,
        private readonly ?FreePreviewActivationService $freePreviewActivationService = null,
        private readonly ?\Priebera\A11yQualityGate\Remediation\Workflow\RemediationPageSummaryService $remediationSummary = null,
        private readonly ?\Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService $remediationAccess = null,
        private readonly ?RemotePageScanTargetResolver $remotePageScanTargetResolver = null,
        private readonly ?\Priebera\A11yQualityGate\Pro\Service\RemoteScanAccessSettingsService $remoteScanAccessSettingsService = null,
        private readonly ?RemoteResultAuthorizationService $remoteResultAuthorizationService = null,
        private readonly ?ScopeAccessService $scopeAccessService = null,
    ) {
    }

    public function buildForPage(int $pageUid, ?Site $site, int $languageUid = 0): string
    {
        $variables = $this->buildViewData($pageUid, $site, $languageUid);

        return $variables === null ? '' : $this->renderTemplate($variables);
    }

    /**
     * The panel as the Page module would render it now, for the indicator's refresh while a scan runs. Rendering
     * reads a running frontend scan back from the AQG service and stores its results once it has completed
     * (RemoteScanRecoveryService), exactly as reloading the Page module does.
     *
     * @return array{running: bool, html: string}|null null when the page has no site context to report on
     */
    public function buildState(int $pageUid, ?Site $site, int $languageUid = 0): ?array
    {
        $variables = $this->buildViewData($pageUid, $site, $languageUid);
        if ($variables === null) {
            return null;
        }

        return [
            'running' => (bool)($variables['isRunning'] ?? false),
            'html' => $this->renderTemplate($variables),
        ];
    }

    /**
     * @return array<string, mixed>|null null when the page has no site context to report on or the user has no
     *                                    access to the Accessibility module
     */
    public function buildViewData(int $pageUid, ?Site $site, int $languageUid = 0): ?array
    {
        if ($pageUid <= 0 || !$site instanceof Site) {
            return null;
        }
        // I-01: the panel is for a default-language page plus a language (that is how the Page module calls it). A
        // translation record's uid read as language 0 filtered the translation's findings out and showed "No issues found"
        // with the translation's scan time to editors without its language.
        if ($this->scopeAccessService instanceof ScopeAccessService && $this->scopeAccessService->isTranslationPageRecord($pageUid)) {
            return null;
        }
        $this->currentPageUidForCount = $pageUid;
        $this->currentRemoteCountPage = null;
        // Web > Page access alone opens no AQG result: the panel follows the Accessibility module like its route.
        if ($this->accessControlService instanceof AccessControlService
            && !$this->accessControlService->canAccessModule($this->backendContextService->getBackendUser())
        ) {
            return null;
        }

        $siteIdentifier = trim($site->getIdentifier());
        if ($siteIdentifier === '') {
            return null;
        }

        $proStatus = $this->proStatusResolverService->resolveForSite($site);
        $hasRemoteScanCapability = (bool)($proStatus->valid ?? false) && (bool)($proStatus->hasCrawler ?? false);
        $currentPageUrl = $hasRemoteScanCapability ? $this->frontendPageUrlService->resolveForPage($site, $pageUid, $languageUid) : '';
        $counts = $this->issueRepository->countOpenBySeverity($pageUid, $siteIdentifier, $languageUid, '', true);
        $latestPageScan = $this->scanRepository->findLastCompletedPageScan($siteIdentifier, $pageUid, $languageUid);
        $latestLocalSourceScanAt = $this->sourceStateRepository->findLatestScanTimestampForPage($siteIdentifier, $pageUid, $languageUid);
        // A site scan checks this page too: a page without content records has no source state to show it.
        $latestSubtreeScan = $this->scanRepository->findLastCompletedSubtreeScanCoveringPage($siteIdentifier, $pageUid, $languageUid);
        $latestLocalScanAt = max(
            $latestLocalSourceScanAt,
            (int)($latestPageScan['finished_at'] ?? 0),
            (int)($latestSubtreeScan['finished_at'] ?? 0),
        );
        $hasLocalScanState = $latestLocalScanAt > 0 || $this->hasOpenFindings($counts);
        $scanStatus = $this->scanStatusService->getStatus();
        $remoteActiveScan = $this->remoteScanRepository->findLatestActiveScanBySite($siteIdentifier);
        if (is_array($remoteActiveScan)) {
            $remoteActiveScan = $this->remoteScanRecoveryService->recoverScanIfNeeded(
                $remoteActiveScan,
                (string)$site->getBase(),
            );
        }
        // Only a scan of this page is followed here: a site crawl, or "Scan this page" for this page and language.
        // A single-page scan of another page of the site is not this page's scan.
        if (is_array($remoteActiveScan) && !$this->remoteScanCoversPage($remoteActiveScan, $pageUid, $languageUid)) {
            $remoteActiveScan = null;
        }

        // Free and paid results are never interchangeable: a licensed installation reads licensed scans
        // only, a Free one reads its Free Remote Preview results for this page only.
        if ($hasRemoteScanCapability) {
            $remoteCompletedScan = $this->remoteScanRepository->findLastCompletedRelevantScan($siteIdentifier, $pageUid, $languageUid, false);
            $pageScanPage = $currentPageUrl !== ''
                ? $this->remoteScanRepository->findLatestPageForCompletedPageScan($siteIdentifier, $pageUid, $languageUid, $currentPageUrl, false)
                : null;
            $urlPage = $currentPageUrl !== '' ? $this->remoteScanRepository->findLatestPageByUrl($currentPageUrl, $siteIdentifier, false) : null;
            $remotePage = $currentPageUrl !== '' ? $this->newestRemotePage($pageScanPage, $urlPage) : null;
            // STATE-01: for a user who sees only the results they may read, a newer result they may not read (a reused URL,
            // a crawl of another owner) does not hide an older one of this page they may read.
            if (is_array($remotePage) && !$this->isRemoteResultUnrestricted() && $this->visibleRemoteIssueTypes($remotePage) === null) {
                $candidates = [$pageScanPage, ...$this->remoteScanRepository->findRecentPagesByUrl($currentPageUrl, $siteIdentifier, false, 10)];
                $readable = null;
                foreach ($candidates as $candidate) {
                    if (is_array($candidate) && $this->visibleRemoteIssueTypes($candidate) !== null) {
                        $readable = $candidate;
                        break;
                    }
                }
                // Review F10-7: a result this user may not read is no result for them (its existence is not shown).
                $remotePage = $readable;
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

        $isRemoteScanActive = $hasRemoteScanCapability
            && is_array($remoteActiveScan)
            && in_array((string)($remoteActiveScan['status'] ?? ''), self::RUNNING_REMOTE_STATUSES, true);
        // Submitted from the Accessibility module but not answered by the AQG service yet: there is no scan record to
        // render, and a panel rendered as not running would have nothing to follow.
        $isRemoteScanStarting = $hasRemoteScanCapability
            && !$isRemoteScanActive
            && $this->isRemoteSubmitPendingForPage($siteIdentifier, $pageUid, $languageUid);
        $isRemoteScanRunning = $isRemoteScanActive || $isRemoteScanStarting;
        if (!$isRemoteScanActive) {
            $remoteActiveScan = null;
        }

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

        // STATE-01: a zero of the findings the user may read is clean only with a completed scan that covered this page in
        // this language (a restricted site scan does not cover pages it left out, ScanRepository::excludesPage()), whose
        // rendered check could fetch the page, read by a user who sees every result.
        $coveringLocalScan = $this->newestLocalScan($latestPageScan, $latestSubtreeScan);
        $renderedSkip = is_array($coveringLocalScan) ? \Priebera\A11yQualityGate\Scan\RenderedCoverage::skippedEntryFor($coveringLocalScan, $pageUid) : null;
        $localEvidence = ResultEvidenceState::resolve(
            running: $isLocalScanRunning,
            hasEvidence: $hasLocalScanState,
            visibleOpen: $this->hasOpenFindings($counts) ? 1 : 0,
            restricted: !$this->isLocalResultUnrestricted(),
            complete: is_array($coveringLocalScan) && !(is_array($renderedSkip) && (string)($renderedSkip['reason'] ?? '') === ''),
        );
        $localState = $this->stateForEvidence($localEvidence, $this->resolveState($counts, $hasLocalScanState, $isLocalScanRunning));
        $hasRemoteScanRun = $this->hasRemoteScanRun($remoteCompletedScan, $remotePage);
        // The Free Remote Preview stores no page row link here; its result page (owner = this page) carries the readable
        // findings for users who see only what they may read.
        $remoteCountPage = $remotePage ?? (!$hasRemoteScanCapability ? $this->freeResultPage($remoteCompletedScan, $pageUid) : null);
        $this->currentRemoteCountPage = $remoteCountPage;
        $remoteEvidence = $this->remoteEvidence($remoteCompletedScan, $remotePage, $remoteCountPage, $isRemoteScanRunning, $hasRemoteScanRun);
        $remoteState = $this->resolveRemoteState($remoteCompletedScan, $remotePage, $isRemoteScanRunning, $remoteEvidence);
        $overallState = $this->resolveOverallState($localState, $remoteState, $hasRemoteScanRun);
        $meta = $this->buildMeta($overallState, $scanStatus, $remoteActiveScan, $isRemoteScanStarting);
        // A site content scan also checks this page: say so instead of "Checking this page" for a whole-site scan.
        $isLocalSiteScanRunning = $isLocalScanRunning && (int)($scanStatus['pageUid'] ?? 0) !== $pageUid;
        $actions = $this->buildActions($overallState, $aqgPageUrl, $overviewUrl);
        // "Scan this page" follows the same permission as the endpoint it calls: the flag and edit access to the page.
        $canScanPage = $this->scanPermissionService instanceof ScanPermissionService
            ? $this->scanPermissionService->canScanPage($pageUid)
            : !($this->accessControlService instanceof AccessControlService)
                || $this->accessControlService->canShowScanNow($this->backendContextService->getBackendUser());
        if (!$canScanPage) {
            $actions = array_values(array_filter($actions, static fn (array $action): bool => !$action['isScan']));
        }
        // Atlas 06·A: "Frontend report" opens the frontend page report when this page has a stored frontend result.
        $remotePageUid = (int)($remotePage['uid'] ?? 0);
        // STATE-01 metadata boundary: the link (it carries the result's uid) only for a result the user may read; the
        // detail route checks the scan and owner again.
        if ($remotePageUid > 0 && !$this->isRemoteResultUnrestricted() && $this->visibleRemoteIssueTypes($remotePage) === null) {
            $remotePageUid = 0;
        }
        if ($hasRemoteScanCapability && $remotePageUid > 0 && $overallState !== 'running') {
            $actions[] = [
                'label' => $this->translate('pageModuleIndicator.action.frontendReport', 'Frontend report'),
                'url' => (string)$this->uriBuilder->buildUriFromRoute('web_a11y.remotePageDetail', [
                    'remotePageUid' => $remotePageUid,
                    'site' => $siteIdentifier,
                    'id' => $pageUid,
                ]),
                'variant' => 'default',
                'type' => 'link',
                'isScan' => false,
            ];
        }
        $progress = $this->buildProgress($overallState, $remoteActiveScan);
        $headline = $this->buildPanelHeadline($overallState);
        $body = $this->buildBody($overallState, $isRemoteScanRunning, $isLocalSiteScanRunning);
        if ($overallState === 'limited') {
            $headline = $this->limitedHeadline($localState === 'limited' ? $localEvidence : $remoteEvidence);
        }
        $remoteScanEnabled = $hasRemoteScanCapability && $currentPageUrl !== '';
        // Atlas 09·E: a page visitors cannot open says so. "Scan again" here also starts the paid frontend scan, which the
        // server stores as a preview scan of this page; "preview scan available" only when that scan can load it (crawler,
        // a Scanner Token, and a restriction the token opens). Read from TYPO3 and local settings, never from the API.
        $notPublic = $this->buildNotPublicState($pageUid, $languageUid, $siteIdentifier, $remoteScanEnabled && $canScanPage);
        if ($notPublic !== null && !$hasRemoteScanRun && $overallState !== 'running') {
            $headline = $notPublic['headline'];
            if ($notPublic['previewAvailable']) {
                $body = $this->translate('pageModuleIndicator.notPublic.body', 'This page is hidden. A preview scan checks how it renders before you make it visible.');
            }
        }
        $scanMode = $remoteScanEnabled ? 'combined' : 'local';
        // Atlas D9 / 09·E (label only): the frontend row shows a preview scan of this page (it was not public) as
        // "Preview scan … · page not public". Nothing else changes; the Quality Gate keeps deciding on the content scan.
        $remoteIsPagePreview = $remoteState !== 'running' && $this->remoteResultIsPagePreview($remoteCompletedScan, $remotePage);
        $remoteRowMeta = $this->buildRowMeta($remoteState, $this->resolveRemoteScanTimestamp($remoteCompletedScan, $remotePage));
        if ($remoteIsPagePreview && $remoteRowMeta !== '') {
            $remoteRowMeta .= ' · ' . $this->translate('pageModuleIndicator.row.pageNotPublic', 'page not public');
        }
        // Content (TYPO3 records) and frontend (rendered page) results are separate sources: each row names
        // its own source, count unit and scan time, and neither is presented as the other.
        $rows = [
            [
                'source' => 'content',
                'label' => $this->translate('pageModuleIndicator.row.local', 'Content scan'),
                'state' => $localState,
                'headline' => $localState === 'limited' ? $this->limitedHeadline($localEvidence) : $this->buildHeadline($localState, $counts),
                'counts' => $this->buildCountItems($localState, $counts),
                'meta' => $this->buildRowMeta($localState, $latestLocalScanAt),
            ],
            [
                'source' => 'frontend',
                'label' => $remoteIsPagePreview || ($notPublic !== null && $notPublic['previewAvailable'] && $remoteState === 'none')
                    ? $this->translate('pageModuleIndicator.row.preview', 'Preview scan')
                    : $this->translate('pageModuleIndicator.row.remote', 'Frontend scan'),
                'state' => $remoteState,
                'headline' => $notPublic !== null && $notPublic['previewAvailable'] && $remoteState === 'none'
                    ? $this->translate('pageModuleIndicator.row.noPreviewScan', 'No preview scan yet')
                    : ($remoteState === 'limited'
                        ? $this->limitedHeadline($remoteEvidence)
                        : $this->buildRemoteHeadline($remoteState, $remoteCompletedScan, $remotePage)),
                'meta' => $remoteRowMeta,
                'isPagePreview' => $remoteIsPagePreview,
            ],
        ];

        if ($hasRemoteScanCapability) {
            // F-35: the installation is entitled to frontend scans (Trial, PRO, Agency) — a plain hint, no upsell tag. On a
            // page that is not public the headline already says whether a (preview) frontend scan can check it.
            $remoteHint = $hasRemoteScanRun || $notPublic !== null ? null : [
                'tag' => '',
                'text' => $this->translate('pageModuleIndicator.proHint.remoteScanAvailable', 'Frontend scan available — run a frontend scan'),
                'linkLabel' => '',
                'linkUrl' => '',
            ];
        } else {
            $remoteHint = $this->buildFreeRemoteHint($site, $siteIdentifier, $pageUid, $languageUid);
        }

        $view = [
            'title' => $this->translate('pageModuleIndicator.title', 'Accessibility'),
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
            // The page's AQG report: the scan notification's action opens it.
            'reportUrl' => $aqgPageUrl,
            'scanMode' => $scanMode,
            'remoteScanEnabled' => $remoteScanEnabled,
            'languageUid' => $languageUid,
            'runningHeadline' => $this->translate('pageModuleIndicator.headline.running', 'Scan running'),
            'runningBody' => $this->translate('pageModuleIndicator.body.running', 'Checking this page for accessibility issues…'),
            // A template: the Page module fills in the clock time when a scan starts from the panel.
            'runningMeta' => $this->translate('pageModuleIndicator.meta.startedAt', 'Started %s'),
            'runningStatus' => $this->translate('pageModuleIndicator.status.running', 'Scanning'),
            'loadingText' => $this->translate('action.scanning', 'Scanning...'),
            'remediation' => $this->buildRemediationLines($pageUid, $siteIdentifier, $languageUid),
            'notPublic' => $notPublic,
        ];

        // M-01: a language the user may not use is not "clean" — its findings are filtered out of every count above.
        // Say so instead of "No issues found", and show no counts, scan time or scan action for it.
        if ($this->scopeAccessService instanceof ScopeAccessService && !$this->scopeAccessService->canUseLanguage($languageUid)) {
            $unavailable = $this->translate('pageModuleIndicator.languageUnavailable.headline', 'Not available for your languages');
            $view['overallState'] = 'none';
            $view['localState'] = 'none';
            $view['remoteState'] = 'none';
            $view['statusLabel'] = $unavailable;
            $view['headline'] = $unavailable;
            $view['body'] = $this->translate('pageModuleIndicator.languageUnavailable.body', 'Accessibility results of this language are shown only to editors who may work in it. Switch to a language you edit.');
            $view['meta'] = '';
            $view['actions'] = [];
            $view['progress'] = null;
            $view['remoteHint'] = null;
            $view['remediation'] = null;
            foreach ($view['rows'] as $index => $row) {
                $view['rows'][$index]['state'] = 'none';
                $view['rows'][$index]['headline'] = $unavailable;
                $view['rows'][$index]['counts'] = [];
                $view['rows'][$index]['meta'] = '';
            }
        }

        return $view;
    }

    /**
     * @return array{badge:string,headline:string,previewAvailable:bool}|null null when visitors can open the page
     */
    private function buildNotPublicState(int $pageUid, int $languageUid, string $siteIdentifier, bool $canStartFrontendScan): ?array
    {
        if ($this->remotePageScanTargetResolver === null) {
            return null;
        }
        $languageUid = max(0, $languageUid);
        if ($this->remotePageScanTargetResolver->visitorRestriction($pageUid, $languageUid) === '') {
            return null;
        }
        $previewAvailable = $canStartFrontendScan
            && $this->remotePageScanTargetResolver->unsupportedPreviewRestriction($pageUid, $languageUid) === ''
            && ($this->remoteScanAccessSettingsService?->hasScannerTokenForSite($siteIdentifier) ?? false);

        return [
            'badge' => $this->translate('pageModuleIndicator.notPublic.badge', 'Not public'),
            'headline' => $previewAvailable
                ? $this->translate('pageModuleIndicator.notPublic.headlineAvailable', 'Not public · preview scan available')
                : $this->translate('pageModuleIndicator.notPublic.headlineContentOnly', 'Not public · content checks only'),
            'previewAvailable' => $previewAvailable,
        ];
    }

    /**
     * The Page module's remediation facts (Atlas 06·A, minimal variant): who should fix the open content-scan issues,
     * how many have AI help, and how many prepared suggestions wait. Content-scan counts only — the frontend row keeps
     * its own. Computed from stored findings; rendering never calls an AI provider.
     *
     * @return array<string,mixed>|null
     */
    private function buildRemediationLines(int $pageUid, string $siteIdentifier, int $languageUid): ?array
    {
        if ($this->remediationSummary === null || $this->remediationAccess === null || $siteIdentifier === ''
            || !$this->remediationAccess->hasLicence($siteIdentifier)) {
            return null;
        }
        $issues = $this->issueRepository->findOpenForPage($pageUid, $siteIdentifier, $languageUid, true);
        if ($issues === []) {
            return null;
        }
        $summary = $this->remediationSummary->summarize($siteIdentifier, $pageUid, $issues);
        $aiHelp = 0;
        if ($this->remediationAccess->aiState($siteIdentifier) === 'ready') {
            foreach ($summary['findings'] as $finding) {
                $aiHelp += in_array($finding['fixPath'], ['ai_image', 'ai_text'], true) ? 1 : 0;
            }
        }
        $pending = (int)$summary['queue']['pending'];
        // Atlas 06·A: owner chips, zero owners left out.
        $owners = [];
        foreach (['editor', 'developer', 'mixed', 'unassigned'] as $owner) {
            $count = (int)($summary['owners'][$owner] ?? 0);
            if ($count > 0) {
                $owners[] = [
                    'owner' => $owner,
                    'count' => $count,
                    'label' => $this->translate('remediation.owner.' . $owner, ucfirst($owner)),
                ];
            }
        }

        return [
            'ownerLabel' => $this->translate('remediation.pm.ownerLabel', 'Owner'),
            'owners' => $owners,
            'ai' => $aiHelp > 0 ? $this->countLabelFor($aiHelp, 'remediation.pm.aiFixable', '%d can be fixed with AI', '%d can be fixed with AI') : '',
            'pending' => $pending > 0 ? $this->countLabelFor($pending, 'remediation.pm.pendingAi', '%d AI suggestion waiting for review', '%d AI suggestions waiting for review') : '',
            'queueUrl' => $pending > 0 ? (string)$this->uriBuilder->buildUriFromRoute('web_a11y.remediation', ['id' => $pageUid, 'pageUid' => $pageUid]) : '',
            'source' => $this->translate('remediation.pm.source', 'Counts from the last content scan of this page; the frontend scan is reported in its own row.'),
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

        $activationState = $this->freePreviewActivationService?->status(rtrim((string)$site->getBase(), '/') . '/')['state'];
        if ($activationState !== null && $activationState !== FreePreviewActivationService::STATE_ACTIVE) {
            // Not activated (no accepted FREE terms recorded by the AQG service): no free scans can start yet.
            $text = $this->translate(
                'pageModuleIndicator.freeHint.activationRequired',
                'Free Remote Preview is not activated for this site yet. A TYPO3 administrator activates it once in the AQG module under Settings › Licence.'
            );
        } elseif ($status === null || ($state === 'FREE_AVAILABLE' && $jobsLimit === 0)) {
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

    /**
     * @param array<string,mixed> $remoteScan
     */
    private function remoteScanCoversPage(array $remoteScan, int $pageUid, int $languageUid): bool
    {
        if ((string)($remoteScan['scan_scope'] ?? '') === 'page' && (int)($remoteScan['page_uid'] ?? 0) !== $pageUid) {
            return false;
        }

        return $this->matchesLanguage((int)($remoteScan['language_uid'] ?? -1), $languageUid);
    }

    private function isRemoteSubmitPendingForPage(string $siteIdentifier, int $pageUid, int $languageUid): bool
    {
        $pending = $this->remoteScanSubmissionTracker?->findPending($siteIdentifier);

        return is_array($pending) && $this->remoteScanCoversPage([
            'scan_scope' => $pending['scope'],
            'page_uid' => $pending['pageUid'],
            'language_uid' => $pending['languageUid'],
        ], $pageUid, $languageUid);
    }

    /**
     * The newer of the page's last single-page scan and the last scan that stored its URL, which can be a site crawl.
     * A failed page proves nothing about the page's findings, so it never replaces a page that was checked.
     *
     * @param array<string,mixed>|null $pageScanPage
     * @param array<string,mixed>|null $urlPage
     * @return array<string,mixed>|null
     */
    private function newestRemotePage(?array $pageScanPage, ?array $urlPage): ?array
    {
        if (!is_array($urlPage)) {
            return $pageScanPage;
        }

        if (!is_array($pageScanPage)) {
            return $urlPage;
        }

        if ((int)($urlPage['is_failed'] ?? 0) === 1) {
            return $pageScanPage;
        }

        return (int)($urlPage['remote_scan_finished_at'] ?? 0) > (int)($pageScanPage['remote_scan_finished_at'] ?? 0)
            ? $urlPage
            : $pageScanPage;
    }

    /**
     * Whether the frontend result the row shows is a preview scan: the page row's scan when there is one, else a
     * single-page scan of this page.
     *
     * @param array<string,mixed>|null $remoteCompletedScan
     * @param array<string,mixed>|null $remotePage
     */
    private function remoteResultIsPagePreview(?array $remoteCompletedScan, ?array $remotePage): bool
    {
        if (is_array($remotePage)) {
            return (int)($remotePage['remote_scan_is_page_preview'] ?? 0) === 1;
        }

        return is_array($remoteCompletedScan)
            && (string)($remoteCompletedScan['scan_scope'] ?? '') === 'page'
            && RemoteScanPairingService::isPagePreview($remoteCompletedScan);
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
    private function resolveRemoteState(?array $remoteCompletedScan, ?array $remotePage, bool $isRunning, string $evidence = ''): string
    {
        if ($isRunning) {
            return 'running';
        }

        if (!$this->hasRemoteScanRun($remoteCompletedScan, $remotePage)) {
            return 'none';
        }

        // A page that could not be loaded proves nothing about its content: it is never "ok".
        if ($this->remoteResultCheckedNoPage($remoteCompletedScan, $remotePage)) {
            return 'warning';
        }

        if ($evidence !== '') {
            return $this->stateForEvidence($evidence, 'error');
        }
        $issuesTotal = $this->getRemoteIssueCount($remoteCompletedScan, $remotePage);
        return $issuesTotal > 0 ? 'error' : 'ok';
    }

    /**
     * STATE-01: the frontend row's evidence. Restricted users count the findings they may read on this page's own result
     * page (paid page row, or the Free Remote Preview result page); a scan whose results are not stored yet is pending,
     * one whose storing failed is failed.
     *
     * @param array<string,mixed>|null $remoteCompletedScan
     * @param array<string,mixed>|null $remotePage
     * @param array<string,mixed>|null $remoteCountPage
     */
    private function remoteEvidence(?array $remoteCompletedScan, ?array $remotePage, ?array $remoteCountPage, bool $isRunning, bool $hasRun): string
    {
        $unrestricted = $this->isRemoteResultUnrestricted();
        $visible = $unrestricted
            ? $this->getRemoteIssueCount($remoteCompletedScan, $remotePage)
            : (int)($this->visibleRemoteIssueTypes($remoteCountPage) ?? 0);
        $scanRow = !is_array($remotePage) && is_array($remoteCompletedScan) ? $remoteCompletedScan : null;
        $notStored = is_array($scanRow) && array_key_exists('persisted_at', $scanRow) && (int)$scanRow['persisted_at'] <= 0;

        return ResultEvidenceState::resolve(
            running: $isRunning,
            hasEvidence: $hasRun,
            visibleOpen: $visible,
            restricted: !$unrestricted,
            pending: $notStored && trim((string)($scanRow['sync_error'] ?? '')) === '',
            failed: $notStored && trim((string)($scanRow['sync_error'] ?? '')) !== '',
        );
    }

    /** STATE-01: the row/panel state of an evidence state ($findingsState for findings: error or warning by severity). */
    private function stateForEvidence(string $evidence, string $findingsState): string
    {
        return match ($evidence) {
            ResultEvidenceState::RUNNING => 'running',
            ResultEvidenceState::UNSCANNED => 'none',
            ResultEvidenceState::FINDINGS => in_array($findingsState, ['error', 'warning'], true) ? $findingsState : 'error',
            ResultEvidenceState::CLEAN => 'ok',
            default => 'limited',
        };
    }

    private function limitedHeadline(string $evidence): string
    {
        return match ($evidence) {
            ResultEvidenceState::PENDING => $this->translate('pageModuleIndicator.headline.pending', 'Results are being saved'),
            ResultEvidenceState::FAILED => $this->translate('pageModuleIndicator.headline.notStored', 'Results could not be saved'),
            ResultEvidenceState::CANCELLED => $this->translate('pageModuleIndicator.headline.cancelled', 'Last scan was cancelled'),
            ResultEvidenceState::INCOMPLETE => $this->translate('pageModuleIndicator.headline.incomplete', 'Not fully checked'),
            default => $this->translate('pageModuleIndicator.headline.restricted', 'No open issues in the results you can access'),
        };
    }

    /**
     * @param array<string,mixed>|null $pageScan
     * @param array<string,mixed>|null $subtreeScan
     * @return array<string,mixed>|null
     */
    private function newestLocalScan(?array $pageScan, ?array $subtreeScan): ?array
    {
        if (!is_array($pageScan)) {
            return $subtreeScan;
        }
        if (!is_array($subtreeScan)) {
            return $pageScan;
        }

        return (int)($subtreeScan['finished_at'] ?? 0) > (int)($pageScan['finished_at'] ?? 0) ? $subtreeScan : $pageScan;
    }

    /** Content findings: only administrators read every source record (LocalFindingSourceAccessService::isUnrestricted()). */
    private function isLocalResultUnrestricted(): bool
    {
        return $this->backendContextService->getBackendUser()?->isAdmin() === true;
    }

    private function isRemoteResultUnrestricted(): bool
    {
        return $this->remoteResultAuthorizationService !== null && $this->remoteResultAuthorizationService->isUnrestrictedUser();
    }

    /**
     * The Free Remote Preview result page of this page in a stored Free scan (owner = this page), null without one.
     *
     * @param array<string,mixed>|null $scan
     * @return array<string,mixed>|null
     */
    private function freeResultPage(?array $scan, int $pageUid): ?array
    {
        $scanUid = is_array($scan) ? (int)($scan['uid'] ?? 0) : 0;
        if ($scanUid <= 0) {
            return null;
        }
        foreach ($this->remoteScanRepository->findPagesForScanPaginated($scanUid, 20, 0) as $page) {
            if (is_array($page) && (int)($page['owner_page_uid'] ?? 0) === $pageUid) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $remoteCompletedScan
     * @param array<string,mixed>|null $remotePage
     */
    private function remoteResultCheckedNoPage(?array $remoteCompletedScan, ?array $remotePage): bool
    {
        if (is_array($remotePage)) {
            return (int)($remotePage['is_failed'] ?? 0) === 1;
        }

        return is_array($remoteCompletedScan)
            && (string)($remoteCompletedScan['scan_scope'] ?? '') === 'page'
            && array_key_exists('pages_scanned', $remoteCompletedScan)
            && RemoteScanCoverage::fromScanRow($remoteCompletedScan)->hasNoLoadedPage();
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

        // STATE-01: one source that is not verified clean keeps the whole panel from saying "No issues found".
        if (in_array('limited', $states, true)) {
            return 'limited';
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
            $parts[] = $this->countLabelFor($warning, 'pageModuleIndicator.metric.warning', '%d warning', '%d warnings');
        }
        if ($info > 0) {
            $parts[] = sprintf($this->translate('pageModuleIndicator.metric.info', '%d info'), $info);
        }
        if ($needsReview > 0) {
            $parts[] = sprintf($this->translate('pageModuleIndicator.metric.needsReview', '%d needs review'), $needsReview);
        }

        return $parts !== [] ? implode(' · ', $parts) : $this->translate('pageModuleIndicator.headline.ok', 'No issues found');
    }

    /**
     * The Content row's open counts as separate icon + word items, so severity is never told by colour alone.
     *
     * @param array{critical:int,warning:int,info:int,needs_review?:int} $counts
     * @return list<array{tone: string, icon: string, label: string}>
     */
    private function buildCountItems(string $state, array $counts): array
    {
        if (!in_array($state, ['error', 'warning'], true)) {
            return [];
        }

        $items = [];
        $critical = (int)($counts['critical'] ?? 0);
        $warning = (int)($counts['warning'] ?? 0);
        $info = (int)($counts['info'] ?? 0);
        $needsReview = (int)($counts['needs_review'] ?? 0);
        if ($critical > 0) {
            $items[] = ['tone' => 'critical', 'icon' => 'critical', 'label' => sprintf($this->translate('pageModuleIndicator.metric.critical', '%d critical'), $critical)];
        }
        if ($warning > 0) {
            $items[] = ['tone' => 'warning', 'icon' => 'warning', 'label' => $this->countLabelFor($warning, 'pageModuleIndicator.metric.warning', '%d warning', '%d warnings')];
        }
        if ($info > 0) {
            $items[] = ['tone' => 'info', 'icon' => 'info', 'label' => sprintf($this->translate('pageModuleIndicator.metric.info', '%d info'), $info)];
        }
        if ($needsReview > 0) {
            $items[] = ['tone' => 'review', 'icon' => 'review', 'label' => sprintf($this->translate('pageModuleIndicator.metric.needsReview', '%d needs review'), $needsReview)];
        }

        return $items;
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

        if ($this->remoteResultCheckedNoPage($remoteCompletedScan, $remotePage)) {
            return $this->translate('pageModuleIndicator.headline.remoteNotLoaded', 'Page could not be loaded');
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
        // Sol post-review R01/R05: for users other than administrators the frontend count is that of a result page whose
        // recorded owner is this page and only of the findings they may read; a URL now carrying this page's slug does
        // not bring an earlier owner's results with it.
        if ($this->remoteResultAuthorizationService === null || !$this->remoteResultAuthorizationService->isUnrestrictedUser()) {
            return $this->visibleRemoteIssueCount($remotePage ?? $this->currentRemoteCountPage);
        }
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

    private function buildBody(string $state, bool $isRemoteScanRunning, bool $isLocalSiteScanRunning = false): string
    {
        if ($state === 'running' && $isLocalSiteScanRunning && !$isRemoteScanRunning) {
            return $this->translate('pageModuleIndicator.body.runningSite', 'A content scan of the whole site is running. This page is included.');
        }

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

        if ($state === 'limited') {
            return $this->translate('pageModuleIndicator.body.limited', 'AQG cannot confirm this page is free of issues: some results are not available to you, not stored yet, or not every check ran. Open the report for details.');
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

        if ($state === 'ok' || $state === 'limited') {
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
        // Page counts are a measure only once a page of several is done. Before that — a single-page scan, a crawl
        // still starting, a content scan — a fixed 0/1 bar would stand still for the whole scan; the bar moves instead.
        $isDeterminate = $pagesTotal > 1 && $pagesScanned > 0;
        $percent = $isDeterminate ? max(5, min(100, (int)round(($pagesScanned / $pagesTotal) * 100))) : 42;
        $modeClass = $isDeterminate ? 'is-determinate' : 'is-indeterminate';

        return [
            'percent' => $percent,
            'modeClass' => $modeClass,
            'determinate' => $isDeterminate,
            'valueText' => $isDeterminate
                ? sprintf($this->translate('pageModuleIndicator.progress.pages', '%1$d of %2$d pages'), $pagesScanned, $pagesTotal)
                : $this->translate('pageModuleIndicator.status.running', 'Scanning'),
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
        bool $isRemoteScanStarting = false,
    ): string {
        if ($state === 'running') {
            if ($isRemoteScanStarting) {
                return $this->translate('pageModuleIndicator.meta.remoteStarting', 'Starting the frontend scan…');
            }

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
            if ($startedAt <= 0 && is_array($remoteActiveScan)) {
                $startedAt = (int)($remoteActiveScan['started_at'] ?? 0);
            }
            if ($startedAt > 0) {
                return sprintf(
                    $this->translate('pageModuleIndicator.meta.startedAt', 'Started %s'),
                    BackendTimeUtility::formatDateTime($startedAt, 'H:i')
                );
            }

            return $this->translate('pageModuleIndicator.meta.running', 'Scan in progress');
        }

        return '';
    }

    private function buildStatusLabel(string $state): string
    {
        return match ($state) {
            'ok' => $this->translate('pageModuleIndicator.status.ok', 'OK'),
            'limited' => $this->translate('pageModuleIndicator.status.limited', 'Not confirmed'),
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

    /**
     * @param array<string,mixed>|null $remotePage
     */
    private function visibleRemoteIssueCount(?array $remotePage): int
    {
        return (int)($this->visibleRemoteIssueTypes($remotePage) ?? 0);
    }

    /**
     * The issue types of a result page the user may read (its scan readable, its owner this page, the page visible), null
     * when the user may not read it at all.
     *
     * @param array<string,mixed>|null $remotePage
     */
    private function visibleRemoteIssueTypes(?array $remotePage): ?int
    {
        if (!is_array($remotePage) || $this->remoteResultAuthorizationService === null || $this->scopeAccessService === null) {
            return null;
        }
        $remotePageUid = (int)($remotePage['uid'] ?? 0);
        $scan = $this->remoteScanRepository->findScanByUid((int)($remotePage['remote_scan'] ?? 0));
        if ($remotePageUid <= 0 || !is_array($scan) || !$this->scopeAccessService->canReadRemoteScan($scan)) {
            return null;
        }
        try {
            $visible = $this->remoteResultAuthorizationService->authorize($scan);
        } catch (RemoteResultsTooLargeException) {
            return null;
        }
        if (!$visible->isResultPageVisible($remotePageUid) || (int)($remotePage['owner_page_uid'] ?? 0) !== $this->currentPageUidForCount) {
            return null;
        }

        return (int)($visible->countsByResultPage()[$remotePageUid]['issueTypes'] ?? 0);
    }

    /** The page whose indicator is being built (visibleRemoteIssueCount() compares the recorded owner with it). */
    private int $currentPageUidForCount = 0;

    /** @var array<string,mixed>|null the result page restricted counts are read from (paid page row or Free result page) */
    private ?array $currentRemoteCountPage = null;
}
