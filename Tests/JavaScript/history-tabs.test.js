// @vitest-environment jsdom

import {beforeEach, describe, expect, it} from 'vitest';
import {initHistoryTabs} from '../../Resources/Public/JavaScript/backend/core/history-tabs.js';

// Atlas D9: the frontend page detail shows the history of a URL in two tabs, Preview scans and Public scans.
const render = (selected = 'preview') => {
    document.body.innerHTML = `
        <div role="tablist" data-aqg-history-tabs="true" aria-label="Scan history">
            <button type="button" role="tab" id="t-prev" aria-controls="p-prev" aria-selected="${selected === 'preview'}" tabindex="${selected === 'preview' ? 0 : -1}">Preview scans (3)</button>
            <button type="button" role="tab" id="t-pub" aria-controls="p-pub" aria-selected="${selected === 'public'}" tabindex="${selected === 'public' ? 0 : -1}">Public scans (0)</button>
        </div>
        <div role="tabpanel" id="p-prev" ${selected === 'preview' ? '' : 'hidden'}>preview</div>
        <div role="tabpanel" id="p-pub" ${selected === 'public' ? '' : 'hidden'}>No public scan yet</div>`;
    initHistoryTabs(document);
};

const tab = (id) => document.getElementById(id);

describe('page history tabs', () => {
    beforeEach(() => render());

    it('shows the panel of the selected tab only', () => {
        tab('t-pub').click();

        expect(tab('t-pub').getAttribute('aria-selected')).toBe('true');
        expect(tab('t-prev').getAttribute('aria-selected')).toBe('false');
        expect(tab('p-pub').hidden).toBe(false);
        expect(tab('p-prev').hidden).toBe(true);
        expect([tab('t-prev').tabIndex, tab('t-pub').tabIndex]).toEqual([-1, 0]);
    });

    it('moves between tabs with the arrow keys, Home and End', () => {
        tab('t-prev').focus();
        tab('t-prev').dispatchEvent(new KeyboardEvent('keydown', {key: 'ArrowRight', bubbles: true}));

        expect(document.activeElement).toBe(tab('t-pub'));
        expect(tab('p-pub').hidden).toBe(false);

        tab('t-pub').dispatchEvent(new KeyboardEvent('keydown', {key: 'Home', bubbles: true}));
        expect(document.activeElement).toBe(tab('t-prev'));
        expect(tab('p-prev').hidden).toBe(false);
    });

    it('opens on the tab the server selected (the kind of the scan shown)', () => {
        render('public');

        expect(tab('p-pub').hidden).toBe(false);
        expect(tab('p-prev').hidden).toBe(true);
    });
});
