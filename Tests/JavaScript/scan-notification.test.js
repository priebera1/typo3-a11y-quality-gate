import {describe, expect, it, vi} from 'vitest';
import {
    contentScanNotification,
    frontendScanNotification,
    moduleUrlWithTarget,
    pageScanNotification,
    renderedCoverageOf,
    scanCancelledNotification,
    showScanNotification,
} from '../../Resources/Public/JavaScript/backend/core/scan-notification.js';

// One notification per scan, for the Content scan, the Frontend scan and the Page module. Regression: "Scan site"
// said "Full scan complete — 0 new, 0 resolved" next to four toasts or a sentence that sent users to the Overview,
// although the four pages were hidden pages no visitor (and so no rendered check) can open.

const t = (key, fallback, ...values) => values.reduce((text, value) => text.replace(/%d/, String(value)), fallback);

const coverage = (skipped, notPublic) => ({checked: 25 - skipped, skipped, notPublic, skippedPages: []});

const open = (critical, warning = 0, info = 0, needsReview = 0) => ({critical, warning, info, needs_review: needsReview, total: critical + warning + info + needsReview});

describe('content scan notification', () => {
    // NOT-01 (2026-10 Sol audit): a repeat scan of a page with 32 open issues and a scan of a clean page both said
    // "0 new, 0 resolved." with a success title.
    it('states what is open on a repeat scan, never only the zero change', () => {
        const result = contentScanNotification({issuesNew: 0, issuesResolved: 0, openIssues: open(14, 13, 2, 3), renderedCoverage: coverage(0, 0), reportUrl: '/typo3/module/content/a11y/page?pageUid=729'}, {scope: 'page'}, t);

        expect(result).toEqual({
            title: 'Content scan completed',
            message: '32 open issues (0 new, 32 already known); 0 resolved.',
            severity: 'info',
            details: false,
            reportUrl: '/typo3/module/content/a11y/page?pageUid=729',
        });
    });

    it('is a success only for a complete scan that left nothing open', () => {
        const result = contentScanNotification({issuesNew: 0, issuesResolved: 0, openIssues: open(0), renderedCoverage: coverage(0, 0)}, {scope: 'page'}, t);

        expect(result.severity).toBe('success');
        expect(result.message).toBe('No open issues.');
    });

    it('names first findings, a genuine fix and a regression', () => {
        expect(contentScanNotification({issuesNew: 5, issuesResolved: 0, openIssues: open(5), renderedCoverage: coverage(0, 0)}, {}, t).message)
            .toBe('5 open issues (5 new, 0 already known); 0 resolved.');
        expect(contentScanNotification({issuesNew: 0, issuesResolved: 2, openIssues: open(0), renderedCoverage: coverage(0, 0)}, {}, t).message)
            .toBe('No open issues: 2 issues were resolved.');
        expect(contentScanNotification({issuesNew: 1, issuesResolved: 1, openIssues: open(1, 3), renderedCoverage: coverage(0, 0)}, {}, t).message)
            .toBe('4 open issues (1 new, 3 already known); 1 resolved.');
    });

    it('opens a site scan with the pages checked and the site total', () => {
        const result = contentScanNotification({issuesNew: 0, issuesResolved: 0, pagesScanned: 25, openIssues: open(151, 86, 12, 53), renderedCoverage: coverage(4, 4)}, {scope: 'site'}, t);

        expect(result.severity).toBe('info');
        expect(result.message).toBe('Content checks ran on 25 pages. 302 open issues (0 new, 302 already known); 0 resolved. Rendered HTML was not checked on 4 pages that visitors cannot open (hidden or restricted).');
        expect(result.details).toBe(true);
    });

    it('is a warning when the rendered HTML of a public page could not be fetched', () => {
        const result = contentScanNotification({issuesNew: 2, issuesResolved: 1, pagesScanned: 25, openIssues: open(2), renderedCoverage: coverage(5, 4)}, {scope: 'site'}, t);

        expect(result.severity).toBe('warning');
        expect(result.title).toBe('Content scan completed with warnings');
        expect(result.message).toContain('Rendered HTML could not be checked on 1 page.');
    });

    it('does not invent a result when the server sent no totals', () => {
        const result = contentScanNotification({issuesNew: 0, issuesResolved: 0, renderedCoverage: coverage(0, 0)}, {scope: 'page'}, t);

        expect(result.severity).toBe('info');
        expect(result.message).toBe('Since the previous scan: 0 new, 0 resolved. The report lists every open issue.');
    });

    it('reads the coverage from the warnings of an answer without it, once per page', () => {
        const warning = (pageUid, reason = '') => ({code: 'rendered_check_skipped', message: 'HTTP 404', context: {pageUid, reason}});
        expect(renderedCoverageOf({warnings: [warning(744, 'hidden'), warning(744, 'hidden'), warning(9), {code: 'rendered_check_skipped_unsupported_doktype', context: {pageUid: 5}}]}))
            .toEqual({skipped: 2, notPublic: 1, failed: 1});
    });
});

describe('frontend scan notification', () => {
    it('names stored occurrences and issue types, not only the change', () => {
        const result = frontendScanNotification({pagesScanned: 1, pagesFailed: 0, pagesExpected: 1, issuesNew: 0, issuesResolved: 0, occurrencesTotal: 39, issueTypesTotal: 14, reportUrl: '/typo3/module/content/a11y/remote-page?remotePageUid=2339'}, {scope: 'page'}, t);

        expect(result).toEqual({
            title: 'Frontend scan completed',
            message: '39 occurrences of 14 issue types (0 new); 0 resolved.',
            severity: 'info',
            details: false,
            reportUrl: '/typo3/module/content/a11y/remote-page?remotePageUid=2339',
        });
    });

    it('is a success when every page was checked and nothing was found', () => {
        const result = frontendScanNotification({pagesScanned: 19, pagesFailed: 0, pagesExpected: 19, issuesNew: 0, issuesResolved: 3, occurrencesTotal: 0, issueTypesTotal: 0}, {scope: 'site'}, t);

        expect(result.severity).toBe('success');
        expect(result.message).toBe('19 pages checked. No issues found on the checked pages; 3 resolved.');
    });

    // NOT-02: a run that knew 3 pages and loaded 1 must not read as complete.
    it('keeps pages that were never checked', () => {
        const oneOfThree = frontendScanNotification({pagesScanned: 1, pagesFailed: 0, pagesExpected: 3, issuesNew: 0, issuesResolved: 0, occurrencesTotal: 2, issueTypesTotal: 1}, {scope: 'site'}, t);
        expect(oneOfThree.severity).toBe('warning');
        expect(oneOfThree.message).toBe('Checked 1 of 3 pages. 2 occurrences of 1 issue type (0 new); 0 resolved. 2 pages were not checked.');

        const failedAndSkipped = frontendScanNotification({pagesScanned: 1, pagesFailed: 1, pagesExpected: 3, issuesNew: 0, issuesResolved: 0, occurrencesTotal: 2, issueTypesTotal: 1}, {scope: 'site'}, t);
        expect(failedAndSkipped.message).toBe('Checked 1 of 3 pages. 2 occurrences of 1 issue type (0 new); 0 resolved. 1 page could not be loaded. 1 page was not checked.');
    });

    it('falls back to the status total of an older service', () => {
        const result = frontendScanNotification({pagesScanned: 1, pagesFailed: 0, pagesTotal: 3, issuesNew: 0, issuesResolved: 0}, {scope: 'site'}, t);

        expect(result.severity).toBe('warning');
        expect(result.message).toContain('Checked 1 of 3 pages.');
        expect(result.message).toContain('Since the previous scan: 0 new, 0 resolved.');
    });

    // The crawler compared a page scan with the previous scan of any page of the site: an unchanged page said
    // "41 new". The server's comparison with the previous compatible stored scan wins over those counts.
    it('takes new and resolved from the comparison with the previous compatible scan', () => {
        const summary = {issuesNew: 41, issuesResolved: 0, occurrencesTotal: 41, issueTypesTotal: 14, pagesExpected: 1, pagesLoaded: 1, comparison: {available: true, new: 0, resolved: 2}};

        expect(frontendScanNotification(summary, {scope: 'page'}, t).message).toBe('41 occurrences of 14 issue types (0 new); 2 resolved.');
    });

    it('claims no change when there is no earlier comparable scan', () => {
        const first = {issuesNew: 41, issuesResolved: 5, occurrencesTotal: 41, issueTypesTotal: 14, pagesExpected: 1, pagesLoaded: 1, comparison: {available: false}};
        const clean = {issuesNew: 0, issuesResolved: 7, occurrencesTotal: 0, issueTypesTotal: 0, pagesExpected: 1, pagesLoaded: 1, comparison: {available: false}};

        expect(frontendScanNotification(first, {scope: 'page'}, t).message).toBe('41 occurrences of 14 issue types (no earlier comparable scan).');
        expect(frontendScanNotification(clean, {scope: 'page'}, t)).toMatchObject({message: 'No issues found on the checked pages.', severity: 'success'});
    });

    it('never reads as clean when no page could be loaded', () => {
        const result = frontendScanNotification({pagesScanned: 0, pagesFailed: 1, issuesNew: 0, issuesResolved: 0}, {scope: 'page'}, t);

        expect(result.severity).toBe('error');
        expect(result.title).toBe('Frontend scan checked no page');
        expect(result.message).toBe('The page could not be loaded. The Overview shows the reason.');
    });
});

describe('page module notification', () => {
    it('names both scans and takes the more severe outcome', () => {
        const result = pageScanNotification(
            {issuesNew: 1, issuesResolved: 0, openIssues: open(1), renderedCoverage: coverage(0, 0), reportUrl: '/report'},
            {pagesScanned: 0, pagesFailed: 1},
            t,
        );

        expect(result.title).toBe('Page scan completed with warnings');
        expect(result.severity).toBe('warning');
        expect(result.message).toBe('Content scan: 1 open issue (1 new, 0 already known); 0 resolved. Frontend scan: The page could not be loaded. The Overview shows the reason.');
        expect(result.reportUrl).toBe('/report');
    });
});

describe('showing a scan notification', () => {
    it('passes title, message, TYPO3 severity, a duration and the details action', () => {
        const api = {showMessage: vi.fn()};
        const container = {setUrl: vi.fn()};
        const topWindow = {TYPO3: {Notification: api, Backend: {ContentContainer: container}}};

        showScanNotification(
            {title: 'Content scan completed with warnings', message: 'm', severity: 'warning', details: true},
            {detailsUrl: 'https://example.test/typo3/module/content/a11y#aqg-rendered-coverage', detailsLabel: 'View affected pages', topWindow},
        );

        const [title, message, severity, duration, actions] = api.showMessage.mock.calls[0];
        expect([title, message, severity, duration]).toEqual(['Content scan completed with warnings', 'm', 1, 20]);

        expect(actions).toHaveLength(1);
        expect(actions[0].label).toBe('View affected pages');
        actions[0].action.execute();
        expect(container.setUrl).toHaveBeenCalledWith('https://example.test/typo3/module/content/a11y#aqg-rendered-coverage');
    });

    it('links a completed scan to its report', () => {
        const api = {showMessage: vi.fn()};
        const container = {setUrl: vi.fn()};
        showScanNotification(
            {title: 'Content scan completed', message: 'No open issues.', severity: 'success', details: false, reportUrl: '/typo3/module/content/a11y/page?pageUid=729'},
            {detailsLabel: 'View affected pages', reportLabel: 'Open report', topWindow: {TYPO3: {Notification: api, Backend: {ContentContainer: container}}}},
        );

        const [, , , duration, actions] = api.showMessage.mock.calls[0];
        expect(duration).toBe(20);
        expect(actions[0].label).toBe('Open report');
        actions[0].action.execute();
        expect(container.setUrl).toHaveBeenCalledWith('/typo3/module/content/a11y/page?pageUid=729');
    });

    it('offers no action for a cancelled scan', () => {
        const api = {showMessage: vi.fn()};
        showScanNotification(scanCancelledNotification('content', t), {detailsUrl: 'https://x.test/', detailsLabel: 'View', topWindow: {TYPO3: {Notification: api}}});

        const [, , severity, duration, actions] = api.showMessage.mock.calls[0];
        expect(severity).toBe(-1);
        expect(duration).toBe(10);
        expect(actions).toEqual([]);
    });

    it('points the module URL at the target without the reload marker', () => {
        expect(moduleUrlWithTarget('https://example.test/typo3/module/content/a11y?id=728&_aqgReload=123', 'aqg-rendered-coverage'))
            .toBe('https://example.test/typo3/module/content/a11y?id=728#aqg-rendered-coverage');
    });
});
