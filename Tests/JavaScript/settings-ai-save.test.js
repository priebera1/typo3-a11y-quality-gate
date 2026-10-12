// @vitest-environment jsdom

import {afterEach, describe, expect, it, vi} from 'vitest';
import {initializeAiSettings} from '../../Resources/Public/JavaScript/backend/settings-ai.js';

// Regression: saving an OpenAI key showed a success message while the key, model and connection status on the page
// stayed as before. The page now refreshes in place from the authoritative uiState and says what happens next.

const SECRET = 'sk-proj-abcdefghijklmnopqrstuvwxyz0123456789';

const createRoot = () => {
    document.documentElement.lang = 'en';
    document.body.innerHTML = `
        <div data-aqg-ai-settings="true"
             data-save-url="/save" data-select-model-url="/select" data-test-url="/test" data-refresh-models-url="/refresh"
             data-configured="0" data-model-selected="0" data-connection-status="not_configured" data-error-code=""
             data-last-tested-at="0" data-last-verified-at="0" data-refresh-enabled="0" data-test-enabled="0"
             data-message-saved="Saved." data-message-saving-key="Saving"
             data-message-saved-connected="Key saved and connection verified with %s."
             data-message-saved-choose-model="Key saved. Choose one of the available models to finish the setup."
             data-message-saved-not-connected="Key saved, but the connection test failed: %s"
             data-message-api-key-rejected="The key was rejected."
             data-message-model-selected="Model saved." data-message-connection-successful="Connection verified."
             data-label-config-site="Site-specific encrypted key" data-label-config-none="Not configured"
             data-label-not-configured="Not configured" data-label-not-verified="Not verified"
             data-label-connected="Connected" data-label-connection-failed="Connection failed">
            <dd data-aqg-ai-config-source="true">Not configured</dd>
            <dd data-aqg-ai-keyhint="true">—</dd>
            <select data-aqg-ai-model="true"><option value="">Select an OpenAI model</option></select>
            <div data-aqg-ai-status-badge="true"></div>
            <div data-aqg-ai-persistent-status="true"></div>
            <div data-aqg-ai-key-panel="true" data-collapsed="0">
                <div data-aqg-ai-key-summary="true" hidden>Stored</div>
                <div class="aqg-ai-key-panel__field" data-aqg-ai-key-field="true"><input id="aqg-ai-api-key" type="password"></div>
            </div>
            <input data-aqg-ai-site="true" value="main">
            <button type="button" class="is-hidden" data-action="aqg-ai-replace" hidden>Replace key</button>
            <button type="button" data-action="aqg-ai-save">Save</button>
            <button type="button" data-action="aqg-ai-refresh-models" disabled>Refresh</button>
            <button type="button" data-action="aqg-ai-test" disabled>Test</button>
            <div data-aqg-ai-status="true" hidden></div>
        </div>`;
    const root = document.querySelector('[data-aqg-ai-settings="true"]');
    initializeAiSettings(root);
    return root;
};

const state = (overrides = {}) => ({
    configured: true,
    source: 'site',
    keyHint: '6789',
    modelSelected: true,
    selectedModelAvailable: true,
    selectedModelId: 'gpt-4.1-mini',
    selectedModelLabel: 'GPT-4.1 mini',
    availableModels: [{id: 'gpt-4.1-mini', label: 'GPT-4.1 mini'}],
    unsupportedModels: [],
    status: 'connected',
    errorCode: '',
    lastTestedAt: 1_790_000_000,
    lastVerifiedAt: 1_790_000_000,
    actions: {refreshModelsEnabled: true, testConnectionEnabled: true},
    ...overrides,
});

const respond = (body, status = 200) => ({ok: status >= 200 && status < 300, status, json: vi.fn().mockResolvedValue(body)});

const flush = async () => {
    for (let i = 0; i < 8; i++) {
        await Promise.resolve();
    }
};

const save = async () => {
    document.getElementById('aqg-ai-api-key').value = SECRET;
    document.querySelector('[data-action="aqg-ai-save"]').click();
    await flush();
};

afterEach(() => {
    document.body.innerHTML = '';
    delete globalThis.fetch;
});

describe('AI key save', () => {
    it('refreshes key, configuration, model and connection in place after a verified save', async () => {
        createRoot();
        globalThis.fetch = vi.fn().mockResolvedValue(respond({success: true, code: 'configuration_saved', connectionCode: 'connection_successful', uiState: state()}));

        await save();

        const status = document.querySelector('[data-aqg-ai-status="true"]');
        expect(status.textContent).toContain('Key saved and connection verified with GPT-4.1 mini.');
        expect(document.querySelector('[data-aqg-ai-keyhint="true"]').textContent).toBe('••••••••••••6789');
        expect(document.querySelector('[data-aqg-ai-config-source="true"]').textContent).toBe('Site-specific encrypted key');
        expect(document.querySelector('[data-aqg-ai-model="true"]').value).toBe('gpt-4.1-mini');
        expect(document.querySelector('[data-aqg-ai-status-badge="true"]').textContent).toContain('Connected');
        expect(document.querySelector('[data-aqg-ai-key-field="true"]').classList.contains('is-hidden')).toBe(true);
        expect(document.querySelector('[data-aqg-ai-key-summary="true"]').hidden).toBe(false);
        expect(document.querySelector('[data-action="aqg-ai-save"]').classList.contains('is-hidden')).toBe(true);
        expect(document.querySelector('[data-action="aqg-ai-replace"]').hidden).toBe(false);
        expect(document.getElementById('aqg-ai-api-key').value).toBe('');
        expect(document.body.innerHTML).not.toContain(SECRET);
    });

    it('asks for a model and moves focus to it when several models are available', async () => {
        createRoot();
        globalThis.fetch = vi.fn().mockResolvedValue(respond({
            success: true,
            code: 'configuration_saved',
            connectionCode: 'model_not_selected',
            uiState: state({
                modelSelected: false,
                selectedModelAvailable: false,
                selectedModelId: '',
                selectedModelLabel: '',
                availableModels: [{id: 'gpt-5.4-mini', label: 'GPT-5.4 mini'}, {id: 'gpt-5.4-nano', label: 'GPT-5.4 nano'}],
                status: 'not_verified',
                lastVerifiedAt: 0,
                actions: {refreshModelsEnabled: true, testConnectionEnabled: false},
            }),
        }));

        await save();

        expect(document.querySelector('[data-aqg-ai-status="true"]').textContent).toContain('Choose one of the available models');
        expect(document.activeElement).toBe(document.querySelector('[data-aqg-ai-model="true"]'));
        expect([...document.querySelectorAll('[data-aqg-ai-model="true"] option')].map((option) => option.value)).toEqual(['', 'gpt-5.4-mini', 'gpt-5.4-nano']);
    });

    it('reports a failed connection test after the key was saved', async () => {
        createRoot();
        globalThis.fetch = vi.fn().mockResolvedValue(respond({
            success: true,
            code: 'configuration_saved',
            connectionCode: 'api_key_rejected',
            uiState: state({status: 'connection_failed', errorCode: 'api_key_rejected', lastVerifiedAt: 0}),
        }));

        await save();

        const status = document.querySelector('[data-aqg-ai-status="true"]');
        expect(status.textContent).toContain('Key saved, but the connection test failed: The key was rejected.');
        expect(status.getAttribute('role')).toBe('alert');
    });

    it('verifies the connection right after a model is chosen', async () => {
        const root = createRoot();
        root.dataset.configured = '1';
        globalThis.fetch = vi.fn()
            .mockResolvedValueOnce(respond({success: true, code: 'configuration_saved', connectionCode: 'model_not_selected', uiState: state({
                modelSelected: false, selectedModelAvailable: false, selectedModelId: '', status: 'not_verified', lastVerifiedAt: 0,
                availableModels: [{id: 'gpt-5.4-mini', label: 'GPT-5.4 mini'}, {id: 'gpt-5.4-nano', label: 'GPT-5.4 nano'}],
            })}))
            .mockResolvedValueOnce(respond({success: true, code: 'model_selected', uiState: state({selectedModelId: 'gpt-5.4-mini', selectedModelLabel: 'GPT-5.4 mini', status: 'not_verified', availableModels: [{id: 'gpt-5.4-mini', label: 'GPT-5.4 mini'}, {id: 'gpt-5.4-nano', label: 'GPT-5.4 nano'}]})}))
            .mockResolvedValueOnce(respond({success: true, code: 'connection_successful', uiState: state({selectedModelId: 'gpt-5.4-mini', selectedModelLabel: 'GPT-5.4 mini', availableModels: [{id: 'gpt-5.4-mini', label: 'GPT-5.4 mini'}, {id: 'gpt-5.4-nano', label: 'GPT-5.4 nano'}]})}));
        await save();

        const select = document.querySelector('[data-aqg-ai-model="true"]');
        select.value = 'gpt-5.4-mini';
        select.dispatchEvent(new Event('change'));
        await flush();
        await flush();

        expect(globalThis.fetch.mock.calls.map(([url]) => url)).toEqual(['/save', '/select', '/test']);
        expect(document.querySelector('[data-aqg-ai-status-badge="true"]').textContent).toContain('Connected');
        expect(document.querySelector('[data-aqg-ai-status="true"]').textContent).toContain('Connection verified.');
    });
});
