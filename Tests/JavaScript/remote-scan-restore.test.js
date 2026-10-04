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
            translate: (_key, fallback) => fallback,
        };

        A11yProBackendModule.prototype.restoreRemoteScanStateFromDom.call(module);
        await vi.waitFor(() => expect(module.showNotification).toHaveBeenCalled());

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

describe('Screenshot of a result from another licence or project', () => {
    const renderShot = () => {
        document.body.innerHTML = `
            <section class="aqg-shot">
                <div class="aqg-shot__sub">Captured at scan time · click to enlarge</div>
                <button type="button" class="aqg-shot__frame" data-action="a11y-open-screenshot-modal"
                        data-image-url="/typo3/module/web/a11y/remote-screenshot?remotePageUid=1888"
                        data-unavailable-label="Screenshot not available for this project.">
                    <img src="/typo3/module/web/a11y/remote-screenshot?remotePageUid=1888" alt="Screenshot preview">
                </button>
            </section>`;

        return document.querySelector('img');
    };

    it('replaces a screenshot that fails to load with the reason instead of a broken image', () => {
        const image = renderShot();
        Object.defineProperty(image, 'complete', {value: false});
        const module = Object.create(A11yProBackendModule.prototype);
        module.initScreenshotFallback();

        image.dispatchEvent(new Event('error'));

        expect(document.querySelector('[data-action="a11y-open-screenshot-modal"]')).toBeNull();
        expect(document.querySelector('.aqg-shot__frame--empty').textContent).toBe('Screenshot not available for this project.');
        expect(document.querySelector('.aqg-shot__sub').textContent).toBe('Screenshot not available for this project.');
    });

    it('also replaces a screenshot that failed before the module ran', () => {
        const image = renderShot();
        Object.defineProperty(image, 'complete', {value: true});
        Object.defineProperty(image, 'naturalWidth', {value: 0});
        Object.create(A11yProBackendModule.prototype).initScreenshotFallback();

        expect(document.querySelector('.aqg-shot__frame--empty')).not.toBeNull();
    });
});
