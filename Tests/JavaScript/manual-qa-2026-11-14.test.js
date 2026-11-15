// @vitest-environment jsdom

import {afterEach, describe, expect, it, vi} from 'vitest';
import {buildFilterUrl, initializeIssues} from '../../Resources/Public/JavaScript/backend/issues.js';
import {ToolbarAssistant} from '../../Resources/Public/JavaScript/toolbar/a11y-toolbar-assistant.js';

/**
 * Owner manual QA 2026-11-14.
 * MI-01: "Show" on My issues loaded a second TYPO3 backend into the module frame (the GET form dropped the route token).
 * MI-02..04: filters, search, page size and chip removal update the results in place, from the server.
 * AQA-07: an AI Fixes change refreshes the Ask AQG plan that shows the item; AQA-06: one main action per state.
 */
const TOKEN = 'a'.repeat(40);
const results = (range, extra = '') => `<p data-aqg-issues-status="true" aria-live="polite"></p><div data-aqg-issues-results="true" aria-busy="false">${extra}<p id="aqg-issues-range" tabindex="-1">${range}</p>
    <a data-aqg-issues-nav="true" href="/typo3/module/content/a11y/issues?token=${TOKEN}&page=2">Next</a></div>`;
const page = (range) => `<form data-aqg-issues-filters="true" method="get" action="/typo3/module/content/a11y/issues?token=${TOKEN}">
        <input type="hidden" name="token" value="${TOKEN}">
        <select name="assignee"><option value="me">Me</option><option value="unassigned">Nobody</option></select>
        <select name="state"><option value="open">To do</option><option value="done">Done</option></select>
        <input type="search" name="q" value="">
        <select name="perPage"><option value="10">10</option><option value="25" selected>25</option></select>
        <button type="submit">Show</button><p data-aqg-issues-pending="true" hidden></p>
    </form>${results(range)}`;

afterEach(() => {
    document.body.innerHTML = '';
    vi.useRealTimers();
});

describe('My issues filters (MI-01..MI-04)', () => {
    it('keeps the route token in the filter URL, also without the hidden field', () => {
        document.body.innerHTML = page('25 tasks');
        const form = document.querySelector('form');
        form.querySelector('[name="assignee"]').value = 'unassigned';
        const url = new URL(buildFilterUrl(form, 'https://example.test/typo3/'));
        expect(url.searchParams.get('token')).toBe(TOKEN);
        expect(url.searchParams.get('assignee')).toBe('unassigned');
        expect(url.searchParams.has('q')).toBe(false);

        form.querySelector('[name="token"]').remove();
        expect(new URL(buildFilterUrl(form, 'https://example.test/typo3/')).searchParams.get('token')).toBe(TOKEN);
    });

    it('applies a select in place: one request with the token, results swapped, no backend injected', async () => {
        document.body.innerHTML = page('25 tasks');
        const fetchMock = vi.fn(async () => ({ok: true, text: async () => `<html><body>${page('Showing 1–10 of 97 tasks')}</body></html>`}));
        const pushState = vi.spyOn(window.history, 'pushState').mockImplementation(() => {});
        const api = initializeIssues(document, Object.assign(Object.create(window), {fetch: fetchMock, DOMParser: window.DOMParser, history: window.history, location: window.location, setTimeout: window.setTimeout.bind(window), clearTimeout: window.clearTimeout.bind(window), addEventListener: () => {}}));
        const select = document.querySelector('[name="assignee"]');
        select.value = 'unassigned';
        await api.apply();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const requested = new URL(fetchMock.mock.calls[0][0]);
        expect(requested.searchParams.get('token')).toBe(TOKEN);
        expect(requested.searchParams.get('assignee')).toBe('unassigned');
        expect(document.querySelector('#aqg-issues-range').textContent).toBe('Showing 1–10 of 97 tasks');
        expect(document.querySelector('[data-aqg-issues-status]').textContent).toBe('Showing 1–10 of 97 tasks');
        expect(document.querySelectorAll('form[data-aqg-issues-filters]')).toHaveLength(1);
        expect(pushState).toHaveBeenCalledTimes(1);
        pushState.mockRestore();
    });

    it('never injects a page without the results region (expired session, login form, backend)', async () => {
        document.body.innerHTML = page('25 tasks');
        const fetchMock = vi.fn(async () => ({ok: true, text: async () => '<html><body><div class="t3js-scaffold"><iframe></iframe></div></body></html>'}));
        const api = initializeIssues(document, Object.assign(Object.create(window), {fetch: fetchMock, DOMParser: window.DOMParser, history: window.history, location: window.location, setTimeout: window.setTimeout.bind(window), clearTimeout: window.clearTimeout.bind(window), addEventListener: () => {}}));
        expect(await api.apply()).toBe(false);
        expect(document.querySelector('iframe')).toBeNull();
        expect(document.querySelector('[data-aqg-issues-error]')?.getAttribute('role')).toBe('alert');
        expect(document.querySelector('#aqg-issues-range').textContent).toBe('25 tasks');
    });

    it('debounces the search: typing sends one request after the pause', async () => {
        vi.useFakeTimers();
        document.body.innerHTML = page('25 tasks');
        const fetchMock = vi.fn(async () => ({ok: true, text: async () => page('3 tasks')}));
        initializeIssues(document, Object.assign(Object.create(window), {fetch: fetchMock, DOMParser: window.DOMParser, history: {pushState: () => {}}, location: window.location, setTimeout: (fn, ms) => setTimeout(fn, ms), clearTimeout: (id) => clearTimeout(id), addEventListener: () => {}}));
        const search = document.querySelector('[name="q"]');
        for (const value of ['b', 'bu', 'button']) {
            search.value = value;
            search.dispatchEvent(new Event('input', {bubbles: true}));
        }
        expect(document.querySelector('[data-aqg-issues-pending]').hidden).toBe(false);
        await vi.advanceTimersByTimeAsync(450);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(new URL(fetchMock.mock.calls[0][0]).searchParams.get('q')).toBe('button');
    });
});

describe('Ask AQG after an AI Fixes change (AQA-07)', () => {
    it('refreshes the newest plan that shows the item once, also a restored one', async () => {
        vi.useFakeTimers();
        document.body.innerHTML = `
            <article class="aqt-plan" data-aqt-repair="true" id="live"><ul><li data-aqt-item-uid="4"></li><li data-aqt-item-uid="9"></li></ul></article>
            <article class="aqt-plan aqt-plan--stored" data-aqt-repair="true" id="stored"><ul><li data-aqt-item-uid="4"></li></ul></article>
            <article class="aqt-plan" data-aqt-repair="true" id="other"><ul><li data-aqt-item-uid="5"></li></ul></article>`;
        const assistant = Object.create(ToolbarAssistant.prototype);
        assistant.win = window;
        assistant.plans = new WeakMap([...document.querySelectorAll('article')].map((article) => [article, {}]));
        assistant.planRefresh = vi.fn();

        assistant.onRemediationChanged(4);
        assistant.onRemediationChanged(9);
        assistant.onRemediationChanged(0);
        await vi.advanceTimersByTimeAsync(450);

        // Item 4 is newest in the restored plan (after "Review suggestion" the dock shows the restored conversation),
        // item 9 only in the live one: one request each, none for the plan that does not show a changed item.
        expect(assistant.planRefresh.mock.calls.map((call) => call[0].id).sort()).toEqual(['live', 'stored']);
    });

    it('finds the finding of a rejected item in the newest plan (Reopen after Reject)', async () => {
        vi.useFakeTimers();
        document.body.innerHTML = `
            <article class="aqt-plan aqt-plan--stored" data-aqt-repair="true" id="older"><ul><li data-aqt-item-uid="12" data-aqt-repair-item="161"></li></ul></article>
            <article class="aqt-plan" data-aqt-repair="true" id="newest"><ul><li data-aqt-item-uid="0" data-aqt-repair-item="161"></li></ul></article>`;
        const assistant = Object.create(ToolbarAssistant.prototype);
        assistant.win = window;
        assistant.plans = new WeakMap([...document.querySelectorAll('article')].map((article) => [article, {}]));
        assistant.planRefresh = vi.fn();

        assistant.onRemediationChanged(12, 161);
        await vi.advanceTimersByTimeAsync(450);

        expect(assistant.planRefresh.mock.calls.map((call) => call[0].id)).toEqual(['newest']);
    });
});

describe('AI Fixes side (AQA-07, AF-04)', () => {
    it('announces a changed item to the backend document and other tabs, with only its id and status', async () => {
        const {notifyRemediationChanged} = await import('@priebera/a11y-quality-gate/remediation/remediation-queue.js');
        const seen = [];
        const listener = (event) => seen.push(event.detail);
        document.addEventListener('aqg:remediation-changed', listener);
        const posted = [];
        const win = Object.assign(Object.create(window), {
            top: window,
            BroadcastChannel: class { constructor(name) { this.name = name; } postMessage(data) { posted.push([this.name, data]); } close() {} },
        });
        notifyRemediationChanged({uid: 4, status: 'rejected', proposedValue: 'secret'}, win);
        notifyRemediationChanged({uid: 0}, win);
        document.removeEventListener('aqg:remediation-changed', listener);

        expect(seen).toEqual([{itemUid: 4, findingUid: 0, status: 'rejected'}]);
        expect(posted).toEqual([['aqg-remediation', {itemUid: 4, findingUid: 0, status: 'rejected'}]]);
    });

    it('replaces a preview that cannot load with a text placeholder', async () => {
        const {previewUnavailable} = await import('@priebera/a11y-quality-gate/remediation/remediation-queue.js');
        const placeholder = previewUnavailable('Image preview unavailable');
        expect(placeholder.tagName).toBe('SPAN');
        expect(placeholder.getAttribute('role')).toBe('img');
        expect(placeholder.getAttribute('aria-label')).toBe('Image preview unavailable');
        expect(placeholder.textContent).toBe('Image preview unavailable');
    });
});

describe('One filter pattern for My issues and AI fixes (owner QA 2026-11-14, follow-up)', () => {
    const queueForm = () => {
        document.body.innerHTML = `<form data-aqg-rq-filters="true">
            <label for="q">Search</label><input id="q" name="query" type="search" value="">
            <label for="st">Status</label><select id="st" name="status"><option value="">All</option><option value="pending_review">Pending review</option></select>
            <label for="si">Site</label><select id="si" name="site"><option value="__all">All sites</option><option value="a" selected>Alpha</option><option value="b">Beta</option></select>
            <label for="sv">Severity</label><select id="sv" name="severity"><option value="-1">All</option><option value="1">Critical</option></select>
        </form>`;
        return document.querySelector('form');
    };

    it('shows chips only for narrowing filters; "All" choices are no chips but still reset', async () => {
        const {activeFilterChips, filtersChanged, resetFilterControl} = await import('@priebera/a11y-quality-gate/remediation/remediation-filters.js');
        const form = queueForm();
        expect(activeFilterChips(form)).toEqual([]);
        expect(filtersChanged(form)).toBe(false);

        form.elements.namedItem('site').value = '__all';
        expect(activeFilterChips(form)).toEqual([]);
        expect(filtersChanged(form)).toBe(true);

        form.elements.namedItem('site').value = 'b';
        form.elements.namedItem('status').value = 'pending_review';
        form.elements.namedItem('query').value = '  alt  text ';
        expect(activeFilterChips(form)).toEqual([
            {name: 'query', label: 'Search', value: '“alt text”'},
            {name: 'status', label: 'Status', value: 'Pending review'},
            {name: 'site', label: 'Site', value: 'Beta'},
        ]);

        resetFilterControl(form, 'site');
        expect(form.elements.namedItem('site').value).toBe('a');
        resetFilterControl(form, 'query');
        expect(activeFilterChips(form).map((chip) => chip.name)).toEqual(['status']);
    });

    it('hides "Show" on My issues when the script runs (no-JS fallback only)', () => {
        document.body.innerHTML = `<form data-aqg-issues-filters="true" action="/typo3/module/content/a11y/issues?token=${TOKEN}">
            <div data-aqg-issues-apply="true"><button type="submit">Show</button></div></form><div data-aqg-issues-results="true"></div>`;
        initializeIssues(document, Object.assign(Object.create(window), {fetch: () => null, DOMParser: window.DOMParser, history: window.history, location: window.location, setTimeout: window.setTimeout.bind(window), clearTimeout: window.clearTimeout.bind(window), addEventListener: () => {}}));
        expect(document.querySelector('[data-aqg-issues-apply]').hidden).toBe(true);
    });
});
