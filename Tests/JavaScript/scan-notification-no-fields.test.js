import {describe, expect, it} from 'vitest';
import {contentScanNotification} from '../../Resources/Public/JavaScript/backend/core/scan-notification.js';

const t = (_key, fallback, ...args) => args.reduce((text, arg) => text.replace(/%[ds]/, String(arg)), fallback);

// Product acceptance F-05: with every content field switched off a scan checked nothing, but the notification read
// "Content scan completed — 2 open issues (0 new…)" like a clean, complete result.
describe('content scan notification without enabled scan fields', () => {
    it('says that no content was checked instead of reporting a result', () => {
        const result = contentScanNotification({
            pagesScanned: 1, recordsScanned: 0, issuesNew: 0, issuesResolved: 0,
            openIssues: {total: 2, critical: 0, warning: 2, info: 0, needsReview: 0},
            warnings: [{code: 'no_scan_fields', message: 'No content fields are enabled for scanning…', context: {}}],
        }, {scope: 'page'}, t);

        expect(result.severity).toBe('warning');
        expect(result.title).toBe('Content scan checked no content');
        expect(result.message).toContain('Settings › Scan fields');
        expect(result.message).not.toMatch(/open issue/);
    });

    it('keeps the normal result when fields are enabled', () => {
        const result = contentScanNotification({pagesScanned: 1, issuesNew: 0, issuesResolved: 0, warnings: []}, {scope: 'page'}, t);
        expect(result.title).not.toBe('Content scan checked no content');
    });
});

describe('toolbar content scan message without enabled scan fields', async () => {
    const {contentScanResultMessage} = await import('../../Resources/Public/JavaScript/toolbar/a11y-toolbar-assistant.js');
    const label = (key, fallback = '') => ({finished: 'Check finished.', resultUnchanged: 'No changes. %d open on this page.'})[key] ?? fallback;

    it('does not read as "No changes" when nothing was checked', () => {
        const message = contentScanResultMessage({openIssues: {total: 2}, issuesNew: 0, issuesResolved: 0, warnings: [{code: 'no_scan_fields'}]}, label);
        expect(message).toBe('No content fields are enabled for scanning, so nothing was checked.');
    });
});
