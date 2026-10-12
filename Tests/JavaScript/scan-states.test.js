// @vitest-environment jsdom

import {afterEach, beforeAll, describe, expect, it, vi} from 'vitest';
import {A11yBaseModule} from '../../Resources/Public/JavaScript/backend/core/base-module.js';
import {initializeLocalPageScan} from '../../Resources/Public/JavaScript/backend/core/local-page-scan.js';
import {A11yFreeBackendModule} from '../../Resources/Public/JavaScript/backend/free/free-module.js';
import {A11yProBackendModule} from '../../Resources/Public/JavaScript/backend/pro/pro-module.js';

// 1.9.9 polish: a scanning table row keeps its geometry and says what runs, a failed scan reads as failed (no frozen
// progress bar, short toast), and an occurrence without a record marker can still be shown on the page.

let module;
beforeAll(() => {
    globalThis.TYPO3 = {lang: {}};
    module = new A11yBaseModule();
});

afterEach(() => {
    document.body.innerHTML = '';
    vi.useRealTimers();
    delete window.top.TYPO3.Notification;
});

const mountRow = () => {
    document.body.innerHTML = `
        <div class="aqg-module">
            <table><tbody>
                <tr data-a11y-page-row="true" data-a11y-page-title="Home">
                    <td>
                        <a class="aqg-rowlink" href="/detail">Home</a>
                        <span><span class="aqg-page-cell__base">UID 1</span><span data-a11y-row-state="true" hidden></span></span>
                    </td>
                    <td class="actions">
                        <button type="button" id="scan" class="aqg-iconbtn" data-action="a11y-rescan" data-aqg-loading="spinner"
                                data-page-uid="1" data-page-title="Home" aria-label="Scan Home"><svg class="aqg-icon"></svg></button>
                    </td>
                </tr>
            </tbody></table>
            <button type="button" id="text" data-action="a11y-rescan" data-page-uid="1">Scan this page</button>
        </div>`;
    return {
        row: document.querySelector('tr'),
        button: document.getElementById('scan'),
        textButton: document.getElementById('text'),
        state: document.querySelector('[data-a11y-row-state]'),
    };
};

describe('scanning table row', () => {
    it('keeps the icon button in place, busy but focusable, and names the scan in the row', () => {
        const {row, button, state} = mountRow();
        button.focus();

        module.setLoadingState(button, true);

        expect(button.querySelector('svg')).not.toBeNull();
        expect(button.textContent.trim()).toBe('');
        expect(state.querySelector('.visually-hidden')?.textContent).toContain('previous result');
        expect(button.disabled).toBe(false);
        expect(document.activeElement).toBe(button);
        expect(button.getAttribute('aria-disabled')).toBe('true');
        expect(button.getAttribute('aria-label')).toBe('Scanning Home');
        expect(button.classList.contains('is-busy')).toBe(true);
        expect(row.classList.contains('is-running')).toBe(true);
        expect(row.getAttribute('aria-busy')).toBe('true');
        expect(state.hidden).toBe(false);
        expect(state.firstChild.textContent).toMatch(/^Scanning · started /);

        module.setLoadingState(button, false);

        expect(button.getAttribute('aria-label')).toBe('Scan Home');
        expect(button.hasAttribute('aria-disabled')).toBe(false);
        expect(row.classList.contains('is-running')).toBe(false);
        expect(state.hidden).toBe(true);
    });

    it('makes other scan controls unavailable without dropping keyboard focus from row buttons', () => {
        const {button, textButton} = mountRow();

        module.setScanControlAvailable(button, false, 'A content scan is already running');
        module.setScanControlAvailable(textButton, false);

        expect(button.disabled).toBe(false);
        expect(button.getAttribute('aria-disabled')).toBe('true');
        expect(button.getAttribute('title')).toBe('A content scan is already running');
        expect(textButton.disabled).toBe(true);

        module.setScanControlAvailable(button, true);
        expect(button.hasAttribute('aria-disabled')).toBe(false);
        expect(button.hasAttribute('title')).toBe(false);
    });

    it('ignores a click on an unavailable row button', async () => {
        const {button} = mountRow();
        const handler = {handleRescan: vi.fn().mockResolvedValue(undefined)};
        initializeLocalPageScan(handler, document.body);
        button.setAttribute('aria-disabled', 'true');

        button.click();
        await Promise.resolve();

        expect(handler.handleRescan).not.toHaveBeenCalled();
    });
});

describe('scan announcements and toasts', () => {
    it('announces through one polite live region', () => {
        vi.useFakeTimers();
        mountRow();

        module.announce('Content scan started: Home.');
        module.announce('Scan complete — 0 new, 1 resolved.');
        vi.runAllTimers();

        const regions = document.querySelectorAll('[data-aqg-live-region="true"]');
        expect(regions).toHaveLength(1);
        expect(regions[0].getAttribute('aria-live')).toBe('polite');
        expect(regions[0].textContent).toBe('Scan complete — 0 new, 1 resolved.');
    });

    it('maps notification types to the TYPO3 severities, so an error is not shown as a notice', () => {
        const showMessage = vi.fn();
        window.top.TYPO3.Notification = {showMessage};

        module.showNotification('failed', 'error');
        module.showNotification('done', 'success');
        module.showNotification('careful', 'warning');
        module.showNotification('fyi', 'info');

        expect(showMessage.mock.calls.map((call) => call[2])).toEqual([2, 0, 1, -1]);
    });
});

describe('failed frontend scan', () => {
    const mountRemote = () => {
        document.body.innerHTML = `
            <div class="aqg-progress-block d-none" data-a11y-remote-scan-progress="true">
                <span class="spinner-border"></span>
                <span data-a11y-remote-scan-title="true">Frontend scan</span>
                <span data-a11y-remote-scan-status="true"></span>
                <div class="aqg-progress-block__bar"><div class="aqg-progress-block__fill"></div></div>
                <span data-a11y-remote-scan-message="true"></span>
                <span data-a11y-remote-scan-background-hint="true"></span>
            </div>
            <p data-aqg-last-completed="remote" hidden>Showing the last completed scan · 24.06.2026 20:11</p>`;
    };

    it('shows the failed state: title, class, no spinner, and labels the older result', () => {
        mountRemote();
        const pro = Object.create(A11yProBackendModule.prototype);
        pro.translations = {};

        pro.updateRemoteScanUi({visible: true, status: 'failed', message: 'Site not reachable.'});

        const box = document.querySelector('[data-a11y-remote-scan-progress]');
        expect(box.classList.contains('aqg-progress-block--failed')).toBe(true);
        expect(box.classList.contains('aqg-progress-block--indeterminate')).toBe(false);
        expect(document.querySelector('[data-a11y-remote-scan-title]').textContent).toBe('Frontend scan failed');
        expect(box.querySelector('.spinner-border').classList.contains('d-none')).toBe(true);
        expect(document.querySelector('[data-a11y-remote-scan-message]').textContent).toBe('Site not reachable.');
        expect(document.querySelector('[data-aqg-last-completed="remote"]').hidden).toBe(false);

        pro.updateRemoteScanUi({visible: true, status: 'running', message: ''});
        expect(document.querySelector('[data-a11y-remote-scan-title]').textContent).toBe('Frontend scan');
        expect(box.classList.contains('aqg-progress-block--failed')).toBe(false);
        // While a scan runs the earlier result stays on screen, labelled as the last completed scan.
        expect(document.querySelector('[data-aqg-last-completed="remote"]').hidden).toBe(false);

        pro.updateRemoteScanUi({visible: false, status: '', message: ''});
        expect(document.querySelector('[data-aqg-last-completed="remote"]').hidden).toBe(true);
    });

    it('keeps a "no scan yet" card out of the way while the first scan runs', () => {
        mountRemote();
        document.body.insertAdjacentHTML('beforeend', '<section class="aqg-status" data-aqg-status="remote" data-aqg-status-empty="true">No frontend scan has been run yet</section>');
        const pro = Object.create(A11yProBackendModule.prototype);
        pro.translations = {};
        const empty = document.querySelector('[data-aqg-status-empty="true"]');

        pro.updateRemoteScanUi({visible: true, status: 'queued', message: ''});
        expect(empty.hidden).toBe(true);
        expect(document.querySelector('[data-a11y-remote-scan-state]')?.textContent ?? 'Running').toBe('Running');

        pro.updateRemoteScanUi({visible: true, status: 'failed', message: 'Site not reachable.'});
        expect(empty.hidden).toBe(false);
    });

    it('gives an unreachable site one sentence and keeps the explanation behind a disclosure', () => {
        mountRemote();
        const box = document.querySelector('[data-a11y-remote-scan-progress]');
        box.insertAdjacentHTML('beforeend', '<div data-a11y-remote-scan-more="true" hidden><button type="button">How to make the site reachable</button><p data-a11y-remote-scan-more-text="true" hidden></p></div>');
        const pro = Object.create(A11yProBackendModule.prototype);
        pro.translations = {};
        const longReason = 'The AQG scanner runs outside your TYPO3 installation and only reaches sites on the public internet. Give the site a publicly reachable base URL.';

        pro.updateRemoteScanUi({visible: true, status: 'failed', message: longReason});
        pro.applyRemoteFailureDetail({code: 'private_network_blocked'}, longReason);

        expect(document.querySelector('[data-a11y-remote-scan-title]').textContent).toBe('Frontend scan failed: site not reachable');
        expect(document.querySelector('[data-a11y-remote-scan-message]').textContent).toContain('cannot reach this site');
        expect(box.querySelector('[data-a11y-remote-scan-more]').hidden).toBe(false);
        expect(box.querySelector('[data-a11y-remote-scan-more-text]').textContent).toBe(longReason);

        // Any other failure keeps its own reason and no explanation block; a new scan hides it again.
        pro.applyRemoteFailureDetail({code: 'licence_invalid'}, 'Licence expired.');
        expect(box.querySelector('[data-a11y-remote-scan-more]').hidden).toBe(true);
    });

    it('keeps the toast short when the status block shows the reason', () => {
        mountRemote();
        const pro = Object.create(A11yProBackendModule.prototype);
        pro.translations = {};
        pro.showNotification = vi.fn();
        pro.announce = vi.fn();
        document.querySelector('[data-a11y-remote-scan-progress]').classList.remove('d-none');

        pro.notifyRemoteScanFailure('Frontend scan failed: Site not reachable from the internet (long text).', 'Site not reachable.');

        expect(pro.showNotification).toHaveBeenCalledWith(
            'Frontend scan failed: The reason is shown in the scan status.',
            'error',
        );
        expect(pro.announce).toHaveBeenCalledWith('Frontend scan failed: Site not reachable.');
    });
});

describe('show on page', () => {
    it('opens an unlinked occurrence by its selector and a linked one by its record', () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);
        const pro = Object.create(A11yProBackendModule.prototype);
        pro.translations = {};
        pro.showNotification = vi.fn();

        const unlinked = document.createElement('button');
        unlinked.dataset.selector = 'img[src$="test.jpg"]';
        unlinked.dataset.url = 'https://example.org/page';
        pro.handleHighlightNode(unlinked);

        const linked = document.createElement('button');
        linked.dataset.uid = '1008';
        linked.dataset.url = 'https://example.org/page';
        pro.handleHighlightNode(linked);

        const first = new URL(open.mock.calls[0][0]);
        expect(first.searchParams.get('aqgDebug')).toBe('1');
        expect(first.searchParams.get('aqgs')).toBe('img[src$="test.jpg"]');
        expect(first.searchParams.has('aqgh')).toBe(false);
        const second = new URL(open.mock.calls[1][0]);
        expect(second.searchParams.get('aqgh')).toBe('1008');
        expect(second.searchParams.has('aqgs')).toBe(false);
        expect(pro.showNotification).not.toHaveBeenCalled();
        open.mockRestore();
    });
});

describe('ignoring one occurrence', () => {
    it('gives focus back to the occurrence menu when the ignore form is cancelled', () => {
        document.body.innerHTML = `
            <details class="aqg-menu aqg-occ__menu" data-aqg-menu="true">
                <summary id="menu">More actions for this occurrence</summary>
                <div><button type="button" class="aqg-menu__item a11y-ignore-trigger" data-issue-uid="7" hidden>Ignore this occurrence…</button></div>
            </details>
            <div class="aqg-ignore-form" id="ignore-form-7"><button type="button" id="cancel" class="a11y-ignore-cancel" data-target="ignore-form-7">Cancel</button></div>`;

        A11yFreeBackendModule.prototype.handleIgnoreCancel.call({}, document.getElementById('cancel'));

        expect(document.getElementById('ignore-form-7').hidden).toBe(true);
        expect(document.querySelector('.a11y-ignore-trigger').hidden).toBe(false);
        expect(document.activeElement).toBe(document.getElementById('menu'));
    });
});

describe('status polling during a scan started on this page', () => {
    it('does not give the scan buttons back with an answer that predates the scan', async () => {
        const {resetAjaxGetHandler, setAjaxGetHandler} = await import('./stubs/ajax-request.js');
        vi.useFakeTimers();
        globalThis.TYPO3 = {settings: {ajaxUrls: {a11y_scan_status: '/typo3/ajax/a11y/scan/status'}}, lang: {}};
        document.body.innerHTML = `
            <button type="button" id="local" data-action="a11y-rescan">Scan this page</button>
            <button type="button" id="page" data-action="a11y-pro-scan-page">Scan this page</button>
            <button type="button" id="site" data-action="a11y-pro-scan-site">Scan site</button>`;
        let answer;
        setAjaxGetHandler(() => ({resolve: () => new Promise((resolve) => { answer = resolve; })}));
        const pro = Object.create(A11yProBackendModule.prototype);
        pro.translations = {};
        pro.scanInProgress = false;
        pro.getRestorableRemoteScanState = () => null;

        A11yFreeBackendModule.prototype.startStatusPolling.call(pro);
        await vi.advanceTimersByTimeAsync(3000);
        // The scan starts while the status request is in flight.
        pro.scanInProgress = true;
        document.querySelectorAll('button').forEach((button) => { button.disabled = true; });
        answer({status: {running: false}, remoteStatus: {status: 'none'}});
        await vi.advanceTimersByTimeAsync(0);

        expect(document.getElementById('local').disabled).toBe(true);
        expect(document.getElementById('page').disabled).toBe(true);
        expect(document.getElementById('site').disabled).toBe(true);
        window.clearInterval(pro.statusPollTimer);
        resetAjaxGetHandler();
    });
});
