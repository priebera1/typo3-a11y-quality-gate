// @vitest-environment jsdom

import {afterEach, describe, expect, it, vi} from 'vitest';
import {initializeAiSettings} from '../../Resources/Public/JavaScript/backend/settings-ai.js';

// AQG 3.0.0 final polish: the section navigation's privacy, usage and diagnostics states follow the server's UI state
// after every AI-settings request (success or refusal); nothing changes before the server answers, a transport failure
// keeps the last server state, and a usage report that no longer matches the server says so.

const createRoot = (usageMonth = '2026-11', usageCurrent = '1') => {
    document.body.innerHTML = `
        <div data-aqg-ai-settings="true" data-privacy-url="/privacy" data-acknowledge-url="/ack" data-test-url="/test"
             data-provider="openai" data-provider-label="OpenAI" data-provider-chosen="1" data-configured="1"
             data-model-selected="1" data-connection-status="connected" data-message-privacy-saved="Privacy saved."
             data-message-request-failed="Request failed.">
            <input data-aqg-ai-site="true" value="main">
            <nav data-aqg-ai-sectionnav="true" data-label-terms-confirmed="Terms confirmed" data-label-terms-missing="Confirmation required"
                 data-label-budget-reached="Budget reached" data-label-budget-warning="%s %% of budget"
                 data-label-requests-singular="%s request" data-label-requests-plural="%s requests"
                 data-label-prices-failing="Price update failing" data-label-prices-automatic="Automatic prices" data-label-cost-unknown="Unknown">
                <span data-aqg-ai-nav-state="privacy"><span class="aqg-ai-badge tone-warning">Confirmation required</span></span>
                <span data-aqg-ai-nav-state="usage"><span class="aqg-ai-sectionnav__figure">≈ $1.00 · 2 requests</span></span>
                <span data-aqg-ai-nav-state="diagnostics"><span class="aqg-ai-badge tone-none">Automatic prices</span></span>
            </nav>
            <input type="checkbox" data-aqg-ai-allow-images="true" checked>
            <input type="checkbox" data-aqg-ai-allow-page-title="true" checked>
            <div data-aqg-ai-ack="true" data-confirmed="0">
                <input type="checkbox" id="ack" data-aqg-ai-ack-checkbox="true">
                <span data-aqg-ai-ack-label="true"></span><p data-aqg-ai-ack-status="true"></p>
            </div>
            <button type="button" data-action="aqg-ai-save-privacy">Save privacy</button>
            <section data-aqg-ai-usage="true" data-usage-month="${usageMonth}" data-usage-requests="2" data-usage-current="${usageCurrent}">
                <div data-aqg-ai-usage-stale="true" hidden></div>
            </section>
            <div data-aqg-ai-status="true" hidden></div>
        </div>`;
    const root = document.querySelector('[data-aqg-ai-settings]');
    initializeAiSettings(root);
    return root;
};

const serverState = (overrides = {}) => ({
    configured: true, modelSelected: true, selectedModelAvailable: true, status: 'connected', provider: 'openai',
    acknowledgement: {confirmed: true},
    budget: {state: 'none', percent: 0, hardStop: false},
    usage: {month: '2026-11', totals: {requests: 2, costState: 'estimated', estimatedMicroUsd: 1_000_000, estimatedLabel: '$1.00', estimatedIsBound: false}},
    pricing: {diagnostics: {anyFailed: false}},
    ...overrides,
});

const respond = (status, body) => Promise.resolve({ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body)});
const text = (root, part) => root.querySelector(`[data-aqg-ai-nav-state="${part}"]`).textContent.replace(/\s+/g, ' ').trim();
const click = async (root, action) => {
    root.querySelector(`[data-action="${action}"]`).click();
    await vi.waitFor(() => expect(root.querySelector('[data-aqg-ai-status]').hidden).toBe(false));
};

describe('AI settings section navigation follows the server state', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('shows a confirmed acknowledgement only after the server stored it', async () => {
        let release;
        const fetchMock = vi.fn((url) => url === '/ack'
            ? new Promise((resolve) => { release = () => resolve(respond(200, {success: true, uiState: serverState()})); })
            : respond(200, {success: true, uiState: serverState({acknowledgement: {confirmed: false}})}));
        vi.stubGlobal('fetch', fetchMock);
        const root = createRoot();
        root.querySelector('[data-aqg-ai-ack-checkbox]').checked = true;
        root.querySelector('[data-action="aqg-ai-save-privacy"]').click();

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(text(root, 'privacy')).toBe('! Confirmation required');
        release();
        await vi.waitFor(() => expect(text(root, 'privacy')).toBe('✓ Terms confirmed'));
    });

    it('keeps the persisted state when the server refuses, and the last server state on a transport failure', async () => {
        vi.stubGlobal('fetch', vi.fn(() => respond(403, {success: false, code: 'permission_denied', uiState: serverState({acknowledgement: {confirmed: false}, pricing: {diagnostics: {anyFailed: true}}})})));
        const root = createRoot();
        await click(root, 'aqg-ai-save-privacy');
        expect(text(root, 'privacy')).toBe('! Confirmation required');
        expect(text(root, 'diagnostics')).toBe('! Price update failing');

        root.querySelector('[data-aqg-ai-status]').hidden = true;
        vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new Error('offline'))));
        await click(root, 'aqg-ai-save-privacy');
        expect(text(root, 'diagnostics')).toBe('! Price update failing');
        expect(text(root, 'usage')).toBe('≈ $1.00 · 2 requests');
    });

    it('updates the month figure and flags a report that no longer matches the server', async () => {
        vi.stubGlobal('fetch', vi.fn(() => respond(200, {success: true, uiState: serverState({
            usage: {month: '2026-11', totals: {requests: 3, costState: 'estimated', estimatedMicroUsd: 1_004_000, estimatedLabel: '$1.00', estimatedIsBound: false}},
        })})));
        const root = createRoot();
        await click(root, 'aqg-ai-save-privacy');

        expect(text(root, 'usage')).toBe('≈ $1.00 · 3 requests');
        expect(root.querySelector('[data-aqg-ai-usage-stale]').hidden).toBe(false);
    });

    it('shows a reached or warning budget instead of the figure', async () => {
        vi.stubGlobal('fetch', vi.fn(() => respond(200, {success: true, uiState: serverState({budget: {state: 'warning', percent: 82, hardStop: true}})})));
        const root = createRoot();
        await click(root, 'aqg-ai-save-privacy');
        expect(text(root, 'usage')).toBe('! 82 % of budget');
        expect(root.querySelector('[data-aqg-ai-usage-stale]').hidden).toBe(true);
    });

    it('leaves the figure of an earlier month alone (the server state is the current month)', async () => {
        vi.stubGlobal('fetch', vi.fn(() => respond(200, {success: true, uiState: serverState({
            usage: {month: '2026-11', totals: {requests: 9, costState: 'estimated', estimatedMicroUsd: 9_000_000, estimatedLabel: '$9.00'}},
        })})));
        const root = createRoot('2026-10', '0');
        await click(root, 'aqg-ai-save-privacy');
        expect(text(root, 'usage')).toBe('≈ $1.00 · 2 requests');
        expect(root.querySelector('[data-aqg-ai-usage-stale]').hidden).toBe(true);
    });
});
