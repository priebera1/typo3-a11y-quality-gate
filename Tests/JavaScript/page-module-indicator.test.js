// @vitest-environment jsdom

import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {
    FOLLOW_INTERVAL_MS,
    FOLLOW_MAX_FAILURES,
    FOLLOW_SLOW_AFTER_MS,
    FOLLOW_SLOW_INTERVAL_MS,
    isFollowingRunningScan,
    startFollowingRunningScan,
    stopFollowingRunningScan,
} from '../../Resources/Public/JavaScript/backend/page-module-indicator.js';

// A frontend scan started in the Accessibility module was shown as "Scan running / Frontend scan: 0/1 pages
// processed" in the Page module until the browser was reloaded: the indicator only followed scans it had started
// itself. It now follows a server-rendered running scan until the scan has ended.

const panelHtml = ({running, meta, extra = ''}) => `
    <section class="aqg-page-module-indicator aqg-page-module-indicator--${running ? 'running' : 'ok'}"
             data-aqg-page-module-indicator="true" data-page-uid="42" data-site="main" data-language-uid="1"
             data-aqg-indicator-running="${running ? '1' : '0'}">
        <p class="aqg-page-module-indicator__headline">${running ? 'Scan running' : 'No issues found'}</p>
        <p class="aqg-page-module-indicator__meta">${meta}</p>
        <a href="/typo3/module/web/a11y/page-detail?id=42">${running ? 'View progress' : 'View details'}</a>
        ${extra}
    </section>`;

const RUNNING_0 = panelHtml({running: true, meta: 'Frontend scan: 0/1 pages processed.'});
const RUNNING_1 = panelHtml({running: true, meta: 'Frontend scan: 1/1 pages processed.'});
const COMPLETED = panelHtml({
    running: false,
    meta: 'Frontend scan: last checked just now',
    extra: '<button type="button" data-aqg-indicator-scan="true">Scan this page</button>',
});
const FAILED = panelHtml({running: false, meta: 'Frontend scan failed'});

const json = (data, status = 200) => ({ok: status >= 200 && status < 300, status, json: async () => data});
const state = (html, running) => json({success: true, running, html});

let hidden = false;

const mount = (html = RUNNING_0) => {
    document.body.innerHTML = html;
    return startFollowingRunningScan();
};

const panel = () => document.querySelector('[data-aqg-page-module-indicator="true"]');
const meta = () => panel().querySelector('.aqg-page-module-indicator__meta').textContent;

beforeEach(() => {
    vi.useFakeTimers();
    hidden = false;
    Object.defineProperty(document, 'hidden', {configurable: true, get: () => hidden});
    globalThis.TYPO3 = {settings: {ajaxUrls: {a11y_page_module_indicator: '/typo3/ajax/a11y/page-module-indicator?token=abc'}}};
    globalThis.fetch = vi.fn();
});

afterEach(() => {
    stopFollowingRunningScan();
    vi.useRealTimers();
    document.body.innerHTML = '';
    delete globalThis.TYPO3;
    delete globalThis.fetch;
});

describe('Page module indicator: following a running scan', () => {
    it('follows a running scan from progress to the completed result, then stops', async () => {
        fetch.mockResolvedValueOnce(state(RUNNING_1, true)).mockResolvedValueOnce(state(COMPLETED, false));
        mount();

        expect(isFollowingRunningScan()).toBe(true);
        expect(fetch).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(FOLLOW_INTERVAL_MS);
        const url = new URL(fetch.mock.calls[0][0], 'https://backend.example');
        expect(url.pathname).toBe('/typo3/ajax/a11y/page-module-indicator');
        expect(url.searchParams.get('token')).toBe('abc');
        expect(url.searchParams.get('pageUid')).toBe('42');
        expect(url.searchParams.get('language')).toBe('1');
        expect(meta()).toBe('Frontend scan: 1/1 pages processed.');

        await vi.advanceTimersByTimeAsync(FOLLOW_INTERVAL_MS);
        expect(panel().dataset.aqgIndicatorRunning).toBe('0');
        expect(panel().textContent).toContain('No issues found');
        expect(isFollowingRunningScan()).toBe(false);

        await vi.advanceTimersByTimeAsync(FOLLOW_SLOW_INTERVAL_MS * 3);
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('ends on a failed or cancelled scan as the server renders it', async () => {
        fetch.mockResolvedValueOnce(state(FAILED, false));
        mount();

        await vi.advanceTimersByTimeAsync(FOLLOW_INTERVAL_MS);

        expect(meta()).toBe('Frontend scan failed');
        expect(isFollowingRunningScan()).toBe(false);
    });

    it('does not start for a panel that is not running', async () => {
        mount(COMPLETED);

        await vi.advanceTimersByTimeAsync(FOLLOW_INTERVAL_MS * 3);

        expect(isFollowingRunningScan()).toBe(false);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('keeps one request loop per document however often it is started', async () => {
        fetch.mockResolvedValue(state(RUNNING_0, true));
        const first = mount();
        expect(startFollowingRunningScan()).toBe(first);
        document.dispatchEvent(new Event('DOMContentLoaded'));
        expect(startFollowingRunningScan()).toBe(first);

        await vi.advanceTimersByTimeAsync(FOLLOW_INTERVAL_MS * 3);

        expect(fetch).toHaveBeenCalledTimes(3);
    });

    it('slows down for a long scan', async () => {
        fetch.mockResolvedValue(state(RUNNING_0, true));
        mount();

        await vi.advanceTimersByTimeAsync(FOLLOW_SLOW_AFTER_MS);
        const fast = fetch.mock.calls.length;
        expect(fast).toBe(FOLLOW_SLOW_AFTER_MS / FOLLOW_INTERVAL_MS);

        await vi.advanceTimersByTimeAsync(FOLLOW_SLOW_INTERVAL_MS * 2 + FOLLOW_INTERVAL_MS);
        expect(fetch.mock.calls.length - fast).toBe(2);
    });

    it('stops without access and after repeated failures, but rides out a single one', async () => {
        fetch.mockResolvedValueOnce(json({success: false, code: 'access_denied'}, 403));
        mount();
        await vi.advanceTimersByTimeAsync(FOLLOW_INTERVAL_MS);
        expect(isFollowingRunningScan()).toBe(false);
        expect(meta()).toBe('Frontend scan: 0/1 pages processed.');

        fetch.mockReset();
        fetch.mockRejectedValueOnce(new TypeError('Failed to fetch')).mockResolvedValueOnce(state(RUNNING_1, true));
        mount();
        await vi.advanceTimersByTimeAsync(FOLLOW_INTERVAL_MS * 2);
        expect(meta()).toBe('Frontend scan: 1/1 pages processed.');
        expect(isFollowingRunningScan()).toBe(true);
        stopFollowingRunningScan();

        fetch.mockReset();
        fetch.mockResolvedValue(json(null, 503));
        mount();
        await vi.advanceTimersByTimeAsync(FOLLOW_INTERVAL_MS * (FOLLOW_MAX_FAILURES + 2));
        expect(fetch).toHaveBeenCalledTimes(FOLLOW_MAX_FAILURES);
        expect(isFollowingRunningScan()).toBe(false);
    });

    it('waits while the document is hidden and asks at once when it is shown again', async () => {
        fetch.mockResolvedValue(state(RUNNING_1, true));
        mount();
        hidden = true;

        await vi.advanceTimersByTimeAsync(FOLLOW_INTERVAL_MS * 3);
        expect(fetch).not.toHaveBeenCalled();

        hidden = false;
        document.dispatchEvent(new Event('visibilitychange'));
        await vi.advanceTimersByTimeAsync(0);
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(meta()).toBe('Frontend scan: 1/1 pages processed.');
    });

    it('ends with the document and aborts a request in flight', async () => {
        let signal = null;
        fetch.mockImplementation((_url, init) => {
            signal = init.signal;
            return new Promise(() => {});
        });
        mount();
        await vi.advanceTimersByTimeAsync(FOLLOW_INTERVAL_MS);

        window.dispatchEvent(new Event('pagehide'));

        expect(signal.aborted).toBe(true);
        expect(isFollowingRunningScan()).toBe(false);
        await vi.advanceTimersByTimeAsync(FOLLOW_INTERVAL_MS * 3);
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('keeps keyboard focus on the same control when the panel is replaced', async () => {
        fetch.mockResolvedValueOnce(state(RUNNING_1, true)).mockResolvedValueOnce(state(COMPLETED, false));
        mount();
        panel().querySelector('a').focus();

        await vi.advanceTimersByTimeAsync(FOLLOW_INTERVAL_MS);
        expect(document.activeElement).toBe(panel().querySelector('a'));

        await vi.advanceTimersByTimeAsync(FOLLOW_INTERVAL_MS);
        // The completed panel has the same link; a control that is gone leaves focus on the panel, not the body.
        expect(document.activeElement).toBe(panel().querySelector('a'));
    });

    it('lets a later scan start from the completed panel without the old follower interfering', async () => {
        fetch.mockResolvedValueOnce(state(COMPLETED, false));
        globalThis.TYPO3.settings.ajaxUrls.a11y_scan_page = '';
        mount();
        await vi.advanceTimersByTimeAsync(FOLLOW_INTERVAL_MS);

        const button = panel().querySelector('[data-aqg-indicator-scan="true"]');
        expect(button).not.toBeNull();
        button.click();
        await vi.advanceTimersByTimeAsync(FOLLOW_INTERVAL_MS * 2);

        expect(isFollowingRunningScan()).toBe(false);
        expect(fetch).toHaveBeenCalledTimes(1);
    });
});
