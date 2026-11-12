// @vitest-environment jsdom

import {afterEach, beforeAll, describe, expect, it, vi} from 'vitest';

// Atlas audit F-30: the plain HTML line panel listed every finding of a line with its AI block and outgrew the form.
// Atlas 03 · A shows one finding with "N issues on this line" and a pager.

const mountPlainHtml = (name) => {
    const wrapper = document.createElement('div');
    wrapper.innerHTML = `
        <textarea name="${name}"><p>Text</p></textarea>
        <div class="js-aqg-plain-html-a11y" data-record-uid="7" data-field-name="bodytext" data-input-name="${name}" data-labels='{}'>
            <div class="ck-a11y-summary"></div>
            <div class="aqg-plain-html-a11y__issues"></div>
        </div>`;
    document.body.append(wrapper);
    document.dispatchEvent(new Event('typo3:formengine:field-added'));

    return wrapper.querySelector('.js-aqg-plain-html-a11y');
};

const issue = (ruleId, title) => ({severity: 'critical', ruleId, ruleTitle: title, message: title, fingerprint: ruleId});

describe('plain HTML line panel', () => {
    beforeAll(async () => {
        vi.useFakeTimers();
        globalThis.fetch = vi.fn(() => new Promise(() => {}));
        await import('../../Resources/Public/JavaScript/plain-html-a11y.js');
    });

    afterEach(() => {
        vi.clearAllTimers();
        document.body.innerHTML = '';
    });

    it('shows one finding of a line at a time and steps with the pager', () => {
        const validator = mountPlainHtml('data[tt_content][7][bodytext]').__aqgPlainHtmlValidator;
        if (!validator.editorPanel) {
            validator.createEditorPanel();
        }
        validator.displayedIssues = [issue('a', 'Button has no accessible name'), issue('b', 'Iframe has no title'), issue('c', 'Heading is empty')];
        const badge = document.createElement('button');
        badge.dataset.aqgIssueIndexes = '0,1,2';
        document.body.append(badge);

        validator.showEditorPanel([0, 1, 2], badge);
        const panel = validator.editorPanel;
        expect(panel.querySelectorAll('.ck-a11y-panel__title')).toHaveLength(1);
        expect(panel.querySelector('.ck-a11y-panel__title').textContent).toContain('Button has no accessible name');
        expect(panel.querySelector('.aqg-code-html-panel__switcher').textContent).toContain('3 issues on this line');
        expect(panel.querySelector('.ck-a11y-popover__position').textContent).toBe('1 of 3');
        expect(panel.querySelector('.js-aqg-code-panel-step[data-step="-1"]').disabled).toBe(true);

        panel.querySelector('.js-aqg-code-panel-step[data-step="1"]').click();
        expect(panel.querySelector('.ck-a11y-panel__title').textContent).toContain('Iframe has no title');
        expect(panel.querySelector('.ck-a11y-popover__position').textContent).toBe('2 of 3');
        expect(document.activeElement).toBe(panel.querySelector('.js-aqg-code-panel-step[data-step="1"]'));
    });

    it('has no pager for a single finding', () => {
        const validator = mountPlainHtml('data[tt_content][8][bodytext]').__aqgPlainHtmlValidator;
        if (!validator.editorPanel) {
            validator.createEditorPanel();
        }
        validator.displayedIssues = [issue('a', 'Button has no accessible name')];
        const badge = document.createElement('button');
        badge.dataset.aqgIssueIndexes = '0';
        document.body.append(badge);

        validator.showEditorPanel([0], badge);
        expect(validator.editorPanel.querySelector('.aqg-code-html-panel__switcher')).toBeNull();
    });
});
