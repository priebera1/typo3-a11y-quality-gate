/**
 * Requests of the AI media actions (file metadata, image uses in content elements). All of them are read-only: answers
 * are a pre-flight, suggestions or check results, and TYPO3 Save stores what the person applied in the form. Errors
 * come back as stable codes.
 */

const ROUTES = {
    fileState: 'a11y_ai_media_file_state',
    suggestMetadata: 'a11y_ai_media_suggest_metadata',
    verifyFile: 'a11y_ai_media_verify_file',
    suggestReference: 'a11y_ai_media_suggest_reference',
    verifyReference: 'a11y_ai_media_verify_reference',
};

function ajaxUrl(action) {
    const settings = globalThis.TYPO3?.settings?.ajaxUrls ?? globalThis.top?.TYPO3?.settings?.ajaxUrls ?? {};
    return settings[ROUTES[action]] ?? '';
}

/**
 * @returns {Promise<{ok: boolean, code: string, data: object, httpStatus: number}>}
 */
export async function postMediaAi(action, payload, {signal} = {}) {
    const url = ajaxUrl(action);
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

/**
 * One suggestion request that waits out the site's rate limit once instead of failing (bounded: at most one wait of
 * up to 65 s), so a batch keeps its pace instead of reporting errors.
 */
export async function postMediaAiPaced(action, payload, {signal, onWait} = {}) {
    let response = await postMediaAi(action, payload, {signal});
    if (response.code === 'rate_limited' && !signal?.aborted) {
        const seconds = Math.min(65, Math.max(1, Number(response.data?.retryAfter ?? 30)));
        onWait?.(seconds);
        const waited = await new Promise((resolve) => {
            const timer = setTimeout(() => resolve(true), seconds * 1000);
            signal?.addEventListener('abort', () => {
                clearTimeout(timer);
                resolve(false);
            }, {once: true});
        });
        if (!waited) {
            return {ok: false, code: 'cancelled', data: {}, httpStatus: 0};
        }
        response = await postMediaAi(action, payload, {signal});
    }
    return response;
}
