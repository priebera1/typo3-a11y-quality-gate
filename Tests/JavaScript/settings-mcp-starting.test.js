// @vitest-environment jsdom

import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';

// First real activation (typo3test14, 2026-11-09): after the code was confirmed the tab reloaded into "Connector
// offline" although the connector simply had not polled yet. A fresh connection is "starting" now; the tab asks its
// state route and reloads exactly once the state changes — no manual reload, no reload loop, bounded polling.

const render = () => {
    document.body.innerHTML = `
        <div data-aqg-mcp="true">
            <div class="aqg-statusline tone-info" role="status" data-aqg-mcp-starting="true" data-state-url="/typo3/ajax/a11y/settings/mcp/connection-state?token=t" data-poll-seconds="4"></div>
        </div>`;
};

const flush = async () => {
    for (let i = 0; i < 6; i++) {
        await Promise.resolve();
    }
};

let reload;

beforeEach(() => {
    vi.useFakeTimers();
    reload = vi.fn();
    Object.defineProperty(window, 'location', {value: {reload}, configurable: true, writable: true});
});

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = '';
    delete globalThis.fetch;
    vi.resetModules();
});

const answers = (...states) => {
    const queue = [...states];
    globalThis.fetch = vi.fn(async () => {
        const next = queue.length > 1 ? queue.shift() : queue[0];
        if (next instanceof Error) {
            throw next;
        }
        return {json: async () => next};
    });
};

describe('settings-mcp: a starting connection', () => {
    it('keeps waiting while the state is starting and reloads once it changes', async () => {
        render();
        answers({success: true, state: 'starting'}, new Error('offline'), {success: false, code: 'admin_only_settings_required'}, {success: true, state: 'connected'});
        await import('../../Resources/Public/JavaScript/backend/settings-mcp.js');

        for (let poll = 1; poll <= 3; poll++) {
            await vi.advanceTimersByTimeAsync(4000);
            await flush();
            expect(reload).not.toHaveBeenCalled();
        }
        await vi.advanceTimersByTimeAsync(4000);
        await flush();
        expect(globalThis.fetch).toHaveBeenCalledTimes(4);
        expect(reload).toHaveBeenCalledTimes(1);
        const [url, init] = globalThis.fetch.mock.calls[0];
        expect(url).toBe('/typo3/ajax/a11y/settings/mcp/connection-state?token=t');
        expect(init.method).toBe('POST');
    });

    it('stops polling after 15 minutes', async () => {
        render();
        answers({success: true, state: 'starting'});
        await import('../../Resources/Public/JavaScript/backend/settings-mcp.js');
        await vi.advanceTimersByTimeAsync(16 * 60 * 1000);
        await flush();
        const calls = globalThis.fetch.mock.calls.length;
        expect(calls).toBeLessThanOrEqual(15 * 60 / 4 + 1);
        await vi.advanceTimersByTimeAsync(60 * 1000);
        await flush();
        expect(globalThis.fetch.mock.calls.length).toBe(calls);
        expect(reload).not.toHaveBeenCalled();
    });

    it('does nothing without the starting status line', async () => {
        document.body.innerHTML = '<div data-aqg-mcp="true"></div>';
        globalThis.fetch = vi.fn();
        await import('../../Resources/Public/JavaScript/backend/settings-mcp.js');
        await vi.advanceTimersByTimeAsync(20000);
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });
});
