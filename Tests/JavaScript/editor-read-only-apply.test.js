// @vitest-environment jsdom

import {afterEach, beforeAll, describe, expect, it, vi} from 'vitest';
import {applyCkeditorChange} from '../../Resources/Public/JavaScript/editor-ai/ckeditor-target.js';
import {EditorAiWriting} from '../../Resources/Public/JavaScript/editor-ai/editor-ai-writing.js';
import {EditorAiSession} from '../../Resources/Public/JavaScript/editor-ai/editor-ai-session.js';

// Regression (3.0 inline editor advice QA, reproduced on TYPO3 13.4): with TCEFORM "readOnly" on bodytext, TYPO3 shows
// CKEditor in read-only mode and the code editor read-only, but still submits the field, and DataHandler does not
// enforce readOnly. AQG offered "Fix with AI" / "Review fixes" there; "Apply" changed the field and TYPO3 Save stored
// it. A read-only field now gets no AI or rule-based action, and no AQG write path changes it.

const readOnlyEditor = () => ({isReadOnly: true, model: {change: vi.fn(), markers: {get: vi.fn()}}});

describe('read-only CKEditor', () => {
    it('never applies a reviewed change (AI, rule-based heading level, approved queue change)', () => {
        const editor = readOnlyEditor();
        const target = {tag: 'h3', operation: 'text', element: {hasChildElements: false}};

        expect(applyCkeditorChange(editor, 'aqg:1', target, {tag: 'h3', attributes: {}, text: ''}, 'Heading')).toEqual({ok: false, reason: 'read_only'});
        expect(editor.model.change).not.toHaveBeenCalled();
    });

    it('never applies a writing suggestion', () => {
        const editor = readOnlyEditor();
        const writing = Object.create(EditorAiWriting.prototype);
        Object.assign(writing, {editor, value: 'Clearer text', markerName: 'aqg-writing', announce: vi.fn(), closeDialog: vi.fn()});

        writing.apply();
        expect(editor.model.change).not.toHaveBeenCalled();
    });

    it('offers no AI or rule-based action for the read-only configuration the server renders', () => {
        const config = {state: 'read_only', isAdmin: false, rules: []};
        const issue = {ruleId: 'rte.empty_heading', aiTarget: {ruleId: 'rte.empty_heading', kind: 'heading_text', operation: 'text'}};

        expect(EditorAiSession.isEligible(issue, config)).toBe(false);
        expect(EditorAiSession.offersFix(issue, config)).toBe(false);
        // No "AI is not set up" style line either: nothing is shown for it.
        expect(EditorAiSession.quietLine(config)).toBeNull();
    });
});

describe('read-only plain HTML field', () => {
    const mount = (readOnly) => {
        const name = `data[tt_content][${readOnly ? 71 : 72}][bodytext]`;
        const wrapper = document.createElement('div');
        wrapper.innerHTML = `
            <textarea name="${name}"><button></button></textarea>
            <div class="js-aqg-plain-html-a11y" data-record-uid="${readOnly ? 71 : 72}" data-field-name="bodytext" data-input-name="${name}" data-labels='{}' data-ai='{"state":"${readOnly ? 'read_only' : 'ready'}","rules":[]}'${readOnly ? ' data-read-only="1"' : ''}>
                <div class="ck-a11y-summary"></div>
                <div class="aqg-plain-html-a11y__issues"></div>
            </div>`;
        document.body.append(wrapper);
        document.dispatchEvent(new Event('typo3:formengine:field-added'));
        return {validator: wrapper.querySelector('.js-aqg-plain-html-a11y').__aqgPlainHtmlValidator, textarea: wrapper.querySelector('textarea')};
    };

    beforeAll(async () => {
        vi.useFakeTimers();
        globalThis.fetch = vi.fn(() => new Promise(() => {}));
        await import('../../Resources/Public/JavaScript/plain-html-a11y.js');
    });

    afterEach(() => {
        vi.clearAllTimers();
        document.body.innerHTML = '';
    });

    it('never writes into the submitted source (AI fix, review dialog, approved queue change)', () => {
        const {validator, textarea} = mount(true);

        expect(validator.setSourceValue({from: 7, to: 7, insert: ' aria-label="Menu"'})).toBe(false);
        expect(textarea.value).toBe('<button></button>');
    });

    it('still writes into an editable field', () => {
        const {validator, textarea} = mount(false);

        expect(validator.setSourceValue({from: 7, to: 7, insert: ' aria-label="Menu"'})).toBe(true);
        expect(textarea.value).toBe('<button aria-label="Menu"></button>');
    });
});
