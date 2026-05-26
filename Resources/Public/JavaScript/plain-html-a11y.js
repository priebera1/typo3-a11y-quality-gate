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

const severityRank = (severity) => ({ critical: 3, warning: 2, needs_review: 1.5, info: 1 }[normalizeSeverity(severity)] ?? 0);

const severityLabel = (severity) => {
    const value = normalizeSeverity(severity);
    return value === 'needs_review' ? 'Needs review' : value.charAt(0).toUpperCase() + value.slice(1);
};

class PlainHtmlA11yValidator {
    constructor(container) {
        this.container = container;
        this.recordUid = parseInt(container.dataset.recordUid || '0', 10);
        this.fieldName = container.dataset.fieldName || 'bodytext';
        this.inputName = container.dataset.inputName || '';
        this.summary = container.querySelector('.ck-a11y-summary');
        this.issuesContainer = container.querySelector('.aqg-plain-html-a11y__issues');
        this.textarea = this.findTextarea();
        this.editorHost = this.findEditorHost();
        this.validationTimer = null;
        this.editorMarkTimer = null;
        this.hidePanelTimer = null;
        this.abortController = null;
        this.sequence = 0;
        this.currentIssues = [];
        this.displayedIssues = [];
        this.issueLines = new Map();
        this.editorPanel = null;
        this.observer = null;
        this.lastKnownHtml = '';
        this.lastValidatedHtml = '';
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
        this.bindEvents();
        this.scheduleValidation(0);
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

    createEditorPanel() {
        this.editorPanel = document.createElement('div');
        this.editorPanel.className = 'aqg-code-html-panel';
        this.editorPanel.hidden = true;
        document.body.appendChild(this.editorPanel);

        this.editorPanel.addEventListener('mouseenter', () => window.clearTimeout(this.hidePanelTimer));
        this.editorPanel.addEventListener('mouseleave', () => this.scheduleHideEditorPanel());
        this.editorPanel.addEventListener('click', (event) => this.handleEditorPanelClick(event));
    }

    bindEvents() {
        const onContentChange = () => this.scheduleValidation();
        this.textarea.addEventListener('input', onContentChange);
        this.textarea.addEventListener('change', () => this.scheduleValidation(VALIDATION_FAST_DEBOUNCE_MS));
        this.issuesContainer.addEventListener('click', (event) => this.handleIssueClick(event));
        this.summary?.addEventListener('click', (event) => {
            if (event.target.closest('.js-aqg-plain-html-retry')) {
                this.scheduleValidation(0);
            }
        });

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
        root.addEventListener('click', (event) => this.handleEditorLineEvent(event, true));
        root.addEventListener('mouseover', (event) => this.handleEditorLineEvent(event, false));
        root.addEventListener('mouseleave', () => this.scheduleHideEditorPanel());
        root.querySelector('.cm-scroller')?.addEventListener('scroll', () => this.scheduleEditorDecorationRefresh());

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

    scheduleValidation(delay = VALIDATION_DEBOUNCE_MS) {
        window.clearTimeout(this.validationTimer);

        // Do not call the live endpoint on every keystroke. The timer is reset
        // while the editor is still changing and validation starts only after
        // the editor has been quiet for the configured debounce time.
        if (delay <= 0) {
            this.renderLoading();
            this.validationTimer = window.setTimeout(() => this.validate(), 0);
            return;
        }

        this.validationTimer = window.setTimeout(() => {
            this.renderLoading();
            this.validate();
        }, delay);
    }

    async validate() {
        const endpoint = TYPO3?.settings?.ajaxUrls?.a11y_rte_validate || TYPO3?.settings?.ajaxUrls?.['a11y_rte_validate'];

        if (!endpoint) {
            this.renderError('Live validation endpoint is not available.');
            return;
        }

        const html = this.getHtmlValue();
        this.lastKnownHtml = html;
        if (html.length > MAX_LIVE_VALIDATION_HTML_SIZE) {
            this.renderError('HTML is too large for live validation. Save the record and run a page scan instead.');
            return;
        }

        if (this.abortController) {
            this.abortController.abort();
        }

        const sequence = ++this.sequence;
        this.abortController = new AbortController();

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

            if (!response.ok) {
                this.renderError(`Live validation failed (${response.status}).`);
                return;
            }

            const data = await response.json();
            if (!data.success) {
                this.renderError(data.error || 'Live validation failed.');
                return;
            }

            this.lastValidatedHtml = html;
            this.currentIssues = Array.isArray(data.issues) ? data.issues : [];
            this.displayedIssues = this.sortIssues(this.currentIssues);
            this.renderSummary(this.displayedIssues);
            this.renderIssues(this.displayedIssues);
            this.applyEditorDecorations(this.displayedIssues);
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }
            this.renderError('Live validation failed. You can keep editing and try again after saving.');
        }
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

        this.summary.className = 'ck-a11y-summary ck-a11y-summary--outside ck-a11y-summary--loading';
        this.summary.innerHTML = `
            <span class="ck-a11y-summary__left">
                <span class="ck-a11y-summary__spin" aria-hidden="true"></span>
                <span class="ck-a11y-summary__title">Updating accessibility status…</span>
                <span class="ck-a11y-summary__help">Refreshing live issues for this HTML field.</span>
            </span>
        `;
    }

    renderError(message) {
        if (!this.summary) {
            return;
        }

        this.summary.className = 'ck-a11y-summary ck-a11y-summary--outside ck-a11y-summary--error';
        this.summary.innerHTML = `
            <span class="ck-a11y-summary__left">
                <span class="ck-a11y-summary__dot" aria-hidden="true"></span>
                <span class="ck-a11y-summary__title">Accessibility issues could not be loaded</span>
                <span class="ck-a11y-summary__help">${escapeHtml(message)}</span>
            </span>
            <span class="ck-a11y-summary__right">
                <button type="button" class="ck-a11y-summary__link js-aqg-plain-html-retry">Retry</button>
            </span>
        `;

        this.issuesContainer.innerHTML = '';
        this.clearEditorDecorations();
        this.hideEditorPanel();
        this.summary.querySelector('.js-aqg-plain-html-retry')?.addEventListener('click', () => this.scheduleValidation(0));
    }

    renderSummary(issues) {
        const counts = issues.reduce((acc, issue) => {
            acc[normalizeSeverity(issue.severity)]++;
            return acc;
        }, { critical: 0, warning: 0, needs_review: 0, info: 0 });
        const total = counts.critical + counts.warning + counts.needs_review + counts.info;
        const state = total === 0 ? 'ok' : counts.critical > 0 ? 'critical' : 'issues';

        this.summary.className = `ck-a11y-summary ck-a11y-summary--outside ck-a11y-summary--${state}`;

        if (total === 0) {
            this.summary.innerHTML = `
                <span class="ck-a11y-summary__left">
                    <span class="ck-a11y-summary__dot" aria-hidden="true"></span>
                    <span class="ck-a11y-summary__title">Accessibility check passed</span>
                    <span class="ck-a11y-summary__help">No issues found in this HTML field.</span>
                </span>
            `;
            return;
        }

        this.summary.innerHTML = `
            <span class="ck-a11y-summary__left">
                <span class="ck-a11y-summary__dot" aria-hidden="true"></span>
                <span class="ck-a11y-summary__title">${total} ${total === 1 ? 'issue' : 'issues'} found</span>
                <span class="ck-a11y-summary__counts">
                    ${this.renderCount('critical', counts.critical)}
                    ${this.renderCount('warning', counts.warning)}
                    ${this.renderCount('needs_review', counts.needs_review)}
                    ${this.renderCount('info', counts.info)}
                </span>
            </span>
            <span class="ck-a11y-summary__right">
                <span class="ck-a11y-summary__help">Lines with issues are marked directly in the HTML editor.</span>
            </span>
        `;
    }

    renderCount(severity, count) {
        if (count <= 0) {
            return '';
        }

        const label = severity === 'info' || severity === 'needs_review'
            ? severityLabel(severity)
            : `${severityLabel(severity)}${count === 1 ? '' : 's'}`;

        return `
            <span class="ck-a11y-summary__count ck-a11y-summary__count--${severity}">
                <span class="ck-a11y-summary__count-dot" aria-hidden="true"></span>
                <span class="ck-a11y-summary__count-num">${count}</span> ${label}
            </span>
        `;
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
                <summary>Show issue list (${issues.length})</summary>
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
                <span class="aqg-plain-html-a11y__fix-label">How to fix</span>
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
                        <span class="aqg-plain-html-a11y__issue-title">${escapeHtml(issue.message || issue.ruleId || 'Accessibility issue')}</span>
                        <span class="aqg-plain-html-a11y__issue-rule">${escapeHtml(issue.ruleId || '')}</span>
                    </span>
                    <span class="ck-a11y-panel__severity ck-a11y-panel__severity--${severity}">
                        <span class="ck-a11y-panel__severity-dot" aria-hidden="true"></span>${severityLabel(severity)}
                    </span>
                </summary>
                <div class="aqg-plain-html-a11y__issue-body">
                    ${hint}
                    <dl class="aqg-plain-html-a11y__meta">
                        <div><dt>Rule</dt><dd>${escapeHtml(issue.ruleId || '')}</dd></div>
                        <div><dt>Location</dt><dd>${escapeHtml(issue.contextPath || 'HTML source')}</dd></div>
                    </dl>
                    ${snippet}
                    <div class="aqg-plain-html-a11y__actions">
                        <button type="button" class="btn btn-default btn-sm js-aqg-plain-html-locate" data-index="${index}">Locate in HTML</button>
                        <button type="button" class="btn btn-default btn-sm js-aqg-plain-html-ignore" data-index="${index}">Ignore this issue</button>
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
        const locateButton = event.target.closest('.js-aqg-code-panel-locate');
        if (locateButton) {
            const issue = this.displayedIssues[parseInt(locateButton.dataset.index || '-1', 10)];
            this.locateIssue(issue);
            return;
        }

        const ignoreButton = event.target.closest('.js-aqg-code-panel-ignore');
        if (ignoreButton) {
            const issue = this.displayedIssues[parseInt(ignoreButton.dataset.index || '-1', 10)];
            this.ignoreIssue(issue, ignoreButton);
        }
    }

    handleEditorLineEvent(event, pin = false) {
        const badge = event.target.closest('.aqg-cm-side-badge[data-aqg-issue-indexes]');
        const line = badge ? null : event.target.closest('.cm-line[data-aqg-issue-indexes]');
        const anchor = badge || line;
        const rawIndexes = anchor?.dataset.aqgIssueIndexes || '';

        if (!anchor) {
            if (!pin) {
                this.scheduleHideEditorPanel();
            }
            return;
        }

        const indexes = String(rawIndexes)
            .split(',')
            .map((value) => parseInt(value, 10))
            .filter((value) => Number.isInteger(value) && value >= 0);

        if (!indexes.length) {
            return;
        }

        window.clearTimeout(this.hidePanelTimer);
        this.showEditorPanel(indexes, anchor, pin);
    }

    showEditorPanel(indexes, line, pin = false) {
        const issues = indexes.map((index) => ({ issue: this.displayedIssues[index], index })).filter((entry) => entry.issue);
        if (!issues.length || !this.editorPanel) {
            return;
        }

        const primary = issues[0].issue;
        const severity = normalizeSeverity(primary.severity);
        const multiple = issues.length > 1;
        const body = multiple
            ? `<div class="aqg-code-html-panel__siblings">
                    <strong>${issues.length} issues on this line</strong>
                    ${issues.map((entry) => `
                        <div class="aqg-code-html-panel__sibling-row">
                            <button type="button" class="aqg-code-html-panel__sibling js-aqg-code-panel-locate" data-index="${entry.index}">
                                <span>${severityLabel(entry.issue.severity)} ·</span> ${escapeHtml(entry.issue.message || entry.issue.ruleId || 'Accessibility issue')}
                            </button>
                            <button type="button" class="btn btn-default btn-sm aqg-code-html-panel__sibling-ignore js-aqg-code-panel-ignore" data-index="${entry.index}">Ignore this issue</button>
                        </div>
                    `).join('')}
                </div>`
            : this.renderPanelIssue(primary, indexes[0]);

        this.editorPanel.className = `aqg-code-html-panel aqg-code-html-panel--${severity}${pin ? ' is-pinned' : ''}`;
        this.editorPanel.innerHTML = `
            <div class="aqg-code-html-panel__header">
                <span class="ck-a11y-panel__severity ck-a11y-panel__severity--${severity}">
                    <span class="ck-a11y-panel__severity-dot" aria-hidden="true"></span>${severityLabel(severity)}
                </span>
                <span class="aqg-code-html-panel__kicker">HTML source issue</span>
            </div>
            ${body}
        `;

        const rect = line.getBoundingClientRect();
        const width = Math.min(460, Math.max(320, window.innerWidth - 32));
        let left = rect.right + 12;
        if (left + width > window.innerWidth - 12) {
            left = Math.max(12, window.innerWidth - width - 12);
        }
        let top = Math.max(12, rect.top - 8);
        const maxTop = window.innerHeight - 180;
        if (top > maxTop) {
            top = Math.max(12, maxTop);
        }

        this.editorPanel.style.width = `${width}px`;
        this.editorPanel.style.left = `${left}px`;
        this.editorPanel.style.top = `${top}px`;
        this.editorPanel.hidden = false;
    }

    renderPanelIssue(issue, index) {
        const hint = issue.hint ? `
            <div class="ck-a11y-panel__hint">
                <span class="ck-a11y-panel__hint-label">How to fix</span>
                <p class="ck-a11y-panel__hint-text">${escapeHtml(issue.hint)}</p>
            </div>
        ` : '';
        const snippet = issue.snippet ? `<code class="ck-a11y-panel__snippet">${escapeHtml(issue.snippet)}</code>` : '';

        return `
            <h4 class="ck-a11y-panel__title">${escapeHtml(issue.message || issue.ruleId || 'Accessibility issue')}</h4>
            ${hint}
            <div class="ck-a11y-panel__details">
                <div class="ck-a11y-panel__details-row">
                    <span class="ck-a11y-panel__details-key">Rule</span>
                    <span class="ck-a11y-panel__details-val">${escapeHtml(issue.ruleId || '')}</span>
                </div>
                <div class="ck-a11y-panel__details-row">
                    <span class="ck-a11y-panel__details-key">Location</span>
                    <span class="ck-a11y-panel__details-val">${escapeHtml(issue.contextPath || 'HTML source')}</span>
                </div>
                ${snippet}
            </div>
            <div class="aqg-code-html-panel__actions">
                <button type="button" class="btn btn-default btn-sm js-aqg-code-panel-locate" data-index="${index}">Locate</button>
                <button type="button" class="btn btn-default btn-sm js-aqg-code-panel-ignore" data-index="${index}">Ignore this issue</button>
            </div>
        `;
    }

    scheduleHideEditorPanel() {
        window.clearTimeout(this.hidePanelTimer);
        this.hidePanelTimer = window.setTimeout(() => this.hideEditorPanel(), 180);
    }

    hideEditorPanel() {
        if (this.editorPanel) {
            this.editorPanel.hidden = true;
        }
    }

    applyEditorDecorations(issues) {
        this.clearEditorDecorations();
        this.issueLines.clear();

        const html = this.getHtmlValue();
        if (!html || !issues.length) {
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
        return issues
            .map((issue, index) => ({
                ...issue,
                index,
                lineNumber: this.findLineNumberForIssue(issue, html),
            }))
            .filter((issue) => issue.lineNumber > 0);
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

        const css = `
            .cm-line.aqg-cm-line {
                position: relative;
                padding-right: 76px !important;
                box-shadow: inset 3px 0 0 #7c828d;
                background: rgba(124, 130, 141, 0.08);
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
                background: #7c828d;
                pointer-events: none;
            }
            .cm-line.aqg-cm-line--critical { box-shadow: inset 3px 0 0 #c2410c; background: rgba(194, 65, 12, .09); }
            .cm-line.aqg-cm-line--critical::after { background: #c2410c; }
            .cm-line.aqg-cm-line--warning { box-shadow: inset 3px 0 0 #b7791f; background: rgba(183, 121, 31, .10); }
            .cm-line.aqg-cm-line--warning::after { color: #1e232a; background: #f3c66f; }
            .cm-line.aqg-cm-line--info { box-shadow: inset 3px 0 0 #2563eb; background: rgba(37, 99, 235, .08); }
            .cm-line.aqg-cm-line--info::after { background: #2563eb; }
            .aqg-cm-overlay { position: absolute; inset: 0; pointer-events: none; z-index: 20; }
            .aqg-cm-side-badge { position: absolute; right: 8px; min-width: 36px; box-sizing: border-box; padding: 1px 7px 2px; border-radius: 999px; font: 700 10px/1.4 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing: .04em; color: #fff; background: #7c828d; pointer-events: auto; cursor: pointer; border: 0; }
            .aqg-cm-side-badge--critical { background: #c2410c; }
            .aqg-cm-side-badge--warning { color: #1e232a; background: #f3c66f; }
            .aqg-cm-side-badge--info { background: #2563eb; }
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

        lineElements.forEach((lineElement, visibleIndex) => {
            const lineNumber = visibleLineNumbers.get(lineElement) || this.resolveLineNumberForVisibleLine(lineElement, visibleIndex);
            // Prefer the actual visible source line text. Line-number mapping can be
            // wrong in virtualized CodeMirror views with repeated paragraphs, which
            // would otherwise show issues on unrelated lines with similar structure.
            const indexesByVisibleText = this.findIssueIndexesForVisibleLine(lineElement);
            const indexes = indexesByVisibleText.length ? indexesByVisibleText : (this.issueLines.get(lineNumber) || []);
            if (!indexes?.length) {
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
                badge.className = `aqg-cm-side-badge aqg-cm-side-badge--${strongestSeverity}`;
                badge.dataset.aqgIssueIndexes = indexes.join(',');
                badge.textContent = indexes.length === 1 ? 'AQG' : `AQG ${indexes.length}`;
                badge.setAttribute('aria-label', indexes.length === 1 ? 'Show AQG issue' : `Show ${indexes.length} AQG issues`);
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
            overlay.setAttribute('aria-hidden', 'true');
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

    destroy() {
        if (this.destroyed) {
            return;
        }
        this.destroyed = true;
        window.clearTimeout(this.validationTimer);
        window.clearTimeout(this.editorMarkTimer);
        window.clearTimeout(this.hidePanelTimer);
        if (this.decorationFrame) {
            window.cancelAnimationFrame(this.decorationFrame);
        }
        this.abortController?.abort();
        this.observer?.disconnect();
        document.removeEventListener('paste', this.boundDocumentPaste, true);
        document.removeEventListener('input', this.boundDocumentInput, true);
        document.removeEventListener('keyup', this.boundDocumentKeyup, true);
        this.clearDomEditorDecorations();
        this.editorPanel?.remove();
        delete this.container.__aqgPlainHtmlValidator;
    }

    async ignoreIssue(issue, button) {
        const endpoint = TYPO3?.settings?.ajaxUrls?.a11y_ignore || TYPO3?.settings?.ajaxUrls?.['a11y_ignore'];
        if (!endpoint || !issue?.fingerprint) {
            return;
        }

        button.disabled = true;
        button.textContent = 'Ignoring…';

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
                    reason: 'Ignored via HTML editor',
                }),
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                button.disabled = false;
                button.textContent = 'Ignore failed';
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
            button.textContent = 'Ignore failed';
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
