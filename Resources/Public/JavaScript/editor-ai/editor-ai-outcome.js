import {postEditorAi} from '@priebera/a11y-quality-gate/editor-ai/editor-ai-client.js';
import {escapeHtml} from '@priebera/a11y-quality-gate/editor-ai/editor-ai-session.js';

/** Applied changes older than this are not checked after a reload: the editor has long moved on. */
const MAX_AGE_MS = 2 * 60 * 60 * 1000;

/**
 * The honest outcome of applied AI changes of one field: "In text, unsaved" while the form is open; after TYPO3 Save
 * reloads the form, AQG asks the server to check the stored record. Only that check can say "Saved" or "Verified".
 * The applied changes survive the reload in sessionStorage (per record and field, never their text beyond the element
 * signature), together with the time the form was last submitted: a reload without a submit (Close, browser reload)
 * is "Not saved" without asking anybody, and a submit that TYPO3 rejected is "Not saved" because the stored record is
 * older than the change.
 *
 * The outcome adds no element of its own: the host renders barHtml() into its existing summary bar and the marker
 * state (isApplied) on its own markers. Each transition is announced once through the host's live region.
 */
export class EditorAiOutcome {
    constructor({table = 'tt_content', recordUid, fieldName, label, announce, editorType, onChange}) {
        this.table = table;
        this.recordUid = recordUid;
        this.fieldName = fieldName;
        this.label = label;
        this.announce = announce ?? (() => {});
        this.onChange = onChange ?? (() => {});
        this.editorType = editorType;
        this.key = `aqg-editor-ai:${table}:${recordUid}:${fieldName}`;
        this.submittedKey = `${this.key}:submitted`;
        this.state = 'none';
        this.results = [];
        // Whether the server confirmed that the record was saved after the change (null: not known).
        this.saved = null;
        this.notSubmitted = false;
        this.onUndoLast = null;
    }

    l(key, fallback) {
        return this.label(key, fallback);
    }

    lh(key, fallback) {
        return escapeHtml(this.l(key, fallback));
    }

    items() {
        try {
            const stored = JSON.parse(window.sessionStorage.getItem(this.key) ?? '[]');
            return Array.isArray(stored) ? stored : [];
        } catch {
            return [];
        }
    }

    store(items) {
        try {
            if (items.length === 0) {
                window.sessionStorage.removeItem(this.key);
                window.sessionStorage.removeItem(this.submittedKey);
            } else {
                window.sessionStorage.setItem(this.key, JSON.stringify(items.slice(-20)));
            }
        } catch {
            // Without storage the outcome is still shown until the form reloads.
        }
    }

    submittedAt() {
        try {
            return Number(window.sessionStorage.getItem(this.submittedKey) ?? 0) || 0;
        } catch {
            return 0;
        }
    }

    /** TYPO3 Save submits the FormEngine form (requestSubmit); Close without saving does not fire "submit". */
    bindForm(form) {
        form?.addEventListener('submit', () => this.markSubmitted(), true);
    }

    markSubmitted() {
        if (this.items().length === 0) {
            return;
        }
        try {
            window.sessionStorage.setItem(this.submittedKey, String(Date.now()));
        } catch {
            // Without storage nothing survives the reload anyway.
        }
    }

    setState(state) {
        this.state = state;
        this.onChange(state);
    }

    /** Is this finding's AI change in the text and not saved yet (marker state "In text, unsaved")? */
    isApplied(id) {
        return this.state === 'inserted' && this.items().some((item) => item.id === id);
    }

    /** An AI change went into the editor (not saved). The session announces it; the bar shows it. */
    added(item) {
        const items = this.items().filter((existing) => existing.id !== item.id);
        items.push({...item, appliedAt: Date.now()});
        this.store(items);
        this.results = [];
        this.saved = null;
        this.setState('inserted');
    }

    removed(id) {
        const items = this.items().filter((existing) => existing.id !== id);
        this.store(items);
        this.setState(items.length > 0 ? 'inserted' : 'none');
    }

    dismiss() {
        this.store([]);
        this.results = [];
        this.setState('none');
    }

    /**
     * On load: applied changes from before a reload. Without a submit after the last change the form was closed or
     * reloaded without TYPO3 Save; otherwise the server checks whether the stored record has the change.
     */
    async verifyOnLoad() {
        const now = Date.now();
        const all = this.items();
        const items = all.filter((item) => Number(item.appliedAt) > 0 && now - Number(item.appliedAt) <= MAX_AGE_MS);
        if (items.length === 0) {
            if (all.length > 0) {
                this.store([]);
            }
            return;
        }
        if (items.length !== all.length) {
            this.store(items);
        }
        const lastApplied = Math.max(...items.map((item) => Number(item.appliedAt)));
        if (this.submittedAt() < lastApplied) {
            this.results = items.map(() => 'not_saved');
            this.saved = false;
            this.notSubmitted = true;
            this.store([]);
            this.finish('not_saved');
            return;
        }
        await this.verify();
    }

    async verify() {
        const items = this.items();
        if (items.length === 0) {
            return;
        }
        this.notSubmitted = false;
        this.setState('checking');
        this.announce(this.title());
        const response = await postEditorAi('verify', {
            table: this.table,
            recordUid: this.recordUid,
            fieldName: this.fieldName,
            items: items.map((item) => ({
                ruleId: item.ruleId,
                tag: item.expected.tag,
                attributes: item.expected.attributes,
                text: item.expected.text,
                occurrence: Number.isInteger(item.expected.occurrence) ? item.expected.occurrence : -1,
                total: Number.isInteger(item.expected.total) ? item.expected.total : -1,
                suggestedAt: Number(item.suggestedAt) || 0,
            })),
        });
        const results = response.ok && Array.isArray(response.data.results) ? response.data.results.map(String) : null;
        if (response.ok && typeof response.data.saved === 'boolean') {
            this.saved = response.data.saved;
        }
        if (results === null || results.length !== items.length) {
            // The check did not run: the items stay for "Check again". "Saved" is said only when the server said so.
            this.results = [];
            this.finish('check_failed');
            return;
        }
        this.results = results;
        // Every answer is final for these items: a later reload must not replay them.
        this.store([]);
        if (this.saved === false || results.every((result) => result === 'not_saved')) {
            // The reloaded form shows the stored record: the unsaved changes are gone and must not count against the next save.
            this.saved = false;
            this.finish('not_saved');
        } else if (results.every((result) => result === 'verified')) {
            this.finish('verified');
        } else {
            this.finish('not_verified');
        }
    }

    finish(state) {
        this.setState(state);
        this.announce(this.sentence());
    }

    /** Title and reason as one announcement ("Resolved and verified. The check of …"). */
    sentence() {
        const title = this.title();
        const help = this.help();
        return help === '' ? title : `${title}${/[.…!?]$/.test(title) ? '' : '.'} ${help}`;
    }

    /** Full sentence of the current state (announcement, tooltip). */
    title() {
        const count = this.items().length || this.results.length;
        switch (this.state) {
            case 'inserted':
                return (count === 1
                    ? this.l('aiOutcomeInsertedOne', '1 change applied — not saved yet')
                    : this.l('aiOutcomeInsertedOther', '%d changes applied — not saved yet')).replace('%d', String(count));
            case 'checking':
                return this.editorType === 'html'
                    ? this.l('aiOutcomeCheckingRecord', 'Checking the saved record…')
                    : this.l('aiOutcomeChecking', 'Checking the saved content…');
            case 'verified':
                return this.l('aiOutcomeVerified', 'Resolved and verified');
            case 'not_verified':
                return this.l('aiOutcomeNotVerified', 'Saved, not verified');
            case 'not_saved':
                return this.l('aiOutcomeNotSaved', 'Not saved — the change was not stored');
            case 'check_failed':
                return this.saved === true
                    ? this.l('aiOutcomeCheckFailed', 'Saved, not verified')
                    : this.l('aiOutcomeCheckUnavailable', 'The saved content could not be checked');
            default:
                return '';
        }
    }

    help() {
        switch (this.state) {
            case 'inserted':
                return this.l('aiOutcomeInsertedHelp', 'Use TYPO3 Save to keep it. The issue only counts as resolved after AQG checked the saved content.');
            case 'verified':
                return this.l('aiOutcomeVerifiedHelp', 'The check of the saved record no longer reports this issue. The rendered page is covered by the next frontend scan.');
            case 'not_verified': {
                if (this.results.includes('still_reported')) {
                    return this.l('aiOutcomeStillReported', 'AQG still reports the issue in the saved content. Open the marker to fix it.');
                }
                if (this.results.includes('not_saved')) {
                    return this.l('aiOutcomePartlyNotSaved', 'Some applied changes were not saved. AQG checks again with the next scan.');
                }
                if (this.results.includes('not_found')) {
                    return this.l('aiOutcomeNotFound', 'The applied change is not in the saved content (it was undone or edited before saving).');
                }
                return this.l('aiOutcomeNotVerifiedHelp', 'Your change is saved; AQG checks again with the next scan.');
            }
            case 'not_saved':
                return this.notSubmitted
                    ? this.l('aiOutcomeNotSubmittedHelp', 'The form was closed or reloaded without TYPO3 Save. Apply the fix again if you still need it.')
                    : this.l('aiOutcomeNotSavedHelp', 'The record was not saved after the change was applied.');
            case 'check_failed':
                return this.saved === true
                    ? this.l('aiOutcomeCheckFailedHelp', 'The check after saving could not run. Your change is saved; AQG checks again with the next scan.')
                    : this.l('aiOutcomeCheckUnavailableHelp', 'AQG could not run the check, so it is not known yet whether the change was saved. Check again.');
            default:
                return '';
        }
    }

    /** Short label inside the summary bar ("1 unsaved", "Checking the saved content…", "1 resolved"). */
    barLabel() {
        switch (this.state) {
            case 'inserted': {
                const count = this.items().length;
                return (count === 1 ? this.l('aiOutcomeBarUnsavedOne', '1 unsaved') : this.l('aiOutcomeBarUnsavedOther', '%d unsaved')).replace('%d', String(count));
            }
            case 'checking':
                return this.title();
            case 'verified': {
                const count = this.results.length;
                return (count === 1 ? this.l('aiOutcomeBarResolvedOne', '1 resolved') : this.l('aiOutcomeBarResolvedOther', '%d resolved')).replace('%d', String(count));
            }
            case 'not_verified':
                return this.l('aiOutcomeBarNotVerified', 'Not verified');
            case 'not_saved':
                return this.l('aiOutcomeBarNotSaved', 'Not saved');
            case 'check_failed':
                return this.saved === true ? this.l('aiOutcomeBarNotVerified', 'Not verified') : this.l('aiOutcomeBarNotChecked', 'Not checked');
            default:
                return '';
        }
    }

    /** Whether the bar shows the outcome's reason in place of its own hint (settled states only). */
    hasHelp() {
        return ['verified', 'not_verified', 'not_saved', 'check_failed'].includes(this.state);
    }

    /**
     * The outcome segment of the host's summary bar, or '' without an outcome. Its text is visual only (aria-hidden):
     * screen readers hear each transition once through the live region, not again with every re-render of the bar.
     */
    barHtml() {
        if (this.state === 'none') {
            return '';
        }
        const tooltip = escapeHtml(this.sentence());
        const icon = this.state === 'checking'
            ? '<span class="ck-a11y-summary__spin ck-a11y-summary__ai-outcome-spin"></span>'
            : '<span class="ck-a11y-summary__ai-outcome-icon"></span>';
        const actions = [];
        if (this.state === 'inserted' && this.onUndoLast) {
            actions.push(`<button type="button" class="ck-a11y-summary__link" data-aqg-ai-outcome="undo" aria-label="${this.lh('aiOutcomeUndoLabel', 'Undo the last AI change')}">${this.lh('aiUndo', 'Undo')}</button>`);
        }
        if (this.state === 'check_failed') {
            actions.push(`<button type="button" class="ck-a11y-summary__link" data-aqg-ai-outcome="check" aria-label="${this.lh('aiOutcomeCheckAgainLabel', 'Check the saved content again')}">${this.lh('aiCheckAgain', 'Check again')}</button>`);
        }
        if (['verified', 'not_verified', 'not_saved'].includes(this.state)) {
            actions.push(`<button type="button" class="ck-a11y-summary__link ck-a11y-summary__ai-outcome-dismiss" data-aqg-ai-outcome="dismiss" aria-label="${this.lh('aiOutcomeDismissLabel', 'Dismiss the AI change status')}" title="${this.lh('aiDismiss', 'Dismiss')}"><span aria-hidden="true">×</span></button>`);
        }
        const help = this.hasHelp() ? `<span class="ck-a11y-summary__help ck-a11y-summary__ai-outcome-help" aria-hidden="true">${escapeHtml(this.help())}</span>` : '';
        return `<span class="ck-a11y-summary__ai-outcome ck-a11y-summary__ai-outcome--${this.state}" data-aqg-ai-outcome-state="${this.state}" title="${tooltip}">`
            + `<span class="ck-a11y-summary__ai-outcome-label" aria-hidden="true">${icon}${escapeHtml(this.barLabel())}</span>`
            + `${help}${actions.join('')}</span>`;
    }

    /** Clicks inside the host's summary bar; true when the outcome handled it. */
    handleClick(target) {
        const button = target instanceof Element ? target.closest('[data-aqg-ai-outcome]') : null;
        if (!button) {
            return false;
        }
        const action = button.getAttribute('data-aqg-ai-outcome');
        if (action === 'check') {
            this.verify();
        } else if (action === 'dismiss') {
            this.dismiss();
        } else if (action === 'undo' && this.onUndoLast) {
            this.onUndoLast();
        }
        return true;
    }
}
