import {postEditorAi} from '@priebera/a11y-quality-gate/editor-ai/editor-ai-client.js';
import {escapeHtml, provenanceLine, sentDetailsHtml} from '@priebera/a11y-quality-gate/editor-ai/editor-ai-session.js';

/**
 * "Review fixes (n)": every AI and rule-based fix of one field in one dialog (Atlas pattern B, the escalation path).
 * AI items start undecided and are fetched one after another (the rate limit queues instead of failing); rule-based
 * items start accepted and say "No AI". Nothing changes until Apply; Apply runs one editor batch per accepted item in
 * document order, so Ctrl+Z steps back item by item. Items whose element changed meanwhile are marked outdated and
 * skipped. Cancel and Escape discard everything without touching the editor.
 */
export class EditorAiReviewDialog {
    constructor({entries, config, label, announce, editorType, onApplied, returnFocus}) {
        this.entries = entries;
        this.config = config ?? {};
        this.label = label;
        this.announce = announce ?? (() => {});
        this.editorType = editorType;
        this.onApplied = onApplied ?? (() => {});
        this.returnFocus = returnFocus;
        this.items = [];
        this.dialog = null;
        this.abortController = new AbortController();
        this.applied = false;
    }

    l(key, fallback) {
        return this.label(key, fallback);
    }

    lh(key, fallback) {
        return escapeHtml(this.l(key, fallback));
    }

    open() {
        this.items = this.entries.map(({issue, adapter}, index) => {
            const ruleBased = issue.aiTarget?.operation === 'heading_level';
            const lock = adapter.lock(issue);
            const item = {index, issue, adapter, ruleBased, lock, state: 'queued', decision: 'undecided', value: '', data: null, reason: ''};
            if (!lock.ok) {
                item.state = 'manual';
                item.reason = lock.reason;
            } else if (ruleBased) {
                item.state = adapter.canRuleBasedFix() ? 'ready' : 'manual';
                item.reason = item.state === 'manual' ? (adapter.ruleBasedFixSupported === false ? 'no_rule_based_fix' : 'heading_level_unavailable') : '';
                item.decision = item.state === 'ready' ? 'accept' : 'undecided';
            }
            return item;
        });

        const dialog = document.createElement('dialog');
        dialog.className = 'aqg-ai-dialog';
        dialog.setAttribute('aria-labelledby', 'aqg-ai-dialog-title');
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            this.close();
        });
        dialog.addEventListener('click', (event) => this.onClick(event));
        dialog.addEventListener('input', (event) => this.onInput(event));
        document.body.appendChild(dialog);
        this.dialog = dialog;
        this.render();
        dialog.showModal();
        dialog.querySelector('#aqg-ai-dialog-title')?.focus();
        this.prepare();
    }

    async prepare() {
        for (const item of this.items) {
            if (item.state !== 'queued' || this.abortController.signal.aborted) {
                continue;
            }
            item.state = 'preparing';
            this.render();
            let response = await postEditorAi('fix', item.adapter.requestPayload(item.issue), {signal: this.abortController.signal});
            if (response.code === 'rate_limited' && !this.abortController.signal.aborted) {
                // Wait for the window instead of failing the rest of the field.
                await new Promise((resolve) => window.setTimeout(resolve, Math.min(65, Number(response.data?.retryAfter ?? 30)) * 1000));
                response = await postEditorAi('fix', item.adapter.requestPayload(item.issue), {signal: this.abortController.signal});
            }
            if (response.code === 'cancelled') {
                return;
            }
            if (!response.ok) {
                item.state = 'failed';
                item.reason = response.code;
            } else if (response.data.status === 'suggestion') {
                item.state = 'ready';
                item.data = response.data;
                item.value = response.data.value;
            } else {
                item.state = response.data.status === 'needs_review' ? 'needs_review' : 'failed';
                item.reason = response.data.status === 'needs_review' ? (response.data.reason || '') : 'invalid_response';
                item.data = response.data;
            }
            this.render();
        }
        this.announce(this.l('aiReviewReady', 'All suggestions are ready. Decide on each one.'));
    }

    onClick(event) {
        const button = event.target instanceof Element ? event.target.closest('[data-aqg-review]') : null;
        if (!button || button.disabled) {
            return;
        }
        const action = button.getAttribute('data-aqg-review');
        const item = this.items[Number(button.getAttribute('data-index') ?? -1)];
        if (action === 'accept' || action === 'skip') {
            item.decision = item.decision === action ? 'undecided' : action;
            this.render();
            this.dialog.querySelector(`[data-aqg-review="${action}"][data-index="${item.index}"]`)?.focus();
        } else if (action === 'apply') {
            this.apply();
        } else if (action === 'cancel' || action === 'close') {
            this.close();
        }
    }

    onInput(event) {
        const input = event.target instanceof Element ? event.target.closest('[data-aqg-review-value]') : null;
        if (!input) {
            return;
        }
        const item = this.items[Number(input.getAttribute('data-index') ?? -1)];
        item.value = input.value;
        const preview = this.dialog.querySelector(`[data-aqg-review-preview="${item.index}"]`);
        if (preview) {
            preview.innerHTML = this.previewHtml(item);
        }
        this.renderFooter();
    }

    accepted() {
        return this.items.filter((item) => item.decision === 'accept' && item.state === 'ready'
            && (item.ruleBased || String(item.value ?? '').trim() !== ''));
    }

    apply() {
        const accepted = this.accepted();
        let applied = 0;
        for (const item of accepted) {
            if (item.adapter.isStale()) {
                item.state = 'outdated';
                continue;
            }
            const result = item.adapter.apply(item.ruleBased ? '' : String(item.value).trim());
            if (result.ok) {
                item.state = 'applied';
                applied++;
                this.onApplied({issue: item.issue, value: item.value, expected: result.expected, suggestion: item.data, adapter: item.adapter});
            } else {
                item.state = result.reason === 'stale' ? 'outdated' : 'rejected';
                item.reason = result.reason;
            }
        }
        this.applied = applied > 0;
        this.summary = applied;
        this.render();
        this.announce((applied === 1
            ? this.l('aiReviewAppliedOne', '1 change applied to the text. Not saved yet.')
            : this.l('aiReviewAppliedOther', '%d changes applied to the text. Not saved yet.')).replace('%d', String(applied)));
        this.dialog.querySelector('[data-aqg-review="close"]')?.focus();
    }

    close() {
        this.abortController.abort();
        for (const item of this.items) {
            if (item.state !== 'applied') {
                item.adapter.release?.();
            }
        }
        this.dialog?.close();
        this.dialog?.remove();
        this.dialog = null;
        // The invoker may have been re-rendered meanwhile (the summary bar follows the live check).
        const target = typeof this.returnFocus === 'function' ? this.returnFocus() : this.returnFocus;
        if (target instanceof HTMLElement && target.isConnected) {
            target.focus();
        }
    }

    render() {
        if (!this.dialog) {
            return;
        }
        // Items re-render while suggestions stream in: keep keyboard focus on the same control.
        const active = this.dialog.contains(document.activeElement) ? document.activeElement : null;
        const focusedValue = active?.matches?.('[data-aqg-review-value]') ? active.getAttribute('data-index') : null;
        const focusedControl = active && !focusedValue
            ? (active.getAttribute('data-aqg-review') ? `[data-aqg-review="${active.getAttribute('data-aqg-review')}"]${active.hasAttribute('data-index') ? `[data-index="${active.getAttribute('data-index')}"]` : ''}` : (active.id ? `#${active.id}` : ''))
            : '';
        const ready = this.items.filter((item) => !['queued', 'preparing'].includes(item.state)).length;
        const pending = this.items.length - ready;
        this.dialog.innerHTML = `
            <div class="aqg-ai-dialog__head">
                <h2 class="aqg-ai-dialog__title" id="aqg-ai-dialog-title" tabindex="-1">${this.editorType === 'html' ? this.lh('aiReviewTitleHtml', 'Review AI fixes for this HTML field') : this.lh('aiReviewTitle', 'Review fixes for this text field')}</h2>
                <button type="button" class="aqg-ai-dialog__close" data-aqg-review="cancel" aria-label="${this.lh('close', 'Close')}"><span aria-hidden="true">×</span></button>
            </div>
            <div class="aqg-ai-dialog__body">
                <p class="aqg-ai-dialog__intro" role="status">${this.applied
                    ? escapeHtml((this.summary === 1 ? this.l('aiReviewAppliedOne', '1 change applied to the text. Not saved yet.') : this.l('aiReviewAppliedOther', '%d changes applied to the text. Not saved yet.')).replace('%d', String(this.summary)))
                    : (pending > 0
                        ? escapeHtml(this.l('aiReviewPreparing', 'Preparing suggestions: %1$d of %2$d ready').replace('%1$d', String(ready)).replace('%2$d', String(this.items.length)))
                        : this.lh('aiReviewDecide', 'Decide on each suggestion. Applied changes stay unsaved until you save the record.'))}</p>
                ${this.provenanceHtml()}
                <ol class="aqg-ai-dialog__items">${this.items.map((item) => this.itemHtml(item)).join('')}</ol>
                <p class="aqg-ai__note">${this.lh('aiReviewOnlyEditor', 'Apply only changes the editor content. Saving happens with TYPO3 Save.')} ${this.lh('aiReviewUndo', 'Each change can be undone with Ctrl+Z in the editor.')}</p>
            </div>
            <div class="aqg-ai-dialog__foot" data-aqg-review-foot></div>`;
        this.renderFooter();
        if (focusedControl !== '') {
            (this.dialog.querySelector(focusedControl) ?? this.dialog.querySelector('#aqg-ai-dialog-title'))?.focus();
        }
        if (focusedValue !== null) {
            const input = this.dialog.querySelector(`[data-aqg-review-value][data-index="${focusedValue}"]`);
            input?.focus();
            if (input && typeof input.setSelectionRange === 'function') {
                input.setSelectionRange(input.value.length, input.value.length);
            }
        }
    }

    /**
     * Model and "What was sent?" once for the dialog (Atlas 02 · B): every AI item of one field is sent the same way,
     * so the first answer describes them all.
     */
    provenanceHtml() {
        const answer = this.items.find((item) => !item.ruleBased && Array.isArray(item.data?.sent) && item.data.sent.length > 0)?.data ?? null;
        const line = provenanceLine(answer, this.config);
        if (line === '' && answer === null) {
            return '';
        }
        return `<div class="aqg-ai-dialog__provenance"><span class="aqg-ai__model">${escapeHtml(line)}</span>${answer ? sentDetailsHtml(answer, this.config, (key, fallback) => this.l(key, fallback)) : ''}</div>`;
    }

    renderFooter() {
        const foot = this.dialog?.querySelector('[data-aqg-review-foot]');
        if (!foot) {
            return;
        }
        if (this.applied) {
            foot.innerHTML = `<button type="button" class="aqg-ai__btn aqg-ai__btn--primary" data-aqg-review="close">${this.lh('aiReviewBack', 'Back to the editor')}</button>`;
            return;
        }
        const accepted = this.accepted().length;
        const undecided = this.items.filter((item) => item.decision === 'undecided' && ['ready', 'needs_review'].includes(item.state)).length;
        const ruleBased = this.items.filter((item) => item.ruleBased && item.decision === 'accept').length;
        const status = [
            undecided > 0 ? this.l('aiReviewUndecided', '%d undecided — stay unchanged').replace('%d', String(undecided)) : '',
            ruleBased > 0 ? this.l('aiReviewRuleAccepted', 'rule-based: %d accepted').replace('%d', String(ruleBased)) : '',
        ].filter(Boolean).join(' · ');
        foot.innerHTML = `
            <span class="aqg-ai-dialog__status">${escapeHtml(status)}</span>
            <button type="button" class="aqg-ai__btn" data-aqg-review="cancel">${this.lh('aiCancel', 'Cancel')}</button>
            <button type="button" class="aqg-ai__btn aqg-ai__btn--primary" data-aqg-review="apply" ${accepted === 0 ? 'disabled' : ''}>${accepted === 0
                ? this.lh('aiReviewNothing', 'Nothing to apply')
                : this.lh('aiReviewApply', 'Apply %d accepted').replace('%d', String(accepted))}</button>`;
    }

    itemHtml(item) {
        const issue = item.issue;
        const severity = String(issue.severity ?? 'warning');
        const chips = {
            queued: this.l('aiReviewQueued', 'Waiting'),
            preparing: this.l('aiReviewPreparingItem', 'Preparing'),
            ready: item.ruleBased ? this.l('aiNoAi', 'No AI') : '',
            needs_review: this.l('aiNeedsReview', 'No safe suggestion'),
            failed: this.l('aiReviewFailed', 'Failed'),
            manual: this.l('aiManualOnly', 'Manual fix only'),
            outdated: this.l('aiReviewOutdated', 'Outdated'),
            rejected: this.l('aiNotApplied', 'Not applied'),
            applied: this.editorType === 'html' ? this.l('aiStepInSource', 'In source, unsaved') : this.l('aiStepInText', 'In text, unsaved'),
        };
        // Atlas 02 · B: Accept / Skip as one segmented control in the item header.
        const decisionButtons = item.state === 'ready'
            ? `<div class="aqg-ai-dialog__decision" role="group" aria-label="${this.lh('aiReviewDecision', 'Decision')}">
                    <button type="button" class="aqg-ai-dialog__segment" data-aqg-review="accept" data-index="${item.index}" aria-pressed="${item.decision === 'accept'}"><span class="aqg-ai-dialog__check" aria-hidden="true"></span>${this.lh('aiReviewAccept', 'Accept')}</button>
                    <button type="button" class="aqg-ai-dialog__segment" data-aqg-review="skip" data-index="${item.index}" aria-pressed="${item.decision === 'skip'}">${this.lh('aiReviewSkip', 'Skip')}</button>
               </div>`
            : '';
        const chip = chips[item.state]
            ? (item.state === 'applied'
                ? `<span class="aqg-outcome aqg-outcome--inserted">${escapeHtml(chips[item.state])}</span>`
                : `<span class="aqg-ai-dialog__chip">${escapeHtml(chips[item.state])}</span>`)
            : '';
        let body = '';
        if (item.state === 'ready' || item.state === 'applied' || item.state === 'outdated') {
            body = `<div class="aqg-ai-dialog__preview" data-aqg-review-preview="${item.index}">${this.previewHtml(item)}</div>`;
            if (!item.ruleBased && item.state === 'ready') {
                body += `<label class="aqg-ai__label" for="aqg-review-value-${item.index}">${this.lh('aiFieldValue', 'Value')}</label>
                    <input id="aqg-review-value-${item.index}" class="aqg-ai__input" type="text" data-aqg-review-value data-index="${item.index}" value="${escapeHtml(item.value)}">
                    ${item.data?.reason ? `<p class="aqg-ai__why"><strong>${this.lh('aiWhy', 'Why')}:</strong> ${escapeHtml(item.data.reason)}</p>` : ''}`;
            }
            if (item.ruleBased) {
                body += `<p class="aqg-ai__note">${this.lh('aiRuleBasedNote', 'A rule-based fix without an AI request.')}</p>`;
            }
        } else if (item.state === 'needs_review') {
            body = `<p class="aqg-ai__help">${escapeHtml(item.reason || this.l('aiNeedsReviewHelp', 'The context does not allow a reliable suggestion. Please fix it yourself.'))}</p>`;
        } else if (['failed', 'manual', 'rejected'].includes(item.state)) {
            body = `<p class="aqg-ai__help">${escapeHtml(this.reasonText(item.reason))}</p>`;
        }
        if (item.state === 'outdated') {
            body += `<p class="aqg-ai__help">${this.lh('aiStaleHelp', 'The marked element was edited after this suggestion was made. Applying would overwrite your edit.')}</p>`;
        }

        return `
            <li class="aqg-ai-dialog__item aqg-ai-dialog__item--${escapeHtml(item.state)}${item.decision === 'accept' && item.state === 'ready' ? ' is-accepted' : ''}">
                <div class="aqg-ai-dialog__item-head">
                    <span class="ck-a11y-panel__severity ck-a11y-panel__severity--${escapeHtml(severity)}"><span class="ck-a11y-panel__severity-dot" aria-hidden="true"></span>${escapeHtml(this.severityLabel(severity))}</span>
                    <strong class="aqg-ai-dialog__item-title">${escapeHtml(EditorAiReviewDialog.itemTitle(issue))}</strong>
                    ${chip}
                    ${decisionButtons}
                </div>
                <div class="aqg-ai-dialog__item-body">
                    <p class="aqg-ai__target">${escapeHtml(item.adapter.describeTarget(issue))}</p>
                    ${body}
                </div>
            </li>`;
    }

    /** Items are titled with the plain rule name (`rule.<id>.title`), never the stored message (audit F-11). */
    static itemTitle(issue) {
        return issue?.ruleTitle || issue?.message || issue?.ruleId || '';
    }

    previewHtml(item) {
        if (item.ruleBased) {
            return `<dl class="aqg-ai__diff">
                <div class="aqg-ai__diff-row aqg-ai__diff-row--before"><dt>${this.lh('aiBefore', 'Before')}</dt><dd>H${escapeHtml(item.issue.aiTarget.headingLevel)} ${escapeHtml(item.issue.aiTarget.element?.text ?? '')}</dd></div>
                <div class="aqg-ai__diff-row aqg-ai__diff-row--after"><dt>${this.lh('aiAfter', 'After')}</dt><dd>H${escapeHtml(item.issue.aiTarget.targetHeadingLevel)} ${escapeHtml(item.issue.aiTarget.element?.text ?? '')}</dd></div>
            </dl>`;
        }
        const value = String(item.value ?? '').trim();
        const preview = item.adapter.preview(value);
        if (preview.kind === 'source') {
            if (!preview.ok) {
                return `<p class="aqg-ai__help">${escapeHtml(this.reasonText(preview.reason))}</p>`;
            }
            const diff = preview.diff;
            return `<div class="aqg-ai__srcdiff">
                <div class="aqg-ai__srcdiff-line aqg-ai__srcdiff-line--del"><span class="aqg-ai__srcdiff-num">${diff.line}</span><span class="aqg-ai__srcdiff-sign">-</span><code>${escapeHtml(diff.head)}<del>${escapeHtml(diff.removed)}</del>${escapeHtml(diff.tail)}</code></div>
                <div class="aqg-ai__srcdiff-line aqg-ai__srcdiff-line--ins"><span class="aqg-ai__srcdiff-num">${diff.line}</span><span class="aqg-ai__srcdiff-sign">+</span><code>${escapeHtml(diff.head)}<mark>${escapeHtml(diff.inserted)}</mark>${escapeHtml(diff.tail)}</code></div>
            </div>`;
        }
        return `<dl class="aqg-ai__diff">
            <div class="aqg-ai__diff-row aqg-ai__diff-row--before"><dt>${this.lh('aiBefore', 'Before')}</dt><dd>${preview.before !== '' ? escapeHtml(preview.before) : `<em>${this.lh('aiEmpty', '(empty)')}</em>`}</dd></div>
            <div class="aqg-ai__diff-row aqg-ai__diff-row--after"><dt>${this.lh('aiAfter', 'After')}</dt><dd>${escapeHtml(preview.after)}</dd></div>
        </dl>`;
    }

    reasonText(reason) {
        const copy = {
            rate_limited: ['aiErrorRateLimited', 'Too many requests'],
            provider_timeout: ['aiErrorTimeout', 'No answer from the AI provider'],
            provider_unavailable: ['aiErrorProviderUnavailable', 'The AI provider is unavailable right now'],
            invalid_response: ['aiInvalid', 'The AI answer could not be used'],
            image_unavailable: ['aiImageUnavailableHelp', 'AQG reads only images stored in TYPO3. Write the alternative text yourself or check the file.'],
            privacy_images: ['aiPrivacyImagesHelp', 'This site does not allow sending images to AI providers. Please write the alternative text yourself.'],
            stale: ['aiStaleHelp', 'The marked element was edited after this suggestion was made. Applying would overwrite your edit.'],
            nested_markup: ['aiTargetNested', 'The element contains formatting or other markup. AQG would remove it, so it offers no automatic change.'],
            mixed_formatting: ['aiTargetNested', 'The element contains formatting or other markup. AQG would remove it, so it offers no automatic change.'],
            template_placeholder: ['aiTargetTemplate', 'This element contains template placeholders. What visitors get is not in this source.'],
            source_unparseable: ['aiSourceUnparseable', 'AQG cannot read the source unambiguously (a tag, comment or quote is not closed). Fix the source; the guidance still applies.'],
            heading_level_unavailable: ['aiHeadingLevelUnavailable', 'This heading level is not available in this editor. Change the level by hand.'],
            no_rule_based_fix: ['aiNoRuleFix', 'No rule-based level change is possible here.'],
            link_text_disabled: ['aiLinkTextDisabled', 'AI link and iframe title suggestions are turned off for this site.'],
        }[reason] ?? ['aiTargetNotProvable', 'AQG cannot identify this exact element in the editor, so it will not change it. Fix it by hand.'];
        return this.l(copy[0], copy[1]);
    }

    severityLabel(severity) {
        return {
            critical: this.l('severityCritical', 'Critical'),
            warning: this.l('severityWarning', 'Warning'),
            needs_review: this.l('severityNeedsReview', 'Needs review'),
        }[severity] ?? this.l('severityInfo', 'Info');
    }
}
