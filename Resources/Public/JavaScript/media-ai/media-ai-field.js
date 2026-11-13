import {postMediaAi, postMediaAiPaced} from '@priebera/a11y-quality-gate/media-ai/media-ai-client.js';
import {
    MediaAiLabels,
    announcer,
    errorMessage,
    escapeHtml,
    fieldLabel,
    isManualOutcome,
    ladderHtml,
    provenanceHtml,
} from '@priebera/a11y-quality-gate/media-ai/media-ai-common.js';
import {renderAltChip} from '@priebera/a11y-quality-gate/media-ai/media-alt-status.js';

const PENDING_PREFIX = 'aqg-media-ai-pending:';
const RECOMMENDED = {alternative: 150, title: 100, description: 1000};

function readPending(key) {
    try {
        const value = JSON.parse(sessionStorage.getItem(PENDING_PREFIX + key) ?? '[]');
        return Array.isArray(value) ? value : [];
    } catch {
        return [];
    }
}

function writePending(key, items) {
    try {
        if (items.length === 0) {
            sessionStorage.removeItem(PENDING_PREFIX + key);
        } else {
            sessionStorage.setItem(PENDING_PREFIX + key, JSON.stringify(items.slice(-20)));
        }
    } catch {
        // Without session storage the outcome after Save is simply not shown.
    }
}

/**
 * One AQG wand beside an image text field in FormEngine (UX Atlas 04 A, 05 A–E). "reference": the alternative text of
 * this image use (sys_file_reference) with the file default shown separately; "metadata": one field of the file
 * default (sys_file_metadata). Apply only fills the form field (Filled in, unsaved); TYPO3 Save stores it, and the
 * next form load verifies the saved record with AQG's structured check. AQG never writes here.
 */
class MediaAiWand {
    constructor(anchor) {
        this.anchor = anchor;
        this.config = JSON.parse(anchor.getAttribute('data-aqg-media') || '{}');
        this.labels = new MediaAiLabels(JSON.parse(anchor.getAttribute('data-aqg-media-labels') || '{}'));
        this.field = anchor.getAttribute('data-aqg-media-field') || 'alternative';
        this.inputName = anchor.getAttribute('data-aqg-media-input') || '';
        this.fieldItem = anchor.closest('.t3js-formengine-field-item') ?? anchor.parentElement;
        this.nativeAside = anchor.parentElement;
        this.popover = null;
        this.filled = false;
        this.abortController = null;
        this.result = null;
        this.undo = null;
        this.site = this.config.mode === 'metadata' ? (this.config.sites?.[0]?.site ?? '') : (this.config.ai?.site ?? '');
        this.announce = announcer();
    }

    l(key, fallback, ...args) {
        return this.labels.l(key, fallback, ...args);
    }

    h(key, fallback, ...args) {
        return this.labels.h(key, fallback, ...args);
    }

    init() {
        this.anchor.setAttribute('data-aqg-ready', '1');
        this.anchor.setAttribute('href', '#');
        this.anchor.setAttribute('role', 'button');
        this.anchor.setAttribute('aria-haspopup', 'dialog');
        this.anchor.setAttribute('aria-expanded', 'false');
        this.placeWand();
        this.anchor.addEventListener('click', (event) => {
            event.preventDefault();
            this.popover ? this.closePopover() : this.openPopover();
        });
        this.anchor.addEventListener('keydown', (event) => {
            if (event.key === ' ') {
                event.preventDefault();
                this.anchor.click();
            }
        });
        const decorative = this.decorativeCheckbox();
        decorative?.addEventListener('change', () => this.syncDecorative());
        this.overrideCheckbox()?.addEventListener('change', () => {
            // FormEngine swaps the inherited mirror and the own field; the wand follows the visible one.
            window.setTimeout(() => {
                this.placeWand();
                this.positionPopover();
                this.syncChips();
            }, 0);
        });
        this.input()?.addEventListener('input', () => this.syncChips());
        this.input()?.addEventListener('change', () => this.syncChips());
        window.addEventListener('resize', () => this.positionPopover());
        this.syncDecorative();
    }

    /**
     * The wand lives in TYPO3's field-control slot to the right of the field (UX Atlas 05 A, like the file metadata
     * form). In "use or override" mode TYPO3 hides the own field with its controls while the use inherits the file
     * default; the wand then moves into the same slot beside the inherited (read-only) value, so it stays reachable
     * in both states and never takes a row of its own.
     */
    placeWand() {
        const placeholder = this.fieldItem?.querySelector('.t3js-formengine-placeholder-placeholder');
        if (!placeholder || !this.nativeAside) {
            return;
        }
        const target = this.inherits() ? this.mirrorAside(placeholder) : this.nativeAside;
        if (target && this.anchor.parentElement !== target) {
            const hadFocus = document.activeElement === this.anchor;
            target.appendChild(this.anchor);
            if (hadFocus) {
                this.anchor.focus();
            }
        }
    }

    /** The field-control slot beside TYPO3's read-only file default, built once with TYPO3's own wizard classes. */
    mirrorAside(placeholder) {
        const existing = placeholder.querySelector('.aqg-media-mirror-aside');
        if (existing) {
            return existing;
        }
        const wrap = placeholder.querySelector('.form-control-wrap') ?? placeholder;
        const wizards = document.createElement('div');
        wizards.className = 'form-wizards-wrap aqg-media-mirror';
        const element = document.createElement('div');
        element.className = 'form-wizards-item-element';
        element.append(...wrap.childNodes);
        const aside = document.createElement('div');
        aside.className = 'form-wizards-item-aside form-wizards-item-aside--field-control';
        const group = document.createElement('div');
        group.className = 'btn-group aqg-media-mirror-aside';
        aside.appendChild(group);
        wizards.append(element, aside);
        wrap.appendChild(wizards);
        return group;
    }

    /** The palette field around the form field: the popover is anchored in it, below the field, never inside it. */
    popoverHost() {
        return this.fieldItem?.closest('.form-group') ?? this.fieldItem?.parentElement ?? document.body;
    }

    /** Anchored below the visible field and aligned with its left edge (Atlas `AQG.anchor`); it never moves the field. */
    positionPopover() {
        if (!this.popover) {
            return;
        }
        const host = this.popover.parentElement;
        const field = this.anchor.closest('.form-wizards-wrap') ?? this.anchor.closest('.form-control-wrap') ?? this.fieldItem;
        if (!host || !field) {
            return;
        }
        const hostBox = host.getBoundingClientRect();
        const fieldBox = field.getBoundingClientRect();
        this.popover.style.top = `${Math.max(0, Math.round(fieldBox.bottom - hostBox.top + 6))}px`;
        this.popover.style.left = `${Math.max(0, Math.round(fieldBox.left - hostBox.left))}px`;
    }

    /** True when the stored rule would report this use: no text of its own and no file default to inherit. */
    altMissing() {
        if (this.config.mode !== 'reference' || this.decorativeCheckbox()?.checked === true) {
            return false;
        }
        return this.inherits() ? !this.config.hasFileDefault : this.currentValue().trim() === '';
    }

    /** FileReferenceAltRule: Critical without any text, Warning while a title (own or file default) stands in. */
    severity() {
        const titleName = this.inputName.replace(/\[alternative\]$/, '[title]');
        const ownTitle = titleName !== this.inputName
            ? String(document.querySelector(`[data-formengine-input-name="${CSS.escape(titleName)}"]`)?.value ?? '').trim()
            : '';
        return ownTitle !== '' || this.config.hasFileDefaultTitle === true ? 'warning' : 'critical';
    }

    altStatus() {
        if (this.decorativeCheckbox()?.checked === true) {
            return 'decorative';
        }
        if (this.filled) {
            return 'unsaved';
        }
        if (this.altMissing()) {
            return 'missing';
        }
        return this.inherits() ? 'inherited' : 'own';
    }

    /**
     * The scope chip at the field label ("This use only" / "File default · all uses") and the ALT status chip on the
     * image's IRRE header, kept current while the form is edited (the header chip is rendered by the server for the
     * stored record, MediaAltStatusChipListener).
     */
    syncChips() {
        if (this.config.mode !== 'reference') {
            return;
        }
        const label = this.popoverHost()?.querySelector(':scope > .form-label, :scope > label');
        if (label) {
            let chip = label.parentElement.querySelector(':scope > .aqg-media-scopechip');
            if (!chip) {
                chip = document.createElement('span');
                label.insertAdjacentElement('afterend', chip);
            }
            const inherits = this.inherits();
            chip.className = `aqg-media-scopechip aqg-media-chip ${inherits ? 'aqg-media-chip--global' : 'aqg-media-chip--local'}`;
            chip.textContent = inherits ? this.l('scopeFileDefault', 'File default · all uses') : this.l('scopeThisUse', 'This use only');
        }
        renderAltChip(this.anchor.closest('.form-irre-object')?.querySelector('[data-aqg-alt-chip]'), this.altStatus(), (key, fallback) => this.l(key, fallback));
    }

    input() {
        return this.inputName !== '' ? document.querySelector(`[data-formengine-input-name="${CSS.escape(this.inputName)}"]`) : null;
    }

    overrideCheckbox() {
        return this.fieldItem?.querySelector('.t3js-form-field-eval-null-placeholder-checkbox input[type="checkbox"]') ?? null;
    }

    decorativeCheckbox() {
        if (this.config.mode !== 'reference' || !this.config.decorativeField || this.inputName === '') {
            return null;
        }
        const name = this.inputName.replace(/\[alternative\]$/, '[tx_a11y_is_decorative]');
        return document.querySelector(`input[type="checkbox"][data-formengine-input-name="${CSS.escape(name)}"]`);
    }

    /** While "Decorative image" is on, nothing needs text: the wand is hidden. */
    syncDecorative() {
        const decorative = this.decorativeCheckbox()?.checked === true;
        this.anchor.hidden = decorative;
        if (decorative) {
            this.closePopover();
        }
        this.syncChips();
    }

    currentValue() {
        const input = this.input();
        // TYPO3 13 fills the visible input from its hidden field only after FormEngine initialised it.
        if (input && input.hasAttribute('data-formengine-input-params') && input.getAttribute('data-formengine-input-initialized') !== 'true') {
            const hidden = document.querySelector(`input[type="hidden"][name="${CSS.escape(this.inputName)}"]`);
            if (hidden) {
                return String(hidden.value);
            }
        }
        return String(input?.value ?? '');
    }

    inherits() {
        const checkbox = this.overrideCheckbox();
        return checkbox !== null && !checkbox.checked;
    }

    openPopover() {
        // One popover at a time (Atlas closes the open one): they float over the form and would cover each other.
        for (const other of wands) {
            if (other !== this && other.popover) {
                other.closePopover();
            }
        }
        const popover = document.createElement('div');
        popover.className = 'aqg-media-popover';
        popover.setAttribute('role', 'dialog');
        popover.setAttribute('aria-modal', 'false');
        popover.setAttribute('aria-labelledby', `${this.popoverId()}-title`);
        popover.id = this.popoverId();
        popover.addEventListener('click', (event) => this.onClick(event));
        popover.addEventListener('input', (event) => this.onInput(event));
        popover.addEventListener('change', (event) => this.onSiteChange(event));
        popover.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                this.closePopover(true);
            }
        });
        const host = this.popoverHost();
        host.classList.add('aqg-media-pophost');
        host.appendChild(popover);
        this.popover = popover;
        this.anchor.setAttribute('aria-expanded', 'true');
        this.anchor.setAttribute('aria-controls', popover.id);
        this.renderStart();
        this.positionPopover();
        popover.querySelector('h4')?.focus();
    }

    popoverId() {
        return `aqg-media-pop-${this.inputName.replace(/[^a-zA-Z0-9]+/g, '-')}`;
    }

    closePopover(returnFocus = false) {
        this.abortController?.abort();
        this.popover?.remove();
        this.popover = null;
        this.anchor.setAttribute('aria-expanded', 'false');
        if (returnFocus) {
            this.anchor.focus();
        }
    }

    /** What the suggestion applies to, in words; the scope chip itself sits in the popover head. */
    scopeHtml() {
        if (this.config.mode === 'metadata') {
            return `<p class="aqg-media-popover__scope">${this.usesText()}</p>`;
        }
        return `<p class="aqg-media-popover__scope">${this.inherits()
            ? this.h('inheritsHelp', 'This use shows the file default.')
            : this.h('thisUseHelp', 'The suggestion applies to this image in this content element only. The file default and other uses stay unchanged.')}</p>`;
    }

    usesText() {
        const uses = this.config.uses ?? {};
        const total = Number(uses.total ?? 0);
        if (total <= 0) {
            return this.h('usesNone', 'Not used on any page yet');
        }
        if (total === 1) {
            if (Number(uses.inheriting ?? 0) === 1) {
                return this.h('usesOneInheriting', 'Used in 1 place · it inherits this default');
            }
            return Number(uses.overriding ?? 0) === 1
                ? this.h('usesOneOwn', 'Used in 1 place · it sets its own text')
                : this.h('usesOne', 'Used in 1 place');
        }
        // Each count with its own singular/plural ("1 inherits", "2 inherit"): audit F-26.
        const inheriting = Number(uses.inheriting ?? 0);
        const overriding = Number(uses.overriding ?? 0);
        return [
            this.h('usesPlaces', 'Used in %d places', total),
            inheriting === 1 ? this.h('usesInheritOne', '1 inherits this default') : this.h('usesInheritOther', '%d inherit this default', inheriting),
            overriding === 1 ? this.h('usesOwnOne', '1 sets its own text') : this.h('usesOwnOther', '%d set their own text', overriding),
        ].join(' · ');
    }

    /** Popover head (UX Atlas 05 A): severity while the use is reported, then the scope of the change. */
    headChipsHtml() {
        const chips = [];
        if (this.altMissing()) {
            chips.push(this.severity() === 'critical'
                ? `<span class="aqg-media-badge aqg-media-badge--critical">${this.h('severityCritical', 'Critical')}</span>`
                : `<span class="aqg-media-badge aqg-media-badge--warning">${this.h('severityWarning', 'Warning')}</span>`);
        }
        // What a suggestion here changes: the file default in the metadata form, only this use in a content element
        // (the label chip shows what the field shows right now).
        const global = this.config.mode === 'metadata';
        chips.push(`<span class="aqg-media-chip ${global ? 'aqg-media-chip--global' : 'aqg-media-chip--local'}">${global
            ? this.h('scopeFileDefault', 'File default · all uses')
            : this.h('scopeThisUse', 'This use only')}</span>`);
        return chips.join('');
    }

    titleText() {
        return this.config.mode === 'metadata'
            ? this.l(`suggestField_${this.field}`, 'Suggest %s', fieldLabel(this.labels, this.field))
            : this.l('referenceTitle', 'Alternative text for this use');
    }

    renderStart() {
        const ai = this.config.mode === 'metadata' ? (this.config.sites ?? []).find((site) => site.site === this.site) : this.config.ai;
        const allowImages = ai?.allowImages === true;
        const emptyOverride = this.config.mode === 'reference' && !this.inherits() && this.currentValue().trim() === '';
        const options = [];
        if (emptyOverride && this.config.hasFileDefault) {
            options.push(`<button type="button" class="btn btn-default btn-sm" data-aqg-pop="use-default">${this.h('useDefault', 'Use file default')} <span class="aqg-media-chip">${this.h('noAi', 'No AI')}</span></button>`);
        }
        if (allowImages) {
            options.push(`<button type="button" class="btn btn-primary btn-sm" data-aqg-pop="suggest"><span class="aqg-media-wand-icon" aria-hidden="true"></span>${this.config.mode === 'metadata' ? this.h('suggestShort', 'Suggest') : this.h('suggestForUse', 'Suggest for this use')}</button>`);
        }
        if (this.config.translation) {
            options.push(`<button type="button" class="btn btn-default btn-sm" data-aqg-pop="translate">${this.h('translateFromDefault', 'Translate from the default language')}</button>`);
        }
        const siteSelect = this.config.mode === 'metadata' && (this.config.sites ?? []).length > 1
            ? `<label class="aqg-media-popover__site">${this.h('site', 'AI settings of site')} <select class="form-select form-select-sm" data-aqg-pop-site>${this.config.sites.map((site) => `<option value="${escapeHtml(site.site)}"${site.site === this.site ? ' selected' : ''}>${escapeHtml(site.label)}</option>`).join('')}</select></label>`
            : '';
        this.renderPopover(`
            ${this.scopeHtml()}
            ${siteSelect}
            ${allowImages ? '' : `<div class="aqg-media-notice aqg-media-notice--warning"><p>${this.h('errorPrivacyImages', 'This site does not allow sending images to AI providers. Nothing was sent.')} ${this.config.mode === 'reference' ? this.h('privacyStillWorks', '"Use file default" and "Decorative image" still work.') : ''}</p></div>`}
            ${emptyOverride ? `<p class="aqg-media-popover__hint">${this.config.hasFileDefault ? this.h('emptyOverrideHides', 'The empty element-specific value hides the file default.') : this.h('noFileDefault', 'This file has no default alternative text.')}</p>` : ''}
            ${allowImages ? `<p class="aqg-media-popover__hint">${this.config.mode === 'metadata' ? this.h('metadataSends', 'Sends the image (scaled down), file name and folder name. The suggestion only fills this field.') : this.h('referenceSends', 'Uses the image, this element\'s header and first paragraph. The file default stays unchanged.')}</p>` : ''}
            <div class="aqg-media-popover__actions">${options.join('') || `<span>${this.h('nothingAvailable', 'No AI action is available here.')}</span>`}</div>`);
    }

    renderPopover(body) {
        if (!this.popover) {
            return;
        }
        // A re-render removes the button that was just used; focus stays in the popover instead of falling to the page.
        const keepFocus = this.popover.contains(document.activeElement) || document.activeElement === document.body;
        this.popover.innerHTML = `<div class="aqg-media-popover__head">${this.headChipsHtml()}
                <button type="button" class="btn btn-default btn-sm aqg-media-popover__close" data-aqg-pop="close" aria-label="${this.h('close', 'Close')}"><span class="aqg-media-close-icon" aria-hidden="true"></span></button>
            </div>
            <h4 class="aqg-media-popover__title" id="${this.popoverId()}-title" tabindex="-1">${escapeHtml(this.titleText())}</h4>
            <div class="aqg-media-popover__body">${body}</div>`;
        this.positionPopover();
        if (keepFocus) {
            this.popover.querySelector('h4')?.focus();
        }
    }

    async suggest(mode = 'suggest') {
        this.abortController?.abort();
        this.abortController = new AbortController();
        const signal = this.abortController.signal;
        // The value the suggestion is for: anything typed while it is prepared makes it stale.
        const fieldValueAtRequest = this.currentValue();
        this.renderPopover(`${this.scopeHtml()}<p class="aqg-media-status" role="status"><span class="aqg-media-spinner" aria-hidden="true"></span>${this.h('preparing', 'Preparing a suggestion…')}</p>
            <div class="aqg-media-popover__actions"><button type="button" class="btn btn-default btn-sm" data-aqg-pop="cancel">${this.h('cancelGeneration', 'Cancel')}</button></div>`);
        this.announce(this.l('preparing', 'Preparing a suggestion…'));
        const response = this.config.mode === 'metadata'
            ? await postMediaAiPaced('suggestMetadata', {
                fileUid: this.config.fileUid,
                languageUid: this.config.languageUid,
                fields: [this.field],
                site: this.site,
                mode: mode === 'translate' ? 'translate' : 'image',
                context: 'single',
            }, {signal})
            : await postMediaAiPaced('suggestReference', {
                parentTable: this.config.parentTable,
                parentUid: this.config.parentUid,
                fieldName: this.config.parentField,
                referenceUid: this.config.referenceUid,
                fileUid: this.config.fileUid,
                currentAlt: this.inherits() ? '' : this.currentValue(),
                mode,
            }, {signal});
        if (response.code === 'cancelled') {
            this.renderPopover(`${this.scopeHtml()}<p class="aqg-media-status">${this.h('discardedField', 'Suggestion discarded. The field is unchanged.')}</p>`);
            return;
        }
        this.result = {response, mode, fieldValueAtRequest};
        this.renderResult();
    }

    renderResult() {
        const {response} = this.result;
        if (!response.ok) {
            const manual = isManualOutcome(response.code);
            this.renderPopover(`${this.scopeHtml()}<div class="aqg-media-notice ${manual ? 'aqg-media-notice--review' : 'aqg-media-notice--error'}"><p>${escapeHtml(errorMessage(this.labels, response.code, response.data))}</p></div>
                <div class="aqg-media-popover__actions">${['session_expired', 'privacy_images', 'privacy_images_usage', 'privacy_usage_unknown'].includes(response.code) ? '' : `<button type="button" class="btn btn-default btn-sm" data-aqg-pop="suggest">${this.h('tryAgain', 'Try again')}</button>`}</div>`);
            this.announce(errorMessage(this.labels, response.code, response.data));
            return;
        }
        const data = response.data;
        const value = this.config.mode === 'metadata'
            ? String(data.proposals?.[this.field]?.rejection ? '' : (data.proposals?.[this.field]?.value ?? ''))
            : String(data.value ?? '');
        const status = data.status;
        if (status === 'decorative') {
            this.renderPopover(`${this.scopeHtml()}<div class="aqg-media-notice aqg-media-notice--review"><p><strong>${this.h('decorativeTitle', 'Probably decorative')}</strong> ${escapeHtml(data.reason ?? '')}</p>
                ${this.config.mode === 'reference'
                    ? `<p>${this.h('decorativeChoice', 'Informative: shows content, data, people or text visitors need. Decorative: styling only, nothing is lost without it.')}</p>`
                    : `<p>${this.h('decorativeHelp', 'File metadata cannot be decorative: the same file can be decorative in one place and informative in another. Decide per use with the "Decorative image" switch in the content element, and leave the alternative text empty here.')}</p>`}</div>
                <div class="aqg-media-popover__actions">
                    ${this.config.mode === 'reference' && this.decorativeCheckbox() ? `<button type="button" class="btn btn-default btn-sm" data-aqg-pop="mark-decorative">${this.h('markDecorative', 'Mark as decorative')}</button>` : ''}
                    <button type="button" class="btn btn-default btn-sm" data-aqg-pop="write-own">${this.h('writeOwn', 'Write alternative text instead')}</button>
                </div>
                <p class="aqg-media-popover__hint">${this.h('decorativeNever', 'AQG never switches "Decorative image" on by itself. Saving happens with TYPO3 Save.')}</p>
                ${provenanceHtml(this.labels, data)}`);
            this.announce(this.l('decorativeTitle', 'Probably decorative'));
            return;
        }
        if (status === 'default_fits') {
            this.renderPopover(`${this.scopeHtml()}<div class="aqg-media-notice"><p><strong>${this.h('defaultFits', 'The file default fits here.')}</strong> ${escapeHtml(data.reason ?? '')}</p>
                <p>“${escapeHtml(data.defaultAlt ?? '')}”</p></div>
                <div class="aqg-media-popover__actions">
                    <button type="button" class="btn btn-primary btn-sm" data-aqg-pop="${this.inherits() ? 'close' : 'use-default'}">${this.h('keepDefault', 'Keep file default')}</button>
                    <button type="button" class="btn btn-default btn-sm" data-aqg-pop="write-own">${this.h('writeOwnAnyway', 'Write own text anyway')}</button>
                </div>${provenanceHtml(this.labels, data)}`);
            this.announce(this.l('defaultFits', 'The file default fits here.'));
            return;
        }
        if (status !== 'suggestion' || value === '') {
            const manual = status === 'needs_review';
            this.renderPopover(`${this.scopeHtml()}<div class="aqg-media-notice aqg-media-notice--review"><p><strong>${manual ? this.h('manualTitle', 'No suggestion. Needs manual review.') : this.h('errorInvalidResponse', 'The AI answer could not be used safely. Nothing was changed.')}</strong></p>
                ${data.reason ? `<p>${escapeHtml(data.reason)}</p>` : ''}</div>
                <div class="aqg-media-popover__actions"><button type="button" class="btn btn-default btn-sm" data-aqg-pop="write-own">${this.h('writeYourself', 'Write it myself')}</button></div>
                ${provenanceHtml(this.labels, data)}`);
            this.announce(this.l('manualTitle', 'No suggestion. Needs manual review.'));
            return;
        }
        this.result.value = value;
        const multiline = this.field === 'description';
        const textareaId = `${this.popoverId()}-value`;
        const modelLabel = data.modelLabel || (this.config.mode === 'metadata' ? (this.config.sites ?? []).find((site) => site.site === this.site)?.modelLabel : this.config.ai?.modelLabel) || '';
        this.renderPopover(`${this.scopeHtml()}
            ${data.mode === 'translate' ? `<p class="aqg-media-popover__hint">${this.h('translatedFrom', 'Translation of the default language:')} “${escapeHtml(typeof data.source === 'string' ? data.source : (data.source?.[this.field] ?? ''))}”</p>` : ''}
            <div class="aqg-media-ai">
                <div class="aqg-media-ai__head"><span class="aqg-media-wand-icon" aria-hidden="true"></span><strong>${this.h('suggestionHead', 'Suggestion')}</strong>${modelLabel !== '' ? `<span class="aqg-media-ai__model">${escapeHtml(modelLabel)}</span>` : ''}</div>
                <div class="aqg-media-ai__body">
                    <label class="aqg-media-field__label" for="${textareaId}">${this.config.mode === 'metadata' ? escapeHtml(fieldLabel(this.labels, this.field)) : this.h('suggestedAlt', 'Alternative text (editable)')}</label>
                    <textarea id="${textareaId}" class="form-control" rows="${multiline ? 4 : 2}" data-aqg-pop-value>${escapeHtml(value)}</textarea>
                    <span class="aqg-media-field__count">${escapeHtml(this.countText(value))}</span>
                    ${data.reason ? `<p class="aqg-media-item__why"><strong>${this.h('why', 'Why:')}</strong> ${escapeHtml(data.reason)}</p>` : ''}
                    ${data.complex ? `<div class="aqg-media-notice aqg-media-notice--review"><p><strong>${this.h('chipComplex', 'Manual review: check figures and labels')}</strong> ${escapeHtml(data.uncertain ?? '')}</p></div>` : ''}
                </div>
                <div class="aqg-media-ai__foot aqg-media-popover__actions">
                    <button type="button" class="btn btn-primary btn-sm" data-aqg-pop="apply">${this.h('applyToField', 'Apply to field')}</button>
                    <button type="button" class="btn btn-default btn-sm" data-aqg-pop="discard">${this.h('discard', 'Discard')}</button>
                    <button type="button" class="btn btn-default btn-sm aqg-media-ai__end" data-aqg-pop="regenerate">${this.h('regenerate', 'Regenerate')}</button>
                </div>
            </div>
            ${provenanceHtml(this.labels, data)}`);
        this.announce(this.l('suggestionReady', 'Suggestion ready. Review and edit it before applying.'));
        this.popover?.querySelector('textarea')?.focus();
    }

    countText(value) {
        const recommended = RECOMMENDED[this.field] ?? 150;
        return this.l('charCount', '%d / %d characters recommended', [...String(value)].length, recommended);
    }

    onInput(event) {
        if (event.target instanceof HTMLTextAreaElement && event.target.hasAttribute('data-aqg-pop-value')) {
            this.result.value = event.target.value;
            const counter = this.popover?.querySelector('.aqg-media-field__count');
            if (counter) {
                counter.textContent = this.countText(event.target.value);
            }
        }
    }

    /**
     * The site whose AI settings (provider, model, privacy, budget) the request uses. Read on every change, however the
     * select changed: release QA 2026-11-12 found the value was only taken after a click on the select, so a keyboard
     * choice was shown but the request went to the first site. The start view depends on the site (image privacy), so
     * it is rendered again with focus back on the select.
     */
    onSiteChange(event) {
        const siteSelect = event.target instanceof HTMLSelectElement && event.target.matches('[data-aqg-pop-site]') ? event.target : null;
        if (!siteSelect || !(this.config.sites ?? []).some((site) => site.site === siteSelect.value)) {
            return;
        }
        this.site = siteSelect.value;
        this.renderStart();
        this.popover?.querySelector('[data-aqg-pop-site]')?.focus();
    }

    onClick(event) {
        const button = event.target instanceof Element ? event.target.closest('[data-aqg-pop]') : null;
        if (!button) {
            return;
        }
        switch (button.getAttribute('data-aqg-pop')) {
            case 'close':
                this.closePopover(true);
                break;
            case 'cancel':
                this.abortController?.abort();
                break;
            case 'suggest':
            case 'regenerate':
                this.suggest(this.result?.mode === 'translate' && button.getAttribute('data-aqg-pop') === 'regenerate' ? 'translate' : 'suggest');
                break;
            case 'translate':
                this.suggest('translate');
                break;
            case 'apply':
                this.apply();
                break;
            case 'discard':
                this.result = null;
                this.renderPopover(`${this.scopeHtml()}<p class="aqg-media-status" role="status">${this.h('discardedField', 'Suggestion discarded. The field is unchanged.')}</p>`);
                this.announce(this.l('discardedField', 'Suggestion discarded. The field is unchanged.'));
                break;
            case 'use-default':
                this.useDefault();
                break;
            case 'mark-decorative':
                this.markDecorative();
                break;
            case 'write-own':
                this.writeOwn();
                break;
            case 'undo':
                this.revert();
                break;
            default:
                break;
        }
    }

    /** Ticks "Set element-specific value" the way a person would, so FormEngine updates its own state. */
    setOverride(active) {
        const checkbox = this.overrideCheckbox();
        if (checkbox && checkbox.checked !== active) {
            checkbox.click();
        }
    }

    setValue(value) {
        const input = this.input();
        if (!input) {
            return false;
        }
        input.value = value;
        input.dispatchEvent(new Event('input', {bubbles: true}));
        input.dispatchEvent(new Event('change', {bubbles: true}));
        return true;
    }

    remember() {
        this.undo = {override: this.overrideCheckbox()?.checked ?? null, value: this.currentValue()};
    }

    apply() {
        const value = String(this.result?.value ?? '').trim();
        if (value === '') {
            return;
        }
        if (this.currentValue() !== this.result.fieldValueAtRequest) {
            // The field changed while the suggestion was prepared: never overwrite what the person typed meanwhile.
            this.renderPopover(`${this.scopeHtml()}<div class="aqg-media-notice aqg-media-notice--warning"><p>${this.h('staleField', 'The field changed after this suggestion was made. Nothing was applied.')}</p></div>
                <div class="aqg-media-popover__actions"><button type="button" class="btn btn-default btn-sm" data-aqg-pop="regenerate">${this.h('regenerate', 'Regenerate')}</button></div>`);
            return;
        }
        this.remember();
        this.setOverride(true);
        if (!this.setValue(value)) {
            return;
        }
        this.recordPending();
        this.renderApplied(this.config.mode === 'metadata'
            ? this.l('appliedMetadata', 'Filled into the field — not saved yet. Use TYPO3 Save to keep it. It becomes the file default for every use without its own text.')
            : this.l('appliedReference', 'Applied to the field — not saved yet. Applies to this image in this content element only. Use TYPO3 Save to keep it.'));
    }

    useDefault() {
        this.remember();
        this.setOverride(false);
        this.renderApplied(this.l('defaultRestored', 'Uses the file default again — not saved yet. "Set element-specific value" is switched off. Use TYPO3 Save to keep it.'));
    }

    markDecorative() {
        const checkbox = this.decorativeCheckbox();
        if (checkbox && !checkbox.checked) {
            checkbox.click();
            // Verified after TYPO3 Save like an applied text: the structured check of the saved use decides.
            this.recordPending();
        }
        this.announce(this.l('markedDecorative', 'Marked as decorative — not saved yet. TYPO3 renders an empty alternative text after Save.'));
        this.anchor.focus();
    }

    writeOwn() {
        this.setOverride(true);
        this.closePopover();
        this.input()?.focus();
    }

    revert() {
        if (!this.undo) {
            return;
        }
        if (this.undo.override !== null) {
            this.setOverride(this.undo.override);
        }
        this.setValue(this.undo.value);
        this.undo = null;
        this.filled = false;
        this.anchor.classList.remove('aqg-media-wand--filled');
        this.dropPending();
        this.syncChips();
        this.renderPopover(`${this.scopeHtml()}<p class="aqg-media-status" role="status">${this.h('undone', 'The previous value is back. Nothing changed.')}</p>`);
        this.announce(this.l('undone', 'The previous value is back. Nothing changed.'));
    }

    renderApplied(message) {
        this.renderPopover(`${ladderHtml(this.labels, 'filled')}
            <p class="aqg-media-status" role="status">${escapeHtml(message)}</p>
            <div class="aqg-media-popover__actions"><button type="button" class="btn btn-default btn-sm" data-aqg-pop="undo">${this.h('undo', 'Undo')}</button>
            <button type="button" class="btn btn-default btn-sm" data-aqg-pop="close">${this.h('close', 'Close')}</button></div>`);
        this.announce(message);
        this.anchor.classList.add('aqg-media-wand--filled');
        this.filled = true;
        this.syncChips();
    }

    pendingKey() {
        return this.config.mode === 'metadata'
            ? `sys_file_metadata:${this.config.metadataUid}`
            : `${this.config.parentTable}:${this.config.parentUid}:${this.config.parentField}`;
    }

    recordPending() {
        const items = readPending(this.pendingKey()).filter((item) => item.inputName !== this.inputName);
        items.push({
            inputName: this.inputName,
            referenceUid: this.config.referenceUid ?? 0,
            fileUid: this.config.fileUid,
            field: this.field,
            languageUid: this.config.languageUid ?? 0,
            // File metadata has no AQG finding of its own: the reloaded form shows whether the applied value was saved.
            value: this.config.mode === 'metadata' ? this.currentValue() : '',
            suggestedAt: Number(this.result?.response?.data?.serverTime ?? Math.floor(Date.now() / 1000)),
        });
        writePending(this.pendingKey(), items);
    }

    dropPending() {
        writePending(this.pendingKey(), readPending(this.pendingKey()).filter((item) => item.inputName !== this.inputName));
    }

    showOutcome(status, message) {
        const host = this.fieldItem;
        host?.querySelector('.aqg-media-outcome')?.remove();
        const outcome = document.createElement('div');
        outcome.className = `aqg-media-outcome aqg-media-outcome--${status}`;
        outcome.setAttribute('role', 'status');
        const step = status === 'verified' ? 'verified' : (status === 'not_saved' ? 'filled' : 'saved');
        outcome.innerHTML = `${ladderHtml(this.labels, step, {notVerifiedReason: status === 'still_reported' ? 'x' : ''})}<p>${escapeHtml(message)}</p>`;
        host?.appendChild(outcome);
    }
}

/**
 * Several images in one field (UX Atlas 05 D): one line above the image list when at least two rendered references
 * have no text of their own and no file default. "Suggest for N missing" opens the wand of each in turn: every image
 * keeps its own review; nothing is applied in bulk.
 */
function renderFieldBars(wands) {
    const groups = new Map();
    for (const wand of wands) {
        if (wand.config.mode !== 'reference') {
            continue;
        }
        const key = `${wand.config.parentTable}:${wand.config.parentUid}:${wand.config.parentField}`;
        groups.set(key, [...(groups.get(key) ?? []), wand]);
    }
    for (const group of groups.values()) {
        const missing = group.filter((wand) => !wand.anchor.hidden && (wand.inherits() ? !wand.config.hasFileDefault : wand.currentValue().trim() === ''));
        const container = group[0].anchor.closest('[data-object-group]')?.parentElement;
        container?.querySelector(':scope > .aqg-media-fieldbar')?.remove();
        if (missing.length < 2 || !container) {
            continue;
        }
        const labels = group[0].labels;
        const bar = document.createElement('div');
        bar.className = 'aqg-media-fieldbar';
        bar.innerHTML = `<span>${(missing.length === 1 ? labels.h('fieldBarMissingOne', '1 of %d images has no alternative text', group.length) : labels.h('fieldBarMissing', '%d of %d images have no alternative text', missing.length, group.length))}</span>
            <button type="button" class="btn btn-default btn-sm"><span class="aqg-media-wand-icon" aria-hidden="true"></span>${labels.h('fieldBarAction', 'Review the first missing image')}</button>`;
        bar.querySelector('button')?.addEventListener('click', () => {
            const first = missing.find((wand) => !wand.popover) ?? missing[0];
            first.anchor.scrollIntoView({block: 'center'});
            first.openPopover();
        });
        container.insertBefore(bar, container.firstChild);
    }
}

/** After TYPO3 Save: verify what was applied in this form before, with AQG's structured check of the saved record. */
async function verifyPending(wands) {
    const byKey = new Map();
    for (const wand of wands) {
        byKey.set(wand.pendingKey(), [...(byKey.get(wand.pendingKey()) ?? []), wand]);
    }
    for (const [key, group] of byKey) {
        const pending = readPending(key);
        if (pending.length === 0) {
            continue;
        }
        writePending(key, []);
        const first = group[0];
        const match = (item) => group.find((wand) => wand.config.mode === 'metadata'
            ? wand.field === item.field
            : (item.referenceUid > 0 ? wand.config.referenceUid === item.referenceUid : wand.config.fileUid === item.fileUid && wand.config.referenceUid > 0));
        if (first.config.mode === 'metadata') {
            const saved = pending.filter((item) => match(item)?.currentValue() === item.value);
            for (const item of pending.filter((entry) => !saved.includes(entry))) {
                match(item)?.showOutcome('not_saved', referenceOutcome(first.labels, 'not_saved'));
            }
            if (saved.length === 0) {
                continue;
            }
            const response = await postMediaAi('verifyFile', {fileUid: first.config.fileUid, languageUid: first.config.languageUid});
            for (const item of saved) {
                const wand = match(item);
                const status = response.ok ? response.data.status : 'check_failed';
                wand?.showOutcome(status === 'verified' ? 'verified' : 'saved', response.ok
                    ? metadataOutcome(first.labels, response.data)
                    : first.l('verifyFailed', 'The check could not run. The metadata stays saved.'));
            }
            continue;
        }
        const items = pending.map((item) => ({...item, referenceUid: item.referenceUid > 0 ? item.referenceUid : (match(item)?.config.referenceUid ?? 0)}));
        const response = await postMediaAi('verifyReference', {
            parentTable: first.config.parentTable,
            parentUid: first.config.parentUid,
            fieldName: first.config.parentField,
            items: items.map((item) => ({referenceUid: item.referenceUid, suggestedAt: item.suggestedAt})),
        });
        for (const item of items) {
            const wand = match(item);
            const result = (response.data?.items ?? []).find((entry) => entry.referenceUid === item.referenceUid);
            const status = response.ok ? (result?.status ?? 'not_verified') : 'check_failed';
            wand?.showOutcome(status, referenceOutcome(first.labels, status));
        }
    }
}

function referenceOutcome(labels, status) {
    switch (status) {
        case 'verified':
            return labels.l('outcomeVerified', 'Resolved and verified: AQG\'s structured image checks of the saved record no longer report this image. The file\'s other uses are unchanged.');
        case 'still_reported':
            return labels.l('outcomeStill', 'Saved, but AQG\'s structured image checks still report this image.');
        case 'not_saved':
            return labels.l('outcomeNotSaved', 'The change was not saved. The field shows the stored value.');
        case 'workspace':
            return labels.l('outcomeWorkspace', 'Saved to the workspace version. Verification follows after publishing.');
        default:
            return labels.l('outcomeNotVerified', 'Saved, not verified: the check could not run.');
    }
}

function metadataOutcome(labels, data) {
    switch (data.status) {
        case 'verified':
            return Number(data.checked ?? 0) === 1
                ? labels.l('verifyVerifiedOne', 'Verified: AQG\'s structured image checks no longer report the use that shows this file default.')
                : labels.l('verifyVerified', 'Verified: AQG\'s structured image checks no longer report the %d uses that show this file default.', Number(data.checked ?? 0));
        case 'still_reported':
            return labels.l('verifyStill', 'Not verified: %d of %d uses are still reported, for example for an empty text of their own, a text over 120 characters or one that starts with "image of".', Number(data.reported ?? 0), Number(data.checked ?? 0));
        case 'workspace':
            return labels.l('verifyWorkspace', 'Saved to the workspace version. Verification follows after publishing.');
        case 'no_uses':
            return labels.l('verifyNoUses', 'Saved, not verified: the file is not used on any page yet. AQG checks it once a content scan finds it in a use.');
        default:
            return labels.l('verifyNotVisible', 'Saved, not verified: the uses that inherit this text are on pages you cannot read.');
    }
}

const wands = [];

function scan() {
    const found = [];
    for (const anchor of document.querySelectorAll('.t3js-aqg-media-wand:not([data-aqg-ready])')) {
        try {
            const wand = new MediaAiWand(anchor);
            wand.init();
            wands.push(wand);
            found.push(wand);
        } catch {
            anchor.remove();
        }
    }
    if (found.length > 0) {
        verifyPending(found);
        renderFieldBars(wands.filter((wand) => wand.anchor.isConnected));
    }
}

scan();
// Inline records (IRRE) render later, when added or expanded.
new MutationObserver(() => scan()).observe(document.body, {childList: true, subtree: true});
document.addEventListener('change', (event) => {
    if (event.target instanceof Element && event.target.closest('.t3js-formengine-field-item')) {
        renderFieldBars(wands.filter((wand) => wand.anchor.isConnected));
    }
});
