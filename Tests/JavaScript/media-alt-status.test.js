// @vitest-environment jsdom
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {altAdvice, altStatus} from '../../Resources/Public/JavaScript/media-ai/media-alt-status.js';

// Regression (3.0 editor-first audit): the ALT chip on an image's header followed the form only through the AI wand.
// Without AI (Free, no provider, ai.images = 0) it kept the stored state: an editor typed a text, or switched
// "Decorative image" on, and the header still said "ALT missing" until Save and reload.

const settle = async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
    await new Promise((resolve) => setTimeout(resolve, 0));
};

/**
 * One image use as TYPO3 13/14 render it inside a content element: the header chip with its binding
 * (MediaAltStatusChipListener), the "use or override" alternative text and "Decorative image".
 */
function imageUse({uid, chip = 'missing', override = false, value = '', decorative = false, hasFileDefault = false, wand = false, decorativeField = true, labels: extraLabels = {}}) {
    const name = `data[sys_file_reference][${uid}][alternative]`;
    const labels = JSON.stringify({altMissing: 'ALT fehlt', altOwn: 'ALT eigen', ...extraLabels});
    return `
        <div class="panel form-irre-object" id="use-${uid}">
            <div class="form-irre-header"><div class="t3js-formengine-file-header-control"><div class="btn-group"><span class="aqg-media-altchip aqg-media-altchip--${chip} badge" data-aqg-alt-chip="${chip}" data-aqg-alt-input="${name}" data-aqg-alt-file-default="${hasFileDefault ? '1' : '0'}" data-aqg-alt-decorative="${chip === 'decorative' ? '1' : '0'}" data-aqg-alt-labels='${labels}'>chip</span></div></div></div>
            <div class="form-group t3js-formengine-palette-field">
                <div class="formengine-field-item t3js-formengine-field-item">
                    <div class="form-check t3js-form-field-eval-null-placeholder-checkbox">
                        <input type="hidden" name="control[active][sys_file_reference][${uid}][alternative]" value="0">
                        <input type="checkbox" class="form-check-input" name="control[active][sys_file_reference][${uid}][alternative]"${override ? ' checked' : ''}>
                    </div>
                    <div class="t3js-formengine-placeholder-placeholder"><input type="text" disabled value="File default"></div>
                    <div class="t3js-formengine-placeholder-formfield"><div class="form-wizards-wrap">
                        <div class="form-wizards-item-element"><input type="text" data-formengine-input-name="${name}" value="${value}"><input type="hidden" name="${name}" value="${value}"></div>
                        ${wand ? `<div class="form-wizards-item-aside"><a class="t3js-aqg-media-wand" data-aqg-ready="1"></a></div>` : ''}
                    </div></div>
                </div>
            </div>
            ${decorativeField ? `<input type="checkbox" data-formengine-input-name="data[sys_file_reference][${uid}][tx_a11y_is_decorative]"${decorative ? ' checked' : ''}>` : ''}
        </div>`;
}

const chip = (uid) => document.querySelector(`#use-${uid} [data-aqg-alt-chip]`);
const field = (uid) => document.querySelector(`[data-formengine-input-name="data[sys_file_reference][${uid}][alternative]"]`);
const override = (uid) => document.querySelector(`#use-${uid} .t3js-form-field-eval-null-placeholder-checkbox input[type="checkbox"]`);
const decorative = (uid) => document.querySelector(`[data-formengine-input-name="data[sys_file_reference][${uid}][tx_a11y_is_decorative]"]`);

async function load(html) {
    document.body.innerHTML = `<div data-object-group="g">${html}</div>`;
    vi.resetModules();
    await import('@priebera/a11y-quality-gate/media-ai/media-alt-status.js');
    await settle();
}

function type(uid, value) {
    field(uid).value = value;
    field(uid).dispatchEvent(new Event('input', {bubbles: true}));
}

function toggle(element, checked) {
    element.checked = checked;
    element.dispatchEvent(new Event('change', {bubbles: true}));
}

beforeEach(() => {
    globalThis.fetch = vi.fn();
    globalThis.CSS ??= {escape: (value) => String(value).replace(/["\\]/g, '\\$&')};
});

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('ALT status of an image use', () => {
    it('is decided like FileReferenceAltRule', () => {
        expect(altStatus({decorative: true, value: ''})).toBe('decorative');
        expect(altStatus({decorative: true, inherits: true, hasFileDefault: false})).toBe('decorative');
        expect(altStatus({inherits: true, hasFileDefault: true})).toBe('inherited');
        expect(altStatus({inherits: true, hasFileDefault: false})).toBe('missing');
        // An own empty value hides the file default.
        expect(altStatus({inherits: false, value: '  ', hasFileDefault: true})).toBe('missing');
        expect(altStatus({inherits: false, value: 'A red bicycle'})).toBe('own');
    });
});

describe('ALT status chip without AI', () => {
    it('turns from missing to own while a text is typed, and back when it is removed, without any request', async () => {
        await load(imageUse({uid: 1, override: true, value: ''}));
        expect(chip(1).getAttribute('data-aqg-alt-chip')).toBe('missing');
        expect(chip(1).textContent).toBe('ALT fehlt');

        type(1, 'A red bicycle against a wall');
        await settle();
        expect(chip(1).getAttribute('data-aqg-alt-chip')).toBe('own');
        expect(chip(1).textContent).toBe('ALT eigen');
        expect(chip(1).className).toContain('badge-success');
        expect(chip(1).className).not.toContain('badge-danger');
        expect(chip(1).getAttribute('data-aqg-alt-input')).toBe('data[sys_file_reference][1][alternative]');

        type(1, '   ');
        await settle();
        expect(chip(1).getAttribute('data-aqg-alt-chip')).toBe('missing');
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('treats an intentionally decorative image as no failure, and goes back when the switch is off', async () => {
        await load(imageUse({uid: 2, override: true, value: ''}));

        toggle(decorative(2), true);
        await settle();
        expect(chip(2).getAttribute('data-aqg-alt-chip')).toBe('decorative');
        expect(chip(2).className).not.toContain('badge-danger');

        toggle(decorative(2), false);
        await settle();
        expect(chip(2).getAttribute('data-aqg-alt-chip')).toBe('missing');
    });

    it('follows "Set element-specific value": inherit a file default, or override it with an own (empty) value', async () => {
        await load(imageUse({uid: 3, chip: 'inherited', override: false, hasFileDefault: true}));
        expect(chip(3).getAttribute('data-aqg-alt-chip')).toBe('inherited');

        toggle(override(3), true);
        await settle();
        expect(chip(3).getAttribute('data-aqg-alt-chip')).toBe('missing');

        type(3, 'Only here');
        await settle();
        expect(chip(3).getAttribute('data-aqg-alt-chip')).toBe('own');

        toggle(override(3), false);
        await settle();
        expect(chip(3).getAttribute('data-aqg-alt-chip')).toBe('inherited');
    });

    it('reports an inheriting use as missing when the file has no default', async () => {
        await load(imageUse({uid: 4, chip: 'inherited', override: false, hasFileDefault: false}));
        expect(chip(4).getAttribute('data-aqg-alt-chip')).toBe('missing');
    });

    it('keeps every image of an element on its own state', async () => {
        await load(imageUse({uid: 5, override: true}) + imageUse({uid: 6, override: true}) + imageUse({uid: 7, chip: 'inherited', hasFileDefault: true}));

        type(5, 'First image');
        toggle(decorative(6), true);
        await settle();

        expect([5, 6, 7].map((uid) => chip(uid).getAttribute('data-aqg-alt-chip'))).toEqual(['own', 'decorative', 'inherited']);
    });

    it('leaves a collapsed image (no form fields rendered) on its stored state', async () => {
        await load(imageUse({uid: 11, chip: 'own'}).replace(/<div class="form-group[\s\S]*<\/div>\s*<input type="checkbox"[^>]*>/, ''));
        expect(chip(11).getAttribute('data-aqg-alt-chip')).toBe('own');
    });

    // Review finding: "Decorative image" is an exclude field. Without it in the form, the stored decision stays.
    it('keeps a stored decorative decision when the editor may not see the "Decorative image" switch', async () => {
        await load(imageUse({uid: 12, chip: 'decorative', override: true, value: '', decorativeField: false}));
        expect(chip(12).getAttribute('data-aqg-alt-chip')).toBe('decorative');

        type(12, 'A text after all');
        await settle();
        expect(chip(12).getAttribute('data-aqg-alt-chip')).toBe('decorative');
    });

    it('binds image uses added later (IRRE)', async () => {
        await load(imageUse({uid: 8, override: true}));
        document.querySelector('[data-object-group]').insertAdjacentHTML('beforeend', imageUse({uid: 9, override: true}));
        await settle();

        type(9, 'Added later');
        await settle();
        expect(chip(9).getAttribute('data-aqg-alt-chip')).toBe('own');
        expect(chip(8).getAttribute('data-aqg-alt-chip')).toBe('missing');
    });

    it('leaves the chip to the AI wand when the wand is on the field', async () => {
        await load(imageUse({uid: 10, override: true, wand: true}));

        type(10, 'Typed while AI is available');
        await settle();
        // The wand (not loaded here) owns the chip; this module does not touch it.
        expect(chip(10).getAttribute('data-aqg-alt-chip')).toBe('missing');
    });
});

// P1 (3.0 inline editor advice): without AI the editor saw only "ALT missing" on the header and nothing that said what
// to do. One line under the field now says it, follows the same live status and is gone as soon as the status is fine.
describe('ALT advice without AI', () => {
    const advice = (uid) => document.querySelector(`#use-${uid} [data-aqg-alt-advice]`);
    const DESCRIBE = 'Describe what the image shows, as you would to someone who cannot see it.';
    const DECORATIVE = 'If the image adds no information, switch on "Decorative image" instead.';

    it('picks the advice the form allows', () => {
        expect(altAdvice({status: 'own'})).toEqual([]);
        expect(altAdvice({status: 'decorative'})).toEqual([]);
        expect(altAdvice({status: 'inherited', canOverride: true, canDecorate: true})).toEqual([]);
        expect(altAdvice({status: 'missing', canOverride: true, canDecorate: true})).toEqual(['describe', 'decorative']);
        expect(altAdvice({status: 'missing', hasFileDefault: true, canOverride: true})).toEqual(['hidesDefault']);
        expect(altAdvice({status: 'missing', inherits: true, canOverride: true, canDecorate: true})).toEqual(['noDefault', 'decorative']);
        // Without the switch in the form, nothing tells the editor to use it.
        expect(altAdvice({status: 'missing', inherits: true, hasFileDefault: false, canOverride: false})).toEqual(['describe']);
    });

    it('explains a missing text under the field, and is gone once a text is typed, without any request', async () => {
        await load(imageUse({uid: 21, override: true, value: ''}));
        expect(advice(21).textContent).toBe(`${DESCRIBE} ${DECORATIVE}`);
        // Inside TYPO3's field item, not a row of its own.
        expect(advice(21).parentElement.classList.contains('t3js-formengine-field-item')).toBe(true);
        expect(field(21).getAttribute('aria-describedby')).toBe(advice(21).id);

        type(21, 'A red bicycle against a wall');
        await settle();
        expect(advice(21)).toBeNull();
        expect(field(21).hasAttribute('aria-describedby')).toBe(false);

        type(21, '');
        await settle();
        expect(advice(21).textContent).toBe(`${DESCRIBE} ${DECORATIVE}`);
        expect(document.querySelectorAll('#use-21 [data-aqg-alt-advice]')).toHaveLength(1);
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('disappears for a decorative image and comes back when the switch is off', async () => {
        await load(imageUse({uid: 22, override: true}));
        toggle(decorative(22), true);
        await settle();
        expect(advice(22)).toBeNull();

        toggle(decorative(22), false);
        await settle();
        expect(advice(22)).not.toBeNull();
    });

    it('says that an empty own value hides the file default, and is gone when the use inherits it again', async () => {
        await load(imageUse({uid: 23, chip: 'inherited', override: false, hasFileDefault: true}));
        expect(advice(23)).toBeNull();

        toggle(override(23), true);
        await settle();
        expect(advice(23).textContent).toContain('hides the file default');
        expect(advice(23).textContent).toContain(DECORATIVE);
        expect(override(23).getAttribute('aria-describedby')).toBe(advice(23).id);

        toggle(override(23), false);
        await settle();
        expect(advice(23)).toBeNull();
        expect(override(23).hasAttribute('aria-describedby')).toBe(false);
    });

    it('tells an inheriting use without a file default to set its own value', async () => {
        await load(imageUse({uid: 24, chip: 'missing', override: false, hasFileDefault: false}));
        expect(advice(24).textContent).toMatch(/^Neither this use nor the file has an alternative text\. Tick "Set element specific value"/);
    });

    // "Decorative image" is an exclude field: never point at a switch the editor does not have.
    it('leaves out "Decorative image" when the editor may not use it, and respects a stored decision', async () => {
        await load(imageUse({uid: 25, override: true, decorativeField: false}) + imageUse({uid: 26, chip: 'decorative', override: true, decorativeField: false}));
        expect(advice(25).textContent).toBe(DESCRIBE);
        expect(advice(26)).toBeNull();
    });

    it('does not point at a switch TYPO3 renders disabled', async () => {
        await load(imageUse({uid: 27, override: true}));
        decorative(27).disabled = true;
        override(27).disabled = true;
        type(27, '');
        await settle();
        expect(advice(27).textContent).toBe(DESCRIBE);
    });

    it('shows no advice for a field TYPO3 renders read-only (no FormEngine input name)', async () => {
        await load(imageUse({uid: 28, override: true}).replace('data-formengine-input-name="data[sys_file_reference][28][alternative]"', 'disabled'));
        expect(advice(28)).toBeNull();
    });

    it('uses the translated texts of the chip', async () => {
        await load(imageUse({uid: 29, override: true, labels: {altAdviceDescribe: 'Beschreiben Sie das Bild.', altAdviceDecorative: 'Oder dekorativ.'}}));
        expect(advice(29).textContent).toBe('Beschreiben Sie das Bild. Oder dekorativ.');
    });

    it('stays beside the AI wand, which keeps the chip', async () => {
        await load(imageUse({uid: 30, override: true, wand: true}));
        expect(advice(30)).not.toBeNull();
        expect(document.querySelectorAll('[data-aqg-alt-advice]')).toHaveLength(1);

        type(30, 'Applied or typed');
        await settle();
        expect(advice(30)).toBeNull();
        expect(chip(30).getAttribute('data-aqg-alt-chip')).toBe('missing');
    });

    it('appears when a collapsed image is expanded later (IRRE)', async () => {
        const html = imageUse({uid: 31, override: true});
        const form = html.match(/<div class="form-group[\s\S]*<\/div>\s*<input type="checkbox"[^>]*>/)[0];
        await load(html.replace(form, ''));
        expect(advice(31)).toBeNull();

        document.querySelector('#use-31').insertAdjacentHTML('beforeend', form);
        await settle();
        expect(advice(31).textContent).toBe(`${DESCRIBE} ${DECORATIVE}`);
    });
});
