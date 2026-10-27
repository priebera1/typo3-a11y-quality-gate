// @vitest-environment jsdom

import {afterEach, describe, expect, it, vi} from 'vitest';
import {A11yProBackendModule} from '../../Resources/Public/JavaScript/backend/pro/pro-module.js';
import {resetAjaxGetHandler, setAjaxGetHandler} from './stubs/ajax-request.js';

afterEach(() => {
    resetAjaxGetHandler();
    document.body.innerHTML = '';
    delete globalThis.TYPO3;
});

describe('Restoring a frontend scan on module load', () => {
    it('drops a job the AQG service no longer serves without reporting a failed scan', async () => {
        const unavailable = new Error('This frontend scan is no longer available for this installation. Start a new scan.');
        unavailable.code = 'remote_job_unavailable';
        const module = {
            getRestorableRemoteScanState: () => ({jobId: 'job-1', siteIdentifier: 'aqg', scope: 'page', status: 'active', pagesScanned: 0, pagesTotal: 1}),
            setScanInProgress: vi.fn(),
            updateRemoteScanUi: vi.fn(),
            buildRemoteProgressMessage: () => '',
            monitorRemoteScan: vi.fn().mockRejectedValue(unavailable),
            resetRemoteScanDomState: vi.fn(),
            showNotification: vi.fn(),
            translate: (_key, fallback) => fallback,
        };

        A11yProBackendModule.prototype.restoreRemoteScanStateFromDom.call(module);
        await vi.waitFor(() => expect(module.setScanInProgress).toHaveBeenLastCalledWith(false));

        expect(module.resetRemoteScanDomState).toHaveBeenCalled();
        expect(module.updateRemoteScanUi).toHaveBeenLastCalledWith(expect.objectContaining({visible: false, status: ''}));
        expect(module.showNotification).not.toHaveBeenCalled();
    });

    it('still reports a restored scan that really failed', async () => {
        const module = {
            getRestorableRemoteScanState: () => ({jobId: 'job-1', siteIdentifier: 'aqg', scope: 'site', status: 'running', pagesScanned: 2, pagesTotal: 9}),
            setScanInProgress: vi.fn(),
            updateRemoteScanUi: vi.fn(),
            buildRemoteProgressMessage: () => '',
            monitorRemoteScan: vi.fn().mockRejectedValue(new Error('Frontend scan did not complete successfully.')),
            resetRemoteScanDomState: vi.fn(),
            showNotification: vi.fn(),
            announce: vi.fn(),
            notifyRemoteScanFailure(notificationMessage, message) {
                return A11yProBackendModule.prototype.notifyRemoteScanFailure.call(this, notificationMessage, message);
            },
            showScanResult(notification, options) {
                return A11yProBackendModule.prototype.showScanResult.call(this, notification, options);
            },
            scanLabel() {
                return A11yProBackendModule.prototype.scanLabel.call(this);
            },
            format: (template) => template,
            translate: (_key, fallback) => fallback,
        };

        A11yProBackendModule.prototype.restoreRemoteScanStateFromDom.call(module);
        await vi.waitFor(() => expect(module.showNotification).toHaveBeenCalled());
        // No visible status block in this fixture: the toast keeps the full reason.
        expect(module.showNotification).toHaveBeenCalledWith('Frontend scan failed: Frontend scan did not complete successfully.', 'error');

        expect(module.updateRemoteScanUi).toHaveBeenLastCalledWith(expect.objectContaining({visible: true, status: 'failed'}));
    });

    it('marks the gone answer of the status endpoint so a restore can tell it apart', async () => {
        globalThis.TYPO3 = {settings: {ajaxUrls: {a11y_pro_crawl_status: '/typo3/ajax/a11y/pro/crawl/status'}}, lang: {}};
        setAjaxGetHandler(() => Promise.reject({
            response: {
                status: 410,
                clone: () => ({json: async () => ({success: false, code: 'remote_job_unavailable', status: 'failed', message: 'Gone for this installation.'})}),
                json: async () => ({success: false, code: 'remote_job_unavailable', status: 'failed', message: 'Gone for this installation.'}),
            },
        }));
        const module = Object.create(A11yProBackendModule.prototype);
        module.remotePollInProgress = false;

        await expect(module.pollRemoteCrawlerJob('job-1', 'aqg')).rejects.toMatchObject({code: 'remote_job_unavailable'});
        expect(module.remotePollInProgress).toBe(false);
    });
});

describe('Screenshot of a result from another licence or project, or past its retention period', () => {
    const renderShot = () => {
        document.body.innerHTML = `
            <section class="aqg-shot">
                <div class="aqg-shot__sub">Captured at scan time · click to enlarge</div>
                <button type="button" class="aqg-shot__frame" data-action="a11y-open-screenshot-modal"
                        data-image-url="/typo3/module/web/a11y/remote-screenshot?remotePageUid=1888"
                        data-unavailable-label="Screenshot not available for this project."
                        data-expired-label="Deleted after its retention period. The results are kept."
                        data-deleted-label="The screenshot was deleted."
                        data-failed-label="The screenshot could not be captured."
                        data-not-captured-label="No screenshot was taken during this scan.">
                    <img src="/typo3/module/web/a11y/remote-screenshot?remotePageUid=1888" alt="Screenshot preview">
                </button>
                <div class="aqg-shot__variants"><button type="button">Original</button></div>
            </section>`;

        return document.querySelector('img');
    };
    const answer = (status, body) => {
        globalThis.fetch = vi.fn(async () => ({ ok: status < 400, status, json: async () => body }));
    };
    const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

    it('replaces a screenshot that fails to load with the reason instead of a broken image', async () => {
        answer(403, { success: false });
        const image = renderShot();
        Object.defineProperty(image, 'complete', {value: false});
        const module = Object.create(A11yProBackendModule.prototype);
        module.initScreenshotFallback();

        image.dispatchEvent(new Event('error'));
        await settle();

        expect(document.querySelector('[data-action="a11y-open-screenshot-modal"]')).toBeNull();
        expect(document.querySelector('.aqg-shot__frame--empty').textContent).toBe('Screenshot not available for this project.');
        // The reason is written once: the description of the removed image goes with it.
        expect(document.querySelector('.aqg-shot__sub')).toBeNull();
        expect(document.body.textContent.split('Screenshot not available for this project.').length - 1).toBe(1);
        expect(document.querySelector('.aqg-shot__variants')).toBeNull();
    });

    it('moves keyboard focus to the reason when the screenshot button had it', async () => {
        answer(410, { success: false, screenshotState: 'expired' });
        const image = renderShot();
        Object.defineProperty(image, 'complete', {value: false});
        document.querySelector('[data-action="a11y-open-screenshot-modal"]').focus();
        Object.create(A11yProBackendModule.prototype).initScreenshotFallback();

        image.dispatchEvent(new Event('error'));
        await settle();

        const replacement = document.querySelector('.aqg-shot__frame--empty');
        expect(document.activeElement).toBe(replacement);
        expect(replacement.tabIndex).toBe(-1);
    });

    it('leaves focus alone when the screenshot button did not have it', async () => {
        answer(410, { success: false, screenshotState: 'expired' });
        const image = renderShot();
        Object.defineProperty(image, 'complete', {value: false});
        Object.create(A11yProBackendModule.prototype).initScreenshotFallback();

        image.dispatchEvent(new Event('error'));
        await settle();

        expect(document.activeElement).toBe(document.body);
        expect(document.querySelector('.aqg-shot__frame--empty').hasAttribute('tabindex')).toBe(false);
    });

    it.each([
        ['expired', 410, 'Deleted after its retention period. The results are kept.'],
        ['deleted', 410, 'The screenshot was deleted.'],
        ['failed', 404, 'The screenshot could not be captured.'],
        ['not_captured', 404, 'No screenshot was taken during this scan.'],
        ['unavailable', 404, 'Screenshot not available for this project.'],
    ])('names the reason the proxy reports: %s', async (state, status, label) => {
        answer(status, { success: false, screenshotState: state });
        const image = renderShot();
        Object.defineProperty(image, 'complete', {value: false});
        Object.create(A11yProBackendModule.prototype).initScreenshotFallback();
        image.dispatchEvent(new Event('error'));
        await settle();
        expect(document.querySelector('.aqg-shot__frame--empty').textContent).toBe(label);
    });

    it('never calls a network failure an expiry', async () => {
        globalThis.fetch = vi.fn(async () => { throw new TypeError('network'); });
        const image = renderShot();
        Object.defineProperty(image, 'complete', {value: false});
        Object.create(A11yProBackendModule.prototype).initScreenshotFallback();
        image.dispatchEvent(new Event('error'));
        await settle();
        expect(document.querySelector('.aqg-shot__frame--empty').textContent).toBe('Screenshot not available for this project.');
    });

    it('also replaces a screenshot that failed before the module ran', async () => {
        answer(410, { success: false, screenshotState: 'expired' });
        const image = renderShot();
        Object.defineProperty(image, 'complete', {value: true});
        Object.defineProperty(image, 'naturalWidth', {value: 0});
        Object.create(A11yProBackendModule.prototype).initScreenshotFallback();
        await settle();

        expect(document.querySelector('.aqg-shot__frame--empty').textContent).toBe('Deleted after its retention period. The results are kept.');
    });
});
