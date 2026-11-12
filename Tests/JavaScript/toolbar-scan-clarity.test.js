// @vitest-environment jsdom

import {describe, expect, it} from 'vitest';
import {contentScanResultMessage} from '../../Resources/Public/JavaScript/toolbar/a11y-toolbar-assistant.js';

/**
 * Owner review 2026-11-10: after "Check saved page now" the toolbar said only "Check finished. This section shows the new
 * result." The result now says what the page scan answer proves (ScanAjaxController::scanPageAction()): new and
 * resolved against the stored findings of this page, and what is open on it now. Nothing is claimed without the counts.
 */
const labels = {
    finished: 'Check finished.',
    resultUnchanged: 'Check finished: no new and no resolved issues. %d open on this page.',
    resultChanged: 'Check finished: %1$d new and %2$d resolved. %3$d open on this page.',
    resultOpen: 'Check finished. %d open on this page.',
};
const label = (key, fallback = '') => labels[key] ?? fallback;

describe('Recheck saved content: the visible result', () => {
    it('says "no new and no resolved" only when both counts are 0', () => {
        expect(contentScanResultMessage({issuesNew: 0, issuesResolved: 0, openIssues: {total: 2}}, label))
            .toBe('Check finished: no new and no resolved issues. 2 open on this page.');
    });

    it('names new and resolved when the check changed something', () => {
        expect(contentScanResultMessage({issuesNew: 1, issuesResolved: 3, openIssues: {total: 2}}, label))
            .toBe('Check finished: 1 new and 3 resolved. 2 open on this page.');
    });

    it('reports only the open count when new or resolved is missing', () => {
        expect(contentScanResultMessage({issuesNew: 1, openIssues: {total: 4}}, label)).toBe('Check finished. 4 open on this page.');
        expect(contentScanResultMessage({issuesNew: null, issuesResolved: 0, openIssues: {total: 0}}, label)).toBe('Check finished. 0 open on this page.');
    });

    it('claims nothing about the page without its open count', () => {
        expect(contentScanResultMessage({issuesNew: 0, issuesResolved: 0}, label)).toBe('Check finished.');
        expect(contentScanResultMessage({issuesNew: 0, issuesResolved: 0, openIssues: {total: 'x'}}, label)).toBe('Check finished.');
        expect(contentScanResultMessage(undefined, label)).toBe('Check finished.');
    });

    it('falls back to the plain text when a result label is missing (older markup)', () => {
        expect(contentScanResultMessage({issuesNew: 0, issuesResolved: 0, openIssues: {total: 1}}, (key, fallback = '') => (key === 'finished' ? 'Done.' : fallback)))
            .toBe('Done.');
    });
});
