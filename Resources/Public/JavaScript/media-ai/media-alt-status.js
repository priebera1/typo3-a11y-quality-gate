import {MediaAiLabels} from '@priebera/a11y-quality-gate/media-ai/media-ai-common.js';

/**
 * The ALT status chip on the header of an image in a content element (UX Atlas 05: "ALT missing / ALT own / ALT
 * inherited / Decorative"), kept current while the form is edited. MediaAltStatus renders it for the stored record,
 * with the input name of the use's alternative text and whether the file has a default text; this module follows the
 * form: that text, its "Set element-specific value" switch and "Decorative image". It does not depend on AI:
 * MediaAltStatusWizard loads it on every image use, and the AI wand (media-ai-field.js) renders the same chip.
 * While a use has no alternative text, one line of advice under the field says what to do (also beside the wand).
 * Nothing is requested or written.
 */

/** Same classes as MediaAltStatus::chipHtml(): TYPO3's badge as the baseline, AQG's chip rules on top. */
const BADGES = {missing: 'badge-danger', own: 'badge-success', inherited: 'badge-info'};
const NAMES = {
    missing: ['altMissing', 'ALT missing'],
    own: ['altOwn', 'ALT own'],
    inherited: ['altInherited', 'ALT inherited'],
    decorative: ['altDecorative', 'Decorative'],
    unsaved: ['stepFilled', 'Filled in, unsaved'],
};

/**
 * Decided like FileReferenceAltRule: "Decorative image" needs no text, a use that inherits shows the file default,
 * and a value of its own (also an empty one) overrides the file default.
 */
export function altStatus({decorative = false, inherits = false, value = '', hasFileDefault = false} = {}) {
    if (decorative) {
        return 'decorative';
    }
    if (inherits) {
        return hasFileDefault ? 'inherited' : 'missing';
    }
    return String(value).trim() !== '' ? 'own' : 'missing';
}

/** @param {(key: string, fallback: string) => string} label */
export function renderAltChip(chip, status, label) {
    if (!chip || !NAMES[status]) {
        return;
    }
    // Keep the binding attributes: only the state classes, the state and the text change.
    chip.classList.remove(...[...chip.classList].filter((name) => name.startsWith('aqg-media-altchip--') || name.startsWith('badge-')));
    chip.classList.add('aqg-media-altchip', `aqg-media-altchip--${status}`, 'badge', BADGES[status] ?? 'badge-default');
    chip.setAttribute('data-aqg-alt-chip', status);
    chip.textContent = label(...NAMES[status]);
}

const ADVICE = {
    hidesDefault: ['altAdviceHidesDefault', 'The empty text of this use hides the file default. Untick "Set element specific value" to use the file default, or describe what the image shows.'],
    noDefault: ['altAdviceNoDefault', 'Neither this use nor the file has an alternative text. Tick "Set element specific value" and describe what the image shows.'],
    describe: ['altAdviceDescribe', 'Describe what the image shows, as you would to someone who cannot see it.'],
    decorative: ['altAdviceDecorative', 'If the image adds no information, switch on "Decorative image" instead.'],
};

/**
 * The advice for a use without alternative text, as label keys: what the editor can do with the controls this form
 * gives them. No advice for any other status (the chip confirms it).
 */
export function altAdvice({status, inherits = false, hasFileDefault = false, canOverride = false, canDecorate = false} = {}) {
    if (status !== 'missing') {
        return [];
    }
    let first = 'describe';
    if (canOverride && !inherits && hasFileDefault) {
        first = 'hidesDefault';
    } else if (canOverride && inherits) {
        first = 'noDefault';
    }
    return canDecorate ? [first, 'decorative'] : [first];
}

class AltStatusBinding {
    constructor(chip) {
        this.chip = chip;
        this.inputName = chip.getAttribute('data-aqg-alt-input') || '';
        this.hasFileDefault = chip.getAttribute('data-aqg-alt-file-default') === '1';
        // "Decorative image" is an exclude field: without the switch in the form the stored decision stays.
        this.storedDecorative = chip.getAttribute('data-aqg-alt-decorative') === '1';
        let labels = {};
        try {
            labels = JSON.parse(chip.getAttribute('data-aqg-alt-labels') || '{}');
        } catch {
            // English fallbacks.
        }
        this.labels = new MediaAiLabels(labels);
        this.decorativeName = this.inputName.replace(/\[alternative\]$/, '[tx_a11y_is_decorative]');
        chip.setAttribute('data-aqg-alt-bound', '1');
    }

    input() {
        return document.querySelector(`[data-formengine-input-name="${CSS.escape(this.inputName)}"]`);
    }

    fieldItem() {
        return this.input()?.closest('.t3js-formengine-field-item') ?? null;
    }

    override() {
        return this.fieldItem()?.querySelector('.t3js-form-field-eval-null-placeholder-checkbox input[type="checkbox"]') ?? null;
    }

    decorative() {
        return this.decorativeName !== this.inputName
            ? document.querySelector(`input[type="checkbox"][data-formengine-input-name="${CSS.escape(this.decorativeName)}"]`)
            : null;
    }

    /** The value TYPO3 will save: TYPO3 13 fills the visible input from its hidden field only after initialising it. */
    value() {
        const input = this.input();
        if (input && input.hasAttribute('data-formengine-input-params') && input.getAttribute('data-formengine-input-initialized') !== 'true') {
            const hidden = document.querySelector(`input[type="hidden"][name="${CSS.escape(this.inputName)}"]`);
            if (hidden) {
                return String(hidden.value);
            }
        }
        return String(input?.value ?? '');
    }

    /** Whether an event target is one of this use's controls. */
    concerns(target) {
        return target === this.input() || target === this.override() || target === this.decorative();
    }

    sync() {
        const input = this.input();
        // Not rendered (collapsed image), or a read-only or excluded field (TYPO3 renders it without the input name).
        if (!this.chip.isConnected || !input) {
            return;
        }
        const override = this.override();
        const decorative = this.decorative();
        const inherits = override !== null && !override.checked;
        const status = altStatus({
            decorative: decorative ? decorative.checked === true : this.storedDecorative,
            inherits,
            value: this.value(),
            hasFileDefault: this.hasFileDefault,
        });
        // The AI wand on the field keeps the chip itself ("Filled in, unsaved"); the advice stays with this module.
        if (!this.fieldItem()?.querySelector('.t3js-aqg-media-wand')) {
            renderAltChip(this.chip, status, (key, fallback) => this.labels.l(key, fallback));
        }
        this.renderAdvice(altAdvice({
            status,
            inherits,
            hasFileDefault: this.hasFileDefault,
            canOverride: override !== null && !override.disabled,
            canDecorate: decorative !== null && !decorative.disabled,
        }));
    }

    /** One line under the field, inside TYPO3's field item (no row of its own); input and switch refer to it. */
    renderAdvice(keys) {
        const item = this.fieldItem();
        let advice = item?.querySelector(':scope > [data-aqg-alt-advice]') ?? null;
        const text = keys.map((key) => this.labels.l(...ADVICE[key])).join(' ');
        if (text === '') {
            advice?.remove();
            this.describe(advice?.id ?? '', false);
            return;
        }
        if (!advice) {
            advice = document.createElement('p');
            advice.className = 'aqg-media-altadvice form-text';
            advice.id = `aqg-alt-advice-${++adviceCount}`;
            advice.setAttribute('data-aqg-alt-advice', '');
            item.appendChild(advice);
        }
        if (advice.textContent !== text) {
            advice.textContent = text;
        }
        this.describe(advice.id, true);
    }

    describe(id, on) {
        if (id === '') {
            return;
        }
        for (const control of [this.input(), this.override()]) {
            const ids = (control?.getAttribute('aria-describedby') ?? '').split(/\s+/).filter((token) => token !== '' && token !== id);
            if (on) {
                ids.push(id);
            }
            if (!control) {
                continue;
            }
            if (ids.length > 0) {
                control.setAttribute('aria-describedby', ids.join(' '));
            } else {
                control.removeAttribute('aria-describedby');
            }
        }
    }
}

let adviceCount = 0;

const bindings = [];

function scan() {
    for (let index = bindings.length - 1; index >= 0; index--) {
        if (!bindings[index].chip.isConnected) {
            bindings.splice(index, 1);
        } else {
            // An image expanded later (IRRE): its chip was bound while collapsed, its field only rendered now.
            bindings[index].sync();
        }
    }
    for (const chip of document.querySelectorAll('[data-aqg-alt-chip][data-aqg-alt-input]:not([data-aqg-alt-bound])')) {
        const binding = new AltStatusBinding(chip);
        bindings.push(binding);
        binding.sync();
    }
}

function onFieldEvent(event) {
    const target = event.target;
    // Only the image use's own controls matter (alternative text, its override switch, "Decorative image"); every
    // other keystroke in the form returns here.
    if (bindings.length === 0 || !(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement)
        || !`${target.name} ${target.getAttribute('data-formengine-input-name') ?? ''}`.includes('[sys_file_reference]')) {
        return;
    }
    for (const binding of bindings) {
        if (binding.chip.isConnected && binding.concerns(target)) {
            binding.sync();
            // FormEngine swaps the inherited mirror and the own field after the switch's own change handler.
            window.setTimeout(() => binding.sync(), 0);
        }
    }
}

let scanQueued = false;

/** Inline records (IRRE) render later, when added or expanded: one scan per burst of added nodes. */
function queueScan(mutations) {
    if (scanQueued || !mutations.some((mutation) => [...mutation.addedNodes].some((node) => node instanceof Element))) {
        return;
    }
    scanQueued = true;
    window.setTimeout(() => {
        scanQueued = false;
        scan();
    }, 0);
}

scan();
new MutationObserver(queueScan).observe(document.body, {childList: true, subtree: true});
document.addEventListener('input', onFieldEvent, true);
document.addEventListener('change', onFieldEvent, true);
