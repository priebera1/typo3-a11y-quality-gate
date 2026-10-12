// @vitest-environment jsdom

import {afterEach, describe, expect, it, vi} from 'vitest';

// Audit S5: the Scanner Token card shows one state at a time. A stored token is never displayed; a token generated in
// this page view is shown masked with a Copy action, and the regenerate warning applies once a token exists.

const FULL_TOKEN = 'f3a1c9e07b5d42a8a6e1d3c5b7f90246e8a0c2d4f6b8091a3c5e7f9b1d3f5a7c';

const renderForm = (hasToken) => {
    document.body.innerHTML = `
        <form class="js-aqg-remote-access-form" data-has-token="${hasToken ? '1' : '0'}" data-regenerate-token-url="/token">
            <input type="hidden" name="rulesetSite" value="main">
            <p class="js-aqg-token-stored" ${hasToken ? '' : 'hidden'}>Active</p>
            <div class="js-aqg-token-present" hidden>
                <code class="js-aqg-token-value"></code>
                <button type="button" class="js-aqg-copy-token" disabled><span class="js-aqg-copy-token-label">Copy</span></button>
            </div>
            <p class="js-aqg-token-empty" ${hasToken ? 'hidden' : ''}>Not set</p>
            <p class="js-aqg-token-warning" ${hasToken ? '' : 'hidden'}>Regenerating invalidates the current token.</p>
            <p class="js-aqg-token-success" hidden></p>
            <button type="button" class="js-aqg-regenerate-token"><span class="js-aqg-regenerate-token-label">Generate token</span></button>
        </form>`;

    return document.querySelector('form');
};

const flush = async () => {
    for (let i = 0; i < 6; i++) {
        await Promise.resolve();
    }
};

const isShown = (selector) => !document.querySelector(selector).hasAttribute('hidden');

afterEach(() => {
    document.body.innerHTML = '';
    delete globalThis.fetch;
    vi.resetModules();
});

describe('Scanner Token states', () => {
    it('switches from "not set" to the one-time masked value with Copy, without printing the token', async () => {
        renderForm(false);
        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({success: true, maskedToken: 'f3a1…5a7c', token: FULL_TOKEN}),
        });
        await import('../../Resources/Public/JavaScript/backend/settings-remote-access.js');

        document.querySelector('.js-aqg-regenerate-token').click();
        await flush();

        expect(isShown('.js-aqg-token-present')).toBe(true);
        expect(isShown('.js-aqg-token-empty')).toBe(false);
        expect(isShown('.js-aqg-token-stored')).toBe(false);
        expect(isShown('.js-aqg-token-warning')).toBe(true);
        expect(document.querySelector('.js-aqg-token-value').textContent).toBe('f3a1…5a7c');
        expect(document.body.textContent).not.toContain(FULL_TOKEN);
        expect(document.querySelector('.js-aqg-copy-token').disabled).toBe(false);
        expect(document.querySelector('.js-aqg-regenerate-token-label').textContent).toBe('Regenerate token');
    });

    it('keeps a stored token hidden and its state unchanged when regeneration fails', async () => {
        renderForm(true);
        globalThis.fetch = vi.fn().mockResolvedValue({ok: false, json: async () => ({success: false, message: 'Denied'})});
        await import('../../Resources/Public/JavaScript/backend/settings-remote-access.js');

        document.querySelector('.js-aqg-regenerate-token').click();
        await flush();

        expect(isShown('.js-aqg-token-stored')).toBe(true);
        expect(isShown('.js-aqg-token-present')).toBe(false);
        expect(document.querySelector('.js-aqg-token-value').textContent).toBe('');
    });
});
