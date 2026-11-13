import {postEditorAi} from '@priebera/a11y-quality-gate/editor-ai/editor-ai-client.js';

/**
 * The AI part of one finding inside AQG's existing finding popover (CKEditor) or line panel (plain HTML): Explain,
 * Fix with AI, the reviewed suggestion and its outcome. Shared by both editors; the editor-specific work (exact
 * target, preview, apply, undo) is done by the host's adapter.
 *
 * Model output is untrusted text: it is only ever inserted with textContent semantics (escaped), never as markup,
 * and it is applied only by the editor's explicit Apply.
 */

const VALUE_LIMITS = {link_text: 120, image_alt: 120, button_label: 80, iframe_title: 150, heading_text: 150};

export const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

/**
 * The model's display name ("Claude Haiku 5.5", as AI settings show it), never only the technical id: the server
 * sends `modelLabel` with every suggestion; the configured model's label covers older answers and staged changes.
 */
export const modelDisplayName = (data, config = {}) => {
    const model = String(data?.model ?? '');
    if (data?.modelLabel) {
        return String(data.modelLabel);
    }
    if (model !== '' && model !== String(config.model ?? '')) {
        return model;
    }
    return String(config.modelLabel || config.model || model);
};

/** "Claude Haiku 5.5 · Anthropic Claude": model and provider of an answer (or of the configuration). */
export const provenanceLine = (data, config = {}) => [modelDisplayName(data, config), data?.providerLabel || config.providerLabel || '']
    .filter(Boolean)
    .join(' · ');

export const SENT_LABELS = {
    linkText: ['aiSentLinkText', 'Link text'],
    linkTarget: ['aiSentLinkTarget', 'Link target (without query string)'],
    linkTargetTitle: ['aiSentLinkTargetTitle', 'Title of the linked page'],
    surroundingText: ['aiSentSurroundingText', 'The surrounding text'],
    pageTitle: ['aiSentPageTitle', 'Page title'],
    image: ['aiSentImage', 'The image'],
    currentAlt: ['aiSentCurrentAlt', 'Current alternative text'],
    caption: ['aiSentCaption', 'Image caption'],
    buttonMarkup: ['aiSentButtonMarkup', "The button's type, classes and icon hints"],
    formContext: ['aiSentFormContext', 'Form address and field names'],
    iframeSource: ['aiSentIframeSource', 'Embed address (host and path)'],
    headingLevel: ['aiSentHeadingLevel', 'Heading level'],
    previousHeading: ['aiSentPreviousHeading', 'Previous heading'],
    followingText: ['aiSentFollowingText', 'Text after the heading'],
    elementSummary: ['aiSentElementSummary', 'The marked element'],
    selectedText: ['aiSentSelectedText', 'The selected text'],
    otherFields: ['aiNotSentOtherFields', 'other fields'],
    userNames: ['aiNotSentUserNames', 'user names'],
    drafts: ['aiNotSentDrafts', 'other drafts'],
};

const FIELD_LABELS = {
    link_text: ['aiFieldLinkText', 'New link text'],
    image_alt: ['aiFieldAlt', 'Alternative text'],
    button_label: ['aiFieldButtonLabel', 'Value for aria-label'],
    iframe_title: ['aiFieldIframeTitle', 'Value for title'],
    heading_text: ['aiFieldHeadingText', 'Heading text'],
};

const ERROR_COPY = {
    not_configured: ['aiErrorNotConfigured', 'AI suggestions are not set up for this site.', 'aiErrorNotConfiguredHelp', 'Your administrator can enable them.'],
    paused: ['aiErrorPaused', 'AI is paused', 'aiErrorPausedHelp', 'The selected AI model must be confirmed again by an administrator.'],
    budget_reached: ['aiErrorBudgetReached', 'Monthly budget reached', 'aiErrorBudgetReachedHelp', 'This site has used its AI budget for this month. Available again on the 1st of next month, or your administrator can raise the budget.'],
    no_permission: ['aiErrorNoPermission', 'AI fixes are not enabled for your user group.', '', ''],
    no_licence: ['aiErrorNoLicence', 'AI fixes are part of AQG PRO and Agency.', '', ''],
    rate_limited: ['aiErrorRateLimited', 'Too many requests', 'aiErrorRateLimitedHelp', 'You can request another suggestion in %d seconds.'],
    provider_rate_limited: ['aiErrorProviderBusy', 'The AI provider is busy', 'aiErrorTryLater', 'Try again in a few minutes. Nothing in the text was changed.'],
    provider_timeout: ['aiErrorTimeout', 'No answer from the AI provider', 'aiErrorTimeoutHelp', 'The request stopped. Nothing in the text was changed.'],
    provider_unavailable: ['aiErrorProviderUnavailable', 'The AI provider is unavailable right now', 'aiErrorTryLater', 'Try again in a few minutes. Nothing in the text was changed.'],
    provider_credit: ['aiErrorCredit', 'AI is unavailable right now', 'aiErrorCreditHelp', 'The provider account for this site reports a billing problem. Please tell your administrator.'],
    network: ['aiErrorNetwork', 'The request did not reach TYPO3', 'aiErrorTryLater', 'Try again in a few minutes. Nothing in the text was changed.'],
    session_expired: ['aiErrorSession', 'Your TYPO3 session has expired', 'aiErrorSessionHelp', 'Your text is still in the editor. Log in again in the TYPO3 login window, then try again.'],
    permission_denied: ['aiErrorPermission', 'You may not change this field', '', ''],
    privacy_images: ['aiPrivacyImages', 'Images are not sent', 'aiPrivacyImagesHelp', 'This site does not allow sending images to AI providers. Please write the alternative text yourself.'],
    privacy_page_title: ['aiPrivacyPageTitle', 'Page titles are not sent', 'aiPrivacyPageTitleHelp', 'This site does not allow sending page titles to AI providers.'],
    link_text_disabled: ['aiLinkTextDisabled', 'AI link and iframe title suggestions are turned off for this site.', '', ''],
    image_unavailable: ['aiImageUnavailable', 'The image could not be read', 'aiImageUnavailableHelp', 'AQG reads only images stored in TYPO3. Write the alternative text yourself or check the file.'],
    stale: ['aiStale', 'The text changed', 'aiStaleHelp', 'The marked element was edited after this suggestion was made. Applying would overwrite your edit.'],
    manual_only: ['aiManualOnly', 'Manual fix only', 'aiManualOnlyHelp', 'AQG cannot change this element safely. The guidance above still applies.'],
    source_ambiguous: ['aiSourceAmbiguous', 'AI fixes are off for this field', 'aiSourceAmbiguousHelp', 'AQG cannot read the content unambiguously.'],
    html_too_large: ['aiHtmlTooLarge', 'The field is too large for AI fixes', '', ''],
    refused: ['aiRefused', 'No safe suggestion', 'aiRefusedHelp', 'The AI provider declined this request. Please fix it yourself.'],
    invalid_response: ['aiInvalid', 'The AI answer could not be used', 'aiInvalidHelp', 'AQG rejected the answer because it did not meet the rules for this field. Nothing was changed.'],
    unsupported_rule: ['aiUnsupported', 'No AI action for this finding', '', ''],
    record_not_found: ['aiErrorRecord', 'Save the record first', 'aiErrorRecordHelp', 'AI fixes need a saved record.'],
};

const TARGET_REASONS = {
    count_mismatch: ['aiTargetChanged', 'The content changed since the last check. Wait for the check to finish, then try again.'],
    signature_mismatch: ['aiTargetChanged', 'The content changed since the last check. Wait for the check to finish, then try again.'],
    not_provable: ['aiTargetNotProvable', 'AQG cannot identify this exact element in the editor, so it will not change it. Fix it by hand.'],
    nested_markup: ['aiTargetNested', 'The element contains formatting or other markup. AQG would remove it, so it offers no automatic change.'],
    mixed_formatting: ['aiTargetNested', 'The element contains formatting or other markup. AQG would remove it, so it offers no automatic change.'],
    template_placeholder: ['aiTargetTemplate', 'This element contains template placeholders. What visitors get is not in this source.'],
    too_long: ['aiTargetTooLong', 'The element is too long for an AI fix.'],
    source_unparseable: ['aiSourceUnparseable', 'AQG cannot read the source unambiguously (a tag, comment or quote is not closed). Fix the source; the guidance still applies.'],
    no_rule_based_fix: ['aiNoRuleFix', 'No rule-based level change is possible here.'],
    heading_level_unavailable: ['aiHeadingLevelUnavailable', 'This heading level is not available in this editor. Change the level by hand.'],
    apply_rejected: ['aiApplyRejected', 'AQG could not apply this change safely. Nothing was changed.'],
    unsupported_element: ['aiApplyRejected', 'AQG could not apply this change safely. Nothing was changed.'],
    not_element: ['aiApplyRejected', 'AQG could not apply this change safely. Nothing was changed.'],
    unsupported_operation: ['aiApplyRejected', 'AQG could not apply this change safely. Nothing was changed.'],
    no_target: ['aiTargetNotProvable', 'AQG cannot identify this exact element in the editor, so it will not change it. Fix it by hand.'],
    stale: ['aiStaleHelp', 'The marked element was edited after this suggestion was made. Applying would overwrite your edit.'],
};

export class EditorAiSession {
    /**
     * The quiet line under a finding when editor AI is not ready: [label key, English fallback], or null. Paused
     * names what the administrator can do (verify the model, confirm the privacy acknowledgement, choose a model with
     * a complete price for a hard-stop budget); budget_reached uses the screen 02 · E copy. The server state comes from the readiness policy.
     */
    static quietLine(config) {
        const admin = config?.isAdmin === true;
        const paused = {
            acknowledgement: admin
                ? ['aiPausedAckAdmin', 'Confirm the privacy acknowledgement for this provider in Accessibility › Settings › AI suggestions to use AI again.']
                : ['aiPausedAckEditor', 'AI is paused for this site. Your administrator can resume it by confirming the privacy acknowledgement in Accessibility › Settings › AI suggestions.'],
            budget_unenforceable: admin
                ? ['aiPausedBudgetAdmin', 'The selected model has no complete price, so the hard stop cannot be enforced. Choose a supported model in Accessibility › Settings › AI suggestions, or switch off the hard stop, to use AI again.']
                : ['aiPausedBudgetEditor', 'AI is paused for this site. Your administrator can resume it by choosing a supported model in Accessibility › Settings › AI suggestions.'],
        }[config?.pausedReason] ?? (admin
            ? ['aiErrorPausedAdmin', 'Test the connection in Accessibility › Settings › AI suggestions to use AI again.']
            : ['aiPausedEditor', 'AI is paused. The selected model must be confirmed again by an administrator.']);
        return {
            not_configured: admin
                ? ['aiNotConfiguredAdmin', 'No AI provider for this site. Set up OpenAI or Anthropic Claude in Accessibility › Settings › AI suggestions so editors get suggested fixes.']
                : ['aiNotConfiguredEditor', 'AI suggestions are not set up for this site. Your administrator can enable them.'],
            no_permission: ['aiErrorNoPermission', 'AI fixes are not enabled for your user group.'],
            paused,
            budget_reached: admin
                ? ['aiBudgetReachedAdmin', 'Monthly budget reached — AI is paused for editors. Raise the budget in Accessibility › Settings › AI suggestions.']
                : ['aiBudgetReachedEditor', 'Monthly budget reached. This site has used its AI budget for this month. Available again on the 1st of next month, or your administrator can raise the budget.'],
        }[config?.state] ?? null;
    }

    /**
     * @param {object} options
     * @param {object} options.issue the live finding (with aiTarget)
     * @param {object} options.adapter editor-specific operations
     * @param {object} options.config the editor AI configuration (state, provider, model, privacy)
     * @param {function(string, string): string} options.label translated labels
     * @param {function(string): void} options.announce polite live-region announcement
     */
    constructor({issue, adapter, config, label, announce, onApplied, onUndone}) {
        this.issue = issue;
        this.target = issue.aiTarget;
        this.adapter = adapter;
        this.config = config ?? {};
        this.label = label;
        this.announce = announce ?? (() => {});
        this.onApplied = onApplied ?? (() => {});
        this.onUndone = onUndone ?? (() => {});
        this.state = 'idle';
        this.versions = [];
        this.versionIndex = -1;
        this.explanation = null;
        this.errorCode = '';
        this.errorData = {};
        this.reason = '';
        this.editedValue = '';
        this.container = null;
        this.abortController = null;
        this.locked = null;
        this.countdownTimer = null;
    }

    /**
     * The host hands over the finding again after every live check. When it now describes another element than the
     * locked one (an element of the same tag was added or removed before it), the shown suggestion belongs to the
     * locked element only and must not be applied from this finding's panel.
     */
    updateIssue(issue) {
        const before = this.lockedTarget;
        this.issue = issue;
        this.target = issue.aiTarget ?? this.target;
        if (!before || !this.target || sameTarget(before, this.target)) {
            return;
        }
        if (this.state === 'generating') {
            this.retargeted = true;
        } else if (['ready', 'undone'].includes(this.state)) {
            this.setState('stale');
        }
    }

    get kind() {
        return this.target?.kind ?? '';
    }

    get isRuleBased() {
        return this.target?.operation === 'heading_level';
    }

    get current() {
        return this.versions[this.versionIndex] ?? null;
    }

    /** Whether a change approved in the AI fixes queue is staged here (it needs no new AI request to be applied). */
    get hasStaged() {
        return this.versions.some((version) => Number(version.stagedItem ?? 0) > 0);
    }

    /**
     * A key that survives edits of the element (the live-check fingerprint contains the element markup, so it
     * changes when the element is edited): rule, tag and occurrence.
     */
    static keyFor(issue) {
        const target = issue?.aiTarget;
        return target ? `${issue.ruleId}|${target.tag}|${target.occurrence}` : `${issue?.ruleId}|${issue?.fingerprint}`;
    }

    /** Whether the session must stay visible although the finding changed or disappeared. */
    get isPinned() {
        return ['generating', 'explaining', 'stale', 'applied'].includes(this.state);
    }

    /** Whether a wand belongs on this finding's marker: an AI or rule-based action exists and can be used. */
    static isEligible(issue, config) {
        const target = issue?.aiTarget;
        return Boolean(target && config?.state === 'ready' && Array.isArray(config.rules) && config.rules.includes(target.ruleId));
    }

    /**
     * Whether a fix (AI or rule-based) can actually be offered: the wand and "Review fixes" count only these. Explain
     * stays available for every eligible finding.
     */
    static offersFix(issue, config) {
        if (!EditorAiSession.isEligible(issue, config)) {
            return false;
        }
        const target = issue.aiTarget;
        if (target.unavailableReason) {
            return false;
        }
        if (target.kind === 'image_alt' && config.allowImages === false) {
            return false;
        }
        if ((target.kind === 'link_text' || target.kind === 'iframe_title') && config.linkText === false) {
            return false;
        }
        return true;
    }

    mount(container) {
        this.container = container;
        container.classList.add('aqg-ai');
        container.addEventListener('click', (event) => this.onClick(event));
        container.addEventListener('input', (event) => this.onInput(event));
        this.render();
    }

    destroy() {
        this.abortController?.abort();
        window.clearInterval(this.countdownTimer ?? undefined);
        if (this.state !== 'applied') {
            this.adapter.release?.();
        }
        this.container = null;
    }

    l(key, fallback) {
        return this.label(key, fallback);
    }

    lh(key, fallback) {
        return escapeHtml(this.l(key, fallback));
    }

    onClick(event) {
        const button = event.target instanceof Element ? event.target.closest('[data-aqg-ai]') : null;
        if (!button || button.disabled) {
            return;
        }
        event.preventDefault();
        const action = button.getAttribute('data-aqg-ai');
        switch (action) {
            case 'fix':
            case 'regenerate':
                this.requestFix();
                break;
            case 'explain':
                this.requestExplain();
                break;
            case 'cancel':
                this.abortController?.abort();
                break;
            case 'apply':
                this.apply();
                break;
            case 'discard':
                this.discard();
                break;
            case 'undo':
                this.undo();
                break;
            case 'rule-fix':
                this.applyRuleBased();
                break;
            case 'previous':
                this.selectVersion(this.versionIndex - 1);
                break;
            case 'next':
                this.selectVersion(this.versionIndex + 1);
                break;
            default:
                break;
        }
    }

    onInput(event) {
        if (!(event.target instanceof HTMLInputElement || event.target instanceof HTMLTextAreaElement) || !event.target.matches('[data-aqg-ai-value]')) {
            return;
        }
        this.editedValue = event.target.value;
        this.updatePreview();
    }

    payload(extra = {}) {
        return {...this.adapter.requestPayload(this.issue), ...extra};
    }

    async requestExplain() {
        this.abortController?.abort();
        this.abortController = new AbortController();
        this.setState('explaining');
        this.announce(this.l('aiAnnouncePreparingExplanation', 'Preparing AI explanation.'));
        const response = await postEditorAi('explain', this.payload(), {signal: this.abortController.signal});
        if (response.code === 'cancelled') {
            this.setState(this.versions.length > 0 ? 'ready' : 'idle');
            return;
        }
        if (!response.ok) {
            return this.fail(response);
        }
        this.explanation = response.data;
        this.setState('explained');
        this.announce(this.l('aiAnnounceExplanationReady', 'AI explanation ready.'));
    }

    async requestFix() {
        if (this.isRuleBased) {
            return;
        }
        const lock = this.adapter.lock(this.issue);
        if (!lock.ok) {
            this.reason = lock.reason;
            this.setState('unavailable');
            return;
        }
        this.locked = lock;
        this.lockedTarget = this.issue.aiTarget;
        this.retargeted = false;
        this.abortController?.abort();
        this.abortController = new AbortController();
        this.setState('generating');
        this.announce(this.l('aiAnnouncePreparing', 'Preparing AI suggestion.'));

        const response = await postEditorAi('fix', this.payload(), {signal: this.abortController.signal});
        if (response.code === 'cancelled') {
            this.setState(this.versions.length > 0 ? 'ready' : 'idle');
            return;
        }
        if (!response.ok) {
            return this.fail(response);
        }
        const data = response.data;
        // The server derived the element itself: it must be the element this editor locked.
        if (data.target && !sameTarget(data.target, this.lockedTarget)) {
            this.reason = 'not_provable';
            this.setState('unavailable');
            return;
        }
        if (data.status !== 'suggestion') {
            this.reason = data.reason ?? '';
            this.errorData = data;
            this.setState(data.status === 'needs_review' ? 'needs_review' : 'invalid');
            this.announce(data.status === 'needs_review'
                ? this.l('aiAnnounceNeedsReview', 'No safe suggestion. Please fix it yourself.')
                : this.l('aiInvalid', 'The AI answer could not be used'));
            return;
        }
        this.versions.push(data);
        this.versionIndex = this.versions.length - 1;
        this.editedValue = data.value;
        this.setState(this.retargeted || this.adapter.isStale() ? 'stale' : 'ready');
        this.announce(this.versions.length > 1
            ? this.l('aiAnnounceVersion', 'New suggestion ready, version %1$d of %2$d.').replace('%1$d', String(this.versionIndex + 1)).replace('%2$d', String(this.versions.length))
            : this.l('aiAnnounceReady', 'Suggestion ready. Review it before applying.'));
        this.container?.querySelector('[data-aqg-ai-value]')?.focus();
    }

    /**
     * A change approved in AQG's AI fixes queue, staged for this finding: it becomes the session's suggestion exactly
     * like one generated here, so the editor still reviews it and applies it with Apply (one undo step) and stores it
     * with TYPO3 Save. The host has matched the queue's element (rule, tag, position, current value) before.
     */
    adoptStaged(staged) {
        if (this.isRuleBased || !staged || typeof staged.value !== 'string' || staged.value.trim() === '') {
            return false;
        }
        const lock = this.adapter.lock(this.issue);
        if (!lock.ok) {
            this.reason = lock.reason;
            this.setState('unavailable');
            return false;
        }
        this.locked = lock;
        this.lockedTarget = this.issue.aiTarget;
        this.versions.push({
            status: 'suggestion',
            value: staged.value,
            reason: staged.reason ?? '',
            provider: staged.provider ?? '',
            providerLabel: staged.providerLabel ?? '',
            model: staged.model ?? '',
            usage: staged.usage ?? {},
            sent: [],
            notSent: [],
            warnings: [],
            serverTime: Number(staged.serverTime ?? 0),
            stagedItem: Number(staged.itemUid ?? 0),
        });
        this.versionIndex = this.versions.length - 1;
        this.editedValue = staged.value;
        this.setState(this.adapter.isStale() ? 'stale' : 'ready');
        this.announce(this.l('aiStagedFromQueue', 'Staged from AI fixes: review the change and apply it to the text. It is saved only with TYPO3 Save.'));
        return true;
    }

    fail(response) {
        this.errorCode = response.code === 'manual_only' ? 'manual_only' : response.code;
        this.errorData = response.data ?? {};
        if (this.errorCode === 'rate_limited') {
            this.startCountdown(Number(this.errorData.retryAfter ?? 60));
        }
        this.setState('error');
        this.announce(this.l(ERROR_COPY[this.errorCode]?.[0] ?? 'aiErrorProviderUnavailable', ERROR_COPY[this.errorCode]?.[1] ?? 'The AI provider is unavailable right now'));
    }

    startCountdown(seconds) {
        window.clearInterval(this.countdownTimer ?? undefined);
        this.retryAt = Date.now() + Math.max(1, seconds) * 1000;
        this.countdownTimer = window.setInterval(() => {
            if (Date.now() >= this.retryAt) {
                window.clearInterval(this.countdownTimer);
                this.countdownTimer = null;
            }
            this.render();
        }, 1000);
    }

    selectVersion(index) {
        if (index < 0 || index >= this.versions.length) {
            return;
        }
        this.versionIndex = index;
        this.editedValue = this.versions[index].value;
        this.render();
    }

    valueProblem(value) {
        const trimmed = String(value ?? '').trim();
        if (trimmed === '') {
            return this.l('aiValueEmpty', 'Enter a value.');
        }
        if (trimmed.length > (VALUE_LIMITS[this.kind] ?? 120)) {
            return this.l('aiValueTooLong', 'At most %d characters.').replace('%d', String(VALUE_LIMITS[this.kind] ?? 120));
        }
        return '';
    }

    apply() {
        const value = String(this.editedValue ?? '').trim();
        if (this.valueProblem(value) !== '') {
            return;
        }
        if (this.adapter.isStale()) {
            this.setState('stale');
            this.announce(this.l('aiStale', 'The text changed'));
            return;
        }
        const preview = this.previewHtml();
        const result = this.adapter.apply(value);
        if (!result.ok) {
            this.reason = result.reason;
            this.setState(result.reason === 'stale' ? 'stale' : 'rejected');
            this.announce(this.l('aiApplyRejected', 'AQG could not apply this change safely. Nothing was changed.'));
            return;
        }
        this.appliedValue = value;
        // The applied panel keeps the Before/After of the change (Atlas 02 · A inserted): taken before the editor
        // changes, because afterwards the element already holds the new value.
        this.appliedPreview = preview;
        this.setState('applied');
        this.onApplied({issue: this.issue, value, expected: result.expected, suggestion: this.current});
        this.announce(this.adapter.type === 'html'
            ? this.l('aiAnnounceAppliedSource', 'Applied to the source. Not saved yet.')
            : this.l('aiAnnounceApplied', 'Applied to the text. Not saved yet.'));
        this.container?.querySelector('[data-aqg-ai="undo"]')?.focus();
    }

    applyRuleBased() {
        const lock = this.adapter.lock(this.issue);
        if (!lock.ok) {
            this.reason = lock.reason;
            this.setState('unavailable');
            return;
        }
        this.locked = lock;
        const result = this.adapter.apply('');
        if (!result.ok) {
            this.reason = result.reason;
            this.setState('rejected');
            return;
        }
        this.appliedPreview = null;
        this.setState('applied');
        this.onApplied({issue: this.issue, value: '', expected: result.expected, suggestion: null});
        this.announce(this.l('aiAnnounceApplied', 'Applied to the text. Not saved yet.'));
    }

    undo() {
        if (this.adapter.undo()) {
            this.onUndone({issue: this.issue});
            this.setState(this.isRuleBased ? 'idle' : 'undone');
            this.announce(this.l('aiAnnounceUndone', 'Change undone.'));
        }
    }

    discard() {
        this.abortController?.abort();
        this.adapter.release?.();
        this.locked = null;
        this.versions = [];
        this.versionIndex = -1;
        this.setState('discarded');
        this.announce(this.l('aiAnnounceDiscarded', 'Suggestion discarded. Nothing changed.'));
        this.container?.querySelector('[data-aqg-ai="fix"]')?.focus();
    }

    /** Called by the host after every content change: an edited target outdates the shown suggestion at once. */
    checkStale() {
        if (['ready', 'undone'].includes(this.state) && this.adapter.isStale()) {
            this.setState('stale');
            this.announce(this.l('aiStale', 'The text changed'));
        }
    }

    setState(state) {
        this.state = state;
        this.render();
    }

    updatePreview() {
        const preview = this.container?.querySelector('[data-aqg-ai-preview]');
        if (preview) {
            preview.innerHTML = this.previewHtml();
        }
        const problem = this.valueProblem(this.editedValue);
        const apply = this.container?.querySelector('[data-aqg-ai="apply"]');
        if (apply) {
            apply.disabled = problem !== '' || this.state === 'stale';
        }
        const hint = this.container?.querySelector('[data-aqg-ai-value-hint]');
        if (hint) {
            hint.textContent = problem !== '' ? problem : `${String(this.editedValue ?? '').trim().length} / ${VALUE_LIMITS[this.kind] ?? 120}`;
        }
    }

    render() {
        if (!this.container) {
            return;
        }
        this.container.dataset.state = this.state;
        this.container.innerHTML = this.html();
        if (this.state === 'ready' || this.state === 'undone' || this.state === 'stale') {
            this.updatePreview();
        }
        // The host re-measures its panel: a suggestion is taller than the two buttons it replaces.
        this.container.dispatchEvent(new CustomEvent('aqg-ai:rendered', {bubbles: true}));
    }

    html() {
        switch (this.state) {
            case 'explaining':
                return this.progressHtml(this.l('aiPreparingExplanation', 'Preparing an explanation…'));
            case 'generating':
                return this.progressHtml(this.l('aiPreparing', 'Preparing a suggestion…'), this.adapter.type === 'html'
                    ? this.l('aiLockedRange', 'The marked range is locked for this suggestion. You can keep typing elsewhere.')
                    : this.l('aiKeepTyping', 'Usually 2–5 seconds. You can keep typing.'));
            case 'explained':
                return this.explanationHtml() + this.actionsHtml();
            case 'ready':
            case 'undone':
            case 'stale':
                return this.suggestionHtml();
            case 'needs_review':
                return this.noticeHtml('review', this.l('aiNeedsReview', 'No safe suggestion'), this.reason || this.l('aiNeedsReviewHelp', 'The context does not allow a reliable suggestion. Please fix it yourself.'))
                    + this.sentHtml(this.errorData) + this.actionsHtml({retry: true});
            case 'invalid':
                return this.noticeHtml('error', this.l('aiInvalid', 'The AI answer could not be used'), this.l('aiInvalidHelp', 'AQG rejected the answer because it did not meet the rules for this field. Nothing was changed.'))
                    + this.actionsHtml({retry: true});
            case 'error':
                return this.errorHtml();
            case 'unavailable':
            case 'rejected':
                return this.noticeHtml(this.state === 'rejected' ? 'error' : 'review',
                    this.state === 'rejected' ? this.l('aiNotApplied', 'Not applied') : this.l('aiManualOnly', 'Manual fix only'),
                    this.reasonText(this.reason)) + this.actionsHtml({fix: false});
            case 'applied':
                return this.appliedHtml();
            case 'discarded':
                return this.noticeHtml('info', '', this.l('aiDiscarded', 'Suggestion discarded. Nothing in the text was changed.')) + this.actionsHtml();
            default:
                return this.actionsHtml();
        }
    }

    reasonText(reason) {
        const copy = TARGET_REASONS[reason];
        return copy ? this.l(copy[0], copy[1]) : this.l('aiApplyRejected', 'AQG could not apply this change safely. Nothing was changed.');
    }

    unavailableReason() {
        if (this.target?.unavailableReason) {
            return this.target.unavailableReason;
        }
        if (this.kind === 'image_alt' && this.config.allowImages === false) {
            return 'privacy_images';
        }
        if ((this.kind === 'link_text' || this.kind === 'iframe_title') && this.config.linkText === false) {
            return 'link_text_disabled';
        }
        if (this.isRuleBased && !this.adapter.canRuleBasedFix?.()) {
            // "Not available in this editor" is about the CKEditor heading list; an editor without rule-based fixes says so.
            return this.target?.targetHeadingLevel && this.adapter.ruleBasedFixSupported !== false ? 'heading_level_unavailable' : 'no_rule_based_fix';
        }
        return '';
    }

    actionsHtml({retry = false, fix = true} = {}) {
        const reason = this.unavailableReason();
        const privacy = ERROR_COPY[reason];
        const notice = reason !== ''
            ? (privacy
                ? this.noticeHtml('review', this.l(privacy[0], privacy[1]), privacy[2] ? this.l(privacy[2], privacy[3]) : '')
                : this.noticeHtml('review', this.l('aiManualOnly', 'Manual fix only'), this.reasonText(reason)))
            : '';
        const fixButton = this.isRuleBased
            ? (reason === '' ? `<button type="button" class="aqg-ai__btn aqg-ai__btn--primary" data-aqg-ai="rule-fix">${this.lh('aiChangeHeadingLevel', 'Change to Heading %d').replace('%d', String(this.target.targetHeadingLevel))}</button><span class="aqg-ai__noai">${this.lh('aiNoAi', 'No AI')}</span>` : '')
            : (reason === '' && fix ? `<button type="button" class="aqg-ai__btn aqg-ai__btn--primary" data-aqg-ai="${retry ? 'regenerate' : 'fix'}"><span class="aqg-ai__wand" aria-hidden="true"></span>${retry ? this.lh('aiTryAgain', 'Try again') : this.lh('aiFix', 'Fix with AI')}</button>` : '');
        const explain = this.state === 'explained' ? '' : `<button type="button" class="aqg-ai__btn" data-aqg-ai="explain">${this.lh('aiExplain', 'Explain')}</button>`;
        const ruleNote = this.isRuleBased && reason === '' ? `<p class="aqg-ai__note">${this.lh('aiRuleBasedNote', 'A rule-based fix without an AI request.')}</p>` : '';

        return `${notice}<div class="aqg-ai__actions">${fixButton}${explain}</div>${ruleNote}`;
    }

    progressHtml(title, help = '') {
        return `
            <div class="aqg-ai__progress" role="status">
                <span class="aqg-ai__spin" aria-hidden="true"></span>
                <span class="aqg-ai__progress-title">${escapeHtml(title)}</span>
                ${this.modelLineHtml()}
                ${help !== '' ? `<p class="aqg-ai__help">${escapeHtml(help)}</p>` : ''}
                <button type="button" class="aqg-ai__btn aqg-ai__btn--ghost" data-aqg-ai="cancel">${this.lh('aiCancel', 'Cancel')}</button>
            </div>`;
    }

    modelLineHtml(data = null) {
        const line = provenanceLine(data, this.config);
        return line === '' ? '' : `<span class="aqg-ai__model">${escapeHtml(line)}</span>`;
    }

    explanationHtml() {
        const data = this.explanation ?? {};
        return `
            <div class="aqg-ai__block aqg-ai__block--explain">
                <div class="aqg-ai__block-head"><strong>${this.lh('aiExplanation', 'Explanation')}</strong>${this.modelLineHtml(data)}</div>
                <p class="aqg-ai__text">${escapeHtml(data.explanation ?? '')}</p>
                ${data.howToFix ? `<p class="aqg-ai__text"><strong>${this.lh('howToFix', 'How to fix')}:</strong> ${escapeHtml(data.howToFix)}</p>` : ''}
                <p class="aqg-ai__note">${this.lh('aiExplanationNote', 'AI explanation for this text. It can be incomplete; the rule description stays authoritative.')}</p>
                ${this.sentHtml(data)}
            </div>`;
    }

    suggestionHtml() {
        const data = this.current ?? {};
        const kindField = FIELD_LABELS[this.kind] ?? ['aiFieldValue', 'Value'];
        const id = `aqg-ai-value-${Math.random().toString(36).slice(2, 9)}`;
        const versions = this.versions.length > 1
            ? `<span class="aqg-ai__versions">
                    <button type="button" class="aqg-ai__icon-btn" data-aqg-ai="previous" aria-label="${this.lh('aiPreviousVersion', 'Previous version')}" ${this.versionIndex === 0 ? 'disabled' : ''}>‹</button>
                    ${this.lh('aiVersion', 'Version %1$d of %2$d').replace('%1$d', String(this.versionIndex + 1)).replace('%2$d', String(this.versions.length))}
                    <button type="button" class="aqg-ai__icon-btn" data-aqg-ai="next" aria-label="${this.lh('aiNextVersion', 'Next version')}" ${this.versionIndex === this.versions.length - 1 ? 'disabled' : ''}>›</button>
               </span>`
            : '';
        const warnings = (data.warnings ?? []).includes('still_reported')
            ? this.noticeHtml('review', this.l('aiStillReported', 'AQG would still report this'), this.l('aiStillReportedHelp', 'The check of the suggested value still finds the issue. Edit the value before applying.'))
            : '';
        const stale = this.state === 'stale'
            ? this.noticeHtml('error', this.l('aiStale', 'The text changed'), this.l('aiStaleHelp', 'The marked element was edited after this suggestion was made. Applying would overwrite your edit.'))
            : '';
        const undone = this.state === 'undone'
            ? this.noticeHtml('info', '', this.adapter.type === 'html'
                ? this.l('aiUndoneSource', 'Undone. The source is back to how it was; the suggestion stays here.')
                : this.l('aiUndone', 'Change undone. The text is back to how it was; the suggestion stays here.'))
            : '';
        const multiline = this.kind === 'image_alt' || this.kind === 'iframe_title';
        const input = multiline
            ? `<textarea id="${id}" class="aqg-ai__input" rows="2" data-aqg-ai-value maxlength="${(VALUE_LIMITS[this.kind] ?? 120) * 2}">${escapeHtml(this.editedValue)}</textarea>`
            : `<input id="${id}" type="text" class="aqg-ai__input" data-aqg-ai-value value="${escapeHtml(this.editedValue)}" maxlength="${(VALUE_LIMITS[this.kind] ?? 120) * 2}">`;

        const staged = data.stagedItem
            ? this.noticeHtml('info', '', this.l('aiStagedFromQueue', 'Staged from AI fixes: review the change and apply it to the text. It is saved only with TYPO3 Save.'))
            : '';

        return `
            ${undone}${stale}${staged}
            <div class="aqg-ai__block aqg-ai__block--suggestion">
                <div class="aqg-ai__block-head"><strong>${this.lh('aiSuggestion', 'Suggestion')}</strong>${versions}${this.modelLineHtml(data)}</div>
                <p class="aqg-ai__target">${escapeHtml(this.adapter.describeTarget(this.issue))}</p>
                <div class="aqg-ai__preview" data-aqg-ai-preview></div>
                <label class="aqg-ai__label" for="${id}">${this.lh(kindField[0], kindField[1])}</label>
                ${input}
                <p class="aqg-ai__hint" data-aqg-ai-value-hint></p>
                ${this.adapter.type === 'html' ? `<p class="aqg-ai__note">${this.lh('aiValueOnlyNote', 'Only the value is editable. AQG escapes quotes and angle brackets.')}</p>` : ''}
                ${data.reason ? `<p class="aqg-ai__why"><strong>${this.lh('aiWhy', 'Why')}:</strong> ${escapeHtml(data.reason)}</p>` : ''}
                ${warnings}
                <p class="aqg-ai__note">${this.lh('aiNothingChanges', 'Nothing changes until you apply it.')}</p>
                <div class="aqg-ai__actions">
                    <button type="button" class="aqg-ai__btn aqg-ai__btn--primary" data-aqg-ai="apply">${this.adapter.type === 'html' ? this.lh('aiApplySource', 'Apply to source') : this.lh('aiApplyText', 'Apply to text')}</button>
                    <button type="button" class="aqg-ai__btn" data-aqg-ai="discard">${this.lh('aiDiscard', 'Discard')}</button>
                    <button type="button" class="aqg-ai__btn aqg-ai__btn--ghost" data-aqg-ai="regenerate">${this.lh('aiRegenerate', 'Regenerate')}</button>
                </div>
                ${this.sentHtml(data)}
            </div>`;
    }

    previewHtml() {
        const value = String(this.editedValue ?? '').trim();
        const preview = this.adapter.preview(value);
        if (preview.kind === 'source') {
            if (!preview.ok) {
                return this.noticeHtml('error', this.l('aiRangeCheckFailed', 'Not offered'), this.reasonText(preview.reason || 'apply_rejected'));
            }
            const diff = preview.diff;
            return `
                <div class="aqg-ai__srcdiff" role="group" aria-label="${this.lh('aiSourceDiff', 'Source change')}">
                    <div class="aqg-ai__srcdiff-line aqg-ai__srcdiff-line--del"><span class="aqg-ai__srcdiff-num">${diff.line}</span><span class="aqg-ai__srcdiff-sign">-</span><code>${escapeHtml(diff.head)}<del>${escapeHtml(diff.removed)}</del>${escapeHtml(diff.tail)}</code></div>
                    <div class="aqg-ai__srcdiff-line aqg-ai__srcdiff-line--ins"><span class="aqg-ai__srcdiff-num">${diff.line}</span><span class="aqg-ai__srcdiff-sign">+</span><code>${escapeHtml(diff.head)}<mark>${escapeHtml(diff.inserted)}</mark>${escapeHtml(diff.tail)}</code></div>
                </div>
                <ul class="aqg-ai__checks">
                    ${preview.checks.map((check) => `<li class="aqg-ai__check aqg-ai__check--${check.passed ? 'pass' : 'fail'}">${this.lh(`aiCheck_${check.id}`, CHECK_COPY[check.id] ?? check.id)}</li>`).join('')}
                </ul>`;
        }
        return `
            <dl class="aqg-ai__diff">
                <div class="aqg-ai__diff-row aqg-ai__diff-row--before"><dt>${this.lh('aiBefore', 'Before')}</dt><dd>${preview.before !== '' ? escapeHtml(preview.before) : `<em>${this.lh('aiEmpty', '(empty)')}</em>`}</dd></div>
                <div class="aqg-ai__diff-row aqg-ai__diff-row--after"><dt>${this.lh('aiAfter', 'After')}</dt><dd>${value !== '' ? escapeHtml(preview.after) : `<em>${this.lh('aiEmpty', '(empty)')}</em>`}</dd></div>
            </dl>`;
    }

    sentHtml(data) {
        const sent = Array.isArray(data?.sent) ? data.sent : [];
        if (sent.length === 0) {
            return '';
        }
        return sentDetailsHtml(data, this.config, (key, fallback) => this.l(key, fallback));
    }

    errorHtml() {
        const copy = ERROR_COPY[this.errorCode] ?? ERROR_COPY.provider_unavailable;
        let help = copy[2] ? this.l(copy[2], copy[3]) : '';
        let retry = !['no_permission', 'no_licence', 'not_configured', 'permission_denied', 'privacy_images', 'privacy_page_title', 'link_text_disabled', 'manual_only', 'unsupported_rule', 'html_too_large', 'record_not_found', 'source_ambiguous'].includes(this.errorCode);
        if (this.errorCode === 'rate_limited') {
            const remaining = Math.max(0, Math.ceil(((this.retryAt ?? 0) - Date.now()) / 1000));
            help = help.replace('%d', String(remaining));
            retry = remaining === 0;
        }
        if (this.errorCode === 'manual_only') {
            help = this.reasonText(String(this.errorData?.manualReason ?? ''));
        }
        if (this.errorCode === 'paused' && this.config.isAdmin) {
            help = this.l('aiErrorPausedAdmin', 'Test the connection in Accessibility › Settings › AI suggestions to use AI again.');
        }
        if (this.errorCode === 'provider_credit' && this.config.isAdmin) {
            help = this.l('aiErrorCreditAdmin', 'The provider reports a billing or quota problem. Check the account in the provider console.');
        }
        return this.noticeHtml('error', this.l(copy[0], copy[1]), help) + (retry ? this.actionsHtml({retry: true}) : '');
    }

    appliedHtml() {
        const source = this.adapter.type === 'html';
        return `
            ${this.ladderHtml('inserted')}
            <div class="aqg-ai__applied" role="status">
                <strong>${source ? this.lh('aiAppliedSource', 'Applied to the source — not saved yet') : this.lh('aiApplied', 'Applied to the text — not saved yet')}</strong>
                <p>${source ? this.lh('aiAppliedSourceHelp', 'Use TYPO3 Save to keep it. Ctrl+Z undoes it in one step.') : this.lh('aiAppliedHelp', 'Use TYPO3 Save to keep it. Ctrl+Z / ⌘Z undoes it.')}</p>
                <div class="aqg-ai__actions"><button type="button" class="aqg-ai__btn" data-aqg-ai="undo">${this.lh('aiUndo', 'Undo')}</button></div>
            </div>
            ${this.appliedPreview ? `<div class="aqg-ai__preview aqg-ai__preview--applied">${this.appliedPreview}</div>` : ''}`;
    }

    /**
     * The honest-outcome ladder (shared `.aqg-ladder` component, Atlas 15): Suggested → In text, unsaved → Saved →
     * Verified. Steps before `step` really happened; Saved and Verified are never marked here, because only AQG's
     * check of the stored record after TYPO3 Save can say so (the summary bar reports that outcome).
     */
    ladderHtml(step) {
        return ladderHtml(step, this.adapter.type, (key, fallback) => this.l(key, fallback));
    }

    noticeHtml(tone, title, text) {
        return `<div class="aqg-ai__notice aqg-ai__notice--${tone}">${title ? `<strong>${escapeHtml(title)}</strong>` : ''}${text ? `<p>${escapeHtml(text)}</p>` : ''}</div>`;
    }
}

/**
 * The honest-outcome ladder, markup of the shared `.aqg-ladder` component (Scss/components/_ladder.scss, copied into
 * ckeditor.css): Suggested → In text (or source), unsaved → Saved → Verified. `step` is the current step; every
 * earlier step is done, later ones are open. Verified is only ever current after AQG re-checked saved content.
 */
export const ladderHtml = (step, editorType, label) => {
    const steps = [
        ['suggested', 'aiStepSuggested', 'Suggested'],
        ['inserted', editorType === 'html' ? 'aiStepInSource' : 'aiStepInText', editorType === 'html' ? 'In source, unsaved' : 'In text, unsaved'],
        ['saved', 'aiStepSaved', 'Saved'],
        ['verified', 'aiStepVerified', 'Verified'],
    ];
    const current = steps.findIndex(([id]) => id === step);
    const items = steps.map(([id, key, fallback], index) => {
        const classes = [index < current ? 'is-done' : '', index === current ? 'is-current' : '', index === current && id === 'verified' ? 'is-verified' : '']
            .filter(Boolean).join(' ');
        return `<li${classes ? ` class="${classes}"` : ''}${index === current ? ' aria-current="step"' : ''}><span class="aqg-ladder__dot" aria-hidden="true"></span><span>${escapeHtml(label(key, fallback))}</span></li>`;
    });
    return `<ol class="aqg-ladder" aria-label="${escapeHtml(label('aiOutcomeLadder', 'Outcome of this change'))}">${items.join('')}</ol>`;
};

/** "What was sent?": provenance, the context sent and not sent, and the reported usage of one AI answer. */
export const sentDetailsHtml = (data, config, label) => {
    const sent = Array.isArray(data?.sent) ? data.sent : [];
    if (sent.length === 0) {
        return '';
    }
    const names = (keys) => keys.map((key) => SENT_LABELS[key] ? label(SENT_LABELS[key][0], SENT_LABELS[key][1]) : key).join(', ');
    const usage = data.usage?.reported
        ? label('aiUsageTokens', 'Usage: %d tokens').replace('%d', String((data.usage.inputTokens ?? 0) + (data.usage.outputTokens ?? 0)))
        : label('aiUsageNotReported', 'Usage: not reported by the provider');
    return `
            <details class="aqg-ai__sent">
                <summary>${escapeHtml(label('aiWhatWasSent', 'What was sent?'))}</summary>
                <p>${escapeHtml(provenanceLine(data, config))}</p>
                <p><strong>${escapeHtml(label('aiSent', 'Sent'))}:</strong> ${escapeHtml(names(sent))}</p>
                <p><strong>${escapeHtml(label('aiNotSent', 'Not sent'))}:</strong> ${escapeHtml(names(data.notSent ?? []))}</p>
                <p>${escapeHtml(usage)}</p>
            </details>`;
};

/** Same element description: tag, position among elements of that tag, count, attributes and text. */
const sameTarget = (left, right) => {
    if (!left || !right) {
        return false;
    }
    const attributes = (target) => JSON.stringify(Object.entries(target.element?.attributes ?? {}).sort(([a], [b]) => a.localeCompare(b)));
    return left.tag === right.tag
        && left.occurrence === right.occurrence
        && left.total === right.total
        && attributes(left) === attributes(right)
        && String(left.element?.text ?? '') === String(right.element?.text ?? '');
};

const CHECK_COPY = {
    range: 'Only the marked range changes; everything else is byte-identical',
    structure: 'Elements and their order unchanged',
    target: 'Only the allowed value of the target changes',
    escaped: 'Value escaped, no markup inside the value',
};
