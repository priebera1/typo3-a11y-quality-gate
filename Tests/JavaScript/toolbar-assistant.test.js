// @vitest-environment jsdom

import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {
    collectAssistantContext,
    errorLabelKey,
    findFormField,
    ToolbarAssistant,
} from '../../Resources/Public/JavaScript/toolbar/a11y-toolbar-assistant.js';
import {statusUpdateTarget} from '../../Resources/Public/JavaScript/toolbar/a11y-scan-toolbar-menu.js';

const frame = (href, body = '') => {
    const doc = document.implementation.createHTMLDocument('frame');
    doc.body.innerHTML = body;
    return {location: {href}, document: doc};
};

describe('toolbar assistant context', () => {
    it('reads the page and language of the module', () => {
        expect(collectAssistantContext(frame('https://x.test/typo3/module/web/layout?id=42&language=1&token=abc'))).toEqual({id: '42', language: '1'});
    });

    it('reads the record of the editing form and only the names of its changed fields', () => {
        const form = `<form name="editform">
            <textarea name="data[tt_content][10][bodytext]" class="has-change">secret unsaved text</textarea>
            <input name="data[tt_content][10][header]" value="unchanged">
            <input name="data[tt_content][77][header]" class="has-change" value="other record">
        </form>`;
        const context = collectAssistantContext(frame('https://x.test/typo3/record/edit?edit%5Btt_content%5D%5B10%5D=edit&returnUrl=%2Ftypo3', form));

        expect(context).toEqual({editTable: 'tt_content', editUid: '10', unsaved: '1', changed: 'bodytext'});
        expect(JSON.stringify(context)).not.toContain('secret');
    });

    it('ignores tables the assistant does not know and new records', () => {
        expect(collectAssistantContext(frame('https://x.test/typo3/record/edit?edit%5Bbe_users%5D%5B1%5D=edit'))).toEqual({});
        expect(collectAssistantContext(frame('https://x.test/typo3/record/edit?edit%5Btt_content%5D%5B10%5D=new'))).toEqual({});
    });

    it('takes the one selected file of the File list, else reports a folder', () => {
        const row = (uid, checked, type = 'file') => `<tr data-filelist-element="true" data-filelist-type="${type}" data-filelist-uid="${uid}"><td><input type="checkbox" class="t3js-multi-record-selection-check"${checked ? ' checked' : ''}></td></tr>`;
        const url = 'https://x.test/typo3/module/file/list?id=1%3A%2Fuser_upload%2F';

        expect(collectAssistantContext(frame(url, `<table>${row(5, true)}${row(6, false)}</table>`))).toEqual({fileUid: '5'});
        expect(collectAssistantContext(frame(url, `<table>${row(5, true)}${row(6, true)}</table>`))).toEqual({folder: '1'});
        expect(collectAssistantContext(frame(url, `<table>${row(5, false)}</table>`))).toEqual({folder: '1'});
    });

    it('falls back to the page tree selection', () => {
        expect(collectAssistantContext(frame('https://x.test/typo3/module/dashboard'), '728')).toEqual({id: '728'});
        expect(collectAssistantContext(frame('https://x.test/typo3/module/dashboard'), '0')).toEqual({});
    });

    it('finds a finding\'s field in the editing form and refuses other names', () => {
        const doc = document.implementation.createHTMLDocument('frame');
        doc.body.innerHTML = '<div class="t3js-formengine-field-item"><textarea name="data[tt_content][10][bodytext]"></textarea><div class="ck-editor__editable" tabindex="0"></div></div>';

        expect(findFormField(doc, 'data[tt_content][10][bodytext]').focusTarget.className).toBe('ck-editor__editable');
        expect(findFormField(doc, 'data[tt_content][10][header]')).toBeNull();

        // Plain HTML: TYPO3's CodeMirror element carries the field name and keeps its editable in a shadow root.
        doc.body.innerHTML = '<div class="t3js-formengine-field-item"><typo3-t3editor-codemirror name="data[tt_content][7][bodytext]"></typo3-t3editor-codemirror><textarea name="data[tt_content][7][bodytext]" style="display:none"></textarea></div>';
        const editor = doc.querySelector('typo3-t3editor-codemirror');
        editor.attachShadow({mode: 'open'}).innerHTML = '<div class="cm-content" contenteditable="true"></div>';
        expect(findFormField(doc, 'data[tt_content][7][bodytext]').focusTarget).toBe(editor.shadowRoot.querySelector('.cm-content'));
        expect(findFormField(doc, '"],[x')).toBeNull();
    });

    it('maps endpoint codes to explanations', () => {
        expect(errorLabelKey('permission_denied')).toBe('errorPermission');
        expect(errorLabelKey('stale_finding')).toBe('errorStale');
        expect(errorLabelKey('anything')).toBe('error');
    });
});

describe('toolbar status refresh with the assistant', () => {
    it('replaces only the status container', () => {
        const menu = document.createElement('div');
        menu.innerHTML = '<div data-aqt-status>old</div><div data-aqt-assistant><button>kept</button></div>';
        const parsed = document.createElement('div');
        parsed.innerHTML = '<div data-aqt-status>new</div><div data-aqt-assistant></div>';

        const target = statusUpdateTarget(menu, parsed);

        expect(target.element).toBe(menu.querySelector('[data-aqt-status]'));
        expect(target.html).toBe('new');
    });
});

describe('toolbar assistant actions', () => {
    let fetchMock;
    const assistantHtml = `<section data-aqt-assistant-root="true" data-label-explanation="AI explanation" data-label-explain-note="Note"
            data-label-error-permission="Not allowed" data-label-error="Failed" data-label-applied="Saved" data-label-sent="What was sent">
        <ol><li data-aqt-finding="5">
            <div data-aqt-image="true" data-expected-version="v1"><textarea data-aqt-alt="true">A dog</textarea>
            <button type="button" data-aqt-action="apply-alt">Apply</button></div>
            <button type="button" data-aqt-action="explain" aria-expanded="false">Explain</button>
            <div data-aqt-result="true" hidden></div>
        </li></ol><p data-aqt-live="true"></p></section>`;

    beforeEach(() => {
        document.body.innerHTML = '<div id="slot" data-aqt-assistant data-label-loading="Loading"></div>';
        fetchMock = vi.fn();
        globalThis.fetch = fetchMock;
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    const assistant = () => new ToolbarAssistant({
        win: window,
        slotSelector: '#slot',
        ajaxUrls: () => ({
            a11y_toolbar_assistant: '/typo3/ajax/a11y/toolbar/assistant',
            a11y_remediation_explain: '/explain',
            a11y_image_apply_alt: '/apply',
            a11y_remediation_prepare: '/prepare',
        }),
    });

    const loaded = async () => {
        fetchMock.mockResolvedValueOnce({ok: true, text: async () => assistantHtml});
        const instance = assistant();
        await instance.load();
        return instance;
    };

    it('loads the assistant without context outside the backend frame set', async () => {
        await loaded();

        expect(String(fetchMock.mock.calls[0][0])).toBe('http://localhost:3000/typo3/ajax/a11y/toolbar/assistant');
        expect(document.querySelector('[data-aqt-assistant-root]')).not.toBeNull();
    });

    it('shows an AI explanation as text and asks only once', async () => {
        await loaded();
        fetchMock.mockResolvedValueOnce({ok: true, status: 200, redirected: false, headers: {get: () => 'application/json'}, json: async () => ({
            success: true, status: 'explanation', explanation: '<img src=x onerror=alert(1)> Describe the dog.', sent: ['finding', 'element'],
        })});
        const button = document.querySelector('[data-aqt-action="explain"]');

        button.click();
        await vi.waitFor(() => expect(document.querySelector('[data-aqt-result]').textContent).toContain('Describe the dog.'));
        expect(button.getAttribute('aria-expanded')).toBe('true');

        const result = document.querySelector('[data-aqt-result]');
        expect(result.querySelector('img')).toBeNull();
        expect(result.textContent).toContain('<img src=x onerror=alert(1)> Describe the dog.');
        expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({findingUid: 5});

        button.click();
        await vi.waitFor(() => expect(result.hidden).toBe(true));
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('applies the reviewed alt text with the version token and reports a refusal', async () => {
        await loaded();
        fetchMock.mockResolvedValueOnce({ok: false, status: 403, json: async () => ({success: false, code: 'permission_denied'})});
        document.querySelector('[data-aqt-alt]').value = '  A brown dog on a beach  ';

        document.querySelector('[data-aqt-action="apply-alt"]').click();
        await vi.waitFor(() => expect(document.querySelector('[data-aqt-result]').textContent).toBe('Not allowed'));

        const body = fetchMock.mock.calls[1][1].body;
        expect(fetchMock.mock.calls[1][0]).toBe('/apply');
        expect(body.get('findingId')).toBe('5');
        expect(body.get('altText')).toBe('A brown dog on a beach');
        expect(body.get('expectedVersion')).toBe('v1');
    });

    it('moves focus to the answer when a refused "Fix with AI" button goes away', async () => {
        fetchMock.mockResolvedValueOnce({ok: true, text: async () => assistantHtml.replace('<div data-aqt-result="true" hidden></div>', '<button type="button" data-aqt-action="prepare">Fix with AI</button><div data-aqt-result="true" hidden></div>').replace('data-label-sent=', 'data-label-prepare-manual="Not safe" data-label-sent=')});
        await assistant().load();
        fetchMock.mockResolvedValueOnce({ok: true, status: 200, redirected: false, headers: {get: () => 'application/json'}, json: async () => ({success: true, items: [{findingUid: 5, outcome: 'skipped', reason: 'manual_only'}]})});
        const button = document.querySelector('[data-aqt-action="prepare"]');
        button.focus();

        button.click();
        await vi.waitFor(() => expect(document.querySelector('[data-aqt-action="prepare"]')).toBeNull());

        expect(document.activeElement).toBe(document.querySelector('[data-aqt-result]'));
        expect(document.activeElement.textContent).toBe('Not safe');
    });

    it('keeps the input when the text is empty and sends nothing', async () => {
        await loaded();
        document.querySelector('[data-aqt-alt]').value = '   ';

        document.querySelector('[data-aqt-action="apply-alt"]').click();
        await vi.waitFor(() => expect(document.querySelector('[data-aqt-result]').hidden).toBe(false));

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});
