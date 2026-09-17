// @vitest-environment jsdom

import {afterEach, describe, expect, it} from 'vitest';
import {initializeQualityGateSettings} from '../../Resources/Public/JavaScript/backend/settings-quality-gate.js';

// jsdom does not implement CSS.escape; the settings script only escapes plain site identifiers with it.
globalThis.CSS ??= {};
globalThis.CSS.escape ??= (value) => String(value).replace(/["\\]/g, '\\$&');

const siteRow = (identifier, label, {critical = '0', warning = '-1'} = {}) => `
    <div class="aqg-site js-aqg-site-row" data-site-identifier="${identifier}">
        <header>
            <span class="aqg-site__title">${label}</span>
            <span class="js-aqg-mode-pill"><span class="js-aqg-mode-label">Warn editors</span></span>
            <span class="js-aqg-site-summary-critical"></span>
            <span class="js-aqg-site-summary-warning"></span>
            <button type="button" class="js-aqg-remove-site">Remove</button>
        </header>
        <details class="js-aqg-site-details">
            <summary>Edit mode and thresholds</summary>
            <label for="aqg-site-${identifier}-publish-mode">Publishing mode</label>
            <select class="js-aqg-site-mode" id="aqg-site-${identifier}-publish-mode" name="qualityGate[sites][${identifier}][publish_mode]">
                <option value="0">Disabled</option>
                <option value="1" selected>Warn</option>
                <option value="2">Block</option>
            </select>
            <label for="aqg-site-${identifier}-threshold-critical">Critical threshold</label>
            <input type="number" min="0" class="js-aqg-site-critical" id="aqg-site-${identifier}-threshold-critical" value="${critical}">
            <label for="aqg-site-${identifier}-threshold-warning">Warning threshold</label>
            <select class="js-aqg-site-warning" id="aqg-site-${identifier}-threshold-warning">
                <option value="-1" ${warning === '-1' ? 'selected' : ''}>Ignore warnings</option>
                <option value="3" ${warning === '3' ? 'selected' : ''}>3 warnings allowed</option>
            </select>
        </details>
    </div>
`;

const renderForm = (rows = '') => {
    document.body.innerHTML = `
        <form class="js-aqg-quality-gate-form"
              data-label-summary-critical="Critical issues allowed: %d"
              data-label-summary-warnings="Warnings allowed: %d"
              data-label-summary-warnings-ignored="Warnings ignored">
            <input type="hidden" class="js-aqg-quality-gate-scope-input" value="0">
            <div class="js-aqg-scope-panel-per-site is-active">
                <button type="button" class="js-aqg-addsite-toggle">Add site override</button>
                <div class="js-aqg-addsite-panel" hidden>
                    <select class="js-aqg-addsite-select">
                        <option value="blog" data-label="Blog">Blog</option>
                    </select>
                    <button type="button" class="js-aqg-addsite-confirm">Add</button>
                </div>
                <div class="js-aqg-sitelist">${rows}</div>
                <template class="js-aqg-site-row-template">${siteRow('__AQG_SITE_IDENTIFIER__', '__AQG_SITE_LABEL__')}</template>
            </div>
        </form>
    `;
    const form = document.querySelector('form');
    initializeQualityGateSettings(form);

    return form;
};

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Quality Gate site overrides', () => {
    it('summarises each override while its fields stay collapsed', () => {
        const form = renderForm(siteRow('main', 'Main site', {critical: '2', warning: '3'}));
        const row = form.querySelector('[data-site-identifier="main"]');

        expect(row.querySelector('.js-aqg-site-details').open).toBe(false);
        expect(row.querySelector('.js-aqg-site-summary-critical').textContent).toBe('Critical issues allowed: 2');
        expect(row.querySelector('.js-aqg-site-summary-warning').textContent).toBe('Warnings allowed: 3');
    });

    it('keeps the summary in step with the edited thresholds', () => {
        const form = renderForm(siteRow('main', 'Main site'));
        const row = form.querySelector('[data-site-identifier="main"]');
        const critical = row.querySelector('.js-aqg-site-critical');
        const warning = row.querySelector('.js-aqg-site-warning');

        critical.value = '4';
        critical.dispatchEvent(new Event('input', {bubbles: true}));
        warning.value = '3';
        warning.dispatchEvent(new Event('change', {bubbles: true}));

        expect(row.querySelector('.js-aqg-site-summary-critical').textContent).toBe('Critical issues allowed: 4');
        expect(row.querySelector('.js-aqg-site-summary-warning').textContent).toBe('Warnings allowed: 3');

        warning.value = '-1';
        warning.dispatchEvent(new Event('change', {bubbles: true}));
        expect(row.querySelector('.js-aqg-site-summary-warning').textContent).toBe('Warnings ignored');
    });

    it('opens a new override for editing with labelled fields bound to its site', () => {
        const form = renderForm();

        form.querySelector('.js-aqg-addsite-confirm').click();

        const row = form.querySelector('[data-site-identifier="blog"]');
        expect(row).not.toBeNull();
        expect(row.querySelector('.js-aqg-site-details').open).toBe(true);
        const mode = row.querySelector('.js-aqg-site-mode');
        expect(document.activeElement).toBe(mode);
        expect(mode.id).toBe('aqg-site-blog-publish-mode');
        for (const field of row.querySelectorAll('select, input')) {
            expect(row.querySelector(`label[for="${field.id}"]`)).not.toBeNull();
        }
        expect(row.querySelector('.js-aqg-site-summary-critical').textContent).toBe('Critical issues allowed: 0');
    });

    it('reveals a collapsed override when one of its fields blocks the submit', () => {
        const form = renderForm(siteRow('main', 'Main site'));
        const row = form.querySelector('[data-site-identifier="main"]');
        const critical = row.querySelector('.js-aqg-site-critical');

        critical.value = '-3';
        expect(critical.checkValidity()).toBe(false);

        expect(row.querySelector('.js-aqg-site-details').open).toBe(true);
    });
});
