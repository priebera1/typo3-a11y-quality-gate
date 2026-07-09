const CONNECTION_STATUSES = new Set([
    'not_configured',
    'not_verified',
    'connected',
    'connection_failed',
]);

const isRecord = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

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
            unique.set(id, {id, label});
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
    const statusBadge = root.querySelector('[data-aqg-ai-status-badge="true"]');
    const lastTested = root.querySelector('[data-aqg-ai-last-tested="true"]');
    const lastVerified = root.querySelector('[data-aqg-ai-last-verified="true"]');
    const keyInput = root.querySelector('#aqg-ai-api-key');
    const modelSelect = root.querySelector('[data-aqg-ai-model="true"]');
    const unsupportedModels = root.querySelector('[data-aqg-ai-unsupported-models="true"]');
    const unsupportedModelsCount = root.querySelector('[data-aqg-ai-unsupported-count="true"]');
    const unsupportedModelsList = root.querySelector('[data-aqg-ai-unsupported-list="true"]');
    const testButton = root.querySelector('[data-action="aqg-ai-test"]');
    const refreshButton = root.querySelector('[data-action="aqg-ai-refresh-models"]');
    const saveButton = root.querySelector('[data-action="aqg-ai-save"]');
    const replaceButton = root.querySelector('[data-action="aqg-ai-replace"]');
    const keyField = root.querySelector('[data-aqg-ai-key-field="true"]');
    const linkTextToggle = root.querySelector('[data-aqg-ai-link-text-toggle="true"]');

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
        connection_test_failed: root.dataset.messageConnectionFailed,
        internal_error: root.dataset.messageRequestFailed,
    }[code] || root.dataset.messageRequestFailed || '');

    const readInitialState = () => normalizeAiSettingsUiState({
        configured: root.dataset.configured === '1',
        modelSelected: root.dataset.modelSelected === '1',
        selectedModelAvailable: root.dataset.modelSelected === '1',
        selectedModelId: modelSelect instanceof HTMLSelectElement ? modelSelect.value : '',
        availableModels: modelSelect instanceof HTMLSelectElement
            ? [...modelSelect.options]
                .filter((option) => option.value !== '')
                .map((option) => ({
                    id: option.value,
                    label: (option.textContent || option.value).split(' — ')[0].trim(),
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
            return new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
                dateStyle: 'medium',
                timeStyle: 'short',
            }).format(new Date(timestamp * 1000));
        } catch {
            return new Date(timestamp * 1000).toLocaleString();
        }
    };

    const renderStatusBadge = (state) => {
        if (!(statusBadge instanceof HTMLElement)) {
            return;
        }
        const definitions = {
            connected: [root.dataset.labelConnected || 'Connected', 'aqg-status-badge aqg-status-badge--success'],
            connection_failed: [root.dataset.labelConnectionFailed || 'Connection failed', 'aqg-status-badge aqg-status-badge--danger'],
            not_verified: [root.dataset.labelNotVerified || 'Not verified', 'aqg-status-badge'],
            not_configured: [root.dataset.labelNotConfigured || 'Not configured', 'aqg-status-badge aqg-status-badge--neutral'],
        };
        const [label, className] = definitions[state.status] || definitions.not_configured;
        const badge = document.createElement('span');
        badge.className = className;
        badge.textContent = label;
        statusBadge.replaceChildren(badge);
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
        message.textContent = isError
            ? (state.errorCode === 'models_permission_denied'
                ? messageForCode(state.errorCode)
                : (root.dataset.messageConnectionFailedPersistent || messageForCode(state.errorCode)))
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

    const modelPlaceholder = modelSelect instanceof HTMLSelectElement
        ? (modelSelect.options[0]?.textContent || 'Select an OpenAI model')
        : 'Select an OpenAI model';

    const renderModelOptions = (state) => {
        if (!(modelSelect instanceof HTMLSelectElement)) {
            return;
        }

        const availableModels = normalizeModelList(state.availableModels);
        const availableModelIds = new Set(availableModels.map((model) => model.id));
        const selectedModelId = String(state.selectedModelId || '').trim();
        const canSelectModel = state.modelSelected === true
            && state.selectedModelAvailable === true
            && selectedModelId !== ''
            && availableModelIds.has(selectedModelId);

        // The latest uiState is the complete source of truth. Never retain options
        // or a selected value from an earlier render.
        modelSelect.replaceChildren();

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = modelPlaceholder;
        placeholder.selected = !canSelectModel;
        modelSelect.append(placeholder);

        availableModels.forEach((model) => {
            const option = document.createElement('option');
            option.value = model.id;
            option.textContent = `${model.label} — ${model.id}`;
            option.selected = canSelectModel && model.id === selectedModelId;
            modelSelect.append(option);
        });

        modelSelect.value = canSelectModel ? selectedModelId : '';
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
            } else if (button === testButton) {
                button.disabled = !uiState.actions.testConnectionEnabled;
            } else {
                button.disabled = false;
            }
        });
        if (modelSelect instanceof HTMLSelectElement) {
            modelSelect.disabled = busy || !uiState.configured;
        }
    };

    const renderSummaryState = (nextState) => {
        uiState = normalizeAiSettingsUiState(nextState, uiState);
        root.dataset.configured = uiState.configured ? '1' : '0';
        root.dataset.modelSelected = uiState.modelSelected ? '1' : '0';
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

        renderModelOptions(uiState);
        renderUnsupportedModels(uiState);
        renderStatusBadge(uiState);
        renderTimestamp(lastTested, uiState.lastTestedAt);
        renderTimestamp(lastVerified, uiState.lastVerifiedAt);
        renderPersistentStatus(uiState);
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

    if (modelSelect instanceof HTMLSelectElement) {
        modelSelect.addEventListener('change', async () => {
            const modelId = modelSelect.value.trim();
            renderSummaryState({
                ...uiState,
                selectedModelId: modelId,
                modelSelected: modelId !== '',
                selectedModelAvailable: modelId !== ''
                    && uiState.availableModels.some((model) => model.id === modelId),
                status: modelId === '' ? uiState.status : 'not_verified',
                errorCode: '',
                lastVerifiedAt: 0,
                actions: {
                    ...uiState.actions,
                    testConnectionEnabled: false,
                },
            });
            if (modelId === '') {
                return;
            }

            modelSelect.setAttribute('aria-busy', 'true');
            applyEnabledState(true);
            try {
                await send(root.dataset.selectModelUrl, {
                    siteIdentifier: siteIdentifier(),
                    modelId,
                });
                show(root.dataset.messageModelSelected || '', 'ok');
            } catch (error) {
                show(error instanceof Error ? error.message : messageForCode('internal_error'), 'error');
            } finally {
                modelSelect.removeAttribute('aria-busy');
                applyEnabledState();
            }
        });
    }


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

        setBusy(button, true);
        if (action === 'aqg-ai-save') {
            show(root.dataset.messageSavingKey || '', 'info');
        } else if (action === 'aqg-ai-refresh-models') {
            show(root.dataset.messageRefreshingModels || '', 'info');
        } else if (action === 'aqg-ai-test') {
            show(root.dataset.messageTestingConnection || '', 'info');
        }
        try {
            if (action === 'aqg-ai-save') {
                const data = await send(root.dataset.saveUrl, {
                    siteIdentifier: siteIdentifier(),
                    apiKey: keyInput?.value || '',
                });
                if (keyInput instanceof HTMLInputElement) {
                    keyInput.value = '';
                }
                const warning = data.warningCode ? messageForCode(data.warningCode) : '';
                show(warning || root.dataset.messageSaved || '', warning !== '' ? 'error' : 'ok');
            } else if (action === 'aqg-ai-refresh-models') {
                await send(root.dataset.refreshModelsUrl, {siteIdentifier: siteIdentifier()});
                show(root.dataset.messageModelsRefreshed || '', 'ok');
            } else if (action === 'aqg-ai-test') {
                await send(root.dataset.testUrl, {siteIdentifier: siteIdentifier()});
                show(root.dataset.messageConnectionSuccessful || '', 'ok');
            }
        } catch (error) {
            show(error instanceof Error ? error.message : messageForCode('internal_error'), 'error');
        } finally {
            setBusy(button, false);
        }
    });

    renderSummaryState(uiState);
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
