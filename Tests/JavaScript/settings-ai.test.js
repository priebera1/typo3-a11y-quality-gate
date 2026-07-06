// @vitest-environment jsdom

import {beforeEach, describe, expect, it, vi} from 'vitest';
import {initializeAiSettings} from '../../Resources/Public/JavaScript/backend/settings-ai.js';

const createRoot = () => {
    document.documentElement.lang = 'en';
    document.body.innerHTML = `
        <div data-aqg-ai-settings="true"
             data-refresh-models-url="/refresh"
             data-test-url="/test"
             data-configured="1"
             data-model-selected="1"
             data-connection-status="not_verified"
             data-error-code=""
             data-last-tested-at="0"
             data-last-verified-at="0"
             data-refresh-enabled="1"
             data-test-enabled="1"
             data-message-models-refreshed="Available OpenAI models were refreshed."
             data-message-model-not-permitted="Model not permitted message"
             data-message-connection-rate-limited="Rate limited message"
             data-message-openai-service-failure="Service failure message"
             data-message-connection-failed="Connection failed message"
             data-message-no-supported-models="No supported models message"
             data-message-request-failed="Request failed message"
             data-message-refreshing-models="Refreshing models"
             data-message-testing-connection="Testing connection"
             data-message-connection-successful="Connection successful"
             data-message-connection-failed-persistent="Persistent connection failure"
             data-message-connection-verified="Verified"
             data-label-not-configured="Not configured"
             data-label-not-verified="Not verified"
             data-label-connected="Connected"
             data-label-connection-failed="Connection failed"
             data-label-never="Never"
             data-label-error-code="Error code">
            <select data-aqg-ai-model="true">
                <option value="">Select an OpenAI model</option>
                <option value="gpt-4.1-mini" selected>GPT-4.1 mini — gpt-4.1-mini</option>
            </select>
            <details class="aqg-ai-unsupported-models is-hidden"
                     data-aqg-ai-unsupported-models="true"
                     hidden>
                <summary>Available from OpenAI, but not yet supported by AQG (<span data-aqg-ai-unsupported-count="true">0</span>)</summary>
                <ul data-aqg-ai-unsupported-list="true"></ul>
            </details>
            <div data-aqg-ai-status-badge="true"><span class="aqg-status-badge">Not verified</span></div>
            <div data-aqg-ai-last-tested="true" data-timestamp="0">Never</div>
            <div data-aqg-ai-last-verified="true" data-timestamp="0">Never</div>
            <div data-aqg-ai-persistent-status="true"></div>
            <input data-aqg-ai-site="true" value="main">
            <button type="button" data-action="aqg-ai-refresh-models">Refresh</button>
            <button type="button" data-action="aqg-ai-test">Test connection</button>
            <div data-aqg-ai-status="true" hidden></div>
        </div>
    `;

    const root = document.querySelector('[data-aqg-ai-settings="true"]');
    initializeAiSettings(root);
    return root;
};

const failureState = (errorCode, responseStatus) => ({
    configured: true,
    modelSelected: true,
    selectedModelAvailable: true,
    selectedModelId: 'gpt-4.1-mini',
    availableModels: [{id: 'gpt-4.1-mini', label: 'GPT-4.1 mini'}],
    unsupportedModels: [],
    status: 'connection_failed',
    errorCode,
    lastTestedAt: 1_720_000_000,
    lastVerifiedAt: 0,
    responseStatus,
    actions: {
        refreshModelsEnabled: true,
        testConnectionEnabled: false,
    },
});

const modelDiscoveryState = (unsupportedModels, availableModels = [
    {id: 'gpt-5.4-mini', label: 'GPT-5.4 mini'},
]) => ({
    configured: true,
    modelSelected: availableModels.length > 0,
    selectedModelAvailable: availableModels.length > 0,
    selectedModelId: availableModels[0]?.id || '',
    availableModels,
    unsupportedModels,
    status: 'not_verified',
    errorCode: '',
    lastTestedAt: 0,
    lastVerifiedAt: 0,
    responseStatus: 200,
    actions: {
        refreshModelsEnabled: true,
        testConnectionEnabled: availableModels.length > 0,
    },
});

const mockRefreshResponses = (...states) => {
    const mock = vi.fn();
    states.forEach((state) => {
        mock.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: vi.fn().mockResolvedValue({
                success: true,
                code: 'models_refreshed',
                selectedModelId: state.selectedModelId,
                modelCount: state.availableModels.length,
                uiState: state,
            }),
        });
    });
    globalThis.fetch = mock;
    return mock;
};

const clickRefresh = async (root, expectedRequestCount = 1) => {
    const button = root.querySelector('[data-action="aqg-ai-refresh-models"]');
    button.click();

    await vi.waitFor(() => {
        expect(globalThis.fetch).toHaveBeenCalledTimes(expectedRequestCount);
        expect(button.getAttribute('aria-busy')).toBe('false');
    });
};

const unsupportedIds = (root) => [
    ...root.querySelectorAll('[data-aqg-ai-unsupported-list="true"] code'),
].map((code) => code.textContent);

const modelOptionValues = (root) => [
    ...root.querySelector('[data-aqg-ai-model="true"]').options,
].map((option) => option.value);

const modelState = ({
    availableModels = [],
    selectedModelId = '',
    modelSelected = false,
    selectedModelAvailable = false,
    unsupportedModels = [],
    status = 'not_verified',
    errorCode = '',
    lastVerifiedAt = 0,
    testConnectionEnabled = false,
} = {}) => ({
    configured: true,
    modelSelected,
    selectedModelAvailable,
    selectedModelId,
    availableModels,
    unsupportedModels,
    status,
    errorCode,
    lastTestedAt: 0,
    lastVerifiedAt,
    responseStatus: 200,
    actions: {
        refreshModelsEnabled: true,
        testConnectionEnabled,
    },
});

const mockRefreshPayloads = (...payloads) => {
    const mock = vi.fn();
    payloads.forEach((payload) => {
        mock.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: vi.fn().mockResolvedValue(payload),
        });
    });
    globalThis.fetch = mock;
    return mock;
};

const cases = [
    [403, 'model_not_permitted', 'Model not permitted message'],
    [429, 'connection_rate_limited', 'Rate limited message'],
    [500, 'openai_service_failure', 'Service failure message'],
    [200, 'connection_test_failed', 'Connection failed message'],
];

describe('AI settings summary state renderer', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    it.each(cases)(
        'renders HTTP %s failure from the shared uiState object',
        async (httpStatus, code, expectedMessage) => {
            const root = createRoot();
            globalThis.fetch = vi.fn().mockResolvedValue({
                ok: httpStatus >= 200 && httpStatus < 300,
                status: httpStatus,
                json: vi.fn().mockResolvedValue({
                    success: false,
                    code,
                    uiState: failureState(code, httpStatus),
                }),
            });

            root.querySelector('[data-action="aqg-ai-test"]').click();

            await vi.waitFor(() => {
                expect(root.dataset.responseStatus).toBe(String(httpStatus));
            });

            expect(root.querySelector('[data-aqg-ai-status="true"]').textContent).toContain(expectedMessage);
            expect(root.querySelector('[data-aqg-ai-status-badge="true"]').textContent).toContain('Connection failed');
            expect(root.querySelector('[data-aqg-ai-persistent-status="true"] code').textContent).toBe(code);
            expect(root.querySelector('[data-aqg-ai-last-tested="true"]').dataset.timestamp).toBe('1720000000');
            expect(root.querySelector('[data-aqg-ai-last-verified="true"]').dataset.timestamp).toBe('0');
            expect(root.querySelector('[data-aqg-ai-last-verified="true"]').textContent).toBe('Never');
            expect(root.querySelector('[data-action="aqg-ai-test"]').disabled).toBe(true);
        },
    );

    it('uses the same renderer for a successful connected response', async () => {
        const root = createRoot();
        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: vi.fn().mockResolvedValue({
                success: true,
                code: 'connection_successful',
                uiState: {
                    ...failureState('', 200),
                    status: 'connected',
                    errorCode: '',
                    lastVerifiedAt: 1_720_000_000,
                    actions: {
                        refreshModelsEnabled: true,
                        testConnectionEnabled: true,
                    },
                },
            }),
        });

        root.querySelector('[data-action="aqg-ai-test"]').click();

        await vi.waitFor(() => {
            expect(root.dataset.connectionStatus).toBe('connected');
        });

        expect(root.querySelector('[data-aqg-ai-status-badge="true"]').textContent).toContain('Connected');
        expect(root.querySelector('[data-aqg-ai-persistent-status="true"] code')).toBeNull();
        expect(root.querySelector('[data-aqg-ai-last-verified="true"]').dataset.timestamp).toBe('1720000000');
        expect(root.querySelector('[data-action="aqg-ai-test"]').disabled).toBe(false);
    });
});

describe('AI settings unsupported model renderer', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    it('renders one unsupported model and updates the heading count', async () => {
        const root = createRoot();
        mockRefreshResponses(modelDiscoveryState(['gpt-4o-mini']));

        await clickRefresh(root);

        const details = root.querySelector('[data-aqg-ai-unsupported-models="true"]');
        expect(details.hidden).toBe(false);
        expect(details.classList.contains('is-hidden')).toBe(false);
        expect(root.querySelector('[data-aqg-ai-unsupported-count="true"]').textContent).toBe('1');
        expect(unsupportedIds(root)).toEqual(['gpt-4o-mini']);
    });

    it('renders multiple unsupported models in stable response order', async () => {
        const root = createRoot();
        mockRefreshResponses(modelDiscoveryState(['gpt-4o-mini', 'text-embedding-3-small', 'whisper-1']));

        await clickRefresh(root);

        expect(root.querySelector('[data-aqg-ai-unsupported-count="true"]').textContent).toBe('3');
        expect(unsupportedIds(root)).toEqual(['gpt-4o-mini', 'text-embedding-3-small', 'whisper-1']);
    });

    it('hides the unsupported block for an empty list', async () => {
        const root = createRoot();
        mockRefreshResponses(modelDiscoveryState([]));

        await clickRefresh(root);

        const details = root.querySelector('[data-aqg-ai-unsupported-models="true"]');
        expect(details.hidden).toBe(true);
        expect(details.classList.contains('is-hidden')).toBe(true);
        expect(root.querySelector('[data-aqg-ai-unsupported-count="true"]').textContent).toBe('0');
        expect(unsupportedIds(root)).toEqual([]);
    });

    it('clears stale entries when the list changes from non-empty to empty', async () => {
        const root = createRoot();
        mockRefreshResponses(
            modelDiscoveryState(['gpt-4o-mini']),
            modelDiscoveryState([]),
        );

        await clickRefresh(root, 1);
        expect(unsupportedIds(root)).toEqual(['gpt-4o-mini']);

        await clickRefresh(root, 2);
        expect(root.querySelector('[data-aqg-ai-unsupported-models="true"]').hidden).toBe(true);
        expect(root.querySelector('[data-aqg-ai-unsupported-count="true"]').textContent).toBe('0');
        expect(unsupportedIds(root)).toEqual([]);
    });

    it('replaces the old unsupported list with the new list', async () => {
        const root = createRoot();
        mockRefreshResponses(
            modelDiscoveryState(['gpt-4o-mini', 'old-model']),
            modelDiscoveryState(['new-model', 'whisper-1']),
        );

        await clickRefresh(root, 1);
        expect(unsupportedIds(root)).toEqual(['gpt-4o-mini', 'old-model']);

        await clickRefresh(root, 2);
        expect(root.querySelector('[data-aqg-ai-unsupported-count="true"]').textContent).toBe('2');
        expect(unsupportedIds(root)).toEqual(['new-model', 'whisper-1']);
    });

    it('normalizes whitespace and removes duplicate unsupported model IDs', async () => {
        const root = createRoot();
        mockRefreshResponses(modelDiscoveryState([
            'gpt-4o-mini',
            ' gpt-4o-mini ',
            '',
            'whisper-1',
            'whisper-1',
        ]));

        await clickRefresh(root);

        expect(root.querySelector('[data-aqg-ai-unsupported-count="true"]').textContent).toBe('2');
        expect(unsupportedIds(root)).toEqual(['gpt-4o-mini', 'whisper-1']);
    });

    it('renders special HTML characters as text without creating elements', async () => {
        const root = createRoot();
        const unsafeModelId = 'model-<img src=x onerror=alert(1)>&"';
        mockRefreshResponses(modelDiscoveryState([unsafeModelId]));

        await clickRefresh(root);

        const details = root.querySelector('[data-aqg-ai-unsupported-models="true"]');
        expect(unsupportedIds(root)).toEqual([unsafeModelId]);
        expect(details.querySelector('img')).toBeNull();
        expect(details.querySelector('script')).toBeNull();
    });

    it('never adds unsupported models to the selectable model options', async () => {
        const root = createRoot();
        mockRefreshResponses(modelDiscoveryState(
            ['gpt-4o-mini', 'text-embedding-3-small'],
            [{id: 'gpt-5.4-mini', label: 'GPT-5.4 mini'}],
        ));

        await clickRefresh(root);

        const optionValues = [...root.querySelector('[data-aqg-ai-model="true"]').options]
            .map((option) => option.value);
        expect(optionValues).toEqual(['', 'gpt-5.4-mini']);
        expect(optionValues).not.toContain('gpt-4o-mini');
        expect(optionValues).not.toContain('text-embedding-3-small');
    });
});

describe('AI settings model select renderer', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    it('preserves a valid server-rendered selection during initial normalization', () => {
        const root = createRoot();

        expect(modelOptionValues(root)).toEqual(['', 'gpt-4.1-mini']);
        expect(root.querySelector('[data-aqg-ai-model="true"]').value).toBe('gpt-4.1-mini');
    });

    it('rebuilds one selected model into an empty list using only the placeholder', async () => {
        const root = createRoot();
        const select = root.querySelector('[data-aqg-ai-model="true"]');
        mockRefreshPayloads(
            {
                success: true,
                code: 'models_refreshed',
                uiState: modelState({
                    availableModels: [{id: 'gpt-5.4-mini', label: 'GPT-5.4 mini'}],
                    selectedModelId: 'gpt-5.4-mini',
                    modelSelected: true,
                    selectedModelAvailable: true,
                    testConnectionEnabled: true,
                }),
            },
            {
                success: false,
                code: 'no_supported_models',
                uiState: modelState({
                    unsupportedModels: ['gpt-4o-mini'],
                    status: 'no_supported_models',
                    errorCode: 'no_supported_models',
                }),
            },
        );

        await clickRefresh(root, 1);
        expect(modelOptionValues(root)).toEqual(['', 'gpt-5.4-mini']);
        expect(select.value).toBe('gpt-5.4-mini');

        await clickRefresh(root, 2);
        expect(modelOptionValues(root)).toEqual(['']);
        expect(select.value).toBe('');
        expect(select.querySelector('option[value="gpt-5.4-mini"]')).toBeNull();
        expect(unsupportedIds(root)).toEqual(['gpt-4o-mini']);
        expect(root.querySelector('[data-action="aqg-ai-test"]').disabled).toBe(true);
    });

    it('does not select a selectedModelId that is absent from availableModels', async () => {
        const root = createRoot();
        mockRefreshResponses(modelState({
            availableModels: [{id: 'gpt-4.1-mini', label: 'GPT-4.1 mini'}],
            selectedModelId: 'gpt-5.4-mini',
            modelSelected: true,
            selectedModelAvailable: true,
            testConnectionEnabled: true,
        }));

        await clickRefresh(root);

        expect(modelOptionValues(root)).toEqual(['', 'gpt-4.1-mini']);
        expect(root.querySelector('[data-aqg-ai-model="true"]').value).toBe('');
    });

    it('ignores an old DOM selection when modelSelected is false', async () => {
        const root = createRoot();
        mockRefreshResponses(modelState({
            availableModels: [{id: 'gpt-4.1-mini', label: 'GPT-4.1 mini'}],
            selectedModelId: 'gpt-4.1-mini',
            modelSelected: false,
            selectedModelAvailable: true,
            testConnectionEnabled: false,
        }));

        await clickRefresh(root);

        expect(modelOptionValues(root)).toEqual(['', 'gpt-4.1-mini']);
        expect(root.querySelector('[data-aqg-ai-model="true"]').value).toBe('');
        expect(root.querySelector('[data-action="aqg-ai-test"]').disabled).toBe(true);
    });

    it('ignores an old DOM selection when selectedModelAvailable is false', async () => {
        const root = createRoot();
        mockRefreshResponses(modelState({
            availableModels: [{id: 'gpt-4.1-mini', label: 'GPT-4.1 mini'}],
            selectedModelId: 'gpt-4.1-mini',
            modelSelected: true,
            selectedModelAvailable: false,
            testConnectionEnabled: false,
        }));

        await clickRefresh(root);

        expect(root.querySelector('[data-aqg-ai-model="true"]').value).toBe('');
        expect(root.querySelector('[data-action="aqg-ai-test"]').disabled).toBe(true);
    });

    it('removes duplicate, empty and invalid available model entries', async () => {
        const root = createRoot();
        mockRefreshResponses(modelState({
            availableModels: [
                {id: 'gpt-5.4-mini', label: 'GPT-5.4 mini'},
                {id: ' gpt-5.4-mini ', label: 'Updated label'},
                {id: '', label: 'Empty ID'},
                {id: 'missing-label', label: ''},
                null,
                'invalid',
                {id: 'gpt-4.1-mini', label: 'GPT-4.1 mini'},
            ],
            selectedModelId: 'gpt-5.4-mini',
            modelSelected: true,
            selectedModelAvailable: true,
            testConnectionEnabled: true,
        }));

        await clickRefresh(root);

        expect(modelOptionValues(root)).toEqual(['', 'gpt-5.4-mini', 'gpt-4.1-mini']);
        expect(root.querySelector('[data-aqg-ai-model="true"]').value).toBe('gpt-5.4-mini');
        expect(root.querySelectorAll('option[value="gpt-5.4-mini"]')).toHaveLength(1);
    });

    it('replaces two old models with one current model', async () => {
        const root = createRoot();
        mockRefreshResponses(
            modelState({
                availableModels: [
                    {id: 'gpt-5.4-mini', label: 'GPT-5.4 mini'},
                    {id: 'gpt-4.1-mini', label: 'GPT-4.1 mini'},
                ],
                selectedModelId: 'gpt-5.4-mini',
                modelSelected: true,
                selectedModelAvailable: true,
                testConnectionEnabled: true,
            }),
            modelState({
                availableModels: [{id: 'gpt-4.1-mini', label: 'GPT-4.1 mini'}],
                selectedModelId: 'gpt-4.1-mini',
                modelSelected: true,
                selectedModelAvailable: true,
                testConnectionEnabled: true,
            }),
        );

        await clickRefresh(root, 1);
        expect(modelOptionValues(root)).toEqual(['', 'gpt-5.4-mini', 'gpt-4.1-mini']);

        await clickRefresh(root, 2);
        expect(modelOptionValues(root)).toEqual(['', 'gpt-4.1-mini']);
        expect(root.querySelector('[data-aqg-ai-model="true"]').value).toBe('gpt-4.1-mini');
        expect(root.querySelector('option[value="gpt-5.4-mini"]')).toBeNull();
    });

    it('replaces one model with a completely different model without carrying selection', async () => {
        const root = createRoot();
        mockRefreshResponses(
            modelState({
                availableModels: [{id: 'gpt-5.4-mini', label: 'GPT-5.4 mini'}],
                selectedModelId: 'gpt-5.4-mini',
                modelSelected: true,
                selectedModelAvailable: true,
                testConnectionEnabled: true,
            }),
            modelState({
                availableModels: [{id: 'gpt-4.1-mini', label: 'GPT-4.1 mini'}],
                selectedModelId: '',
                modelSelected: false,
                selectedModelAvailable: false,
                testConnectionEnabled: false,
            }),
        );

        await clickRefresh(root, 1);
        await clickRefresh(root, 2);

        expect(modelOptionValues(root)).toEqual(['', 'gpt-4.1-mini']);
        expect(root.querySelector('[data-aqg-ai-model="true"]').value).toBe('');
        expect(root.querySelector('option[value="gpt-5.4-mini"]')).toBeNull();
    });

    it('does not restore CONNECTED when a disappeared model reappears', async () => {
        const root = createRoot();
        const select = root.querySelector('[data-aqg-ai-model="true"]');
        const testButton = root.querySelector('[data-action="aqg-ai-test"]');
        mockRefreshPayloads(
            {
                success: true,
                code: 'models_refreshed',
                uiState: modelState({
                    availableModels: [{id: 'gpt-5.4-mini', label: 'GPT-5.4 mini'}],
                    selectedModelId: 'gpt-5.4-mini',
                    modelSelected: true,
                    selectedModelAvailable: true,
                    status: 'connected',
                    lastVerifiedAt: 1_720_000_000,
                    testConnectionEnabled: true,
                }),
            },
            {
                success: false,
                code: 'no_supported_models',
                uiState: modelState({
                    unsupportedModels: ['gpt-4o-mini'],
                    status: 'no_supported_models',
                    errorCode: 'no_supported_models',
                    testConnectionEnabled: false,
                }),
            },
            {
                success: true,
                code: 'models_refreshed',
                uiState: modelState({
                    availableModels: [{id: 'gpt-5.4-mini', label: 'GPT-5.4 mini'}],
                    selectedModelId: '',
                    modelSelected: false,
                    selectedModelAvailable: false,
                    status: 'not_verified',
                    lastVerifiedAt: 0,
                    testConnectionEnabled: false,
                }),
            },
        );

        await clickRefresh(root, 1);
        expect(select.value).toBe('gpt-5.4-mini');
        expect(root.querySelector('[data-aqg-ai-status-badge="true"]').textContent).toContain('Connected');

        await clickRefresh(root, 2);
        expect(modelOptionValues(root)).toEqual(['']);
        expect(select.value).toBe('');
        expect(root.querySelector('[data-aqg-ai-last-verified="true"]').textContent).toBe('Never');
        expect(testButton.disabled).toBe(true);
        expect(unsupportedIds(root)).toEqual(['gpt-4o-mini']);

        await clickRefresh(root, 3);
        expect(modelOptionValues(root)).toEqual(['', 'gpt-5.4-mini']);
        expect(select.value).toBe('');
        expect(root.querySelector('[data-aqg-ai-status-badge="true"]').textContent).toContain('Not verified');
        expect(root.querySelector('[data-aqg-ai-status-badge="true"]').textContent).not.toContain('Connected');
        expect(root.querySelector('[data-aqg-ai-last-verified="true"]').textContent).toBe('Never');
        expect(testButton.disabled).toBe(true);
        expect(unsupportedIds(root)).toEqual([]);
    });

    it('uses actions.testConnectionEnabled as the only source for the Test connection button', async () => {
        const root = createRoot();
        const testButton = root.querySelector('[data-action="aqg-ai-test"]');
        mockRefreshResponses(
            modelState({
                availableModels: [{id: 'gpt-5.4-mini', label: 'GPT-5.4 mini'}],
                selectedModelId: 'gpt-5.4-mini',
                modelSelected: true,
                selectedModelAvailable: true,
                testConnectionEnabled: false,
            }),
            modelState({
                availableModels: [],
                selectedModelId: '',
                modelSelected: false,
                selectedModelAvailable: false,
                testConnectionEnabled: true,
            }),
        );

        await clickRefresh(root, 1);
        expect(testButton.disabled).toBe(true);

        await clickRefresh(root, 2);
        expect(testButton.disabled).toBe(false);
        expect(modelOptionValues(root)).toEqual(['']);
        expect(root.querySelector('[data-aqg-ai-model="true"]').value).toBe('');
    });
});

