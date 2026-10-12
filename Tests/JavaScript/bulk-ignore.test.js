// @vitest-environment jsdom

import {beforeAll, beforeEach, describe, expect, it} from 'vitest';
import {A11yFreeBackendModule} from '../../Resources/Public/JavaScript/backend/free/free-module.js';

// Audit A4/A5: closing the bulk-ignore confirmation left focus on <body>, and its breakdown listed raw rule IDs under an
// untranslated, monospaced "SELECTION BREAKDOWN".

let module;
beforeAll(() => {
    globalThis.TYPO3 = {lang: {}};
});

beforeEach(() => {
    document.body.innerHTML = `
        <article data-aqg-rule-card="true" data-rule-id="rte.empty_heading">
            <span class="aqg-rulecard__title">Empty heading</span>
            <details data-aqg-menu="true" open>
                <summary id="menu">More actions</summary>
                <button type="button" id="rule-page" data-action="a11y-bulk-open-rule-page" data-rule-id="rte.empty_heading" data-rule-count="1">Ignore all on this page</button>
            </details>
            <ul><li data-a11y-issue-card="true" data-issue-uid="11" data-rule-id="rte.empty_heading"></li></ul>
        </article>
        <div data-a11y-bulkbar="true">
            <button type="button" id="open" data-action="a11y-bulk-open-confirm">Ignore selected</button>
        </div>
        <form data-a11y-bulk-confirm="true" data-batch-url="/batch" hidden>
            <h2 data-a11y-bulk-confirm-title="true"></h2>
            <p data-a11y-bulk-confirm-sub="true"></p>
            <button type="button" id="close" data-action="a11y-bulk-close-confirm">x</button>
            <div data-a11y-bulk-summary="true"></div>
            <div data-a11y-bulk-selected-inputs="true"></div>
            <textarea data-a11y-bulk-reason="true"></textarea>
            <div data-a11y-bulk-error="true" hidden></div>
        </form>`;
    module = Object.create(A11yFreeBackendModule.prototype);
    module.translations = {};
    module.batchSelection = new Set(['11']);
});

describe('bulk ignore confirmation', () => {
    it('names the rule by its title and returns focus to the opener when it closes', () => {
        const opener = document.getElementById('open');
        opener.focus();

        module.openBatchConfirm();
        const form = document.querySelector('[data-a11y-bulk-confirm]');
        expect(form.hidden).toBe(false);
        expect(document.activeElement).toBe(form.querySelector('textarea'));
        const summary = form.querySelector('[data-a11y-bulk-summary]').textContent.replace(/\s+/g, ' ');
        expect(summary).toContain('Selected issues by rule');
        expect(summary).toContain('Empty heading');
        expect(summary).toContain('1 issue');

        module.closeBatchConfirm(true, true);
        expect(form.hidden).toBe(true);
        expect(document.activeElement).toBe(opener);
    });

    it('returns focus to the More actions menu when a rule-level ignore opened from it is closed', () => {
        const item = document.getElementById('rule-page');
        item.focus();

        module.openBatchRuleConfirm(item, 'rule-page');
        // The menu closes once the confirmation takes focus; its item can no longer be focused.
        document.querySelector('details[data-aqg-menu]').open = false;
        document.querySelector('[data-a11y-bulkbar]').hidden = true;
        module.closeBatchConfirm(true, true);

        expect(document.activeElement).toBe(document.getElementById('menu'));
    });
});
