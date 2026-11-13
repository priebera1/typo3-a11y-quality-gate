// @vitest-environment jsdom

import {afterEach, beforeAll, beforeEach, describe, expect, it, vi} from 'vitest';

// Regressions (3.0 editor-first audit):
// - every keyup in the code editor (arrow keys, selecting text) started a live check 1.6 s later, also when the
//   source had not changed;
// - an editor without the Accessibility module saw "Live validation failed (403)";
// - "How to fix" and "Ignoring…" were English in every backend language.

const VALIDATE = '/typo3/ajax/a11y/rte/validate';
const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), {status, headers: {'Content-Type': 'application/json'}}));

let uid = 100;

const mount = (html = '<p>Text</p>', labels = {}) => {
    const name = `data[tt_content][${++uid}][bodytext]`;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = `
        <div class="form-wizards-item-element">
            <typo3-t3editor-codemirror name="${name}"><div class="cm-editor"><div class="cm-content" contenteditable="true"></div></div></typo3-t3editor-codemirror>
        </div>
        <textarea name="${name}"></textarea>
        <div class="js-aqg-plain-html-a11y" data-record-uid="${uid}" data-field-name="bodytext" data-input-name="${name}" data-labels='${JSON.stringify(labels)}'>
            <div class="ck-a11y-summary"></div>
            <div class="aqg-plain-html-a11y__issues"></div>
        </div>`;
    document.body.append(wrapper);
    const host = wrapper.querySelector('typo3-t3editor-codemirror');
    host.value = html;
    wrapper.querySelector('textarea').value = html;
    document.dispatchEvent(new Event('typo3:formengine:field-added'));

    const content = wrapper.querySelector('.cm-content');
    return {
        container: wrapper.querySelector('.js-aqg-plain-html-a11y'),
        content,
        type(newHtml) {
            host.value = newHtml;
            content.dispatchEvent(new Event('input', {bubbles: true}));
            content.dispatchEvent(new KeyboardEvent('keyup', {key: 'a', bubbles: true}));
        },
        moveCaret() {
            content.dispatchEvent(new KeyboardEvent('keyup', {key: 'ArrowLeft', bubbles: true}));
            content.dispatchEvent(new MouseEvent('mouseup', {bubbles: true}));
        },
    };
};

const posts = () => globalThis.fetch.mock.calls.filter(([url]) => String(url).includes(VALIDATE));
const postedHtml = () => posts().map(([, init]) => JSON.parse(init.body).html);

describe('plain HTML live check requests', () => {
    beforeAll(async () => {
        vi.useFakeTimers();
        globalThis.TYPO3 = {settings: {ajaxUrls: {a11y_rte_validate: VALIDATE, a11y_ignore: '/typo3/ajax/a11y/ignore'}}};
        globalThis.fetch = vi.fn(() => json({success: true, issues: []}));
        await import('../../Resources/Public/JavaScript/plain-html-a11y.js');
    });

    beforeEach(() => {
        globalThis.fetch.mockReset();
        globalThis.fetch.mockImplementation(() => json({success: true, issues: []}));
    });

    afterEach(() => {
        vi.clearAllTimers();
        document.body.innerHTML = '';
    });

    it('checks once on open and not again for caret moves or selections', async () => {
        const editor = mount();
        await vi.advanceTimersByTimeAsync(400);
        expect(postedHtml()).toEqual(['<p>Text</p>']);

        for (let i = 0; i < 5; i++) {
            editor.moveCaret();
            await vi.advanceTimersByTimeAsync(400);
        }
        await vi.advanceTimersByTimeAsync(3000);

        expect(posts()).toHaveLength(1);
        expect(editor.container.querySelector('.ck-a11y-summary').textContent).not.toContain('Updating');
    });

    it('still checks a real change once after the debounce', async () => {
        const editor = mount();
        await vi.advanceTimersByTimeAsync(400);

        editor.type('<p>Text <a href="/x"></a></p>');
        editor.moveCaret();
        await vi.advanceTimersByTimeAsync(3000);

        expect(postedHtml()).toEqual(['<p>Text</p>', '<p>Text <a href="/x"></a></p>']);
    });

    it('does not let an older answer replace the result of a newer source', async () => {
        const answers = [];
        globalThis.fetch.mockImplementation((url, init) => new Promise((resolve) => answers.push({resolve, html: JSON.parse(init.body).html})));
        const editor = mount('<p>One</p>');
        await vi.advanceTimersByTimeAsync(400);

        editor.type('<p>Two <img src="x.png"></p>');
        await vi.advanceTimersByTimeAsync(3000);
        expect(answers.map((answer) => answer.html)).toEqual(['<p>One</p>', '<p>Two <img src="x.png"></p>']);

        answers[1].resolve(await json({success: true, issues: [{severity: 'critical', ruleId: 'rte.img_alt_missing', message: 'Image without alt', fingerprint: 'fp1'}]}));
        await vi.advanceTimersByTimeAsync(0);
        answers[0].resolve(await json({success: true, issues: []}));
        await vi.advanceTimersByTimeAsync(0);

        expect(editor.container.__aqgPlainHtmlValidator.currentIssues.map((issue) => issue.fingerprint)).toEqual(['fp1']);
    });

    // Review finding: undo back to the checked source while the next check is still running.
    it('does not show the answer for an undone source', async () => {
        const answers = [];
        globalThis.fetch.mockImplementation((url, init) => new Promise((resolve) => answers.push({resolve, html: JSON.parse(init.body).html})));
        const editor = mount('<p>One</p>');
        await vi.advanceTimersByTimeAsync(400);
        answers[0].resolve(await json({success: true, issues: []}));
        await vi.advanceTimersByTimeAsync(0);

        editor.type('<p>Two <img src="x.png"></p>');
        await vi.advanceTimersByTimeAsync(3000);
        editor.type('<p>One</p>');
        await vi.advanceTimersByTimeAsync(3000);
        expect(answers.map((answer) => answer.html)).toEqual(['<p>One</p>', '<p>Two <img src="x.png"></p>', '<p>One</p>']);

        answers[1].resolve(await json({success: true, issues: [{severity: 'critical', ruleId: 'rte.img_alt_missing', message: 'Image without alt', fingerprint: 'fp1'}]}));
        await vi.advanceTimersByTimeAsync(0);
        answers[2].resolve(await json({success: true, issues: []}));
        await vi.advanceTimersByTimeAsync(0);

        expect(editor.container.__aqgPlainHtmlValidator.currentIssues).toEqual([]);
    });

    it('keeps AQG\'s own refusal (record access, JSON answer) visible', async () => {
        globalThis.fetch.mockImplementation(() => json({success: false, error: 'Access denied'}, 403));
        const editor = mount();
        await vi.advanceTimersByTimeAsync(400);

        expect(editor.container.hidden).toBe(false);
        expect(editor.container.textContent).toContain('403');
    });

    it('Retry asks again for the same source', async () => {
        globalThis.fetch.mockImplementationOnce(() => json({success: false}, 500));
        const editor = mount();
        await vi.advanceTimersByTimeAsync(400);
        editor.container.querySelector('.js-aqg-plain-html-retry').click();
        await vi.advanceTimersByTimeAsync(400);

        expect(posts()).toHaveLength(2);
    });

    it('removes the AQG bar and stops asking when TYPO3 refuses the route (no module access)', async () => {
        globalThis.fetch.mockImplementation(() => Promise.resolve(new Response(null, {status: 403})));
        const editor = mount();
        await vi.advanceTimersByTimeAsync(400);

        expect(editor.container.hidden).toBe(true);
        expect(document.body.textContent).not.toContain('Live validation failed');

        editor.type('<p>Changed</p>');
        await vi.advanceTimersByTimeAsync(3000);
        expect(posts()).toHaveLength(1);
    });

    it('labels "How to fix" and "Ignoring…" in the backend language', async () => {
        globalThis.fetch.mockImplementation((url) => (String(url).includes(VALIDATE)
            ? json({success: true, issues: [{severity: 'critical', ruleId: 'rte.empty_link', message: 'Leerer Link', hint: 'Linktext ergänzen.', fingerprint: 'fp-link', ignorable: true}]})
            : new Promise(() => {})));
        const editor = mount('<p><a href="/x"></a></p>', {howToFix: 'So beheben Sie es', ignoring: 'Wird ignoriert…'});
        await vi.advanceTimersByTimeAsync(400);

        const issues = editor.container.querySelector('.aqg-plain-html-a11y__issues');
        expect(issues.textContent).toContain('So beheben Sie es');
        expect(issues.textContent).not.toContain('How to fix');

        const ignore = issues.querySelector('[data-action="ignore"], .js-aqg-plain-html-ignore');
        expect(ignore).not.toBeNull();
        ignore.click();
        await vi.advanceTimersByTimeAsync(0);
        expect(ignore.textContent).toBe('Wird ignoriert…');
    });
});
