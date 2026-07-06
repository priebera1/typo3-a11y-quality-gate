const root = document.querySelector('[data-aqg-image-remediation="true"]');

if (root) {
    const messageForCode = (code) => {
        const messages = {
            stale_finding: root.dataset.messageStale,
            invalid_version_token: root.dataset.messageInvalidToken,
            permission_denied: root.dataset.messagePermissionDenied,
            invalid_input: root.dataset.messageInvalidInput,
            image_update_failed: root.dataset.messageUpdateFailed,
            image_unavailable: root.dataset.messageImageUnavailable,
            ai_unavailable: root.dataset.messageAiUnavailable,
            rate_limited: root.dataset.messageRateLimited,
            provider_failure: root.dataset.messageProviderFailure,
            internal_error: root.dataset.messageInternalError,
        };
        return messages[code] || root.dataset.messageRequestFailed || '';
    };

    const post = async (url, payload) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            body: new URLSearchParams(payload),
            credentials: 'same-origin',
        });
        const data = await response.json().catch(() => ({success: false, code: 'invalid_response'}));
        if (!response.ok || data.success === false) {
            const error = new Error(data.code === 'invalid_response'
                ? (root.dataset.messageInvalidResponse || root.dataset.messageRequestFailed || '')
                : messageForCode(data.code || ''));
            error.status = response.status;
            error.code = data.code || '';
            throw error;
        }
        return data;
    };

    const setBusy = (card, activeButton, busy) => {
        card.querySelectorAll('button').forEach((button) => {
            button.disabled = busy;
            button.setAttribute('aria-busy', busy && button === activeButton ? 'true' : 'false');
        });
        if (!activeButton) return;
        if (busy) {
            activeButton.dataset.originalHtml = activeButton.innerHTML;
            activeButton.innerHTML = `<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>${activeButton.dataset.loadingLabel || '…'}</span>`;
        } else if (activeButton.dataset.originalHtml) {
            activeButton.innerHTML = activeButton.dataset.originalHtml;
            delete activeButton.dataset.originalHtml;
        }
    };

    const showStatus = (card, message, tone = 'info', stale = false) => {
        const target = card.querySelector('[data-aqg-remediation-status="true"]');
        if (!target) return;
        target.hidden = false;
        target.className = `aqg-statusline tone-${tone}${stale ? ' aqg-statusline--stale' : ''}`;
        target.textContent = message;
    };

    const setAiDraftState = (card, active) => {
        const note = card.querySelector('[data-aqg-ai-note="true"]');
        const discardButton = card.querySelector('[data-action="aqg-discard-ai"]');
        if (note) note.hidden = !active;
        if (discardButton) discardButton.hidden = !active;
    };

    root.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-action^="aqg-"]');
        if (!button || button.disabled) return;
        const card = button.closest('[data-aqg-image-remediation-card="true"]');
        if (!card) return;
        const findingId = Number(card.dataset.findingId || 0);
        const expectedVersion = card.dataset.expectedVersion || '';
        const action = button.dataset.action;
        const altInput = card.querySelector('[data-aqg-alt-text="true"]');

        if (action === 'aqg-discard-ai') {
            if (altInput) {
                altInput.value = card.dataset.aiOriginalAlt || '';
                altInput.focus();
            }
            delete card.dataset.aiOriginalAlt;
            setAiDraftState(card, false);
            showStatus(card, root.dataset.messageSuggestionDiscarded || '', 'info');
            return;
        }

        if (action === 'aqg-mark-decorative' && !window.confirm(root.dataset.messageConfirmDecorative || '')) return;
        setBusy(card, button, true);
        try {
            if (action === 'aqg-suggest-alt') {
                const originalAlt = altInput ? altInput.value : '';
                const data = await post(root.dataset.suggestAltUrl, {findingId});
                if (data.expectedVersion) card.dataset.expectedVersion = data.expectedVersion;

                if (data.status === 'needs_review') {
                    setAiDraftState(card, false);
                    showStatus(card, root.dataset.messageNeedsReview || '', 'warning');
                    if (altInput) altInput.focus();
                    return;
                }

                card.dataset.aiOriginalAlt = originalAlt;
                if (altInput) {
                    altInput.value = data.suggestion || '';
                    altInput.focus();
                }
                setAiDraftState(card, true);
                showStatus(card, root.dataset.messageSuggestionReady || '', 'info');
                return;
            }
            if (action === 'aqg-apply-alt') {
                await post(root.dataset.applyAltUrl, {findingId, altText: altInput ? altInput.value : '', expectedVersion});
                showStatus(card, root.dataset.messageAltSaved || '', 'ok');
                window.setTimeout(() => window.location.reload(), 650);
                return;
            }
            if (action === 'aqg-mark-decorative') {
                await post(root.dataset.markDecorativeUrl, {findingId, expectedVersion});
                showStatus(card, root.dataset.messageDecorativeSaved || '', 'ok');
                window.setTimeout(() => window.location.reload(), 650);
                return;
            }
            if (action === 'aqg-mark-informative') {
                await post(root.dataset.markInformativeUrl, {findingId, expectedVersion});
                showStatus(card, root.dataset.messageInformativeSaved || '', 'ok');
                window.setTimeout(() => window.location.reload(), 650);
            }
        } catch (error) {
            const stale = error.code === 'stale_finding';
            showStatus(card, error.message, 'error', stale);
        } finally {
            setBusy(card, button, false);
        }
    });
}
