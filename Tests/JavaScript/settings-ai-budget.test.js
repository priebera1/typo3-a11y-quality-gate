// @vitest-environment jsdom

import {afterEach, describe, expect, it, vi} from 'vitest';
import {initializeAiSettings} from '../../Resources/Public/JavaScript/backend/settings-ai.js';

// AQG 3.0 Package 1 safeguards (UX Atlas 01 A, E, F): the readiness line follows the server policy including the
// processing acknowledgement and the budget; a budget needs an explicit hard-stop choice. Prices are automatic and read
// only (AQG 3.0): they come from the AQG service's catalogue with the server state; the page never asks for them.

const createRoot = () => {
    document.body.innerHTML = `
        <div data-aqg-ai-settings="true"
             data-privacy-url="/privacy" data-acknowledge-url="/ack" data-budget-url="/budget"
             data-select-model-url="/select" data-test-url="/test" data-ready-for-editors="0"
             data-label-ready-active="AI active for editors" data-label-ready-inactive="AI not active for editors"
             data-label-price-summary="%1$s: %2$s input, %3$s output, %4$s cached input per 1M tokens · checked %5$s"
             data-label-price-tier="Requests with more than %1$s input tokens are billed at %2$s input and %3$s output per 1M tokens."
             data-label-price-pair="%1$s in / %2$s out" data-label-price-unknown="No price" data-label-model-current="In use"
             data-provider="openai" data-provider-label="OpenAI" data-source="site" data-provider-chosen="1"
             data-configured="1" data-model-selected="1" data-connection-status="connected" data-licence-allows-ai="1"
             data-label-ready-licence-ok="Licence ok" data-label-ready-provider-ok="Provider: %s"
             data-label-ready-key-ok="Key stored" data-label-ready-model-ok="Model verified: %s" data-label-ready-model-missing="No verified model"
             data-label-ready-ack-ok="Privacy acknowledged" data-label-ready-ack-missing="Acknowledge privacy for %s"
             data-label-ready-budget-none="No budget set" data-label-ready-budget-used="Budget: %1$s %% of %2$s"
             data-label-ready-budget-no-price="Cost unknown: no price set"
             data-label-ready-budget-reached="Monthly budget reached — AI is paused for editors"
             data-label-ready-budget-unenforceable="Not enforceable: no price set for %s."
             data-label-ack-label="I confirm that a DPA with %s covers this site."
             data-label-ack-help="Required. Applies to %s on this site only."
             data-label-ack-confirmed="Confirmed by %1$s on %2$s · applies to %3$s on this site only."
             data-message-privacy-saved="Privacy saved." data-message-hard-stop-required="Choose whether AI pauses."
             data-message-request-failed="Request failed." data-selected-model-id="gpt-5.4-mini" data-selected-model-label="GPT-5.4 mini">
            <input data-aqg-ai-site="true" value="main">
            <ul data-aqg-ai-readiness="true">
                <li data-ready-item="licence"><span class="aqg-ai-ready__icon"></span><span data-ready-text="true"></span></li>
                <li data-ready-item="model"><span class="aqg-ai-ready__icon"></span><span data-ready-text="true"></span></li>
                <li data-ready-item="acknowledgement"><span class="aqg-ai-ready__icon"></span><span data-ready-text="true">server</span></li>
                <li data-ready-item="budget"><span class="aqg-ai-ready__icon"></span><span data-ready-text="true">server</span></li>
            </ul>
            <input type="checkbox" data-aqg-ai-allow-images="true" checked>
            <input type="checkbox" data-aqg-ai-allow-page-title="true" checked>
            <div data-aqg-ai-ack="true" data-confirmed="0">
                <div data-aqg-ai-ack-blocked="true"></div>
                <input type="checkbox" id="ack" data-aqg-ai-ack-checkbox="true">
                <label for="ack"><span data-aqg-ai-ack-label="true">I confirm that a DPA with OpenAI covers this site.</span></label>
                <p data-aqg-ai-ack-status="true"></p>
            </div>
            <button type="button" data-action="aqg-ai-save-privacy">Save privacy</button>
            <input id="budget" data-aqg-ai-budget-input="true" value="">
            <input id="warn" data-aqg-ai-budget-warning="true" value="">
            <input type="radio" name="aqg-ai-hard-stop" id="stop-on" value="1" data-aqg-ai-hard-stop="true">
            <input type="radio" name="aqg-ai-hard-stop" id="stop-off" value="0" data-aqg-ai-hard-stop="true">
            <button type="button" data-action="aqg-ai-save-limits">Save limits</button>
            <span data-aqg-ai-ready-badge="true"><span class="aqg-ai-badge__icon"></span><span data-aqg-ai-ready-badge-text="true"></span></span>
            <p data-aqg-ai-not-ready-note="true" hidden>Not ready yet.</p>
            <table><tbody data-aqg-ai-model-list="true"><tr data-model-id="gpt-5.4-mini" data-model-label="GPT-5.4 mini" data-price-known="1" data-price-input="$0.75" data-price-output="$4.50" data-price-cached="$0.075"><td><input type="radio" name="aqg-ai-model" value="gpt-5.4-mini" data-aqg-ai-model-option="true" checked></td><td></td><td></td><td></td></tr></tbody></table>
            <div data-aqg-ai-price-summary="true"><p data-aqg-ai-price-summary-text="true">server</p><p data-aqg-ai-price-stale="true" hidden></p><p data-aqg-ai-price-tier="true" hidden></p></div>
            <button type="button" data-action="aqg-ai-use-model">Use this model</button>
            <div data-aqg-ai-status="true" hidden></div>
        </div>`;
    const root = document.querySelector('[data-aqg-ai-settings="true"]');
    initializeAiSettings(root);
    return root;
};

const respond = (body, status = 200) => ({ok: status >= 200 && status < 300, status, json: vi.fn().mockResolvedValue(body)});
const flush = async () => {
    for (let i = 0; i < 10; i++) {
        await Promise.resolve();
    }
};
const bodyOf = (call) => Object.fromEntries(new URLSearchParams(call[1].body));
const click = (root, action) => root.querySelector(`[data-action="${action}"]`).click();

const readyState = (overrides = {}) => ({
    configured: true, status: 'connected', provider: 'openai', providerLabel: 'OpenAI', providerChosen: true,
    licenceAllowsAi: true, selectedModelLabel: 'GPT-5.4 mini',
    readiness: {licence: 'ok', provider: 'ok', key: 'ok', model: 'ok', acknowledgement: 'missing', budget: 'ok'},
    acknowledgement: {confirmed: false, provider: '', providerLabel: '', byName: '', atDate: ''},
    budget: {state: 'none', blocking: false, percent: 0, budgetLabel: '$0.00', modelLabel: 'GPT-5.4 mini'},
    actions: {refreshModelsEnabled: true, testConnectionEnabled: true},
    ...overrides,
});

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
    window.sessionStorage.clear();
});

describe('AI readiness, acknowledgement, budget and prices', () => {
    it('saves a ticked acknowledgement with the privacy settings and renders it from the server', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(respond({success: true, code: 'privacy_saved', uiState: readyState()}))
            .mockResolvedValueOnce(respond({success: true, code: 'acknowledgement_saved', uiState: readyState({
                readiness: {licence: 'ok', provider: 'ok', key: 'ok', model: 'ok', acknowledgement: 'ok', budget: 'ok'},
                acknowledgement: {confirmed: true, provider: 'openai', providerLabel: 'OpenAI', byName: 'Ada Admin', atDate: '2 Oct 2026'},
            })}));
        vi.stubGlobal('fetch', fetchMock);
        const root = createRoot();

        root.querySelector('#ack').checked = true;
        click(root, 'aqg-ai-save-privacy');
        await flush();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[1][0]).toBe('/ack');
        expect(bodyOf(fetchMock.mock.calls[1])).toEqual({siteIdentifier: 'main', provider: 'openai', confirmed: '1'});
        expect(root.querySelector('[data-aqg-ai-ack-status="true"]').textContent)
            .toBe('Confirmed by Ada Admin on 2 Oct 2026 · applies to OpenAI on this site only.');
        expect(root.querySelector('#ack').disabled).toBe(true);
        expect(root.querySelector('[data-aqg-ai-ack-blocked="true"]').hidden).toBe(true);
        const ack = root.querySelector('[data-ready-item="acknowledgement"]');
        expect(ack.dataset.state).toBe('ok');
        expect(ack.textContent).toContain('Privacy acknowledged');
    });

    it('does not send an acknowledgement that was not ticked', async () => {
        const fetchMock = vi.fn().mockResolvedValue(respond({success: true, code: 'privacy_saved', uiState: readyState()}));
        vi.stubGlobal('fetch', fetchMock);
        const root = createRoot();

        click(root, 'aqg-ai-save-privacy');
        await flush();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const ack = root.querySelector('[data-ready-item="acknowledgement"]');
        expect(ack.dataset.state).toBe('missing');
        expect(ack.textContent).toContain('Acknowledge privacy for OpenAI');
    });

    it('shows a blocking and a warning budget item from the policy', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(respond({success: true, code: 'privacy_saved', uiState: readyState({
                readiness: {licence: 'ok', provider: 'ok', key: 'ok', model: 'ok', acknowledgement: 'ok', budget: 'missing'},
                budget: {state: 'reached', blocking: true, percent: 100, budgetLabel: '$50.00'},
            })}))
            .mockResolvedValueOnce(respond({success: true, code: 'privacy_saved', uiState: readyState({
                readiness: {licence: 'ok', provider: 'ok', key: 'ok', model: 'ok', acknowledgement: 'ok', budget: 'warn'},
                budget: {state: 'warning', blocking: false, percent: 82, budgetLabel: '$50.00'},
            })}));
        vi.stubGlobal('fetch', fetchMock);
        const root = createRoot();
        const budget = root.querySelector('[data-ready-item="budget"]');

        click(root, 'aqg-ai-save-privacy');
        await flush();
        expect(budget.dataset.state).toBe('missing');
        expect(budget.textContent).toContain('Monthly budget reached — AI is paused for editors');

        click(root, 'aqg-ai-save-privacy');
        await flush();
        expect(budget.dataset.state).toBe('warn');
        expect(budget.querySelector('.aqg-ai-ready__icon').textContent).toBe('!');
        expect(budget.textContent).toContain('Budget: 82 % of $50.00');
    });

    it('requires an explicit hard-stop choice before a budget is sent', async () => {
        const fetchMock = vi.fn().mockResolvedValue(respond({success: true, code: 'budget_saved', uiState: readyState()}));
        vi.stubGlobal('fetch', fetchMock);
        const root = createRoot();

        root.querySelector('#budget').value = '50';
        click(root, 'aqg-ai-save-limits');
        await flush();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(root.querySelector('[data-aqg-ai-status="true"]').textContent).toContain('Choose whether AI pauses.');
        expect(document.activeElement).toBe(root.querySelector('#stop-on'));
        // Product acceptance: the shared status line is far above the limits; the error is also shown at the limits.
        const inline = root.querySelector('[data-aqg-ai-limits-error="true"]');
        expect(inline?.getAttribute('role')).toBe('alert');
        expect(inline?.textContent).toContain('Choose whether AI pauses.');
        expect(inline?.hidden).toBe(false);
    });

    it('sends the chosen hard stop and the warning threshold as typed', async () => {
        const fetchMock = vi.fn().mockResolvedValue(respond({success: true, code: 'budget_saved', uiState: readyState()}));
        vi.stubGlobal('fetch', fetchMock);
        const root = createRoot();

        root.querySelector('#budget').value = '50';
        root.querySelector('#warn').value = '80';
        root.querySelector('#stop-off').checked = true;
        click(root, 'aqg-ai-save-limits');
        await flush();

        expect(fetchMock.mock.calls[0][0]).toBe('/budget');
        expect(bodyOf(fetchMock.mock.calls[0])).toEqual({siteIdentifier: 'main', budget: '50', warningPercent: '80', hardStop: '0'});
    });

    it('sends the budget alert e-mail with the limits and shows a rejected address (F-39)', async () => {
        const fetchMock = vi.fn().mockResolvedValue(respond({success: false, code: 'invalid_alert_email', uiState: readyState()}, 422));
        vi.stubGlobal('fetch', fetchMock);
        const root = createRoot();
        root.dataset.messageInvalidAlertEmail = 'Enter one valid e-mail address, or leave the field empty.';
        root.insertAdjacentHTML('beforeend', '<label for="alert">Budget alert e-mail</label><input id="alert" type="email" data-aqg-ai-budget-alert-email="true" value="">');

        root.querySelector('#budget').value = '50';
        root.querySelector('#warn').value = '80';
        root.querySelector('#stop-on').checked = true;
        root.querySelector('#alert').value = '  a@example.com, b@example.com ';
        click(root, 'aqg-ai-save-limits');
        await flush();

        expect(bodyOf(fetchMock.mock.calls[0])).toEqual({siteIdentifier: 'main', budget: '50', warningPercent: '80', hardStop: '1', alertEmail: 'a@example.com, b@example.com'});
        expect(root.querySelector('[data-aqg-ai-status="true"]').textContent).toContain('Enter one valid e-mail address, or leave the field empty.');
    });

    it('never offers to enter a price and shows the selected model price from the server', () => {
        const root = createRoot();

        expect(root.querySelector('[data-action^="aqg-ai-price-"]')).toBeNull();
        expect(root.querySelector('input[name*="price"], [data-aqg-ai-price-input]')).toBeNull();
        expect(root.querySelector('tr[data-model-id="gpt-5.4-mini"]').textContent).toContain('$0.75 in / $4.50 out');
        expect(root.querySelector('tr[data-model-id="gpt-5.4-mini"]').textContent).toContain('$0.075');
    });

    it('fills the prompt-length tier of a model chosen in place', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(respond({success: true, code: 'model_selected', uiState: readyState({modelSelected: true, selectedModelAvailable: true, selectedModelId: 'claude-haiku-5-5'})}))
            .mockResolvedValueOnce(respond({success: true, code: 'connection_successful', uiState: readyState({
                modelSelected: true, selectedModelAvailable: true, selectedModelId: 'claude-haiku-5-5', selectedModelLabel: 'Claude Haiku 5.5',
                availableModels: [{id: 'claude-haiku-5-5', label: 'Claude Haiku 5.5', price: {known: true, inputLabel: '$0.10', outputLabel: '$0.50', cachedInputLabel: '$0.01'}}],
                pricing: {selected: {known: true, state: 'current', inputLabel: '$0.10', outputLabel: '$0.50', cachedInputLabel: '$0.01', verifiedLabel: '2 Nov 2026',
                    longContext: true, longContextThreshold: '100,000', longInputLabel: '$0.50', longOutputLabel: '$2.50'}},
            })}));
        vi.stubGlobal('fetch', fetchMock);
        const root = createRoot();

        click(root, 'aqg-ai-use-model');
        await flush();

        expect(fetchMock.mock.calls.map(([url]) => url)).toEqual(['/select', '/test']);
        const tier = root.querySelector('[data-aqg-ai-price-tier="true"]');
        expect(tier.hidden).toBe(false);
        expect(tier.textContent).toBe('Requests with more than 100,000 input tokens are billed at $0.50 input and $2.50 output per 1M tokens.');
        expect(root.querySelector('[data-aqg-ai-price-summary-text="true"]').textContent).toBe('Claude Haiku 5.5: $0.10 input, $0.50 output, $0.01 cached input per 1M tokens · checked 2 Nov 2026');
    });

    it('never asks the server about prices: they come with the settings state', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        createRoot();
        await flush();

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('a verified connection is not shown as AI ready for editors while an item is missing', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(respond({success: true, code: 'privacy_saved', uiState: readyState({readyForEditors: false})}))
            .mockResolvedValueOnce(respond({success: true, code: 'acknowledgement_saved', uiState: readyState({
                readyForEditors: true,
                readiness: {licence: 'ok', provider: 'ok', key: 'ok', model: 'ok', acknowledgement: 'ok', budget: 'ok'},
                acknowledgement: {confirmed: true, provider: 'openai', providerLabel: 'OpenAI', byName: 'Ada Admin', atDate: '2 Nov 2026'},
            })}));
        vi.stubGlobal('fetch', fetchMock);
        const root = createRoot();

        expect(root.querySelector('[data-aqg-ai-ready-badge-text="true"]').textContent).toBe('AI not active for editors');
        expect(root.querySelector('[data-aqg-ai-not-ready-note="true"]').hidden).toBe(false);

        root.querySelector('[data-aqg-ai-ack-checkbox="true"]').checked = true;
        click(root, 'aqg-ai-save-privacy');
        await flush();

        expect(root.querySelector('[data-aqg-ai-ready-badge-text="true"]').textContent).toBe('AI active for editors');
        expect(root.querySelector('[data-aqg-ai-ready-badge="true"]').className).toContain('tone-ok');
        expect(root.querySelector('[data-aqg-ai-not-ready-note="true"]').hidden).toBe(true);
    });
});
