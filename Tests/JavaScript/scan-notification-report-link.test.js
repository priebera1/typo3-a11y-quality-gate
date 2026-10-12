// @vitest-environment jsdom

import {describe, expect, it, vi} from 'vitest';
import {
    frontendScanNotification,
    withoutSelectedScan,
} from '../../Resources/Public/JavaScript/backend/core/scan-notification.js';
import {A11yProBackendModule} from '../../Resources/Public/JavaScript/backend/pro/pro-module.js';

// V-05 (2026-10 Sol verification): a site-scan notification linked to the Overview without its scan, so a click after
// the next site scan finished opened that newer result. The server now sends a reportUrl bound to the scan
// (`remoteScanUid`); the action, including its failed / not-checked target, opens exactly that URL.

const t = (key, fallback, ...values) => values.reduce((text, value) => text.replace(/%d/, String(value)), fallback);

const reportUrlOfScanA = '/typo3/module/content/a11y?id=1&site=main&language=0&remoteScanUid=20&aqgSource=remote';

function showWithModule(notification, options) {
    const api = {showMessage: vi.fn()};
    const container = {setUrl: vi.fn()};
    window.TYPO3 = {Notification: api, Backend: {ContentContainer: container}};
    const context = {
        translate: (key, fallback) => fallback,
        announce: () => undefined,
        showNotification: () => undefined,
    };
    A11yProBackendModule.prototype.showScanResult.call(context, notification, options);
    return {api, container};
}

describe('site scan notification action', () => {
    it('opens its own scan, at the failed pages, whatever finished later', () => {
        const notificationA = frontendScanNotification(
            {pagesScanned: 2, pagesFailed: 1, pagesExpected: 4, occurrencesTotal: 3, issueTypesTotal: 1, comparison: {available: false}, reportUrl: reportUrlOfScanA},
            {scope: 'site'},
            t,
        );
        expect(notificationA.details).toBe(true);
        expect(notificationA.reportUrl).toBe(reportUrlOfScanA);

        const {api, container} = showWithModule(notificationA, {detailsTarget: 'a11y-remote-failed-pages', detailsLabel: 'View failed pages'});
        // Scan B finished and the module now shows it; the stored action still belongs to A.
        window.history.replaceState(null, '', '/typo3/module/content/a11y?id=1&site=main&language=0&aqgSource=remote');

        const [, , , , actions] = api.showMessage.mock.calls[0];
        actions[0].action.execute();
        const opened = new URL(container.setUrl.mock.calls[0][0]);
        expect(opened.searchParams.get('remoteScanUid')).toBe('20');
        expect(opened.searchParams.get('site')).toBe('main');
        expect(opened.searchParams.get('language')).toBe('0');
        expect(opened.hash).toBe('#a11y-remote-failed-pages');
    });

    it('opens its own scan for a complete result too', () => {
        const notification = frontendScanNotification(
            {pagesScanned: 3, pagesFailed: 0, pagesExpected: 3, occurrencesTotal: 0, issueTypesTotal: 0, comparison: {available: true, new: 0, resolved: 2}, reportUrl: reportUrlOfScanA},
            {scope: 'site'},
            t,
        );
        const {api, container} = showWithModule(notification, {detailsTarget: 'a11y-remote-failed-pages'});

        api.showMessage.mock.calls[0][4][0].action.execute();
        expect(container.setUrl).toHaveBeenCalledWith(reportUrlOfScanA);
    });
});

describe('reloading after a scan', () => {
    it('shows the fresh result instead of an explicitly selected earlier scan', () => {
        const pinned = 'https://example.test/typo3/module/content/a11y?id=1&site=main&remoteScanUid=20&compareFromJobId=a&compareToJobId=b&remotePage=2';

        const fresh = new URL(withoutSelectedScan(pinned));
        expect(fresh.searchParams.has('remoteScanUid')).toBe(false);
        expect(fresh.searchParams.has('compareFromJobId')).toBe(false);
        expect(fresh.searchParams.has('compareToJobId')).toBe(false);
        expect(fresh.searchParams.get('site')).toBe('main');
        expect(fresh.searchParams.get('remotePage')).toBe('2');
    });
});
