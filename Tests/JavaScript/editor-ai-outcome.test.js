// @vitest-environment jsdom
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {EditorAiOutcome} from '@priebera/a11y-quality-gate/editor-ai/editor-ai-outcome.js';

// Package 2 acceptance: "nothing written to TYPO3 without TYPO3 Save; Verified only after a stored-record check".
// The outcome never says "Saved" before the verify endpoint confirmed a save after the apply, and a reload without
// a submit (Close, browser reload) is "Not saved" without a request.

const KEY = 'aqg-editor-ai:tt_content:5:bodytext';
const item = (overrides = {}) => ({id: 'a', ruleId: 'rte.non_descriptive_link', expected: {tag: 'a', attributes: {href: '/k'}, text: 'Contact'}, suggestedAt: 100, appliedAt: Date.now() - 1000, ...overrides});
const respond = (status, body) => vi.fn(async () => new Response(JSON.stringify(body), {status, headers: {'Content-Type': 'application/json'}}));
const text = (html) => {
    const element = document.createElement('div');
    element.innerHTML = html;
    return element.textContent.replace(/\s+/g, ' ').trim();
};

const create = (options = {}) => {
    const announce = vi.fn();
    const onChange = vi.fn();
    const outcome = new EditorAiOutcome({recordUid: 5, fieldName: 'bodytext', label: (k, f) => f, announce, onChange, ...options});
    return {outcome, announce, onChange, bar: () => text(outcome.barHtml())};
};

/** The state of a field after a reload: applied items, and when the form was last submitted. */
const reloadWith = (items, submittedAt = null) => {
    window.sessionStorage.setItem(KEY, JSON.stringify(items));
    if (submittedAt !== null) {
        window.sessionStorage.setItem(`${KEY}:submitted`, String(submittedAt));
    }
};

beforeEach(() => {
    globalThis.TYPO3 = {settings: {ajaxUrls: {a11y_ai_editor_verify: '/verify'}}};
    window.sessionStorage.clear();
});

afterEach(() => {
    vi.restoreAllMocks();
    delete globalThis.fetch;
});

describe('editor AI outcome in the summary bar', () => {
    it('shows an applied change as "1 unsaved" with Undo, inside the bar markup only', () => {
        const {outcome, bar, onChange} = create();
        outcome.onUndoLast = vi.fn();
        expect(outcome.barHtml()).toBe('');

        outcome.added(item());

        expect(onChange).toHaveBeenCalledWith('inserted');
        expect(outcome.isApplied('a')).toBe(true);
        const holder = document.createElement('div');
        holder.innerHTML = outcome.barHtml();
        // One inline segment for the existing bar: no block element, no own live region.
        expect(holder.children).toHaveLength(1);
        expect(holder.firstElementChild.tagName).toBe('SPAN');
        expect(holder.querySelector('div, p, [role], [aria-live]')).toBeNull();
        expect(bar()).toContain('1 unsaved');
        expect(bar()).not.toMatch(/saved to|verified|resolved/i);
        expect(holder.querySelector('[data-aqg-ai-outcome="undo"]').getAttribute('aria-label')).toBe('Undo the last AI change');

        holder.querySelector('[data-aqg-ai-outcome="undo"]').click();
        expect(outcome.handleClick(holder.querySelector('[data-aqg-ai-outcome="undo"]'))).toBe(true);
        expect(outcome.onUndoLast).toHaveBeenCalled();

        outcome.removed('a');
        expect(outcome.barHtml()).toBe('');
        expect(window.sessionStorage.getItem(KEY)).toBeNull();
    });

    it('marks the form submit, and only while changes are applied', () => {
        const {outcome} = create();
        const form = document.createElement('form');
        outcome.bindForm(form);
        form.dispatchEvent(new Event('submit'));
        expect(window.sessionStorage.getItem(`${KEY}:submitted`)).toBeNull();

        outcome.added(item());
        form.dispatchEvent(new Event('submit'));
        expect(Number(window.sessionStorage.getItem(`${KEY}:submitted`))).toBeGreaterThan(0);
    });
});

describe('editor AI outcome after a reload (save truth)', () => {
    it('closed or reloaded without TYPO3 Save: "Not saved", no request, nothing replayed later', async () => {
        globalThis.fetch = vi.fn();
        reloadWith([item()]);
        const {outcome, bar, announce} = create();

        await outcome.verifyOnLoad();

        expect(globalThis.fetch).not.toHaveBeenCalled();
        expect(outcome.state).toBe('not_saved');
        expect(bar()).toContain('Not saved');
        expect(bar()).toContain('closed or reloaded without TYPO3 Save');
        expect(bar()).not.toMatch(/saved to typo3|checking|verified|resolved/i);
        expect(announce).toHaveBeenCalledTimes(1);
        expect(announce.mock.calls[0][0]).toContain('Not saved — the change was not stored');
        expect(window.sessionStorage.getItem(KEY)).toBeNull();

        const reopened = create();
        await reopened.outcome.verifyOnLoad();
        expect(reopened.outcome.state).toBe('none');
    });

    it('a submit older than the last change counts as not submitted', async () => {
        globalThis.fetch = vi.fn();
        const appliedAt = Date.now() - 1000;
        reloadWith([item({appliedAt})], appliedAt - 5000);
        const {outcome} = create();
        await outcome.verifyOnLoad();
        expect(globalThis.fetch).not.toHaveBeenCalled();
        expect(outcome.state).toBe('not_saved');
    });

    it('shows only the neutral "Checking the saved content…" until the server answers', async () => {
        let release;
        globalThis.fetch = vi.fn(() => new Promise((resolve) => { release = resolve; }));
        reloadWith([item()], Date.now());
        const {outcome, bar, announce} = create();

        const pending = outcome.verifyOnLoad();
        await Promise.resolve();

        expect(outcome.state).toBe('checking');
        expect(bar()).toBe('Checking the saved content…');
        expect(bar()).not.toMatch(/saved to typo3|in typo3 gespeichert/i);
        expect(announce).toHaveBeenLastCalledWith('Checking the saved content…');

        release(new Response(JSON.stringify({success: true, status: 'checked', saved: true, results: ['verified']}), {status: 200}));
        await pending;
        expect(outcome.state).toBe('verified');
    });

    it('saved and verified: "1 resolved" with the reason, storage cleared', async () => {
        globalThis.fetch = respond(200, {success: true, status: 'checked', saved: true, results: ['verified']});
        reloadWith([item()], Date.now());
        const {outcome, bar, announce} = create();

        await outcome.verifyOnLoad();

        expect(bar()).toContain('1 resolved');
        expect(bar()).toContain('no longer reports this issue');
        const sent = JSON.parse(globalThis.fetch.mock.calls[0][1].body);
        expect(sent.items[0].suggestedAt).toBe(100);
        expect(sent).not.toHaveProperty('items.0.appliedAt');
        expect(announce).toHaveBeenCalledTimes(2);
        expect(announce.mock.calls[1][0]).toMatch(/^Resolved and verified\. /);
        expect(window.sessionStorage.getItem(KEY)).toBeNull();
        expect(window.sessionStorage.getItem(`${KEY}:submitted`)).toBeNull();
    });

    it('saved but still reported: "Not verified", never resolved', async () => {
        globalThis.fetch = respond(200, {success: true, status: 'checked', saved: true, results: ['still_reported']});
        reloadWith([item()], Date.now());
        const {outcome, bar} = create();
        await outcome.verifyOnLoad();
        expect(outcome.state).toBe('not_verified');
        expect(bar()).toContain('Not verified');
        expect(bar()).toContain('still reports the issue');
        expect(bar()).not.toMatch(/resolved/i);
        expect(outcome.title()).toBe('Saved, not verified');
    });

    it('submitted but the save was rejected (record older than the change): "Not saved"', async () => {
        globalThis.fetch = respond(200, {success: true, status: 'checked', saved: false, results: ['not_saved']});
        reloadWith([item()], Date.now());
        const {outcome, bar} = create();
        await outcome.verifyOnLoad();
        expect(outcome.state).toBe('not_saved');
        expect(bar()).toContain('Not saved');
        expect(bar()).toContain('The record was not saved after the change was applied.');
        expect(bar()).not.toMatch(/saved,|verified|resolved/i);
        expect(window.sessionStorage.getItem(KEY)).toBeNull();
    });

    it('verify request failed: neutral "Not checked" (never "Saved"), items kept for Check again', async () => {
        globalThis.fetch = respond(503, {success: false, code: 'unavailable'});
        reloadWith([item()], Date.now());
        const {outcome, bar} = create();
        await outcome.verifyOnLoad();

        expect(outcome.state).toBe('check_failed');
        expect(bar()).toContain('Not checked');
        expect(bar()).toContain('not known yet whether the change was saved');
        expect(bar()).not.toMatch(/saved,|your change is saved/i);
        expect(outcome.barHtml()).toContain('data-aqg-ai-outcome="check"');
        expect(JSON.parse(window.sessionStorage.getItem(KEY))).toHaveLength(1);

        globalThis.fetch = respond(200, {success: true, status: 'checked', saved: true, results: ['verified']});
        const holder = document.createElement('div');
        holder.innerHTML = outcome.barHtml();
        outcome.handleClick(holder.querySelector('[data-aqg-ai-outcome="check"]'));
        await vi.waitFor(() => expect(outcome.state).toBe('verified'));
    });

    it('saved is known but the check gave no result: "Saved, not verified"', async () => {
        globalThis.fetch = respond(200, {success: true, status: 'checked', saved: true, results: []});
        reloadWith([item()], Date.now());
        const {outcome, bar} = create();
        await outcome.verifyOnLoad();
        expect(outcome.state).toBe('check_failed');
        expect(outcome.title()).toBe('Saved, not verified');
        expect(bar()).toContain('Not verified');
        expect(bar()).toContain('Your change is saved');
    });

    it('drops stale entries silently instead of replaying them on a later reopen', async () => {
        globalThis.fetch = vi.fn();
        reloadWith([item({appliedAt: Date.now() - 3 * 60 * 60 * 1000}), item({id: 'legacy', appliedAt: undefined})], Date.now());
        const {outcome, announce} = create();
        await outcome.verifyOnLoad();
        expect(globalThis.fetch).not.toHaveBeenCalled();
        expect(outcome.state).toBe('none');
        expect(announce).not.toHaveBeenCalled();
        expect(window.sessionStorage.getItem(KEY)).toBeNull();
    });

    it('labels the plain HTML check as the saved record', async () => {
        globalThis.fetch = vi.fn(() => new Promise(() => {}));
        reloadWith([item()], Date.now());
        const {outcome, bar} = create({editorType: 'html'});
        outcome.verifyOnLoad();
        await Promise.resolve();
        expect(bar()).toBe('Checking the saved record…');
    });
});
