// @vitest-environment jsdom

import {beforeAll, beforeEach, describe, expect, it} from 'vitest';
import {A11yFreeBackendModule} from '../../Resources/Public/JavaScript/backend/free/free-module.js';

// Product acceptance (Rules tab): searching "descriptive" said "3/34 on" while the matching rules stayed inside a
// collapsed group, so nothing matching was visible until the user opened the group by hand.

beforeAll(() => {
    globalThis.TYPO3 = {lang: {}};
});

beforeEach(() => {
    document.body.innerHTML = `
        <form data-a11y-rules-form="true">
            <input type="search" data-a11y-rules-search="true">
            <div class="aqg-rgroup" data-a11y-rule-group="true">
                <button type="button" data-aqg-group-toggle="true" aria-expanded="false" aria-controls="body-rte">RTE content rules</button>
                <div class="aqg-rgroup__body" id="body-rte" hidden>
                    <label data-a11y-rule-row="true" data-rule-search="rte.non_descriptive_link link text is not descriptive" data-rule-enabled="1"><input type="checkbox" data-a11y-rules-input="true" checked></label>
                    <label data-a11y-rule-row="true" data-rule-search="rte.img_alt_missing image alt" data-rule-enabled="1"><input type="checkbox" data-a11y-rules-input="true" checked></label>
                </div>
            </div>
        </form>`;
    // The group disclosure itself is settings-groups.js; here a click opens it like that module does.
    const toggle = document.querySelector('[data-aqg-group-toggle]');
    toggle.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        document.getElementById('body-rte').hidden = !open;
    });
    Object.create(A11yFreeBackendModule.prototype).initRulesTab();
});

describe('rules search', () => {
    it('opens a collapsed group that holds matching rules', () => {
        const search = document.querySelector('[data-a11y-rules-search]');
        search.value = 'descriptive';
        search.dispatchEvent(new Event('input', {bubbles: true}));

        expect(document.getElementById('body-rte').hidden).toBe(false);
        const visible = [...document.querySelectorAll('[data-a11y-rule-row]')].filter((row) => !row.hidden);
        expect(visible).toHaveLength(1);
    });

    it('leaves groups collapsed while nothing is searched', () => {
        expect(document.getElementById('body-rte').hidden).toBe(true);
    });
});
