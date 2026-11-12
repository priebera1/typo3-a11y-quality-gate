import {el, format, postRemediation} from '@priebera/a11y-quality-gate/remediation/remediation-api.js';

/** Same rounding as AiMoney::formatUsd(): below one cent is "< $0.01", otherwise whole cents. */
const formatUsdMicro = (micro) => {
    if (micro > 0 && micro < 10000) {
        return '< $0.01';
    }
    const cents = Math.floor((Math.max(0, micro) + 5000) / 10000);
    return '$' + Math.floor(cents / 100).toLocaleString('en-US') + '.' + String(cents % 100).padStart(2, '0');
};

/** Records whose editing form the assistant understands (the server allowlists them again). */
const EDITABLE_TABLES = ['tt_content', 'pages', 'sys_file_metadata'];
const FIELD_NAME = /^data\[([A-Za-z][A-Za-z0-9_]{0,63})\]\[([1-9][0-9]{0,9})\]\[([A-Za-z][A-Za-z0-9_]{0,63})\]$/;

/** The window of the backend's module content frame, else null (outside the backend frame set). */
export function contentWindowOf(win) {
    try {
        const container = win.TYPO3?.Backend?.ContentContainer;
        const frame = typeof container?.get === 'function' ? container.get() : null;
        if (frame?.document) {
            return frame;
        }
    } catch {
        // The content frame is not accessible.
    }
    try {
        return win.document.querySelector('#typo3-contentIframe')?.contentWindow ?? null;
    } catch {
        return null;
    }
}

/**
 * What the user works on, as identifiers only: the page and language of the module, the record open in the editing
 * form (with whether it has unsaved changes and the names, never the values, of the changed fields), or the one file
 * selected in the File list. Everything else is derived and permission-checked on the server.
 */
export function collectAssistantContext(frameWindow, treePage = '') {
    const context = {};
    let url = null;
    let doc = null;
    try {
        url = new URL(frameWindow.location.href);
        doc = frameWindow.document;
    } catch {
        url = null;
    }

    if (url) {
        const id = url.searchParams.get('id') ?? '';
        if (/^[1-9][0-9]{0,9}$/.test(id)) {
            context.id = id;
        }
        for (const key of ['language', 'languageUid']) {
            const language = url.searchParams.get(key) ?? '';
            if (/^(0|[1-9][0-9]{0,4})$/.test(language)) {
                context.language = language;
                break;
            }
        }
        for (const [key, value] of url.searchParams) {
            const match = /^edit\[([A-Za-z][A-Za-z0-9_]{0,63})\]\[([1-9][0-9]{0,9})\]$/.exec(key);
            if (match && value === 'edit' && EDITABLE_TABLES.includes(match[1])) {
                context.editTable = match[1];
                context.editUid = match[2];
                break;
            }
        }
    }

    if (doc && context.editTable) {
        const changed = new Set();
        let unsaved = false;
        doc.querySelectorAll('form[name="editform"] .has-change, [name^="data["].has-change').forEach((element) => {
            unsaved = true;
            const name = element.getAttribute('name') || element.getAttribute('data-formengine-input-name') || '';
            const match = FIELD_NAME.exec(name);
            if (match && match[1] === context.editTable && match[2] === context.editUid) {
                changed.add(match[3]);
            }
        });
        if (unsaved) {
            context.unsaved = '1';
            context.changed = Array.from(changed).slice(0, 30).join(',');
        }
    }

    if (doc && !context.editTable) {
        const fileRows = Array.from(doc.querySelectorAll('[data-filelist-element="true"]'));
        if (fileRows.length > 0) {
            const selected = fileRows.filter((row) => row.getAttribute('data-filelist-type') === 'file'
                && row.querySelector('input.t3js-multi-record-selection-check:checked') !== null);
            const uid = selected.length === 1 ? String(selected[0].getAttribute('data-filelist-uid') ?? '') : '';
            if (/^[1-9][0-9]{0,9}$/.test(uid)) {
                context.fileUid = uid;
            } else {
                context.folder = '1';
            }
            delete context.id;
        }
    }

    if (!context.id && !context.editTable && !context.fileUid && !context.folder && /^[1-9][0-9]*$/.test(treePage)) {
        context.id = treePage;
    }

    return context;
}

/** The form field element of a finding inside the editing form, or null. */
export function findFormField(doc, fieldName) {
    if (!doc || !FIELD_NAME.test(fieldName)) {
        return null;
    }
    const escaped = fieldName.replace(/["\\]/g, '\\$&');
    const input = doc.querySelector(`[name="${escaped}"]`) ?? doc.querySelector(`[data-formengine-input-name="${escaped}"]`);
    const item = input?.closest('.t3js-formengine-field-item, .form-section, .form-group') ?? input?.parentElement ?? null;
    if (!item) {
        return null;
    }
    // The editor's own editable first: CKEditor, then CodeMirror for Plain HTML (TYPO3's typo3-t3editor-codemirror keeps
    // it in an open shadow root), else a visible plain form control. A control hidden by CSS cannot take focus.
    const roots = [item, ...Array.from(item.querySelectorAll('*')).map((element) => element.shadowRoot).filter(Boolean)];
    // Without a layout engine (tests) nothing has client rects; then every control counts as visible.
    const noLayout = doc.documentElement.getClientRects().length === 0;
    const visible = (element) => noLayout || element.getClientRects().length > 0;
    const focusTarget = ['.ck-editor__editable', '.cm-content', 'textarea:not([hidden])', 'input:not([type="hidden"])']
        .flatMap((selector) => roots.map((root) => root.querySelector(selector)))
        .find((element) => element !== null && element !== undefined && visible(element)) ?? null;

    return {item, focusTarget};
}

/** Only same-origin backend paths are rendered as links (citations come from the server; this is defence in depth). */
export function safeBackendHref(href, win = window) {
    if (typeof href !== 'string' || href === '') {
        return false;
    }
    try {
        const url = new URL(href, win.location.origin);
        return url.origin === win.location.origin && /^\/typo3\//.test(url.pathname);
    } catch {
        return false;
    }
}

const ERROR_LABELS = {
    permission_denied: 'errorPermission',
    forbidden: 'errorPermission',
    no_edit_access: 'errorPermission',
    stale_finding: 'errorStale',
    invalid_version_token: 'errorStale',
    budget_reached: 'errorBudget',
    budget_exceeded: 'errorBudget',
    rate_limited: 'errorRate',
    session_expired: 'errorSession',
    invalid_input: 'errorInput',
    already_running: 'alreadyRunning',
};

export function errorLabelKey(code) {
    return ERROR_LABELS[code] ?? 'error';
}

const ASK_ERROR_LABELS = {
    scope_restricted: 'errorScope',
    no_context: 'errorContext',
    no_licence: 'errorNotReady',
    no_permission: 'errorNotReady',
    not_configured: 'errorNotReady',
    paused: 'errorNotReady',
    privacy_page_title: 'errorNotReady',
    budget_reached: 'errorBudget',
    rate_limited: 'errorRate',
    provider_rate_limited: 'errorRate',
    provider_timeout: 'errorProvider',
    provider_unavailable: 'errorProvider',
    provider_credit: 'errorProvider',
    refused: 'errorProvider',
    // 502/503/504 whose body is not AQG's JSON (remediation-api.js): the request did not get through to an answer.
    upstream_unavailable: 'errorProvider',
    invalid_response: 'invalid',
    invalid_input: 'errorQuestion',
    session_expired: 'errorSession',
    permission_denied: 'errorPermission',
    busy: 'errorBusy',
    daily_limit: 'errorDailyLimit',
    conversation_limit: 'errorConversationLimit',
    conversation_budget: 'errorConversationBudget',
    history_too_long: 'errorHistory',
    cancelled: 'errorTimeout',
    network: 'errorNetwork',
    internal_error: 'error',
    usage_unavailable: 'errorUsage',
};

/** The label key for an "Ask AQG" refusal code. */
export function askErrorLabelKey(code) {
    return ASK_ERROR_LABELS[code] ?? 'error';
}

const DEFAULT_LIMITS = {
    maxQuestionChars: 400,
    minQuestionChars: 3,
    maxHistoryTurns: 3,
    maxHistoryAnswerChars: 700,
    maxHistoryChars: 2400,
    maxSecondsPerQuestion: 45,
};

/** The limits the server published on the Ask AQG section (it enforces the same values). */
export function askLimits(section) {
    try {
        const parsed = JSON.parse(section?.dataset?.limits ?? '{}');
        const limits = {...DEFAULT_LIMITS};
        Object.keys(DEFAULT_LIMITS).forEach((key) => {
            if (Number.isInteger(parsed[key]) && parsed[key] > 0) {
                limits[key] = parsed[key];
            }
        });
        return limits;
    } catch {
        return {...DEFAULT_LIMITS};
    }
}

/**
 * The earlier turns sent back with a question: the newest ones within the server's limits (turn count, answer length,
 * total length). Only AI turns that carry the server's history form ({question, answer, token}: the exact text the
 * server signed for this conversation) are sent, unchanged; the server verifies the token and the limits again and
 * drops every turn it did not issue for this user, login and context.
 */
export function historyFor(turns, limits) {
    const history = [];
    let total = 0;
    // Only earlier AI turns: answers from the findings ("source: aqg") can name elements and stay in the browser.
    for (const turn of [...(Array.isArray(turns) ? turns : [])].filter((item) => item?.source === 'ai').reverse()) {
        if (history.length >= limits.maxHistoryTurns) {
            break;
        }
        const signed = turn?.history;
        if (!signed || typeof signed.token !== 'string' || signed.token === '' || typeof signed.question !== 'string' || typeof signed.answer !== 'string') {
            continue;
        }
        const length = [...signed.question].length + [...signed.answer].length;
        if ([...signed.question].length < limits.minQuestionChars || [...signed.question].length > limits.maxQuestionChars
            || [...signed.answer].length > limits.maxHistoryAnswerChars || total + length > limits.maxHistoryChars) {
            break;
        }
        total += length;
        history.unshift({question: signed.question, answer: signed.answer, token: signed.token});
    }
    return history;
}

/** sessionStorage keys of "Ask AQG" conversations; the marker holds the principal (user + login) they belong to. */
export const ASK_STORAGE_PREFIX = 'aqg-toolbar-ask:';
export const ASK_PRINCIPAL_KEY = 'aqg-toolbar-ask-principal';
const OPAQUE_KEY = /^[a-f0-9]{16,128}$/;

/** Removes every stored "Ask AQG" conversation of this tab (another user or login session took over). */
export function purgeAskStorage(storage) {
    try {
        const keys = [];
        for (let index = 0; index < storage.length; index++) {
            const key = storage.key(index);
            if (typeof key === 'string' && key.startsWith(ASK_STORAGE_PREFIX)) {
                keys.push(key);
            }
        }
        keys.forEach((key) => storage.removeItem(key));
    } catch {
        // No storage: nothing was stored.
    }
}

/** Cuts by characters (code points), never inside a surrogate pair, so the JSON stays valid for the server. */
export function truncate(text, max) {
    const characters = Array.from(String(text ?? ''));
    return characters.length <= max ? characters.join('') : characters.slice(0, max - 1).join('').trimEnd() + '…';
}

/** An estimated amount in dollars: tenths of a cent are shown as "<$0.001", larger amounts with three decimals. */
export function money(micro) {
    const value = Math.max(0, Number(micro) || 0);
    if (value > 0 && value < 1000) {
        return '<$0.001';
    }
    return '$' + (value / 1000000).toFixed(3);
}

/** The answer text as paragraphs and "- " lists, built as text nodes only (model output is never markup). */
export function answerNodes(text) {
    const nodes = [];
    let list = null;
    String(text ?? '').split(/\n+/).map((line) => line.trim()).filter((line) => line !== '').forEach((line) => {
        if (/^[-•]\s+/.test(line)) {
            list ??= el('ul', {class: 'aqt-turn__list'});
            list.append(el('li', {text: line.replace(/^[-•]\s+/, '')}));
            return;
        }
        if (list) {
            nodes.push(list);
            list = null;
        }
        nodes.push(el('p', {text: line}));
    });
    if (list) {
        nodes.push(list);
    }

    return nodes;
}

/**
 * The visible result of "Recheck saved content" from the page scan answer (ScanAjaxController::scanPageAction()): what
 * the check changed against the stored findings of this page and what is open on it now. New and resolved are named only
 * when the answer carries both counts and the open count only when the answer has it; otherwise the plain "finished"
 * text. "No new and no resolved issues" is literally what the counts say, also for a page that was never checked before.
 *
 * @param {Record<string, unknown>} data
 * @param {(key: string, fallback?: string) => string} label
 */
export function contentScanResultMessage(data, label) {
    // F-05: with every content field switched off nothing was checked.
    if ((Array.isArray(data?.warnings) ? data.warnings : []).some((warning) => String(warning?.code || '') === 'no_scan_fields')) {
        return label('noFields', 'No content fields are enabled for scanning, so nothing was checked.');
    }
    const open = data?.openIssues && typeof data.openIssues === 'object' ? data.openIssues.total : undefined;
    if (!Number.isInteger(open) || open < 0) {
        return label('finished');
    }
    const fresh = data.issuesNew;
    const resolved = data.issuesResolved;
    if (!Number.isInteger(fresh) || !Number.isInteger(resolved)) {
        return format(label('resultOpen', label('finished')), open);
    }
    if (fresh === 0 && resolved === 0) {
        return format(label('resultUnchanged', label('finished')), open);
    }
    return format(label('resultChanged', label('finished')), Math.max(0, fresh), Math.max(0, resolved), open);
}

/** Form-encoded POST for the image and scan endpoints (they read the parsed body); answers normalised like postRemediation. */
async function postForm(url, payload) {
    if (!url) {
        return {ok: false, code: 'generic', data: {}};
    }
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            body: new URLSearchParams(payload),
            credentials: 'same-origin',
        });
        const data = await response.json().catch(() => ({}));
        if (response.status === 401) {
            return {ok: false, code: 'session_expired', data};
        }
        if (response.status === 409) {
            return {ok: false, code: 'already_running', data};
        }
        if (!response.ok || data.success === false) {
            return {ok: false, code: String(data.code ?? (response.status === 403 ? 'forbidden' : 'generic')), data};
        }

        return {ok: true, code: '', data};
    } catch {
        return {ok: false, code: 'network', data: {}};
    }
}

/**
 * The toolbar assistant inside the existing AQG dropdown: loads the context view when the dropdown opens and runs
 * the actions the server offered. Each action is an existing AQG endpoint that checks every permission, licence and
 * budget again; AI answers are shown as text and change nothing until the user applies or approves them.
 */
export class ToolbarAssistant {
    constructor({win = window, slotSelector, ajaxUrls = () => ({}), treePage = () => '', navigate, onScanFinished = () => {}}) {
        this.win = win;
        this.slotSelector = slotSelector;
        this.ajaxUrls = ajaxUrls;
        this.treePage = treePage;
        this.navigate = navigate;
        this.onScanFinished = onScanFinished;
        this.request = 0;
        // "Ask AQG" conversations of this tab, keyed by the server's opaque scope (user + login session + context); the
        // in-memory copy is used only where sessionStorage is unavailable and never for another scope (PI-01/PI-02).
        this.principal = '';
        this.conversationScope = '';
        this.memoryConversations = new Map();
        // "Check saved page now" in the expanded Content scan section: the page the assistant's server view offered last
        // (0 = not offered; always the next target, also while a scan runs), the running request's own target (`active`)
        // and the last visible result, which belongs to the offered page only. The section is re-rendered by the status
        // refresh, so this state is applied again with syncContentScan() after every refresh.
        this.contentScanState = {pageUid: 0, languageUid: 0, busy: false, active: null, message: '', tone: '', waitMessage: false};
        this.win.document.addEventListener('click', (event) => {
            const button = event.target instanceof this.win.Element ? event.target.closest('[data-aqt-content-scan-button]') : null;
            if (!button) {
                return;
            }
            event.preventDefault();
            this.contentScan(button);
        });
    }

    /** The Content scan section's action area in the open dropdown, if there is one. */
    contentScanArea() {
        const pop = this.slot()?.closest('.aqt-pop') ?? this.win.document;
        return pop.querySelector('[data-aqt-content-scan]');
    }

    /**
     * The server's latest offer is always the next target, also while an earlier scan runs (PI-04). A new context
     * (another page or language) starts without the previous page's result; a running scan of another page keeps
     * running, but its result is not shown for the new page.
     */
    setContentScanOffer(pageUid, languageUid) {
        const state = this.contentScanState;
        const page = Number.isInteger(pageUid) && pageUid > 0 ? pageUid : 0;
        const language = Number.isInteger(languageUid) && languageUid > 0 ? languageUid : 0;
        if (page !== state.pageUid || language !== state.languageUid) {
            state.message = '';
            state.tone = '';
            state.waitMessage = false;
        }
        state.pageUid = page;
        state.languageUid = language;
        this.syncContentScan();
    }

    /** Whether the running scan is for the page and language offered now. */
    contentScanIsCurrent() {
        const state = this.contentScanState;
        return state.active !== null && state.active.pageUid === state.pageUid && state.active.languageUid === state.languageUid;
    }

    /** Applies the offer and the last result to the (possibly re-rendered) Content scan section. */
    syncContentScan({refocus = false} = {}) {
        const area = this.contentScanArea();
        if (!area) {
            return;
        }
        const state = this.contentScanState;
        // Offered for a page the user may scan. While a content scan runs (this user's request for another page, or a
        // scan started elsewhere) the button stays visible and says why it cannot start one now (only one content scan
        // runs at a time; the server refuses a second one with 409), instead of disappearing or ignoring the click.
        area.hidden = state.pageUid <= 0;
        const waiting = this.contentScanWaiting(area);
        if (waiting && state.message === '') {
            state.message = this.label(area, 'waitRunning', this.label(area, 'alreadyRunning'));
            state.tone = 'info';
            state.waitMessage = true;
        } else if (!waiting && state.waitMessage === true) {
            // The other scan has finished: the explanation no longer applies.
            state.message = '';
            state.tone = '';
            state.waitMessage = false;
        }
        const button = area.querySelector('[data-aqt-content-scan-button]');
        if (button) {
            // The hint (what the check covers) always describes the button; while waiting, the reason comes first.
            const hint = area.querySelector('[data-aqt-content-scan-hint]')?.id || '';
            if (waiting) {
                // aria-disabled (not disabled): the button keeps the keyboard focus and a click explains the wait.
                button.setAttribute('aria-disabled', 'true');
                button.setAttribute('aria-describedby', [area.querySelector('[data-aqt-content-scan-status]')?.id || '', hint].filter(Boolean).join(' '));
            } else {
                button.removeAttribute('aria-disabled');
                if (hint !== '') {
                    button.setAttribute('aria-describedby', hint);
                } else {
                    button.removeAttribute('aria-describedby');
                }
            }
            // Always the offered (current) page: the next click targets it, never the page of a scan still running.
            button.dataset.pageUid = String(state.pageUid);
            button.dataset.languageUid = String(state.languageUid);
            if (state.busy) {
                // aria-busy (not disabled): a disabled button would drop the keyboard focus.
                button.setAttribute('aria-busy', 'true');
            } else {
                button.removeAttribute('aria-busy');
            }
            if (refocus && !area.hidden) {
                button.focus({preventScroll: true});
            }
        }
        const status = area.querySelector('[data-aqt-content-scan-status]');
        if (status) {
            status.hidden = state.message === '';
            status.textContent = state.message;
            status.className = `aqt-scan__action-status${state.tone !== '' ? ' aqt-scan__action-status--' + state.tone : ''}`;
        }
    }

    /** Announces a text once in the dropdown's polite live region (the section itself is replaced by refreshes). */
    announce(text) {
        const pop = this.slot()?.closest('.aqt-pop') ?? this.win.document;
        const live = pop.querySelector('[data-aqt-scan-live]');
        if (live && text !== '') {
            live.textContent = '';
            this.win.setTimeout(() => {
                live.textContent = text;
            }, 50);
        }
    }

    /**
     * Whether a content scan other than one for the offered page runs now: this user's own request for another page
     * or language, or a scan the status shows as running (started in the module or by someone else).
     */
    contentScanWaiting(area) {
        const state = this.contentScanState;
        if (state.busy) {
            return !this.contentScanIsCurrent();
        }
        return area?.dataset.scanRunning === '1';
    }

    /** "Check saved page now": the existing page scan route (Scan now permission and page edit access checked again). */
    async contentScan(button) {
        const state = this.contentScanState;
        const area = button.closest('[data-aqt-content-scan]');
        if (state.pageUid <= 0 || !area) {
            return;
        }
        if (state.busy && this.contentScanIsCurrent()) {
            // A second click on the page being checked: say again that it is running (no second request).
            this.announce(state.message);
            return;
        }
        if (this.contentScanWaiting(area)) {
            // Another content scan runs: nothing is sent (the server would refuse it), the reason is shown and announced.
            state.message = this.label(area, 'waitRunning', this.label(area, 'alreadyRunning'));
            state.tone = 'info';
            state.waitMessage = true;
            this.syncContentScan();
            this.announce(state.message);
            return;
        }
        // The target is fixed when the scan starts; later offers change only the next one.
        const target = {pageUid: state.pageUid, languageUid: state.languageUid};
        state.active = target;
        state.busy = true;
        state.waitMessage = false;
        state.message = this.label(area, 'scanning');
        state.tone = 'info';
        this.syncContentScan();
        this.announce(state.message);
        const answer = await postForm(this.ajaxUrls().a11y_scan_page, {pageUid: String(target.pageUid), languageUid: String(target.languageUid)});
        const current = this.contentScanArea() ?? area;
        const hadFocus = this.win.document.activeElement === button || !this.win.document.body.contains(button)
            || this.win.document.activeElement === this.win.document.body;
        const stillCurrent = this.contentScanIsCurrent();
        state.busy = false;
        state.active = null;
        if (!stillCurrent) {
            // The user moved to another page or language meanwhile: that page's section shows its own state, never the
            // earlier page's result. The toolbar status is refreshed all the same.
            // Focus is not moved: the user never activated the new page's button.
            state.message = '';
            state.tone = '';
            this.syncContentScan();
            this.onScanFinished({contentScan: true, refocus: false});
            return;
        }
        if (answer.ok) {
            state.message = contentScanResultMessage(answer.data, (key, fallback = '') => this.label(current, key, fallback));
            state.tone = 'ok';
        } else {
            const key = errorLabelKey(answer.code);
            state.message = this.label(current, key === 'alreadyRunning' ? 'alreadyRunning' : key, this.label(current, 'error'));
            state.tone = answer.code === 'already_running' ? 'info' : 'error';
        }
        this.syncContentScan({refocus: hadFocus});
        this.announce(state.message);
        // The section's summary, status and the toolbar icon come from the status refresh, applied right away.
        this.onScanFinished({contentScan: true, refocus: hadFocus});
    }

    slot() {
        return this.win.document.querySelector(this.slotSelector);
    }

    label(root, key, fallback = '') {
        const dataKey = `label${key.charAt(0).toUpperCase()}${key.slice(1)}`;
        return root?.dataset?.[dataKey] || fallback;
    }

    async load() {
        const slot = this.slot();
        const url = this.ajaxUrls().a11y_toolbar_assistant;
        if (!slot || !url) {
            return;
        }
        const ticket = ++this.request;
        // Opening the dropdown moves focus out of the editing form; editors (CodeMirror for Plain HTML) mark their
        // field as changed in their blur handler. Read the form after those handlers ran.
        await new Promise((resolve) => this.win.setTimeout(resolve, 60));
        if (ticket !== this.request) {
            return;
        }
        const frameWindow = contentWindowOf(this.win);
        const context = frameWindow ? collectAssistantContext(frameWindow, this.treePage()) : {};
        // "Ask AQG" sends the same identifiers; the server resolves and permission-checks them again.
        this.context = context;
        const target = new URL(url, this.win.location.origin);
        Object.entries(context).forEach(([key, value]) => target.searchParams.set(key, value));

        slot.hidden = false;
        if (!slot.querySelector('[data-aqt-assistant-root]')) {
            slot.replaceChildren(el('p', {class: 'aqt-assistant__loading', role: 'status', text: this.label(slot, 'loading')}));
        }
        let html = '';
        try {
            const response = await fetch(target.toString(), {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}});
            html = response.ok ? await response.text() : '';
        } catch {
            html = '';
        }
        if (ticket !== this.request) {
            return;
        }
        const hadFocus = slot.contains(this.win.document.activeElement);
        if (html === '') {
            this.setConversationScope('', '');
            const retry = el('button', {type: 'button', class: 'aqt-btn', text: this.label(slot, 'reload')});
            retry.addEventListener('click', () => this.load());
            slot.replaceChildren(el('div', {class: 'aqt-assistant__loading'}, el('p', {role: 'status', text: this.label(slot, 'loadFailed')}), retry));
            this.setContentScanOffer(0, 0);
            if (hadFocus) {
                retry.focus({preventScroll: true});
            }
            return;
        }
        slot.innerHTML = html;
        const root = slot.querySelector('[data-aqt-assistant-root]');
        this.setContentScanOffer(Number(root?.dataset.contentScanPage ?? 0), Number(root?.dataset.contentScanLanguage ?? 0));
        this.bind(root);
        if (hadFocus) {
            slot.querySelector('#aqt-assistant-title')?.setAttribute('tabindex', '-1');
            slot.querySelector('#aqt-assistant-title')?.focus({preventScroll: true});
        }
    }

    bind(root) {
        if (!root) {
            return;
        }
        this.bindAsk(root);
        root.addEventListener('click', (event) => {
            const button = event.target.closest('[data-aqt-action]');
            if (!button || !root.contains(button) || button.getAttribute('aria-busy') === 'true') {
                return;
            }
            event.preventDefault();
            this.run(root, button);
        });
    }

    async run(root, button) {
        const action = button.dataset.aqtAction;
        const finding = button.closest('[data-aqt-finding]');
        const findingUid = finding ? Number(finding.dataset.aqtFinding) : 0;
        // aria-busy (not disabled) guards against a second request: a disabled button would drop the keyboard focus.
        button.setAttribute('aria-busy', 'true');
        try {
            switch (action) {
                case 'explain':
                    await this.explain(root, button, finding, findingUid);
                    break;
                case 'prepare':
                    await this.prepare(root, finding, findingUid);
                    break;
                case 'suggest-alt':
                    await this.suggestAlt(root, finding, findingUid);
                    break;
                case 'apply-alt':
                    await this.applyAlt(root, finding, findingUid);
                    break;
                case 'mark-decorative':
                    await this.markDecorative(root, finding, findingUid);
                    break;
                case 'scan':
                    await this.scan(root, button);
                    break;
                case 'show-field':
                    this.showField(root, finding, button.dataset.field ?? '');
                    break;
            }
        } finally {
            button.removeAttribute('aria-busy');
        }
    }

    result(finding) {
        return finding?.querySelector('[data-aqt-result]') ?? null;
    }

    say(root, finding, text, tone = 'info', ...extra) {
        const region = this.result(finding);
        if (region) {
            region.hidden = false;
            region.replaceChildren(el('p', {class: `aqt-result aqt-result--${tone}`, text}), ...extra);
        }
        const live = root.querySelector('[data-aqt-live]');
        if (live) {
            live.textContent = text;
        }
    }

    /** Keeps keyboard focus in the finding when the control that had it goes away. */
    focusResult(finding) {
        const region = this.result(finding);
        if (region) {
            region.setAttribute('tabindex', '-1');
            region.focus({preventScroll: true});
        }
    }

    failed(root, finding, code) {
        this.say(root, finding, this.label(root, errorLabelKey(code), this.label(root, 'error')), 'error');
    }

    async explain(root, button, finding, findingUid) {
        const region = this.result(finding);
        if (region?.dataset.aqtExplained === '1') {
            region.hidden = !region.hidden;
            button.setAttribute('aria-expanded', region.hidden ? 'false' : 'true');
            return;
        }
        this.say(root, finding, this.label(root, 'explaining'));
        button.setAttribute('aria-expanded', 'true');
        const answer = await postRemediation(this.ajaxUrls().a11y_remediation_explain, {findingUid});
        if (!answer.ok) {
            this.failed(root, finding, answer.code);
            return;
        }
        const data = answer.data;
        const nodes = [el('p', {class: 'aqt-result__head'}, el('strong', {text: this.label(root, 'explanation')}))];
        if (data.status === 'explanation') {
            nodes.push(el('p', {text: String(data.explanation ?? '')}));
            if (data.howToFix) {
                nodes.push(el('p', {text: String(data.howToFix)}));
            }
        } else {
            nodes.push(el('p', {text: this.label(root, 'explainNone')}));
        }
        nodes.push(el('p', {class: 'aqt-assistant__muted', text: this.label(root, 'explainNote')}));
        const sent = Array.isArray(data.sent) ? data.sent.map(String) : [];
        if (sent.length > 0) {
            nodes.push(el('details', {class: 'aqt-result__sent'}, el('summary', {text: this.label(root, 'sent')}), el('ul', {}, sent.map((field) => el('li', {text: field})))));
        }
        region.hidden = false;
        region.replaceChildren(...nodes);
        // Every answer is kept: asking again would cost again.
        region.dataset.aqtExplained = '1';
        button.setAttribute('aria-expanded', 'true');
        const live = root.querySelector('[data-aqt-live]');
        if (live) {
            live.textContent = this.label(root, 'explanation');
        }
    }

    async prepare(root, finding, findingUid) {
        this.say(root, finding, this.label(root, 'preparing'));
        const urls = this.ajaxUrls();
        const prepared = await postRemediation(urls.a11y_remediation_prepare, {findingUids: [findingUid], scope: 'single'});
        if (!prepared.ok) {
            this.failed(root, finding, prepared.code);
            return;
        }
        const entry = (prepared.data.items ?? []).find((item) => Number(item.findingUid) === findingUid) ?? prepared.data.items?.[0];
        if (!entry || (entry.outcome !== 'queued' && entry.outcome !== 'already_queued')) {
            const reason = String(entry?.reason ?? 'generic');
            // The server decides per occurrence whether an AI change can be applied safely; offering it again is pointless.
            if (['manual_only', 'no_safe_action', 'target_changed', 'target_missing'].includes(reason)) {
                this.say(root, finding, this.label(root, 'prepareManual'), 'warning');
                this.removePrepare(finding);
                return;
            }
            this.failed(root, finding, reason);
            return;
        }
        let status = entry.outcome === 'already_queued' ? 'queued' : 'preparing';
        if (entry.outcome === 'queued') {
            const current = await postRemediation(urls.a11y_remediation_item, {item: entry.itemUid, detailed: false});
            if (current.ok && current.data.item?.status === 'preparing') {
                const generated = await postRemediation(urls.a11y_remediation_generate, {item: entry.itemUid, rowVersion: current.data.item.rowVersion, detailed: false});
                status = generated.ok && !generated.data.retryAfter ? String(generated.data.item?.status ?? 'preparing') : 'preparing';
            } else if (current.ok) {
                status = String(current.data.item?.status ?? 'preparing');
            }
        }
        const queueUrl = root.querySelector('a[data-aqt-queue]')?.getAttribute('href') ?? '';
        const link = queueUrl !== '' ? el('a', {class: 'aqt-btn aqt-btn--link', href: queueUrl, 'data-aqt-navigate': 'true', text: this.label(root, 'review')}) : null;
        if (status === 'pending_review') {
            this.say(root, finding, this.label(root, 'prepared'), 'ok', ...(link ? [link] : []));
        } else if (status === 'preparing' || status === 'queued') {
            this.say(root, finding, this.label(root, 'queued'), 'info', ...(link ? [link] : []));
        } else {
            this.say(root, finding, this.label(root, 'prepareNone'), 'warning');
        }
        this.removePrepare(finding);
    }

    removePrepare(finding) {
        const button = finding.querySelector('[data-aqt-action="prepare"]');
        if (button && button === this.win.document.activeElement) {
            this.focusResult(finding);
        }
        button?.remove();
    }

    imageCard(finding) {
        return finding?.querySelector('[data-aqt-image]') ?? null;
    }

    async suggestAlt(root, finding, findingUid) {
        const card = this.imageCard(finding);
        this.say(root, finding, this.label(root, 'busy'));
        const answer = await postForm(this.ajaxUrls().a11y_ai_suggest_alt, {findingId: findingUid});
        if (!answer.ok) {
            this.failed(root, finding, answer.code);
            return;
        }
        const input = card?.querySelector('[data-aqt-alt]');
        if (input && typeof answer.data.suggestion === 'string' && answer.data.suggestion !== '') {
            input.value = answer.data.suggestion;
            if (answer.data.expectedVersion) {
                card.dataset.expectedVersion = String(answer.data.expectedVersion);
            }
            input.focus({preventScroll: true});
            this.say(root, finding, this.label(root, 'suggested'), 'info');
        } else {
            this.say(root, finding, this.label(root, 'prepareNone'), 'warning');
        }
    }

    async applyAlt(root, finding, findingUid) {
        const card = this.imageCard(finding);
        const input = card?.querySelector('[data-aqt-alt]');
        const altText = String(input?.value ?? '').trim();
        if (altText === '') {
            this.failed(root, finding, 'invalid_input');
            input?.focus({preventScroll: true});
            return;
        }
        const answer = await postForm(this.ajaxUrls().a11y_image_apply_alt, {findingId: findingUid, altText, expectedVersion: card?.dataset.expectedVersion ?? ''});
        if (!answer.ok) {
            this.failed(root, finding, answer.code);
            return;
        }
        this.finishImage(root, finding, 'applied');
    }

    async markDecorative(root, finding, findingUid) {
        const card = this.imageCard(finding);
        const answer = await postForm(this.ajaxUrls().a11y_image_mark_decorative, {findingId: findingUid, expectedVersion: card?.dataset.expectedVersion ?? ''});
        if (!answer.ok) {
            this.failed(root, finding, answer.code);
            return;
        }
        this.finishImage(root, finding, 'decorative');
    }

    finishImage(root, finding, labelKey) {
        const card = this.imageCard(finding);
        card?.querySelectorAll('button, textarea').forEach((element) => {
            element.setAttribute('aria-disabled', 'true');
            if (element.tagName === 'TEXTAREA') {
                element.readOnly = true;
            } else {
                element.removeAttribute('data-aqt-action');
            }
        });
        this.say(root, finding, this.label(root, labelKey), 'ok');
    }

    async scan(root, button) {
        // Announced once in the live region; the busy button keeps its name (aria-busy is set by run()).
        const live = root.querySelector('[data-aqt-live]');
        if (live) {
            live.textContent = this.label(root, 'scanning');
        }
        const answer = await postForm(this.ajaxUrls().a11y_scan_page, {pageUid: button.dataset.pageUid ?? '', languageUid: button.dataset.languageUid ?? '0'});
        if (!answer.ok) {
            if (live) {
                live.textContent = this.label(root, errorLabelKey(answer.code), this.label(root, 'error'));
            }
            return;
        }
        this.onScanFinished();
        await this.load();
        // A live region only announces changes after it is in the page: set the text once it has been rendered.
        this.win.setTimeout(() => {
            const reloaded = this.slot()?.querySelector('[data-aqt-live]');
            if (reloaded) {
                reloaded.textContent = this.label(this.slot()?.querySelector('[data-aqt-assistant-root]'), 'scanned');
            }
        }, 150);
    }

    /**
     * "Ask AQG". Quick questions (data-aqt-ask-intent) are answered from the stored findings without AI; own questions
     * are sent only when the user submits the form. The browser keeps the conversation in sessionStorage (bounded by
     * the limits the server published) under the server's opaque scope key (user + login session + context), sends at
     * most those earlier turns back with their server tokens, and nothing else. Another user or login in this tab drops
     * every stored conversation; a context without its own history starts empty. The server binds the conversation id
     * to user, login and context, verifies each turn token and counts AI questions and cost from its usage rows.
     */
    bindAsk(root) {
        const section = root.querySelector('[data-aqt-ask]');
        const form = section?.querySelector('[data-aqt-ask-form]');
        if (!section || !form) {
            this.setConversationScope('', '');
            return;
        }
        this.askLimits = askLimits(section);
        this.setConversationScope(section.dataset.principal ?? '', section.dataset.scope ?? '');
        // The context and scope this view was rendered for: a question asked on it belongs to them, even if load() has
        // already moved on to the next context while that view is still on screen.
        this.askViews ??= new WeakMap();
        this.askViews.set(form, {context: {...(this.context ?? {})}, scope: this.conversationScope});
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            this.ask(root, form, {});
        });
        section.querySelectorAll('[data-aqt-ask-intent]').forEach((button) => {
            button.addEventListener('click', () => this.ask(root, form, {intent: button.dataset.aqtAskIntent, question: button.textContent.trim(), button}));
        });
        form.querySelector('[data-aqt-ask-clear]')?.addEventListener('click', (event) => {
            this.storeConversation({conversation: '', turns: []});
            const log = section.querySelector('[data-aqt-ask-log]');
            log?.replaceChildren();
            if (log) {
                log.hidden = true;
            }
            event.currentTarget.hidden = true;
            const usage = form.querySelector('[data-aqt-ask-usage]');
            if (usage) {
                usage.hidden = true;
                usage.textContent = '';
            }
            const status = form.querySelector('[data-aqt-ask-status]');
            if (status) {
                status.textContent = this.label(root, 'cleared');
                delete status.dataset.tone;
            }
            (form.querySelector('[data-aqt-ask-input]') ?? section.querySelector('[data-aqt-ask-intent]'))?.focus({preventScroll: true});
        });
        // A conversation of this context from earlier in this browser tab (sessionStorage only).
        const stored = this.storedConversation();
        if (stored.turns.length > 0) {
            const log = section.querySelector('[data-aqt-ask-log]');
            stored.turns.forEach((turn) => log?.append(this.turn(root, turn)));
            if (log) {
                log.hidden = false;
            }
            form.querySelector('[data-aqt-ask-clear]')?.removeAttribute('hidden');
        }
    }

    /**
     * The server's opaque keys for this view: `principal` (backend user + login session) and `scope` (principal + the
     * resolved site/page/language/record/file context). Another principal than before means another user or a new
     * login in this tab: every stored and in-memory conversation is dropped before anything is shown.
     */
    setConversationScope(principal, scope) {
        const nextPrincipal = OPAQUE_KEY.test(String(principal ?? '')) ? String(principal) : '';
        const nextScope = nextPrincipal !== '' && OPAQUE_KEY.test(String(scope ?? '')) ? String(scope) : '';
        if (nextPrincipal === '') {
            // A view without "Ask AQG" (or a failed load) names no principal: nothing is shown, stored or sent there.
            this.conversationScope = '';
            return;
        }
        let storage = null;
        try {
            storage = this.win.sessionStorage;
        } catch {
            storage = null;
        }
        let storedPrincipal = null;
        try {
            storedPrincipal = storage?.getItem(ASK_PRINCIPAL_KEY) ?? null;
        } catch {
            storedPrincipal = null;
        }
        if (nextPrincipal !== this.principal || (storedPrincipal !== null && storedPrincipal !== nextPrincipal)) {
            this.memoryConversations.clear();
            if (storage && storedPrincipal !== nextPrincipal) {
                purgeAskStorage(storage);
            }
        }
        if (storage && storedPrincipal !== nextPrincipal) {
            try {
                storage.setItem(ASK_PRINCIPAL_KEY, nextPrincipal);
            } catch {
                // Memory only.
            }
        }
        this.principal = nextPrincipal;
        this.conversationScope = nextScope;
    }

    /** The storage key of the current scope ('' without one: then nothing is stored or replayed). */
    storageKey(scope = this.conversationScope) {
        return OPAQUE_KEY.test(String(scope ?? '')) ? ASK_STORAGE_PREFIX + scope : '';
    }

    storedConversation(scope = this.conversationScope) {
        const empty = {conversation: '', turns: []};
        const key = this.storageKey(scope);
        if (key === '') {
            return empty;
        }
        const limit = this.askLimits?.maxHistoryTurns ?? DEFAULT_LIMITS.maxHistoryTurns;
        const valid = (data) => data && typeof data === 'object' && Array.isArray(data.turns)
            ? {conversation: typeof data.conversation === 'string' ? data.conversation : '', turns: data.turns.slice(-limit)}
            : null;
        try {
            const stored = valid(JSON.parse(this.win.sessionStorage.getItem(key) ?? 'null'));
            if (stored) {
                return stored;
            }
        } catch {
            // No storage (private mode, blocked): the conversation lives only in the page.
        }
        // Only this scope's in-memory copy, never another context's or user's.
        return valid(this.memoryConversations.get(scope)) ?? empty;
    }

    storeConversation(conversation, scope = this.conversationScope) {
        const key = this.storageKey(scope);
        if (key === '') {
            return;
        }
        this.memoryConversations.set(scope, conversation);
        try {
            this.win.sessionStorage.setItem(key, JSON.stringify(conversation));
        } catch {
            // Kept in memory only.
        }
    }

    async ask(root, form, {intent = '', question: given = '', button = null, repairTarget = ''}) {
        const input = form.querySelector('[data-aqt-ask-input]');
        const submit = form.querySelector('[data-aqt-ask-submit]');
        const status = form.querySelector('[data-aqt-ask-status]');
        const trigger = button ?? submit;
        if (this.askPending) {
            return;
        }
        const question = (intent !== '' ? given : String(input?.value ?? '')).replace(/\s+/g, ' ').trim();
        const limits = this.askLimits;
        if ((intent === '' || intent === 'repair') && (question.length < limits.minQuestionChars || question.length > limits.maxQuestionChars)) {
            if (status) {
                status.textContent = this.label(root, 'errorQuestion');
                status.dataset.tone = 'error';
            }
            input?.setAttribute('aria-invalid', 'true');
            input?.focus({preventScroll: true});
            return;
        }
        input?.removeAttribute('aria-invalid');
        const scope = form.querySelector('input[name="aqt-ask-scope"]:checked, input[type="hidden"][name="aqt-ask-scope"]')?.value === 'site' ? 'site' : 'page';
        // The conversation and context of the view this form belongs to; its answer is stored there even if the user moves on.
        const view = this.askViews?.get(form) ?? {context: this.context ?? {}, scope: this.conversationScope};
        const conversationScope = view.scope;
        const stored = this.storedConversation(conversationScope);
        this.askPending = true;
        trigger?.setAttribute('aria-busy', 'true');
        // Loading: the question stays readable and focusable (readOnly, not disabled) until the answer arrives.
        form.setAttribute('aria-busy', 'true');
        if (input) {
            input.readOnly = true;
        }
        if (status) {
            status.textContent = this.label(root, intent !== '' ? 'answering' : 'asking');
            delete status.dataset.tone;
        }
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        const timer = controller ? this.win.setTimeout(() => controller.abort(), (limits.maxSecondsPerQuestion + 15) * 1000) : null;
        const answer = await postRemediation(this.ajaxUrls().a11y_toolbar_ask, {
            question,
            scope,
            intent,
            context: view.context,
            history: historyFor(stored.turns, limits),
            conversation: stored.conversation,
            ...(repairTarget !== '' ? {repairTarget} : {}),
        }, controller ? {signal: controller.signal} : {});
        if (timer !== null) {
            this.win.clearTimeout(timer);
        }
        this.askPending = false;
        trigger?.removeAttribute('aria-busy');
        form.removeAttribute('aria-busy');
        if (input) {
            input.readOnly = false;
        }
        if (!answer.ok) {
            if (status) {
                status.textContent = this.label(root, askErrorLabelKey(answer.code), this.label(root, 'error'));
                // Presentation only: a failed question reads as an error, not as the neutral "Asking…" line.
                status.dataset.tone = 'error';
            }
            if (['conversation_limit', 'conversation_budget'].includes(answer.code)) {
                form.querySelector('[data-aqt-ask-clear]')?.removeAttribute('hidden');
            }
            return;
        }
        if (answer.data.status === 'invalid_response') {
            if (status) {
                status.textContent = this.label(root, 'invalid');
                status.dataset.tone = 'error';
            }
            this.showUsage(root, form, answer.data.conversation, stored);
            return;
        }
        // The log announces the new turn; the status is emptied so the answer is not read twice.
        if (status) {
            status.textContent = '';
            delete status.dataset.tone;
        }
        if (answer.data.intent === 'repair' && answer.data.repair && typeof answer.data.repair === 'object') {
            // A repair plan has live controls: it is shown, never stored or replayed (AI Fixes holds the real state).
            const log = root.querySelector('[data-aqt-ask-log]');
            if (log) {
                log.hidden = false;
                const article = this.repairTurn(root, form, answer.data, view);
                log.append(article);
                article.scrollIntoView?.({block: 'nearest'});
            }
            if (input && intent === '') {
                input.value = '';
            }
            form.querySelector('[data-aqt-ask-clear]')?.removeAttribute('hidden');
            return;
        }
        const turn = {...answer.data};
        const log = root.querySelector('[data-aqt-ask-log]');
        if (log) {
            log.hidden = false;
            log.append(this.turn(root, turn));
            log.lastElementChild?.scrollIntoView?.({block: 'nearest'});
        }
        if (input && intent === '') {
            input.value = '';
        }
        this.storeConversation({
            conversation: typeof answer.data.conversation?.id === 'string' ? answer.data.conversation.id : stored.conversation,
            turns: [...stored.turns, turn].slice(-limits.maxHistoryTurns),
        }, conversationScope);
        this.showUsage(root, form, answer.data.conversation, stored);
        form.querySelector('[data-aqt-ask-clear]')?.removeAttribute('hidden');
    }

    /**
     * Ask AQG's repair answer: what AQG found for the target, what the existing AI Fixes workflow can prepare (re-checked
     * in the saved content just now), what needs manual work and why, the staleness of the stored check, the estimated
     * maximum AI cost and the real "Prepare" action. Built as text nodes only; every link is a server-built backend URL.
     */
    repairTurn(root, form, data, view) {
        const repair = data.repair;
        const items = Array.isArray(repair.items) ? repair.items : [];
        const ai = repair.ai && typeof repair.ai === 'object' ? repair.ai : {};
        const counts = repair.counts && typeof repair.counts === 'object' ? repair.counts : {};
        this.repairSequence = (this.repairSequence ?? 0) + 1;
        const id = `aqt-repair-${this.repairSequence}`;
        const article = el('article', {class: 'aqt-turn aqt-turn--repair', 'aria-labelledby': `${id}-head`, 'data-aqt-repair': 'true', 'data-aqt-repair-target': String(repair.target ?? '')});
        const status = el('p', {class: 'aqt-repair__status', role: 'status', 'aria-live': 'polite', 'data-aqt-repair-status': 'true'});

        article.append(
            el('p', {class: 'aqt-turn__q'}, el('span', {class: 'aqt-turn__who', text: `${this.label(root, 'youAsked')}: `}), String(data.question ?? '')),
            el('p', {class: 'aqt-turn__who', text: [this.label(root, 'answerAqg'), this.label(root, 'repairTitle')].filter(Boolean).join(' · ')}),
            el('p', {class: 'aqt-repair__head', id: `${id}-head`}, el('strong', {text: String(repair.headline ?? '')})),
            el('p', {class: 'aqt-repair__target aqt-assistant__muted', text: String(repair.targetLabel ?? '')}),
        );
        const notices = Array.isArray(repair.notices) ? repair.notices.map(String).filter(Boolean) : [];
        if (notices.length > 0) {
            article.append(el('ul', {class: 'aqt-repair__notices'}, notices.map((notice) => el('li', {text: notice}))));
        }
        const alternatives = Array.isArray(repair.alternatives) ? repair.alternatives : [];
        if (alternatives.length > 0) {
            article.append(el('p', {class: 'aqt-repair__switch'}, alternatives.map((alternative) => el('button', {
                type: 'button',
                class: 'aqt-btn',
                text: String(alternative.label ?? ''),
                onclick: (event) => this.ask(root, form, {intent: 'repair', question: String(data.question ?? ''), button: event.currentTarget, repairTarget: String(alternative.target ?? '')}),
            }))));
        }
        const summary = Array.isArray(repair.summary) ? repair.summary.map(String) : [];
        if (summary.length > 0) {
            article.append(el('ul', {class: 'aqt-turn__list aqt-repair__summary'}, summary.map((line) => el('li', {text: line}))));
        }
        const freshness = repair.freshness && typeof repair.freshness === 'object' ? repair.freshness : {};
        if (freshness.text) {
            const warn = Boolean(freshness.stale || freshness.never);
            const line = el('p', {class: `aqt-repair__fresh${warn ? ' aqt-repair__fresh--warning' : ''}`, text: String(freshness.text)});
            article.append(line);
            if (freshness.canCheck && Number(freshness.pageUid) > 0) {
                article.append(el('p', {class: 'aqt-repair__actions'}, el('button', {
                    type: 'button',
                    class: 'aqt-btn',
                    'data-aqt-repair-check': 'true',
                    text: this.label(root, 'repairCheck'),
                    onclick: () => this.repairCheck(root, status),
                })));
            }
        }
        if (!ai.ready && ai.reason) {
            article.append(el('p', {class: 'aqt-repair__ai aqt-repair__fresh--warning', text: String(ai.reason)}));
        }

        const list = el('ul', {class: 'aqt-repair__items'});
        items.forEach((item) => list.append(this.repairItem(root, item, repair, article, form, view, status)));
        if (items.length > 0) {
            article.append(list);
        }

        const ready = Array.isArray(repair.prepareIds) ? repair.prepareIds.map(Number).filter((value) => value > 0) : [];
        if (ready.length > 0 && ai.ready) {
            const cost = repair.cost && typeof repair.cost === 'object' ? repair.cost : {};
            const costText = cost.total
                ? format(this.label(root, ready.length === 1 ? 'repairCostOne' : 'repairCost'), String(cost.total), ready.length)
                : (cost.unknown ? this.label(root, 'repairCostUnknown') : '');
            const costId = `${id}-cost`;
            if (costText !== '') {
                article.append(el('p', {class: 'aqt-repair__cost aqt-assistant__muted', id: costId, text: costText}));
            }
            if (repair.bulk || ready.length === 1) {
                // One step for every ready finding: only with the AI queue permission (or a single finding), as in Page Detail.
                article.append(el('p', {class: 'aqt-repair__actions'}, el('button', {
                    type: 'button',
                    class: 'aqt-btn aqt-btn--primary',
                    'data-aqt-repair-prepare': 'all',
                    'aria-describedby': costText !== '' ? costId : null,
                    text: ready.length === 1 ? this.label(root, 'repairPrepareOne') : format(this.label(root, 'repairPrepare'), ready.length),
                    onclick: (event) => this.repairPrepare(root, article, repair, view, ready, event.currentTarget, status),
                })));
            } else {
                article.append(el('p', {class: 'aqt-repair__note aqt-assistant__muted', text: this.label(root, 'repairOneByOne')}));
            }
        }
        if (repair.queueUrl && safeBackendHref(repair.queueUrl, this.win) && (Number(counts.queued ?? 0) > 0 || Number(counts.saved ?? 0) > 0)) {
            article.append(el('p', {class: 'aqt-repair__actions'}, el('a', {href: repair.queueUrl, class: 'aqt-turn__link', 'data-aqt-navigate': 'true', text: this.label(root, 'repairOpenQueue')})));
        }
        if (repair.editUrl && safeBackendHref(repair.editUrl, this.win)) {
            article.append(el('p', {class: 'aqt-repair__actions'}, el('a', {href: repair.editUrl, class: 'aqt-turn__link', 'data-aqt-navigate': 'true', 'data-aqt-return': 'true', text: this.label(root, 'repairEditElement')})));
        }
        article.append(el('p', {class: 'aqt-repair__safety', text: this.label(root, 'repairSafety')}), status);
        article.append(el('p', {class: 'aqt-turn__basis', text: this.label(root, 'repairBasis')}));

        return article;
    }

    /** One finding of the repair plan: what it is, where, its state and reason, and the actions that exist for it. */
    repairItem(root, item, repair, article, form, view, status) {
        const state = ['ready', 'queued', 'saved', 'manual', 'blocked', 'stale'].includes(item.state) ? item.state : 'manual';
        const row = el('li', {class: `aqt-repair__item aqt-repair__item--${state}`, 'data-aqt-repair-item': String(Number(item.id) || 0)});
        const tone = ['critical', 'warning', 'info'].includes(item.tone) ? item.tone : 'info';
        row.append(
            el('p', {class: 'aqt-repair__item-title'},
                el('span', {class: `aqt-sev aqt-sev--${tone}`}, el('span', {class: 'aqt-sev__dot', 'aria-hidden': 'true'}), String(item.severityLabel ?? '')),
                ' ',
                el('span', {text: String(item.title ?? '')}),
            ),
        );
        if (item.location) {
            row.append(el('p', {class: 'aqt-assistant__muted', text: String(item.location)}));
        }
        row.append(el('p', {class: 'aqt-repair__state-line'},
            el('span', {class: `aqt-repair__state aqt-repair__state--${state}`, 'data-aqt-repair-state': 'true', text: String(item.stateLabel ?? '')}),
            item.cost ? el('span', {class: 'aqt-assistant__muted', text: ` · ${format(this.label(root, 'repairItemCost'), String(item.cost))}`}) : null,
        ));
        const reason = el('p', {class: 'aqt-repair__reason', 'data-aqt-repair-result': 'true', text: String(item.reason ?? '')});
        row.append(reason);
        if (['manual', 'blocked', 'stale'].includes(state) && item.howToFix) {
            row.append(el('p', {class: 'aqt-assistant__muted', text: String(item.howToFix)}));
        }
        const links = el('p', {class: 'aqt-repair__links', 'data-aqt-repair-links': 'true'});
        if (item.reviewUrl && safeBackendHref(item.reviewUrl, this.win)) {
            // "Review suggestion" only for a proposal waiting for review; any other item is opened as it is.
            links.append(el('a', {href: item.reviewUrl, class: 'aqt-turn__link', 'data-aqt-navigate': 'true', text: this.label(root, item.itemStatus === 'pending_review' ? 'repairReview' : 'repairOpenItem')}), ' ');
        }
        if (state !== 'ready' && state !== 'queued' && item.editUrl && safeBackendHref(item.editUrl, this.win)) {
            links.append(el('a', {href: item.editUrl, class: 'aqt-turn__link', 'data-aqt-navigate': 'true', 'data-aqt-return': 'true', text: this.label(root, 'edit')}), ' ');
        }
        if (item.detailUrl && safeBackendHref(item.detailUrl, this.win)) {
            links.append(el('a', {href: item.detailUrl, class: 'aqt-turn__link', 'data-aqt-navigate': 'true', text: this.label(root, 'details')}));
        }
        if (state === 'ready' && repair.ai?.ready && !repair.bulk && (repair.prepareIds ?? []).length > 1) {
            // Without the AI queue permission each suggestion is its own explicit request, as on Page Detail.
            links.prepend(el('button', {
                type: 'button',
                class: 'aqt-btn',
                'data-aqt-repair-prepare': String(Number(item.id)),
                text: this.label(root, 'repairPrepareOne'),
                onclick: (event) => this.repairPrepare(root, article, repair, view, [Number(item.id)], event.currentTarget, status),
            }), ' ');
        }
        if (links.childNodes.length > 0) {
            row.append(links);
        }

        return row;
    }

    /** "Check saved page now": the Content scan section's own action (PI-04 target handling), opened and started. */
    repairCheck(root, status) {
        const area = this.contentScanArea();
        const button = area?.querySelector('[data-aqt-content-scan-button]');
        if (!area || !button || area.hidden) {
            status.textContent = this.label(root, 'repairCheckUnavailable');
            return;
        }
        const pop = this.slot()?.closest('.aqt-pop') ?? this.win.document;
        const toggle = pop.querySelector('[data-aqt-disclosure="content"]');
        if (toggle && toggle.getAttribute('aria-expanded') !== 'true') {
            toggle.click();
        }
        button.focus({preventScroll: false});
        this.contentScan(button);
        status.textContent = this.label(root, 'repairCheckStarted');
    }

    /**
     * "Prepare": creates the AI Fixes items through the toolbar route (scope re-checked, no AI request), then asks the
     * existing generate route once per new item with the row version the server returned. A second click is ignored
     * (aria-busy); a retry with an old row version is refused by the workflow, so nothing is paid twice.
     */
    async repairPrepare(root, article, repair, view, ids, button, status) {
        if (article.dataset.aqtRepairBusy === '1' || button.getAttribute('aria-busy') === 'true') {
            return;
        }
        const hadFocus = this.win.document.activeElement === button;
        article.dataset.aqtRepairBusy = '1';
        button.setAttribute('aria-busy', 'true');
        status.textContent = this.label(root, 'repairPreparing');
        const urls = this.ajaxUrls();
        const prepared = await postRemediation(urls.a11y_toolbar_repair_prepare, {context: view.context, target: String(repair.target ?? ''), findingIds: ids});
        if (!prepared.ok) {
            article.dataset.aqtRepairBusy = '';
            button.removeAttribute('aria-busy');
            status.textContent = this.label(root, prepared.code === 'busy' ? 'repairBusy' : askErrorLabelKey(prepared.code), this.label(root, 'error'));
            return;
        }
        const results = Array.isArray(prepared.data.items) ? prepared.data.items : [];
        const tally = {review: 0, attention: 0, costs: [], costMicro: 0, costMicroComplete: true, unknownCost: false};
        let step = 0;
        const toGenerate = results.filter((result) => result.generate && Number(result.itemUid) > 0).length;
        for (const result of results) {
            const row = article.querySelector(`[data-aqt-repair-item="${Number(result.id)}"]`);
            if (result.generate && Number(result.itemUid) > 0) {
                step++;
                this.repairRow(root, row, {state: 'ready', stateLabel: this.label(root, 'repairGenerating'), reason: ''});
                status.textContent = format(this.label(root, 'repairProgress'), step, toGenerate);
                const generated = await postRemediation(urls.a11y_remediation_generate, {item: Number(result.itemUid), rowVersion: Number(result.rowVersion), detailed: false});
                const usage = generated.data?.aiUsage;
                if (usage?.sent) {
                    if (usage.costKnown && usage.cost) {
                        tally.costs.push(String(usage.cost));
                        if (Number.isFinite(Number(usage.costMicro))) {
                            tally.costMicro += Math.max(0, Number(usage.costMicro));
                        } else {
                            tally.costMicroComplete = false;
                        }
                    } else {
                        tally.unknownCost = true;
                    }
                }
                this.repairGenerated(root, row, result, generated, tally);
                continue;
            }
            if (Number(result.itemUid) > 0) {
                // Already in AI Fixes (also by an earlier click): its actual status, never another success.
                const ready = result.status === 'pending_review';
                tally[ready ? 'review' : 'attention']++;
                this.repairRow(root, row, {state: 'queued', stateLabel: String(result.statusLabel ?? ''), reason: this.label(root, 'repairAlreadyQueued'), reviewUrl: result.reviewUrl, ready});
                continue;
            }
            tally.attention++;
            this.repairRow(root, row, {state: 'blocked', stateLabel: this.label(root, 'repairNotPrepared'), reason: String(result.reasonLabel ?? '')});
        }
        article.querySelectorAll('[data-aqt-repair-prepare]').forEach((control) => {
            if (control === button || ids.length > 1 || control.dataset.aqtRepairPrepare === String(ids[0])) {
                control.remove();
            }
        });
        article.dataset.aqtRepairBusy = '';
        const parts = [format(this.label(root, 'repairDone'), tally.review, tally.attention)];
        if (tally.costs.length > 0) {
            // U-05: one total ("$0.01"), not one term per request ("< $0.01 + < $0.01 + …").
            const total = tally.costMicroComplete ? formatUsdMicro(tally.costMicro) : tally.costs.join(' + ');
            parts.push(format(this.label(root, 'repairCostRecorded'), total));
        }
        if (tally.unknownCost) {
            parts.push(this.label(root, 'repairCostRecordedUnknown'));
        }
        parts.push(this.label(root, 'repairNothingApplied'));
        status.textContent = parts.join(' ');
        status.classList.toggle('aqt-repair__status--partial', tally.attention > 0);
        if (hadFocus) {
            status.setAttribute('tabindex', '-1');
            status.focus({preventScroll: true});
        }
    }

    /** The outcome of one generate request, from the item the workflow returned (its real status and reason). */
    repairGenerated(root, row, result, generated, tally) {
        if (!generated.ok) {
            tally.attention++;
            const key = generated.code === 'in_progress' ? 'repairInProgress' : (generated.code === 'changed_concurrently' ? 'repairChanged' : askErrorLabelKey(generated.code));
            this.repairRow(root, row, {state: 'blocked', stateLabel: this.label(root, 'repairNotPrepared'), reason: this.label(root, key, this.label(root, 'error')), reviewUrl: result.reviewUrl});
            return;
        }
        const item = generated.data.item ?? {};
        const status = String(item.status ?? '');
        const reason = String(item.statusReasonLabel ?? '');
        if (status === 'pending_review') {
            tally.review++;
            this.repairRow(root, row, {state: 'queued', stateLabel: String(item.statusLabel ?? ''), reason: this.label(root, 'repairReady'), reviewUrl: result.reviewUrl, ready: true});
            return;
        }
        tally.attention++;
        if (status === 'preparing' && generated.data.retryAfter) {
            this.repairRow(root, row, {state: 'blocked', stateLabel: String(item.statusLabel ?? ''), reason: format(this.label(root, 'repairRetryLater'), Number(generated.data.retryAfter)), reviewUrl: result.reviewUrl});
            return;
        }
        this.repairRow(root, row, {
            state: status === 'not_applicable' ? 'stale' : 'blocked',
            stateLabel: String(item.statusLabel ?? ''),
            reason: reason !== '' ? reason : this.label(root, status === 'no_suggestion' ? 'repairNoSuggestion' : 'repairFailed'),
            reviewUrl: result.reviewUrl,
        });
    }

    /** Updates one finding row in place (state, reason, review link); its other links stay. */
    repairRow(root, row, {state, stateLabel, reason, reviewUrl = '', ready = false}) {
        if (!row) {
            return;
        }
        row.className = `aqt-repair__item aqt-repair__item--${state}`;
        const badge = row.querySelector('[data-aqt-repair-state]');
        if (badge) {
            badge.className = `aqt-repair__state aqt-repair__state--${state}`;
            badge.textContent = stateLabel;
        }
        const result = row.querySelector('[data-aqt-repair-result]');
        if (result) {
            result.textContent = reason;
        }
        if (reviewUrl && safeBackendHref(reviewUrl, this.win) && !row.querySelector('[data-aqt-repair-review]')) {
            let links = row.querySelector('[data-aqt-repair-links]');
            if (!links) {
                links = el('p', {class: 'aqt-repair__links', 'data-aqt-repair-links': 'true'});
                row.append(links);
            }
            links.querySelectorAll('a[href]').forEach((link) => {
                if (link.getAttribute('href') === reviewUrl) {
                    link.remove();
                }
            });
            links.prepend(el('a', {href: reviewUrl, class: 'aqt-turn__link', 'data-aqt-navigate': 'true', 'data-aqt-repair-review': 'true', text: this.label(root, ready ? 'repairReview' : 'repairOpenItem')}), ' ');
        }
    }

    /** AI questions and estimated cost of this conversation, as the server counted them from its usage rows. */
    showUsage(root, form, conversation, stored) {
        const usage = form.querySelector('[data-aqt-ask-usage]');
        if (!usage || !conversation || typeof conversation !== 'object') {
            return;
        }
        const parts = [format(this.label(root, 'usage'), Number(conversation.aiQuestions ?? 0), Number(conversation.maxAiQuestions ?? 0))];
        if (!conversation.costUnknown) {
            parts.push(format(this.label(root, 'usageCost'), money(conversation.spentMicroUsd), money(conversation.maxCostMicroUsd)));
        }
        if (conversation.limitReached) {
            parts.push(this.label(root, 'limitReached'));
        }
        usage.textContent = parts.join(' · ');
        usage.hidden = false;
    }

    /** One question and its answer, built as text: verified findings, general advice and data notes kept apart. */
    turn(root, data) {
        const kind = data.status === 'out_of_scope' ? 'outOfScope' : (data.status === 'needs_more_data' ? 'needsData' : '');
        const basis = [];
        if (data.source === 'aqg') {
            basis.push(this.label(root, 'deterministic'));
        } else {
            basis.push(data.basedOnFindings ? this.label(root, 'basedOn') : this.label(root, 'noFindingsUsed'));
        }
        if (data.generalAdvice) {
            basis.push(this.label(root, 'general'));
        }
        const citations = Array.isArray(data.citations) ? data.citations : [];
        const nodes = [
            el('p', {class: 'aqt-turn__q'}, el('span', {class: 'aqt-turn__who', text: `${this.label(root, 'youAsked')}: `}), String(data.question ?? '')),
            el('p', {class: 'aqt-turn__who', text: [this.label(root, data.source === 'aqg' ? 'answerAqg' : 'answer'), kind !== '' ? this.label(root, kind) : ''].filter(Boolean).join(' · ')}),
            ...answerNodes(data.answer),
        ];
        if (data.nextStep) {
            nodes.push(el('p', {class: 'aqt-turn__next'}, el('strong', {text: `${this.label(root, 'nextStep')}: `}), String(data.nextStep)));
        }
        nodes.push(el('p', {class: 'aqt-turn__basis', text: basis.join(' · ')}));
        if (citations.length > 0) {
            nodes.push(el('p', {class: 'aqt-turn__sources-title', text: this.label(root, 'sources')}));
            nodes.push(el('ul', {class: 'aqt-turn__sources'}, citations.map((citation) => this.citation(root, citation))));
        }
        const notes = Array.isArray(data.notes) ? data.notes.map(String) : [];
        if (notes.length > 0) {
            nodes.push(el('ul', {class: 'aqt-turn__notes'}, notes.map((note) => el('li', {text: note}))));
        }
        const sent = Array.isArray(data.sent) ? data.sent.map(String) : [];
        if (sent.length > 0) {
            nodes.push(el('details', {class: 'aqt-result__sent'}, el('summary', {text: this.label(root, 'sent')}), el('ul', {}, sent.map((field) => el('li', {text: field})))));
        }

        return el('article', {class: `aqt-turn${kind !== '' ? ' aqt-turn--' + kind : ''}`}, ...nodes);
    }

    /** A cited finding: links come from the server (same-origin backend routes), never from the model. */
    citation(root, citation) {
        const parts = [
            el('span', {class: `aqt-sev aqt-sev--${['critical', 'warning', 'info'].includes(citation.tone) ? citation.tone : 'info'}`}, el('span', {class: 'aqt-sev__dot', 'aria-hidden': 'true'}), String(citation.severityLabel ?? '')),
            ' ',
            safeBackendHref(citation.detailUrl, this.win)
                ? el('a', {href: citation.detailUrl, class: 'aqt-turn__link', 'data-aqt-navigate': 'true', text: String(citation.title ?? '')})
                : el('span', {text: String(citation.title ?? '')}),
        ];
        const count = Number(citation.count ?? 0);
        const facts = [count === 1 ? this.label(root, 'occurrence', '1 occurrence') : format(this.label(root, 'occurrences'), count)];
        if (Number(citation.pages ?? 0) > 0) {
            facts.push(format(this.label(root, 'pages'), Number(citation.pages)));
        }
        if (citation.pageTitle) {
            facts.push(format(this.label(root, 'mostOn'), String(citation.pageTitle)));
        }
        if (citation.sourceLabel) {
            facts.push(String(citation.sourceLabel));
        }
        parts.push(el('span', {class: 'aqt-assistant__muted', text: ` · ${facts.join(' · ')}`}));
        if (safeBackendHref(citation.editUrl, this.win)) {
            parts.push(' ', el('a', {href: citation.editUrl, class: 'aqt-turn__link', 'data-aqt-navigate': 'true', 'data-aqt-return': 'true', text: this.label(root, 'edit')}));
        }

        return el('li', {}, ...parts);
    }

    showField(root, finding, fieldName) {
        const frameWindow = contentWindowOf(this.win);
        const field = frameWindow ? findFormField(frameWindow.document, fieldName) : null;
        if (!field) {
            this.say(root, finding, this.label(root, 'fieldMissing'), 'warning');
            return;
        }
        field.item.scrollIntoView({block: 'center'});
        this.navigate?.(null);
        field.focusTarget?.focus?.({preventScroll: true});
    }
}
