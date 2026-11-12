// @vitest-environment jsdom

import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {ToolbarAssistant} from '../../Resources/Public/JavaScript/toolbar/a11y-toolbar-assistant.js';
import {forceMenuContent} from '../../Resources/Public/JavaScript/toolbar/a11y-scan-toolbar-menu.js';

/** The Content scan section as the dropdown renders it: collapsed panel, hidden action area. */
const contentSection = (running = false) => `
    <section class="aqt-scan" data-aqt-source="content">
        <h2><button type="button" data-aqt-disclosure="content" aria-expanded="true" aria-controls="aqt-scan-panel-content">Content scan</button></h2>
        <div id="aqt-scan-panel-content" data-aqt-panel="content">
            <div class="aqt-scan__actions" data-aqt-content-scan="true" data-scan-running="${running ? '1' : '0'}" hidden="hidden"
                 data-label-scanning="Checking the saved page…" data-label-finished="Check finished."
                 data-label-already-running="A content scan is already running." data-label-wait-running="A content scan is already running. Only one runs at a time." data-label-error="The page could not be checked."
                 data-label-error-permission="You may not do this here." data-label-error-session="Your TYPO3 session ended.">
                <button type="button" data-aqt-content-scan-button="true">Check saved page now</button>
                <p id="aqt-content-scan-status" data-aqt-content-scan-status="true" hidden="hidden"></p>
            </div>
        </div>
    </section>`;

// Each test gets its own window (the assistant registers one document click listener per window, like the backend).
let win;
let doc;

function renderMenu(running = false) {
    doc.body.innerHTML = `
        <div class="aqt-pop">
            <div data-aqt-assistant="true"></div>
            <div class="aqt-scans" data-aqt-refresh="scans">${contentSection(running)}
                <section class="aqt-scan" data-aqt-source="frontend"><div id="aqt-scan-panel-frontend"></div></section>
            </div>
            <p data-aqt-scan-live="true" role="status"></p>
        </div>`;
}

const area = () => doc.querySelector('[data-aqt-content-scan]');
const button = () => doc.querySelector('[data-aqt-content-scan-button]');
const status = () => doc.querySelector('[data-aqt-content-scan-status]');

function respond(status, body) {
    return Promise.resolve({ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body)});
}

describe('Check saved page now in the Content scan section', () => {
    let finished;
    let assistant;

    beforeEach(() => {
        const frame = document.createElement('iframe');
        document.body.appendChild(frame);
        win = frame.contentWindow;
        doc = win.document;
        renderMenu();
        finished = vi.fn();
        assistant = new ToolbarAssistant({win, slotSelector: '[data-aqt-assistant]', ajaxUrls: () => ({a11y_scan_page: '/typo3/ajax/a11y/scan/page'}), onScanFinished: finished});
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('is shown only for a page the server offered, never in the Frontend section', () => {
        assistant.setContentScanOffer(0, 0);
        expect(area().hidden).toBe(true);
        assistant.setContentScanOffer(42, 1);
        expect(area().hidden).toBe(false);
        expect(button().dataset.pageUid).toBe('42');
        expect(button().dataset.languageUid).toBe('1');
        expect(doc.querySelector('#aqt-scan-panel-frontend [data-aqt-content-scan-button]')).toBeNull();
    });

    it('explains a content scan running elsewhere instead of hiding the button, and sends nothing', async () => {
        const fetchMock = vi.fn(() => respond(200, {success: true}));
        vi.stubGlobal('fetch', fetchMock);
        renderMenu(true);
        assistant.setContentScanOffer(42, 0);

        expect(area().hidden).toBe(false);
        expect(button().getAttribute('aria-disabled')).toBe('true');
        expect(button().disabled).toBe(false);
        expect(button().getAttribute('aria-describedby')).toBe('aqt-content-scan-status');
        expect(status().hidden).toBe(false);
        expect(status().textContent).toBe('A content scan is already running. Only one runs at a time.');
        expect(status().className).toContain('--info');

        button().focus();
        await assistant.contentScan(button());
        expect(fetchMock).not.toHaveBeenCalled();
        expect(doc.activeElement).toBe(button());
        await vi.waitFor(() => expect(doc.querySelector('[data-aqt-scan-live]').textContent).toBe('A content scan is already running. Only one runs at a time.'));

        // The other scan finished: the status refresh renders the section without it, the explanation goes away.
        forceMenuContent(doc.querySelector('[data-aqt-refresh="scans"]'), contentSection(false));
        assistant.syncContentScan();
        expect(button().hasAttribute('aria-disabled')).toBe(false);
        expect(status().hidden).toBe(true);
    });

    it('explains why a check of another page waits for the user\'s own running check', async () => {
        let release;
        const fetchMock = vi.fn(() => new Promise((resolve) => { release = () => resolve(respond(200, {success: true})); }));
        vi.stubGlobal('fetch', fetchMock);
        assistant.setContentScanOffer(42, 0);
        const run = assistant.contentScan(button());
        // The editor opens another page while page 42 is checked.
        assistant.setContentScanOffer(43, 0);
        expect(button().dataset.pageUid).toBe('43');
        expect(button().getAttribute('aria-disabled')).toBe('true');
        expect(status().textContent).toBe('A content scan is already running. Only one runs at a time.');
        await assistant.contentScan(button());
        expect(fetchMock).toHaveBeenCalledTimes(1);

        release();
        await run;
        // Page 42's check finished: page 43 can be checked now; its button targets 43.
        expect(button().hasAttribute('aria-disabled')).toBe(false);
        expect(status().hidden).toBe(true);
        expect(button().dataset.pageUid).toBe('43');
    });

    it('posts the original page scan route once, shows loading, then the result, and refreshes the section at once', async () => {
        let release;
        const fetchMock = vi.fn(() => new Promise((resolve) => { release = () => resolve(respond(200, {success: true})); }));
        vi.stubGlobal('fetch', fetchMock);
        assistant.setContentScanOffer(42, 0);
        button().focus();

        const run = assistant.contentScan(button());
        await assistant.contentScan(button()); // a second press while busy sends nothing
        expect(button().getAttribute('aria-busy')).toBe('true');
        expect(button().disabled).toBe(false);
        expect(status().hidden).toBe(false);
        expect(status().textContent).toBe('Checking the saved page…');
        release();
        await run;

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/typo3/ajax/a11y/scan/page');
        expect(init.method).toBe('POST');
        expect(String(init.body)).toBe('pageUid=42&languageUid=0');
        expect(button().hasAttribute('aria-busy')).toBe(false);
        expect(status().textContent).toBe('Check finished.');
        expect(status().className).toContain('--ok');
        expect(finished).toHaveBeenCalledWith({contentScan: true, refocus: true});
        expect(doc.activeElement).toBe(button());
    });

    it.each([
        [403, {success: false}, 'You may not do this here.'],
        [401, {}, 'Your TYPO3 session ended.'],
        [409, {success: false}, 'A content scan is already running.'],
        [500, {success: false}, 'The page could not be checked.'],
    ])('answers HTTP %i visibly and keeps focus on the button', async (code, body, text) => {
        vi.stubGlobal('fetch', vi.fn(() => respond(code, body)));
        assistant.setContentScanOffer(42, 0);
        button().focus();
        await assistant.contentScan(button());

        expect(status().hidden).toBe(false);
        expect(status().textContent).toBe(text);
        expect(status().className).toContain(code === 409 ? '--info' : '--error');
        expect(doc.activeElement).toBe(button());
    });

    it('keeps its result and focus when the status refresh re-renders the section', async () => {
        vi.stubGlobal('fetch', vi.fn(() => respond(200, {success: true})));
        assistant.setContentScanOffer(42, 0);
        button().focus();
        await assistant.contentScan(button());

        const scans = doc.querySelector('[data-aqt-refresh="scans"]');
        forceMenuContent(scans, contentSection());
        expect(area().hidden).toBe(true);
        assistant.syncContentScan({refocus: true});

        expect(area().hidden).toBe(false);
        expect(status().textContent).toBe('Check finished.');
        expect(doc.activeElement).toBe(button());
    });

    it('drops the previous page\'s result when the context changes', async () => {
        vi.stubGlobal('fetch', vi.fn(() => respond(200, {success: true})));
        assistant.setContentScanOffer(42, 0);
        await assistant.contentScan(button());
        assistant.setContentScanOffer(43, 0);

        expect(status().hidden).toBe(true);
        expect(button().dataset.pageUid).toBe('43');
    });

    it('the button in the section is wired by the document click handler', async () => {
        const fetchMock = vi.fn(() => respond(200, {success: true}));
        vi.stubGlobal('fetch', fetchMock);
        assistant.setContentScanOffer(42, 0);
        button().click();
        await vi.waitFor(() => expect(finished).toHaveBeenCalled());
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});
