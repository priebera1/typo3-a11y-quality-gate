export const initializeAiIframeTitleSuggestion = (root = document.querySelector('[data-aqg-ai-iframe-title-url]')) => {
    if (!(root instanceof HTMLElement)) {
        return false;
    }

    if (root.dataset.aqgAiIframeTitleInitialized === '1') {
        return false;
    }
    root.dataset.aqgAiIframeTitleInitialized = '1';

    const messageForCode = (code) => {
        const messages = {
            permission_denied: root.dataset.messagePermissionDenied,
            invalid_input: root.dataset.messageInvalidInput,
            unsupported_context: root.dataset.messageIframeTitleUnsupported,
            unsupported_rule: root.dataset.messageIframeTitleUnsupported,
            finding_not_found: root.dataset.messageIframeTitleUnsupported,
            needs_review: root.dataset.messageIframeTitleNoSuggestion,
            refusal: root.dataset.messageIframeTitleNoSuggestion,
            ai_iframe_title_disabled: root.dataset.messageIframeTitleDisabled,
            ai_not_configured: root.dataset.messageIframeTitleNotConfigured,
            ai_feature_unavailable: root.dataset.messageAiUnavailable,
            rate_limited: root.dataset.messageRateLimited,
            provider_error: root.dataset.messageIframeTitleProviderError,
            invalid_response: root.dataset.messageInvalidResponse,
            internal_error: root.dataset.messageInternalError,
        };
        return messages[code] || root.dataset.messageRequestFailed || '';
    };

    const isExpectedNoSuggestionStatus = (status) => [
        'needs_review',
        'unsupported_context',
        'refusal',
    ].includes(status);

    const setBusy = (card, activeButton, busy) => {
        card.querySelectorAll('button').forEach((button) => {
            button.disabled = busy || (button.hidden && button.dataset.action === 'aqg-copy-iframe-title');
            button.setAttribute('aria-busy', busy && button === activeButton ? 'true' : 'false');
        });
        if (!(activeButton instanceof HTMLButtonElement)) {
            return;
        }
        if (busy) {
            activeButton.dataset.originalHtml = activeButton.innerHTML;
            activeButton.innerHTML = `<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>${activeButton.dataset.loadingLabel || '…'}</span>`;
        } else if (activeButton.dataset.originalHtml) {
            activeButton.innerHTML = activeButton.dataset.originalHtml;
            delete activeButton.dataset.originalHtml;
        }
    };

    const showStatus = (card, message, tone = 'info') => {
        const target = card.querySelector('[data-aqg-ai-iframe-title-status="true"]');
        if (!(target instanceof HTMLElement)) {
            return;
        }
        target.hidden = false;
        target.className = `aqg-statusline tone-${tone}`;
        target.setAttribute('role', tone === 'error' ? 'alert' : 'status');
        target.textContent = message;
    };

    const hideStatus = (card) => {
        const target = card.querySelector('[data-aqg-ai-iframe-title-status="true"]');
        if (!(target instanceof HTMLElement)) {
            return;
        }
        target.hidden = true;
        target.className = 'aqg-statusline';
        target.removeAttribute('role');
        target.textContent = '';
    };

    const post = async (payload) => {
        const response = await fetch(root.dataset.aqgAiIframeTitleUrl || '', {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            body: new URLSearchParams(payload),
            credentials: 'same-origin',
        });
        const data = await response.json().catch(() => ({success: false, code: 'invalid_response'}));
        if (!response.ok) {
            const error = new Error(messageForCode(data.code || 'internal_error'));
            error.status = response.status;
            error.code = data.code || '';
            throw error;
        }
        return data;
    };

    const setReviewVisibility = (card, hasSuggestion) => {
        const label = card.querySelector('[data-aqg-ai-iframe-title-label="true"]');
        const fieldRow = card.querySelector('[data-aqg-ai-iframe-title-field-row="true"]');
        const empty = card.querySelector('[data-aqg-ai-iframe-title-no-suggestion="true"]');
        const copyButton = card.querySelector('[data-aqg-ai-iframe-title-copy="true"]');

        if (label instanceof HTMLElement) {
            label.hidden = !hasSuggestion;
        }
        if (fieldRow instanceof HTMLElement) {
            fieldRow.hidden = !hasSuggestion;
        }
        if (empty instanceof HTMLElement) {
            empty.hidden = hasSuggestion;
        }
        if (copyButton instanceof HTMLButtonElement) {
            copyButton.hidden = !hasSuggestion;
            copyButton.disabled = !hasSuggestion;
        }
    };

    const renderSuggestion = (card, data) => {
        const review = card.querySelector('[data-aqg-ai-iframe-title-review="true"]');
        const input = card.querySelector('[data-aqg-ai-iframe-title-suggestion="true"]');
        const reason = card.querySelector('[data-aqg-ai-iframe-title-reason="true"]');
        if (!(review instanceof HTMLElement) || !(input instanceof HTMLInputElement)) {
            return;
        }
        setReviewVisibility(card, true);
        input.value = String(data.suggestedIframeTitle || '');
        if (reason instanceof HTMLElement) {
            reason.textContent = String(data.reason || '');
        }
        review.hidden = false;
        input.focus();
        input.select();
    };

    const renderNoSuggestion = (card, data) => {
        const review = card.querySelector('[data-aqg-ai-iframe-title-review="true"]');
        const input = card.querySelector('[data-aqg-ai-iframe-title-suggestion="true"]');
        const reason = card.querySelector('[data-aqg-ai-iframe-title-reason="true"]');
        if (!(review instanceof HTMLElement)) {
            return;
        }
        setReviewVisibility(card, false);
        if (input instanceof HTMLInputElement) {
            input.value = '';
        }
        if (reason instanceof HTMLElement) {
            reason.textContent = String(data.reason || data.message || messageForCode(data.code || data.status || 'unsupported_context'));
        }
        review.hidden = false;
    };

    root.addEventListener('click', async (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const button = target?.closest('[data-action]');
        if (!(button instanceof HTMLButtonElement) || button.disabled) {
            return;
        }

        const action = button.dataset.action || '';
        const card = button.closest('[data-aqg-ai-iframe-title-card="true"]');
        if (!(card instanceof HTMLElement)) {
            return;
        }

        if (action === 'aqg-copy-iframe-title') {
            const input = card.querySelector('[data-aqg-ai-iframe-title-suggestion="true"]');
            const value = input instanceof HTMLInputElement ? input.value.trim() : '';
            if (input instanceof HTMLInputElement && value !== '') {
                input.select();
                try {
                    await navigator.clipboard.writeText(input.value);
                } catch {
                    document.execCommand('copy');
                }
                showStatus(card, root.dataset.messageIframeTitleCopied || '', 'ok');
            }
            return;
        }

        if (action !== 'aqg-suggest-iframe-title') {
            return;
        }

        const findingId = Number(card.dataset.findingId || 0);
        if (!Number.isInteger(findingId) || findingId <= 0) {
            showStatus(card, messageForCode('invalid_input'), 'error');
            return;
        }

        setBusy(card, button, true);
        try {
            const data = await post({findingId});
            const status = String(data.status || data.code || '');
            const suggestedIframeTitle = String(data.suggestedIframeTitle || '').trim();

            if (data.success === false) {
                if (isExpectedNoSuggestionStatus(status)) {
                    renderNoSuggestion(card, data);
                    hideStatus(card);
                    return;
                }
                showStatus(card, messageForCode(status || 'internal_error'), 'error');
                return;
            }

            if (status === 'suggestion' && suggestedIframeTitle !== '') {
                renderSuggestion(card, data);
                showStatus(card, root.dataset.messageIframeTitleReady || '', 'info');
                return;
            }

            if (isExpectedNoSuggestionStatus(status)) {
                renderNoSuggestion(card, data);
                hideStatus(card);
                return;
            }

            showStatus(card, messageForCode(status || 'invalid_response'), 'error');
        } catch (error) {
            showStatus(card, error instanceof Error ? error.message : messageForCode('internal_error'), 'error');
        } finally {
            setBusy(card, button, false);
        }
    });

    return true;
};

initializeAiIframeTitleSuggestion();
