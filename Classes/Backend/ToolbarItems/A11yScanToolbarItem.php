<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Priebera\A11yQualityGate\Service\ResultEvidenceState;
use Priebera\A11yQualityGate\Service\RemoteResultAuthorization;
use Priebera\A11yQualityGate\Service\RemoteResultAuthorizationService;
use Priebera\A11yQualityGate\Service\RemoteResultsTooLargeException;
use Priebera\A11yQualityGate\Backend\ToolbarItems\ConnectedAgents\ConnectedAgentsLinkResolver;
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
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;

final class A11yScanToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    /** F11-04: newest-first candidates per kind that are checked for read access before the Frontend card gives up. */
    private const REMOTE_SCAN_CANDIDATES = 20;

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
        private readonly ?RemoteResultAuthorizationService $remoteResultAuthorizationService = null,
        private readonly ?ConnectedAgentsLinkResolver $connectedAgentsLinkResolver = null,
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
            // Collapsed and neutral when this user or installation has no frontend results here.
            'frontendCard' => $summary['frontend'] ?? $this->summaryBuilder()->frontendUnavailableCard(),
            'siteLabel' => $summary['siteLabel'],
            'moduleUrl' => (string)$this->uriBuilder->buildUriFromRoute('web_a11y'),
            'agentsUrl' => $this->connectedAgentsLinkResolver?->resolve($this->backendContextService->getBackendUser()) ?? '',
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
        // Every Content count is page-level data: pages the user may not show (TYPO3 page permissions) add nothing.
        $readablePagesClause = $this->scopeAccessService->readablePagesClause();
        $localStatus = $this->withReadableLastScanDelta($localStatus, $canReadSite ? $siteIdentifier : '', $readablePagesClause);

        $contentCard = $builder->contentCard(
            $withFrontendChanges ? $this->withScannedPageTitle($localStatus) : $localStatus,
            $canReadSite ? $this->countOpenTotals($siteIdentifier, $readablePagesClause) : null,
            $canReadSite ? $this->contentEvidence($siteIdentifier) : null,
            $withFrontendChanges && $canReadSite ? $this->countOpenPage($siteIdentifier) : null,
        );
        $frontendCard = null;
        if ($canReadSite && $this->shouldShowRemoteSection($siteIdentifier)) {
            // Sol R03/R05: a scan the user may not read is not shown (resolveRemoteStatus() checks every candidate); for
            // users other than administrators the counts are those of the findings they may read, and no new/resolved
            // comparison is claimed.
            $remoteScan = $this->resolveRemoteStatus($siteIdentifier);
            $restricted = $this->remoteResultAuthorizationService === null || !$this->remoteResultAuthorizationService->isUnrestrictedUser();
            $remoteScan = $restricted ? $this->withVisibleOccurrences($remoteScan) : $this->withStoredOccurrences($remoteScan);
            $frontendCard = $builder->frontendCard(
                $remoteScan,
                $withFrontendChanges && !$restricted ? $this->frontendChanges($remoteScan) : null
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
     * STATE-01: what a zero of the readable open Content findings proves (ResultEvidenceState): clean only for a user who
     * sees every result (administrator, in a language they may use) after a content scan of the whole site in that
     * language that left nothing out (ScanRepository::coversWholeSite()). Same language as countOpenTotals().
     */
    private function contentEvidence(string $siteIdentifier): string
    {
        $languageUid = max(0, $this->requestParameterService->getLanguageUid($this->request));
        $backendUser = $this->backendContextService->getBackendUser();
        $restricted = !($backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin())
            || !$this->scopeAccessService->canUseScanLanguage($languageUid);
        try {
            $site = $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier);
            $siteScan = $this->scanRepository->findLastCompletedSubtreeScan($siteIdentifier, $languageUid);
        } catch (\Throwable) {
            return ResultEvidenceState::INCOMPLETE;
        }

        return ResultEvidenceState::resolve(
            running: false,
            hasEvidence: true,
            visibleOpen: 0,
            restricted: $restricted,
            complete: ScanRepository::coversWholeSite($siteScan, $site !== null ? (int)$site->getRootPageId() : 0),
        );
    }

    /**
     * @return array{critical:int,warning:int,info:int,needs_review:int,total:int}|null
     */
    private function countOpenTotals(string $siteIdentifier, string $readablePagesClause): ?array
    {
        $languageUid = $this->requestParameterService->getLanguageUid($this->request);

        try {
            return $this->issueRepository->countOpenTotalsForSite($siteIdentifier, max(0, $languageUid), $readablePagesClause);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The open Content issues of the page open in the backend (the toolbar request's `id`), counted like the Page
     * module's panel (PageModuleIndicatorService: same repository call, readable sources only), so the dropdown can say
     * which part of the website total is on this page. Null without such a page, for a page of another site or one the
     * user may not read in this language.
     *
     * @return array{critical:int,warning:int,info:int,needs_review:int}|null
     */
    private function countOpenPage(string $siteIdentifier): ?array
    {
        $pageUid = (int)($this->requestParameterService->getPageUid($this->request) ?? 0);
        $languageUid = max(0, $this->requestParameterService->getLanguageUid($this->request));
        if ($pageUid <= 0
            || $this->siteResolutionService->resolveSiteIdentifierForPageId($pageUid) !== $siteIdentifier
            || !$this->scopeAccessService->canReadPageResultsInLanguage($pageUid, $languageUid)) {
            return null;
        }
        try {
            return $this->issueRepository->countOpenBySeverity($pageUid, $siteIdentifier, $languageUid, '', true);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The title of the page a finished page scan checked ("Page scan: “Resources”"), for a page the user may show; the
     * status passed restrictLocalScanStatus() already.
     *
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function withScannedPageTitle(array $status): array
    {
        $pageUid = (int)($status['pageUid'] ?? 0);
        if ($pageUid <= 0 || (bool)($status['running'] ?? false) || !$this->scopeAccessService->canReadPage($pageUid)) {
            return $status;
        }
        $page = BackendUtility::getRecord('pages', $pageUid, 'title');
        $title = is_array($page) ? trim((string)($page['title'] ?? '')) : '';
        if ($title !== '') {
            $status['pageTitle'] = mb_substr($title, 0, 80);
        }

        return $status;
    }

    /**
     * The scan row's new/resolved counts cover every page the scan processed. For a user restricted by page
     * permissions (non-empty clause) "new" is recounted from the stored findings of readable pages (the Overview's
     * "new" count); "resolved" findings keep no page-level trace there, so it is left out instead of reporting the
     * scan's total. Administrators (empty clause) keep the scan's own counts.
     *
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function withReadableLastScanDelta(array $status, string $siteIdentifier, string $readablePagesClause): array
    {
        if ($readablePagesClause === '' || !is_array($status['summary'] ?? null)) {
            return $status;
        }

        $summary = $status['summary'];
        $summary['issuesNew'] = null;
        $summary['issuesResolved'] = null;
        $scanUid = $this->extractLocalStatusScanUid($status);
        if ($siteIdentifier !== '' && $scanUid > 0) {
            $languageUid = $this->requestParameterService->getLanguageUid($this->request);
            try {
                $summary['issuesNew'] = $this->issueRepository->countNewOpenTotalsForScan(
                    $siteIdentifier,
                    $scanUid,
                    max(0, $languageUid),
                    $readablePagesClause
                )['total'];
            } catch (\Throwable) {
                $summary['issuesNew'] = null;
            }
        }
        $status['summary'] = $summary;

        return $status;
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

    /**
     * N-05: the Frontend card belongs to the site the toolbar shows; its licence answers whether to show it. Asking
     * "any site with the crawler?" validated the sites in alphabetical order on every uncached backend render.
     */
    private function shouldShowRemoteSection(string $siteIdentifier): bool
    {
        if ($siteIdentifier === '') {
            return false;
        }
        $status = $this->proStatusResolverService->resolveForSiteIdentifier($siteIdentifier);

        return (bool)($status->valid ?? false) && (bool)($status->hasCrawler ?? false);
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

        // AUTH-02: a status rebuilt from a stored scan row names its page or root, language and counters too; it passes
        // the same page/language/scope rule as the registry status.
        return $this->languageAwareLocalStatus(
            $this->scopeAccessService->restrictLocalScanStatus($this->mergeWithPersistedLocalScan($status))
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
        // Review F10-1: the registry's trigger and actor describe the registry's scan only, never another stored row (a
        // Scheduler/CLI scan writes no registry entry): who started a row decides which counters a viewer sees.
        $sameScan = (int)($fallbackStatus['scanUid'] ?? 0) > 0 && (int)($fallbackStatus['scanUid'] ?? 0) === (int)($scanRow['uid'] ?? 0);
        $fallbackStatus = $sameScan ? $fallbackStatus : [];

        return [
            'running' => true,
            'startedAt' => (int)($scanRow['started_at'] ?? 0) ?: null,
            'finishedAt' => null,
            'trigger' => $fallbackStatus['trigger'] ?? 'persisted-scan',
            'triggeredBy' => $fallbackStatus['triggeredBy'] ?? '',
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
        // Review F10-1: the registry's trigger and actor describe the registry's scan only, never another stored row (a
        // Scheduler/CLI scan writes no registry entry): who started a row decides which counters a viewer sees.
        $sameScan = (int)($fallbackStatus['scanUid'] ?? 0) > 0 && (int)($fallbackStatus['scanUid'] ?? 0) === (int)($scanRow['uid'] ?? 0);
        $fallbackStatus = $sameScan ? $fallbackStatus : [];

        return [
            'running' => false,
            'startedAt' => (int)($scanRow['started_at'] ?? 0) ?: null,
            'finishedAt' => (int)($scanRow['finished_at'] ?? 0),
            'trigger' => $fallbackStatus['trigger'] ?? 'persisted-scan',
            'triggeredBy' => $fallbackStatus['triggeredBy'] ?? '',
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
     * F11-04: each candidate is checked with the user's read access (ScopeAccessService::canReadRemoteScan()) before it is
     * chosen, so a newer scan of a page the user may not read neither blanks the card ("Not scanned yet") nor hides an
     * older readable result; an unreadable scan is skipped without trace (no date, count or recovery). Page scans and
     * active scans are taken from bounded, newest-first windows of the same site and language.
     *
     * @return array<string, mixed>
     */
    private function resolveRemoteStatus(string $siteIdentifier): array
    {
        $languageUid = $this->requestParameterService->getLanguageUid($this->request);
        $siteBase = $this->siteResolutionService->resolveSiteBaseByIdentifier($siteIdentifier);
        $readable = fn (?array $scan): bool => is_array($scan) && $scan !== [] && $this->scopeAccessService->canReadRemoteScan($scan);
        $recovered = fn (array $scan): array => $siteBase !== ''
            ? ($this->remoteScanRecoveryService->recoverScanIfNeeded($scan, $siteBase) ?? $scan)
            : $scan;

        $activeSiteScan = $this->remoteScanRepository->findLatestActiveSiteScanBySite($siteIdentifier, $languageUid);
        if ($readable($activeSiteScan)) {
            return $recovered($activeSiteScan);
        }

        $lastSiteScan = $this->remoteScanRepository->findLastCompletedSiteScanBySite($siteIdentifier, $languageUid, false);
        if ($readable($lastSiteScan)) {
            return $lastSiteScan;
        }

        foreach ($this->remoteScanRepository->findRecentActiveScansBySite($siteIdentifier, $languageUid, self::REMOTE_SCAN_CANDIDATES) as $activeAnyScan) {
            if ($readable($activeAnyScan)) {
                return $recovered($activeAnyScan);
            }
        }

        foreach ($this->remoteScanRepository->findRecentCompletedPageScansBySite($siteIdentifier, $languageUid, false, self::REMOTE_SCAN_CANDIDATES) as $lastPageScan) {
            if ($readable($lastPageScan)) {
                return $lastPageScan;
            }
        }

        return [];
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

    /**
     * @param array<string, mixed> $scan
     * @return array<string, mixed>
     */
    private function withVisibleOccurrences(array $scan): array
    {
        if ($scan === []) {
            return $scan;
        }
        $scan['issues_new'] = 0;
        $scan['issues_resolved'] = 0;
        // Sol QA-06: a restricted card says "limited to your access", never "no page checked" or "no issues", unless the
        // stored scan itself loaded no page (its own counter; it reveals nothing about withheld results).
        $scan['aqg_results_restricted'] = true;
        $scan['aqg_scan_loaded_pages'] = (int)($scan['pages_scanned'] ?? 0) > 0;
        if ((string)($scan['scan_scope'] ?? '') !== 'page') {
            // A site crawl's own page counts (also while it runs) count pages the user may be refused.
            $scan['pages_scanned'] = 0;
            $scan['pages_total'] = 0;
            $scan['pages_failed'] = 0;
        }
        if ((string)($scan['status'] ?? '') !== 'completed' || (int)($scan['persisted_at'] ?? 0) <= 0 || $this->remoteResultAuthorizationService === null) {
            $scan['issues_total'] = 0;
            return $scan;
        }
        try {
            $visible = $this->remoteResultAuthorizationService->authorize($scan);
        } catch (RemoteResultsTooLargeException) {
            $visible = new RemoteResultAuthorization(false, [], [], []);
        }
        $scan['issues_total'] = $visible->totals()->total;
        $scan['pages_total'] = count($visible->resultPages);
        $scan['pages_scanned'] = count($visible->resultPages);

        return $scan;
    }

    private function resolveSiteIdentifierFromRequest(): string
    {
        return $this->siteResolutionService->resolveSiteIdentifierForBackendRequest($this->request);
    }
}
