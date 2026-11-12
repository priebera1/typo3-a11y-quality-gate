import {postRemediation, el, format} from '@priebera/a11y-quality-gate/remediation/remediation-api.js';

/**
 * The remediation entries of Page Detail: "Fix with AI" per occurrence (only where the card has no inline AI
 * suggestion of its own, Atlas 07·A), "Explain with AI", "Prepare AI suggestions" for the selection, owner changes and
 * the page plan drawer (07·D). Everything that is prepared lands in the queue for review — nothing here applies a
 * change. Each occurrence has its own result blocks for Explain and Fix: they say what was clicked, what AQG did,
 * whether anything changed, what the request cost and what to do next, and take the focus when the request ends.
 * AI requests run one after another and wait for the rate limit instead of failing the rest.
 */
export class PageDetailRemediation {
    constructor(root) {
        this.root = root;
        this.config = JSON.parse(root.querySelector('[data-aqg-remediation-page-config]')?.textContent ?? '{}');
        this.labels = this.config.labels ?? {};
        this.urls = this.config.urls ?? {};
        this.page = root.closest('.a11y-page-detail') ?? document;
        this.languageUid = Number(this.page.getAttribute?.('data-a11y-language-uid') ?? -1);
        this.live = root.querySelector('[data-aqg-remediation-page-live]');
        this.planDialog = root.querySelector('[data-aqg-remediation-plan-dialog]');
        this.queueLine = root.querySelector('[data-aqg-remediation-summary-queue]');
        this.queue = {pending: 0, inEditor: 0, saved: 0, notVerified: 0, ...(this.config.queue ?? {})};
        this.bulkButton = this.page.querySelector('[data-aqg-remediation-bulk-prepare]');
        this.run = null;
    }

    l(key, fallback = '') {
        const value = this.labels[key];
        return value && value !== `remediation.${key}` ? value : fallback;
    }

    announce(text, target = this.live) {
        if (!target || !text) {
            return;
        }
        target.textContent = '';
        window.setTimeout(() => {
            target.textContent = text;
        }, 30);
    }

    errorText(code, data = {}) {
        if (code === 'rate_limited' && data.retryAfter) {
            return format(this.l('error.rate_limited', 'Too many AI requests. Try again in %d seconds.'), data.retryAfter);
        }
        return this.l(`error.${code}`, '') || this.l('error.generic', 'Something went wrong. Nothing was changed.');
    }

    init() {
        this.page.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const prepare = target?.closest('[data-aqg-remediation-prepare]');
            if (prepare) {
                event.preventDefault();
                this.prepareOne(prepare);
                return;
            }
            const explain = target?.closest('[data-aqg-remediation-explain]');
            if (explain) {
                event.preventDefault();
                this.explainOne(explain);
                return;
            }
            const owner = target?.closest('[data-aqg-owner-set]');
            if (owner) {
                event.preventDefault();
                this.setOwner(owner);
                return;
            }
            const copy = target?.closest('[data-aqg-copy-guidance]');
            if (copy) {
                event.preventDefault();
                this.copyGuidance(copy);
                return;
            }
            if (target?.closest('[data-aqg-remediation-plan]')) {
                event.preventDefault();
                this.openPlan(target.closest('[data-aqg-remediation-plan]'));
                return;
            }
            if (target?.closest('[data-aqg-remediation-bulk-prepare]')) {
                event.preventDefault();
                this.confirmBulk(target.closest('[data-aqg-remediation-bulk-prepare]'));
                return;
            }
            if (target?.closest('[data-action="a11y-bulk-toggle-issue"], [data-action="a11y-bulk-clear"], [data-action="a11y-bulk-select-rule"], [data-action="a11y-bulk-toggle-group"]')) {
                // The existing bulk script updates aria-checked first.
                window.setTimeout(() => this.updateBulkButton(), 0);
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                window.setTimeout(() => this.updateBulkButton(), 0);
            }
        });
        this.updateBulkButton();
    }

    statusSlot(findingUid) {
        return this.page.querySelector(`[data-aqg-remediation-status="${findingUid}"]`);
    }

    /** Whether an occurrence can still be prepared: an AI fix path and no queue item yet (with or without its own button). */
    isEligible(uid) {
        return uid > 0 && this.page.querySelector(`[data-aqg-remediation-occ="${uid}"][data-aqg-remediation-eligible="1"]`) !== null;
    }

    /** Selected open occurrences that have an AI fix path and no queue item yet. */
    selectedEligible() {
        return [...this.page.querySelectorAll('[data-action="a11y-bulk-toggle-issue"][aria-checked="true"]')]
            .map((toggle) => Number(toggle.getAttribute('data-issue-uid') ?? 0))
            .filter((uid) => this.isEligible(uid));
    }

    /** The AI fixes queue opened on exactly this item (the queue checks again that this person may see it). */
    itemUrl(itemUid) {
        const base = String(this.config.queueUrl ?? '');
        return itemUid > 0 && base !== '' ? `${base}${base.includes('?') ? '&' : '?'}item=${itemUid}` : base;
    }

    /**
     * An occurrence now has a queue item: its "Fix with AI" button becomes the item's status ("In AI fixes: …"), and it
     * leaves the bulk selection pool. The next step (review, open) is in the occurrence's result block.
     */
    markQueued(uid, item = null) {
        const line = this.page.querySelector(`[data-aqg-remediation-occ="${uid}"]`);
        if (!line || line.getAttribute('data-aqg-remediation-eligible') !== '1') {
            return;
        }
        line.setAttribute('data-aqg-remediation-eligible', '0');
        const status = String(item?.status ?? 'preparing');
        const chip = el('span', {class: `aqg-remediation__status aqg-remediation__status--${status}`, 'data-aqg-remediation-item-state': String(uid)},
            el('span', {class: 'aqg-remediation__status-glyph', 'aria-hidden': 'true'}),
            `${this.l('pd.queued', 'In AI fixes:')} ${item?.statusLabel ?? ''}`.trim());
        const button = line.querySelector(`[data-aqg-remediation-prepare="${uid}"]`);
        if (button) {
            button.replaceWith(chip);
        } else {
            line.querySelector('.aqg-remediation-occ__actions')?.prepend(chip);
        }
    }

    /** F-32: the summary's queue line follows what was prepared on this page, without a reload. */
    renderQueueLine() {
        if (!this.queueLine || !this.labels['pd.summaryQueue']) {
            return;
        }
        this.queueLine.textContent = format(this.labels['pd.summaryQueue'], this.queue.pending, this.queue.inEditor, this.queue.saved, this.queue.notVerified);
    }

    addPending(count) {
        if (count > 0) {
            this.queue.pending += count;
            this.renderQueueLine();
        }
    }

    updateBulkButton() {
        if (!this.bulkButton) {
            return;
        }
        const count = this.selectedEligible().length;
        this.bulkButton.hidden = count === 0 || !this.config.canBulk;
        this.bulkButton.textContent = format(this.l('pd.bulkPrepare', 'Prepare AI suggestions (%d)'), count);
    }

    /**
     * A reason label without its closing "Nothing was changed." sentence: the result block states that once, in bold.
     */
    withoutChangedNote(text) {
        const note = this.l('pd.result.nothingChanged', 'Nothing was changed.');
        const value = String(text ?? '').trim();
        return note && value.endsWith(note) && value.length > note.length ? value.slice(0, -note.length).trim() : value;
    }

    /** Why nothing was prepared, from a stable reason or error code; never the raw code. */
    reasonText(code, data = {}) {
        return this.l(`reason.${code}`, '') || this.errorText(code, data);
    }

    /**
     * What a click did at the AI provider, from the usage the server recorded for it (aiUsage of explain/generate):
     * nothing sent, the recorded cost, or the counted maximum. A failed request that reached the provider may be billed.
     */
    usageText(usage, failed = false) {
        if (usage?.sent === false) {
            return this.l('pd.usage.notSent', 'No AI request was sent, so nothing was billed.');
        }
        if (usage?.sent !== true) {
            // No answer about usage (network error, gateway timeout, older server): never claim "nothing was sent".
            return this.l('pd.usage.unconfirmed', 'AQG could not confirm whether a request reached the AI provider. AI usage shows what was billed.');
        }
        const parts = [];
        if (usage.basis === 'reported' && usage.cost) {
            parts.push(format(this.l('pd.usage.reported', 'Recorded AI usage: %s.'), usage.cost));
        } else if (usage.basis === 'bound' && usage.cost) {
            parts.push(format(this.l('pd.usage.bound', 'The provider reported no usage; AQG counted the estimated maximum (%s) against the AI budget.'), usage.cost));
        } else if (usage.basis === 'none') {
            parts.push(this.l('pd.usage.none', 'The provider rejected the request before processing it; no cost was recorded.'));
        } else {
            parts.push(this.l('pd.usage.unknown', 'The request was sent; AQG has no price for this model, so the cost is not known.'));
        }
        if ((failed || usage.outcome === 'failed') && usage.basis !== 'none') {
            parts.push(this.l('pd.usage.mayBill', 'The request reached the AI provider, which may bill it although it failed.'));
        }
        return parts.join(' ');
    }

    /**
     * One result block (Explain or Fix) of an occurrence: a heading that names the outcome, the explanation, one line
     * on what changed and on usage, and the next actions. The heading takes the focus once the request has ended.
     */
    resultBlock(region, {tone, title, busy = false, lines = [], actions = [], extra = [], meta = ''}) {
        const headingId = `${region.id}-h`;
        region.className = `aqg-ai-result ${region.classList.contains('aqg-ai-result--explain') ? 'aqg-ai-result--explain' : 'aqg-ai-result--fix'} aqg-ai-result--${tone}`;
        region.setAttribute('aria-labelledby', headingId);
        if (busy) {
            region.setAttribute('aria-busy', 'true');
        } else {
            region.removeAttribute('aria-busy');
        }
        region.replaceChildren(
            el('div', {class: 'aqg-ai-result__head'},
                el('span', {class: 'aqg-ai-result__glyph', 'aria-hidden': 'true'}),
                el('h4', {class: 'aqg-ai-result__title', id: headingId, tabindex: '-1', text: title}),
                meta ? el('span', {class: 'aqg-ai-result__meta', text: meta}) : null,
            ),
            ...lines.filter(Boolean).map((line) => (line instanceof Node ? line : el('p', {class: 'aqg-ai-result__text', text: line}))),
            ...extra,
            ...(actions.some(Boolean) ? [el('div', {class: 'aqg-ai-result__actions'}, ...actions)] : []),
        );
        region.hidden = false;
        return region;
    }

    focusResult(region) {
        region?.querySelector('.aqg-ai-result__title')?.focus({preventScroll: false});
    }

    editRecordAction(findingUid) {
        const url = this.page.querySelector(`[data-aqg-remediation-occ="${findingUid}"]`)?.getAttribute('data-aqg-edit-url') ?? '';
        return url ? el('a', {class: 'btn btn-default btn-sm', href: url, text: this.l('pd.result.editRecord', 'Edit record')}) : null;
    }

    /** The finished "Fix with AI" outcome of one occurrence, specific to what actually happened. */
    renderFixResult(findingUid, outcome) {
        outcome = {...outcome, message: this.withoutChangedNote(outcome.message)};
        const region = this.page.querySelector(`[data-aqg-remediation-fixresult="${findingUid}"]`);
        if (!region) {
            return null;
        }
        const item = outcome.item ?? null;
        const itemUid = Number(outcome.itemUid ?? item?.uid ?? 0);
        const reviewLink = (label, primary = false) => (itemUid > 0
            ? el('a', {class: `btn ${primary ? 'btn-primary' : 'btn-default'} btn-sm`, href: this.itemUrl(itemUid), 'data-aqg-remediation-item-link': String(itemUid), text: label})
            : null);
        const changed = (text) => el('p', {class: 'aqg-ai-result__changed'}, el('strong', {text}));
        const usage = (failed = false) => el('p', {class: 'aqg-ai-result__usage', text: this.usageText(outcome.usage, failed)});
        switch (outcome.state) {
            case 'ready':
                return this.resultBlock(region, {tone: 'ok', title: this.l('pd.result.readyTitle', 'Suggestion ready for review'),
                    lines: [this.l('pd.result.readyText', 'AQG saved the suggestion in AI fixes. The content stays unchanged until someone reviews and approves it.'),
                        outcome.warning ? outcome.warning : null, usage()],
                    actions: [reviewLink(this.l('pd.result.reviewAction', 'Review suggestion'), true)]});
            case 'queued':
                return this.resultBlock(region, {tone: 'info', title: this.l('pd.result.queuedTitle', 'Already in AI fixes'),
                    lines: [format(this.l('pd.result.queuedText', 'This occurrence already has an item in AI fixes. Status: %s'), item?.statusLabel ?? this.l('pd.preparing', 'Preparing AI suggestion…')), outcome.usage ? usage() : null],
                    actions: [reviewLink(this.l('pd.result.openItem', 'Open in AI fixes'), true)]});
            case 'existing':
                return this.resultBlock(region, {tone: item?.status === 'pending_review' ? 'ok' : 'info', title: this.l('pd.result.queuedTitle', 'Already in AI fixes'),
                    lines: [format(this.l('pd.result.queuedText', 'This occurrence already has an item in AI fixes. Status: %s'), item?.statusLabel ?? '—'),
                        item?.statusReasonLabel || null, this.l('pd.usage.notSent', 'No AI request was sent, so nothing was billed.')],
                    actions: [reviewLink(item?.status === 'pending_review' ? this.l('pd.result.reviewAction', 'Review suggestion') : this.l('pd.result.openItem', 'Open in AI fixes'), true)]});
            case 'none':
                return this.resultBlock(region, {tone: 'warn', title: this.l('pd.result.noneTitle', 'No usable suggestion'),
                    lines: [outcome.message || null, changed(this.l('pd.result.noneText', 'The AI answered, but without a suggestion AQG can use. Nothing was changed; fix this occurrence in the record.')), usage()],
                    actions: [this.editRecordAction(findingUid), reviewLink(this.l('pd.result.openItem', 'Open in AI fixes'))]});
            case 'failed': {
                const sent = outcome.usage?.sent !== false;
                const retry = itemUid > 0 && outcome.retryable !== false
                    ? el('button', {type: 'button', class: 'btn btn-default btn-sm', 'data-aqg-remediation-retry': String(findingUid), onclick: (event) => this.retryOne(findingUid, itemUid, event.currentTarget)},
                        this.l('pd.result.retry', 'Try again'), this.fixCost(findingUid) ? el('span', {class: 'aqg-ai-cost', 'aria-hidden': 'true', text: this.fixCost(findingUid)}) : null)
                    : null;
                return this.resultBlock(region, {tone: 'err', title: sent ? this.l('pd.result.failedTitle', 'AI request failed') : this.l('pd.result.unavailableTitle', 'Fix with AI is not possible here'),
                    lines: [outcome.message || null, changed(this.l('pd.result.failedText', 'No suggestion was created and nothing was changed.')), usage(true)],
                    actions: [retry, this.editRecordAction(findingUid), reviewLink(this.l('pd.result.openItem', 'Open in AI fixes'))]});
            }
            default:
                // Refused before anything was queued or sent: unsupported image, missing right, budget, AI paused …
                return this.resultBlock(region, {tone: 'warn', title: this.l('pd.result.unavailableTitle', 'Fix with AI is not possible here'),
                    lines: [outcome.message || null, changed(this.l('pd.result.unavailableText', 'No suggestion was created and nothing was changed.')), usage()],
                    actions: [this.editRecordAction(findingUid)]});
        }
    }

    /** The visible maximum of this occurrence's "Fix with AI" button, for the retry button. */
    fixCost(findingUid) {
        return this.page.querySelector(`[data-aqg-remediation-occ="${findingUid}"] .aqg-remediation-occ__fix .aqg-ai-cost`)?.textContent
            ?? this.page.querySelector(`[data-aqg-remediation-fixresult="${findingUid}"]`)?.dataset.aqgFixCost ?? '';
    }

    pendingFix(findingUid, title) {
        const region = this.page.querySelector(`[data-aqg-remediation-fixresult="${findingUid}"]`);
        if (!region) {
            return;
        }
        const cost = this.fixCost(findingUid);
        if (cost) {
            region.dataset.aqgFixCost = cost;
        }
        this.resultBlock(region, {tone: 'info', busy: true, title, lines: [this.l('pd.result.preparingText', 'AQG is asking the AI provider for a suggestion. This usually takes a few seconds.')]});
    }

    /** aria-busy and aria-disabled, never disabled: a disabled button would drop the keyboard focus. */
    setBusy(button, busy) {
        if (!button) {
            return;
        }
        if (busy) {
            button.setAttribute('aria-busy', 'true');
            button.setAttribute('aria-disabled', 'true');
        } else {
            button.removeAttribute('aria-busy');
            button.removeAttribute('aria-disabled');
        }
    }

    async prepareOne(button) {
        const findingUid = Number(button.getAttribute('data-aqg-remediation-prepare'));
        if (button.hasAttribute('aria-busy')) {
            return;
        }
        const slot = this.statusSlot(findingUid);
        this.setBusy(button, true);
        this.pendingFix(findingUid, this.l('pd.preparing', 'Preparing AI suggestion…'));
        this.announce(this.l('pd.preparing', 'Preparing AI suggestion…'), slot);
        const result = await this.prepareAndGenerate([findingUid], {single: true});
        this.setBusy(button, false);
        const outcome = result.outcomes.get(findingUid) ?? {state: 'unavailable', message: this.errorText('generic')};
        this.finishFix(findingUid, outcome, result.counts.newPending);
    }

    finishFix(findingUid, outcome, newPending = 0) {
        if (outcome.itemUid > 0 || outcome.item) {
            this.markQueued(findingUid, outcome.item ?? null);
            this.addPending(newPending);
            this.updateBulkButton();
        }
        const region = this.renderFixResult(findingUid, outcome);
        const title = region?.querySelector('.aqg-ai-result__title')?.textContent ?? '';
        this.announce([title, outcome.message].filter(Boolean).join('. '), this.statusSlot(findingUid));
        this.focusResult(region);
    }

    /** "Try again" on a failed suggestion: regenerates the same AI fixes item (same checks, a new provider request). */
    async retryOne(findingUid, itemUid, button) {
        if (button?.hasAttribute('aria-busy')) {
            return;
        }
        this.setBusy(button, true);
        this.pendingFix(findingUid, this.l('pd.preparing', 'Preparing AI suggestion…'));
        this.announce(this.l('pd.preparing', 'Preparing AI suggestion…'), this.statusSlot(findingUid));
        const outcome = await this.generateWithRetry(itemUid, () => {}, {regenerate: true});
        this.finishFix(findingUid, outcome);
    }

    /**
     * "Explain with AI": one provider request per click, shown under the occurrence. A shown explanation is only
     * collapsed and expanded again, never requested twice. Nothing is queued and nothing in the content changes.
     */
    async explainOne(button) {
        const findingUid = Number(button.getAttribute('data-aqg-remediation-explain'));
        const region = this.page.querySelector(`[data-aqg-remediation-explanation="${findingUid}"]`);
        if (!region || button.hasAttribute('aria-busy')) {
            return;
        }
        if (region.childElementCount > 0 && region.dataset.aqgExplained === '1') {
            region.hidden = !region.hidden;
            button.setAttribute('aria-expanded', region.hidden ? 'false' : 'true');
            return;
        }
        const slot = this.statusSlot(findingUid);
        this.setBusy(button, true);
        this.resultBlock(region, {tone: 'info', busy: true, title: this.l('pd.explaining', 'Asking AI for an explanation…')});
        button.setAttribute('aria-expanded', 'true');
        this.announce(this.l('pd.explaining', 'Asking AI for an explanation…'), slot);
        const result = await postRemediation(this.urls.explain, {findingUid});
        this.setBusy(button, false);
        if (!result.ok) {
            region.dataset.aqgExplained = '0';
            const message = this.errorText(result.code, result.data);
            this.resultBlock(region, {tone: 'err', title: this.l('pd.result.explainFailedTitle', 'No explanation'),
                lines: [this.withoutChangedNote(message), el('p', {class: 'aqg-ai-result__changed'}, el('strong', {text: this.l('pd.result.nothingChanged', 'Nothing was changed.')})), el('p', {class: 'aqg-ai-result__usage', text: this.usageText(result.data?.aiUsage, true)})]});
            this.announce(`${this.l('pd.result.explainFailedTitle', 'No explanation')}. ${message}`, slot);
            this.focusResult(region);
            return;
        }
        this.renderExplanation(region, findingUid, result.data);
        // Every answer the provider gave is kept (also "no reliable explanation"): asking again would cost again.
        // Only a refused or failed request (above) can be retried.
        region.dataset.aqgExplained = '1';
        this.announce(this.l('pd.result.explainTitle', 'AI explanation'), slot);
        this.focusResult(region);
    }

    renderExplanation(region, findingUid, data) {
        const ruleTitle = this.page.querySelector(`[data-aqg-remediation-occ="${findingUid}"]`)?.getAttribute('data-aqg-rule-title') ?? '';
        const model = [data.modelLabel || data.model, data.providerLabel].filter(Boolean).join(' · ');
        const lines = [ruleTitle ? el('p', {class: 'aqg-ai-result__subject', text: format(this.l('pd.result.explains', 'Explains: %s'), ruleTitle)}) : null];
        if (data.status === 'explanation') {
            lines.push(el('dl', {class: 'aqg-ai-result__body'},
                el('dt', {text: this.l('pd.result.problem', 'What the problem is')}), el('dd', {text: String(data.explanation ?? '')}),
                data.howToFix ? el('dt', {text: this.l('pd.result.howToFix', 'How to fix it')}) : null,
                data.howToFix ? el('dd', {text: String(data.howToFix)}) : null));
        } else {
            lines.push(this.l('pd.explainNeedsReview', 'No reliable explanation for this element. Use the rule guidance above.'));
        }
        lines.push(el('p', {class: 'aqg-ai-result__changed'}, el('strong', {text: this.l('pd.result.nothingChanged', 'Nothing was changed.')}), ' ', this.usageText(data.aiUsage)));
        const sent = Array.isArray(data.sent) ? data.sent : [];
        const extra = sent.length > 0 ? [el('details', {class: 'aqg-ai-result__sent aqg-remediation-occ__sent'},
            el('summary', {text: this.l('pd.explainSent', 'What was sent')}),
            el('ul', {}, sent.map((field) => el('li', {text: this.l(`pd.explainSentField.${field}`, field)}))))] : [];
        this.resultBlock(region, {tone: 'neutral', title: this.l('pd.result.explainTitle', 'AI explanation'), meta: model, lines, extra});
    }

    /**
     * Prepares queue items for the findings, then generates their suggestions one by one. A rate limit waits for the
     * provider's retry time (the queue keeps the item as "waiting"); Stop ends the run and keeps what is finished.
     * Outcomes: ready (a suggestion awaits review), none (the AI answered without a usable suggestion), failed (the
     * generation failed; the item is Failed in AI fixes), queued (still waiting), existing (the finding already had an
     * item; its real status is shown), unavailable (refused before anything was queued or sent).
     */
    async prepareAndGenerate(findingUids, {single = false, onProgress = () => {}} = {}) {
        const outcomes = new Map();
        const prepared = await postRemediation(this.urls.prepare, {findingUids, scope: single ? 'single' : 'bulk'});
        if (!prepared.ok) {
            for (const uid of findingUids) {
                // The prepare route never calls a provider, whatever its answer.
                outcomes.set(uid, {state: 'unavailable', code: prepared.code, message: this.errorText(prepared.code, prepared.data), usage: {sent: false}});
            }
            return {outcomes, counts: {ready: 0, none: 0, skipped: findingUids.length, newPending: 0}};
        }
        // newPending: items created by this run that wait for review (preparing or suggested), for the summary line.
        const counts = {ready: 0, none: 0, skipped: 0, newPending: 0};
        const queue = [];
        for (const entry of prepared.data.items ?? []) {
            if (entry.outcome === 'queued') {
                queue.push(entry);
            } else if (entry.outcome === 'already_queued') {
                // Never "ready" by assumption: the item's real state decides (it may be Failed or No suggestion).
                const itemUid = Number(entry.itemUid ?? 0);
                const current = itemUid > 0 ? await postRemediation(this.urls.item, {item: itemUid, detailed: false}) : null;
                outcomes.set(entry.findingUid, {state: 'existing', itemUid, item: current?.ok ? current.data.item : null, usage: {sent: false}});
                counts.skipped++;
            } else {
                outcomes.set(entry.findingUid, {state: 'unavailable', code: entry.reason, message: this.reasonText(entry.reason), usage: {sent: false}});
                counts.skipped++;
            }
        }
        let done = 0;
        for (const entry of queue) {
            if (this.run?.stop) {
                outcomes.set(entry.findingUid, {state: 'queued', itemUid: entry.itemUid});
                counts.newPending++;
                continue;
            }
            const outcome = await this.generateWithRetry(entry.itemUid, onProgress);
            outcomes.set(entry.findingUid, outcome);
            if (outcome.state === 'ready') {
                counts.ready++;
                counts.newPending++;
            } else if (outcome.state === 'queued') {
                counts.skipped++;
                counts.newPending++;
            } else {
                counts.none++;
            }
            done++;
            onProgress(done, queue.length);
        }

        return {outcomes, counts};
    }

    async generateWithRetry(itemUid, onProgress, {regenerate = false} = {}) {
        let usage;
        for (let attempt = 0; attempt < 6; attempt++) {
            const current = await postRemediation(this.urls.item, {item: itemUid, detailed: false});
            if (!current.ok) {
                return {state: 'failed', itemUid, retryable: false, message: this.errorText(current.code), usage};
            }
            const item = current.data.item;
            const mayRegenerate = regenerate && attempt === 0 && ['failed', 'no_suggestion'].includes(item.status);
            if (item.status !== 'preparing' && !mayRegenerate) {
                return this.outcomeOf(item, usage);
            }
            const response = await postRemediation(this.urls.generate, {item: itemUid, rowVersion: item.rowVersion, detailed: false});
            if (response.data?.aiUsage?.sent) {
                usage = response.data.aiUsage;
            } else if (response.data?.aiUsage && usage === undefined) {
                usage = response.data.aiUsage;
            }
            if (!response.ok) {
                if (response.code === 'in_progress') {
                    await this.wait(2, onProgress);
                    continue;
                }
                return {state: 'failed', itemUid, item, message: this.errorText(response.code, response.data), usage};
            }
            if (response.data.retryAfter) {
                if (this.run?.stop) {
                    return {state: 'queued', itemUid, item: response.data.item, usage};
                }
                await this.wait(Number(response.data.retryAfter), onProgress);
                continue;
            }
            return this.outcomeOf(response.data.item, usage);
        }

        return {state: 'queued', itemUid, usage};
    }

    outcomeOf(item, usage) {
        const base = {itemUid: item.uid, item, usage};
        if (item.status === 'pending_review') {
            return {...base, state: 'ready', warning: item.statusReasonLabel || ''};
        }
        if (item.status === 'preparing') {
            return {...base, state: 'queued'};
        }
        const message = item.statusReasonLabel || item.statusLabel || '';
        if (item.status === 'no_suggestion') {
            return {...base, state: 'none', message};
        }
        if (item.status === 'failed') {
            return {...base, state: 'failed', message};
        }
        // Not applicable (finding closed, record changed, duplicate) and every other end state: nothing to review here.
        return {...base, state: 'unavailable', message};
    }

    wait(seconds, onProgress) {
        const until = Date.now() + Math.max(1, seconds) * 1000;
        return new Promise((resolve) => {
            const tick = () => {
                const remaining = Math.ceil((until - Date.now()) / 1000);
                if (remaining <= 0 || this.run?.stop) {
                    resolve();
                    return;
                }
                onProgress(null, null, remaining);
                window.setTimeout(tick, 1000);
            };
            tick();
        });
    }

    confirmBulk(invoker) {
        const uids = this.selectedEligible().slice(0, Number(this.config.maxBatch ?? 50));
        if (uids.length === 0) {
            this.announce(this.l('pd.bulkNone', 'None of the selected issues has an AI fix.'));
            return;
        }
        if (uids.length <= 10) {
            this.runBulk(uids, invoker);
            return;
        }
        // More than the 10-per-minute rate limit: say what is sent and how long it takes before anything starts.
        const dialog = this.planDialog;
        dialog.replaceChildren(
            el('div', {class: 'aqg-remediation__dialog-head'}, el('h2', {id: 'aqg-rem-plan-h', class: 'aqg-remediation__drawer-title', tabindex: '-1', text: format(this.l('pd.bulkPrepare', 'Prepare AI suggestions (%d)'), uids.length)})),
            el('div', {class: 'aqg-remediation__dialog-body'}, el('p', {text: format(this.l('pd.bulkPrepareConfirm', 'Prepare %1$d AI suggestions?'), uids.length)})),
            el('div', {class: 'aqg-remediation__dialog-foot'},
                el('button', {type: 'button', class: 'btn btn-default btn-sm', text: this.l('plan.close', 'Cancel'), onclick: () => dialog.close()}),
                el('button', {type: 'button', class: 'btn btn-primary btn-sm', text: format(this.l('pd.bulkPrepare', 'Prepare AI suggestions (%d)'), uids.length), onclick: () => {
                    dialog.close();
                    this.runBulk(uids, invoker);
                }}),
            ),
        );
        dialog.showModal();
        dialog.querySelector('#aqg-rem-plan-h')?.focus();
        dialog.addEventListener('close', () => invoker?.focus(), {once: true});
    }

    async runBulk(uids, invoker) {
        if (this.run) {
            return;
        }
        this.run = {stop: false};
        invoker.disabled = true;
        const stop = el('button', {type: 'button', class: 'btn btn-default btn-sm', text: this.l('pd.bulkCancel', 'Stop preparing'), onclick: () => {
            if (this.run) {
                this.run.stop = true;
            }
        }});
        invoker.after(stop);
        const progressText = (done, total, waiting) => {
            this.live.textContent = waiting
                ? format(this.l('pd.bulkWaiting', 'Waiting %d s for the AI rate limit…'), waiting)
                : format(this.l('pd.bulkProgress', 'Prepared %1$d of %2$d…'), done ?? 0, total ?? uids.length);
        };
        progressText(0, uids.length);
        const {outcomes, counts} = await this.prepareAndGenerate(uids, {onProgress: progressText});
        stop.remove();
        invoker.disabled = false;
        this.run = null;
        const summary = format(counts.ready === 1
            ? this.l('pd.bulkDoneOne', '%1$d suggestion ready, %2$d without a suggestion, %3$d skipped.')
            : this.l('pd.bulkDone', '%1$d suggestions ready, %2$d without a suggestion, %3$d skipped.'), counts.ready, counts.none, counts.skipped);
        this.live.replaceChildren(document.createTextNode(summary + ' '), el('a', {href: this.config.queueUrl, text: this.l('pd.review', 'Review in AI fixes')}));
        for (const [uid, outcome] of outcomes) {
            if (outcome.itemUid > 0 || outcome.item) {
                this.markQueued(uid, outcome.item ?? null);
            }
        }
        this.addPending(counts.newPending);
        this.updateBulkButton();
    }

    async setOwner(button) {
        const owner = button.getAttribute('data-aqg-owner-set');
        const ruleId = button.getAttribute('data-rule-id');
        const response = await postRemediation(this.urls.owner, {pageUid: this.config.pageUid, ruleId, owner});
        const menu = button.closest('details');
        menu?.removeAttribute('open');
        if (!response.ok) {
            this.announce(this.errorText(response.code));
            return;
        }
        const label = this.l(`owner.${owner}`, owner);
        // Owner is per rule and page: the rule card shows it once.
        this.page.querySelectorAll(`[data-aqg-rule-owner][data-rule-id="${CSS.escape(ruleId)}"]`).forEach((line) => {
            const chip = line.querySelector('[data-aqg-owner-current], .aqg-remediation__owner');
            if (chip) {
                chip.className = `aqg-remediation__owner aqg-remediation__owner--${owner}`;
                chip.textContent = label;
            }
            line.querySelectorAll('[data-aqg-owner-set]').forEach((option) => option.setAttribute('aria-pressed', option.getAttribute('data-aqg-owner-set') === owner ? 'true' : 'false'));
        });
        menu?.querySelector('summary')?.focus();
        this.announce(this.l('owner.saved', 'Owner changed for this issue type on this page.'));
    }

    async copyGuidance(button) {
        const text = button.closest('details')?.querySelector('[data-aqg-dev-guidance]')?.textContent ?? '';
        const title = button.closest('.aqg-rulecard')?.querySelector('.aqg-rulecard__title')?.textContent ?? '';
        try {
            await navigator.clipboard.writeText([title, text].filter(Boolean).join('\n'));
            this.announce(this.l('pd.copied', 'Guidance copied.'), this.statusSlot(button.getAttribute('data-aqg-copy-guidance')));
        } catch {
            this.announce(this.errorText('generic'));
        }
    }

    /** "%d occurrence" / "%d occurrences". */
    occurrences(count) {
        return format(count === 1 ? this.l('plan.occurrence', '%d occurrence') : this.l('plan.occurrences', '%d occurrences'), count);
    }

    /** The rule card of a plan step on this list page, or null when the step's rule is not shown here. */
    ruleCard(ruleId) {
        return [...this.page.querySelectorAll('.aqg-rulecard[data-rule-id]')].find((card) => card.getAttribute('data-rule-id') === String(ruleId ?? '')) ?? null;
    }

    /**
     * Occurrences of the plan's editor steps on this list page that can still be prepared — the same bulk prepare
     * route and checks as "Prepare AI suggestions" for a selection.
     */
    editorStepOccurrences(plan) {
        const rules = new Set();
        for (const group of plan.groups ?? []) {
            if (group.owner !== 'editor' && group.owner !== 'mixed') {
                continue;
            }
            for (const step of group.steps ?? []) {
                if (step.fixPath === 'ai_image' || step.fixPath === 'ai_text') {
                    rules.add(String(step.ruleId));
                }
            }
        }
        return [...this.page.querySelectorAll('[data-aqg-remediation-occ][data-aqg-remediation-eligible="1"]')]
            .filter((line) => rules.has(line.getAttribute('data-rule-id') ?? ''))
            .map((line) => Number(line.getAttribute('data-aqg-remediation-occ') ?? 0))
            .filter((uid) => uid > 0)
            .slice(0, Number(this.config.maxBatch ?? 50));
    }

    goToRule(dialog, card) {
        dialog.close();
        card.scrollIntoView({block: 'start'});
        const toggle = card.querySelector('[data-aqg-rule-toggle]');
        if (toggle?.getAttribute('aria-expanded') === 'false') {
            toggle.click();
        }
        toggle?.focus({preventScroll: true});
    }

    /**
     * Atlas 07·D: the page plan as a side drawer — steps grouped by owner, each with its fix path, a "Go to issue" link
     * to the rule on this page, and "Copy as ticket". Built from stored findings by the server, without AI.
     */
    async openPlan(invoker) {
        const dialog = this.planDialog;
        if (!dialog) {
            return;
        }
        const response = await postRemediation(this.urls.plan, {pageUid: this.config.pageUid, languageUid: this.languageUid});
        if (!response.ok) {
            this.announce(this.errorText(response.code));
            return;
        }
        const plan = response.data;
        let number = 1;
        const body = plan.total === 0
            ? el('p', {text: this.l('plan.empty', 'No open issues on this page.')})
            : el('div', {class: 'aqg-remediation-pageplan'}, ...plan.groups.map((group) => el('section', {class: 'aqg-remediation-plan__group', 'data-aqg-plan-owner': group.owner},
                el('h3', {class: 'aqg-remediation-plan__grouphead'},
                    el('span', {class: `aqg-remediation__owner aqg-remediation__owner--${group.owner}`, text: group.ownerLabel}),
                    el('span', {class: 'aqg-remediation-plan__groupcount', text: format(group.steps.length === 1 ? this.l('plan.step', '%d step') : this.l('plan.steps', '%d steps'), group.steps.length)}),
                ),
                el('ol', {class: 'aqg-remediation-plan__steps', start: String(number)}, ...group.steps.map((step) => {
                    number++;
                    const card = this.ruleCard(step.ruleId);
                    return el('li', {class: 'aqg-remediation-plan__step'},
                        el('div', {class: 'aqg-remediation-plan__stephead'},
                            el('strong', {class: 'aqg-remediation-plan__title', text: step.title}),
                            card ? el('a', {class: 'aqg-remediation-plan__goto', href: `#${card.id}`, 'data-aqg-plan-goto': step.ruleId, text: this.l('plan.goTo', 'Go to issue'), onclick: (event) => {
                                event.preventDefault();
                                this.goToRule(dialog, card);
                            }}) : null,
                        ),
                        el('p', {class: 'aqg-remediation-plan__meta', text: this.occurrences(Number(step.occurrences ?? 0))}),
                        el('p', {class: 'aqg-remediation-plan__chips'},
                            el('span', {class: `aqg-remediation__badge aqg-remediation-plan__path aqg-remediation-plan__path--${step.fixPath}`, text: step.fixPathLabel}),
                            step.queued > 0 ? el('span', {class: 'aqg-remediation__badge', text: format(this.l('plan.queued', '%d in the queue'), step.queued)}) : null,
                        ),
                        step.howToFix ? el('p', {class: 'aqg-remediation__note', text: step.howToFix}) : null,
                    );
                })),
            )));
        const copied = el('p', {class: 'aqg-remediation__note', role: 'status', 'aria-live': 'polite'});
        const editorUids = plan.total > 0 && this.config.canBulk ? this.editorStepOccurrences(plan) : [];
        dialog.classList.add('aqg-remediation__dialog--drawer');
        dialog.replaceChildren(
            el('div', {class: 'aqg-remediation__dialog-head'},
                el('h2', {id: 'aqg-rem-plan-h', class: 'aqg-remediation__drawer-title', tabindex: '-1', text: this.l('plan.title', 'Remediation plan')}),
                el('button', {type: 'button', class: 'btn btn-default btn-sm aqg-remediation-plan__close', 'aria-label': this.l('plan.close', 'Close plan'), title: this.l('plan.close', 'Close plan'), onclick: () => dialog.close()},
                    el('span', {'aria-hidden': 'true', text: '×'})),
            ),
            el('div', {class: 'aqg-remediation__dialog-body'}, el('p', {class: 'aqg-remediation__muted', text: this.l('plan.intro', '')}), body, copied),
            el('div', {class: 'aqg-remediation__dialog-foot'},
                editorUids.length > 0 ? el('button', {type: 'button', class: 'btn btn-primary btn-sm', 'data-aqg-plan-send-editor': 'true', text: format(this.l('plan.sendEditor', 'Send editor steps to queue (%d)'), editorUids.length), onclick: () => {
                    dialog.close();
                    this.runBulk(editorUids, invoker);
                }}) : null,
                plan.total > 0 ? el('button', {type: 'button', class: 'btn btn-default btn-sm', 'data-aqg-plan-copy': 'true', text: this.l('plan.copyTicket', 'Copy as ticket'), onclick: async () => {
                    try {
                        await navigator.clipboard.writeText(plan.text);
                        copied.textContent = this.l('plan.copied', 'Plan copied.');
                    } catch {
                        copied.textContent = this.errorText('generic');
                    }
                }}) : null,
            ),
        );
        dialog.showModal();
        dialog.querySelector('#aqg-rem-plan-h')?.focus();
        dialog.addEventListener('close', () => {
            dialog.classList.remove('aqg-remediation__dialog--drawer');
            // "Go to issue" moved focus to the rule; otherwise focus returns to the plan button.
            if (document.activeElement === document.body || dialog.contains(document.activeElement)) {
                invoker?.focus();
            }
        }, {once: true});
    }
}

function boot() {
    document.querySelectorAll('[data-aqg-remediation-page]').forEach((root) => {
        if (!root.dataset.aqgBooted) {
            root.dataset.aqgBooted = '1';
            new PageDetailRemediation(root).init();
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
