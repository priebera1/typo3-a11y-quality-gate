<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems;

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
     * @return array{key: string, title: string, state: string, tagTone: string, tagIcon: string, tagLabel: string, running: bool, delta: string, meta: string, progress: ?int}
     */
    public function contentCard(array $status, ?array $openTotals): array
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
        $delta = $summary !== [] ? $this->delta((int)($summary['issuesNew'] ?? 0), (int)($summary['issuesResolved'] ?? 0)) : '';

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
                'delta' => $this->t('toolbar.delta.frontendFailed', 'The last frontend scan did not finish.'),
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
        if (array_intersect($states, ['clear', 'finished']) !== []) {
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
        $parts = [
            (int)($status['pageUid'] ?? 0) > 0
                ? $this->t('remote.history.pageScan', 'Page scan')
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
