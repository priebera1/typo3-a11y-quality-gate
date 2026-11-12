import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';

const CONNECTION_STATUSES = new Set([
    'not_configured',
    'not_verified',
    'connected',
    'connection_failed',
]);

const isRecord = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

// Provider wording slots (Ai\Settings\AiSettingsCopy) that live in the root's data-message-* attributes.
const COPY_MESSAGE_KEYS = {
    saved: ['messageSaved'],
    modelsRefreshed: ['messageModelsRefreshed'],
    apiKeyRejected: ['messageApiKeyRejected'],
    insufficientQuota: ['messageInsufficientQuota'],
    billingError: ['messageProviderBillingError'],
    modelUnavailable: ['messageModelUnavailable'],
    modelNotPermitted: ['messageModelNotPermitted'],
    modelNotSelected: ['messageModelNotSelected'],
    invalidModelSelection: ['messageInvalidModelSelection'],
    modelsPermissionDenied: ['messageModelsPermissionDenied'],
    modelsRateLimited: ['messageModelsRateLimited'],
    modelsInvalidResponse: ['messageModelsInvalidResponse'],
    modelsRequestFailed: ['messageModelsRequestFailed'],
    noSupportedModels: ['messageNoSupportedModels'],
    connectionRateLimited: ['messageConnectionRateLimited'],
    invalidProviderRequest: ['messageInvalidProviderRequest'],
    transportFailure: ['messageTransportFailure'],
    serviceFailure: ['messageOpenaiServiceFailure', 'messageProviderServiceFailure'],
    savingKey: ['messageSavingKey'],
    refreshingModels: ['messageRefreshingModels'],
};

const normalizeProviders = (providers) => (Array.isArray(providers) ? providers : [])
    .filter((provider) => isRecord(provider) && String(provider.id || '').trim() !== '')
    .map((provider) => ({
        id: String(provider.id).trim(),
        label: String(provider.label || ''),
        active: Boolean(provider.active),
        configured: Boolean(provider.configured),
        source: String(provider.source || 'none'),
        selectedModelLabel: String(provider.selectedModelLabel || ''),
    }));

const normalizeModelList = (models) => {
    if (!Array.isArray(models)) {
        return [];
    }

    const unique = new Map();
    models.forEach((model) => {
        if (!isRecord(model)) {
            return;
        }
        const id = String(model.id || '').trim();
        const label = String(model.label || '').trim();
        if (id !== '' && label !== '') {
            const price = isRecord(model.price) ? model.price : {};
            unique.set(id, {
                id,
                label,
                price: {
                    known: price.known === true,
                    inputLabel: String(price.inputLabel || ''),
                    outputLabel: String(price.outputLabel || ''),
                    cachedInputLabel: String(price.cachedInputLabel || ''),
                },
            });
        }
    });

    return [...unique.values()];
};

const normalizeUnsupportedModelList = (models) => {
    if (!Array.isArray(models)) {
        return [];
    }

    return [...new Set(models.map((id) => String(id).trim()).filter(Boolean))];
};

export const normalizeAiSettingsUiState = (rawState, fallbackState = {}) => {
    const raw = isRecord(rawState) ? rawState : {};
    const fallback = isRecord(fallbackState) ? fallbackState : {};
    const configured = Boolean(raw.configured ?? fallback.configured ?? false);
    const modelSelected = Boolean(
        raw.modelSelected
        ?? fallback.modelSelected
        ?? false,
    );
    const selectedModelAvailable = Boolean(
        raw.selectedModelAvailable
        ?? fallback.selectedModelAvailable
        ?? false,
    );
    const requestedStatus = String(raw.status ?? fallback.status ?? (configured ? 'not_verified' : 'not_configured'));
    const status = CONNECTION_STATUSES.has(requestedStatus)
        ? requestedStatus
        : (configured ? 'not_verified' : 'not_configured');
    const rawActions = isRecord(raw.actions) ? raw.actions : {};
    const fallbackActions = isRecord(fallback.actions) ? fallback.actions : {};
    const defaultTestEnabled = configured
        && modelSelected
        && selectedModelAvailable
        && status !== 'connection_failed';

    return {
        ...fallback,
        ...raw,
        configured,
        modelSelected,
        selectedModelAvailable,
        selectedModelId: String(raw.selectedModelId ?? fallback.selectedModelId ?? '').trim(),
        status,
        errorCode: String(raw.errorCode ?? fallback.errorCode ?? '').trim(),
        lastTestedAt: Math.max(0, Number.parseInt(String(raw.lastTestedAt ?? fallback.lastTestedAt ?? 0), 10) || 0),
        lastVerifiedAt: Math.max(0, Number.parseInt(String(raw.lastVerifiedAt ?? fallback.lastVerifiedAt ?? 0), 10) || 0),
        responseStatus: Math.max(0, Number.parseInt(String(raw.responseStatus ?? fallback.responseStatus ?? 0), 10) || 0),
        availableModels: normalizeModelList(raw.availableModels ?? fallback.availableModels ?? []),
        unsupportedModels: normalizeUnsupportedModelList(
            raw.unsupportedModels ?? fallback.unsupportedModels ?? [],
        ),
        linkTextSuggestionsEnabled: Boolean(raw.linkTextSuggestionsEnabled ?? fallback.linkTextSuggestionsEnabled ?? false),
        provider: String(raw.provider ?? fallback.provider ?? '').trim(),
        providerLabel: String(raw.providerLabel ?? fallback.providerLabel ?? '').trim(),
        providerChosen: Boolean(raw.providerChosen ?? fallback.providerChosen ?? false),
        revalidationRequired: Boolean(raw.revalidationRequired ?? fallback.revalidationRequired ?? false),
        licenceAllowsAi: Boolean(raw.licenceAllowsAi ?? fallback.licenceAllowsAi ?? false),
        readyForEditors: Boolean(raw.readyForEditors ?? fallback.readyForEditors ?? false),
        verified: status === 'connected',
        selectedModelLabel: String(raw.selectedModelLabel ?? fallback.selectedModelLabel ?? '').trim(),
        source: String(raw.source ?? fallback.source ?? 'none'),
        keyHint: String(raw.keyHint ?? fallback.keyHint ?? ''),
        environmentOverride: Boolean(raw.environmentOverride ?? fallback.environmentOverride ?? false),
        providers: normalizeProviders(raw.providers ?? fallback.providers ?? []),
        actions: {
            refreshModelsEnabled: Boolean(
                rawActions.refreshModelsEnabled
                ?? fallbackActions.refreshModelsEnabled
                ?? configured,
            ),
            testConnectionEnabled: Boolean(
                rawActions.testConnectionEnabled
                ?? fallbackActions.testConnectionEnabled
                ?? defaultTestEnabled,
            ),
        },
    };
};

export const initializeAiSettings = (root) => {
    if (!(root instanceof HTMLElement) || root.dataset.aqgInitialized === '1') {
        return;
    }

    root.dataset.aqgInitialized = '1';

    const transientStatus = root.querySelector('[data-aqg-ai-status="true"]');
    const persistentStatus = root.querySelector('[data-aqg-ai-persistent-status="true"]');
    const lastTested = root.querySelector('[data-aqg-ai-last-tested="true"]');
    const lastVerified = root.querySelector('[data-aqg-ai-last-verified="true"]');
    const keyInput = root.querySelector('#aqg-ai-api-key');
    const modelList = root.querySelector('[data-aqg-ai-model-list="true"]');
    const useModelButton = root.querySelector('[data-action="aqg-ai-use-model"]');
    // The model table is a radio group; the checked radio is the model the administrator wants to use.
    const chosenModelId = () => {
        const checked = modelList instanceof HTMLElement ? modelList.querySelector('[data-aqg-ai-model-option="true"]:checked') : null;
        return checked instanceof HTMLInputElement ? checked.value.trim() : '';
    };
    const unsupportedModels = root.querySelector('[data-aqg-ai-unsupported-models="true"]');
    const unsupportedModelsCount = root.querySelector('[data-aqg-ai-unsupported-count="true"]');
    const unsupportedModelsList = root.querySelector('[data-aqg-ai-unsupported-list="true"]');
    const testButton = root.querySelector('[data-action="aqg-ai-test"]');
    const refreshButton = root.querySelector('[data-action="aqg-ai-refresh-models"]');
    const saveButton = root.querySelector('[data-action="aqg-ai-save"]');
    const replaceButton = root.querySelector('[data-action="aqg-ai-replace"]');
    const keyField = root.querySelector('[data-aqg-ai-key-field="true"]');
    const keySummary = root.querySelector('[data-aqg-ai-key-summary="true"]');
    const keyHint = root.querySelector('[data-aqg-ai-keyhint="true"]');
    const configSource = root.querySelector('[data-aqg-ai-config-source="true"]');
    const linkTextToggle = root.querySelector('[data-aqg-ai-link-text-toggle="true"]');
    const readinessList = root.querySelector('[data-aqg-ai-readiness="true"]');
    const revalidateBanner = root.querySelector('[data-aqg-ai-revalidate="true"]');
    const revalidateButton = root.querySelector('[data-action="aqg-ai-revalidate"]');
    const allowImages = root.querySelector('[data-aqg-ai-allow-images="true"]');
    const allowPageTitle = root.querySelector('[data-aqg-ai-allow-page-title="true"]');
    const providerOptions = [...root.querySelectorAll('[data-aqg-ai-provider-option="true"]')];
    const keyPanel = root.querySelector('[data-aqg-ai-key-panel="true"]');
    let providerCopy = {};
    try {
        const parsed = JSON.parse(root.dataset.providerCopy || '{}');
        providerCopy = isRecord(parsed) ? parsed : {};
    } catch {
        providerCopy = {};
    }
    const fill = (template, value) => String(template || '').replace('%s', String(value));
    // Labels with several arguments use positional placeholders (%1$s) and %% for a literal percent sign.
    const fillAll = (template, ...values) => String(template || '')
        .replace(/%(\d+)\$s/g, (match, index) => String(values[Number(index) - 1] ?? ''))
        .replace(/%s/g, () => String(values[0] ?? ''))
        .replace(/%%/g, '%');
    const flashKey = 'aqg-ai-settings-flash';
    // Budget changes affect several server-rendered sections (usage, limits): the page is reloaded and the result
    // message shown once after the reload.
    const reloadWithMessage = (message) => {
        try {
            window.sessionStorage.setItem(flashKey, message);
        } catch {
            // Without storage the reload still shows the saved state.
        }
        window.location.reload();
    };

    // After a save the stored key, its source and the key panel follow the server's uiState; the secret itself is
    // never part of it, only the last characters the server keeps as a hint.
    const renderKeyState = (state) => {
        // An environment key cannot be entered or replaced here; the key form belongs to site keys only.
        const environment = state.source === 'environment';
        if (keyPanel instanceof HTMLElement) {
            keyPanel.hidden = environment;
        }
        if (environment) {
            [saveButton, replaceButton].forEach((button) => {
                if (button instanceof HTMLElement) {
                    button.hidden = true;
                    button.classList.add('is-hidden');
                }
            });
            if (configSource instanceof HTMLElement) {
                configSource.textContent = root.dataset.labelConfigEnvironment || '';
            }
            if (keyHint instanceof HTMLElement) {
                keyHint.textContent = root.dataset.labelEnvironmentKey || '';
            }
            return;
        }
        const configured = state.configured === true;
        if (keyHint instanceof HTMLElement) {
            const hint = String(state.keyHint || '').trim();
            // Hints since 2.0 read "sk-ant-…x9Q2"; older ones are only the last characters (AiSettingsUiStateBuilder).
            const display = hint.includes('…') ? hint : (hint === 'configured' ? '••••••••' : `••••${hint}`);
            keyHint.textContent = configured && hint !== '' ? display : '—';
        }
        if (configSource instanceof HTMLElement) {
            configSource.textContent = configured ? (root.dataset.labelConfigSite || '') : (root.dataset.labelConfigNone || '');
        }
        if (keySummary instanceof HTMLElement) {
            keySummary.hidden = !configured;
        }
        if (keyField instanceof HTMLElement) {
            keyField.classList.toggle('is-hidden', configured);
        }
        [[saveButton, configured], [replaceButton, !configured]].forEach(([button, hide]) => {
            if (button instanceof HTMLElement) {
                button.hidden = hide;
                button.classList.toggle('is-hidden', hide);
            }
        });
    };

    const messageForCode = (code) => ({
        permission_denied: root.dataset.messagePermissionDenied,
        site_not_found: root.dataset.messageSiteNotFound,
        invalid_configuration: root.dataset.messageInvalidConfiguration,
        configuration_save_failed: root.dataset.messageSaveFailed,
        provider_unavailable: root.dataset.messageProviderUnavailable,
        api_key_rejected: root.dataset.messageApiKeyRejected,
        insufficient_quota: root.dataset.messageInsufficientQuota,
        model_unavailable: root.dataset.messageModelUnavailable,
        model_not_permitted: root.dataset.messageModelNotPermitted,
        model_not_selected: root.dataset.messageModelNotSelected,
        invalid_model_selection: root.dataset.messageInvalidModelSelection,
        model_selection_failed: root.dataset.messageModelSelectionFailed,
        models_permission_denied: root.dataset.messageModelsPermissionDenied,
        models_rate_limited: root.dataset.messageModelsRateLimited,
        models_invalid_response: root.dataset.messageModelsInvalidResponse,
        models_request_failed: root.dataset.messageModelsRequestFailed,
        no_supported_models: root.dataset.messageNoSupportedModels,
        connection_rate_limited: root.dataset.messageConnectionRateLimited,
        invalid_provider_request: root.dataset.messageInvalidProviderRequest,
        structured_output_test_failed: root.dataset.messageStructuredOutputTestFailed,
        transport_failure: root.dataset.messageTransportFailure,
        openai_service_failure: root.dataset.messageOpenaiServiceFailure,
        provider_service_failure: root.dataset.messageProviderServiceFailure,
        provider_billing_error: root.dataset.messageProviderBillingError,
        provider_changed: root.dataset.messageProviderChanged,
        invalid_provider: root.dataset.messageInvalidProvider,
        invalid_key_format: root.dataset.messageInvalidKeyFormat,
        key_for_other_provider: root.dataset.messageKeyForOtherProvider,
        settings_rate_limited: root.dataset.messageSettingsRateLimited,
        connection_test_failed: root.dataset.messageConnectionFailed,
        invalid_budget: root.dataset.messageInvalidBudget,
        invalid_warning_percent: root.dataset.messageInvalidWarningPercent,
        hard_stop_required: root.dataset.messageHardStopRequired,
        invalid_alert_email: root.dataset.messageInvalidAlertEmail,
        internal_error: root.dataset.messageRequestFailed,
    }[code] || root.dataset.messageRequestFailed || '');

    const readInitialState = () => normalizeAiSettingsUiState({
        configured: root.dataset.configured === '1',
        modelSelected: root.dataset.modelSelected === '1',
        selectedModelAvailable: root.dataset.modelSelected === '1',
        selectedModelId: root.dataset.selectedModelId || chosenModelId(),
        selectedModelLabel: root.dataset.selectedModelLabel || '',
        availableModels: modelList instanceof HTMLElement
            ? [...modelList.querySelectorAll('tr[data-model-id]')].map((row) => ({
                id: row.dataset.modelId || '',
                label: row.dataset.modelLabel || row.dataset.modelId || '',
                price: {
                    known: row.dataset.priceKnown === '1',
                    inputLabel: row.dataset.priceInput || '',
                    outputLabel: row.dataset.priceOutput || '',
                    cachedInputLabel: row.dataset.priceCached || '',
                },
            }))
            : [],
        unsupportedModels: unsupportedModelsList instanceof HTMLUListElement
            ? [...unsupportedModelsList.querySelectorAll('code')].map((code) => code.textContent || '')
            : [],
        status: root.dataset.connectionStatus || '',
        errorCode: root.dataset.errorCode || '',
        lastTestedAt: root.dataset.lastTestedAt || 0,
        lastVerifiedAt: root.dataset.lastVerifiedAt || 0,
        linkTextSuggestionsEnabled: root.dataset.linkTextSuggestionsEnabled === '1',
        provider: root.dataset.provider || '',
        providerLabel: root.dataset.providerLabel || '',
        source: root.dataset.source || (root.dataset.configured === '1' ? 'site' : 'none'),
        keyHint: root.dataset.keyHint || '',
        environmentOverride: root.dataset.environmentOverride === '1',
        providerChosen: root.dataset.providerChosen === '1',
        revalidationRequired: root.dataset.revalidationRequired === '1',
        licenceAllowsAi: root.dataset.licenceAllowsAi === '1',
        readyForEditors: root.dataset.readyForEditors === '1',
        actions: {
            refreshModelsEnabled: root.dataset.refreshEnabled === '1',
            testConnectionEnabled: root.dataset.testEnabled === '1',
        },
    });

    let uiState = readInitialState();

    const formatTimestamp = (timestamp) => {
        if (!Number.isInteger(timestamp) || timestamp <= 0) {
            return root.dataset.labelNever || 'Never';
        }
        try {
            return new Intl.DateTimeFormat(root.dataset.locale || document.documentElement.lang || undefined, {
                dateStyle: 'medium',
                timeStyle: 'short',
            }).format(new Date(timestamp * 1000));
        } catch {
            return new Date(timestamp * 1000).toLocaleString();
        }
    };

    const badge = (tone, icon, label) => {
        const element = document.createElement('span');
        element.className = `aqg-ai-badge tone-${tone}`;
        if (icon !== '') {
            const iconElement = document.createElement('span');
            iconElement.className = 'aqg-ai-badge__icon';
            iconElement.setAttribute('aria-hidden', 'true');
            iconElement.textContent = icon;
            element.append(iconElement, ' ');
        }
        element.append(label);
        return element;
    };

    const renderStatusBadge = (state) => {
        const definitions = {
            connected: ['ok', '✓', root.dataset.labelConnected || 'Connected'],
            connection_failed: ['error', '!', root.dataset.labelConnectionFailed || 'Connection failed'],
            not_verified: ['none', '', root.dataset.labelNotVerified || 'Not verified'],
            not_configured: ['none', '', root.dataset.labelNotConfigured || 'Not configured'],
        };
        const [tone, icon, label] = definitions[state.status] || definitions.not_configured;
        root.querySelectorAll('[data-aqg-ai-status-badge="true"]').forEach((holder) => {
            holder.replaceChildren(badge(tone, icon, label));
        });
    };

    // "Connection verified" never means "AI ready for editors": the site badge and the note below the connection
    // follow the server's readiness policy (licence, provider, key, verified model, acknowledgement, budget).
    const renderEditorReadiness = (state) => {
        const ready = state.readyForEditors === true;
        root.dataset.readyForEditors = ready ? '1' : '0';
        const readyBadge = root.querySelector('[data-aqg-ai-ready-badge="true"]');
        if (readyBadge instanceof HTMLElement) {
            readyBadge.className = `aqg-ai-badge ${ready ? 'tone-ok' : 'tone-none'}`;
            const icon = readyBadge.querySelector('.aqg-ai-badge__icon');
            if (icon instanceof HTMLElement) {
                icon.textContent = ready ? '✓' : '–';
            }
            const text = readyBadge.querySelector('[data-aqg-ai-ready-badge-text="true"]');
            if (text instanceof HTMLElement) {
                text.textContent = (ready ? root.dataset.labelReadyActive : root.dataset.labelReadyInactive) || '';
            }
        }
        setHidden(root.querySelector('[data-aqg-ai-not-ready-note="true"]'), !(state.status === 'connected' && !ready));
        const keyBadge = root.querySelector('[data-aqg-ai-key-badge="true"]');
        if (keyBadge instanceof HTMLElement) {
            const stored = state.configured === true;
            keyBadge.className = `aqg-ai-badge ${stored ? 'tone-ok' : 'tone-none'}`;
            const icon = keyBadge.querySelector('.aqg-ai-badge__icon');
            if (icon instanceof HTMLElement) {
                icon.textContent = stored ? '✓' : '–';
            }
            const text = keyBadge.querySelector('[data-aqg-ai-key-badge-text="true"]');
            if (text instanceof HTMLElement) {
                text.textContent = (stored ? root.dataset.labelKeyStored : root.dataset.labelKeyMissing) || '';
            }
        }
        const model = root.querySelector('[data-aqg-ai-connection-model="true"]');
        if (model instanceof HTMLElement) {
            model.textContent = state.selectedModelLabel || state.selectedModelId || '—';
        }
        const modelId = root.querySelector('[data-aqg-ai-connection-model-id="true"]');
        if (modelId instanceof HTMLElement) {
            if (state.selectedModelId) {
                const code = document.createElement('code');
                code.className = 'aqg-ai-code';
                code.textContent = state.selectedModelId;
                modelId.replaceChildren(code);
            } else {
                modelId.textContent = '—';
            }
        }
    };

    // The selected model's automatic price in one line (read only), from the server's pricing state.
    const renderPriceSummary = (state) => {
        const summary = root.querySelector('[data-aqg-ai-price-summary="true"]');
        if (!(summary instanceof HTMLElement)) {
            return;
        }
        const pricing = isRecord(state.pricing) && isRecord(state.pricing.selected) ? state.pricing.selected : null;
        summary.hidden = !(state.modelSelected === true && String(state.selectedModelId || '') !== '');
        if (pricing === null || summary.hidden) {
            return;
        }
        summary.dataset.state = String(pricing.state || 'none');
        const label = state.selectedModelLabel || state.selectedModelId;
        const text = summary.querySelector('[data-aqg-ai-price-summary-text="true"]');
        if (text instanceof HTMLElement) {
            text.textContent = pricing.known === true
                ? fillAll(root.dataset.labelPriceSummary, label, pricing.inputLabel || '', pricing.outputLabel || '', pricing.cachedInputLabel || '', pricing.verifiedLabel || '')
                : fill(root.dataset.labelPriceSummaryNone, label);
        }
        setHidden(summary.querySelector('[data-aqg-ai-price-stale="true"]'), pricing.state !== 'stale');
        const tier = summary.querySelector('[data-aqg-ai-price-tier="true"]');
        if (tier instanceof HTMLElement) {
            tier.hidden = pricing.longContext !== true;
            if (!tier.hidden) {
                tier.textContent = fillAll(root.dataset.labelPriceTier, pricing.longContextThreshold || '', pricing.longInputLabel || '', pricing.longOutputLabel || '');
            }
        }
    };

    const renderTimestamp = (element, timestamp) => {
        if (!(element instanceof HTMLElement)) {
            return;
        }
        element.dataset.timestamp = String(timestamp);
        element.textContent = formatTimestamp(timestamp);
    };

    const renderPersistentStatus = (state) => {
        if (!(persistentStatus instanceof HTMLElement)) {
            return;
        }
        persistentStatus.replaceChildren();
        if (!['connected', 'connection_failed'].includes(state.status)) {
            return;
        }

        const line = document.createElement('div');
        const isError = state.status === 'connection_failed';
        line.className = `aqg-statusline tone-${isError ? 'error' : 'ok'}`;
        line.setAttribute('role', isError ? 'alert' : 'status');

        const icon = document.createElement('span');
        icon.className = 'aqg-statusline__icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = isError ? '!' : '✓';

        const content = document.createElement('div');
        content.className = 'aqg-statusline__content';
        const message = document.createElement('p');
        message.className = 'aqg-statusline__message';
        // N-10: an editor request the provider rejected keeps its own explanation after every re-render.
        const rejected = revalidationReason(state);
        const rejectedText = rejected === 'key' ? root.dataset.messageProviderRejectedKey
            : (rejected === 'model' ? root.dataset.messageProviderRejectedModel : '');
        message.textContent = isError
            ? (state.errorCode === 'models_permission_denied'
                ? messageForCode(state.errorCode)
                : (rejectedText || root.dataset.messageConnectionFailedPersistent || messageForCode(state.errorCode)))
            : (root.dataset.messageConnectionVerified || '');
        content.append(message);

        if (isError && state.errorCode !== '') {
            const codeLine = document.createElement('p');
            codeLine.className = 'aqg-statusline__code';
            codeLine.append(`${root.dataset.labelErrorCode || 'Error code'}: `);
            const code = document.createElement('code');
            code.textContent = state.errorCode;
            codeLine.append(code);
            content.append(codeLine);
        }

        line.append(icon, content);
        persistentStatus.append(line);
    };

    let modelPlaceholder = modelList instanceof HTMLElement
        ? (modelList.querySelector('.aqg-ai-models__empty td')?.textContent || '')
        : '';

    // Every provider-specific text of the tab follows the active provider: labels, help, placeholders, messages.
    const applyProviderCopy = (provider) => {
        const copy = isRecord(providerCopy[provider]) ? providerCopy[provider] : null;
        if (copy === null) {
            return;
        }
        Object.entries(COPY_MESSAGE_KEYS).forEach(([slot, datasetKeys]) => {
            if (typeof copy[slot] === 'string') {
                datasetKeys.forEach((datasetKey) => {
                    root.dataset[datasetKey] = copy[slot];
                });
            }
        });
        root.querySelectorAll('[data-aqg-ai-copy]').forEach((element) => {
            const text = copy[element.getAttribute('data-aqg-ai-copy') || ''];
            if (typeof text === 'string') {
                element.textContent = text;
            }
        });
        root.querySelectorAll('[data-aqg-ai-copy-attr]').forEach((element) => {
            const [attribute, slot] = String(element.getAttribute('data-aqg-ai-copy-attr') || '').split(':');
            if (attribute && typeof copy[slot] === 'string') {
                element.setAttribute(attribute, copy[slot]);
            }
        });
        if (typeof copy.selectModel === 'string') {
            modelPlaceholder = copy.selectModel;
        }
        const emptyCell = modelList instanceof HTMLElement ? modelList.querySelector('.aqg-ai-models__empty td') : null;
        if (emptyCell instanceof HTMLElement) {
            emptyCell.textContent = modelPlaceholder;
        }
    };

    const setHidden = (element, hidden) => {
        if (element instanceof HTMLElement) {
            element.hidden = hidden;
        }
    };

    // The active provider is the server's: cards, badge, configuration area and provider-only notes follow uiState.
    const renderProviderState = (state) => {
        root.dataset.provider = state.provider;
        root.dataset.providerLabel = state.providerLabel;
        root.dataset.providerChosen = state.providerChosen ? '1' : '0';
        const badge = root.querySelector('[data-aqg-ai-provider-badge="true"]');
        if (badge instanceof HTMLElement) {
            badge.textContent = state.providerLabel;
            badge.hidden = !state.providerChosen;
        }
        setHidden(root.querySelector('[data-aqg-ai-provider-config="true"]'), !state.providerChosen);
        setHidden(root.querySelector('[data-aqg-ai-provider-hint="true"]'), state.providerChosen);
        setHidden(root.querySelector('[data-aqg-ai-first-provider="true"]'), state.providerChosen);
        setHidden(root.querySelector('[data-aqg-ai-provider-switch-help="true"]'), !state.providerChosen);
        setHidden(root.querySelector('[data-aqg-ai-envbar="true"]'), !state.environmentOverride);
        root.querySelectorAll('[data-aqg-ai-anthropic-only="true"]').forEach((element) => {
            element.hidden = state.provider !== 'anthropic';
        });
        const cards = new Map(state.providers.map((provider) => [provider.id, provider]));
        providerOptions.forEach((option) => {
            if (!(option instanceof HTMLInputElement)) {
                return;
            }
            const card = cards.get(option.value);
            const active = state.providerChosen && option.value === state.provider;
            option.checked = active;
            setHidden(option.closest('label')?.querySelector('[data-aqg-ai-provider-active-badge="true"]'), !active);
            const status = root.querySelector(`[data-aqg-ai-provider-status="${option.value}"]`);
            if (card && status instanceof HTMLElement) {
                const keyLabel = card.configured
                    ? (card.source === 'environment' ? root.dataset.labelEnvironmentKey : root.dataset.labelReadyKeyOk)
                    : root.dataset.labelReadyKeyMissing;
                status.textContent = [keyLabel || '', card.selectedModelLabel].filter(Boolean).join(' · ');
            }
        });
    };

    // One radio row per model this key may use, with its automatic price. The latest uiState is the complete source of
    // truth: rows and the checked radio are rebuilt from it; an unsaved choice survives only while its model is offered.
    const renderModelOptions = (state) => {
        if (!(modelList instanceof HTMLElement)) {
            return;
        }

        const availableModels = normalizeModelList(state.availableModels);
        const availableModelIds = new Set(availableModels.map((model) => model.id));
        const selectedModelId = String(state.selectedModelId || '').trim();
        const hasSelection = state.modelSelected === true
            && state.selectedModelAvailable === true
            && selectedModelId !== ''
            && availableModelIds.has(selectedModelId);
        const pending = chosenModelId();
        const checkedId = pending !== '' && availableModelIds.has(pending) && modelList.dataset.pendingChoice === '1'
            ? pending
            : (hasSelection ? selectedModelId : '');

        const fragment = document.createDocumentFragment();
        availableModels.forEach((model) => {
            const row = document.createElement('tr');
            row.dataset.modelId = model.id;
            row.dataset.modelLabel = model.label;
            row.className = hasSelection && model.id === selectedModelId ? 'is-selected' : '';

            const pick = document.createElement('td');
            pick.className = 'aqg-ai-models__pick';
            const radio = document.createElement('input');
            radio.type = 'radio';
            radio.name = 'aqg-ai-model';
            radio.id = `aqg-ai-model-${model.id}`;
            radio.value = model.id;
            radio.dataset.aqgAiModelOption = 'true';
            radio.checked = model.id === checkedId;
            pick.append(radio);

            // Labels for the stacked layout on narrow screens, taken from the column headers.
            const headers = [...(modelList.closest('table')?.querySelectorAll('thead th') || [])].map((th) => th.textContent.trim());
            const name = document.createElement('td');
            name.dataset.label = headers[1] || '';
            const nameWrap = document.createElement('span');
            nameWrap.className = 'aqg-ai-modelname';
            const label = document.createElement('label');
            label.htmlFor = radio.id;
            label.textContent = model.label;
            const code = document.createElement('code');
            code.textContent = model.id;
            nameWrap.append(label, code);
            name.append(nameWrap);
            if (hasSelection && model.id === selectedModelId) {
                name.append(' ', badge('info', '', root.dataset.labelModelCurrent || ''));
            }

            const price = document.createElement('td');
            price.className = 'is-num';
            price.dataset.label = headers[2] || '';
            const cached = document.createElement('td');
            cached.className = 'is-num';
            cached.dataset.label = headers[3] || '';
            if (model.price.known) {
                price.textContent = fillAll(root.dataset.labelPricePair, model.price.inputLabel, model.price.outputLabel);
                cached.textContent = model.price.cachedInputLabel;
            } else {
                price.append(badge('warning', '', root.dataset.labelPriceUnknown || ''));
                cached.textContent = '—';
            }
            row.append(pick, name, price, cached);
            fragment.append(row);
        });
        if (availableModels.length === 0) {
            const row = document.createElement('tr');
            row.className = 'aqg-ai-models__empty';
            const cell = document.createElement('td');
            cell.colSpan = 4;
            cell.textContent = state.configured ? modelPlaceholder : (root.dataset.labelModelEmpty || modelPlaceholder);
            row.append(cell);
            fragment.append(row);
        }
        modelList.replaceChildren(fragment);
        modelList.dataset.pendingChoice = '0';
    };

    const renderUnsupportedModels = (state) => {
        if (!(unsupportedModels instanceof HTMLDetailsElement)) {
            return;
        }

        const modelIds = normalizeUnsupportedModelList(state.unsupportedModels);
        const isEmpty = modelIds.length === 0;
        unsupportedModels.hidden = isEmpty;
        unsupportedModels.classList.toggle('is-hidden', isEmpty);
        if (isEmpty) {
            unsupportedModels.open = false;
        }

        if (unsupportedModelsCount instanceof HTMLElement) {
            unsupportedModelsCount.textContent = String(modelIds.length);
        }

        if (!(unsupportedModelsList instanceof HTMLUListElement)) {
            return;
        }

        const fragment = document.createDocumentFragment();
        modelIds.forEach((modelId) => {
            const item = document.createElement('li');
            const code = document.createElement('code');
            code.textContent = modelId;
            item.append(code);
            fragment.append(item);
        });
        unsupportedModelsList.replaceChildren(fragment);
    };

    // The readiness list answers "why do editors (not) get AI?" from the same state the server computes.
    const renderReadiness = (state) => {
        if (!(readinessList instanceof HTMLElement)) {
            return;
        }
        const reason = revalidationReason(state);
        const modelLabel = !state.revalidationRequired
            ? root.dataset.labelReadyModelMissing
            : (reason === 'key'
                ? root.dataset.labelReadyModelKeyRejected
                : (reason === 'model' ? root.dataset.labelReadyModelRejected : root.dataset.labelReadyModelRevalidate));
        // The server's readiness policy decides each item; without it (initial render) the legacy flags do.
        const policy = isRecord(state.readiness) ? state.readiness : null;
        const policyState = (item, fallbackOk) => {
            const value = policy ? String(policy[item] || 'missing') : (fallbackOk ? 'ok' : 'missing');
            return ['ok', 'warn'].includes(value) ? value : 'missing';
        };
        const items = {
            licence: [policyState('licence', state.licenceAllowsAi), root.dataset.labelReadyLicenceOk, root.dataset.labelReadyLicenceMissing],
            provider: [policyState('provider', state.providerChosen), fill(root.dataset.labelReadyProviderOk, state.providerLabel), root.dataset.labelReadyProviderMissing],
            key: [policyState('key', state.configured), root.dataset.labelReadyKeyOk, root.dataset.labelReadyKeyMissing],
            model: [policyState('model', state.verified), fill(root.dataset.labelReadyModelOk, state.selectedModelLabel || state.selectedModelId), modelLabel],
        };
        if (policy && isRecord(state.acknowledgement)) {
            items.acknowledgement = [
                policyState('acknowledgement', false),
                root.dataset.labelReadyAckOk,
                fill(root.dataset.labelReadyAckMissing, state.providerLabel),
            ];
        }
        if (policy && isRecord(state.budget)) {
            const budget = state.budget;
            let budgetLabel = fillAll(root.dataset.labelReadyBudgetUsed, budget.percent ?? 0, budget.budgetLabel || '');
            if (budget.blocking === true) {
                budgetLabel = budget.state === 'no_price'
                    ? fill(root.dataset.labelReadyBudgetUnenforceable, budget.modelLabel || '')
                    : root.dataset.labelReadyBudgetReached;
            } else if (budget.state === 'none') {
                budgetLabel = root.dataset.labelReadyBudgetNone;
            } else if (budget.state === 'no_price') {
                budgetLabel = root.dataset.labelReadyBudgetNoPrice;
            }
            const budgetState = policyState('budget', false);
            items.budget = [budgetState, budgetLabel, budgetLabel];
        }
        readinessList.querySelectorAll('[data-ready-item]').forEach((item) => {
            const definition = items[item.dataset.readyItem || ''];
            if (!definition) {
                return;
            }
            const [itemState, okLabel, missingLabel] = definition;
            item.dataset.state = itemState;
            const icon = item.querySelector('.aqg-ai-ready__icon');
            if (icon instanceof HTMLElement) {
                icon.textContent = {ok: '✓', warn: '!'}[itemState] || '–';
            }
            const text = item.querySelector('[data-ready-text="true"]');
            if (text instanceof HTMLElement) {
                text.textContent = String((itemState === 'missing' ? missingLabel : okLabel) || '');
            }
        });
    };

    // Processing acknowledgement (UX Atlas 01 F): per site and active provider; a provider switch clears it.
    const ackBlock = root.querySelector('[data-aqg-ai-ack="true"]');
    const ackCheckbox = root.querySelector('[data-aqg-ai-ack-checkbox="true"]');
    const renderAcknowledgement = (state) => {
        if (!(ackBlock instanceof HTMLElement) || !isRecord(state.acknowledgement)) {
            return;
        }
        const acknowledgement = state.acknowledgement;
        const confirmed = acknowledgement.confirmed === true;
        ackBlock.dataset.confirmed = confirmed ? '1' : '0';
        const blocked = ackBlock.querySelector('[data-aqg-ai-ack-blocked="true"]');
        if (blocked instanceof HTMLElement) {
            blocked.hidden = confirmed;
        }
        const label = ackBlock.querySelector('[data-aqg-ai-ack-label="true"]');
        if (label instanceof HTMLElement) {
            label.textContent = fill(root.dataset.labelAckLabel, state.providerLabel);
        }
        if (ackCheckbox instanceof HTMLInputElement) {
            // An unsaved tick stays while the provider stays; it never carries over to another provider.
            const providerChanged = (ackBlock.dataset.provider || state.provider) !== state.provider;
            ackCheckbox.checked = confirmed || (ackCheckbox.checked && !providerChanged);
            ackCheckbox.disabled = confirmed;
        }
        ackBlock.dataset.provider = state.provider || '';
        const status = ackBlock.querySelector('[data-aqg-ai-ack-status="true"]');
        if (status instanceof HTMLElement) {
            status.textContent = confirmed
                ? fillAll(root.dataset.labelAckConfirmed, acknowledgement.byName || '', acknowledgement.atDate || '', acknowledgement.providerLabel || '')
                : fill(root.dataset.labelAckHelp, state.providerLabel);
        }
    };

    const renderRevalidation = (state) => {
        if (!(revalidateBanner instanceof HTMLElement)) {
            return;
        }
        const hidden = !state.revalidationRequired;
        revalidateBanner.hidden = hidden;
        revalidateBanner.classList.toggle('is-hidden', hidden);
        if (hidden) {
            return;
        }
        // N-10: after an editor request the provider rejected, the banner names that cause, not a provider change.
        const reason = revalidationReason(state);
        const title = revalidateBanner.querySelector('[data-aqg-ai-revalidate-title="true"]');
        const text = revalidateBanner.querySelector('[data-aqg-ai-revalidate-text="true"]');
        if (title instanceof HTMLElement) {
            title.textContent = (reason === 'change' ? root.dataset.labelRevalidateTitleChange : root.dataset.labelRevalidateTitleRejected) || title.textContent;
        }
        if (text instanceof HTMLElement) {
            const label = reason === 'key' ? root.dataset.labelRevalidateTextKey
                : (reason === 'model' ? root.dataset.labelRevalidateTextModel : root.dataset.labelRevalidateTextChange);
            text.textContent = label || text.textContent;
        }
    };

    // The matrix is the consequence of the switches, so it follows them before they are saved.
    const renderPrivacyMatrix = () => {
        const imagesAllowed = !(allowImages instanceof HTMLInputElement) || allowImages.checked;
        const titleAllowed = !(allowPageTitle instanceof HTMLInputElement) || allowPageTitle.checked;
        root.querySelectorAll('[data-aqg-ai-matrix-image="true"]').forEach((cell) => {
            cell.textContent = imagesAllowed ? (root.dataset.labelMatrixSent || '') : (root.dataset.labelMatrixImageOff || '');
        });
        root.querySelectorAll('[data-aqg-ai-matrix-title="true"]').forEach((cell) => {
            cell.textContent = titleAllowed ? (root.dataset.labelMatrixSent || '') : (root.dataset.labelMatrixNotSent || '');
        });
    };

    const applyEnabledState = (busy = false) => {
        root.querySelectorAll('[data-action]').forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }
            if (busy) {
                button.disabled = true;
                return;
            }
            if (button === refreshButton) {
                button.disabled = !uiState.actions.refreshModelsEnabled;
            } else if (button === testButton || button === revalidateButton) {
                button.disabled = !uiState.actions.testConnectionEnabled;
            } else {
                button.disabled = false;
            }
        });
        if (modelList instanceof HTMLElement) {
            modelList.querySelectorAll('[data-aqg-ai-model-option="true"]').forEach((radio) => {
                radio.disabled = busy || !uiState.configured;
            });
        }
        if (useModelButton instanceof HTMLButtonElement && !busy) {
            useModelButton.disabled = !uiState.configured || !uiState.availableModels.length;
        }
        // No provider switch while another settings request is running.
        providerOptions.forEach((option) => {
            option.disabled = busy;
        });
    };

    /**
     * Section navigation states (privacy, usage, diagnostics) from the server's UI state. Each part is replaced only when
     * the state carries it (every AI-settings response for an administrator does, including refusals, which carry the
     * persisted state); a transport failure keeps what the server said last. Nothing is shown before the server answers.
     */
    const sectionNav = root.querySelector('[data-aqg-ai-sectionnav="true"]');
    const navLabel = (key, fallback = '') => sectionNav?.dataset?.[key] || fallback;
    const fillLabel = (template, ...values) => String(template || '')
        .replace(/%(\d+)\$s/g, (match, index) => String(values[Number(index) - 1] ?? ''))
        .replace(/%s/g, () => String(values[0] ?? ''))
        .replace(/%%/g, '%');
    const usageCard = root.querySelector('[data-aqg-ai-usage="true"]');
    const renderSectionNav = (state) => {
        if (!(sectionNav instanceof HTMLElement)) {
            return;
        }
        const privacy = sectionNav.querySelector('[data-aqg-ai-nav-state="privacy"]');
        if (privacy instanceof HTMLElement && isRecord(state.acknowledgement)) {
            const confirmed = state.acknowledgement.confirmed === true;
            privacy.replaceChildren(confirmed
                ? badge('ok', '✓', navLabel('labelTermsConfirmed'))
                : badge('warning', '!', navLabel('labelTermsMissing')));
        }
        const diagnostics = sectionNav.querySelector('[data-aqg-ai-nav-state="diagnostics"]');
        if (diagnostics instanceof HTMLElement && isRecord(state.pricing) && isRecord(state.pricing.diagnostics)) {
            diagnostics.replaceChildren(state.pricing.diagnostics.anyFailed === true
                ? badge('warning', '!', navLabel('labelPricesFailing'))
                : badge('none', '', navLabel('labelPricesAutomatic')));
        }
        const usage = sectionNav.querySelector('[data-aqg-ai-nav-state="usage"]');
        const budget = isRecord(state.budget) ? state.budget : null;
        const usageState = isRecord(state.usage) ? state.usage : null;
        // The navigation always speaks about the current month (the AJAX state is the current month); a page showing an
        // earlier month keeps its figure, which belongs to that month.
        const showsCurrentMonth = !(usageCard instanceof HTMLElement) || usageCard.dataset.usageCurrent === '1';
        if (usage instanceof HTMLElement && budget !== null && usageState !== null && showsCurrentMonth) {
            if (budget.state === 'reached') {
                usage.replaceChildren(badge(budget.hardStop === true ? 'error' : 'warning', '!', navLabel('labelBudgetReached')));
            } else if (budget.state === 'warning') {
                usage.replaceChildren(badge('warning', '!', fillLabel(navLabel('labelBudgetWarning'), budget.percent ?? 0)));
            } else if (isRecord(usageState.totals)) {
                const totals = usageState.totals;
                const requests = Number(totals.requests ?? 0) || 0;
                const amount = totals.costState === 'unknown'
                    ? navLabel('labelCostUnknown')
                    : `${totals.estimatedIsBound === true || !Number(totals.estimatedMicroUsd) ? '' : '≈ '}${totals.estimatedLabel ?? ''}`;
                const figure = document.createElement('span');
                figure.className = 'aqg-ai-sectionnav__figure';
                figure.textContent = `${amount} · ${fillLabel(navLabel(requests === 1 ? 'labelRequestsSingular' : 'labelRequestsPlural'), requests)}`;
                usage.replaceChildren(figure);
            }
        }
        // The usage report below is server-rendered: when the server now counts other requests for the month shown
        // (e.g. a connection test was recorded), say so instead of leaving stale figures unexplained.
        const stale = usageCard?.querySelector('[data-aqg-ai-usage-stale="true"]');
        if (stale instanceof HTMLElement && usageState !== null && isRecord(usageState.totals)
            && String(usageState.month ?? '') === String(usageCard.dataset.usageMonth ?? '')) {
            stale.hidden = String(usageState.totals.requests ?? '') === String(usageCard.dataset.usageRequests ?? '');
        }
    };

    const renderSummaryState = (nextState) => {
        const previousProvider = uiState?.provider;
        uiState = normalizeAiSettingsUiState(nextState, uiState);
        // Another tab or admin may have switched the site's provider: the server's answer wins, and the wording and
        // key field follow it, so a key typed for the previous provider is never sent as the new provider's key.
        if (previousProvider !== undefined && previousProvider !== uiState.provider) {
            applyProviderCopy(uiState.provider);
            if (keyInput instanceof HTMLInputElement) {
                keyInput.value = '';
            }
        }
        root.dataset.configured = uiState.configured ? '1' : '0';
        root.dataset.modelSelected = uiState.modelSelected ? '1' : '0';
        root.dataset.selectedModelId = uiState.selectedModelId;
        root.dataset.selectedModelLabel = uiState.selectedModelLabel;
        root.dataset.connectionStatus = uiState.status;
        root.dataset.errorCode = uiState.errorCode;
        root.dataset.lastTestedAt = String(uiState.lastTestedAt);
        root.dataset.lastVerifiedAt = String(uiState.lastVerifiedAt);
        root.dataset.responseStatus = String(uiState.responseStatus);
        root.dataset.linkTextSuggestionsEnabled = uiState.linkTextSuggestionsEnabled ? '1' : '0';
        if (linkTextToggle instanceof HTMLInputElement) {
            linkTextToggle.checked = uiState.linkTextSuggestionsEnabled;
        }
        root.dataset.refreshEnabled = uiState.actions.refreshModelsEnabled ? '1' : '0';
        root.dataset.testEnabled = uiState.actions.testConnectionEnabled ? '1' : '0';

        renderProviderState(uiState);
        renderKeyState(uiState);
        renderModelOptions(uiState);
        renderUnsupportedModels(uiState);
        renderStatusBadge(uiState);
        renderTimestamp(lastTested, uiState.lastTestedAt);
        renderTimestamp(lastVerified, uiState.lastVerifiedAt);
        renderPersistentStatus(uiState);
        renderReadiness(uiState);
        renderEditorReadiness(uiState);
        renderPriceSummary(uiState);
        renderAcknowledgement(uiState);
        renderRevalidation(uiState);
        renderSectionNav(uiState);
        applyEnabledState();

        return uiState;
    };

    const show = (message, tone = 'ok') => {
        if (!(transientStatus instanceof HTMLElement)) {
            return;
        }
        const normalizedTone = ['ok', 'error', 'info'].includes(tone) ? tone : 'info';
        const icon = document.createElement('span');
        icon.className = 'aqg-statusline__icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = normalizedTone === 'error' ? '!' : (normalizedTone === 'ok' ? '✓' : '…');

        const content = document.createElement('span');
        content.className = 'aqg-statusline__content';
        content.textContent = message;

        transientStatus.hidden = false;
        transientStatus.className = `aqg-statusline tone-${normalizedTone}`;
        transientStatus.setAttribute('role', normalizedTone === 'error' ? 'alert' : 'status');
        transientStatus.replaceChildren(icon, content);
    };

    const failureState = (code, responseStatus) => normalizeAiSettingsUiState({
        ...uiState,
        status: 'connection_failed',
        errorCode: code,
        lastVerifiedAt: 0,
        responseStatus,
        actions: {
            ...uiState.actions,
            testConnectionEnabled: false,
        },
    }, uiState);

    const send = async (url, values) => {
        if (!url) {
            const state = failureState('internal_error', 0);
            renderSummaryState(state);
            const error = new Error(messageForCode('internal_error'));
            error.code = 'internal_error';
            error.uiState = state;
            throw error;
        }

        let response;
        try {
            response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new URLSearchParams(values),
            });
        } catch {
            const state = failureState('transport_failure', 0);
            renderSummaryState(state);
            const error = new Error(messageForCode('transport_failure'));
            error.code = 'transport_failure';
            error.uiState = state;
            throw error;
        }

        const data = await response.json().catch(() => ({success: false, code: 'internal_error'}));
        const failed = !response.ok || data.success === false;
        const code = String(data.code || (failed ? 'internal_error' : '')).trim();
        const responseUiState = isRecord(data.uiState)
            ? {...data.uiState, responseStatus: response.status}
            : (failed ? failureState(code || 'internal_error', response.status) : {...uiState, responseStatus: response.status});

        renderSummaryState(responseUiState);

        if (failed) {
            const error = new Error(messageForCode(code || 'internal_error'));
            error.code = code;
            error.responseStatus = response.status;
            // Whether the server described its state itself, or the state is a local failure stand-in.
            error.serverState = isRecord(data.uiState);
            error.uiState = uiState;
            throw error;
        }

        return data;
    };

    const setBusy = (activeElement, busy) => {
        applyEnabledState(busy);
        if (!(activeElement instanceof HTMLButtonElement)) {
            return;
        }
        activeElement.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (busy) {
            activeElement.dataset.originalHtml = activeElement.innerHTML;
            const visibleLabel = activeElement.textContent?.trim() || '…';
            activeElement.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';
            const label = document.createElement('span');
            label.textContent = visibleLabel;
            activeElement.append(label);
        } else if (activeElement.dataset.originalHtml) {
            activeElement.innerHTML = activeElement.dataset.originalHtml;
            delete activeElement.dataset.originalHtml;
        }
    };

    const siteIdentifier = () => root.querySelector('[data-aqg-ai-site]')?.value || '';

    if (modelList instanceof HTMLElement) {
        // A choice stays unsaved until "Use this model"; re-renders keep it while the model is still offered.
        modelList.addEventListener('change', (event) => {
            if (event.target instanceof HTMLInputElement && event.target.dataset.aqgAiModelOption === 'true') {
                modelList.dataset.pendingChoice = '1';
            }
        });
    }

    // "Use this model" saves the choice and verifies it right away, so the page shows "Connected" or the reason not.
    const useModel = async (button) => {
        const modelId = chosenModelId();
        if (modelId === '') {
            show(root.dataset.messageChooseModel || messageForCode('model_not_selected'), 'error');
            modelList?.querySelector('[data-aqg-ai-model-option="true"]')?.focus();
            return;
        }
        renderSummaryState({
            ...uiState,
            selectedModelId: modelId,
            selectedModelLabel: uiState.availableModels.find((model) => model.id === modelId)?.label || modelId,
            modelSelected: true,
            selectedModelAvailable: uiState.availableModels.some((model) => model.id === modelId),
            status: 'not_verified',
            errorCode: '',
            lastVerifiedAt: 0,
            actions: {
                ...uiState.actions,
                testConnectionEnabled: false,
            },
        });
        setBusy(button, true);
        try {
            await send(root.dataset.selectModelUrl, {
                siteIdentifier: siteIdentifier(),
                modelId,
            });
            show(root.dataset.messageTestingConnection || root.dataset.messageModelSelected || '', 'info');
            await send(root.dataset.testUrl, {siteIdentifier: siteIdentifier()});
            show(root.dataset.messageConnectionSuccessful || '', 'ok');
        } catch (error) {
            show(error instanceof Error ? error.message : messageForCode('internal_error'), 'error');
        } finally {
            setBusy(button, false);
        }
    };

    if (linkTextToggle instanceof HTMLInputElement) {
        linkTextToggle.addEventListener('change', async () => {
            linkTextToggle.disabled = true;
            try {
                const enabled = linkTextToggle.checked;
                const data = await send(root.dataset.linkTextToggleUrl, {
                    siteIdentifier: siteIdentifier(),
                    enabled: enabled ? '1' : '0',
                });
                renderSummaryState({
                    ...(isRecord(data.uiState) ? data.uiState : uiState),
                    linkTextSuggestionsEnabled: enabled,
                });
                show(enabled
                    ? (root.dataset.messageLinkTextSuggestionsEnabled || '')
                    : (root.dataset.messageLinkTextSuggestionsDisabled || ''), 'ok');
            } catch (error) {
                linkTextToggle.checked = uiState.linkTextSuggestionsEnabled;
                show(error instanceof Error ? error.message : messageForCode('internal_error'), 'error');
            } finally {
                linkTextToggle.disabled = false;
            }
        });
    }

    const optionFor = (provider) => providerOptions.find((option) => option instanceof HTMLInputElement && option.value === provider);

    const restoreProviderChoice = () => {
        providerOptions.forEach((option) => {
            if (option instanceof HTMLInputElement) {
                option.checked = uiState.providerChosen && option.value === uiState.provider;
            }
        });
    };

    // Saving the provider changes the site's active configuration in place: wording, key form, model list and status
    // all come from the server's answer for the new provider. Nothing changes before the server confirmed it.
    const saveProvider = async (trigger, provider, label) => {
        if (trigger instanceof HTMLButtonElement) {
            setBusy(trigger, true);
        } else {
            applyEnabledState(true);
        }
        const previousState = uiState;
        show(root.dataset.messageSwitchingProvider || '', 'info');
        try {
            const data = await send(root.dataset.selectProviderUrl, {siteIdentifier: siteIdentifier(), provider});
            if (keyInput instanceof HTMLInputElement) {
                // A key typed for the previous provider is never sent with the new one.
                keyInput.value = '';
            }
            applyProviderCopy(uiState.provider);
            renderSummaryState(uiState);
            if (data.code === 'provider_unchanged') {
                show(root.dataset.messageProviderUnchanged || '', 'info');
                return;
            }
            show(fill(root.dataset.messageProviderSwitched || root.dataset.messageProviderSelected, label), 'ok');
            const needsKey = uiState.source !== 'environment' && !uiState.configured;
            const focusTarget = needsKey ? keyInput : optionFor(uiState.provider);
            if (focusTarget instanceof HTMLElement) {
                focusTarget.focus();
            }
        } catch (error) {
            // A switch that did not happen leaves the active provider exactly as it was. When the server answered
            // with its own state (JSON error), that state is already rendered; a network or session failure must not
            // mark the still-active provider as failed.
            if (error?.serverState !== true) {
                renderSummaryState(previousState);
            }
            restoreProviderChoice();
            show(error instanceof Error ? error.message : messageForCode('internal_error'), 'error');
        } finally {
            if (trigger instanceof HTMLButtonElement) {
                setBusy(trigger, false);
            } else {
                applyEnabledState();
            }
        }
    };

    // On a site with an active provider, choosing the other card opens the confirmation right away (click, Space or
    // arrow keys); Cancel, Esc or the close button keep the active provider and return focus to its card.
    const confirmProviderSwitch = (option) => {
        const provider = option.value;
        const label = option.dataset.label || provider;
        const content = document.createElement('div');
        [
            fill(root.dataset.labelSwitchPause, label),
            fill(root.dataset.labelSwitchKeep, uiState.providerLabel || uiState.provider),
            root.dataset.labelSwitchUsage || '',
        ].forEach((text) => {
            const paragraph = document.createElement('p');
            paragraph.textContent = text;
            content.append(paragraph);
        });

        let confirmed = false;
        const modal = Modal.advanced({
            title: fill(root.dataset.labelSwitchTitle, label),
            content,
            severity: Severity.warning,
            size: Modal.sizes.small,
            buttons: [
                {
                    text: root.dataset.labelCancel || 'Cancel',
                    active: true,
                    btnClass: 'btn-default',
                    name: 'cancel',
                    trigger: () => Modal.dismiss(),
                },
                {
                    text: fill(root.dataset.labelSwitchConfirm, label),
                    btnClass: 'btn-warning',
                    name: 'switch',
                    trigger: () => {
                        confirmed = true;
                        Modal.dismiss();
                        saveProvider(option, provider, label);
                    },
                },
            ],
        });
        modal?.addEventListener?.('typo3-modal-hidden', () => {
            if (!confirmed) {
                restoreProviderChoice();
                optionFor(uiState.provider)?.focus();
            }
        });
    };

    providerOptions.forEach((option) => {
        if (!(option instanceof HTMLInputElement)) {
            return;
        }
        option.addEventListener('change', () => {
            if (option.checked && uiState.providerChosen && option.value !== uiState.provider) {
                confirmProviderSwitch(option);
            }
        });
    });

    // A site without a provider has nothing to pause: the first choice is saved with "Save provider".
    const chooseFirstProvider = async (button) => {
        const option = providerOptions.find((candidate) => candidate instanceof HTMLInputElement && candidate.checked);
        if (!(option instanceof HTMLInputElement)) {
            providerOptions[0]?.focus();
            return;
        }
        if (uiState.providerChosen && option.value === uiState.provider) {
            show(root.dataset.messageProviderUnchanged || '', 'info');
            return;
        }
        await saveProvider(button, option.value, option.dataset.label || option.value);
    };

    [allowImages, allowPageTitle].forEach((input) => {
        if (input instanceof HTMLInputElement) {
            input.addEventListener('change', renderPrivacyMatrix);
        }
    });

    root.addEventListener('click', async (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const button = target?.closest('[data-action]');
        if (!(button instanceof HTMLButtonElement) || button.disabled) {
            return;
        }
        const action = button.dataset.action;

        if (action === 'aqg-ai-replace') {
            if (keyField instanceof HTMLElement) {
                keyField.hidden = false;
                keyField.classList.remove('is-hidden');
            }
            if (saveButton instanceof HTMLButtonElement) {
                saveButton.hidden = false;
                saveButton.classList.remove('is-hidden');
            }
            button.hidden = true;
            keyInput?.focus();
            return;
        }

        if (action === 'aqg-ai-open-site') {
            const siteSelect = root.querySelector('[data-aqg-ai-site-select="true"]');
            if (siteSelect instanceof HTMLSelectElement && siteSelect.value !== '') {
                window.location.assign(siteSelect.value);
            }
            return;
        }

        if (action === 'aqg-ai-reload-usage') {
            window.location.reload();
            return;
        }

        if (action === 'aqg-ai-open-usage-month') {
            const monthSelect = root.querySelector('[data-aqg-ai-usage-month="true"]');
            if (monthSelect instanceof HTMLSelectElement && monthSelect.value !== '') {
                window.location.assign(monthSelect.value);
            }
            return;
        }

        if (action === 'aqg-ai-select-provider') {
            await chooseFirstProvider(button);
            return;
        }

        if (action === 'aqg-ai-save-limits') {
            const budgetInput = root.querySelector('[data-aqg-ai-budget-input="true"]');
            const warningInput = root.querySelector('[data-aqg-ai-budget-warning="true"]');
            const hardStop = root.querySelector('[data-aqg-ai-hard-stop="true"]:checked');
            const alertEmailInput = root.querySelector('[data-aqg-ai-budget-alert-email="true"]');
            const budget = budgetInput instanceof HTMLInputElement ? budgetInput.value.trim() : '';
            // The answer belongs next to the limits: the shared status line is far above this section, so an error
            // shown only there looked like a click that did nothing (product acceptance).
            const limitsError = (message) => {
                const choice = root.querySelector('.aqg-ai-limits__choice') || button;
                let note = root.querySelector('[data-aqg-ai-limits-error="true"]');
                if (!(note instanceof HTMLElement)) {
                    note = document.createElement('p');
                    note.className = 'aqg-field__error';
                    note.setAttribute('role', 'alert');
                    note.dataset.aqgAiLimitsError = 'true';
                    choice.after(note);
                }
                note.textContent = message;
                note.hidden = message === '';
            };
            limitsError('');
            // Open owner decision 01#4: AQG preselects nothing, so a budget needs an explicit choice.
            if (budget !== '' && !(hardStop instanceof HTMLInputElement)) {
                show(messageForCode('hard_stop_required'), 'error');
                limitsError(messageForCode('hard_stop_required'));
                root.querySelector('[data-aqg-ai-hard-stop="true"]')?.focus();
                return;
            }
            setBusy(button, true);
            try {
                const payload = {
                    siteIdentifier: siteIdentifier(),
                    budget,
                    warningPercent: warningInput instanceof HTMLInputElement ? warningInput.value.trim() : '',
                    hardStop: budget !== '' && hardStop instanceof HTMLInputElement ? hardStop.value : '',
                };
                // F-39: the alert address is sent only by a form that shows it; the server validates it.
                if (alertEmailInput instanceof HTMLInputElement) {
                    payload.alertEmail = alertEmailInput.value.trim();
                }
                await send(root.dataset.budgetUrl, payload);
                reloadWithMessage(budget === '' ? (root.dataset.messageBudgetRemoved || '') : (root.dataset.messageLimitsSaved || ''));
            } catch (error) {
                show(error instanceof Error ? error.message : messageForCode('internal_error'), 'error');
                limitsError(error instanceof Error ? error.message : messageForCode('internal_error'));
                setBusy(button, false);
            }
            return;
        }

        if (action === 'aqg-ai-use-model') {
            await useModel(button);
            return;
        }

        if (action === 'aqg-ai-save-privacy') {
            const acknowledge = ackCheckbox instanceof HTMLInputElement && ackCheckbox.checked && !ackCheckbox.disabled;
            const acknowledgedProvider = uiState.provider || root.dataset.provider || '';
            setBusy(button, true);
            try {
                await send(root.dataset.privacyUrl, {
                    siteIdentifier: siteIdentifier(),
                    allowImageInput: allowImages instanceof HTMLInputElement && allowImages.checked ? '1' : '0',
                    allowPageTitle: allowPageTitle instanceof HTMLInputElement && allowPageTitle.checked ? '1' : '0',
                });
                // A newly ticked acknowledgement is stored with the privacy settings, for the provider shown.
                if (acknowledge) {
                    await send(root.dataset.acknowledgeUrl, {
                        siteIdentifier: siteIdentifier(),
                        provider: acknowledgedProvider,
                        confirmed: '1',
                    });
                }
                show(root.dataset.messagePrivacySaved || '', 'ok');
            } catch (error) {
                show(error instanceof Error ? error.message : messageForCode('internal_error'), 'error');
            } finally {
                setBusy(button, false);
            }
            return;
        }

        let focusModelList = false;
        setBusy(button, true);
        if (action === 'aqg-ai-save') {
            show(root.dataset.messageSavingKey || '', 'info');
        } else if (action === 'aqg-ai-refresh-models') {
            show(root.dataset.messageRefreshingModels || '', 'info');
        } else if (action === 'aqg-ai-test' || action === 'aqg-ai-revalidate') {
            show(root.dataset.messageTestingConnection || '', 'info');
        }
        try {
            if (action === 'aqg-ai-save') {
                const data = await send(root.dataset.saveUrl, {
                    siteIdentifier: siteIdentifier(),
                    provider: root.dataset.provider || '',
                    apiKey: keyInput?.value || '',
                });
                if (keyInput instanceof HTMLInputElement) {
                    keyInput.value = '';
                }
                renderKeyState(uiState);
                const connectionCode = String(data.connectionCode || '');
                if (data.warningCode) {
                    show(messageForCode(data.warningCode), 'error');
                } else if (connectionCode === 'connection_successful') {
                    show(fill(root.dataset.messageSavedConnected, uiState.selectedModelLabel || uiState.selectedModelId), 'ok');
                } else if (connectionCode === 'model_not_selected') {
                    show(root.dataset.messageSavedChooseModel || root.dataset.messageSaved || '', 'info');
                    focusModelList = true;
                } else if (connectionCode !== '') {
                    show(fill(root.dataset.messageSavedNotConnected, messageForCode(connectionCode)), 'error');
                } else {
                    show(root.dataset.messageSaved || '', 'ok');
                }
            } else if (action === 'aqg-ai-refresh-models') {
                await send(root.dataset.refreshModelsUrl, {siteIdentifier: siteIdentifier()});
                show(root.dataset.messageModelsRefreshed || '', 'ok');
            } else if (action === 'aqg-ai-test' || action === 'aqg-ai-revalidate') {
                await send(root.dataset.testUrl, {siteIdentifier: siteIdentifier()});
                show(root.dataset.messageConnectionSuccessful || '', 'ok');
                if (action === 'aqg-ai-revalidate' && testButton instanceof HTMLButtonElement) {
                    // The banner is gone after a passing test; keep keyboard focus on the equivalent action.
                    testButton.focus();
                }
            }
        } catch (error) {
            show(error instanceof Error ? error.message : messageForCode('internal_error'), 'error');
        } finally {
            setBusy(button, false);
            if (focusModelList) {
                modelList?.querySelector('[data-aqg-ai-model-option="true"]')?.focus();
            }
        }
    });

    renderSummaryState(uiState);
    initializeSectionNavigation(root);

    try {
        const flash = window.sessionStorage.getItem(flashKey);
        if (flash) {
            window.sessionStorage.removeItem(flashKey);
            show(flash, 'ok');
        }
    } catch {
        // Storage can be unavailable; the saved state is visible without the message.
    }
};

/**
 * In-page section navigation (Provider & model, Privacy & editor features, Usage & limits, Diagnostics & model prices):
 * plain anchor links that work without JavaScript; this only marks the section being read with aria-current="location".
 * The current section is the last one whose top has passed the bottom of the (sticky) navigation; at the end of the
 * page it is the last section, which may be too short to reach the top.
 */
/**
 * Why AI is paused for editors (N-10): the provider rejected the key ('key') or the model ('model') during an editor
 * request (AiUsageRecorder stores provider_<code>), otherwise a provider change needs a new test ('change').
 */
export const revalidationReason = (state) => {
    const code = String(state?.errorCode || '');
    if (code === 'provider_1771002310') {
        return 'key';
    }
    if (code === 'provider_1771002317' || code === 'provider_1771002321') {
        return 'model';
    }
    return 'change';
};

export const initializeSectionNavigation = (root) => {
    const links = [...root.querySelectorAll('[data-aqg-ai-section-link]')];
    const sections = links
        .map((link) => root.querySelector(link.getAttribute('href') || ''))
        .filter((section) => section instanceof HTMLElement);
    if (links.length === 0 || sections.length === 0) {
        return null;
    }
    const nav = root.querySelector('[data-aqg-ai-sectionnav="true"]');
    const mark = (id) => {
        links.forEach((link) => {
            if (link.getAttribute('href') === `#${id}`) {
                link.setAttribute('aria-current', 'location');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    };
    const update = () => {
        const line = (nav instanceof HTMLElement ? nav.getBoundingClientRect().bottom : 0) + 24;
        const scroller = document.scrollingElement || document.documentElement;
        const atEnd = scroller.scrollHeight > scroller.clientHeight
            && scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 2;
        let current = sections[0];
        sections.forEach((section) => {
            if (section.getBoundingClientRect().top <= line) {
                current = section;
            }
        });
        mark((atEnd ? sections[sections.length - 1] : current).id);
    };
    let scheduled = false;
    const schedule = () => {
        if (scheduled) {
            return;
        }
        scheduled = true;
        window.requestAnimationFrame(() => {
            scheduled = false;
            update();
        });
    };
    document.addEventListener('scroll', schedule, {passive: true, capture: true});
    window.addEventListener('resize', schedule, {passive: true});
    links.forEach((link) => link.addEventListener('click', () => mark((link.getAttribute('href') || '').slice(1))));
    update();

    return update;
};

export const bootstrapAiSettings = () => {
    document.querySelectorAll('[data-aqg-ai-settings="true"]').forEach(initializeAiSettings);
};

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrapAiSettings, {once: true});
    } else {
        bootstrapAiSettings();
    }
}
