// @vitest-environment jsdom
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';

const settle = async (rounds = 8) => {
    for (let i = 0; i < rounds; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
};
const json = (status, body) => new Response(JSON.stringify(body), {status, headers: {'Content-Type': 'application/json'}});

const config = (overrides = {}) => ({
    mode: 'reference',
    hasFileDefault: true,
    parentTable: 'tt_content',
    parentUid: 512,
    parentField: 'image',
    referenceUid: 33,
    fileUid: 7,
    languageUid: 0,
    translation: false,
    decorativeField: true,
    ai: {site: 'main', providerLabel: 'OpenAI', modelLabel: 'GPT', allowImages: true},
    ...overrides,
});

/**
 * FormEngine markup of sys_file_reference.alternative in "use or override" mode inside a palette field of an IRRE
 * image, as TYPO3 13/14 render it (the header chip comes from MediaAltStatusChipListener).
 */
function renderField({checked = false, value = '', cfg = config(), title = ''} = {}) {
    const name = `data[sys_file_reference][${cfg.referenceUid}][alternative]`;
    document.body.innerHTML = `
        <div data-object-group="data-1-tt_content-512-image-sys_file_reference"><div class="panel form-irre-object">
        <div class="form-irre-header"><div class="form-irre-header-control t3js-formengine-file-header-control"><div class="btn-group"><span class="aqg-media-altchip aqg-media-altchip--missing" data-aqg-alt-chip="missing">ALT missing</span></div></div></div>
        <div class="form-group t3js-formengine-palette-field">
        <label class="form-label t3js-formengine-label" for="alt-input">Alternative Text</label>
        <div class="formengine-field-item t3js-formengine-field-item">
            <div class="form-check t3js-form-field-eval-null-placeholder-checkbox">
                <input type="checkbox" class="form-check-input" name="control[active][sys_file_reference][33][alternative]"${checked ? ' checked' : ''}>
            </div>
            <div class="t3js-formengine-placeholder-placeholder"><div class="form-control-wrap"><input type="text" class="form-control" disabled value="Chart of water prices"></div></div>
            <div class="t3js-formengine-placeholder-formfield"><div class="form-control-wrap"><div class="form-wizards-wrap">
                <div class="form-wizards-item-element"><input type="text" id="alt-input" data-formengine-input-name="${name}" value="${value}"><input type="hidden" name="${name}" value="${value}"></div>
                <div class="form-wizards-item-aside form-wizards-item-aside--field-control"><div class="btn-group"><a class="aqg-media-wand t3js-aqg-media-wand btn btn-default" data-aqg-media-field="alternative" data-aqg-media-table="sys_file_reference" data-aqg-media-input="${name}"
                    data-aqg-media='${JSON.stringify(cfg)}' data-aqg-media-labels='{}'>wand</a></div></div>
            </div></div></div>
        </div>
        </div>
        <input type="text" data-formengine-input-name="data[sys_file_reference][33][title]" value="${title}">
        <input type="checkbox" data-formengine-input-name="data[sys_file_reference][33][tx_a11y_is_decorative]">
        </div></div>`;
}

const suggestion = (body = {}) => vi.fn(async () => json(200, {success: true, status: 'suggestion', value: 'Bar chart: water prices rise to 2.24 EUR', reason: 'Fits the text.', serverTime: 500, provider: 'anthropic', providerLabel: 'Anthropic Claude', model: 'claude-haiku-5-5', modelLabel: 'Claude Haiku 5.5', usage: {}, ...body}));

async function loadModule() {
    vi.resetModules();
    await import('@priebera/a11y-quality-gate/media-ai/media-ai-field.js');
    await settle();
}

beforeEach(() => {
    globalThis.TYPO3 = {settings: {ajaxUrls: {a11y_ai_media_suggest_reference: '/suggest', a11y_ai_media_verify_reference: '/verify', a11y_ai_media_suggest_metadata: '/suggest-meta', a11y_ai_media_verify_file: '/verify-file'}}};
    window.sessionStorage.clear();
    globalThis.CSS ??= {escape: (value) => String(value).replace(/["\\]/g, '\\$&')};
});

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('AI media field wand', () => {
    it('sits in TYPO3\'s field-control slot beside the visible field in both override states', async () => {
        renderField();
        await loadModule();

        const wand = document.querySelector('.t3js-aqg-media-wand');
        // Inherits: beside the read-only file default, in a field-control aside of TYPO3's wizard layout.
        expect(wand.closest('.t3js-formengine-placeholder-placeholder .form-wizards-item-aside--field-control')).not.toBeNull();
        expect(document.querySelector('.aqg-media-wand-slot')).toBeNull();
        expect(wand.getAttribute('aria-expanded')).toBe('false');

        const override = document.querySelector('.t3js-form-field-eval-null-placeholder-checkbox input');
        override.checked = true;
        override.dispatchEvent(new Event('change', {bubbles: true}));
        await settle();
        expect(wand.closest('.t3js-formengine-placeholder-formfield .form-wizards-item-aside--field-control')).not.toBeNull();
        // Never a row of its own above the field.
        const item = document.querySelector('.t3js-formengine-field-item');
        expect([...item.children].some((child) => child.contains(wand) && !child.matches('.t3js-formengine-placeholder-formfield, .t3js-formengine-placeholder-placeholder'))).toBe(false);
    });

    it('anchors the popover below the field without a second field in the field container (Atlas 05 A)', async () => {
        globalThis.fetch = suggestion();
        renderField({checked: true, value: ''});
        await loadModule();
        const wand = document.querySelector('.t3js-aqg-media-wand');
        wand.click();

        const popover = document.querySelector('.aqg-media-popover');
        const item = document.querySelector('.t3js-formengine-field-item');
        expect(item.contains(popover)).toBe(false);
        expect(popover.parentElement.classList.contains('aqg-media-pophost')).toBe(true);
        expect(popover.querySelector('.aqg-media-popover__title').tagName).toBe('H4');
        expect(popover.querySelector('.aqg-media-badge--critical')?.textContent).toBe('Critical');
        expect(popover.querySelector('.aqg-media-chip--local')?.textContent).toBe('This use only');
        expect(popover.textContent).not.toContain('×');

        popover.querySelector('[data-aqg-pop="suggest"]').click();
        await settle();
        expect(item.querySelectorAll('textarea, input[type="text"]:not([disabled])').length).toBe(1);
        // The suggestion is a band of the popover with the model's display name, not its id.
        expect(popover.querySelector('.aqg-media-ai__model')?.textContent).toBe('Claude Haiku 5.5');
        expect(popover.querySelector('.aqg-media-provenance').textContent).toContain('Claude Haiku 5.5');
        expect(popover.querySelector('.aqg-media-provenance').textContent).not.toContain('claude-haiku-5-5');
        // Atlas ink primary: the one primary action uses the btn-primary variant that media-ai.css re-tokenises.
        expect([...popover.querySelectorAll('.btn-primary')].map((button) => button.getAttribute('data-aqg-pop'))).toEqual(['apply']);

        popover.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
        expect(document.querySelector('.aqg-media-popover')).toBeNull();
        expect(document.activeElement).toBe(wand);
    });

    it('keeps the label scope chip and the header ALT chip current', async () => {
        globalThis.fetch = suggestion();
        renderField({checked: true, value: ''});
        await loadModule();

        const label = document.querySelector('.form-label');
        expect(label.nextElementSibling.textContent).toBe('This use only');
        const header = document.querySelector('[data-aqg-alt-chip]');
        expect(header.textContent).toBe('ALT missing');

        document.querySelector('.t3js-aqg-media-wand').click();
        document.querySelector('[data-aqg-pop="suggest"]').click();
        await settle();
        document.querySelector('[data-aqg-pop="apply"]').click();
        expect(header.getAttribute('data-aqg-alt-chip')).toBe('unsaved');
        expect(header.textContent).toBe('Filled in, unsaved');

        // The shared ladder: words and classes carry the state, no glyphs; Verified is not reached before Save.
        const ladder = document.querySelector('.aqg-media-popover ol.aqg-ladder');
        expect(ladder).not.toBeNull();
        expect(ladder.querySelector('li.is-current[aria-current="step"]').textContent).toBe('Filled in, unsaved');
        expect(ladder.querySelectorAll('li.is-done').length).toBe(1);
        expect(ladder.querySelector('.is-verified')).toBeNull();
        expect(ladder.textContent).not.toMatch(/[\u2713\u2714]/);

        document.querySelector('[data-aqg-pop="undo"]').click();
        expect(header.textContent).toBe('ALT missing');

        const decorative = document.querySelector('[data-formengine-input-name="data[sys_file_reference][33][tx_a11y_is_decorative]"]');
        decorative.checked = true;
        decorative.dispatchEvent(new Event('change', {bubbles: true}));
        expect(header.textContent).toBe('Decorative');
    });

    it('rates a missing text Warning while a title stands in, like FileReferenceAltRule', async () => {
        renderField({checked: true, value: '', title: 'Water prices'});
        await loadModule();
        document.querySelector('.t3js-aqg-media-wand').click();
        expect(document.querySelector('.aqg-media-popover .aqg-media-badge--warning')?.textContent).toBe('Warning');
    });

    it('opens one popover per image and repeated editing keeps one popover per wand', async () => {
        globalThis.fetch = suggestion();
        renderField({checked: true, value: ''});
        await loadModule();
        const wand = document.querySelector('.t3js-aqg-media-wand');
        for (let i = 0; i < 3; i++) {
            wand.click();
            expect(document.querySelectorAll('.aqg-media-popover').length).toBe(1);
            wand.click();
            expect(document.querySelectorAll('.aqg-media-popover').length).toBe(0);
        }
    });

    it('applies a reviewed suggestion to this use only and can undo it', async () => {
        const calls = [];
        globalThis.fetch = vi.fn(async (url, init) => {
            calls.push({url, body: JSON.parse(init.body)});
            return json(200, {success: true, status: 'suggestion', value: 'Bar chart: water prices rise to 2.24 EUR', reason: 'Fits the text.', serverTime: 500, provider: 'openai', model: 'gpt', usage: {}});
        });
        renderField();
        await loadModule();
        const wand = document.querySelector('.t3js-aqg-media-wand');
        wand.click();
        expect(wand.getAttribute('aria-expanded')).toBe('true');
        expect(document.activeElement?.tagName).toBe('H4');
        document.querySelector('[data-aqg-pop="suggest"]').click();
        await settle();

        expect(calls[0].body).toMatchObject({parentTable: 'tt_content', parentUid: 512, fieldName: 'image', referenceUid: 33, currentAlt: '', mode: 'suggest'});
        const input = document.querySelector('[data-formengine-input-name="data[sys_file_reference][33][alternative]"]');
        const changes = vi.fn();
        input.addEventListener('change', changes);
        document.querySelector('[data-aqg-pop="apply"]').click();

        expect(input.value).toBe('Bar chart: water prices rise to 2.24 EUR');
        expect(changes).toHaveBeenCalled();
        expect(document.querySelector('.t3js-form-field-eval-null-placeholder-checkbox input').checked).toBe(true);
        expect(document.querySelector('.aqg-media-popover').textContent).toContain('not saved yet');
        expect(JSON.parse(sessionStorage.getItem('aqg-media-ai-pending:tt_content:512:image'))[0]).toMatchObject({referenceUid: 33, suggestedAt: 500});

        document.querySelector('[data-aqg-pop="undo"]').click();
        expect(input.value).toBe('');
        expect(document.querySelector('.t3js-form-field-eval-null-placeholder-checkbox input').checked).toBe(false);
        expect(sessionStorage.getItem('aqg-media-ai-pending:tt_content:512:image')).toBeNull();
    });

    it('explains a refusal by the image switches of the file\'s uses and offers no retry', async () => {
        globalThis.fetch = vi.fn(async () => json(422, {success: false, status: 'error', code: 'privacy_images_usage'}));
        renderField();
        await loadModule();
        document.querySelector('.t3js-aqg-media-wand').click();
        document.querySelector('[data-aqg-pop="suggest"]').click();
        await settle();

        const popover = document.querySelector('.aqg-media-popover');
        expect(popover.textContent).toContain('is used on a site that does not allow sending images');
        expect(popover.querySelector('[data-aqg-pop="suggest"]')).toBeNull();
    });

    it('never overwrites what the editor typed while the suggestion was prepared', async () => {
        let release;
        globalThis.fetch = vi.fn(() => new Promise((resolve) => {
            release = () => resolve(json(200, {success: true, status: 'suggestion', value: 'AI text', reason: '', serverTime: 1, usage: {}}));
        }));
        renderField({checked: true, value: 'Draft'});
        await loadModule();
        document.querySelector('.t3js-aqg-media-wand').click();
        document.querySelector('[data-aqg-pop="suggest"]').click();
        const input = document.querySelector('[data-formengine-input-name="data[sys_file_reference][33][alternative]"]');
        input.value = 'Draft typed further';
        release();
        await settle();
        document.querySelector('[data-aqg-pop="apply"]').click();

        expect(input.value).toBe('Draft typed further');
        expect(document.querySelector('.aqg-media-popover').textContent).toContain('Nothing was applied');
    });

    it('offers the file default without AI for an empty override and hides itself for decorative images', async () => {
        globalThis.fetch = vi.fn();
        renderField({checked: true, value: ''});
        await loadModule();
        document.querySelector('.t3js-aqg-media-wand').click();
        document.querySelector('[data-aqg-pop="use-default"]').click();

        expect(document.querySelector('.t3js-form-field-eval-null-placeholder-checkbox input').checked).toBe(false);
        expect(globalThis.fetch).not.toHaveBeenCalled();

        const decorative = document.querySelector('[data-formengine-input-name="data[sys_file_reference][33][tx_a11y_is_decorative]"]');
        decorative.checked = true;
        decorative.dispatchEvent(new Event('change', {bubbles: true}));
        expect(document.querySelector('.t3js-aqg-media-wand').hidden).toBe(true);
    });

    it('marks decorative only when the editor chooses it', async () => {
        globalThis.fetch = vi.fn(async () => json(200, {success: true, status: 'decorative', value: '', reason: 'An ornament.', usage: {}}));
        renderField();
        await loadModule();
        document.querySelector('.t3js-aqg-media-wand').click();
        document.querySelector('[data-aqg-pop="suggest"]').click();
        await settle();
        const decorative = document.querySelector('[data-formengine-input-name="data[sys_file_reference][33][tx_a11y_is_decorative]"]');

        expect(decorative.checked).toBe(false);
        document.querySelector('[data-aqg-pop="mark-decorative"]').click();
        expect(decorative.checked).toBe(true);
    });

    it('verifies after the form reloads and reports an unsaved change honestly', async () => {
        sessionStorage.setItem('aqg-media-ai-pending:tt_content:512:image', JSON.stringify([{inputName: 'data[sys_file_reference][33][alternative]', referenceUid: 33, fileUid: 7, field: 'alternative', suggestedAt: 500}]));
        const calls = [];
        globalThis.fetch = vi.fn(async (url, init) => {
            calls.push({url, body: JSON.parse(init.body)});
            return json(200, {success: true, status: 'checked', items: [{referenceUid: 33, status: 'not_saved'}]});
        });
        renderField({checked: true, value: 'Old'});
        await loadModule();

        expect(calls[0]).toEqual({url: '/verify', body: {parentTable: 'tt_content', parentUid: 512, fieldName: 'image', items: [{referenceUid: 33, suggestedAt: 500}]}});
        expect(document.querySelector('.aqg-media-outcome').textContent).toContain('The change was not saved.');
        expect(sessionStorage.getItem('aqg-media-ai-pending:tt_content:512:image')).toBeNull();
    });

    it('in the metadata form only fills the field: TYPO3 Save stores it, AQG writes nothing', async () => {
        const calls = [];
        globalThis.fetch = vi.fn(async (url, init) => {
            calls.push({url, body: JSON.parse(init.body)});
            return json(200, {success: true, status: 'suggestion', mode: 'image', proposals: {alternative: {value: 'Bar chart of water prices', rejection: ''}}, provider: 'openai', model: 'gpt', usage: {}});
        });
        const cfg = {mode: 'metadata', metadataUid: 50, fileUid: 5, languageUid: 0, translation: false,
            uses: {total: 2, inheriting: 1, overriding: 1, decorative: 0, hidden: 0},
            sites: [{identifier: 'main', label: 'Main', site: 'main', providerLabel: 'OpenAI', modelLabel: 'GPT', allowImages: true}]};
        const name = 'data[sys_file_metadata][50][alternative]';
        document.body.innerHTML = `<div class="formengine-field-item t3js-formengine-field-item">
            <div class="form-wizards-item-element"><input type="text" data-formengine-input-name="${name}" value=""><input type="hidden" name="${name}" value=""></div>
            <div class="form-wizards-item-aside"><a class="aqg-media-wand t3js-aqg-media-wand btn btn-default" data-aqg-media-field="alternative" data-aqg-media-table="sys_file_metadata" data-aqg-media-input="${name}"
                data-aqg-media='${JSON.stringify(cfg)}' data-aqg-media-labels='{}'>wand</a></div></div>`;
        await loadModule();

        document.querySelector('.t3js-aqg-media-wand').click();
        expect(document.querySelector('.aqg-media-popover').textContent).toContain('File default · all uses');
        expect(document.querySelector('.aqg-media-popover').textContent).toContain('Used in 2 places');
        document.querySelector('[data-aqg-pop="suggest"]').click();
        await settle();
        document.querySelector('[data-aqg-pop="apply"]').click();

        expect(document.querySelector(`[data-formengine-input-name="${name}"]`).value).toBe('Bar chart of water prices');
        expect(document.querySelector('.aqg-media-popover').textContent).toContain('not saved yet');
        expect(calls.map((call) => call.url)).toEqual(['/suggest-meta']);
        expect(calls[0].body).toMatchObject({fileUid: 5, languageUid: 0, fields: ['alternative'], site: 'main', mode: 'image', context: 'single'});
    });

    // Release QA 2026-11-12 (TYPO3 13.4, two AI sites): the site chosen without a click on the select (keyboard) was
    // shown, but the request used the first site's provider, privacy and budget.
    it('sends the site chosen in the popover however the select was changed, and follows its image privacy', async () => {
        const calls = [];
        globalThis.fetch = vi.fn(async (url, init) => {
            calls.push({url, body: JSON.parse(init.body)});
            return json(200, {success: true, status: 'suggestion', mode: 'image', proposals: {alternative: {value: 'Logo', rejection: ''}}, provider: 'openai', model: 'gpt', usage: {}});
        });
        const cfg = {mode: 'metadata', metadataUid: 50, fileUid: 5, languageUid: 0, translation: false,
            uses: {total: 2, inheriting: 1, overriding: 1, decorative: 0, hidden: 0},
            sites: [
                {identifier: 'typo3', label: 'typo3', site: 'typo3', providerLabel: 'Anthropic Claude', modelLabel: 'Claude', allowImages: true},
                {identifier: 'global2', label: 'global2', site: 'global2', providerLabel: 'OpenAI', modelLabel: 'GPT', allowImages: true},
                {identifier: 'noimages', label: 'noimages', site: 'noimages', providerLabel: 'OpenAI', modelLabel: 'GPT', allowImages: false},
            ]};
        const name = 'data[sys_file_metadata][50][alternative]';
        document.body.innerHTML = `<div class="formengine-field-item t3js-formengine-field-item">
            <div class="form-wizards-item-element"><input type="text" data-formengine-input-name="${name}" value=""><input type="hidden" name="${name}" value=""></div>
            <div class="form-wizards-item-aside"><a class="aqg-media-wand t3js-aqg-media-wand btn btn-default" data-aqg-media-field="alternative" data-aqg-media-table="sys_file_metadata" data-aqg-media-input="${name}"
                data-aqg-media='${JSON.stringify(cfg)}' data-aqg-media-labels='{}'>wand</a></div></div>`;
        await loadModule();
        document.querySelector('.t3js-aqg-media-wand').click();

        const choose = (site) => {
            const select = document.querySelector('[data-aqg-pop-site]');
            select.value = site;
            // Keyboard / assistive technology: a change without any click on the select.
            select.dispatchEvent(new Event('change', {bubbles: true}));
        };

        choose('noimages');
        expect(document.querySelector('[data-aqg-pop="suggest"]')).toBeNull();
        expect(document.querySelector('.aqg-media-popover').textContent).toContain('does not allow sending images');
        expect(document.activeElement).toBe(document.querySelector('[data-aqg-pop-site]'));

        choose('global2');
        expect(document.querySelector('[data-aqg-pop-site]').value).toBe('global2');
        document.querySelector('[data-aqg-pop="suggest"]').click();
        await settle();

        expect(calls).toHaveLength(1);
        expect(calls[0].body).toMatchObject({fileUid: 5, fields: ['alternative'], site: 'global2'});
    });

    it('says "Used in 1 place" for a single use (no "1 places")', async () => {
        const cfg = {mode: 'metadata', metadataUid: 50, fileUid: 5, languageUid: 0, translation: false,
            uses: {total: 1, inheriting: 1, overriding: 0, decorative: 0, hidden: 0},
            sites: [{identifier: 'main', label: 'Main', site: 'main', providerLabel: 'OpenAI', modelLabel: 'GPT', allowImages: true}]};
        const name = 'data[sys_file_metadata][50][alternative]';
        document.body.innerHTML = `<div class="form-group"><label class="form-label">Alternative text</label><div class="formengine-field-item t3js-formengine-field-item">
            <div class="form-wizards-wrap"><div class="form-wizards-item-element"><input type="text" data-formengine-input-name="${name}" value=""></div>
            <div class="form-wizards-item-aside"><a class="aqg-media-wand t3js-aqg-media-wand btn btn-default" data-aqg-media-field="alternative" data-aqg-media-table="sys_file_metadata" data-aqg-media-input="${name}"
                data-aqg-media='${JSON.stringify(cfg)}' data-aqg-media-labels='{}'>wand</a></div></div></div></div>`;
        await loadModule();
        document.querySelector('.t3js-aqg-media-wand').click();
        const text = document.querySelector('.aqg-media-popover').textContent;
        expect(text).toContain('Used in 1 place · it inherits this default');
        expect(text).not.toContain('1 places');
    });

    it('uses singular and plural per count in the uses summary ("1 inherits", "2 set")', async () => {
        const cfg = {mode: 'metadata', metadataUid: 51, fileUid: 6, languageUid: 0, translation: false,
            uses: {total: 3, inheriting: 1, overriding: 2, decorative: 0, hidden: 0},
            sites: [{identifier: 'main', label: 'Main', site: 'main', providerLabel: 'OpenAI', modelLabel: 'GPT', allowImages: true}]};
        const name = 'data[sys_file_metadata][51][alternative]';
        document.body.innerHTML = `<div class="form-group"><label class="form-label">Alternative text</label><div class="formengine-field-item t3js-formengine-field-item">
            <div class="form-wizards-wrap"><div class="form-wizards-item-element"><input type="text" data-formengine-input-name="${name}" value=""></div>
            <div class="form-wizards-item-aside"><a class="aqg-media-wand t3js-aqg-media-wand btn btn-default" data-aqg-media-field="alternative" data-aqg-media-table="sys_file_metadata" data-aqg-media-input="${name}"
                data-aqg-media='${JSON.stringify(cfg)}' data-aqg-media-labels='{}'>wand</a></div></div></div></div>`;
        await loadModule();
        document.querySelector('.t3js-aqg-media-wand').click();
        expect(document.querySelector('.aqg-media-popover').textContent).toContain('Used in 3 places · 1 inherits this default · 2 set their own text');
    });

    it('does not render a wand without a configuration it can trust', async () => {
        document.body.innerHTML = '<a class="t3js-aqg-media-wand" data-aqg-media="{broken" data-aqg-media-labels="{}">wand</a>';
        await loadModule();
        expect(document.querySelector('.t3js-aqg-media-wand')).toBeNull();
    });
});
