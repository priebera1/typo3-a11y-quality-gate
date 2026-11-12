import { EditorAiSession } from '@priebera/a11y-quality-gate/editor-ai/editor-ai-session.js';
import { StagedRemediation, reportInserted } from '@priebera/a11y-quality-gate/remediation/editor-staging.js';
import { EditorAiOutcome } from '@priebera/a11y-quality-gate/editor-ai/editor-ai-outcome.js';
import { EditorAiReviewDialog } from '@priebera/a11y-quality-gate/editor-ai/editor-ai-review-dialog.js';
import { computePopoverPlacement, sheetScrollDelta } from '@priebera/a11y-quality-gate/editor-ai/popover-placement.js';
import {
    attributeMap,
    buildChange,
    locateTarget,
    locateTargetAt,
    plainContentRange,
    sourceDiff,
    tokenizeHtml,
    verifyChange,
} from '@priebera/a11y-quality-gate/editor-ai/html-source.js';

const MAX_LIVE_VALIDATION_HTML_SIZE = 500000;
const VALIDATION_DEBOUNCE_MS = 1600;
const VALIDATION_FAST_DEBOUNCE_MS = 450;
const EDITOR_MARK_REFRESH_MS = 120;

const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

const decodeHtmlEntities = (value) => {
    const textarea = document.createElement('textarea');
    textarea.innerHTML = String(value ?? '');
    return textarea.value;
};

const normalizeSeverity = (severity) => {
    const value = String(severity ?? '').toLowerCase();
    return ['critical', 'warning', 'info', 'needs_review', 'needsreview'].includes(value)
        ? (value === 'needsreview' ? 'needs_review' : value)
        : 'info';
};

// The stroked AQG wand (as on the CKEditor markers): the line badge says "AI can help here" with it (Atlas 03 · A).
const WAND_MASK = 'url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 16 16\'%3E%3Cpath d=\'M2.3 12.6 10 4.9l1.1 1.1-7.7 7.7a.8.8 0 0 1-1.1-1.1Z\'/%3E%3Cpath d=\'M11.6 1.2 12 2.6l1.4.4-1.4.4-.4 1.4-.4-1.4-1.4-.4 1.4-.4ZM14 5.4l.3.8.8.3-.8.3-.3.8-.3-.8-.8-.3.8-.3ZM7.2 1.6l.3.8.8.3-.8.3-.3.8-.3-.8-.8-.3.8-.3Z\'/%3E%3C/svg%3E") center / contain no-repeat';

const severityRank = (severity) => ({ critical: 3, warning: 2, needs_review: 1.5, info: 1 }[normalizeSeverity(severity)] ?? 0);

// Translated labels from the wizard markup (PlainHtmlA11yWizard, data-labels); English fallbacks otherwise.
let editorLabels = {};

const editorLabel = (key, fallback) => {
    const value = editorLabels[key];
    return typeof value === 'string' && value !== '' ? value : fallback;
};

const editorLabelHtml = (key, fallback) => escapeHtml(editorLabel(key, fallback));

/**
 * TYPO3 refuses a route that inherits access from the Accessibility module with an empty 403. AQG's own 403 (record
 * access) carries a JSON answer and stays a visible failure.
 */
const isModuleAccessDenial = async (response) => {
    if (response.status !== 403) {
        return false;
    }
    try {
        return (await response.clone().text()).trim() === '';
    } catch {
        return false;
    }
};

const readEditorLabels = (raw) => {
    try {
        const parsed = JSON.parse(raw || '{}');
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (error) {
        return {};
    }
};

const severityLabel = (severity) => {
    switch (normalizeSeverity(severity)) {
        case 'critical':
            return editorLabel('severityCritical', 'Critical');
        case 'warning':
            return editorLabel('severityWarning', 'Warning');
        case 'needs_review':
            return editorLabel('severityNeedsReview', 'Needs review');
        default:
            return editorLabel('severityInfo', 'Info');
    }
};

// Severity badge text of the summary bar (Atlas 03 · A: "3 Critical", "2 Warnings"), singular and plural keys.
const SEVERITY_COUNT_LABELS = {
    critical: ['badgeCritical', '%d Critical', 'badgeCritical', '%d Critical'],
    warning: ['badgeWarningOne', '%d Warning', 'badgeWarningOther', '%d Warnings'],
    needs_review: ['badgeNeedsReview', '%d Needs review', 'badgeNeedsReview', '%d Needs review'],
    info: ['badgeInfoOne', '%d Info', 'badgeInfoOther', '%d Info'],
};

const severityCountLabel = (severity, count) => {
    const [oneKey, oneFallback, otherKey, otherFallback] = SEVERITY_COUNT_LABELS[normalizeSeverity(severity)] || SEVERITY_COUNT_LABELS.info;
    return (count === 1 ? editorLabel(oneKey, oneFallback) : editorLabel(otherKey, otherFallback)).replace('%d', String(count));
};

// A finding is titled with its plain rule name (rule.<id>.title, sent by the live check), else its message.
const issueTitle = (issue) => issue?.ruleTitle || issue?.message || issue?.ruleId || editorLabel('accessibilityIssue', 'Accessibility issue');

class PlainHtmlA11yValidator {
    constructor(container) {
        this.container = container;
        this.recordUid = parseInt(container.dataset.recordUid || '0', 10);
        this.fieldName = container.dataset.fieldName || 'bodytext';
        this.inputName = container.dataset.inputName || '';
        editorLabels = { ...editorLabels, ...readEditorLabels(container.dataset.labels) };
        this.aiConfig = readEditorLabels(container.dataset.ai);
        this.aiSessions = new Map();
        this.aiTrackers = new Set();
        this.aiTrackingInstalled = false;
        this.aiLockSequence = 0;
        // A change approved in AQG's AI fixes queue for exactly this record and field (?aqgRemediation=<item>).
        this.staged = new StagedRemediation({
            table: 'tt_content',
            uid: this.recordUid,
            field: this.fieldName,
            label: editorLabel,
            announce: (text) => this.announce(text),
        });
        this.aiOutcome = null;
        this.aiLiveRegion = null;
        // Applied, unsaved changes by finding key: the issue and the adapter that knows where the change is.
        this.aiApplied = new Map();
        this.summaryMode = null;
        // Without CodeMirror positions cannot be tracked. A source reached from a lock's source only through AQG's own
        // verified changes still allows exact relocation (those changes never add, remove or alter other elements):
        // each applied result remembers the source it was made from.
        this.aiAppliedFrom = new Map();
        this.exactIssueIndexes = new Set();
        this.summary = container.querySelector('.ck-a11y-summary');
        this.issuesContainer = container.querySelector('.aqg-plain-html-a11y__issues');
        this.textarea = this.findTextarea();
        this.editorHost = this.findEditorHost();
        this.validationTimer = null;
        this.editorMarkTimer = null;
        this.editorPanelAnchor = null;
        this.editorPanelAnchorKey = '';
        this.boundOutsidePointer = null;
        this.abortController = null;
        this.sequence = 0;
        this.currentIssues = [];
        this.displayedIssues = [];
        this.issueLines = new Map();
        this.editorPanel = null;
        this.observer = null;
        this.lastKnownHtml = '';
        this.lastValidatedHtml = '';
        // The HTML of the request in flight: a repeated trigger for the same source waits for that answer.
        this.inFlightHtml = null;
        // TYPO3 refused the live check (403, no access to the Accessibility module): no feedback, no further requests.
        this.accessDenied = false;
        this.lastEditorInteractionAt = 0;
        this.destroyed = false;
        this.decorationFrame = null;
        this.boundDocumentPaste = (event) => this.handlePossibleEditorInput(event);
        this.boundDocumentInput = (event) => this.handlePossibleEditorInput(event);
        this.boundDocumentKeyup = (event) => this.handlePossibleEditorInput(event);

        if (!this.textarea || !this.recordUid || !this.fieldName) {
            this.renderError('HTML field could not be initialized.');
            return;
        }

        this.moveSummaryNearEditor();
        this.createEditorPanel();
        this.installAiOutcome();
        this.bindEvents();
        this.scheduleValidation(0, { force: true });
    }

    findTextarea() {
        if (!this.inputName) {
            return null;
        }

        for (const textarea of document.querySelectorAll('textarea')) {
            if (textarea.getAttribute('name') === this.inputName) {
                return textarea;
            }
        }

        return null;
    }

    findEditorHost() {
        if (!this.inputName) {
            return null;
        }

        for (const host of document.querySelectorAll('typo3-t3editor-codemirror')) {
            if (host.getAttribute('name') === this.inputName) {
                return host;
            }
            for (const textarea of host.querySelectorAll('textarea')) {
                if (textarea.getAttribute('name') === this.inputName) {
                    return host;
                }
            }
        }

        return this.textarea?.closest('typo3-t3editor-codemirror') || null;
    }

    moveSummaryNearEditor() {
        if (!this.editorHost) {
            return;
        }

        const elementColumn = this.editorHost.closest('.form-wizards-item-element');
        if (elementColumn && this.container.parentElement !== elementColumn) {
            elementColumn.insertBefore(this.container, this.editorHost);
            this.container.classList.add('aqg-plain-html-a11y--attached-to-editor');
        }
    }

    /**
     * The details of a marked line open only when its badge is pressed (never on a click into the line, hover or
     * typing), close with Escape, the close button or a click elsewhere, and give focus back to the code editor.
     */
    createEditorPanel() {
        this.editorPanel = document.createElement('div');
        this.editorPanel.className = 'aqg-code-html-panel';
        this.editorPanel.hidden = true;
        this.editorPanel.setAttribute('role', 'dialog');
        this.editorPanel.setAttribute('aria-modal', 'false');
        document.body.appendChild(this.editorPanel);

        this.editorPanel.addEventListener('click', (event) => this.handleEditorPanelClick(event));
        // A suggestion is taller than the buttons it replaces: the panel is placed again so all of it stays in view.
        this.editorPanel.addEventListener('aqg-ai:rendered', () => {
            if (!this.editorPanel.hidden) {
                this.placeEditorPanel(this.editorPanelAnchor);
            }
        });
        window.addEventListener('resize', () => {
            if (!this.editorPanel.hidden) {
                this.placeEditorPanel(this.editorPanelAnchor);
            }
        }, { passive: true });
        this.editorPanel.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                this.hideEditorPanel({ returnFocus: true });
            }
        });
        this.boundOutsidePointer = (event) => {
            if (this.editorPanel.hidden || !(event.target instanceof Node)) {
                return;
            }
            if (this.editorPanel.contains(event.target) || event.target.closest?.('.aqg-cm-side-badge')) {
                return;
            }
            this.hideEditorPanel();
        };
        document.addEventListener('mousedown', this.boundOutsidePointer, true);
    }

    bindEvents() {
        const onContentChange = () => this.scheduleValidation();
        this.textarea.addEventListener('input', onContentChange);
        this.textarea.addEventListener('change', () => this.scheduleValidation(VALIDATION_FAST_DEBOUNCE_MS));
        this.issuesContainer.addEventListener('click', (event) => this.handleIssueClick(event));
        this.summary?.addEventListener('click', (event) => {
            if (this.aiOutcome?.handleClick(event.target)) {
                return;
            }
            if (event.target.closest('.js-aqg-plain-html-retry')) {
                this.scheduleValidation(0, { force: true });
            }
            if (event.target.closest('.js-aqg-plain-html-next')) {
                this.openAdjacentIssue(1);
            }
            const reviewButton = event.target.closest('.js-aqg-plain-html-review');
            if (reviewButton) {
                this.openReviewDialog(reviewButton);
            }
        });
        // F8 / Shift+F8: next / previous issue from the cursor line. Inside the code editor Tab indents, so the
        // line badges are not a Tab stop; this is their keyboard route.
        this.boundIssueKeys = (event) => {
            if (event.key !== 'F8' || event.altKey || event.ctrlKey || event.metaKey) {
                return;
            }
            const root = this.getCodeMirrorRoot();
            const target = event.composedPath?.()[0] ?? event.target;
            const inside = target === this.textarea || (root && target instanceof Node && root.contains(target))
                || (this.editorHost && target instanceof Node && this.editorHost.contains(target))
                || (this.editorPanel && target instanceof Node && this.editorPanel.contains(target));
            if (!inside) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            this.openAdjacentIssue(event.shiftKey ? -1 : 1);
        };
        document.addEventListener('keydown', this.boundIssueKeys, true);

        document.addEventListener('paste', this.boundDocumentPaste, true);
        document.addEventListener('input', this.boundDocumentInput, true);
        document.addEventListener('keyup', this.boundDocumentKeyup, true);

        this.lastKnownHtml = this.getHtmlValue();
        this.bindCodeMirrorEvents();
        this.observeCodeMirrorDom();
    }

    handlePossibleEditorInput(event) {
        const root = this.getCodeMirrorRoot();
        const target = event.target;
        if (!(target instanceof Node)) {
            return;
        }

        const isInsideEditor = (root && root.contains(target))
            || (this.editorHost && this.editorHost.contains(target))
            || target === this.textarea;

        if (!isInsideEditor) {
            return;
        }

        this.lastEditorInteractionAt = Date.now();
        window.setTimeout(() => this.scheduleValidationIfHtmlChanged(), 80);
    }

    scheduleValidationIfHtmlChanged(delay = VALIDATION_DEBOUNCE_MS) {
        const currentHtml = this.getHtmlValue();
        if (currentHtml === this.lastKnownHtml && currentHtml === this.lastValidatedHtml) {
            return;
        }

        this.lastKnownHtml = currentHtml;
        this.scheduleValidation(delay);
    }

    bindCodeMirrorEvents() {
        const root = this.getCodeMirrorRoot();
        if (!root) {
            window.setTimeout(() => this.bindCodeMirrorEvents(), 250);
            return;
        }

        if (root.dataset.aqgBound === '1') {
            if (this.displayedIssues.length > 0) {
                this.applyEditorDecorations(this.displayedIssues);
            }
            return;
        }

        root.dataset.aqgBound = '1';
        // The CodeMirror web component may be hydrated after the FormEngine wizard.
        // Once we have the root, start observing it here as well.
        this.observeCodeMirrorDom();
        const content = root.querySelector('.cm-content');
        const onEditorChange = () => this.scheduleValidation();

        root.addEventListener('keyup', onEditorChange);
        root.addEventListener('paste', () => window.setTimeout(onEditorChange, 80));
        root.addEventListener('input', onEditorChange, true);
        this.editorHost?.addEventListener('paste', () => window.setTimeout(onEditorChange, 80));
        this.editorHost?.addEventListener('input', onEditorChange, true);
        content?.addEventListener('input', onEditorChange, true);
        content?.addEventListener('keyup', onEditorChange);
        content?.addEventListener('paste', () => window.setTimeout(onEditorChange, 80));
        // Only the badge opens details: a click into a marked line places the cursor like anywhere else.
        root.addEventListener('mousedown', (event) => {
            if (event.target instanceof Element && event.target.closest('.aqg-cm-side-badge')) {
                event.preventDefault();
            }
        });
        root.addEventListener('click', (event) => this.handleEditorLineEvent(event));
        root.querySelector('.cm-scroller')?.addEventListener('scroll', () => {
            this.hideEditorPanel();
            this.scheduleEditorDecorationRefresh();
        });

        if (this.displayedIssues.length > 0) {
            this.applyEditorDecorations(this.displayedIssues);
        }
    }

    observeCodeMirrorDom() {
        const root = this.getCodeMirrorRoot();
        if (!root || this.observer) {
            return;
        }

        this.observer = new MutationObserver((mutations) => {
            if (this.destroyed || !document.contains(this.container)) {
                this.destroy();
                return;
            }

            const contentChanged = mutations.some((mutation) => {
                const target = mutation.target;
                if (!(target instanceof Element) && !(target instanceof Text)) {
                    return false;
                }
                const element = target instanceof Element ? target : target.parentElement;
                if (!element || element.closest('.aqg-cm-overlay') || element.closest('.aqg-code-html-panel')) {
                    return false;
                }
                return Boolean(element.closest('.cm-content'));
            });

            // Important: do not refresh on every mutation. Our own overlay badges
            // create DOM mutations as well. Refreshing on all mutations creates a
            // feedback loop and can make the TYPO3 tab grow to multiple GB RAM.
            if (contentChanged) {
                this.scheduleEditorDecorationRefresh();
                window.setTimeout(() => this.scheduleValidationIfHtmlChanged(), 120);
            }
        });
        this.observer.observe(root, { childList: true, subtree: true, characterData: true });
    }

    getEditorSearchRoots() {
        const roots = [];
        const add = (root) => {
            if (root && !roots.includes(root)) {
                roots.push(root);
            }
        };

        add(this.editorHost);
        add(this.editorHost?.shadowRoot);
        add(this.textarea?.closest('typo3-t3editor-codemirror'));
        add(this.textarea?.closest('typo3-t3editor-codemirror')?.shadowRoot);
        add(this.textarea?.closest('.form-wizards-item-element'));
        add(document);

        return roots;
    }

    getCodeMirrorRoot() {
        for (const searchRoot of this.getEditorSearchRoots()) {
            if (searchRoot instanceof Element && searchRoot.matches('.cm-editor')) {
                return searchRoot;
            }
            const root = searchRoot.querySelector?.('.cm-editor') || searchRoot.querySelector?.('.cm-content')?.closest('.cm-editor');
            if (root) {
                return root;
            }
        }

        return null;
    }

    getCodeMirrorContent() {
        for (const searchRoot of this.getEditorSearchRoots()) {
            if (searchRoot instanceof Element && searchRoot.matches('.cm-content')) {
                return searchRoot;
            }
            const content = searchRoot.querySelector?.('.cm-content');
            if (content) {
                return content;
            }
        }

        return null;
    }

    scheduleValidation(delay = VALIDATION_DEBOUNCE_MS, { force = false } = {}) {
        if (this.accessDenied) {
            return;
        }
        window.clearTimeout(this.validationTimer);
        this.aiSessions?.forEach((session) => session.checkStale());

        // Do not call the live endpoint on every keystroke. The timer is reset
        // while the editor is still changing and validation starts only after
        // the editor has been quiet for the configured debounce time. Key and
        // pointer events also arrive for caret moves and selections: validate()
        // asks the server only when the source differs from the checked one.
        this.validationTimer = window.setTimeout(() => this.validate({ force }), Math.max(0, delay));
    }

    async validate({ force = false } = {}) {
        if (this.accessDenied) {
            return;
        }
        const endpoint = TYPO3?.settings?.ajaxUrls?.a11y_rte_validate || TYPO3?.settings?.ajaxUrls?.['a11y_rte_validate'];

        if (!endpoint) {
            this.renderError(editorLabel('liveUnavailable', 'Live validation endpoint is not available.'));
            return;
        }

        const html = this.getHtmlValue();
        this.lastKnownHtml = html;
        // Unchanged source (a caret move, a selection, a focus change): the shown result still describes it. Back at
        // the checked source while another one is in flight (undo), the request below replaces that older answer.
        if (!force && (html === this.inFlightHtml || (this.inFlightHtml === null && html === this.lastValidatedHtml))) {
            return;
        }
        if (html.length > MAX_LIVE_VALIDATION_HTML_SIZE) {
            this.renderError(editorLabel('htmlTooLarge', 'HTML is too large for live validation. Save the record and run a page scan instead.'));
            return;
        }

        if (this.abortController) {
            this.abortController.abort();
        }

        const sequence = ++this.sequence;
        this.abortController = new AbortController();
        this.inFlightHtml = html;
        this.renderLoading();

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                signal: this.abortController.signal,
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    recordUid: this.recordUid,
                    fieldName: this.fieldName,
                    html,
                }),
            });

            if (sequence !== this.sequence) {
                return;
            }
            this.inFlightHtml = null;

            if (await isModuleAccessDenial(response)) {
                this.disableForMissingAccess();
                return;
            }

            if (!response.ok) {
                this.renderError(editorLabel('liveFailedStatus', 'Live validation failed (%s).').replace('%s', String(response.status)));
                return;
            }

            const data = await response.json();
            if (!data.success) {
                this.renderError(data.error || editorLabel('liveFailed', 'Live validation failed.'));
                return;
            }

            this.lastValidatedHtml = html;
            this.serverTime = Number(data.serverTime) || this.serverTime || 0;
            this.currentIssues = Array.isArray(data.issues) ? data.issues : [];
            this.displayedIssues = this.sortIssues(this.currentIssues);
            this.syncAiSessions(this.displayedIssues);
            if (this.aiConfig.state === 'ready') {
                this.installAiTracking(this.getEditorView());
            }
            this.renderSummary(this.displayedIssues);
            this.renderIssues(this.displayedIssues);
            this.applyEditorDecorations(this.displayedIssues);
            if (this.staged.active) {
                this.staged.adopt(this.displayedIssues, (issue) => this.aiSessionFor(issue), (issue) => this.openIssuePanel(issue));
            }
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }
            if (sequence === this.sequence) {
                this.inFlightHtml = null;
            }
            this.renderError(editorLabel('liveFailedRetry', 'Live validation failed. You can keep editing and try again after saving.'));
        }
    }

    /**
     * TYPO3 answers AQG's routes with 403 when the user lacks the Accessibility module (the routes inherit its
     * access). That is no failure of the check: the AQG bar and markers are removed and nothing is requested again.
     */
    disableForMissingAccess() {
        this.accessDenied = true;
        window.clearTimeout(this.validationTimer);
        this.validationTimer = null;
        this.abortController?.abort();
        this.abortController = null;
        this.currentIssues = [];
        this.displayedIssues = [];
        this.applyEditorDecorations([]);
        this.hideEditorPanel();
        this.container.hidden = true;
        this.summary?.remove();
    }

    getHtmlValue() {
        const hostValue = this.editorHost && typeof this.editorHost.value === 'string' ? this.editorHost.value : '';
        if (hostValue !== '') {
            return hostValue;
        }

        return this.textarea?.value || '';
    }

    sortIssues(issues) {
        return issues.slice().sort((a, b) => severityRank(b.severity) - severityRank(a.severity));
    }

    renderLoading() {
        if (!this.summary) {
            return;
        }

        this.summaryMode = { kind: 'loading' };
        this.summary.className = 'ck-a11y-summary ck-a11y-summary--outside ck-a11y-summary--loading';
        this.summary.innerHTML = `
            <span class="ck-a11y-summary__left">
                <span class="ck-a11y-summary__spin" aria-hidden="true"></span>
                <span class="ck-a11y-summary__title">${editorLabelHtml('updating', 'Updating accessibility status…')}</span>
                <span class="ck-a11y-summary__help">${editorLabelHtml('plainUpdatingHelp', 'Refreshing live issues for this HTML field.')}</span>
            </span>
        `;
        this.renderSummaryOutcome();
    }

    renderError(message, { barOnly = false } = {}) {
        if (!this.summary) {
            return;
        }

        this.summaryMode = { kind: 'error', message };
        this.summary.className = 'ck-a11y-summary ck-a11y-summary--outside ck-a11y-summary--error';
        this.summary.innerHTML = `
            <span class="ck-a11y-summary__left">
                <span class="ck-a11y-summary__dot" aria-hidden="true"></span>
                <span class="ck-a11y-summary__title">${editorLabelHtml('loadFailed', 'Accessibility issues could not be loaded')}</span>
                <span class="ck-a11y-summary__help">${escapeHtml(message)}</span>
            </span>
            <span class="ck-a11y-summary__right">
                <button type="button" class="ck-a11y-summary__link js-aqg-plain-html-retry">${editorLabelHtml('retry', 'Retry')}</button>
            </span>
        `;

        this.renderSummaryOutcome();
        this.summary.querySelector('.js-aqg-plain-html-retry')?.addEventListener('click', () => this.scheduleValidation(0, { force: true }));
        if (barOnly) {
            return;
        }
        this.issuesContainer.innerHTML = '';
        this.clearEditorDecorations();
        this.hideEditorPanel();
    }

    renderSummary(issues) {
        const counts = issues.reduce((acc, issue) => {
            acc[normalizeSeverity(issue.severity)]++;
            return acc;
        }, { critical: 0, warning: 0, needs_review: 0, info: 0 });
        const total = counts.critical + counts.warning + counts.needs_review + counts.info;
        const state = total === 0 ? 'ok' : counts.critical > 0 ? 'critical' : 'issues';

        this.summaryMode = { kind: 'issues', issues };
        this.summary.className = `ck-a11y-summary ck-a11y-summary--outside ck-a11y-summary--${state}`;

        if (total === 0) {
            this.summary.innerHTML = `
                <span class="ck-a11y-summary__left">
                    <span class="ck-a11y-summary__dot" aria-hidden="true"></span>
                    <span class="ck-a11y-summary__title">${editorLabelHtml('passed', 'Accessibility check passed')}</span>
                    <span class="ck-a11y-summary__help">${editorLabelHtml('plainPassedHelp', 'No issues found in this HTML field.')}</span>
                </span>
            `;
            this.renderSummaryOutcome();
            return;
        }

        this.summary.innerHTML = `
            <span class="ck-a11y-summary__left">
                <span class="ck-a11y-summary__dot" aria-hidden="true"></span>
                <span class="ck-a11y-summary__title">${(total === 1
                    ? editorLabelHtml('issuesFoundOne', '%d issue found')
                    : editorLabelHtml('issuesFoundOther', '%d issues found')).replace('%d', String(total))}</span>
                <span class="ck-a11y-summary__counts">
                    ${this.renderCount('critical', counts.critical)}
                    ${this.renderCount('warning', counts.warning)}
                    ${this.renderCount('needs_review', counts.needs_review)}
                    ${this.renderCount('info', counts.info)}
                </span>
            </span>
            <span class="ck-a11y-summary__right">
                <span class="ck-a11y-summary__help">${editorLabelHtml('plainLinesMarked', 'Lines with issues are marked directly in the HTML editor.')}</span>
                <button class="ck-a11y-summary__link ck-a11y-summary__next js-aqg-plain-html-next" type="button" aria-keyshortcuts="F8" title="F8 / Shift+F8"><span class="ck-a11y-summary__next-label">${editorLabelHtml('nextIssue', 'Next issue')}</span> <kbd>F8</kbd></button>
                ${this.reviewableIssues(issues).length >= 2 ? `<button class="ck-a11y-summary__link js-aqg-plain-html-review" type="button" aria-haspopup="dialog">${editorLabelHtml('aiReviewFixes', 'Review fixes (%d)').replace('%d', String(this.reviewableIssues(issues).length))}</button>` : ''}
            </span>
        `;
        this.renderSummaryOutcome();
    }

    /**
     * The outcome of applied AI changes lives inside the summary bar ("1 unsaved", "Checking the saved record…"),
     * never in a block of its own: the field keeps its height. A settled outcome's reason replaces the bar's hint.
     */
    renderSummaryOutcome() {
        const html = this.aiOutcome?.barHtml() ?? '';
        if (!this.summary || html === '') {
            return;
        }
        // The live check's own error stays readable; only the generic hints give way.
        if (this.aiOutcome.hasHelp() && this.summaryMode?.kind !== 'error') {
            this.summary.querySelectorAll('.ck-a11y-summary__help').forEach((help) => help.remove());
        }
        const left = this.summary.querySelector('.ck-a11y-summary__left') ?? this.summary;
        const title = left.querySelector('.ck-a11y-summary__title');
        if (title) {
            // "1 unsaved" takes the title's place (Atlas 03 · A): the bar keeps one line.
            title.classList.add('ck-a11y-summary__title--replaced');
            this.summary.classList.add('ck-a11y-summary--has-outcome');
            title.insertAdjacentHTML('afterend', html);
        } else {
            left.insertAdjacentHTML('beforeend', html);
        }
    }

    /** Re-renders the summary bar as it is (after an outcome change), keeping keyboard focus inside it. */
    rerenderSummary() {
        if (!this.summary || !this.summaryMode) {
            return;
        }
        const hadFocus = this.summary.contains(document.activeElement);
        if (this.summaryMode.kind === 'loading') {
            this.renderLoading();
        } else if (this.summaryMode.kind === 'error') {
            this.renderError(this.summaryMode.message, { barOnly: true });
        } else {
            this.renderSummary(this.summaryMode.issues);
        }
        if (hadFocus) {
            (this.summary.querySelector('.js-aqg-plain-html-next') ?? this.summary.querySelector('button'))?.focus();
        }
    }

    renderCount(severity, count) {
        if (count <= 0) {
            return '';
        }

        return `<span class="ck-a11y-summary__count ck-a11y-summary__count--${severity}"><span class="ck-a11y-summary__count-dot" aria-hidden="true"></span>${escapeHtml(severityCountLabel(severity, count))}</span>`;
    }

    renderIssues(issues) {
        if (!this.issuesContainer) {
            return;
        }

        if (!issues.length) {
            this.issuesContainer.hidden = true;
            this.issuesContainer.innerHTML = '';
            return;
        }

        this.issuesContainer.hidden = false;
        this.issuesContainer.innerHTML = `
            <details class="aqg-plain-html-a11y__details-list">
                <summary>${editorLabelHtml('plainShowIssueList', 'Show issue list (%d)').replace('%d', String(issues.length))}</summary>
                <div class="aqg-plain-html-a11y__details-body">
                    ${issues.map((issue, index) => this.renderIssue(issue, index)).join('')}
                </div>
            </details>
        `;
    }

    renderIssue(issue, index) {
        const severity = normalizeSeverity(issue.severity);
        const hint = issue.hint ? `
            <div class="aqg-plain-html-a11y__fix">
                <span class="aqg-plain-html-a11y__fix-label">${editorLabelHtml('howToFix', 'How to fix')}</span>
                <p>${escapeHtml(issue.hint)}</p>
            </div>
        ` : '';
        const snippet = issue.snippet ? `
            <code class="aqg-plain-html-a11y__snippet">${escapeHtml(issue.snippet)}</code>
        ` : '';

        return `
            <details class="aqg-plain-html-a11y__issue aqg-plain-html-a11y__issue--${severity}" data-fingerprint="${escapeHtml(issue.fingerprint)}" data-index="${index}">
                <summary class="aqg-plain-html-a11y__issue-head">
                    <span class="aqg-plain-html-a11y__issue-num">#${String(index + 1).padStart(2, '0')}</span>
                    <span class="aqg-plain-html-a11y__issue-title-wrap">
                        <span class="aqg-plain-html-a11y__issue-title">${escapeHtml(issueTitle(issue))}</span>
                        <span class="aqg-plain-html-a11y__issue-rule">${escapeHtml(issue.ruleId || '')}</span>
                    </span>
                    <span class="ck-a11y-panel__severity ck-a11y-panel__severity--${severity}">
                        <span class="ck-a11y-panel__severity-dot" aria-hidden="true"></span>${severityLabel(severity)}
                    </span>
                </summary>
                <div class="aqg-plain-html-a11y__issue-body">
                    ${hint}
                    <dl class="aqg-plain-html-a11y__meta">
                        <div><dt>${editorLabelHtml('rule', 'Rule')}</dt><dd>${escapeHtml(issue.ruleId || '')}</dd></div>
                        <div><dt>${editorLabelHtml('location', 'Location')}</dt><dd>${escapeHtml(issue.contextPath || editorLabel('htmlSource', 'HTML source'))}</dd></div>
                    </dl>
                    ${snippet}
                    <div class="aqg-plain-html-a11y__actions">
                        <button type="button" class="btn btn-default btn-sm js-aqg-plain-html-locate" data-index="${index}">${editorLabelHtml('locateInHtml', 'Locate in HTML')}</button>
                        <button type="button" class="btn btn-default btn-sm js-aqg-plain-html-ignore" data-index="${index}">${editorLabelHtml('ignoreIssue', 'Ignore this issue')}</button>
                    </div>
                </div>
            </details>
        `;
    }

    handleIssueClick(event) {
        const locateButton = event.target.closest('.js-aqg-plain-html-locate');
        if (locateButton) {
            const issue = this.displayedIssues[parseInt(locateButton.dataset.index || '-1', 10)];
            this.locateIssue(issue);
            return;
        }

        const ignoreButton = event.target.closest('.js-aqg-plain-html-ignore');
        if (ignoreButton) {
            const issue = this.displayedIssues[parseInt(ignoreButton.dataset.index || '-1', 10)];
            this.ignoreIssue(issue, ignoreButton);
        }
    }

    handleEditorPanelClick(event) {
        if (event.target.closest('.js-aqg-code-panel-close')) {
            this.hideEditorPanel({ returnFocus: true });
            return;
        }

        const stepButton = event.target.closest('.js-aqg-code-panel-step');
        if (stepButton && this.editorPanelAnchor) {
            const step = parseInt(stepButton.dataset.step || '0', 10);
            this.showEditorPanel(this.editorPanelIndexes ?? [], this.editorPanelAnchor, (this.editorPanelPosition ?? 0) + step, step);
            return;
        }

        const locateButton = event.target.closest('.js-aqg-code-panel-locate');
        if (locateButton) {
            const issue = this.displayedIssues[parseInt(locateButton.dataset.index || '-1', 10)];
            this.hideEditorPanel();
            this.locateIssue(issue);
            return;
        }

        const ignoreButton = event.target.closest('.js-aqg-code-panel-ignore');
        if (ignoreButton) {
            const issue = this.displayedIssues[parseInt(ignoreButton.dataset.index || '-1', 10)];
            this.ignoreIssue(issue, ignoreButton);
        }
    }

    handleEditorLineEvent(event) {
        const appliedBadge = event.target instanceof Element ? event.target.closest('.aqg-cm-side-badge[data-aqg-applied-key]') : null;
        if (appliedBadge) {
            const key = appliedBadge.dataset.aqgAppliedKey || '';
            if (!this.editorPanel.hidden && this.editorPanelAnchorKey === `applied:${key}`) {
                this.hideEditorPanel({ returnFocus: true });
                return;
            }
            this.showAppliedPanel(key, appliedBadge);
            return;
        }
        const badge = event.target instanceof Element ? event.target.closest('.aqg-cm-side-badge[data-aqg-issue-indexes]') : null;
        if (!badge) {
            return;
        }

        const indexes = String(badge.dataset.aqgIssueIndexes || '')
            .split(',')
            .map((value) => parseInt(value, 10))
            .filter((value) => Number.isInteger(value) && value >= 0);

        if (!indexes.length) {
            return;
        }

        if (!this.editorPanel.hidden && this.editorPanelAnchorKey === badge.dataset.aqgIssueIndexes) {
            this.hideEditorPanel({ returnFocus: true });
            return;
        }

        this.panelLine = Array.from(this.issueLines.entries()).find(([, lineIndexes]) => lineIndexes.join(',') === indexes.join(','))?.[0] ?? null;
        this.showEditorPanel(indexes, badge);
    }

    showEditorPanel(indexes, line, position = 0, focusStep = 0) {
        const issues = indexes.map((index) => ({ issue: this.displayedIssues[index], index })).filter((entry) => entry.issue);
        if (!issues.length || !this.editorPanel) {
            return;
        }

        // Atlas 03 · A: one finding at a time; several findings on the line get "N issues on this line" and a pager
        // instead of a list of every finding with its AI block (audit F-30: that list outgrew the form).
        const current = Math.min(Math.max(0, position), issues.length - 1);
        this.editorPanelIndexes = indexes;
        this.editorPanelPosition = current;
        const primary = issues[current].issue;
        const severity = normalizeSeverity(primary.severity);
        const switcher = issues.length > 1
            ? `<div class="aqg-code-html-panel__switcher">
                    <span>${editorLabelHtml('issuesOnLine', '%d issues on this line').replace('%d', String(issues.length))}</span>
                    <span class="ck-a11y-popover__pager">
                        <button type="button" class="ck-a11y-popover__step ck-a11y-popover__step--prev js-aqg-code-panel-step" data-step="-1" aria-label="${editorLabelHtml('previousIssue', 'Previous issue')}"${current === 0 ? ' disabled' : ''}><span aria-hidden="true"></span></button>
                        <span class="ck-a11y-popover__position">${escapeHtml(editorLabel('popoverPosition', '%1$d of %2$d').replace('%1$d', String(current + 1)).replace('%2$d', String(issues.length)))}</span>
                        <button type="button" class="ck-a11y-popover__step ck-a11y-popover__step--next js-aqg-code-panel-step" data-step="1" aria-label="${editorLabelHtml('nextIssue', 'Next issue')}"${current === issues.length - 1 ? ' disabled' : ''}><span aria-hidden="true"></span></button>
                    </span>
                </div>`
            : '';
        const body = switcher + this.renderPanelIssue(primary, issues[current].index);

        const titleId = 'aqg-code-html-panel-title';
        this.editorPanel.className = `aqg-code-html-panel aqg-code-html-panel--${severity} is-pinned`;
        this.editorPanel.setAttribute('aria-labelledby', titleId);
        this.editorPanel.innerHTML = `
            <div class="aqg-code-html-panel__header">
                <span class="ck-a11y-panel__severity ck-a11y-panel__severity--${severity}">
                    <span class="ck-a11y-panel__severity-dot" aria-hidden="true"></span>${severityLabel(severity)}
                </span>
                <span class="aqg-code-html-panel__kicker" id="${titleId}" tabindex="-1">${editorLabelHtml('htmlSourceIssue', 'HTML source issue')}</span>
                <button type="button" class="aqg-code-html-panel__close js-aqg-code-panel-close" aria-label="${editorLabelHtml('close', 'Close')}"><span aria-hidden="true">×</span></button>
            </div>
            ${body}
        `;

        this.mountAiSessions();
        this.editorPanel.hidden = false;
        this.editorPanelAnchorKey = line.dataset.aqgIssueIndexes || '';
        this.editorPanelAnchor = line;
        this.placeEditorPanel(line);
        line.setAttribute('aria-expanded', 'true');
        const stepFocus = focusStep !== 0
            ? this.editorPanel.querySelector(`.js-aqg-code-panel-step[data-step="${focusStep}"]:not([disabled])`)
                ?? this.editorPanel.querySelector('.js-aqg-code-panel-step:not([disabled])')
            : null;
        (stepFocus ?? this.editorPanel.querySelector(`#${titleId}`))?.focus({ preventScroll: true });
    }

    /**
     * The line panel of an applied, unsaved change: its finding, the outcome ladder and Undo (the session that
     * applied it), or, when the review dialog applied it, the honest "not saved yet" note with the editor's Ctrl+Z.
     */
    showAppliedPanel(key, badge) {
        const applied = this.aiApplied.get(key);
        if (!applied || !this.editorPanel) {
            return;
        }
        const session = this.aiSessions.get(key);
        const titleId = 'aqg-code-html-panel-title';
        this.editorPanel.className = 'aqg-code-html-panel aqg-code-html-panel--info is-pinned';
        this.editorPanel.setAttribute('aria-labelledby', titleId);
        this.editorPanel.innerHTML = `
            <div class="aqg-code-html-panel__header">
                <span class="aqg-code-html-panel__kicker" id="${titleId}" tabindex="-1">${editorLabelHtml('aiAppliedSource', 'Applied to the source — not saved yet')}</span>
                <button type="button" class="aqg-code-html-panel__close js-aqg-code-panel-close" aria-label="${editorLabelHtml('close', 'Close')}"><span aria-hidden="true">×</span></button>
            </div>
            <h4 class="ck-a11y-panel__title">${escapeHtml(issueTitle(applied.issue))}</h4>
            ${session?.state === 'applied'
                ? '<div class="aqg-ai-slot" data-aqg-ai-applied-slot="1"></div>'
                : `<p class="aqg-ai-quiet">${editorLabelHtml('aiAppliedSourceHelp', 'Use TYPO3 Save to keep it. Ctrl+Z undoes it in one step.')}</p>`}
        `;
        const slot = this.editorPanel.querySelector('[data-aqg-ai-applied-slot]');
        if (slot && session) {
            session.mount(slot);
        }
        this.editorPanel.hidden = false;
        this.editorPanelAnchorKey = `applied:${key}`;
        this.editorPanelAnchor = badge;
        this.placeEditorPanel(badge);
        badge.setAttribute('aria-expanded', 'true');
        this.editorPanel.querySelector(`#${titleId}`)?.focus({ preventScroll: true });
    }

    placeEditorPanel(line) {
        // Right-aligned to the badge, never wider than 380 px, and all of it in the window (shared placement with the
        // CKEditor popover, audit F-03): below the badge, above it, or shifted into view; only a panel taller than
        // the window scrolls inside, with its action row sticky at the bottom.
        if (!line?.isConnected && this.editorPanel.style.top !== '') {
            line = null;
        }
        const panel = this.editorPanel;
        const rect = (line ?? panel).getBoundingClientRect();
        const width = Math.min(380, window.innerWidth - 24);
        const left = Math.min(Math.max(12, rect.right - width), window.innerWidth - width - 12);
        const sheet = (this.editorHost?.getBoundingClientRect().width ?? window.innerWidth) < 560;

        panel.classList.toggle('aqg-code-html-panel--sheet', sheet);
        panel.style.width = `${width}px`;
        panel.style.left = `${left}px`;
        panel.style.maxHeight = '';
        if (!line) {
            return;
        }
        if (!sheet) {
            this.sheetSpacer?.remove();
        }
        if (sheet) {
            panel.style.top = '';
            panel.dataset.placement = 'sheet';
            this.keepLineAboveSheet(line);
            return;
        }
        const placement = computePopoverPlacement({
            anchorRect: rect,
            // The panel is fixed: viewport coordinates, and the sheet rule follows the editor width (above).
            hostRect: { top: 0, bottom: window.innerHeight, width: Math.max(560, window.innerWidth) },
            popoverHeight: panel.hidden ? 0 : panel.scrollHeight,
            viewportHeight: window.innerHeight,
            topLimit: this.visibleFormTop(),
            gap: 6,
        });
        panel.dataset.placement = placement.mode;
        panel.style.top = `${placement.top}px`;
        panel.classList.toggle('aqg-code-html-panel--clamped', placement.maxHeight !== null);
        if (placement.maxHeight !== null) {
            panel.style.maxHeight = `${placement.maxHeight}px`;
        }
    }

    /** The marked line stays visible above the bottom sheet: the form (not the code editor) scrolls it up. */
    keepLineAboveSheet(line) {
        if (this.editorPanel.hidden) {
            return;
        }
        const sheetHeight = this.editorPanel.getBoundingClientRect().height;
        // Room below the field, so that a line near the end of the form can still scroll above the sheet.
        if (!this.sheetSpacer) {
            this.sheetSpacer = document.createElement('div');
            this.sheetSpacer.setAttribute('aria-hidden', 'true');
        }
        (this.editorHost ?? this.container).after(this.sheetSpacer);
        this.sheetSpacer.style.height = `${Math.round(sheetHeight)}px`;
        const delta = sheetScrollDelta({
            targetRect: line.getBoundingClientRect(),
            viewportHeight: window.innerHeight,
            sheetHeight,
            topLimit: this.visibleFormTop(),
        });
        if (delta !== 0) {
            (this.formScrollContainer() ?? document.scrollingElement)?.scrollBy({ top: delta });
        }
    }

    formScrollContainer() {
        for (let element = this.editorHost?.parentElement ?? null; element && element !== document.body; element = element.parentElement) {
            if (/(auto|scroll)/.test(window.getComputedStyle(element).overflowY) && element.scrollHeight > element.clientHeight) {
                return element;
            }
        }
        return null;
    }

    /** Top of the visible form below TYPO3's document header (the form scrolls in its own container). */
    visibleFormTop() {
        return Math.max(0, this.formScrollContainer()?.getBoundingClientRect().top ?? 0);
    }

    renderPanelIssue(issue, index) {
        const hint = issue.hint ? `
            <div class="ck-a11y-panel__hint">
                <span class="ck-a11y-panel__hint-label">${editorLabelHtml('howToFix', 'How to fix')}</span>
                <p class="ck-a11y-panel__hint-text">${escapeHtml(issue.hint)}</p>
            </div>
        ` : '';
        const snippet = issue.snippet ? `<code class="ck-a11y-panel__snippet">${escapeHtml(issue.snippet)}</code>` : '';

        return `
            <h4 class="ck-a11y-panel__title">${escapeHtml(issueTitle(issue))}</h4>
            ${issue.message && issue.message !== issueTitle(issue) ? `<p class="ck-a11y-panel__message">${escapeHtml(issue.message)}</p>` : ''}
            ${hint}
            <div class="ck-a11y-panel__details">
                <div class="ck-a11y-panel__details-row">
                    <span class="ck-a11y-panel__details-key">${editorLabelHtml('rule', 'Rule')}</span>
                    <span class="ck-a11y-panel__details-val">${escapeHtml(issue.ruleId || '')}</span>
                </div>
                <div class="ck-a11y-panel__details-row">
                    <span class="ck-a11y-panel__details-key">${editorLabelHtml('location', 'Location')}</span>
                    <span class="ck-a11y-panel__details-val">${escapeHtml(issue.contextPath || editorLabel('htmlSource', 'HTML source'))}</span>
                </div>
                ${snippet}
            </div>
            ${this.aiSlotHtml(issue, index)}
            <div class="aqg-code-html-panel__actions">
                <button type="button" class="aqg-code-html-panel__action js-aqg-code-panel-locate" data-index="${index}">${editorLabelHtml('locate', 'Locate')}</button>
                <button type="button" class="aqg-code-html-panel__action js-aqg-code-panel-ignore" data-index="${index}">${editorLabelHtml('ignoreIssue', 'Ignore this issue')}</button>
            </div>
        `;
    }

    hideEditorPanel({ returnFocus = false } = {}) {
        if (!this.editorPanel || this.editorPanel.hidden) {
            return;
        }

        const focusWasInside = this.editorPanel.contains(document.activeElement);
        this.editorPanel.hidden = true;
        this.sheetSpacer?.remove();
        this.editorPanelAnchor?.setAttribute('aria-expanded', 'false');
        this.editorPanelAnchor = null;
        this.editorPanelAnchorKey = '';
        if (returnFocus && focusWasInside) {
            // The badge is not in the tab order; the code editor takes focus back with its cursor where it was.
            this.getCodeMirrorContent()?.focus();
        }
    }

    applyEditorDecorations(issues) {
        this.clearEditorDecorations();
        this.issueLines.clear();

        const html = this.getHtmlValue();
        if (!html || !issues.length) {
            if (this.aiApplied.size > 0) {
                this.scheduleEditorDecorationRefresh(0);
            }
            return;
        }

        const issuesWithLines = this.getIssuesWithLineNumbers(issues, html);
        issuesWithLines.forEach((issue) => {
            if (!this.issueLines.has(issue.lineNumber)) {
                this.issueLines.set(issue.lineNumber, []);
            }
            this.issueLines.get(issue.lineNumber).push(issue.index);
        });

        // Notify the official CodeMirror addon when it is available. We still keep
        // the DOM-level marker as a defensive fallback because TYPO3's CodeMirror
        // web component can be initialized before/after our FormEngine wizard,
        // depending on browser timing and cached backend assets.
        this.notifyCodeMirrorAddon(issuesWithLines);
        this.scheduleEditorDecorationRefresh(0);
    }

    getIssuesWithLineNumbers(issues, html) {
        this.exactIssueIndexes = new Set();
        return issues
            .map((issue, index) => {
                const exactLine = this.exactLineForIssue(issue, html);
                if (exactLine > 0) {
                    this.exactIssueIndexes.add(index);
                }
                return { ...issue, index, lineNumber: exactLine || this.findLineNumberForIssue(issue, html) };
            })
            .filter((issue) => issue.lineNumber > 0);
    }

    /** The document line of a rendered CodeMirror line, read from the editor itself. */
    exactVisibleLineNumber(view, lineElement) {
        if (!view || typeof view.posAtDOM !== 'function') {
            return 0;
        }
        try {
            return view.state.doc.lineAt(view.posAtDOM(lineElement, 0)).number;
        } catch (error) {
            return 0;
        }
    }

    /** The line of the finding's exact element when the live check identified it (see html-source.js). */
    exactLineForIssue(issue, html) {
        if (!issue?.aiTarget) {
            return 0;
        }
        const located = locateTarget(html, issue.aiTarget);
        return located.ok ? located.line : 0;
    }

    notifyCodeMirrorAddon(issuesWithLines) {
        const root = this.getCodeMirrorRoot();
        if (!root || root.dataset.aqgCodeMirrorAddon !== '1') {
            return false;
        }

        const eventInit = {
            bubbles: true,
            composed: true,
            detail: { issues: issuesWithLines },
        };
        root.dispatchEvent(new CustomEvent('aqg:updateIssues', eventInit));
        root.querySelector('.cm-content')?.dispatchEvent(new CustomEvent('aqg:updateIssues', eventInit));
        this.editorHost?.dispatchEvent(new CustomEvent('aqg:updateIssues', eventInit));
        return true;
    }

    scheduleEditorDecorationRefresh(delay = EDITOR_MARK_REFRESH_MS) {
        window.clearTimeout(this.editorMarkTimer);
        this.editorMarkTimer = window.setTimeout(() => this.refreshVisibleEditorDecorations(), delay);
    }

    ensureCodeMirrorDecorationStyles(content) {
        const rootNode = content?.getRootNode?.();
        if (!(rootNode instanceof ShadowRoot) || rootNode.__aqgDecorationStylesApplied) {
            return;
        }

        // Literal colours on purpose: these rules live in TYPO3's T3editor shadow root, where the AQG --aqi-* tokens
        // (declared on AQG roots only) do not exist. Badges carry their own fill, so they keep contrast in both schemes;
        // the focus ring is the AQG ring shape (#2563eb stays >= 3:1 on the light and the dark editor ground).
        const css = `
            .cm-line.aqg-cm-line {
                position: relative;
                padding-right: 76px !important;
                box-shadow: inset 3px 0 0 #5b6472;
                background: rgba(91, 100, 114, 0.08);
            }
            .cm-line.aqg-cm-line::after {
                content: attr(data-aqg-label);
                position: absolute;
                right: 8px;
                top: 2px;
                padding: 1px 6px 2px;
                border-radius: 999px;
                font: 700 10px/1.4 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                letter-spacing: .04em;
                color: #fff;
                background: #5b6472;
                pointer-events: none;
            }
            .cm-line.aqg-cm-line--critical { box-shadow: inset 3px 0 0 #c2410c; background: rgba(194, 65, 12, .09); }
            .cm-line.aqg-cm-line--critical::after { background: #c2410c; }
            .cm-line.aqg-cm-line--warning { box-shadow: inset 3px 0 0 #b7791f; background: rgba(183, 121, 31, .10); }
            .cm-line.aqg-cm-line--warning::after { color: #1e232a; background: #f3c66f; }
            .cm-line.aqg-cm-line--info { box-shadow: inset 3px 0 0 #2563eb; background: rgba(37, 99, 235, .08); }
            .cm-line.aqg-cm-line--info::after { background: #2563eb; }
            .aqg-cm-overlay { position: absolute; inset: 0; pointer-events: none; z-index: 20; }
            .aqg-cm-side-badge { position: absolute; right: 8px; display: inline-flex; align-items: center; gap: 4px; min-width: 36px; box-sizing: border-box; padding: 1px 7px 2px; border-radius: 999px; font: 700 10px/1.4 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing: .04em; color: #fff; background: #5b6472; pointer-events: auto; cursor: pointer; border: 0; }
            .aqg-cm-side-badge:focus-visible { outline: none; box-shadow: 0 0 0 1px #fff, 0 0 0 3px #2563eb; }
            .aqg-cm-side-badge--ai { padding-right: 3px; }
            .aqg-cm-side-badge__wand { display: inline-grid; place-items: center; width: 14px; height: 14px; border-radius: 50%; background: #1f2937; box-shadow: 0 0 0 1.5px #fff; }
            .aqg-cm-side-badge__wand::after { content: ""; width: 9px; height: 9px; background: #fff; -webkit-mask: ${WAND_MASK}; mask: ${WAND_MASK}; }
            .aqg-cm-side-badge--critical { background: #c2410c; }
            .aqg-cm-side-badge--warning { color: #1e232a; background: #f3c66f; }
            .aqg-cm-side-badge--info { background: #2563eb; }
            .cm-line.aqg-cm-line--inserted { box-shadow: inset 3px 0 0 #2563eb; background: rgba(37, 99, 235, .06); }
            .cm-line.aqg-cm-line--inserted::after { color: #2563eb; background: #fff; outline: 1px dashed #2563eb; }
            .aqg-cm-side-badge--inserted { color: #2563eb; background: #fff; border: 1px dashed #2563eb; }
            .aqg-cm-side-badge--has-inserted { outline: 1px dashed #2563eb; outline-offset: 1px; }
        `;

        try {
            if ('adoptedStyleSheets' in rootNode && 'CSSStyleSheet' in window) {
                const sheet = new CSSStyleSheet();
                sheet.replaceSync(css);
                rootNode.adoptedStyleSheets = [...rootNode.adoptedStyleSheets, sheet];
                rootNode.__aqgDecorationStylesApplied = true;
            }
        } catch (error) {
            // Decoration styles are progressive enhancement only. If a browser or
            // CSP setup rejects constructable stylesheets, the issue list and
            // global backend stylesheet still remain available.
            rootNode.__aqgDecorationStylesApplied = true;
        }
    }

    refreshVisibleEditorDecorations() {
        const content = this.getCodeMirrorContent();
        if (!content) {
            return;
        }

        this.ensureCodeMirrorDecorationStyles(content);
        this.clearDomEditorDecorations();

        const root = this.getCodeMirrorRoot();
        const overlay = this.ensureCodeMirrorOverlay(root);
        const rootRect = root?.getBoundingClientRect();
        const lineElements = Array.from(content.querySelectorAll('.cm-line'));
        const visibleLineNumbers = this.resolveVisibleLineNumbers(lineElements);

        const view = this.getEditorView();
        const appliedLines = this.appliedLines();
        const appliedKeys = new Set(Array.from(appliedLines.values()).flat());
        lineElements.forEach((lineElement, visibleIndex) => {
            const lineNumber = this.exactVisibleLineNumber(view, lineElement)
                || visibleLineNumbers.get(lineElement)
                || this.resolveLineNumberForVisibleLine(lineElement, visibleIndex);
            // Issues the live check identified exactly (aiTarget) belong to their own line only, so identical
            // duplicate lines keep their own marker. The others keep the visible-text matching: line-number mapping
            // can be wrong in virtualized CodeMirror views with repeated paragraphs.
            const exactIndexes = (this.issueLines.get(lineNumber) || []).filter((index) => this.exactIssueIndexes.has(index));
            const indexesByVisibleText = this.findIssueIndexesForVisibleLine(lineElement).filter((index) => !this.exactIssueIndexes.has(index));
            const fallback = (this.issueLines.get(lineNumber) || []).filter((index) => !this.exactIssueIndexes.has(index));
            // An applied finding's own (not yet re-checked) issue gives way to its "unsaved" marker.
            const indexes = [...exactIndexes, ...(indexesByVisibleText.length ? indexesByVisibleText : fallback)]
                .filter((index) => !appliedKeys.has(EditorAiSession.keyFor(this.displayedIssues[index] ?? {})));
            const appliedHere = appliedLines.get(lineNumber) ?? [];
            if (!indexes.length && appliedHere.length === 0) {
                return;
            }
            if (!indexes.length) {
                // D2 "In source, unsaved": a dashed blue line badge that opens the applied change (with its Undo).
                lineElement.classList.add('aqg-cm-line', 'aqg-cm-line--inserted');
                lineElement.dataset.aqgLabel = 'AQG';
                if (overlay && rootRect) {
                    const badge = document.createElement('button');
                    badge.type = 'button';
                    badge.tabIndex = -1;
                    badge.className = 'aqg-cm-side-badge aqg-cm-side-badge--inserted';
                    badge.dataset.aqgAppliedKey = appliedHere[0];
                    badge.textContent = 'AQG';
                    badge.setAttribute('aria-haspopup', 'dialog');
                    badge.setAttribute('aria-expanded', this.editorPanelAnchorKey === `applied:${appliedHere[0]}` && !this.editorPanel?.hidden ? 'true' : 'false');
                    badge.setAttribute('aria-label', editorLabel('aiChipInserted', 'AI change applied, not saved. Show details.'));
                    badge.style.top = `${Math.max(0, lineElement.getBoundingClientRect().top - rootRect.top + 2)}px`;
                    overlay.appendChild(badge);
                }
                return;
            }

            const strongestSeverity = indexes
                .map((index) => normalizeSeverity(this.displayedIssues[index]?.severity))
                .sort((a, b) => severityRank(b) - severityRank(a))[0] || 'info';

            lineElement.classList.add('aqg-cm-line', `aqg-cm-line--${strongestSeverity}`);
            lineElement.dataset.aqgIssueIndexes = indexes.join(',');
            lineElement.dataset.aqgLabel = indexes.length === 1 ? 'AQG' : `AQG ${indexes.length}`;

            if (overlay && rootRect) {
                const lineRect = lineElement.getBoundingClientRect();
                const badge = document.createElement('button');
                badge.type = 'button';
                badge.tabIndex = -1;
                badge.className = `aqg-cm-side-badge aqg-cm-side-badge--${strongestSeverity}`;
                badge.dataset.aqgIssueIndexes = indexes.join(',');
                badge.textContent = indexes.length === 1 ? 'AQG' : `AQG ${indexes.length}`;
                badge.setAttribute('aria-haspopup', 'dialog');
                badge.setAttribute('aria-expanded', this.editorPanelAnchorKey === badge.dataset.aqgIssueIndexes && !this.editorPanel?.hidden ? 'true' : 'false');
                badge.setAttribute('aria-label', indexes.length === 1
                    ? editorLabel('plainBadgeOne', 'Show the accessibility issue on this line')
                    : editorLabel('plainBadgeMany', 'Show the %d accessibility issues on this line').replace('%d', String(indexes.length)));
                if (indexes.some((index) => EditorAiSession.offersFix(this.displayedIssues[index], this.aiConfig))) {
                    badge.classList.add('aqg-cm-side-badge--ai');
                    badge.setAttribute('aria-label', `${badge.textContent}, ${badge.getAttribute('aria-label')}. ${editorLabel('aiFixAvailable', 'AI fix available')}`);
                    badge.insertAdjacentHTML('beforeend', '<span class="aqg-cm-side-badge__wand" aria-hidden="true"></span>');
                }
                if (appliedHere.length > 0) {
                    badge.classList.add('aqg-cm-side-badge--has-inserted');
                    badge.setAttribute('aria-label', `${badge.getAttribute('aria-label')}. ${editorLabel('aiChipInserted', 'AI change applied, not saved. Show details.')}`);
                }
                badge.style.top = `${Math.max(0, lineRect.top - rootRect.top + 2)}px`;
                overlay.appendChild(badge);
            }
        });
    }

    ensureCodeMirrorOverlay(root) {
        if (!root) {
            return null;
        }

        if (window.getComputedStyle(root).position === 'static') {
            root.style.position = 'relative';
        }

        let overlay = root.querySelector(':scope > .aqg-cm-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'aqg-cm-overlay';
            root.appendChild(overlay);
        }

        overlay.innerHTML = '';
        return overlay;
    }

    clearDomEditorDecorations() {
        const content = this.getCodeMirrorContent();
        const root = this.getCodeMirrorRoot();

        root?.querySelector(':scope > .aqg-cm-overlay')?.remove();

        if (!content) {
            return;
        }

        content.querySelectorAll('.cm-line.aqg-cm-line').forEach((lineElement) => {
            lineElement.classList.remove(
                'aqg-cm-line',
                'aqg-cm-line--critical',
                'aqg-cm-line--warning',
                'aqg-cm-line--info',
                'aqg-cm-line--inserted',
                'aqg-cm-line-reveal'
            );
            delete lineElement.dataset.aqgIssueIndexes;
            delete lineElement.dataset.aqgLabel;
        });
    }

    clearEditorDecorations() {
        const root = this.getCodeMirrorRoot();
        if (root?.dataset.aqgCodeMirrorAddon === '1') {
            root.dispatchEvent(new CustomEvent('aqg:clearIssues', { bubbles: true, composed: true }));
            root.querySelector('.cm-content')?.dispatchEvent(new CustomEvent('aqg:clearIssues', { bubbles: true, composed: true }));
            this.editorHost?.dispatchEvent(new CustomEvent('aqg:clearIssues', { bubbles: true, composed: true }));
        }

        this.clearDomEditorDecorations();
    }

    resolveVisibleLineNumbers(lineElements) {
        const result = new Map();
        const sourceLines = this.getHtmlValue().split(/\r?\n/);
        const normalizedSourceLines = sourceLines.map((line) => this.normalizeCodeMirrorLineText(line));
        let searchFrom = 0;

        lineElements.forEach((lineElement, visibleIndex) => {
            const normalizedVisibleLine = this.normalizeCodeMirrorLineText(lineElement.textContent || '');

            if (normalizedVisibleLine === '') {
                const previousLineNumber = visibleIndex > 0 ? result.get(lineElements[visibleIndex - 1]) : null;
                if (previousLineNumber && previousLineNumber < sourceLines.length) {
                    result.set(lineElement, previousLineNumber + 1);
                    searchFrom = previousLineNumber;
                }
                return;
            }

            let foundIndex = -1;
            for (let i = searchFrom; i < normalizedSourceLines.length; i++) {
                if (normalizedSourceLines[i] === normalizedVisibleLine) {
                    foundIndex = i;
                    break;
                }
            }

            if (foundIndex === -1) {
                foundIndex = normalizedSourceLines.findIndex((line) => line === normalizedVisibleLine);
            }

            if (foundIndex !== -1) {
                result.set(lineElement, foundIndex + 1);
                searchFrom = foundIndex + 1;
            }
        });

        return result;
    }

    normalizeCodeMirrorLineText(value) {
        return String(value || '')
            .replace(/AQG\s*\d*/g, '')
            .replace(/\u00a0/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    resolveLineNumberForVisibleLine(lineElement, visibleIndex) {
        const content = this.getCodeMirrorContent();
        const lineElements = Array.from(content?.querySelectorAll('.cm-line') || []);
        const firstVisibleText = lineElements[0]?.textContent || '';
        const sourceLines = this.getHtmlValue().split(/\r?\n/);
        let offset = 0;

        if (firstVisibleText.trim() !== '') {
            const matchIndex = sourceLines.findIndex((line) => line.trim() === firstVisibleText.trim());
            if (matchIndex >= 0) {
                offset = matchIndex;
            }
        }

        return offset + visibleIndex + 1;
    }

    findIssueIndexesForVisibleLine(lineElement) {
        const visibleLine = this.normalizeSourceForSearch(
            decodeHtmlEntities(lineElement.textContent || '').replace(/AQG\s*\d*/g, '')
        );

        if (visibleLine === '') {
            return [];
        }

        const indexes = [];
        this.displayedIssues.forEach((issue, index) => {
            const signatures = this.getIssueLineSignatures(issue);
            if (signatures.some((signature) => signature !== '' && (visibleLine.includes(signature) || signature.includes(visibleLine)))) {
                indexes.push(index);
            }
        });

        return indexes;
    }

    getIssueLineSignatures(issue) {
        const values = [issue.snippet, issue.contextSnippet]
            .map((value) => decodeHtmlEntities(value || ''))
            .filter(Boolean);

        const signatures = [];
        values.forEach((value) => {
            const firstMeaningfulLine = String(value)
                .split(/\r?\n/)
                .map((line) => this.normalizeSourceForSearch(line))
                .find(Boolean);

            if (firstMeaningfulLine) {
                signatures.push(firstMeaningfulLine);
            }

            const openingTagMatch = String(value).match(/<([a-z][a-z0-9:-]*)(?:\s[^>]*)?>/i);
            const openingTag = openingTagMatch?.[0] || '';
            const tagName = String(openingTagMatch?.[1] || '').toLowerCase();
            // Do not use generic opening tags for links/images as signatures.
            // Example: <a href="/kontakt"><img ...></a> and <a href="/kontakt">Kontakt...</a>
            // share the same opening tag but represent different source lines.
            if (openingTag && !['a', 'img'].includes(tagName)) {
                signatures.push(this.normalizeSourceForSearch(openingTag));
            }
        });

        return [...new Set(signatures)];
    }

    findIndexOutsideHtmlComments(html, needle) {
        const source = String(html || '');
        const search = String(needle || '');
        if (search === '') {
            return -1;
        }

        let start = source.indexOf(search);
        while (start !== -1) {
            if (!this.isIndexInsideHtmlComment(source, start)) {
                return start;
            }
            start = source.indexOf(search, start + Math.max(1, search.length));
        }

        return -1;
    }

    isIndexInsideHtmlComment(html, index) {
        const before = String(html || '').slice(0, Math.max(0, index));
        const lastOpen = before.lastIndexOf('<!--');
        if (lastOpen === -1) {
            return false;
        }

        const lastClose = before.lastIndexOf('-->');
        return lastClose < lastOpen;
    }

    findLineNumberForIssue(issue, html) {
        const candidates = [
            issue.snippet,
            issue.contextSnippet,
            issue.selector,
            issue.contextPath,
        ]
            .map((value) => String(value || '').trim())
            .filter(Boolean);

        for (const candidate of candidates) {
            const decoded = decodeHtmlEntities(candidate);
            const variants = [candidate, decoded, this.normalizeSnippet(candidate), this.normalizeSnippet(decoded)]
                .filter(Boolean);

            for (const variant of variants) {
                const start = this.findIndexOutsideHtmlComments(html, variant);
                if (start !== -1) {
                    return html.slice(0, start).split(/\r?\n/).length;
                }
            }
        }

        const contextLine = this.findLineNumberByContextPath(issue.contextPath, html);
        if (contextLine > 0) {
            return contextLine;
        }

        const normalizedHtml = this.normalizeSourceForSearch(html);
        for (const candidate of candidates) {
            const decoded = decodeHtmlEntities(candidate);
            const variants = [this.normalizeSourceForSearch(candidate), this.normalizeSourceForSearch(decoded)]
                .filter(Boolean);

            for (const variant of variants) {
                const start = normalizedHtml.indexOf(variant);
                if (start !== -1) {
                    const prefix = normalizedHtml.slice(0, start);
                    const originalPrefixLength = Math.min(html.length, prefix.length);
                    return html.slice(0, originalPrefixLength).split(/\r?\n/).length;
                }
            }
        }

        return this.findLineNumberByRule(issue, html);
    }

    normalizeSnippet(value) {
        return String(value || '')
            .replace(/\s+/g, ' ')
            .replace(/\s+\/?>/g, '>')
            .trim();
    }

    normalizeSourceForSearch(value) {
        return String(value || '')
            .replace(/\s+/g, ' ')
            .replace(/\s+\/>/g, '>')
            .replace(/\s+>/g, '>')
            .trim();
    }

    findLineNumberByContextPath(contextPath, html) {
        const path = String(contextPath || '').trim();
        if (path === '') {
            return 0;
        }

        const segments = path
            .split('>')
            .map((segment) => segment.trim())
            .filter(Boolean)
            .map((segment) => this.parseContextSegment(segment))
            .filter(Boolean);

        if (!segments.length) {
            return 0;
        }

        let from = 0;
        let to = html.length;
        let found = -1;

        for (const segment of segments) {
            const occurrence = segment.index || 1;
            found = this.findNthTagIndex(html, segment.tag, occurrence, from, to);

            if (found === -1 && segment.index === null) {
                found = this.findNthTagIndex(html, segment.tag, 1, from, to);
            }

            if (found === -1) {
                return 0;
            }

            from = found + 1;
            const closeIndex = this.findClosingTagIndex(html, segment.tag, found);
            if (closeIndex !== -1) {
                to = closeIndex;
            }
        }

        return found >= 0 ? html.slice(0, found).split(/\r?\n/).length : 0;
    }

    parseContextSegment(segment) {
        const match = String(segment || '').match(/^([a-z][a-z0-9:-]*)(?:\[(\d+)\])?/i);
        if (!match) {
            return null;
        }

        return {
            tag: match[1].toLowerCase(),
            index: match[2] ? parseInt(match[2], 10) : null,
        };
    }

    findNthTagIndex(html, tag, occurrence, from = 0, to = null) {
        const source = String(html || '');
        const end = to === null ? source.length : Math.min(to, source.length);
        const expression = new RegExp(`<${tag}(?:\\s|>|/)`, 'ig');
        expression.lastIndex = Math.max(0, from);
        let count = 0;
        let match;

        while ((match = expression.exec(source)) !== null) {
            if (match.index >= end) {
                return -1;
            }
            if (this.isIndexInsideHtmlComment(source, match.index)) {
                continue;
            }
            count++;
            if (count === occurrence) {
                return match.index;
            }
        }

        return -1;
    }

    findClosingTagIndex(html, tag, from) {
        const source = String(html || '');
        const expression = new RegExp(`</${tag}\\s*>`, 'ig');
        expression.lastIndex = Math.max(0, from);
        const match = expression.exec(source);
        return match ? match.index : -1;
    }

    findLineNumberByRule(issue, html) {
        const ruleId = String(issue.ruleId || '');
        const lines = html.split(/\r?\n/);
        const patterns = [
            [/img_alt|image_in_link/, /<img\b/i],
            [/iframe/, /<iframe\b/i],
            [/marquee|blink/, /<(marquee|blink)\b/i],
            [/duplicate_id/, /\sid=/i],
            [/heading/, /<h[1-6]\b/i],
            [/table/, /<table\b/i],
            [/link|empty_link/, /<a\b/i],
            [/button/, /<button\b/i],
            [/svg/, /<svg\b/i],
        ];

        const pattern = patterns.find(([rulePattern]) => rulePattern.test(ruleId))?.[1];
        if (!pattern) {
            return 0;
        }

        const index = lines.findIndex((line) => pattern.test(line));
        return index >= 0 ? index + 1 : 0;
    }

    collapseIssueListForLocate() {
        this.container
            .querySelectorAll('.aqg-plain-html-a11y__details-list[open], .aqg-plain-html-a11y__issue[open]')
            .forEach((details) => details.removeAttribute('open'));
    }

    locateIssue(issue) {
        if (!issue || !this.textarea) {
            return;
        }

        const value = this.getHtmlValue();
        const lineNumber = this.findLineNumberForIssue(issue, value);
        const selection = this.findSelectionForIssue(issue, value, lineNumber);

        this.collapseIssueListForLocate();

        const reveal = () => {
            this.scrollCodeMirrorLineIntoView(lineNumber, selection);
            this.textarea.focus({ preventScroll: true });
            if (selection.start >= 0 && selection.end > selection.start) {
                this.textarea.setSelectionRange(selection.start, selection.end);
            }
        };

        this.editorHost?.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'smooth' });
        window.setTimeout(reveal, 180);
    }

    findSelectionForIssue(issue, value, lineNumber = 0) {
        const candidates = [issue.snippet, issue.contextSnippet]
            .map((candidate) => String(candidate || '').trim())
            .filter(Boolean);

        const lineRange = lineNumber > 0 ? this.getLineCharacterRange(value, lineNumber) : null;

        for (const candidate of candidates) {
            const variants = [candidate, decodeHtmlEntities(candidate), this.normalizeSnippet(candidate), this.normalizeSnippet(decodeHtmlEntities(candidate))]
                .filter(Boolean);
            for (const variant of variants) {
                const boundedStart = lineRange ? value.indexOf(variant, lineRange.start) : -1;
                if (boundedStart !== -1 && (!lineRange || boundedStart < lineRange.end)) {
                    return { start: boundedStart, end: boundedStart + variant.length };
                }

                const globalStart = this.findIndexOutsideHtmlComments(value, variant);
                if (globalStart !== -1) {
                    return { start: globalStart, end: globalStart + variant.length };
                }
            }
        }

        if (lineRange) {
            return lineRange;
        }

        return { start: 0, end: 0 };
    }

    getLineCharacterRange(value, lineNumber) {
        const lines = String(value || '').split(/\r?\n/);
        if (lineNumber <= 0 || lineNumber > lines.length) {
            return null;
        }

        let start = 0;
        for (let i = 0; i < lineNumber - 1; i++) {
            start += lines[i].length + 1;
        }

        return { start, end: start + lines[lineNumber - 1].length };
    }

    scrollCodeMirrorLineIntoView(lineNumber, selection = null) {
        if (lineNumber <= 0) {
            return;
        }

        const root = this.getCodeMirrorRoot();
        if (root?.dataset.aqgCodeMirrorAddon === '1') {
            root.dispatchEvent(new CustomEvent('aqg:revealLine', {
                bubbles: true,
                composed: true,
                detail: { lineNumber, selection },
            }));
            window.setTimeout(() => this.flashVisibleCodeMirrorLine(lineNumber), 120);
            return;
        }

        const content = this.getCodeMirrorContent();
        const scroller = root?.querySelector('.cm-scroller');
        if (!content || !scroller) {
            return;
        }

        const computedLineHeight = parseFloat(window.getComputedStyle(content).lineHeight || '18') || 18;
        const targetTop = Math.max(0, ((lineNumber - 1) * computedLineHeight) - (scroller.clientHeight / 2) + computedLineHeight);
        scroller.scrollTop = targetTop;
        this.scheduleEditorDecorationRefresh(60);
        this.editorHost?.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'smooth' });
        window.setTimeout(() => this.flashVisibleCodeMirrorLine(lineNumber), 220);
    }

    flashVisibleCodeMirrorLine(lineNumber) {
        const content = this.getCodeMirrorContent();
        if (!content) {
            return;
        }

        const lineElements = Array.from(content.querySelectorAll('.cm-line'));
        const visibleLineNumbers = this.resolveVisibleLineNumbers(lineElements);
        let target = null;

        for (const lineElement of lineElements) {
            const currentLine = visibleLineNumbers.get(lineElement);
            if (currentLine === lineNumber) {
                target = lineElement;
                break;
            }
        }

        if (!target) {
            target = lineElements.find((lineElement, index) => this.resolveLineNumberForVisibleLine(lineElement, index) === lineNumber) || null;
        }

        if (!target) {
            return;
        }

        target.classList.add('aqg-cm-line-reveal');
        window.setTimeout(() => target.classList.remove('aqg-cm-line-reveal'), 1800);
    }

    isAiEligible(issue) {
        return EditorAiSession.isEligible(issue, this.aiConfig)
            || this.aiSessions.get(EditorAiSession.keyFor(issue))?.hasStaged === true;
    }

    /** AI slot of one issue in the line panel, or one quiet line saying why AI is not offered. */
    aiSlotHtml(issue, index) {
        if (!issue) {
            return '';
        }
        if (this.isAiEligible(issue)) {
            return `<div class="aqg-ai-slot" data-aqg-ai-slot="${index}"></div>`;
        }
        if (!issue.aiTarget || !(this.aiConfig.rules ?? []).includes(issue.ruleId)) {
            return '';
        }
        const quiet = EditorAiSession.quietLine(this.aiConfig);
        const line = quiet ? editorLabel(...quiet) : '';
        return line !== '' ? `<p class="aqg-ai-quiet">${escapeHtml(line)}</p>` : '';
    }

    mountAiSessions() {
        this.editorPanel?.querySelectorAll('[data-aqg-ai-slot]').forEach((slot) => {
            const issue = this.displayedIssues[parseInt(slot.getAttribute('data-aqg-ai-slot') || '-1', 10)];
            if (issue) {
                this.aiSessionFor(issue).mount(slot);
            }
        });
    }

    aiSessionFor(issue) {
        const key = EditorAiSession.keyFor(issue);
        let session = this.aiSessions.get(key);
        if (session) {
            session.updateIssue(issue);
            return session;
        }
        session = new EditorAiSession({
            issue,
            adapter: this.aiAdapter(issue),
            config: this.aiConfig,
            label: editorLabel,
            announce: (text) => this.announce(text),
            onApplied: (result) => {
                reportInserted(Number(result.suggestion?.stagedItem ?? 0));
                this.onAiApplied(key, result);
            },
            onUndone: () => {
                this.aiApplied.delete(key);
                this.aiOutcome?.removed(key);
            },
        });
        this.aiSessions.set(key, session);
        return session;
    }

    /** An applied change (popover or review dialog): its outcome, its marker and the bar's Undo of the last one. */
    onAiApplied(key, result) {
        const adapter = result.adapter ?? this.aiSessions.get(key)?.adapter ?? null;
        this.aiApplied.set(key, { issue: result.issue, adapter });
        if (this.aiOutcome) {
            this.aiOutcome.onUndoLast = () => {
                const session = this.aiSessions.get(key);
                if (session?.state === 'applied') {
                    session.undo();
                } else if (adapter?.undo()) {
                    this.aiApplied.delete(key);
                    this.aiOutcome?.removed(key);
                    this.announce(editorLabel('aiAnnounceUndone', 'Change undone.'));
                }
            };
        }
        this.aiOutcome?.added({
            id: key,
            ruleId: result.issue.ruleId,
            expected: result.expected,
            suggestedAt: Number(result.suggestion?.serverTime ?? this.serverTime ?? 0),
        });
    }

    /** Document lines of applied, unsaved changes: line → finding keys. */
    appliedLines() {
        const lines = new Map();
        for (const [key, applied] of this.aiApplied.entries()) {
            const line = this.aiOutcome?.isApplied(key) ? Number(applied.adapter?.appliedLine?.() ?? 0) : 0;
            if (line > 0) {
                lines.set(line, [...(lines.get(line) ?? []), key]);
            }
        }
        return lines;
    }

    syncAiSessions(issues) {
        const keys = new Set(issues.map((issue) => EditorAiSession.keyFor(issue)));
        for (const [key, session] of this.aiSessions.entries()) {
            if (!keys.has(key) && !session.isPinned) {
                session.destroy();
                this.aiSessions.delete(key);
            } else if (keys.has(key)) {
                session.updateIssue(issues.find((candidate) => EditorAiSession.keyFor(candidate) === key));
            }
        }
    }

    /** CodeMirror 6 EditorView of TYPO3's code editor element (property `editorView`, TYPO3 13 and 14). */
    getEditorView() {
        const view = this.editorHost?.editorView ?? null;
        return view && view.state && typeof view.dispatch === 'function' ? view : null;
    }

    /**
     * Tracks source ranges through CodeMirror transactions: a locked suggestion follows edits elsewhere and becomes
     * outdated when an edit touches its range. Without CodeMirror (plain textarea) any change outdates it.
     */
    async installAiTracking(view) {
        if (this.aiTrackingInstalled || !view) {
            return this.aiTrackingInstalled;
        }
        try {
            // TYPO3's import map provides CodeMirror 6 to every code editor; the specifiers stay runtime-resolved.
            const load = (specifier) => import(/* @vite-ignore */ specifier);
            const [{ StateEffect }, { EditorView }] = await Promise.all([load('@codemirror/state'), load('@codemirror/view')]);
            view.dispatch({
                effects: StateEffect.appendConfig.of(EditorView.updateListener.of((update) => {
                    if (!update.docChanged) {
                        return;
                    }
                    this.aiTrackers.forEach((tracker) => {
                        if (update.changes.touchesRange(tracker.from, tracker.to)) {
                            tracker.touched = true;
                        }
                        tracker.from = update.changes.mapPos(tracker.from, 1);
                        tracker.to = update.changes.mapPos(tracker.to, -1);
                    });
                })),
            });
            this.aiTrackingInstalled = true;
        } catch (error) {
            this.aiTrackingInstalled = false;
        }
        return this.aiTrackingInstalled;
    }

    setSourceValue(change) {
        // A read-only field is still submitted by TYPO3: AQG never writes into it (an approved queue change included).
        if (this.container.dataset.readOnly === '1') {
            return false;
        }
        const view = this.getEditorView();
        if (view) {
            view.dispatch({
                changes: { from: change.from, to: change.to, insert: change.insert },
                userEvent: 'input.aqg',
                scrollIntoView: true,
            });
            return true;
        }
        if (!this.textarea) {
            return false;
        }
        this.textarea.focus({ preventScroll: true });
        this.textarea.setSelectionRange(change.from, change.to);
        // execCommand keeps the change in the browser's own undo history of the textarea.
        let inserted = false;
        try {
            inserted = document.execCommand('insertText', false, change.insert);
        } catch (error) {
            inserted = false;
        }
        if (!inserted) {
            this.textarea.setRangeText(change.insert, change.from, change.to, 'end');
            this.textarea.dispatchEvent(new Event('input', { bubbles: true }));
        }
        this.textarea.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    }

    /** Editor-specific operations of an AI session in the plain HTML editor. */
    aiAdapter(issue) {
        let lock = null;
        let applied = null;
        const release = () => {
            if (lock?.tracker) {
                this.aiTrackers.delete(lock.tracker);
            }
            lock = null;
        };
        const currentLocation = () => {
            if (!lock) {
                return { ok: false, reason: 'stale' };
            }
            const source = this.getHtmlValue();
            if (lock.tracker) {
                if (lock.tracker.touched) {
                    return { ok: false, reason: 'stale' };
                }
                return locateTargetAt(source, lock.tracker.from, lock.target);
            }
            let reached = source;
            for (let step = 0; reached !== lock.source && this.aiAppliedFrom.has(reached) && step < 50; step++) {
                reached = this.aiAppliedFrom.get(reached);
            }
            return reached === lock.source ? locateTarget(source, lock.target) : { ok: false, reason: 'stale' };
        };

        return {
            type: 'html',
            requestPayload: (current) => ({
                table: 'tt_content',
                recordUid: this.recordUid,
                fieldName: this.fieldName,
                html: this.getHtmlValue(),
                ruleId: current.ruleId,
                contextPath: current.aiTarget?.contextPath ?? '',
                occurrence: current.aiTarget?.occurrence ?? -1,
            }),
            lock: (current) => {
                release();
                const source = this.getHtmlValue();
                const located = locateTarget(source, current.aiTarget);
                if (!located.ok) {
                    return located;
                }
                const view = this.getEditorView();
                const end = located.content ? located.content.closeEnd : located.element.end;
                // Positions are tracked only once the CodeMirror listener runs; until then any change outdates the lock.
                const tracker = view && this.aiTrackingInstalled ? { from: located.element.start, to: end, touched: false } : null;
                if (tracker) {
                    this.aiTrackers.add(tracker);
                } else if (view) {
                    this.installAiTracking(view);
                }
                lock = { target: current.aiTarget, source, tracker, line: located.line };
                return { ok: true, line: located.line };
            },
            isStale: () => !currentLocation().ok,
            preview: (value) => {
                const located = currentLocation();
                if (!located.ok) {
                    return { kind: 'source', ok: false, reason: located.reason, checks: [] };
                }
                const source = this.getHtmlValue();
                const change = buildChange(source, located, lock.target, value);
                const check = verifyChange(source, change, located, lock.target, value);
                return { kind: 'source', ok: check.ok, checks: check.checks, diff: sourceDiff(source, change, located) };
            },
            apply: (value) => {
                const located = currentLocation();
                if (!located.ok) {
                    return { ok: false, reason: located.reason === 'stale' ? 'stale' : located.reason };
                }
                const source = this.getHtmlValue();
                const change = buildChange(source, located, lock.target, value);
                const check = verifyChange(source, change, located, lock.target, value);
                if (!check.ok || !this.setSourceValue(change)) {
                    return { ok: false, reason: 'apply_rejected' };
                }
                if (this.getHtmlValue() !== check.result) {
                    // The editor did not take the change exactly: take back what was inserted, report nothing applied.
                    const now = this.getHtmlValue();
                    if (now.slice(change.from, change.from + change.insert.length) === change.insert) {
                        this.setSourceValue({ from: change.from, to: change.from + change.insert.length, insert: source.slice(change.from, change.to) });
                    }
                    return { ok: false, reason: 'apply_rejected' };
                }
                const elements = tokenizeHtml(check.result).elements;
                const after = elements.find((element) => element.start === located.element.start);
                const content = after ? plainContentRange(check.result, after) : null;
                const sameTag = elements.filter((element) => element.tag === lock.target.tag);
                applied = { change, before: source, after: check.result, removed: source.slice(change.from, change.to) };
                this.aiAppliedFrom.set(check.result, source);
                this.scheduleValidation(VALIDATION_FAST_DEBOUNCE_MS);
                return {
                    ok: true,
                    expected: {
                        tag: lock.target.tag,
                        attributes: after ? attributeMap(after) : {},
                        text: content ? content.text : (lock.target.operation === 'text' ? value : lock.target.element?.text ?? ''),
                        occurrence: sameTag.indexOf(after),
                        total: sameTag.length,
                    },
                };
            },
            undo: () => {
                if (!applied || this.getHtmlValue() !== applied.after) {
                    // Something else changed after Apply: the editor's own Ctrl+Z is the safe way back.
                    this.announce(editorLabel('aiUndoUseEditor', 'The source changed after applying. Use Ctrl+Z in the editor.'));
                    return false;
                }
                const inverse = { from: applied.change.from, to: applied.change.from + applied.change.insert.length, insert: applied.removed };
                this.setSourceValue(inverse);
                applied = null;
                const relocated = locateTarget(this.getHtmlValue(), issue.aiTarget);
                release();
                if (relocated.ok) {
                    const view = this.getEditorView();
                    const tracker = view && this.aiTrackingInstalled ? { from: relocated.element.start, to: relocated.content ? relocated.content.closeEnd : relocated.element.end, touched: false } : null;
                    if (tracker) {
                        this.aiTrackers.add(tracker);
                    }
                    lock = { target: issue.aiTarget, source: this.getHtmlValue(), tracker, line: relocated.line };
                }
                this.scheduleValidation(VALIDATION_FAST_DEBOUNCE_MS);
                return true;
            },
            release,
            // The document line of the applied change (for its "unsaved" marker), 0 when nothing is applied.
            appliedLine: () => (applied ? this.getHtmlValue().slice(0, applied.change.from).split('\n').length : 0),
            // Rule-based level changes are a CKEditor feature (its heading list); in the source the editor changes the tag.
            canRuleBasedFix: () => false,
            ruleBasedFixSupported: false,
            describeTarget: (current) => {
                const located = lock ? currentLocation() : null;
                const line = located?.ok ? located.line : (lock?.line ?? this.exactLineForIssue(current, this.getHtmlValue()));
                const what = current.aiTarget?.operation === 'attribute'
                    ? editorLabel('aiRangeAttribute', 'L%1$d · <%2$s> · %3$s only').replace('%3$s', current.aiTarget.attribute)
                    : editorLabel('aiRangeText', 'L%1$d · <%2$s> · text only');
                return `${what.replace('%1$d', String(line)).replace('%2$s', current.aiTarget?.tag ?? '')} · ${editorLabel('aiOnlyThisRange', 'Only this range changes')}`;
            },
        };
    }

    /** Issues with an actionable AI fix, in source order. */
    reviewableIssues(issues) {
        const html = this.getHtmlValue();
        return (issues ?? [])
            .filter((issue) => EditorAiSession.offersFix(issue, this.aiConfig) && issue.aiTarget?.operation !== 'heading_level')
            .map((issue) => ({ issue, line: this.exactLineForIssue(issue, html) }))
            .filter((entry) => entry.line > 0)
            .sort((a, b) => a.line - b.line)
            .map((entry) => entry.issue);
    }

    openReviewDialog(invoker) {
        this.hideEditorPanel();
        new EditorAiReviewDialog({
            entries: this.reviewableIssues(this.displayedIssues).map((issue) => ({ issue, adapter: this.aiAdapter(issue) })),
            config: this.aiConfig,
            label: editorLabel,
            announce: (text) => this.announce(text),
            editorType: 'html',
            onApplied: (result) => this.onAiApplied(EditorAiSession.keyFor(result.issue), result),
            returnFocus: () => (invoker.isConnected ? invoker : null)
                // The re-rendered "Review fixes" first (a selector list matches in document order, not list order).
                ?? this.summary?.querySelector('.js-aqg-plain-html-review')
                ?? this.summary?.querySelector('.js-aqg-plain-html-next')
                ?? this.getCodeMirrorContent(),
        }).open();
    }

    installAiOutcome() {
        if (!this.summary) {
            return;
        }
        // Visually hidden (absolutely positioned): announcements only, no layout of its own.
        const live = document.createElement('span');
        live.className = 'aqg-ai-live';
        live.setAttribute('aria-live', 'polite');
        live.setAttribute('role', 'status');
        this.summary.insertAdjacentElement('afterend', live);
        this.aiLiveRegion = live;
        this.aiOutcome = new EditorAiOutcome({
            recordUid: this.recordUid,
            fieldName: this.fieldName,
            label: editorLabel,
            announce: (text) => this.announce(text),
            editorType: 'html',
            onChange: () => {
                this.rerenderSummary();
                this.scheduleEditorDecorationRefresh(0);
            },
        });
        this.aiOutcome.bindForm(this.container.closest('form') ?? this.textarea?.form ?? null);
        this.aiOutcome.verifyOnLoad();
    }

    announce(text) {
        if (!this.aiLiveRegion || !text) {
            return;
        }
        this.aiLiveRegion.textContent = '';
        window.setTimeout(() => {
            if (this.aiLiveRegion) {
                this.aiLiveRegion.textContent = text;
            }
        }, 30);
    }

    cursorLine() {
        const view = this.getEditorView();
        if (view) {
            return view.state.doc.lineAt(view.state.selection.main.head).number;
        }
        const position = this.textarea?.selectionStart ?? 0;
        return this.getHtmlValue().slice(0, position).split('\n').length;
    }

    /** Opens the line panel of the next (or previous) line with issues, counted from the cursor or open panel. */
    openAdjacentIssue(direction) {
        const lines = Array.from(this.issueLines.keys()).sort((a, b) => a - b);
        if (lines.length === 0) {
            this.announce(editorLabel('passed', 'Accessibility check passed'));
            return;
        }
        const openLine = !this.editorPanel.hidden ? this.panelLine ?? null : null;
        const from = openLine ?? this.cursorLine();
        let line;
        if (direction > 0) {
            line = lines.find((candidate) => openLine !== null ? candidate > from : candidate >= from) ?? lines[0];
        } else {
            line = [...lines].reverse().find((candidate) => candidate < from) ?? lines[lines.length - 1];
        }
        const indexes = this.issueLines.get(line) ?? [];
        const view = this.getEditorView();
        if (view) {
            const position = view.state.doc.line(Math.min(line, view.state.doc.lines)).from;
            view.dispatch({ selection: { anchor: position }, scrollIntoView: true });
        }
        this.scrollCodeMirrorLineIntoView(line);
        window.setTimeout(() => {
            this.refreshVisibleEditorDecorations();
            const badge = Array.from(this.getCodeMirrorRoot()?.querySelectorAll('.aqg-cm-side-badge') ?? [])
                .find((candidate) => candidate.dataset.aqgIssueIndexes === indexes.join(','));
            this.panelLine = line;
            this.showEditorPanel(indexes, badge ?? this.editorHost ?? this.container);
        }, 160);
    }

    /** Opens the line panel of one finding (the staged queue change), like the issue keys do. */
    openIssuePanel(issue) {
        // issueLines maps a document line to the indexes (in displayedIssues) of the findings on it.
        const index = this.displayedIssues.indexOf(issue);
        const [line, indexes] = Array.from(this.issueLines.entries()).find(([, lineIndexes]) => lineIndexes.includes(index)) ?? [0, []];
        if (index < 0 || line <= 0 || indexes.length === 0) {
            return;
        }
        const view = this.getEditorView();
        if (view) {
            const position = view.state.doc.line(Math.min(line, view.state.doc.lines)).from;
            view.dispatch({ selection: { anchor: position }, scrollIntoView: true });
        }
        this.scrollCodeMirrorLineIntoView(line);
        window.setTimeout(() => {
            this.refreshVisibleEditorDecorations();
            const badge = Array.from(this.getCodeMirrorRoot()?.querySelectorAll('.aqg-cm-side-badge') ?? [])
                .find((candidate) => candidate.dataset.aqgIssueIndexes === indexes.join(','));
            this.panelLine = line;
            this.showEditorPanel(indexes, badge ?? this.editorHost ?? this.container);
        }, 160);
    }

    destroy() {
        if (this.destroyed) {
            return;
        }
        this.destroyed = true;
        window.clearTimeout(this.validationTimer);
        window.clearTimeout(this.editorMarkTimer);
        if (this.decorationFrame) {
            window.cancelAnimationFrame(this.decorationFrame);
        }
        this.abortController?.abort();
        this.observer?.disconnect();
        document.removeEventListener('paste', this.boundDocumentPaste, true);
        document.removeEventListener('input', this.boundDocumentInput, true);
        document.removeEventListener('keyup', this.boundDocumentKeyup, true);
        document.removeEventListener('keydown', this.boundIssueKeys, true);
        this.aiSessions.forEach((session) => session.destroy());
        this.aiSessions.clear();
        this.clearDomEditorDecorations();
        document.removeEventListener('mousedown', this.boundOutsidePointer, true);
        this.editorPanel?.remove();
        delete this.container.__aqgPlainHtmlValidator;
    }

    async ignoreIssue(issue, button) {
        const endpoint = TYPO3?.settings?.ajaxUrls?.a11y_ignore || TYPO3?.settings?.ajaxUrls?.['a11y_ignore'];
        if (!endpoint || !issue?.fingerprint) {
            return;
        }

        button.disabled = true;
        button.textContent = editorLabel('ignoring', 'Ignoring…');

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    fingerprint: issue.fingerprint,
                    recordUid: this.recordUid,
                    fieldName: this.fieldName,
                    html: this.getHtmlValue(),
                    reason: editorLabel('ignoredReasonHtml', 'Ignored via HTML editor'),
                }),
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                button.disabled = false;
                button.textContent = editorLabel('ignoreFailed', 'Ignore failed');
                return;
            }

            this.currentIssues = this.currentIssues.filter((currentIssue) => currentIssue.fingerprint !== issue.fingerprint);
            this.displayedIssues = this.sortIssues(this.currentIssues);
            this.renderSummary(this.displayedIssues);
            this.renderIssues(this.displayedIssues);
            this.applyEditorDecorations(this.displayedIssues);
            this.hideEditorPanel();
        } catch (error) {
            button.disabled = false;
            button.textContent = editorLabel('ignoreFailed', 'Ignore failed');
        }
    }
}

const aqgPlainHtmlInstances = window.__aqgPlainHtmlInstances || new Set();
window.__aqgPlainHtmlInstances = aqgPlainHtmlInstances;

const cleanupDetachedInstances = () => {
    aqgPlainHtmlInstances.forEach((instance) => {
        if (!document.contains(instance.container)) {
            instance.destroy();
            aqgPlainHtmlInstances.delete(instance);
        }
    });
};

const boot = () => {
    cleanupDetachedInstances();
    document.querySelectorAll('.js-aqg-plain-html-a11y:not([data-aqg-initialized])').forEach((container) => {
        container.dataset.aqgInitialized = '1';
        const instance = new PlainHtmlA11yValidator(container);
        container.__aqgPlainHtmlValidator = instance;
        aqgPlainHtmlInstances.add(instance);
    });
};

boot();
window.setInterval(cleanupDetachedInstances, 5000);
document.addEventListener('typo3:formengine:field-added', boot);
document.addEventListener('typo3:backend:formengine:field-added', boot);
