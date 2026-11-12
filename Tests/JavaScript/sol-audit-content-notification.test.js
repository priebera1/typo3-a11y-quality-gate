import {describe, expect, it} from 'vitest';
import {contentScanNotification} from '../../Resources/Public/JavaScript/backend/core/scan-notification.js';

// SOL audit STATE-01 / AUTH-01: a zero of the findings this user may read is not "No open issues"; a site scan that left
// out pages the user may not scan says so. The server's flag comes from the user's rights only.

const t = (key, fallback, ...values) => values.reduce((text, value) => text.replace(/%d|%s/, String(value)), fallback);
const zero = {total: 0, critical: 0, warning: 0, info: 0, needs_review: 0};

describe('content scan notification evidence', () => {
    it('keeps "No open issues" and success for a verified zero', () => {
        const n = contentScanNotification({openIssues: zero, openIssuesRestricted: false, pagesScanned: 1}, {scope: 'page'}, t);
        expect(n.message).toContain('No open issues.');
        expect(n.severity).toBe('success');
    });

    it('qualifies a restricted zero and is never a success', () => {
        const n = contentScanNotification({openIssues: zero, openIssuesRestricted: true, pagesScanned: 1}, {scope: 'page'}, t);
        expect(n.message).toContain('No open issues in the pages and records you can access.');
        expect(n.message).not.toMatch(/No open issues\.(\s|$)/);
        expect(n.severity).toBe('info');
    });

    it('names pages left out of a site scan and warns', () => {
        const n = contentScanNotification({openIssues: zero, openIssuesRestricted: false, pagesScanned: 3, pagesNotPermitted: 2}, {scope: 'site'}, t);
        expect(n.message).toContain('2 pages were not checked: you may not scan them in this language.');
        expect(n.severity).toBe('warning');
    });

    it('limits the zero to the checked content when a rendered page could not be checked (F11 D2)', () => {
        const n = contentScanNotification({openIssues: zero, openIssuesRestricted: false, renderedCoverage: {skipped: 1, notPublic: 0}}, {scope: 'page'}, t);
        expect(n.message).toContain('No open issues in the content that was checked.');
        expect(n.message).not.toMatch(/No open issues\.(\s|$)/);
        expect(n.message).toContain('Rendered HTML could not be checked on 1 page.');
        expect(n.severity).toBe('warning');
    });

    // F11-03: a rendered check skipped as expected (hidden, scheduled or access-restricted page) also limits the zero.
    it('limits the zero when the rendered check of a page visitors cannot open was skipped (F11-03)', () => {
        const n = contentScanNotification({openIssues: zero, openIssuesRestricted: false, renderedCoverage: {skipped: 1, notPublic: 1}}, {scope: 'page'}, t);
        expect(n.message).toContain('No open issues in the content that was checked.');
        expect(n.message).not.toMatch(/No open issues\.(\s|$)/);
        expect(n.message).not.toMatch(/No open issues:/);
        expect(n.message).toContain('Rendered HTML was not checked on 1 page that visitors cannot open (hidden or restricted).');
        expect(n.message).not.toContain('could not be checked');
        expect(n.severity).toBe('info');
        expect(n.title).toBe('Content scan completed');
        expect(n.details).toBe(true);
    });

    it('limits a zero with resolved issues when a rendered check was skipped (F11-03)', () => {
        const n = contentScanNotification({openIssues: zero, openIssuesRestricted: false, issuesResolved: 2, renderedCoverage: {skipped: 2, notPublic: 2}}, {scope: 'site', }, t);
        expect(n.message).toContain('No open issues in the content that was checked.');
        expect(n.message).not.toMatch(/No open issues[.:](\s|$)/);
        expect(n.message).toContain('Rendered HTML was not checked on 2 pages that visitors cannot open (hidden or restricted).');
        expect(n.severity).toBe('info');
    });

    it('keeps expected skips (info) and unexpected failures (warning) apart when both occur (F11-03)', () => {
        const n = contentScanNotification({openIssues: zero, openIssuesRestricted: false, renderedCoverage: {skipped: 2, notPublic: 1}}, {scope: 'site'}, t);
        expect(n.message).toContain('No open issues in the content that was checked.');
        expect(n.message).toContain('Rendered HTML could not be checked on 1 page.');
        expect(n.message).toContain('Rendered HTML was not checked on 1 page that visitors cannot open (hidden or restricted).');
        expect(n.severity).toBe('warning');
    });

    it('keeps the restricted wording first when a restricted user also had a skipped rendered check (F11-03)', () => {
        const n = contentScanNotification({openIssues: zero, openIssuesRestricted: true, renderedCoverage: {skipped: 1, notPublic: 1}}, {scope: 'page'}, t);
        expect(n.message).toContain('No open issues in the pages and records you can access.');
        expect(n.message).not.toContain('No open issues in the content that was checked.');
        expect(n.severity).toBe('info');
    });

    it('keeps the success for a verified zero with full rendered coverage (F11-03 control)', () => {
        const n = contentScanNotification({openIssues: zero, openIssuesRestricted: false, renderedCoverage: {skipped: 0, notPublic: 0}}, {scope: 'page'}, t);
        expect(n.message).toBe('No open issues.');
        expect(n.severity).toBe('success');
    });

    it('keeps visible counts for a restricted user with findings', () => {
        const n = contentScanNotification({openIssues: {...zero, total: 2, warning: 2}, openIssuesRestricted: true, issuesNew: 1}, {scope: 'page'}, t);
        expect(n.message).toContain('2 open issues');
        expect(n.severity).toBe('info');
    });
});
