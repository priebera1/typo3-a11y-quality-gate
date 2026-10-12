// @vitest-environment jsdom

import {afterEach, beforeAll, describe, expect, it} from 'vitest';
import {A11yBaseModule} from '../../Resources/Public/JavaScript/backend/core/base-module.js';
import {A11yProBackendModule} from '../../Resources/Public/JavaScript/backend/pro/pro-module.js';

// The scan views disclose rules, details and "show more" lists with buttons (aria-expanded + aria-controls),
// and the Overview switches between its scans with an ARIA tablist.

// One module for the whole file: it listens on the document, a second instance would toggle every click twice.
let module;
beforeAll(() => {
    globalThis.TYPO3 = {lang: {}};
    module = new A11yBaseModule();
});

afterEach(() => {
    document.body.innerHTML = '';
    window.history.replaceState(null, '', '/');
});

const mountRules = () => {
    document.body.innerHTML = `
        <div id="rules">
            <button type="button" id="all" data-aqg-toggle-all="[data-aqg-rule-toggle]" data-aqg-toggle-scope="#rules"
                    data-label-expand="Expand all" data-label-collapse="Collapse all">Expand all</button>
            <article id="rule-a" data-aqg-rule-card="true">
                <h3><button type="button" id="toggle-a" data-aqg-toggle="true" data-aqg-rule-toggle="true" aria-expanded="true" aria-controls="body-a">A</button></h3>
                <div id="body-a"><a id="inside-a" href="#x">x</a></div>
            </article>
            <article id="rule-b" data-aqg-rule-card="true">
                <h3><button type="button" id="toggle-b" data-aqg-toggle="true" data-aqg-rule-toggle="true" aria-expanded="false" aria-controls="body-b">B</button></h3>
                <div id="body-b" hidden><p id="inside-b">b</p></div>
            </article>
            <ol id="more" hidden><li><h3 id="first-more">Fix 6</h3></li></ol>
            <button type="button" id="show-more" data-aqg-toggle="true" data-aqg-focus-first="h3" aria-expanded="false"
                    aria-controls="more" data-label-closed="Show 5 more" data-label-open="Show fewer">Show 5 more</button>
            <details id="evidence"><summary>Checklist</summary><p>Tasks</p></details>
            <a id="to-evidence" href="#evidence" data-aqg-reveal="true">Checklist</a>
        </div>`;
};

describe('scan view disclosures', () => {
    it('toggles the controlled element and keeps aria-expanded in sync', () => {
        mountRules();

        document.querySelector('#toggle-b').click();
        expect(document.querySelector('#toggle-b').getAttribute('aria-expanded')).toBe('true');
        expect(document.querySelector('#body-b').hidden).toBe(false);

        document.querySelector('#toggle-a').click();
        expect(document.querySelector('#toggle-a').getAttribute('aria-expanded')).toBe('false');
        expect(document.querySelector('#body-a').hidden).toBe(true);
    });

    it('expands every rule when one is closed, collapses them all otherwise, and names what it will do', () => {
        mountRules();
        const all = document.querySelector('#all');

        expect(all.textContent).toBe('Expand all');
        all.click();
        expect(document.querySelector('#body-b').hidden).toBe(false);
        expect(all.textContent).toBe('Collapse all');
        all.click();
        expect(document.querySelector('#body-a').hidden).toBe(true);
        expect(document.querySelector('#body-b').hidden).toBe(true);
        expect(all.textContent).toBe('Expand all');
    });

    it('moves the focus to the first item a "show more" button reveals', () => {
        mountRules();
        const more = document.querySelector('#show-more');

        more.click();
        expect(document.querySelector('#more').hidden).toBe(false);
        expect(document.activeElement).toBe(document.querySelector('#first-more'));
        expect(more.textContent).toBe('Show fewer');
        expect(more.getAttribute('aria-expanded')).toBe('true');
    });

    it('opens a collapsed rule or a closed evidence row before a link or the URL fragment lands on it', () => {
        mountRules();

        module.revealTarget('inside-b');
        expect(document.querySelector('#body-b').hidden).toBe(false);
        expect(document.querySelector('#toggle-b').getAttribute('aria-expanded')).toBe('true');

        document.querySelector('#to-evidence').click();
        expect(document.querySelector('#evidence').open).toBe(true);
    });
});

describe('Overview source tabs', () => {
    it('selects tabs with the arrow, Home and End keys and keeps one tab in the tab order', () => {
        document.body.innerHTML = `
            <div role="tablist">
                <button type="button" role="tab" id="tab-local" data-a11y-overview-source-trigger="local" aria-selected="true" aria-controls="panel-local" tabindex="0">Content scan</button>
                <button type="button" role="tab" id="tab-remote" data-a11y-overview-source-trigger="remote" aria-selected="false" aria-controls="panel-remote" tabindex="-1">Frontend scan</button>
            </div>
            <div role="tabpanel" id="panel-local" data-a11y-overview-panel="local"></div>
            <div role="tabpanel" id="panel-remote" data-a11y-overview-panel="remote" hidden></div>`;
        const context = {
            activeOverviewSource: 'local',
            applyOverviewSearchForInput: () => {},
        };
        context.setOverviewSource = (source) => A11yProBackendModule.prototype.setOverviewSource.call(context, source);
        A11yProBackendModule.prototype.initOverviewSourceTabs.call(context);

        const local = document.querySelector('#tab-local');
        const remote = document.querySelector('#tab-remote');
        local.focus();
        local.dispatchEvent(new KeyboardEvent('keydown', {key: 'ArrowRight', bubbles: true}));

        expect(document.activeElement).toBe(remote);
        expect(remote.getAttribute('aria-selected')).toBe('true');
        expect(remote.tabIndex).toBe(0);
        expect(local.tabIndex).toBe(-1);
        expect(document.querySelector('#panel-remote').hidden).toBe(false);
        expect(document.querySelector('#panel-local').hidden).toBe(true);

        remote.dispatchEvent(new KeyboardEvent('keydown', {key: 'Home', bubbles: true}));
        expect(document.activeElement).toBe(local);
        expect(local.getAttribute('aria-selected')).toBe('true');
        expect(remote.hasAttribute('aria-pressed')).toBe(false);
    });
});
