// @vitest-environment jsdom

import {describe, expect, it, vi} from 'vitest';
import {coalescedRefresh} from '../../Resources/Public/JavaScript/toolbar/a11y-scan-toolbar-menu.js';

// MEDIUM-3 (large-installation stress test): every backend load rendered the toolbar summary twice — the startup timer
// and TYPO3's toolbar-ready callback (Toolbar.registerEvent() runs it on document ready) asked at the same time. Under
// 17 concurrent editors that endpoint was 28.7 % of all server time.
const deferred = () => {
    let resolve;
    const promise = new Promise((r) => {
        resolve = r;
    });

    return {promise, resolve};
};

describe('toolbar refresh coalescing', () => {
    it('sends one request when two refreshes are asked while the first is in flight', async () => {
        const answer = deferred();
        const run = vi.fn(() => answer.promise);
        const refresh = coalescedRefresh(run);

        const first = refresh(false, true, {});
        const second = refresh(false, false, {});
        answer.resolve('menu');

        expect(await first).toBe('menu');
        expect(await second).toBe('menu');
        expect(run).toHaveBeenCalledTimes(1);
    });

    it('asks again once the previous refresh has finished', async () => {
        const run = vi.fn(async () => 'menu');
        const refresh = coalescedRefresh(run);

        await refresh(false);
        await refresh(false);

        expect(run).toHaveBeenCalledTimes(2);
    });

    it('always sends the refresh the user asked for after a content scan', async () => {
        const answer = deferred();
        const run = vi.fn(() => answer.promise);
        const refresh = coalescedRefresh(run);

        refresh(false);
        refresh(true, false, {contentScan: true});
        answer.resolve('menu');
        await Promise.resolve();

        expect(run).toHaveBeenCalledTimes(2);
        expect(run.mock.calls[1]).toEqual([false, {contentScan: true}]);
    });

    it('lets a failed refresh go so that the next one is sent', async () => {
        const run = vi.fn().mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce('menu');
        const refresh = coalescedRefresh(run);

        await expect(refresh(false)).rejects.toThrow('offline');
        expect(await refresh(false)).toBe('menu');
        expect(run).toHaveBeenCalledTimes(2);
    });
});
