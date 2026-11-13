// @vitest-environment jsdom

import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import A11yPlugin from '../../Resources/Public/JavaScript/ckeditor/a11y-plugin.js';

// Package 2 acceptance "No added editor height": the outcome of applied AI changes lives in the existing summary
// bar and on the marker, never in a block of its own between the bar and the editor.

const VALIDATE = '/typo3/ajax/a11y/rte/validate';
const VERIFY = '/typo3/ajax/a11y/ai/editor/verify';
const KEY = 'aqg-editor-ai:tt_content:1516:bodytext';
const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), {status, headers: {'Content-Type': 'application/json'}}));
const storedItem = () => ({id: 'live:1|rte.non_descriptive_link', ruleId: 'rte.non_descriptive_link', expected: {tag: 'a', attributes: {href: '/k'}, text: 'Contact'}, suggestedAt: 100, appliedAt: Date.now() - 1000});
const linkIssue = () => ({
    fingerprint: 'live:1', ruleId: 'rte.non_descriptive_link', severity: 'warning', message: 'Link text is not descriptive.',
    aiTarget: {ruleId: 'rte.non_descriptive_link', kind: 'link_text', operation: 'text', tag: 'a', occurrence: 0, total: 1, contextPath: 'p > a', currentValue: 'more', element: {tag: 'a', attributes: {href: '/k'}, text: 'more', hasChildElements: false}},
});

function createPlugin() {
    const listeners = {};
    const documentListeners = {};
    const form = document.createElement('form');
    form.innerHTML = '<div class="field"><div class="ck-editor"><div class="ck-toolbar"></div><div class="ck-editor__main"><div class="ck-editor__editable"><p>Read <a href="/k">more</a></p><p>Second</p></div></div></div></div>';
    document.body.append(form);
    const ckEditor = form.querySelector('.ck-editor');
    const editable = form.querySelector('.ck-editor__editable');
    const editor = {
        config: {get: (key) => (key === 'a11yQualityGate' ? {recordUid: 1516, fieldName: 'bodytext', pageUid: 752, labels: {}, ai: {state: 'ready', rules: ['rte.non_descriptive_link'], linkText: true}} : undefined)},
        conversion: {for: () => ({markerToHighlight() {}})},
        model: {document: {on: (event, callback) => (documentListeners[event] ??= []).push(callback), selection: {on() {}}}, change: (callback) => callback({}), markers: []},
        plugins: {has: () => false},
        ui: {getEditableElement: () => editable, view: {element: ckEditor, toolbar: {element: form.querySelector('.ck-toolbar')}}},
        editing: {view: {focus() {}, scrollToTheSelection() {}}},
        on: (event, callback) => (listeners[event] ??= []).push(callback),
        fire: (event) => (listeners[event] ?? []).forEach((callback) => callback()),
        getData: () => editable.innerHTML,
    };
    const plugin = new A11yPlugin(editor);
    for (const method of ['_applyHighlights', '_applyElementDecorations', '_scheduleGutterRender', '_installGutter', '_installDecorationObserver', '_markCurrentChip']) {
        vi.spyOn(plugin, method).mockImplementation(() => {});
    }
    vi.spyOn(plugin, '_filterIssuesForCurrentEditor').mockImplementation((issues) => issues);
    plugin.init();
    // A real gutter host (CKEditor's main area) so markers render.
    const gutter = document.createElement('div');
    form.querySelector('.ck-editor__main').append(gutter);
    plugin._gutter = gutter;
    plugin._gutterHost = form.querySelector('.ck-editor__main');

    return {plugin, editor, form, editable, gutter};
}

const summary = () => document.querySelector('.ck-a11y-summary');
const summaryText = () => summary().textContent.replace(/\s+/g, ' ').trim();

describe('CKEditor: AI outcome inside the summary bar', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        globalThis.TYPO3 = {settings: {ajaxUrls: {a11y_rte_validate: VALIDATE, a11y_ai_editor_verify: VERIFY}}};
        window.sessionStorage.clear();
        globalThis.fetch = vi.fn((url) => (String(url).includes(VERIFY)
            ? json({success: true, status: 'checked', saved: true, results: ['verified']})
            : json({success: true, live: true, issues: []})));
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
        delete globalThis.TYPO3;
    });

    it('adds no element between the summary bar and the editor (no added height)', async () => {
        window.sessionStorage.setItem(KEY, JSON.stringify([storedItem()]));
        window.sessionStorage.setItem(`${KEY}:submitted`, String(Date.now()));
        const {editor} = createPlugin();
        editor.fire('ready');
        await vi.advanceTimersByTimeAsync(100);

        const field = document.querySelector('.field');
        // Bar, visually hidden live region (absolutely positioned), editor: nothing else in the flow.
        expect(Array.from(field.children).map((child) => child.className)).toEqual([
            expect.stringContaining('ck-a11y-summary'),
            'aqg-ai-live',
            'ck-editor',
        ]);
        expect(document.querySelector('.aqg-ai-outcome')).toBeNull();
        const segment = summary().querySelector('.ck-a11y-summary__ai-outcome');
        expect(segment).not.toBeNull();
        expect(segment.closest('.ck-a11y-summary__left')).not.toBeNull();
        expect(summaryText()).toContain('1 resolved');
        expect(summaryText()).toContain('no longer reports this issue');
        // The bar's own hint gives way to the outcome's reason (one row of help, not two).
        expect(summaryText()).not.toContain('No issues found in this field.');
    });

    it('a reload without TYPO3 Save shows "Not saved" and never asks the server', async () => {
        window.sessionStorage.setItem(KEY, JSON.stringify([storedItem()]));
        const {editor} = createPlugin();
        editor.fire('ready');
        await vi.advanceTimersByTimeAsync(100);

        expect(globalThis.fetch.mock.calls.map(([url]) => String(url))).not.toContain(VERIFY);
        expect(summaryText()).toContain('Not saved');
        expect(summaryText()).not.toMatch(/Saved to TYPO3|resolved|verified/i);
        await vi.advanceTimersByTimeAsync(50);
        expect(document.querySelector('.aqg-ai-live').textContent).toContain('Not saved — the change was not stored');
    });

    it('an applied change shows "1 unsaved", a dashed blue marker, and Undo; the submit is remembered', async () => {
        const {plugin, editor, form, editable, gutter} = createPlugin();
        editor.fire('ready');
        await vi.advanceTimersByTimeAsync(100);

        const issue = linkIssue();
        const adapter = {undo: vi.fn(() => true), appliedElement: () => editable.querySelector('a')};
        plugin._onAiApplied('live:1|rte.non_descriptive_link', {issue, expected: {tag: 'a', attributes: {href: '/k'}, text: 'Contact'}, suggestion: {serverTime: 100}, adapter});

        expect(summaryText()).toContain('1 unsaved');
        expect(summaryText()).not.toMatch(/saved to|resolved|verified/i);
        const chip = gutter.querySelector('.ck-a11y-chip');
        expect(chip.classList.contains('ck-a11y-chip--inserted')).toBe(true);
        expect(chip.getAttribute('aria-label')).toBe('AI change applied, not saved. Show details.');
        expect(chip.querySelector('.ck-a11y-chip__wand')).toBeNull();

        form.dispatchEvent(new Event('submit'));
        expect(Number(window.sessionStorage.getItem(`${KEY}:submitted`))).toBeGreaterThan(0);

        summary().querySelector('[data-aqg-ai-outcome="undo"]').click();
        expect(adapter.undo).toHaveBeenCalledTimes(1);
        expect(summaryText()).not.toContain('unsaved');
        expect(gutter.querySelector('.ck-a11y-chip--inserted')).toBeNull();
        expect(window.sessionStorage.getItem(KEY)).toBeNull();
    });

    it('a block that still has another issue keeps its marker with the save dot', async () => {
        globalThis.fetch = vi.fn(() => json({success: true, live: true, issues: []}));
        const {plugin, editor, editable, gutter} = createPlugin();
        editor.fire('ready');
        await vi.advanceTimersByTimeAsync(100);

        const other = {fingerprint: 'live:2', ruleId: 'rte.img_alt_missing', severity: 'critical', message: 'Image has no alt.'};
        plugin._currentIssues = [other];
        vi.spyOn(plugin, '_collectGutterGroups').mockImplementation(() => [{block: editable.firstElementChild, target: editable.firstElementChild, top: 0, entries: [{issue: other, target: null, markerName: ''}]}]);
        plugin._onAiApplied('live:1|rte.non_descriptive_link', {issue: linkIssue(), expected: {tag: 'a', attributes: {}, text: 'x'}, suggestion: null, adapter: {undo: () => true, appliedElement: () => editable.querySelector('a')}});

        const chips = gutter.querySelectorAll('.ck-a11y-chip');
        expect(chips).toHaveLength(1);
        expect(chips[0].classList.contains('ck-a11y-chip--critical')).toBe(true);
        expect(chips[0].classList.contains('ck-a11y-chip--has-inserted')).toBe(true);
        expect(chips[0].getAttribute('aria-label')).toContain('AI change applied, not saved');
    });
});

describe('Plain HTML: AI outcome inside the summary bar', () => {
    beforeEach(() => {
        vi.resetModules();
        globalThis.TYPO3 = {settings: {ajaxUrls: {a11y_rte_validate: VALIDATE, a11y_ai_editor_verify: VERIFY}}};
        window.sessionStorage.clear();
    });

    afterEach(() => {
        window.__aqgPlainHtmlInstances?.forEach((instance) => instance.destroy());
        window.__aqgPlainHtmlInstances?.clear();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    const mountPlainField = async () => {
        document.body.innerHTML = `
            <form>
                <div class="js-aqg-plain-html-a11y" data-record-uid="77" data-field-name="bodytext" data-input-name="data[tt_content][77][bodytext]">
                    <div class="ck-a11y-summary" role="status" aria-live="polite"></div>
                    <div class="aqg-plain-html-a11y__issues"></div>
                </div>
                <textarea name="data[tt_content][77][bodytext]"><p>Text</p></textarea>
            </form>`;
        await import('../../Resources/Public/JavaScript/plain-html-a11y.js');
        await vi.waitFor(() => expect(document.querySelector('.ck-a11y-summary').textContent).toMatch(/passed|resolved|Not saved/));
    };

    it('renders the verified outcome in the bar, with no block of its own', async () => {
        window.sessionStorage.setItem('aqg-editor-ai:tt_content:77:bodytext', JSON.stringify([storedItem()]));
        window.sessionStorage.setItem('aqg-editor-ai:tt_content:77:bodytext:submitted', String(Date.now()));
        globalThis.fetch = vi.fn((url) => (String(url).includes(VERIFY)
            ? json({success: true, status: 'checked', saved: true, results: ['verified']})
            : json({success: true, live: true, issues: []})));

        await mountPlainField();
        await vi.waitFor(() => expect(document.querySelector('.ck-a11y-summary').textContent).toContain('1 resolved'));

        const container = document.querySelector('.js-aqg-plain-html-a11y');
        expect(Array.from(container.children).map((child) => child.className)).toEqual([
            expect.stringContaining('ck-a11y-summary'),
            'aqg-ai-live',
            'aqg-plain-html-a11y__issues',
        ]);
        expect(document.querySelector('.aqg-ai-outcome')).toBeNull();
    });

    it('an applied change is "1 unsaved" in the bar with Undo, and the line knows it', async () => {
        globalThis.fetch = vi.fn(() => json({success: true, live: true, issues: []}));
        await mountPlainField();
        const instance = document.querySelector('.js-aqg-plain-html-a11y').__aqgPlainHtmlValidator;
        const adapter = {undo: vi.fn(() => true), appliedLine: () => 1};
        instance.onAiApplied('live:1|rte.non_descriptive_link', {issue: linkIssue(), expected: {tag: 'a', attributes: {}, text: 'x'}, suggestion: {serverTime: 100}, adapter});

        const bar = document.querySelector('.ck-a11y-summary');
        expect(bar.textContent).toContain('1 unsaved');
        expect(bar.textContent).not.toMatch(/Saved to TYPO3|resolved|verified/i);
        expect(instance.appliedLines().get(1)).toEqual(['live:1|rte.non_descriptive_link']);

        bar.querySelector('[data-aqg-ai-outcome="undo"]').click();
        expect(adapter.undo).toHaveBeenCalledTimes(1);
        expect(document.querySelector('.ck-a11y-summary').textContent).not.toContain('unsaved');
        expect(instance.appliedLines().size).toBe(0);
    });

    it('a reload without TYPO3 Save is "Not saved" without a verify request', async () => {
        window.sessionStorage.setItem('aqg-editor-ai:tt_content:77:bodytext', JSON.stringify([storedItem()]));
        globalThis.fetch = vi.fn(() => json({success: true, live: true, issues: []}));

        await mountPlainField();
        await vi.waitFor(() => expect(document.querySelector('.ck-a11y-summary').textContent).toContain('Not saved'));
        expect(globalThis.fetch.mock.calls.map(([url]) => String(url))).not.toContain(VERIFY);
        expect(document.querySelector('.ck-a11y-summary').textContent).not.toMatch(/Saved to TYPO3|resolved/);
    });
});
