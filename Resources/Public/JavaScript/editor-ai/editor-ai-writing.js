import {postEditorAi} from '@priebera/a11y-quality-gate/editor-ai/editor-ai-client.js';
import {escapeHtml} from '@priebera/a11y-quality-gate/editor-ai/editor-ai-session.js';

/**
 * Writing aids in CKEditor (no finding needed): make the selected text clearer, draft plain language, or draft a
 * translation inside a translated record. Gated by User TSconfig options.a11y_quality_gate.ai.writing (default off).
 *
 * Only plain text inside one block is rewritten: a selection with links, inline objects or mixed formatting is refused
 * so no markup is lost. The selection is locked with a model marker; Apply replaces exactly that text in one batch
 * (one Ctrl+Z) and only if it is unchanged. Results never apply themselves.
 */
const MAX_TEXT = 1500;

export class EditorAiWriting {
    constructor({editor, config, label, announce, recordUid, fieldName}) {
        this.editor = editor;
        this.config = config ?? {};
        this.label = label;
        this.announce = announce ?? (() => {});
        this.recordUid = recordUid;
        this.fieldName = fieldName;
        this.menu = null;
        this.dialog = null;
        this.markerName = '';
        this.snapshot = '';
        this.abortController = null;
        this.sequence = 0;
    }

    l(key, fallback) {
        return this.label(key, fallback);
    }

    lh(key, fallback) {
        return escapeHtml(this.l(key, fallback));
    }

    buttonHtml() {
        return this.config.writing
            ? `<button class="ck-a11y-summary__link" type="button" data-a11y-writing="1" aria-haspopup="menu" aria-expanded="false">${this.lh('aiWriting', 'Writing')}</button>`
            : '';
    }

    /** The plain text to rewrite: the selection, or the block at the caret; null with a reason otherwise. */
    selectionRange() {
        const model = this.editor.model;
        const selection = model.document.selection;
        let range = selection.getFirstRange();
        if (!range) {
            return {reason: 'aiWritingNoText'};
        }
        if (range.isCollapsed) {
            const block = range.start.parent;
            if (!block || !block.is('element') || block.isEmpty) {
                return {reason: 'aiWritingNoText'};
            }
            range = model.createRangeIn(block);
        }
        if (range.start.parent !== range.end.parent) {
            return {reason: 'aiWritingOneBlock'};
        }
        let attributes = null;
        let text = '';
        for (const item of range.getItems({shallow: true})) {
            if (!item.is('$textProxy')) {
                return {reason: 'aiWritingPlainOnly'};
            }
            const current = JSON.stringify(Array.from(item.getAttributes()).sort(([a], [b]) => a.localeCompare(b)));
            if (attributes !== null && attributes !== current) {
                return {reason: 'aiWritingPlainOnly'};
            }
            if (Array.from(item.getAttributeKeys()).some((key) => key.startsWith('link') || key.startsWith('html'))) {
                return {reason: 'aiWritingPlainOnly'};
            }
            attributes = current;
            text += item.data;
        }
        if (text.trim() === '' || text.length > MAX_TEXT) {
            return {reason: text.trim() === '' ? 'aiWritingNoText' : 'aiWritingTooLong'};
        }
        return {range, text};
    }

    reasonText(reason) {
        return {
            aiWritingNoText: this.l('aiWritingNoText', 'Place the cursor in a paragraph or select text first.'),
            aiWritingOneBlock: this.l('aiWritingOneBlock', 'Select text inside one paragraph.'),
            aiWritingPlainOnly: this.l('aiWritingPlainOnly', 'Select plain text without links or mixed formatting; AQG would otherwise remove them.'),
            aiWritingTooLong: this.l('aiWritingTooLong', 'Select at most 1500 characters.'),
        }[reason] ?? '';
    }

    toggleMenu(button) {
        if (this.menu && this.menu.isConnected) {
            this.closeMenu();
            return;
        }
        const scope = this.selectionRange();
        const words = scope.text ? scope.text.trim().split(/\s+/).length : 0;
        const menu = document.createElement('div');
        menu.className = 'aqg-ai-menu';
        menu.setAttribute('role', 'menu');
        menu.innerHTML = `
            <p class="aqg-ai-menu__scope">${scope.text
                ? escapeHtml((words === 1 ? this.l('aiWritingScopeOne', 'Selection: 1 word') : this.l('aiWritingScope', 'Selection: %d words')).replace('%d', String(words)))
                : escapeHtml(this.reasonText(scope.reason))}</p>
            <button type="button" role="menuitem" data-aqg-writing="clarity" ${scope.text ? '' : 'disabled'}>${this.lh('aiWritingClarity', 'Make clearer')}</button>
            <button type="button" role="menuitem" data-aqg-writing="plain_language" ${scope.text ? '' : 'disabled'}>${this.lh('aiWritingPlain', 'Plain-language draft')}</button>
            ${this.config.translation ? `<button type="button" role="menuitem" data-aqg-writing="translation" ${scope.text ? '' : 'disabled'}>${this.lh('aiWritingTranslate', 'Translate draft (review)')}</button>` : ''}`;
        menu.addEventListener('click', (event) => {
            const item = event.target instanceof Element ? event.target.closest('[data-aqg-writing]') : null;
            if (item && !item.disabled) {
                const mode = item.getAttribute('data-aqg-writing');
                this.closeMenu();
                this.run(mode, button);
            }
        });
        menu.addEventListener('keydown', (event) => {
            const items = Array.from(menu.querySelectorAll('[role="menuitem"]:not([disabled])'));
            const index = items.indexOf(document.activeElement);
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                this.closeMenu();
                (button.isConnected ? button : document.querySelector('[data-a11y-writing]'))?.focus();
            } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                items[(index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length]?.focus();
            }
        });
        // The summary bar re-renders with every live check; the menu lives in its own host next to it.
        const summary = button.closest('.ck-a11y-summary');
        let host = summary?.nextElementSibling?.classList.contains('aqg-ai-menu-host') ? summary.nextElementSibling : null;
        if (!host) {
            host = document.createElement('div');
            host.className = 'aqg-ai-menu-host';
            (summary ?? button).insertAdjacentElement('afterend', host);
        }
        host.replaceChildren(menu);
        button.setAttribute('aria-expanded', 'true');
        this.menu = menu;
        this.menuButton = button;
        (menu.querySelector('[role="menuitem"]:not([disabled])') ?? menu.querySelector('[role="menuitem"]'))?.focus();
        this.outside = (event) => {
            if (!menu.contains(event.target) && !(event.target instanceof Element && event.target.closest('[data-a11y-writing]'))) {
                this.closeMenu();
            }
        };
        document.addEventListener('mousedown', this.outside, true);
    }

    closeMenu() {
        this.menu?.remove();
        this.menu = null;
        this.menuButton?.setAttribute('aria-expanded', 'false');
        if (this.menuButton && !this.menuButton.isConnected) {
            this.menuButton = null;
        }
        document.removeEventListener('mousedown', this.outside, true);
    }

    lock(range) {
        const model = this.editor.model;
        this.release();
        this.markerName = `aqgAiWriting:${++this.sequence}`;
        model.change((writer) => writer.addMarker(this.markerName, {range, usingOperation: false, affectsData: false}));
    }

    lockedText() {
        const marker = this.editor.model.markers.get(this.markerName);
        if (!marker) {
            return null;
        }
        let text = '';
        for (const item of marker.getRange().getItems({shallow: true})) {
            if (!item.is('$textProxy')) {
                return null;
            }
            text += item.data;
        }
        return text;
    }

    /** The locked text is still one plain run (no link, inline object or mixed formatting added meanwhile). */
    plainShape() {
        const marker = this.editor.model.markers.get(this.markerName);
        if (!marker) {
            return false;
        }
        let attributes = null;
        for (const item of marker.getRange().getItems({shallow: true})) {
            const current = item.is('$textProxy') ? JSON.stringify(Array.from(item.getAttributes()).sort(([a], [b]) => a.localeCompare(b))) : null;
            if (current === null || (attributes !== null && attributes !== current)
                || Array.from(item.getAttributeKeys()).some((key) => key.startsWith('link') || key.startsWith('html'))) {
                return false;
            }
            attributes = current;
        }
        return true;
    }

    release() {
        if (this.markerName && this.editor.model.markers.has(this.markerName)) {
            this.editor.model.change((writer) => writer.removeMarker(this.markerName));
        }
        this.markerName = '';
    }

    async run(mode, invoker) {
        const scope = this.selectionRange();
        if (!scope.text) {
            this.announce(this.reasonText(scope.reason));
            return;
        }
        this.lock(scope.range);
        this.snapshot = scope.text;
        this.mode = mode;
        this.result = null;
        this.openDialog(invoker);
        this.state = 'generating';
        this.renderDialog();
        this.abortController = new AbortController();
        const response = await postEditorAi('write', {
            table: 'tt_content',
            recordUid: this.recordUid,
            fieldName: this.fieldName,
            mode,
            text: scope.text,
        }, {signal: this.abortController.signal});
        if (!this.dialog || response.code === 'cancelled') {
            return;
        }
        if (!response.ok) {
            this.state = 'error';
            this.errorCode = response.code;
        } else {
            this.result = response.data;
            this.value = response.data.text || '';
            this.state = response.data.status === 'suggestion' ? 'ready' : (response.data.status === 'needs_review' ? 'needs_review' : 'invalid');
        }
        this.renderDialog();
        this.announce(this.state === 'ready'
            ? this.l('aiAnnounceReady', 'Suggestion ready. Review it before applying.')
            : this.l('aiNeedsReview', 'No safe suggestion'));
    }

    openDialog(invoker) {
        this.invoker = invoker;
        const dialog = document.createElement('dialog');
        dialog.className = 'aqg-ai-dialog';
        dialog.setAttribute('aria-labelledby', 'aqg-ai-writing-title');
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            this.closeDialog();
        });
        dialog.addEventListener('click', (event) => {
            const action = event.target instanceof Element ? event.target.closest('[data-aqg-writing-action]')?.getAttribute('data-aqg-writing-action') : null;
            if (action === 'apply') {
                this.apply();
            } else if (action === 'cancel') {
                this.closeDialog();
            }
        });
        dialog.addEventListener('input', (event) => {
            if (event.target instanceof HTMLTextAreaElement) {
                this.value = event.target.value;
                const apply = dialog.querySelector('[data-aqg-writing-action="apply"]');
                if (apply) {
                    apply.disabled = this.value.trim() === '';
                }
            }
        });
        document.body.appendChild(dialog);
        this.dialog = dialog;
        dialog.showModal();
    }

    renderDialog() {
        if (!this.dialog) {
            return;
        }
        const title = {
            clarity: this.l('aiWritingClarity', 'Make clearer'),
            plain_language: this.l('aiWritingPlain', 'Plain-language draft'),
            translation: this.l('aiWritingTranslateTitle', 'Review translation: %s').replace('%s', this.result?.targetLanguage ?? ''),
        }[this.mode] ?? '';
        let body = '';
        if (this.state === 'generating') {
            body = `<div class="aqg-ai__progress" role="status"><span class="aqg-ai__spin" aria-hidden="true"></span><span class="aqg-ai__progress-title">${this.lh('aiPreparing', 'Preparing a suggestion…')}</span></div>`;
        } else if (this.state === 'error') {
            body = `<div class="aqg-ai__notice aqg-ai__notice--error"><strong>${this.lh('aiErrorProviderUnavailable', 'The AI provider is unavailable right now')}</strong><p>${escapeHtml(this.errorCode === 'no_permission'
                ? this.l('aiErrorNoPermission', 'AI fixes are not enabled for your user group.')
                : this.l('aiErrorTryLater', 'Try again in a few minutes. Nothing in the text was changed.'))}</p></div>`;
        } else {
            const notes = this.result?.notes ? `<p class="aqg-ai__note">${escapeHtml(this.result.notes)}</p>` : '';
            body = `
                ${this.state !== 'ready' ? `<div class="aqg-ai__notice aqg-ai__notice--review"><strong>${this.lh('aiNeedsReview', 'No safe suggestion')}</strong>${notes}</div>` : ''}
                <div class="aqg-ai-dialog__compare">
                    <div><strong>${this.lh('aiWritingOriginal', 'Original')}</strong><p class="aqg-ai-dialog__original">${escapeHtml(this.snapshot)}</p></div>
                    ${this.state === 'ready' ? `<div><label class="aqg-ai__label" for="aqg-ai-writing-value">${this.lh('aiWritingSuggestion', 'Suggestion (editable)')}</label><textarea id="aqg-ai-writing-value" class="aqg-ai__input" rows="6">${escapeHtml(this.value)}</textarea></div>` : ''}
                </div>
                ${this.state === 'ready' ? notes : ''}
                <p class="aqg-ai__note">${this.lh('aiWritingCheck', 'Binding or legal wording must still be checked by the responsible person. Links and formatting are not part of the rewrite.')}</p>
                ${this.result?.model ? `<details class="aqg-ai__sent"><summary>${this.lh('aiWhatWasSent', 'What was sent?')}</summary><p>${escapeHtml([this.result.model, this.result.providerLabel].filter(Boolean).join(' · '))}</p><p><strong>${this.lh('aiSent', 'Sent')}:</strong> ${this.lh('aiSentSelectedText', 'The selected text')}</p><p><strong>${this.lh('aiNotSent', 'Not sent')}:</strong> ${this.lh('aiWritingNotSent', 'other text of this field, other fields, user names')}</p></details>` : ''}`;
        }
        this.dialog.innerHTML = `
            <div class="aqg-ai-dialog__head">
                <h2 class="aqg-ai-dialog__title" id="aqg-ai-writing-title" tabindex="-1">${escapeHtml(title)}</h2>
                <button type="button" class="aqg-ai-dialog__close" data-aqg-writing-action="cancel" aria-label="${this.lh('close', 'Close')}"><span aria-hidden="true">×</span></button>
            </div>
            <div class="aqg-ai-dialog__body">${body}</div>
            <div class="aqg-ai-dialog__foot">
                <span class="aqg-ai-dialog__status">${this.lh('aiWritingReplaces', 'Replaces the selection in the editor · unsaved until Save')}</span>
                <button type="button" class="aqg-ai__btn" data-aqg-writing-action="cancel">${this.lh('aiCancel', 'Cancel')}</button>
                ${this.state === 'ready' ? `<button type="button" class="aqg-ai__btn aqg-ai__btn--primary" data-aqg-writing-action="apply">${this.lh('aiApplyText', 'Apply to text')}</button>` : ''}
            </div>`;
        (this.dialog.querySelector('#aqg-ai-writing-value') ?? this.dialog.querySelector('#aqg-ai-writing-title'))?.focus();
    }

    apply() {
        const value = String(this.value ?? '').trim();
        // CKEditor's read-only mode does not block model writers, and TYPO3 still submits a read-only field.
        if (value === '' || this.editor.isReadOnly) {
            return;
        }
        if (this.lockedText() !== this.snapshot || !this.plainShape()) {
            this.state = 'needs_review';
            this.result = {...(this.result ?? {}), notes: this.l('aiStaleHelp', 'The marked element was edited after this suggestion was made. Applying would overwrite your edit.')};
            this.renderDialog();
            return;
        }
        const model = this.editor.model;
        const range = model.markers.get(this.markerName).getRange();
        model.change((writer) => {
            const first = Array.from(range.getItems({shallow: true}))[0];
            const attributes = first ? Object.fromEntries(first.getAttributes()) : {};
            const start = range.start;
            writer.remove(range);
            writer.insertText(value, attributes, start);
        });
        this.announce(this.l('aiAnnounceApplied', 'Applied to the text. Not saved yet.'));
        this.closeDialog();
        this.editor.editing.view.focus();
    }

    closeDialog() {
        this.abortController?.abort();
        this.release();
        this.dialog?.close();
        this.dialog?.remove();
        this.dialog = null;
        if (this.invoker instanceof HTMLElement && this.invoker.isConnected) {
            this.invoker.focus();
        } else if (!this.applied) {
            this.editor.editing.view.focus();
        }
    }
}
