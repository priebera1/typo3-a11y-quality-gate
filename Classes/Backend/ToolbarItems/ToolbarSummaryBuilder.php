<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems;

use Priebera\A11yQualityGate\Service\ResultEvidenceState;

/**
 * The toolbar dropdown's Content scan and Frontend scan summaries and the state of the toolbar icon.
 *
 * The headline of a card is what is open now (Content: open issues of the site; Frontend: occurrences of the last
 * scan). What the last scan changed ("0 new and 1 resolved") is secondary and never stands in for the site's state:
 * a scan that found nothing new on a site with open issues is not "clean". Without readable open totals the Content
 * card makes no claim about the site at all.
 */
final class ToolbarSummaryBuilder
{
    private const REMOTE_QUEUED_STATUSES = ['waiting', 'queued'];
    /** A completed scan older than this is shown as "Out of date". */
    public const STALE_AFTER_SECONDS = 14 * 86400;
    private const REMOTE_RUNNING_STATUSES = ['running', 'active', 'processing', 'in_progress', 'in-progress', 'started'];

    /**
     * @param \Closure(string, string): string $translate key + English fallback
     * @param \Closure(int): string $formatDateTime
     * @param \Closure(int): string $formatTime
     */
    public function __construct(
        private readonly \Closure $translate,
        private readonly \Closure $formatDateTime,
        private readonly \Closure $formatTime,
    ) {
    }

    /**
     * @param array<string, mixed> $status local scan status (ScanStatusService / persisted scan row shape)
     * @param array{critical:int,warning:int,info:int,needs_review:int,total:int}|null $openTotals open issues of the
     *        site and language, null when no readable site is known
     * @param array{critical:int,warning:int,info:int,needs_review?:int}|null $pageCounts open issues of the page open in
     *        the backend (the Page module's counts: PageModuleIndicatorService), null without a readable open page
     * @return array{key: string, title: string, state: string, tagTone: string, tagIcon: string, tagLabel: string, running: bool, delta: string, meta: string, progress: ?int}
     */
    public function contentCard(array $status, ?array $openTotals, ?string $evidence = null, ?array $pageCounts = null): array
    {
        $summary = is_array($status['summary'] ?? null) ? $status['summary'] : [];
        $new = array_key_exists('issuesNew', $summary) && $summary['issuesNew'] !== null && empty($summary['countsWithheld'])
            ? max(0, (int)$summary['issuesNew'])
            : null;
        $card = $this->withDisclosure($this->contentCardState($status, $openTotals, $evidence), $new, (int)($status['finishedAt'] ?? 0));
        // The site total and the open page's own counts are different scopes: the page line says which part of the total
        // is on the page the editor sees, by severity class like the Page module ("1 warning · 1 needs review").
        $card['pageLine'] = $openTotals !== null && !in_array($card['state'], ['running', 'failed'], true) ? $this->pageLine($pageCounts) : '';
        $card['scopeNote'] = in_array($card['state'], ['open', 'clear'], true)
            ? $this->t('toolbar.content.scopeNote', 'Saved TYPO3 content. Open counts all pages you can access, in this language.')
            : '';

        return $card;
    }

    /**
     * "This page: 2 open · 1 warning · 1 needs review" for an open page with open Content issues; '' otherwise (a zero is
     * not shown as "no issues": the page may never have been scanned).
     *
     * @param array{critical:int,warning:int,info:int,needs_review?:int}|null $counts
     */
    private function pageLine(?array $counts): string
    {
        if ($counts === null) {
            return '';
        }
        $critical = max(0, (int)($counts['critical'] ?? 0));
        $warning = max(0, (int)($counts['warning'] ?? 0));
        $info = max(0, (int)($counts['info'] ?? 0));
        $needsReview = max(0, (int)($counts['needs_review'] ?? 0));
        $total = $critical + $warning + $info + $needsReview;
        if ($total === 0) {
            return '';
        }
        $parts = [sprintf($this->t('toolbar.content.pageOpen', 'This page: %d open'), $total)];
        // The Page module's own labels (PageModuleIndicatorService::buildHeadline()), so both name the counts alike.
        if ($critical > 0) {
            $parts[] = sprintf($this->t('pageModuleIndicator.metric.critical', '%d critical'), $critical);
        }
        if ($warning > 0) {
            $parts[] = $warning === 1
                ? sprintf($this->t('pageModuleIndicator.metric.warning.singular', '%d warning'), $warning)
                : sprintf($this->t('pageModuleIndicator.metric.warning.plural', '%d warnings'), $warning);
        }
        if ($info > 0) {
            $parts[] = sprintf($this->t('pageModuleIndicator.metric.info', '%d info'), $info);
        }
        if ($needsReview > 0) {
            $parts[] = sprintf($this->t('pageModuleIndicator.metric.needsReview', '%d needs review'), $needsReview);
        }

        return implode(' · ', $parts);
    }

    /**
     * @param array<string, mixed> $status
     * @param array{critical:int,warning:int,info:int,needs_review:int,total:int}|null $openTotals
     * @return array<string, mixed>
     */
    private function contentCardState(array $status, ?array $openTotals, ?string $evidence = null): array
    {
        $card = [
            'key' => 'content',
            'title' => $this->t('toolbar.content.title', 'Content scan'),
            'running' => false,
            'delta' => '',
            'meta' => '',
            'progress' => null,
        ];

        if ((bool)($status['running'] ?? false)) {
            $startedAt = (int)($status['startedAt'] ?? 0);

            return array_replace($card, $this->tag('running', 'info', 'spin', $this->t('toolbar.tag.scanning', 'Scanning'), true), [
                'meta' => $startedAt > 0 ? sprintf($this->t('toolbar.meta.started', 'Started %s'), ($this->formatTime)($startedAt)) : '',
            ]);
        }

        $summary = is_array($status['summary'] ?? null) ? $status['summary'] : [];
        $finishedAt = (int)($status['finishedAt'] ?? 0);
        $meta = $this->contentMeta($status, $summary, $finishedAt);
        $delta = $summary !== [] ? $this->contentDelta($summary) : '';

        if (!empty($status['error'])) {
            return array_replace($card, $this->tag('failed', 'error', 'x', $this->t('toolbar.tag.failed', 'Failed')), [
                'delta' => $this->t('toolbar.delta.contentFailed', 'The last content scan did not finish.'),
                'meta' => $meta,
            ]);
        }

        $openTotal = $openTotals !== null ? max(0, (int)($openTotals['total'] ?? 0)) : null;
        if ($finishedAt <= 0 && $summary === [] && !$openTotal) {
            return array_replace($card, $this->tag('none', 'none', 'clock', $this->t('toolbar.tag.notScanned', 'Not scanned yet')), [
                'delta' => $this->t('toolbar.delta.notScanned', 'Run a scan in the Accessibility module.'),
            ]);
        }

        if ($openTotal === null) {
            return array_replace($card, $this->tag('finished', 'none', 'ok', $this->t('toolbar.tag.finished', 'Scan finished')), [
                'delta' => $delta,
                'meta' => $meta,
            ]);
        }

        if ($openTotal > 0) {
            $isCritical = (int)($openTotals['critical'] ?? 0) > 0;

            return array_replace($card, $this->tag(
                'open',
                $isCritical ? 'critical' : 'warning',
                $isCritical ? 'crit' : 'warn',
                sprintf($this->t('toolbar.tag.open', '%d open'), $openTotal)
            ), [
                'delta' => $delta,
                'meta' => $meta,
                'openTotal' => $openTotal,
            ]);
        }

        // STATE-01: a zero of the readable findings is "No open issues" only as a verified result (ResultEvidenceState).
        if ($evidence === ResultEvidenceState::INCOMPLETE) {
            return array_replace($card, $this->tag('incomplete', 'info', 'warn', $this->t('toolbar.tag.notFullyChecked', 'Not fully checked')), [
                'delta' => $this->t('toolbar.delta.contentIncomplete', 'No open issues found, but the last scan did not check the whole site.'),
                'meta' => $meta,
            ]);
        }
        if ($evidence !== null && $evidence !== ResultEvidenceState::CLEAN) {
            return array_replace($card, $this->tag('restricted', 'info', 'eye', $this->t('toolbar.tag.restricted', 'Limited to your access')), [
                'delta' => $this->t('toolbar.delta.contentRestricted', 'No open issues in the pages and records you can access.'),
                'meta' => $meta,
            ]);
        }

        return array_replace($card, $this->tag('clear', 'ok', 'ok', $this->t('toolbar.tag.noOpen', 'No open issues')), [
            'delta' => $delta,
            'meta' => $meta,
        ]);
    }

    /**
     * What the last frontend scan changed comes from its stored findings against the previous compatible scan (the
     * scan notification's numbers), never from the crawler's own `issues_new` / `issues_resolved`: those count against
     * the crawler's history, so a re-run of an unchanged site reported every occurrence as new. Without such a
     * comparison the card makes no claim about what changed.
     *
     * @param array<string, mixed> $scan a tx_a11y_remote_scan row, [] when none is stored
     * @param array{available: bool, new?: int, resolved?: int}|null $comparison ScanResultSummaryService::frontendComparison()
     * @return array<string, mixed>
     */
    public function frontendCard(array $scan, ?array $comparison = null): array
    {
        $new = ($comparison['available'] ?? false) === true ? max(0, (int)($comparison['new'] ?? 0)) : null;
        $card = $this->withDisclosure($this->frontendCardState($scan, $comparison), $new, (int)($scan['finished_at'] ?? 0));
        $card['scopeNote'] = in_array($card['state'], ['open', 'clear', 'incomplete'], true)
            ? $this->t('toolbar.frontend.scopeNote', 'Live pages as visitors see them, counted per affected element.')
            : '';

        return $card;
    }

    /**
     * The Frontend section for an installation or user without frontend scan results here (no crawler access or no
     * readable site): collapsed, neutral, and pointing to the Accessibility module instead of claiming a result.
     *
     * @return array<string, mixed>
     */
    public function frontendUnavailableCard(): array
    {
        return $this->withDisclosure([
            'key' => 'frontend',
            'title' => $this->t('toolbar.frontend.title', 'Frontend scan'),
            'state' => 'unavailable',
            'tagTone' => 'none',
            'tagIcon' => 'clock',
            'tagLabel' => $this->t('toolbar.status.unavailable', 'Not available here'),
            'running' => false,
            'delta' => $this->t('toolbar.delta.frontendUnavailable', 'Frontend scan results of this site are not available here. The Accessibility module shows the options for this installation.'),
            'meta' => '',
            'progress' => null,
        ], null, 0);
    }

    /**
     * @param array<string, mixed> $scan
     * @param array{available: bool, new?: int, resolved?: int}|null $comparison
     * @return array<string, mixed>
     */
    private function frontendCardState(array $scan, ?array $comparison = null): array
    {
        $card = [
            'key' => 'frontend',
            'title' => $this->t('toolbar.frontend.title', 'Frontend scan'),
            'running' => false,
            'delta' => '',
            'meta' => '',
            'progress' => null,
        ];
        $status = strtolower(trim((string)($scan['status'] ?? '')));
        $pagesScanned = max(0, (int)($scan['pages_scanned'] ?? 0));
        $pagesTotal = max(0, (int)($scan['pages_total'] ?? 0));

        if (in_array($status, self::REMOTE_QUEUED_STATUSES, true) || in_array($status, self::REMOTE_RUNNING_STATUSES, true)) {
            $queued = in_array($status, self::REMOTE_QUEUED_STATUSES, true);
            $startedAt = (int)($scan['started_at'] ?? 0);
            $parts = [];
            if ($startedAt > 0) {
                $parts[] = sprintf($this->t('toolbar.meta.started', 'Started %s'), ($this->formatTime)($startedAt));
            }
            if ($pagesTotal > 0) {
                $parts[] = $this->pagesOf($pagesScanned, $pagesTotal);
            }

            return array_replace($card, $this->tag(
                $queued ? 'queued' : 'running',
                'info',
                $queued ? 'clock' : 'spin',
                $queued ? $this->t('toolbar.tag.queued', 'Queued') : $this->t('toolbar.tag.scanning', 'Scanning'),
                true
            ), [
                'meta' => implode(' · ', $parts),
                'progress' => !$queued && $pagesTotal > 0 ? (int)min(100, max(1, round($pagesScanned / $pagesTotal * 100))) : null,
            ]);
        }

        if ((int)($scan['uid'] ?? 0) <= 0) {
            return array_replace($card, $this->tag('none', 'none', 'clock', $this->t('toolbar.tag.notScanned', 'Not scanned yet')), [
                'delta' => $this->t('toolbar.delta.notScanned', 'Run a scan in the Accessibility module.'),
            ]);
        }

        $meta = $this->frontendMeta($scan, $pagesScanned, $pagesTotal);
        if ($status === 'failed' || trim((string)($scan['sync_error'] ?? '')) !== '') {
            return array_replace($card, $this->tag('failed', 'error', 'x', $this->t('toolbar.tag.failed', 'Failed')), [
                // F11 (acceptance §25 D3): a crawl that finished but whose results could not be stored did finish.
                'delta' => $status === 'failed'
                    ? $this->t('toolbar.delta.frontendFailed', 'The last frontend scan did not finish.')
                    : $this->t('toolbar.delta.frontendNotStored', 'The last frontend scan finished, but its results could not be saved.'),
                'meta' => $meta,
            ]);
        }

        // STATE-01: the crawler finished, but its results are not stored yet (RemoteScanRecoveryService writes the
        // completion first): nothing is known about the findings until they are.
        if ($status === 'completed' && array_key_exists('persisted_at', $scan) && (int)$scan['persisted_at'] <= 0) {
            return array_replace($card, $this->tag('pending', 'info', 'clock', $this->t('toolbar.tag.pending', 'Saving results')), [
                'delta' => $this->t('toolbar.delta.pending', 'The results of the last frontend scan are being saved.'),
                'meta' => $meta,
            ]);
        }

        $restricted = (bool)($scan['aqg_results_restricted'] ?? false);
        if ($restricted && $status === 'completed' && (bool)($scan['aqg_scan_loaded_pages'] ?? false)) {
            $occurrences = max(0, (int)($scan['issues_total'] ?? 0));
            $restrictedDelta = $this->t('toolbar.delta.restricted', 'Only results your permissions allow are shown.');
            if ($occurrences > 0) {
                return array_replace($card, $this->tag(
                    'open',
                    'warning',
                    'warn',
                    $occurrences === 1
                        ? $this->t('toolbar.tag.occurrence', '1 occurrence')
                        : sprintf($this->t('toolbar.tag.occurrences', '%d occurrences'), $occurrences)
                ), ['delta' => $restrictedDelta, 'meta' => $meta, 'openTotal' => $occurrences]);
            }

            return array_replace($card, $this->tag('restricted', 'info', 'eye', $this->t('toolbar.tag.restricted', 'Limited to your access')), [
                'delta' => $this->t('toolbar.delta.restrictedNone', 'No results of this scan are available to you.'),
                'meta' => $meta,
            ]);
        }

        if ($pagesScanned === 0) {
            return array_replace($card, $this->tag('no-coverage', 'error', 'x', $this->t('toolbar.tag.noPageChecked', 'No page checked')), [
                'delta' => $this->t('toolbar.delta.noCoverage', 'The last frontend scan could not load any page.'),
                'meta' => $meta,
            ]);
        }

        $occurrences = max(0, (int)($scan['issues_total'] ?? 0));
        $delta = ($comparison['available'] ?? false) === true
            ? $this->delta((int)($comparison['new'] ?? 0), (int)($comparison['resolved'] ?? 0))
            : '';
        $complete = max(0, (int)($scan['pages_failed'] ?? 0)) === 0 && ($pagesTotal === 0 || $pagesScanned >= $pagesTotal);

        if ($occurrences > 0) {
            return array_replace($card, $this->tag(
                'open',
                'warning',
                'warn',
                $occurrences === 1
                    ? $this->t('toolbar.tag.occurrence', '1 occurrence')
                    : sprintf($this->t('toolbar.tag.occurrences', '%d occurrences'), $occurrences)
            ), ['delta' => $delta, 'meta' => $meta, 'openTotal' => $occurrences]);
        }

        return array_replace($card, $complete
            ? $this->tag('clear', 'ok', 'ok', $this->t('toolbar.tag.noIssues', 'No issues found'))
            : $this->tag('incomplete', 'warning', 'warn', $this->t('toolbar.tag.incomplete', 'Incomplete')), [
            'delta' => $complete ? $delta : $this->t('toolbar.delta.incomplete', 'Some pages could not be checked.'),
            'meta' => $meta,
        ]);
    }

    /**
     * The collapsed header of a section: a short summary ("2 new · 300 open") and a textual status with a neutral,
     * informational or problem tone. A completed scan that found issues is "Complete", not an error: issues are
     * counted in the summary, the tone is reserved for the scan itself (running, failed, out of date).
     *
     * @param array<string, mixed> $card
     * @return array<string, mixed>
     */
    private function withDisclosure(array $card, ?int $new, int $finishedAt): array
    {
        $state = (string)($card['state'] ?? '');
        $open = (int)($card['openTotal'] ?? 0);
        $stale = $finishedAt > 0 && $finishedAt < time() - self::STALE_AFTER_SECONDS
            && in_array($state, ['open', 'clear', 'finished', 'incomplete'], true);
        [$statusLabel, $statusTone] = match (true) {
            $state === 'running' => [$this->t('toolbar.status.running', 'Running'), 'info'],
            $state === 'queued' => [$this->t('toolbar.tag.queued', 'Queued'), 'info'],
            $state === 'failed' => [$this->t('toolbar.tag.failed', 'Failed'), 'error'],
            $state === 'no-coverage' => [$this->t('toolbar.tag.noPageChecked', 'No page checked'), 'error'],
            $state === 'none' => [$this->t('toolbar.status.notScanned', 'Not scanned'), 'none'],
            $state === 'unavailable' => [$this->t('toolbar.status.unavailable', 'Not available here'), 'none'],
            // Sol QA-06: a scan whose results this user may not read is neither "Complete" nor "No issues found".
            $state === 'restricted' => [$this->t('toolbar.tag.restricted', 'Limited to your access'), 'info'],
            $state === 'pending' => [$this->t('toolbar.tag.pending', 'Saving results'), 'info'],
            $stale => [$this->t('toolbar.status.stale', 'Out of date'), 'warning'],
            $state === 'incomplete' => [$this->t('toolbar.tag.incomplete', 'Incomplete'), 'warning'],
            default => [$this->t('toolbar.status.complete', 'Complete'), 'ok'],
        };

        // What is open comes first and names its scope (Content: the website; Frontend: the last scan's occurrences);
        // "new" belongs to the last scan, which may have checked a single page, so it follows and only when it is not 0
        // ("0 new · 14 open" read as if the 14 were on the open page and nothing had changed there).
        $parts = [];
        if (in_array($state, ['running', 'queued'], true)) {
            $parts[] = (string)($card['meta'] ?? '');
        } elseif (in_array($state, ['open', 'clear', 'incomplete', 'finished'], true)) {
            if ($card['key'] === 'content' && $state !== 'finished') {
                $parts[] = sprintf($this->t('toolbar.summary.openSite', '%d open on the website'), $open);
            } elseif ($card['key'] === 'frontend') {
                $parts[] = $open === 1
                    ? $this->t('toolbar.tag.occurrence', '1 occurrence')
                    : sprintf($this->t('toolbar.tag.occurrences', '%d occurrences'), $open);
            }
            if ($new !== null && $new > 0) {
                $parts[] = sprintf($this->t('toolbar.summary.new', '%d new'), $new);
            }
        }

        return $card + [
            'summary' => implode(' · ', array_filter($parts, static fn (string $part): bool => $part !== '')),
            'statusLabel' => $statusLabel,
            'statusTone' => $statusTone,
            'stale' => $stale,
            'staleNote' => $stale
                ? sprintf($this->t('toolbar.staleNote', 'Older than %d days. Later changes are not included.'), intdiv(self::STALE_AFTER_SECONDS, 86400))
                : '',
        ];
    }

    /**
     * The toolbar icon: running and failed first, then open Content issues, then Frontend occurrences.
     *
     * @param array<string, mixed> $contentCard
     * @param array<string, mixed>|null $frontendCard null when the Frontend section is not shown
     * @return array{state: string, tone: string, count: int, running: bool, ariaLabel: string}
     */
    public function toolbarState(array $contentCard, ?array $frontendCard): array
    {
        $cards = array_values(array_filter([$contentCard, $frontendCard], 'is_array'));
        $states = array_map(static fn (array $card): string => (string)($card['state'] ?? ''), $cards);

        if (array_intersect($states, ['running', 'queued']) !== []) {
            return $this->iconState('running', 'info', 0, $this->t('toolbar.aria.running', 'Accessibility scan is running'), true);
        }
        if (array_intersect($states, ['failed', 'no-coverage']) !== []) {
            return $this->iconState('failed', 'error', 0, $this->t('toolbar.aria.failed', 'Accessibility scan failed'));
        }

        $contentOpen = ($contentCard['state'] ?? '') === 'open' ? (int)($contentCard['openTotal'] ?? 0) : 0;
        if ($contentOpen > 0) {
            return $this->iconState(
                'issues',
                (string)$contentCard['tagTone'],
                $contentOpen,
                sprintf(
                    $contentOpen === 1
                        ? $this->t('toolbar.aria.open.singular', 'Accessibility: %d open content issue')
                        : $this->t('toolbar.aria.open.plural', 'Accessibility: %d open content issues'),
                    $contentOpen
                )
            );
        }
        if (is_array($frontendCard) && in_array($frontendCard['state'] ?? '', ['open', 'incomplete'], true)) {
            return $this->iconState('issues', 'warning', 0, $this->t('toolbar.aria.frontendOpen', 'Accessibility: frontend issues to review'));
        }
        // STATE-01: the green icon only when no shown card is short of a verified result; a restricted, incomplete,
        // pending or unconfirmed card ("Scan finished" without a count) keeps it neutral.
        if (array_intersect($states, ['restricted', 'incomplete', 'pending', 'finished']) !== []) {
            return $this->iconState('limited', 'none', 0, $this->t('toolbar.aria.limited', 'Accessibility: no open issues in the results you can see'));
        }
        if (in_array('clear', $states, true)) {
            return $this->iconState('ok', 'ok', 0, $this->t('toolbar.aria.noOpen', 'Accessibility: no open issues'));
        }

        return $this->iconState('not-scanned', 'none', 0, $this->t('toolbar.aria.notScanned', 'Accessibility: not scanned yet'));
    }

    /**
     * @param array<string, mixed> $status
     * @param array<string, mixed> $summary
     */
    private function contentMeta(array $status, array $summary, int $finishedAt): string
    {
        $pageTitle = trim((string)($status['pageTitle'] ?? ''));
        $parts = [
            (int)($status['pageUid'] ?? 0) > 0
                ? ($pageTitle !== ''
                    ? sprintf($this->t('toolbar.meta.pageScanOf', 'Page scan: “%s”'), $pageTitle)
                    : $this->t('remote.history.pageScan', 'Page scan'))
                : $this->t('remote.history.siteScan', 'Site scan'),
        ];
        if ($finishedAt > 0) {
            $parts[] = ($this->formatDateTime)($finishedAt);
        }
        if (isset($summary['pagesScanned'])) {
            $pages = (int)$summary['pagesScanned'];
            $parts[] = $pages === 1
                ? $this->t('toolbar.meta.page', '1 page')
                : sprintf($this->t('toolbar.meta.pages', '%d pages'), $pages);
        }

        return implode(' · ', $parts);
    }

    /**
     * @param array<string, mixed> $scan
     */
    private function frontendMeta(array $scan, int $pagesScanned, int $pagesTotal): string
    {
        $parts = [
            (string)($scan['scan_scope'] ?? '') === 'page'
                ? $this->t('remote.history.pageScan', 'Page scan')
                : $this->t('remote.history.siteScan', 'Site scan'),
        ];
        $finishedAt = (int)($scan['finished_at'] ?? 0);
        if ($finishedAt > 0) {
            $parts[] = ($this->formatDateTime)($finishedAt);
        }
        if ($pagesTotal > 0) {
            $parts[] = $this->pagesOf($pagesScanned, $pagesTotal);
        }

        return implode(' · ', $parts);
    }

    private function pagesOf(int $scanned, int $total): string
    {
        return $total === 1
            ? sprintf($this->t('toolbar.meta.pagesOfOne', '%d of 1 page'), $scanned)
            : sprintf($this->t('toolbar.meta.pagesOf', '%1$d of %2$d pages'), $scanned, $total);
    }

    /**
     * A summary key set to null makes no claim (A11yScanToolbarItem leaves out what spans pages the user may not
     * show): without "resolved" only the new count is reported, without "new" nothing.
     *
     * @param array<string, mixed> $summary
     */
    private function contentDelta(array $summary): string
    {
        $new = array_key_exists('issuesNew', $summary) ? $summary['issuesNew'] : 0;
        $resolved = array_key_exists('issuesResolved', $summary) ? $summary['issuesResolved'] : 0;
        if ($new === null) {
            return '';
        }
        if ($resolved === null) {
            return sprintf($this->t('toolbar.delta.lastScanNew', '%d new in the last scan'), max(0, (int)$new));
        }

        return $this->delta((int)$new, (int)$resolved);
    }

    private function delta(int $new, int $resolved): string
    {
        return sprintf($this->t('toolbar.delta.lastScan', '%1$d new and %2$d resolved in the last scan'), max(0, $new), max(0, $resolved));
    }

    /**
     * @return array{state: string, tagTone: string, tagIcon: string, tagLabel: string, running: bool}
     */
    private function tag(string $state, string $tone, string $icon, string $label, bool $running = false): array
    {
        return ['state' => $state, 'tagTone' => $tone, 'tagIcon' => $icon, 'tagLabel' => $label, 'running' => $running];
    }

    /**
     * @return array{state: string, tone: string, count: int, running: bool, ariaLabel: string}
     */
    private function iconState(string $state, string $tone, int $count, string $ariaLabel, bool $running = false): array
    {
        return ['state' => $state, 'tone' => $tone, 'count' => $count, 'running' => $running, 'ariaLabel' => $ariaLabel];
    }

    private function t(string $key, string $fallback): string
    {
        return ($this->translate)($key, $fallback);
    }
}
