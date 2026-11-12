// @vitest-environment jsdom

import {afterEach, describe, expect, it, vi} from 'vitest';
import {modalCalls} from './stubs/modal.js';
import {initializeAiSettings} from '../../Resources/Public/JavaScript/backend/settings-ai.js';

// The model chooser is a radio table (UX Atlas 01 C). This reads it like the former select: value = checked model,
// options = an empty "no model" entry plus one per offered model.
const modelPicker = (root) => ({
    get value() {
        return root.querySelector('[data-aqg-ai-model-option="true"]:checked')?.value || '';
    },
    get options() {
        return [{value: ''}, ...root.querySelectorAll('[data-aqg-ai-model-option="true"]')];
    },
    querySelector(selector) {
        const match = /^option\[value="([^"]+)"\]$/.exec(selector);
        return match ? root.querySelector(`[data-aqg-ai-model-option="true"][value="${match[1]}"]`) : null;
    },
    focus() {
        root.querySelector('[data-aqg-ai-model-option="true"]')?.focus();
    },
});

// AQG 3.0: one provider per site. Switching an active provider is confirmed in a TYPO3 modal; the key never travels
// anywhere except the save request; the readiness list and privacy matrix follow the state.

const COPY = {
    openai: {projectKey: 'OpenAI project key', keyPlaceholder: 'sk-proj-…', selectModel: 'Select an OpenAI model', apiKeyRejected: 'OpenAI rejected the key.'},
    anthropic: {projectKey: 'Anthropic API key', keyPlaceholder: 'sk-ant-…', selectModel: 'Select a Claude model', apiKeyRejected: 'Anthropic rejected the key.'},
};

const createRoot = ({provider = 'openai', chosen = '1', status = 'connected', revalidation = '0', errorCode = ''} = {}) => {
    document.body.innerHTML = `
        <div data-aqg-ai-settings="true"
             data-save-url="/save" data-select-model-url="/select" data-test-url="/test" data-refresh-models-url="/refresh"
             data-select-provider-url="/provider" data-privacy-url="/privacy" data-reload-url="/settings?tab=ai&rulesetSite=main"
             data-provider="${provider}" data-provider-label="${provider === 'anthropic' ? 'Anthropic Claude' : 'OpenAI'}"
             data-source="site" data-key-hint="…6789" data-environment-override="0"
             data-provider-copy='${JSON.stringify(COPY)}'
             data-label-environment-key="Environment key" data-label-config-site="Site key" data-label-config-none="Not configured"
             data-message-provider-switched="%s is now the provider." data-message-switching-provider="Saving provider"
             data-message-api-key-rejected="${provider === 'anthropic' ? 'Anthropic rejected the key.' : 'OpenAI rejected the key.'}"
             data-provider-chosen="${chosen}" data-revalidation-required="${revalidation}" data-licence-allows-ai="1"
             data-configured="1" data-model-selected="1" data-connection-status="${status}" data-error-code="${errorCode}"
             data-last-tested-at="0" data-last-verified-at="0" data-refresh-enabled="1" data-test-enabled="1"
             data-message-provider-unchanged="Already active." data-message-provider-selected="Provider saved."
             data-message-privacy-saved="Privacy saved." data-message-testing-connection="Testing"
             data-message-connection-successful="Connection verified." data-message-invalid-key-format="Not an Anthropic key."
             data-message-provider-changed="Provider changed." data-message-provider-billing-error="Billing problem."
             data-label-switch-title="Switch this site to %s?" data-label-switch-pause="AI pauses until a model of %s passes."
             data-label-switch-keep="The %s key stays stored." data-label-switch-usage="Usage stays attributed."
             data-label-switch-confirm="Switch to %s" data-label-cancel="Cancel"
             data-label-ready-licence-ok="Licence ok" data-label-ready-licence-missing="Licence missing"
             data-label-ready-provider-ok="Provider: %s" data-label-ready-provider-missing="No provider"
             data-label-ready-key-ok="Key stored" data-label-ready-key-missing="No key"
             data-label-ready-model-ok="Model verified: %s" data-label-ready-model-missing="No verified model"
             data-label-ready-model-revalidate="Test again"
             data-message-provider-rejected-key="Provider rejected the key; AI paused." data-message-provider-rejected-model="Model gone; AI paused." data-message-connection-failed-persistent="Last verification failed."
             data-label-ready-model-key-rejected="Key rejected" data-label-ready-model-rejected="Model rejected"
             data-label-revalidate-title-change="Provider changed: test" data-label-revalidate-title-rejected="Paused: provider rejected"
             data-label-revalidate-text-change="Chosen before the provider changed." data-label-revalidate-text-key="Replace the key, then test." data-label-revalidate-text-model="Choose a model, then test."
             data-label-matrix-sent="Sent" data-label-matrix-not-sent="Not sent" data-label-matrix-image-off="Off">
            <ul data-aqg-ai-readiness="true">
                <li data-ready-item="licence"><span class="aqg-ai-ready__icon"></span><span data-ready-text="true"></span></li>
                <li data-ready-item="provider"><span class="aqg-ai-ready__icon"></span><span data-ready-text="true"></span></li>
                <li data-ready-item="key"><span class="aqg-ai-ready__icon"></span><span data-ready-text="true"></span></li>
                <li data-ready-item="model"><span class="aqg-ai-ready__icon"></span><span data-ready-text="true"></span></li>
            </ul>
            <span data-aqg-ai-provider-badge="true">${provider === 'anthropic' ? 'Anthropic Claude' : 'OpenAI'}</span>
            <label><input type="radio" name="aqg-ai-provider" id="p-openai" value="openai" data-aqg-ai-provider-option="true" data-label="OpenAI" ${provider === 'openai' && chosen === '1' ? 'checked' : ''}><span data-aqg-ai-provider-active-badge="true">Active</span></label>
            <span data-aqg-ai-provider-status="openai">Key stored</span>
            <input type="radio" name="aqg-ai-provider" id="p-anthropic" value="anthropic" data-aqg-ai-provider-option="true" data-label="Anthropic Claude" ${provider === 'anthropic' && chosen === '1' ? 'checked' : ''}><span data-aqg-ai-provider-active-badge="true" hidden>Active</span></label>
            <span data-aqg-ai-provider-status="anthropic">No key</span>
            <div data-aqg-ai-provider-config="true">
                <div data-aqg-ai-key-panel="true">
                    <div data-aqg-ai-key-summary="true"><strong data-aqg-ai-copy="projectKey">${provider === 'anthropic' ? 'Anthropic API key' : 'OpenAI project key'}</strong></div>
                    <div data-aqg-ai-key-field="true" class="is-hidden"><label for="aqg-ai-api-key" data-aqg-ai-copy="projectKey">${provider === 'anthropic' ? 'Anthropic API key' : 'OpenAI project key'}</label></div>
                </div>
                <button type="button" data-action="aqg-ai-replace">Replace key</button>
            </div>
            <button type="button" data-action="aqg-ai-select-provider">Save provider</button>
            <div data-aqg-ai-revalidate="true" ${revalidation === '1' ? '' : 'hidden'}><strong data-aqg-ai-revalidate-title="true">Server title</strong><p data-aqg-ai-revalidate-text="true">Server text</p><button type="button" data-action="aqg-ai-revalidate">Test again</button></div>
            <table><tbody data-aqg-ai-model-list="true"><tr data-model-id="claude-haiku-5-5" data-model-label="Claude Haiku 5.5" data-price-known="0"><td><input type="radio" name="aqg-ai-model" value="claude-haiku-5-5" data-aqg-ai-model-option="true" checked></td><td>Claude Haiku 5.5</td><td></td><td></td></tr></tbody></table><button type="button" data-action="aqg-ai-use-model">Use this model</button>
            <input id="aqg-ai-api-key" type="password" data-aqg-ai-copy-attr="placeholder:keyPlaceholder">
            <input data-aqg-ai-site="true" value="main">
            <button type="button" data-action="aqg-ai-save">Save</button>
            <button type="button" data-action="aqg-ai-test">Test</button>
            <input type="checkbox" data-aqg-ai-allow-images="true" checked>
            <input type="checkbox" data-aqg-ai-allow-page-title="true" checked>
            <table><tr><td data-aqg-ai-matrix-image="true">Sent</td><td data-aqg-ai-matrix-title="true">Sent</td><td data-aqg-ai-matrix-title="true">Sent</td></tr></table>
            <button type="button" data-action="aqg-ai-save-privacy">Save privacy</button>
            <div data-aqg-ai-status="true" hidden></div>
            <div data-aqg-ai-persistent-status="true"></div>
        </div>`;
    const root = document.querySelector('[data-aqg-ai-settings="true"]');
    initializeAiSettings(root);
    return root;
};

const respond = (body, status = 200) => ({ok: status >= 200 && status < 300, status, json: vi.fn().mockResolvedValue(body)});
const flush = async () => {
    for (let i = 0; i < 8; i++) {
        await Promise.resolve();
    }
};
const bodyOf = (call) => Object.fromEntries(new URLSearchParams(call[1].body));

afterEach(() => {
    vi.unstubAllGlobals();
    modalCalls.length = 0;
    document.body.innerHTML = '';
});

const anthropicActive = (overrides = {}) => ({
    configured: false, source: 'none', keyHint: '', modelSelected: false, selectedModelAvailable: false,
    selectedModelId: '', availableModels: [], unsupportedModels: [], status: 'not_configured',
    provider: 'anthropic', providerLabel: 'Anthropic Claude', providerChosen: true, licenceAllowsAi: true,
    environmentOverride: false, revalidationRequired: false,
    providers: [
        {id: 'openai', label: 'OpenAI', active: false, configured: true, source: 'site', selectedModelLabel: 'GPT-5.4 nano'},
        {id: 'anthropic', label: 'Anthropic Claude', active: true, configured: false, source: 'none', selectedModelLabel: ''},
    ],
    actions: {refreshModelsEnabled: false, testConnectionEnabled: false},
    ...overrides,
});

const chooseCard = (id) => {
    const option = document.getElementById(id);
    option.checked = true;
    option.dispatchEvent(new Event('change', {bubbles: true}));
};

describe('AI provider choice', () => {
    // Regression (TYPO3 14.3.9): selecting the Anthropic card only checked a radio. The key form still belonged to
    // OpenAI, so an Anthropic key was refused with "This is an Anthropic key, but the site uses OpenAI".
    it('choosing the other card asks for confirmation at once and changes nothing until confirmed', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const root = createRoot();

        chooseCard('p-anthropic');
        await flush();

        expect(modalCalls).toHaveLength(1);
        const {configuration} = modalCalls[0];
        expect(configuration.title).toBe('Switch this site to Anthropic Claude?');
        expect(configuration.content.textContent).toContain('The OpenAI key stays stored.');
        expect(configuration.buttons.map((button) => button.text)).toEqual(['Cancel', 'Switch to Anthropic Claude']);
        expect(fetchMock).not.toHaveBeenCalled();
        expect(root.dataset.provider).toBe('openai');
        expect(root.querySelector('label[for="aqg-ai-api-key"]').textContent).toBe('OpenAI project key');
    });

    it('a confirmed switch updates the key form in place, without a reload, and the key goes to the new provider', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(respond({success: true, code: 'provider_selected', uiState: anthropicActive()}))
            .mockResolvedValueOnce(respond({success: true, code: 'configuration_saved', connectionCode: 'model_not_selected', uiState: anthropicActive({configured: true, source: 'site', keyHint: 'sk-ant-…0000'})}));
        vi.stubGlobal('fetch', fetchMock);
        const assign = vi.fn();
        vi.stubGlobal('location', {assign, reload: assign});
        const root = createRoot();
        document.getElementById('aqg-ai-api-key').value = 'typed-before-switch';

        chooseCard('p-anthropic');
        await flush();
        modalCalls[0].configuration.buttons[1].trigger();
        await flush();

        expect(bodyOf(fetchMock.mock.calls[0])).toEqual({siteIdentifier: 'main', provider: 'anthropic'});
        expect(assign).not.toHaveBeenCalled();
        expect(root.dataset.provider).toBe('anthropic');
        expect(root.querySelector('label[for="aqg-ai-api-key"]').textContent).toBe('Anthropic API key');
        expect(document.getElementById('aqg-ai-api-key').placeholder).toBe('sk-ant-…');
        expect(document.getElementById('aqg-ai-api-key').value).toBe('');
        expect(root.querySelector('.aqg-ai-models__empty td').textContent).toBe('Select a Claude model');
        expect(root.dataset.messageApiKeyRejected).toBe('Anthropic rejected the key.');
        expect(root.querySelector('[data-aqg-ai-provider-badge="true"]').textContent).toBe('Anthropic Claude');
        expect(document.getElementById('p-anthropic').checked).toBe(true);
        expect(document.querySelector('[data-aqg-ai-provider-status="openai"]').textContent).toBe('Key stored · GPT-5.4 nano');
        expect(document.activeElement).toBe(document.getElementById('aqg-ai-api-key'));
        expect(root.querySelector('[data-aqg-ai-status="true"]').textContent).toContain('Anthropic Claude is now the provider.');

        document.getElementById('aqg-ai-api-key').value = 'sk-ant-api03-test';
        root.querySelector('[data-action="aqg-ai-save"]').click();
        await flush();
        expect(bodyOf(fetchMock.mock.calls[1]).provider).toBe('anthropic');
    });

    it('cancelling keeps the active provider selected and focused', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        createRoot();

        chooseCard('p-anthropic');
        await flush();
        modalCalls[0].element.dispatchEvent(new Event('typo3-modal-hidden'));

        expect(fetchMock).not.toHaveBeenCalled();
        expect(document.getElementById('p-openai').checked).toBe(true);
        expect(document.getElementById('p-anthropic').checked).toBe(false);
        expect(document.activeElement).toBe(document.getElementById('p-openai'));
    });

    it('a failed switch restores the active provider', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(respond({success: false, code: 'invalid_provider', uiState: {provider: 'openai', providerLabel: 'OpenAI', providerChosen: true, configured: true, status: 'connected'}}, 422)));
        const root = createRoot();

        chooseCard('p-anthropic');
        await flush();
        modalCalls[0].configuration.buttons[1].trigger();
        await flush();

        expect(root.dataset.provider).toBe('openai');
        expect(document.getElementById('p-openai').checked).toBe(true);
    });

    it('a network failure during a switch leaves the active provider working, not failed', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')));
        const root = createRoot();

        chooseCard('p-anthropic');
        await flush();
        modalCalls[0].configuration.buttons[1].trigger();
        await flush();

        expect(root.dataset.provider).toBe('openai');
        expect(root.dataset.connectionStatus).toBe('connected');
        expect(document.getElementById('p-openai').checked).toBe(true);
        expect(root.querySelector('[data-action="aqg-ai-test"]').disabled).toBe(false);
    });

    it('a provider change made in another tab updates the wording and drops a typed key', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(respond({success: false, code: 'provider_changed', uiState: anthropicActive()}, 409)));
        const root = createRoot();
        document.getElementById('aqg-ai-api-key').value = 'sk-proj-typed-in-stale-tab';

        root.querySelector('[data-action="aqg-ai-save"]').click();
        await flush();

        expect(root.dataset.provider).toBe('anthropic');
        expect(root.querySelector('label[for="aqg-ai-api-key"]').textContent).toBe('Anthropic API key');
        expect(document.getElementById('aqg-ai-api-key').value).toBe('');
        expect(document.getElementById('p-anthropic').checked).toBe(true);
    });

    it('provider cards cannot be switched while a settings request runs', async () => {
        let resolveFetch;
        vi.stubGlobal('fetch', vi.fn().mockReturnValue(new Promise((resolve) => {
            resolveFetch = resolve;
        })));
        const root = createRoot();

        root.querySelector('[data-action="aqg-ai-test"]').click();
        await flush();
        expect(document.getElementById('p-anthropic').disabled).toBe(true);

        resolveFetch(respond({success: true, code: 'connection_successful', uiState: {provider: 'openai', providerLabel: 'OpenAI', providerChosen: true, configured: true, status: 'connected', actions: {refreshModelsEnabled: true, testConnectionEnabled: true}}}));
        await flush();
        expect(document.getElementById('p-anthropic').disabled).toBe(false);
    });

    it('saves the first provider of a new site without a confirmation', async () => {
        const fetchMock = vi.fn().mockResolvedValue(respond({success: true, code: 'provider_selected', uiState: anthropicActive()}));
        vi.stubGlobal('fetch', fetchMock);
        const root = createRoot({chosen: '0', status: 'not_configured'});

        chooseCard('p-anthropic');
        await flush();
        expect(modalCalls).toHaveLength(0);
        root.querySelector('[data-action="aqg-ai-select-provider"]').click();
        await flush();

        expect(modalCalls).toHaveLength(0);
        expect(bodyOf(fetchMock.mock.calls[0]).provider).toBe('anthropic');
        expect(root.dataset.providerChosen).toBe('1');
    });

    it('sends the active provider with a key and shows the format error without keeping the key', async () => {
        const fetchMock = vi.fn().mockResolvedValue(respond({success: false, code: 'invalid_key_format', uiState: {configured: false, provider: 'anthropic', providerChosen: true}}, 422));
        vi.stubGlobal('fetch', fetchMock);
        const root = createRoot({provider: 'anthropic', status: 'not_configured'});

        document.getElementById('aqg-ai-api-key').value = 'sk-proj-not-anthropic';
        root.querySelector('[data-action="aqg-ai-save"]').click();
        await flush();

        expect(bodyOf(fetchMock.mock.calls[0]).provider).toBe('anthropic');
        expect(root.querySelector('[data-aqg-ai-status="true"]').textContent).toContain('Not an Anthropic key.');
        expect(root.innerHTML).not.toContain('sk-proj-not-anthropic');
    });
});

describe('AI readiness and revalidation', () => {
    it('lists why editors do not get AI and clears the revalidation banner after a passing test', async () => {
        const fetchMock = vi.fn().mockResolvedValue(respond({
            success: true,
            code: 'connection_successful',
            uiState: {
                configured: true, modelSelected: true, selectedModelAvailable: true, selectedModelId: 'claude-haiku-5-5',
                selectedModelLabel: 'Claude Haiku 5.5', availableModels: [{id: 'claude-haiku-5-5', label: 'Claude Haiku 5.5'}],
                status: 'connected', provider: 'anthropic', providerLabel: 'Anthropic Claude', providerChosen: true,
                revalidationRequired: false, licenceAllowsAi: true, actions: {refreshModelsEnabled: true, testConnectionEnabled: true},
            },
        }));
        vi.stubGlobal('fetch', fetchMock);
        const root = createRoot({provider: 'anthropic', status: 'not_verified', revalidation: '1'});

        const modelItem = root.querySelector('[data-ready-item="model"]');
        expect(modelItem.dataset.state).toBe('missing');
        expect(modelItem.textContent).toContain('Test again');
        expect(root.querySelector('[data-ready-item="provider"]').textContent).toContain('Provider: Anthropic Claude');

        root.querySelector('[data-action="aqg-ai-revalidate"]').click();
        await flush();

        expect(fetchMock.mock.calls[0][0]).toBe('/test');
        expect(root.querySelector('[data-aqg-ai-revalidate="true"]').hidden).toBe(true);
        expect(modelItem.dataset.state).toBe('ok');
        expect(modelItem.textContent).toContain('Model verified: Claude Haiku 5.5');
    });
});

describe('AI paused after a provider rejection (N-10)', () => {
    it('names a rejected key, not a provider change', () => {
        const root = createRoot({provider: 'openai', status: 'connection_failed', revalidation: '1', errorCode: 'provider_1771002310'});
        expect(root.querySelector('[data-ready-item="model"]').textContent).toContain('Key rejected');
        expect(root.querySelector('[data-ready-item="model"]').textContent).not.toContain('Test again');
        expect(root.querySelector('[data-aqg-ai-revalidate-title="true"]').textContent).toBe('Paused: provider rejected');
        expect(root.querySelector('[data-aqg-ai-revalidate-text="true"]').textContent).toBe('Replace the key, then test.');
        expect(root.querySelector('[data-aqg-ai-persistent-status="true"]').textContent).toContain('Provider rejected the key; AI paused.');
        expect(root.querySelector('[data-aqg-ai-persistent-status="true"]').textContent).not.toContain('Last verification failed.');
    });

    it('names an unavailable model, and keeps the provider-change copy otherwise', () => {
        let root = createRoot({provider: 'openai', status: 'connection_failed', revalidation: '1', errorCode: 'provider_1771002317'});
        expect(root.querySelector('[data-ready-item="model"]').textContent).toContain('Model rejected');
        expect(root.querySelector('[data-aqg-ai-revalidate-text="true"]').textContent).toBe('Choose a model, then test.');
        root = createRoot({provider: 'anthropic', status: 'not_verified', revalidation: '1'});
        expect(root.querySelector('[data-ready-item="model"]').textContent).toContain('Test again');
        expect(root.querySelector('[data-aqg-ai-revalidate-title="true"]').textContent).toBe('Provider changed: test');
    });
});

describe('AI privacy settings', () => {
    it('updates the matrix before saving and sends both switches', async () => {
        const fetchMock = vi.fn().mockResolvedValue(respond({success: true, code: 'privacy_saved', uiState: {}}));
        vi.stubGlobal('fetch', fetchMock);
        const root = createRoot();

        const images = root.querySelector('[data-aqg-ai-allow-images="true"]');
        images.checked = false;
        images.dispatchEvent(new Event('change'));
        const title = root.querySelector('[data-aqg-ai-allow-page-title="true"]');
        title.checked = false;
        title.dispatchEvent(new Event('change'));

        expect(root.querySelector('[data-aqg-ai-matrix-image="true"]').textContent).toBe('Off');
        expect([...root.querySelectorAll('[data-aqg-ai-matrix-title="true"]')].map((cell) => cell.textContent)).toEqual(['Not sent', 'Not sent']);

        root.querySelector('[data-action="aqg-ai-save-privacy"]').click();
        await flush();

        expect(fetchMock.mock.calls[0][0]).toBe('/privacy');
        expect(bodyOf(fetchMock.mock.calls[0])).toEqual({siteIdentifier: 'main', allowImageInput: '0', allowPageTitle: '0'});
        expect(root.querySelector('[data-aqg-ai-status="true"]').textContent).toContain('Privacy saved.');
    });
});
