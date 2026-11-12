// @vitest-environment jsdom

import {afterEach, describe, expect, it} from 'vitest';
import {initSettingsGroups} from '../../Resources/Public/JavaScript/backend/settings-groups.js';

// Scan fields / Rules group header (Atlas audit F-25): the title is the disclosure button, the bulk button its sibling.
const group = (expanded) => `
    <div class="aqg-rgroup${expanded ? ' is-open' : ''}" data-aqg-group-disclosure="true">
        <div class="aqg-rgroup__head">
            <button type="button" id="g-toggle" aria-expanded="${expanded}" aria-controls="g-body" data-aqg-group-toggle="true">RTE content rules</button>
            <button type="button" class="aqg-rgroup__bulk" data-bulk="true">Enable all in group</button>
        </div>
        <div class="aqg-rgroup__body" id="g-body" ${expanded ? '' : 'hidden'}><label><input type="checkbox"> Rule</label></div>
    </div>`;

describe('settings group disclosure', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('toggles aria-expanded, the body and the open class from the title button', () => {
        document.body.innerHTML = group(false);
        initSettingsGroups(document);
        const toggle = document.getElementById('g-toggle');
        const body = document.getElementById('g-body');
        const root = document.querySelector('[data-aqg-group-disclosure]');

        toggle.click();
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        expect(body.hidden).toBe(false);
        expect(root.classList.contains('is-open')).toBe(true);

        toggle.click();
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(body.hidden).toBe(true);
        expect(root.classList.contains('is-open')).toBe(false);
    });

    it('leaves the group as it is when the bulk button is used', () => {
        document.body.innerHTML = group(true);
        initSettingsGroups(document);

        document.querySelector('[data-bulk]').click();

        expect(document.getElementById('g-toggle').getAttribute('aria-expanded')).toBe('true');
        expect(document.getElementById('g-body').hidden).toBe(false);
    });

    it('binds each group once', () => {
        document.body.innerHTML = group(false);
        initSettingsGroups(document);
        initSettingsGroups(document);

        document.getElementById('g-toggle').click();

        expect(document.getElementById('g-toggle').getAttribute('aria-expanded')).toBe('true');
    });
});
