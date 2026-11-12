/**
 * Requests of the editor AI actions. Answers are suggestions only; errors come back as stable codes.
 */

const ROUTES = {
    fix: 'a11y_ai_editor_fix',
    explain: 'a11y_ai_editor_explain',
    write: 'a11y_ai_editor_write',
    verify: 'a11y_ai_editor_verify',
};

/**
 * @returns {Promise<{ok: boolean, code: string, data: object, httpStatus: number}>}
 */
export async function postEditorAi(action, payload, {signal} = {}) {
    const url = globalThis.TYPO3?.settings?.ajaxUrls?.[ROUTES[action]];
    if (!url) {
        return {ok: false, code: 'unavailable', data: {}, httpStatus: 0};
    }

    let response;
    try {
        response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            signal,
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            body: JSON.stringify(payload),
        });
    } catch (error) {
        if (error?.name === 'AbortError') {
            return {ok: false, code: 'cancelled', data: {}, httpStatus: 0};
        }
        return {ok: false, code: 'network', data: {}, httpStatus: 0};
    }

    let data = {};
    try {
        data = await response.json();
    } catch {
        data = {};
    }
    if (response.status === 401) {
        return {ok: false, code: 'session_expired', data, httpStatus: 401};
    }
    if (!response.ok || data?.success !== true) {
        return {ok: false, code: String(data?.code ?? 'provider_unavailable'), data: data ?? {}, httpStatus: response.status};
    }
    return {ok: true, code: String(data.status ?? ''), data, httpStatus: response.status};
}
