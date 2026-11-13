<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Service\RemoteOccurrenceTotalsService;
use Priebera\A11yQualityGate\Pro\ViewModel\RemoteScanCoverage;
use TYPO3\CMS\Backend\Routing\UriBuilder;

/**
 * The authoritative result of a finished scan, as its completion notification reports it (2026-10 Sol audit NOT-01,
 * NOT-02): what is open now in the scanned scope, not only the change since the previous scan, which pages were and
 * were not checked, and the report that shows it all.
 *
 * "0 new, 0 resolved" alone read as "no problems" on a page with 32 open issues. The counts here come from the stored
 * results — the same numbers the Overview, the page report, the PDF and the CSV show — and are omitted, never guessed,
 * when they cannot be read.
 */
class ScanResultSummaryService
{
    public function __construct(
        private readonly IssueRepository $issueRepository,
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly RemoteOccurrenceTotalsService $remoteOccurrenceTotalsService,
        private readonly UriBuilder $uriBuilder,
        private readonly RemoteIssueRepository $remoteIssueRepository,
        private readonly RemoteScanPairingService $remoteScanPairingService,
        private readonly ?RemoteResultAuthorizationService $remoteResultAuthorizationService = null,
    ) {
    }

    /**
     * Open Content issues of the scanned page or site in the scanned language, after the scan.
     *
     * @return array{total:int,critical:int,warning:int,info:int,needs_review:int}
     */
    public function contentOpenIssues(string $scope, string $siteIdentifier, int $uid, int $languageUid, string $readablePagesClause = ''): array
    {
        // A site total counts only pages the requesting user may show (ScopeAccessService::readablePagesClause()).
        $counts = $scope === 'site'
            ? $this->issueRepository->countOpenTotalsForSite($siteIdentifier, $languageUid, $readablePagesClause)
            : $this->issueRepository->countOpenBySeverity($uid, $siteIdentifier, $languageUid, '', true);

        $result = [
            'critical' => (int)($counts['critical'] ?? 0),
            'warning' => (int)($counts['warning'] ?? 0),
            'info' => (int)($counts['info'] ?? 0),
            'needs_review' => (int)($counts['needs_review'] ?? 0),
        ];

        return ['total' => array_sum($result)] + $result;
    }

    /**
     * The view that shows a Content scan's result: the page report of a page scan, the Overview of a site scan.
     * Content results are the current state of the content, not a stored snapshot of one scan run, so this is not bound
     * to a scan: it opens what is open now. Only frontend scans are stored per run (frontendResult()).
     */
    public function contentReportUrl(string $scope, string $siteIdentifier, int $uid, int $languageUid): string
    {
        return $scope === 'site'
            ? (string)$this->uriBuilder->buildUriFromRoute('web_a11y', ['id' => $uid, 'site' => $siteIdentifier, 'language' => $languageUid, 'aqgSource' => 'local'])
            : (string)$this->uriBuilder->buildUriFromRoute('web_a11y.pageDetail', ['id' => $uid, 'pageUid' => $uid, 'site' => $siteIdentifier, 'language' => $languageUid]);
    }

    /**
     * Stored occurrence totals and page coverage of a persisted frontend scan, and the report that shows it.
     *
     * @param array<string, mixed> $scan a tx_a11y_remote_scan row
     * @return array<string, mixed>
     */
    public function frontendResult(array $scan): array
    {
        $result = [];
        $scanUid = (int)($scan['uid'] ?? 0);
        // Sol R05: for users other than administrators the notification states what they may read of the scan — its
        // visible findings and result pages — and claims no new/resolved comparison over pages they cannot read.
        $restriction = null;
        if ($this->remoteResultAuthorizationService === null || !$this->remoteResultAuthorizationService->isUnrestrictedUser()) {
            try {
                $restriction = (int)($scan['persisted_at'] ?? 0) > 0 && $this->remoteResultAuthorizationService !== null
                    ? $this->remoteResultAuthorizationService->authorize($scan)
                    : new RemoteResultAuthorization(false, [], [], []);
            } catch (RemoteResultsTooLargeException) {
                $restriction = new RemoteResultAuthorization(false, [], [], []);
            }
        }
        if ((int)($scan['persisted_at'] ?? 0) > 0) {
            $totals = $restriction !== null ? $restriction->totals() : $this->remoteOccurrenceTotalsService->forScan($scan);
            $result['occurrencesTotal'] = $totals->total;
            $result['issueTypesTotal'] = $totals->issueTypes;
            $result['comparison'] = $restriction !== null ? ['available' => false] : $this->comparisonWithPreviousScan($scan);
        }

        if ($restriction !== null) {
            // Sol QA-06: the notification says the results are limited to the user's access; only the stored scan's own
            // "loaded a page" decides between that and "no page checked" (a boolean, no withheld count).
            $result['resultsRestricted'] = true;
            $result['scanLoadedPages'] = (int)($scan['pages_scanned'] ?? 0) > 0 || $restriction->resultPages !== [];
            $visiblePages = count($restriction->resultPages);
            $scan['pages_total'] = $visiblePages;
            $scan['pages_scanned'] = $visiblePages;
            $scan['pages_failed'] = 0;
        }
        $coverage = RemoteScanCoverage::fromScanRow($scan, $scanUid > 0 && $restriction === null ? $this->remoteScanRepository->countPagesForScan($scanUid, true) : 0);
        $result += [
            'pagesExpected' => $coverage->expectedPages,
            'pagesLoaded' => $coverage->loadedPages,
            'pagesFailed' => $coverage->failedPages,
            'pagesNotChecked' => max(0, $coverage->expectedPages - $coverage->loadedPages - $coverage->failedPages),
        ];

        $siteIdentifier = (string)($scan['site_identifier'] ?? '');
        $languageUid = (int)($scan['language_uid'] ?? -1);
        $reportUrl = '';
        if ((string)($scan['scan_scope'] ?? '') === 'page' && $scanUid > 0) {
            $pages = $this->remoteScanRepository->findAllPagesForScan($scanUid);
            $remotePageUid = (int)($pages[0]['uid'] ?? 0);
            if ($restriction !== null && !$restriction->isResultPageVisible($remotePageUid)) {
                $remotePageUid = 0;
            }
            if (count($pages) === 1 && $remotePageUid > 0) {
                $reportUrl = (string)$this->uriBuilder->buildUriFromRoute('web_a11y.remotePageDetail', array_filter([
                    'remotePageUid' => $remotePageUid,
                    'site' => $siteIdentifier,
                    'language' => $languageUid >= 0 ? $languageUid : null,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''));
            }
        }
        if ($reportUrl === '' && $siteIdentifier !== '') {
            $reportUrl = (string)$this->uriBuilder->buildUriFromRoute('web_a11y', array_filter([
                'id' => (int)($scan['page_uid'] ?? 0) ?: null,
                'site' => $siteIdentifier,
                'language' => $languageUid >= 0 ? $languageUid : null,
                // V-05: this notification's own scan, not whatever finished last when the action is clicked. The
                // Overview re-validates it (site, scope, language, Free vs paid) and the site read access as always.
                'remoteScanUid' => $scanUid > 0 ? $scanUid : null,
                // Opens the Overview on its Frontend scan tab (pro-module.js reads `aqgSource`).
                'aqgSource' => 'remote',
            ], static fn (mixed $value): bool => $value !== null && $value !== ''));
        }
        $result['reportUrl'] = $reportUrl;
        // Atlas D9: the notification says it describes a preview scan of a page that was not public.
        $result['pagePreview'] = RemoteScanPairingService::isPagePreview($scan);

        return $result;
    }

    /**
     * What a persisted frontend scan changed against the previous compatible scan, for views other than the scan
     * notification (the toolbar): the same numbers as frontendResult()['comparison'].
     *
     * @param array<string, mixed> $scan a tx_a11y_remote_scan row
     * @return array{available: bool, new?: int, resolved?: int}
     */
    public function frontendComparison(array $scan): array
    {
        return (int)($scan['persisted_at'] ?? 0) > 0 ? $this->comparisonWithPreviousScan($scan) : ['available' => false];
    }

    /**
     * New and resolved issues against the previous compatible stored scan (same site, scope, scan type, language,
     * start URL, Free vs paid — `RemoteScanPairingService`), from the stored fingerprints.
     *
     * The crawler's own `issuesNew` / `issuesResolved` compared a single-page scan with the previous single-page scan
     * of any page of the site (fixed in the crawler with this release): an unchanged page reported "41 new". Reading
     * them from the stored findings keeps the notification right whichever crawler answered. Without a compatible
     * earlier scan nothing is claimed.
     *
     * @param array<string, mixed> $scan
     * @return array{available: bool, new?: int, resolved?: int}
     */
    private function comparisonWithPreviousScan(array $scan): array
    {
        $current = $this->remoteIssueRepository->findFingerprintsForScan((int)($scan['uid'] ?? 0));
        $previousScan = $current !== null ? $this->remoteScanPairingService->findPreviousCompatible($scan) : null;
        $previous = is_array($previousScan) ? $this->remoteIssueRepository->findFingerprintsForScan((int)($previousScan['uid'] ?? 0)) : null;
        if ($current === null || $previous === null) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'new' => count(array_diff_key($current, $previous)),
            'resolved' => count(array_diff_key($previous, $current)),
        ];
    }
}
