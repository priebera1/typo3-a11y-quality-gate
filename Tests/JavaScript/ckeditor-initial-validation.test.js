// @vitest-environment jsdom

import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import A11yPlugin from '../../Resources/Public/JavaScript/ckeditor/a11y-plugin.js';

// Regression (2.0.1): opening an editor showed the stored content-scan findings first, then CKEditor's own initial
// data load ("change:data" before "ready") started a live check 1.6 s later that silently replaced them, announced
// as "after your content change". On tt_content 1516 the editor showed 4 critical findings, then "passed".
// The live check of the current text is now the first result; stored findings are only a labelled fallback.

const VALIDATE = '/typo3/ajax/a11y/rte/validate';
const ISSUES = '/typo3/ajax/a11y/issues';

const critical = (snippet) => ({ruleId: 'rte.form_control_missing_label', severity: 'critical', message: 'Missing label', snippet, fingerprint: 'fp-' + snippet});
const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), {status, headers: {'Content-Type': 'application/json'}}));
const deferred = () => {
    let resolve;
    const promise = new Promise((r) => { resolve = r; });
    return {promise, resolve};
};

function createEditor(initialData = '<p>Text</p>') {
    const listeners = {};
    const documentListeners = {};
    let data = initialData;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = '<div class="ck-editor"><div class="ck-toolbar"></div><div class="ck-editor__editable"></div></div>';
    document.body.append(wrapper);
    const editable = wrapper.querySelector('.ck-editor__editable');
    editable.innerHTML = initialData;

    const editor = {
        config: {get: (key) => (key === 'a11yQualityGate' ? {recordUid: 1516, fieldName: 'bodytext', pageUid: 752, labels: {}} : undefined)},
        conversion: {for: () => ({markerToHighlight() {}})},
        model: {
            document: {
                on: (event, callback) => (documentListeners[event] ??= []).push(callback),
                selection: {on() {}},
            },
            change: (callback) => callback({}),
            markers: [],
        },
        ui: {getEditableElement: () => editable, view: {toolbar: {element: wrapper.querySelector('.ck-toolbar')}}},
        editing: {view: {focus() {}, scrollToTheSelection() {}}},
        on: (event, callback) => (listeners[event] ??= []).push(callback),
        fire: (event) => (listeners[event] ?? []).forEach((callback) => callback()),
        getData: () => data,
    };

    return {
        editor,
        // CKEditor fires change:data for the initial load (before "ready") and for every edit.
        changeData(newData = data) {
            data = newData;
            editable.innerHTML = newData;
            (documentListeners['change:data'] ?? []).forEach((callback) => callback());
        },
    };
}

function createPlugin(initialData) {
    const fake = createEditor(initialData);
    const plugin = new A11yPlugin(fake.editor);
    plugin.init();
    // Marker, decoration and gutter rendering need a real CKEditor; the summary and requests are real.
    for (const method of ['_applyHighlights', '_applyElementDecorations', '_scheduleGutterRender', '_installGutter', '_installDecorationObserver']) {
        vi.spyOn(plugin, method).mockImplementation(() => {});
    }
    vi.spyOn(plugin, '_filterIssuesForCurrentEditor').mockImplementation((issues) => issues);

    return {plugin, ...fake};
}

const summary = () => document.querySelector('.ck-a11y-summary');
const summaryText = () => summary().textContent.replace(/\s+/g, ' ').trim();
const calls = () => globalThis.fetch.mock.calls.map(([url, init]) => `${(init?.method ?? 'GET')} ${String(url).replace(/^https?:\/\/[^/]+/, '').split('?')[0]}`);
const settle = () => vi.advanceTimersByTimeAsync(0);

describe('CKEditor accessibility feedback on open', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        globalThis.TYPO3 = {settings: {ajaxUrls: {a11y_issues: ISSUES, a11y_rte_validate: VALIDATE}}};
        globalThis.fetch = vi.fn();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
        delete globalThis.TYPO3;
    });

    it('does not treat the initial content load as an edit', async () => {
        const {changeData, editor} = createPlugin();
        changeData();
        await vi.advanceTimersByTimeAsync(3000);
        expect(globalThis.fetch).not.toHaveBeenCalled();

        globalThis.fetch.mockImplementation(() => json({success: true, live: true, issues: []}));
        editor.fire('ready');
        await vi.advanceTimersByTimeAsync(3000);

        expect(calls()).toEqual([`POST ${VALIDATE}`]);
    });

    it('shows "Checking accessibility" until the live check of the current text answers', async () => {
        const response = deferred();
        globalThis.fetch.mockImplementation(() => response.promise);
        const {editor} = createPlugin();
        editor.fire('ready');
        await settle();

        expect(summaryText()).toContain('Checking accessibility');
        expect(summaryText()).not.toContain('content change');
        expect(summary().getAttribute('aria-busy')).toBe('true');

        response.resolve(await json({success: true, live: true, issues: []}));
        await settle();

        expect(summaryText()).toContain('Accessibility check passed');
        expect(summary().getAttribute('aria-busy')).toBe('false');
        expect(calls()).toEqual([`POST ${VALIDATE}`]);
    });

    it('never shows stored findings first: the live result is the first result', async () => {
        globalThis.fetch.mockImplementation(() => json({success: true, live: true, issues: [critical('a'), critical('b')]}));
        const {editor} = createPlugin();
        editor.fire('ready');
        await settle();

        expect(summaryText()).toContain('2 issues found');
        expect(summary().classList.contains('ck-a11y-summary--critical')).toBe(true);
        expect(summary().classList.contains('ck-a11y-summary--stored')).toBe(false);
        expect(calls()).toEqual([`POST ${VALIDATE}`]);
    });

    it('falls back to the stored findings, labelled as the last content scan, when the live check fails', async () => {
        globalThis.fetch.mockImplementation((url) => (String(url).includes(VALIDATE)
            ? json({success: false}, 500)
            : json({success: true, issues: [critical('a'), critical('b'), critical('c'), critical('d')]})));
        const {editor} = createPlugin();
        editor.fire('ready');
        await settle();

        expect(calls()).toEqual([`POST ${VALIDATE}`, `GET ${ISSUES}`]);
        expect(summaryText()).toContain('4 issues found');
        expect(summaryText()).toContain('last content scan');
        expect(summary().classList.contains('ck-a11y-summary--stored')).toBe(true);
        expect(summary().querySelector('[data-a11y-refresh]')).not.toBeNull();
    });

    it('does not report "passed" when only the stored scan is available and it has no findings', async () => {
        globalThis.fetch.mockImplementation((url) => (String(url).includes(VALIDATE)
            ? Promise.reject(new TypeError('network down'))
            : json({success: true, issues: []})));
        const {editor} = createPlugin();
        editor.fire('ready');
        await settle();

        expect(summaryText()).toContain('No issues in the last content scan');
        expect(summaryText()).not.toContain('Accessibility check passed');
    });

    it('Retry runs the live check again and replaces the fallback', async () => {
        let liveAvailable = false;
        globalThis.fetch.mockImplementation((url) => (String(url).includes(VALIDATE)
            ? (liveAvailable ? json({success: true, live: true, issues: []}) : json({}, 503))
            : json({success: true, issues: [critical('a')]})));
        const {editor} = createPlugin();
        editor.fire('ready');
        await settle();
        expect(summary().classList.contains('ck-a11y-summary--stored')).toBe(true);

        liveAvailable = true;
        summary().querySelector('[data-a11y-refresh]').click();
        await settle();

        expect(summaryText()).toContain('Accessibility check passed');
        expect(summary().classList.contains('ck-a11y-summary--stored')).toBe(false);
        expect(calls()).toEqual([`POST ${VALIDATE}`, `GET ${ISSUES}`, `POST ${VALIDATE}`]);
    });

    it('validates an edit once after the debounce and says it is updating', async () => {
        const responses = [];
        globalThis.fetch.mockImplementation(() => {
            const next = deferred();
            responses.push(next);
            return next.promise;
        });
        const {editor, changeData} = createPlugin();
        editor.fire('ready');
        await settle();
        responses[0].resolve(await json({success: true, live: true, issues: []}));
        await settle();

        changeData('<p>Text a</p>');
        await vi.advanceTimersByTimeAsync(500);
        changeData('<p>Text ab</p>');
        await vi.advanceTimersByTimeAsync(1500);
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(200);
        expect(globalThis.fetch).toHaveBeenCalledTimes(2);
        expect(JSON.parse(globalThis.fetch.mock.calls[1][1].body).html).toBe('<p>Text ab</p>');
        expect(summaryText()).toContain('content change');
    });

    it('lets a newer check win: the older request is aborted and its late answer ignored', async () => {
        const responses = [];
        globalThis.fetch.mockImplementation((url, init) => {
            const next = deferred();
            responses.push({...next, signal: init.signal});
            return next.promise;
        });
        const {editor, changeData} = createPlugin();
        editor.fire('ready');
        await settle();

        changeData('<p>Edited</p>');
        await vi.advanceTimersByTimeAsync(1600);
        expect(responses).toHaveLength(2);
        expect(responses[0].signal.aborted).toBe(true);

        responses[1].resolve(await json({success: true, live: true, issues: [critical('x')]}));
        await settle();
        responses[0].resolve(await json({success: true, live: true, issues: []}));
        await settle();

        expect(summaryText()).toContain('1 issue found');
    });

    it('does not let a late stored fallback overwrite a newer live result', async () => {
        const stored = deferred();
        let validateCalls = 0;
        globalThis.fetch.mockImplementation((url) => {
            if (String(url).includes(ISSUES)) {
                return stored.promise;
            }
            validateCalls++;
            return validateCalls === 1 ? json({}, 500) : json({success: true, live: true, issues: []});
        });
        const {editor, changeData} = createPlugin();
        editor.fire('ready');
        await settle();

        changeData('<p>Edited</p>');
        await vi.advanceTimersByTimeAsync(1600);
        expect(summaryText()).toContain('Accessibility check passed');

        stored.resolve(await json({success: true, issues: [critical('a'), critical('b')]}));
        await settle();

        expect(summaryText()).toContain('Accessibility check passed');
    });

    it('runs the live check after an ignore (a11y:refresh), not the stored findings', async () => {
        globalThis.fetch.mockImplementation(() => json({success: true, live: true, issues: []}));
        const {editor} = createPlugin();
        editor.fire('ready');
        await settle();

        editor.fire('a11y:refresh');
        await settle();

        expect(calls()).toEqual([`POST ${VALIDATE}`, `POST ${VALIDATE}`]);
    });

    it('cancels a pending check when the editor is destroyed', async () => {
        globalThis.fetch.mockImplementation(() => json({success: true, live: true, issues: []}));
        const {editor, changeData} = createPlugin();
        editor.fire('ready');
        await settle();

        changeData('<p>Edited</p>');
        editor.fire('destroy');
        await vi.advanceTimersByTimeAsync(3000);

        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
    });
});
