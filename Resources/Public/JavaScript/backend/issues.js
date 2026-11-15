/**
 * My issues.
 *
 * N-17: on a narrow screen the task table scrolls inside its frame; the frame becomes a named region keyboard users can
 * focus and scroll (scrollable-tables.js), like the Overview tables.
 *
 * MI-01..MI-04 (manual QA 2026-11-14): filters, search, page size, chip removal and pagination update the results in
 * place. The server filters and paginates; this script only requests the same module URL the form or link names
 * (route token included) and swaps the results region, so the TYPO3 backend is never loaded into the module frame.
 * Selects apply on change, search after a short pause; "Show" is hidden and stays the fallback without JavaScript.
 */
import { initializeScrollableTables } from '@priebera/a11y-quality-gate/backend/core/scrollable-tables.js';

const SEARCH_DELAY_MS = 400;

/** The URL a GET submission of the form would open: the action URL with the form fields as its query. */
export function buildFilterUrl(form, base) {
    const url = new URL(form.getAttribute('action') || '', base);
    const params = new URLSearchParams();
    for (const [name, value] of new FormData(form).entries()) {
        if (typeof value === 'string' && value.trim() !== '') {
            params.set(name, value.trim());
        }
    }
    // The route token of the action URL survives even when the hidden field is missing.
    const token = url.searchParams.get('token');
    if (token && !params.has('token')) {
        params.set('token', token);
    }
    url.search = params.toString();

    return url.toString();
}

function formSnapshot(form) {
    return JSON.stringify([...new FormData(form).entries()].filter(([name]) => name !== 'token'));
}

export function initializeIssues(doc = document, win = window) {
    initializeScrollableTables(doc);
    const form = doc.querySelector('[data-aqg-issues-filters]');
    const results = doc.querySelector('[data-aqg-issues-results]');
    if (!form || !results || typeof win.fetch !== 'function' || typeof win.DOMParser !== 'function') {
        return null;
    }
    const pending = form.querySelector('[data-aqg-issues-pending]');
    // Filters apply by themselves (as in AI fixes); "Show" stays only for a browser without JavaScript.
    form.querySelectorAll('[data-aqg-issues-apply]').forEach((fallback) => {
        fallback.hidden = true;
    });
    let applied = formSnapshot(form);
    let controller = null;
    let searchTimer = 0;

    const markDirty = () => {
        const dirty = formSnapshot(form) !== applied;
        form.classList.toggle('is-dirty', dirty);
        if (pending) {
            pending.hidden = !dirty;
        }
    };

    const syncForm = (fresh) => {
        const freshForm = fresh.querySelector('[data-aqg-issues-filters]');
        if (!freshForm) {
            return;
        }
        // Controls follow the applied state (an editor's own assignee, a removed chip) without touching the focused one.
        for (const control of form.elements) {
            if (!control.name || control === doc.activeElement) {
                continue;
            }
            const twin = freshForm.elements.namedItem(control.name);
            if (twin && 'value' in twin && control.value !== twin.value) {
                control.value = twin.value;
            }
        }
        applied = formSnapshot(freshForm);
        markDirty();
    };

    const showError = () => {
        let error = results.querySelector('[data-aqg-issues-error]');
        if (!error) {
            error = doc.createElement('p');
            error.className = 'alert alert-danger aqg-issues__error';
            error.setAttribute('role', 'alert');
            error.setAttribute('data-aqg-issues-error', 'true');
            results.prepend(error);
        }
        error.textContent = form.getAttribute('data-error-label') || 'The list could not be updated. Reload the module and try again.';
    };

    const load = async (url, { push = true } = {}) => {
        controller?.abort();
        const current = new AbortController();
        controller = current;
        results.setAttribute('aria-busy', 'true');
        try {
            const response = await win.fetch(url, { credentials: 'same-origin', signal: current.signal, headers: { Accept: 'text/html' } });
            const html = response.ok ? await response.text() : '';
            const fresh = new win.DOMParser().parseFromString(html, 'text/html');
            const freshResults = fresh.querySelector('[data-aqg-issues-results]');
            if (!freshResults) {
                // Session expired or an unexpected page: never inject it (it could be the backend or the login form).
                showError();
                return false;
            }
            results.innerHTML = freshResults.innerHTML;
            syncForm(fresh);
            initializeScrollableTables(results);
            const status = doc.querySelector('[data-aqg-issues-status]');
            const range = results.querySelector('#aqg-issues-range, .aqg-empty__title');
            if (status && range) {
                status.textContent = range.textContent.trim();
            }
            if (push) {
                win.history.pushState({ aqgIssues: true }, '', url);
            }
            return true;
        } catch (error) {
            if (error?.name !== 'AbortError') {
                showError();
            }
            return false;
        } finally {
            if (controller === current) {
                results.setAttribute('aria-busy', 'false');
            }
        }
    };

    const apply = () => {
        win.clearTimeout(searchTimer);
        return load(buildFilterUrl(form, win.location.href));
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        apply();
    });
    form.addEventListener('change', (event) => {
        if (event.target?.matches?.('select')) {
            apply();
        } else {
            markDirty();
        }
    });
    form.addEventListener('input', (event) => {
        if (event.target?.matches?.('input[type="search"]')) {
            markDirty();
            win.clearTimeout(searchTimer);
            searchTimer = win.setTimeout(apply, SEARCH_DELAY_MS);
        }
    });
    results.addEventListener('click', (event) => {
        const link = event.target?.closest?.('a[data-aqg-issues-nav]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();
        load(link.href).then((ok) => {
            if (ok) {
                // Keep keyboard users in the list after paging or removing a chip.
                (results.querySelector('#aqg-issues-range') || results.querySelector('.aqg-empty__title'))?.focus?.();
            }
        });
    });
    win.addEventListener('popstate', () => {
        load(win.location.href, { push: false });
    });

    return { load, apply };
}

initializeIssues();
