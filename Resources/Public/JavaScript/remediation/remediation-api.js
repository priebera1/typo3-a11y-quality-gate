/**
 * POST JSON to one remediation route. Answers are normalised to {ok, code, data, status}; a lost TYPO3 session is
 * reported as code "session_expired", so callers keep the person's input and say how to recover.
 */
export async function postRemediation(url, body, {signal} = {}) {
    if (!url) {
        return {ok: false, code: 'generic', data: {}, status: 0};
    }
    let response;
    try {
        response = await fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', Accept: 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify(body ?? {}),
            signal,
        });
    } catch (error) {
        return {ok: false, code: error?.name === 'AbortError' ? 'cancelled' : 'network', data: {}, status: 0};
    }
    let data = {};
    try {
        data = await response.json();
    } catch {
        data = {};
    }
    if (response.status === 401 || (response.redirected && !response.headers.get('content-type')?.includes('json'))) {
        return {ok: false, code: 'session_expired', data, status: 401};
    }
    if (!response.ok || data?.success !== true) {
        // A gateway answer without AQG's JSON (a reverse proxy or CDN replacing a 502/503/504 with its own page) is an
        // upstream failure, not an unknown one: callers can say so instead of the generic "did not work".
        const ownCode = typeof data?.code === 'string' && data.code !== '' ? data.code : '';
        const code = ownCode !== '' ? ownCode : ([502, 503, 504].includes(response.status) ? 'upstream_unavailable' : 'generic');
        return {ok: false, code, data: data ?? {}, status: response.status};
    }

    return {ok: true, code: '', data, status: response.status};
}

/** Text helper: model and record values are only ever set as text, never parsed as markup. */
export function el(tag, attributes = {}, ...children) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attributes)) {
        if (value === null || value === undefined || value === false) {
            continue;
        }
        if (key === 'class') {
            node.className = String(value);
        } else if (key === 'text') {
            node.textContent = String(value);
        } else if (key.startsWith('on') && typeof value === 'function') {
            node.addEventListener(key.slice(2), value);
        } else {
            node.setAttribute(key, value === true ? '' : String(value));
        }
    }
    for (const child of children.flat()) {
        if (child === null || child === undefined || child === false) {
            continue;
        }
        node.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }

    return node;
}

/** "%d"/"%s"/"%1$d" placeholders of the XLIFF labels. */
export function format(template, ...values) {
    let index = 0;
    return String(template ?? '').replace(/%(?:(\d+)\$)?[ds]/g, (match, position) => {
        const value = position ? values[Number(position) - 1] : values[index++];
        return value === undefined ? match : String(value);
    });
}

export function relativeAge(timestamp, labels, now = Date.now() / 1000) {
    const seconds = Math.max(0, now - Number(timestamp || 0));
    if (seconds < 60) {
        return labels['age.now'] || 'now';
    }
    if (seconds < 3600) {
        return format(labels['age.minutes'] || '%d min', Math.floor(seconds / 60));
    }
    if (seconds < 86400) {
        return format(labels['age.hours'] || '%d h', Math.floor(seconds / 3600));
    }
    return format(labels['age.days'] || '%d d', Math.floor(seconds / 86400));
}
