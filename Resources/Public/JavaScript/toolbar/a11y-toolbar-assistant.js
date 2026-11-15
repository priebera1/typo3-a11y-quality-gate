import {aiDeclineReason, el, format, postRemediation} from '@priebera/a11y-quality-gate/remediation/remediation-api.js';
import {AskAqgDock} from '@priebera/a11y-quality-gate/toolbar/ask-aqg-dock.js';

/** Same rounding as AiMoney::formatUsd(): below one cent is "< $0.01", otherwise whole cents. */
/**
 * Whether the toolbar's approve saves on the server: the same rule as AI fixes and Page Detail (applyMode "server").
 * Rich text gets the same review as there: what changes, why, Before / After, the write note and the editor alternative.
 */
const directSave = (item) => item?.applyMode === 'server';

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
        // A repeated parameter ("?id=4&id=16", as TYPO3 13 builds it after module navigation) means its LAST value, as in
        // PHP and so in TYPO3: the first one would name the previous page.
        const last = (key) => url.searchParams.getAll(key).pop() ?? null;
        const id = last('id') ?? '';
        if (/^[1-9][0-9]{0,9}$/.test(id)) {
            context.id = id;
        }
        for (const key of ['language', 'languageUid']) {
            const language = last(key) ?? '';
            if (/^(0|[1-9][0-9]{0,4})$/.test(language)) {
                context.language = language;
                break;
            }
        }
        if (context.language === undefined) {
            // TYPO3 14's Page module names its languages as `languages[]` (several in its comparison view): one
            // translation is that language, otherwise the default language.
            const listed = [...url.searchParams].filter(([key]) => /^languages\[[0-9]{0,3}\]$/.test(key)).map(([, value]) => value)
                .filter((value) => /^(0|[1-9][0-9]{0,4})$/.test(value));
            const translations = [...new Set(listed.filter((value) => value !== '0'))];
            if (listed.length > 0) {
                context.language = translations.length === 1 ? translations[0] : '0';
            } else if (/\/module\/web\/layout\/?$/.test(url.pathname)) {
                // Without a language in the URL (a click in the page tree) the Page module shows the language the editor
                // chose before, kept in TYPO3's module data: the server reads it from there (ToolbarAssistantContextResolver).
                context.module = 'web_layout';
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
 * The stored form of a plan answer (this tab's sessionStorage, the same scope as the other turns): what was shown, so a
 * reload can show it again as a record with "Refresh status". Never sent back as AI history (source "aqg").
 */
export function planTurn(data) {
    const repair = data?.repair && typeof data.repair === 'object' ? data.repair : {};
    return {
        status: 'answer',
        source: 'aqg',
        intent: 'repair',
        question: String(data?.question ?? ''),
        answer: String(data?.answer ?? ''),
        // The plan keeps its own signed list: a refresh resolves its numbers against the list it showed, never a newer one.
        repair: {...repair, items: (Array.isArray(repair.items) ? repair.items : []).slice(0, 50)},
    };
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
        // Where the assistant's one DOM root is shown: 'toolbar' (dropdown), 'dock' (right-hand column beside the TYPO3
        // workspace, non-blocking) or 'dialog' (fallback where the dock does not fit).
        this.presentation = 'toolbar';
        this.dock = new AskAqgDock({
            win,
            label: (key, fallback) => this.label(this.root, key, fallback),
            onClose: () => this.closeDock(),
            onMinimize: () => {},
            onNavigate: (href, withReturnUrl) => this.navigate?.(href, withReturnUrl),
            // The rail cannot open beside the editor (narrow window, page tree open): the same conversation moves into
            // the existing dialog instead of a panel that would squeeze the form.
            onNoRoom: () => {
                this.closeDock({restoreFocus: false});
                this.expand();
            },
        });
        // While docked, the assistant follows what is open in TYPO3: every module or record the content frame loads
        // (navigation, Save, Save & close) is resolved again on the server, and the dock shows that context's own
        // conversation. A plan of the previous page never stays on screen for the next one.
        const followContext = () => {
            if (this.presentation !== 'dock') {
                return;
            }
            this.win.clearTimeout(this.followTimer);
            this.followTimer = this.win.setTimeout(() => this.load({into: 'dock'}), 250);
        };
        const followFrame = (event) => {
            if (event.target instanceof this.win.Element && event.target.matches('#typo3-contentIframe, typo3-iframe-module iframe')) {
                followContext();
            }
        };
        this.win.document.addEventListener('typo3-module-loaded', followContext);
        this.win.document.addEventListener('load', followFrame, true);
        // AQA-07: AI Fixes tells the backend document when an item changed (approve, reject, reopen, regenerate, save,
        // verify). A live plan that shows that item asks the server again once (free, no AI); the event carries only
        // an item id, so what is shown still comes from the server's scoped answer.
        const onRemediationChanged = (event) => {
            const detail = event?.detail ?? event?.data ?? {};
            this.onRemediationChanged(Number(detail.itemUid ?? 0), Number(detail.findingUid ?? 0));
        };
        this.win.document.addEventListener('aqg:remediation-changed', onRemediationChanged);
        let channel = null;
        try {
            channel = typeof this.win.BroadcastChannel === 'function' ? new this.win.BroadcastChannel('aqg-remediation') : null;
            if (channel) {
                channel.onmessage = onRemediationChanged;
            }
        } catch {
            channel = null;
        }
        this.disposers = [
            () => this.win.document.removeEventListener('typo3-module-loaded', followContext),
            () => this.win.document.removeEventListener('load', followFrame, true),
            () => this.win.document.removeEventListener('aqg:remediation-changed', onRemediationChanged),
            () => channel?.close(),
        ];
        this.win.document.addEventListener('click', (event) => {
            const button = event.target instanceof this.win.Element ? event.target.closest('[data-aqt-content-scan-button]') : null;
            if (!button) {
                return;
            }
            event.preventDefault();
            this.contentScan(button);
        });
    }

    /** Removes the document listeners of this instance (the backend keeps one for its lifetime; tests create several). */
    dispose() {
        (this.disposers ?? []).forEach((dispose) => dispose());
        this.disposers = [];
        this.win.clearTimeout(this.followTimer);
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

    /**
     * Renders the assistant for what is open now. `into: 'dock'` renders it inside the docked column (after a TYPO3
     * navigation); the dropdown opening while the assistant is docked shows only a pointer to the dock.
     */
    async load({into = 'toolbar'} = {}) {
        const dropdown = this.slot();
        const url = this.ajaxUrls().a11y_toolbar_assistant;
        if (!url) {
            return;
        }
        if (this.dialog?.open) {
            // The expanded view holds the assistant (the backend is inert behind it): nothing to reload underneath it.
            return;
        }
        if (this.presentation === 'dock' && into !== 'dock') {
            this.showDockedNotice();
            return;
        }
        const slot = into === 'dock' && this.presentation === 'dock' ? this.dock.body : dropdown;
        if (!slot) {
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
        this.root = root;
        if (slot === this.dock.body && root) {
            // A re-render inside the dock (after a TYPO3 navigation) needs the same layout as openDock(): without
            // --expanded the conversation does not scroll and the composer is pushed out of the column.
            root.classList.add('aqt-assistant--expanded', 'aqt-assistant--docked');
        }
        this.setContentScanOffer(Number(root?.dataset.contentScanPage ?? 0), Number(root?.dataset.contentScanLanguage ?? 0));
        this.bind(root);
        if (hadFocus) {
            slot.querySelector('#aqt-assistant-title')?.setAttribute('tabindex', '-1');
            slot.querySelector('#aqt-assistant-title')?.focus({preventScroll: true});
        }
        this.syncExpandButtons(root);
    }

    bind(root) {
        if (!root) {
            return;
        }
        this.bindAsk(root);
        root.querySelectorAll('[data-aqt-expand]').forEach((button) => {
            button.setAttribute('aria-expanded', 'false');
            button.addEventListener('click', (event) => {
                event.preventDefault();
                if (this.dialog?.open) {
                    this.collapse();
                } else if (this.presentation === 'dock') {
                    this.closeDock();
                } else {
                    this.expand(button);
                }
            });
        });
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

    /** "Reason given by the AI: …" — model text, rendered as text only. */
    aiReasonLine(root, reason) {
        return el('p', {class: 'aqt-repair__why', 'data-aqt-ai-reason': 'true'},
            el('strong', {text: `${this.label(root, 'aiReason', 'Reason given by the AI')}: `}), reason);
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
        let item = null;
        if (entry.outcome === 'queued') {
            const current = await postRemediation(urls.a11y_remediation_item, {item: entry.itemUid, detailed: false});
            if (current.ok && current.data.item?.status === 'preparing') {
                const generated = await postRemediation(urls.a11y_remediation_generate, {item: entry.itemUid, rowVersion: current.data.item.rowVersion, detailed: false});
                if (!generated.ok && generated.code !== 'in_progress') {
                    // A refused or failed request is not "queued": say what happened (the item stays in AI fixes).
                    this.failed(root, finding, generated.code);
                    this.removePrepare(finding);
                    return;
                }
                item = generated.ok ? (generated.data.item ?? null) : null;
                status = generated.ok && !generated.data.retryAfter ? String(item?.status ?? 'preparing') : 'preparing';
            } else if (current.ok) {
                item = current.data.item ?? null;
                status = String(item?.status ?? 'preparing');
            }
        }
        const queueUrl = root.querySelector('a[data-aqt-queue]')?.getAttribute('href') ?? '';
        const link = queueUrl !== '' ? el('a', {class: 'aqt-btn aqt-btn--link', href: queueUrl, 'data-aqt-navigate': 'true', text: this.label(root, 'review')}) : null;
        if (status === 'pending_review') {
            this.say(root, finding, this.label(root, 'prepared'), 'ok', ...(link ? [link] : []));
        } else if (status === 'preparing' || status === 'queued') {
            this.say(root, finding, this.label(root, 'queued'), 'info', ...(link ? [link] : []));
        } else if (status === 'no_suggestion') {
            // The AI answered without a usable value: its own reason (if it gave one) says what the editor must decide.
            const why = aiDeclineReason(item);
            this.say(root, finding, this.label(root, 'prepareNone'), 'warning',
                ...(why !== '' ? [this.aiReasonLine(root, why)] : []), ...(link ? [link] : []));
            const live = root.querySelector('[data-aqt-live]');
            if (live && why !== '') {
                // Screen readers hear the reason too, not only the generic line.
                live.textContent = `${this.label(root, 'prepareNone')} ${this.label(root, 'aiReason', 'Reason given by the AI')}: ${why}`;
            }
        } else {
            // Failed, not applicable (the content changed) …: the item's own status and reason, not "no suggestion".
            const text = [item?.statusLabel, item?.statusReasonLabel].filter((part) => String(part ?? '') !== '').join(': ');
            this.say(root, finding, text !== '' ? text : this.label(root, 'prepareNone'), status === 'failed' ? 'error' : 'warning', ...(link ? [link] : []));
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
     *
     * One conversation, one place: the log of the current scope is a single DOM node. When the dropdown opens again for
     * the same context, that node (with live repair plans, their pending requests and results) is moved into the newly
     * rendered view instead of being rebuilt; "Expand" moves the whole assistant into a dialog and back. So the toolbar
     * and the expanded view never hold two copies that could disagree or send a request twice.
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
        const view = {context: {...(this.context ?? {})}, scope: this.conversationScope};
        this.askViews.set(form, view);
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            this.ask(root, form, {});
        });
        section.querySelectorAll('[data-aqt-ask-intent]').forEach((button) => {
            button.addEventListener('click', () => this.ask(root, form, {intent: button.dataset.aqtAskIntent, question: (button.dataset.question ?? button.textContent).trim(), button}));
        });
        form.querySelector('[data-aqt-ask-input]')?.addEventListener('keydown', (event) => {
            // Enter sends, Shift+Enter starts a new line (the usual chat convention); IME composition is left alone.
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && !event.altKey && !event.ctrlKey && !event.metaKey) {
                event.preventDefault();
                this.ask(root, form, {});
            }
        });
        form.querySelector('[data-aqt-ask-clear]')?.addEventListener('click', (event) => this.newConversation(root, section, form, event.currentTarget));

        const log = section.querySelector('[data-aqt-ask-log]');
        const live = this.liveLog;
        if (log && live && live.scope !== '' && live.scope === this.conversationScope && live.log !== log) {
            // The same context as before: keep the very same conversation (live plans included).
            log.replaceWith(live.log);
            this.liveLog = {scope: this.conversationScope, log: live.log};
            if (live.log.childElementCount > 0) {
                live.log.hidden = false;
                form.querySelector('[data-aqt-ask-clear]')?.removeAttribute('hidden');
            }
            this.syncAskEmpty(section);
            return;
        }
        this.liveLog = {scope: this.conversationScope, log};
        // A conversation of this context from earlier in this browser tab (sessionStorage only).
        const stored = this.storedConversation();
        if (stored.turns.length > 0 && log) {
            stored.turns.forEach((turn) => log.append(turn?.intent === 'repair' && turn?.repair ? this.renderPlan(turn, view, {stored: true}) : this.turn(root, turn)));
            log.hidden = false;
            form.querySelector('[data-aqt-ask-clear]')?.removeAttribute('hidden');
        }
        this.syncAskEmpty(section);
    }

    /** The quick questions are the empty state; once a conversation exists they stay available but step back. */
    syncAskEmpty(section) {
        const log = section?.querySelector('[data-aqt-ask-log]');
        section?.classList.toggle('aqt-ask--active', Boolean(log && log.childElementCount > 0));
    }

    /**
     * "New conversation": this context's stored turns, numbered list and conversation id are dropped, and an answer
     * still on its way for the old conversation is discarded when it arrives (askEpoch), so it can neither reappear
     * nor be stored into the new one.
     */
    newConversation(root, section, form, button) {
        this.askEpoch = (this.askEpoch ?? 0) + 1;
        this.storeConversation({conversation: '', turns: [], selection: ''});
        const log = section.querySelector('[data-aqt-ask-log]');
        log?.replaceChildren();
        if (log) {
            log.hidden = true;
        }
        button.hidden = true;
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
        this.syncAskEmpty(section);
        (form.querySelector('[data-aqt-ask-input]') ?? section.querySelector('[data-aqt-ask-intent]'))?.focus({preventScroll: true});
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
            // Another user or login: the live conversation of the previous one is never moved into this view.
            this.liveLog = null;
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
        const empty = {conversation: '', turns: [], selection: ''};
        const key = this.storageKey(scope);
        if (key === '') {
            return empty;
        }
        const limit = this.askLimits?.maxHistoryTurns ?? DEFAULT_LIMITS.maxHistoryTurns;
        const valid = (data) => data && typeof data === 'object' && Array.isArray(data.turns)
            ? {
                conversation: typeof data.conversation === 'string' ? data.conversation : '',
                turns: data.turns.slice(-limit),
                // The signed numbered list of the latest plan ("Fix problem 2" refers to it); opaque to the browser.
                selection: typeof data.selection === 'string' && /^s1\.[a-z]+\.[0-9]+\.[0-9-]*\.[a-f0-9]+$/.test(data.selection) ? data.selection : '',
            }
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

    /** The Ask AQG form of the view on screen now (toolbar or expanded), or null. */
    currentAsk() {
        const root = this.root?.isConnected ? this.root : this.slot()?.querySelector('[data-aqt-assistant-root]');
        const form = root?.querySelector('[data-aqt-ask-form]') ?? null;

        return root && form ? {root, form} : null;
    }

    /**
     * @param {{intent?: string, question?: string, button?: HTMLElement|null, repairTarget?: string, pick?: number[], plan?: HTMLElement|null}} options
     *   `pick`: numbers of the conversation's signed list (a card's "Prepare fix"); `plan`: the plan article a refresh replaces
     */
    async ask(root, form, {intent = '', question: given = '', button = null, repairTarget = '', pick = [], plan = null, selection = null}) {
        const input = form.querySelector('[data-aqt-ask-input]');
        const submit = form.querySelector('[data-aqt-ask-submit]');
        const status = form.querySelector('[data-aqt-ask-status]');
        const trigger = button ?? submit;
        if (this.askPending) {
            return null;
        }
        const question = (intent !== '' ? given : String(input?.value ?? '')).replace(/\s+/g, ' ').trim();
        const limits = this.askLimits ?? {...DEFAULT_LIMITS};
        if ((intent === '' || intent === 'repair') && (question.length < limits.minQuestionChars || question.length > limits.maxQuestionChars)) {
            if (status) {
                status.textContent = this.label(root, 'errorQuestion');
                status.dataset.tone = 'error';
            }
            input?.setAttribute('aria-invalid', 'true');
            input?.focus({preventScroll: true});
            return null;
        }
        input?.removeAttribute('aria-invalid');
        const scope = form.querySelector('input[name="aqt-ask-scope"]:checked, input[type="hidden"][name="aqt-ask-scope"]')?.value === 'site' ? 'site' : 'page';
        // The conversation and context of the view this form belongs to; its answer is stored there even if the user moves on.
        const view = this.askViews?.get(form) ?? {context: this.context ?? {}, scope: this.conversationScope};
        const conversationScope = view.scope;
        const stored = this.storedConversation(conversationScope);
        const epoch = this.askEpoch ?? 0;
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
            // A plan's own refresh names its list; otherwise the conversation's newest list.
            ...((selection ?? stored.selection) !== '' ? {selection: selection ?? stored.selection} : {}),
            ...(Array.isArray(pick) && pick.length > 0 ? {pick: pick.map(Number).filter((number) => Number.isInteger(number) && number > 0)} : {}),
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
        if ((this.askEpoch ?? 0) !== epoch) {
            // "New conversation" was chosen meanwhile: this answer belongs to the conversation that was cleared.
            if (status) {
                status.textContent = '';
            }
            return null;
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
            return null;
        }
        if (answer.data.status === 'invalid_response') {
            if (status) {
                status.textContent = this.label(root, 'invalid');
                status.dataset.tone = 'error';
            }
            this.showUsage(root, form, answer.data.conversation, stored);
            return null;
        }
        // The log announces the new turn; the status is emptied so the answer is not read twice.
        if (status) {
            status.textContent = '';
            delete status.dataset.tone;
        }
        // The live log of this conversation, wherever it is now: the dropdown may have re-rendered (and moved the log into
        // its new view) while the answer was on its way.
        const live = this.liveLog?.scope === conversationScope && this.liveLog.log ? this.liveLog.log : null;
        const log = live ?? root.querySelector('[data-aqt-ask-log]');
        const conversationId = typeof answer.data.conversation?.id === 'string' ? answer.data.conversation.id : stored.conversation;
        let node = null;
        if (answer.data.intent === 'repair' && answer.data.repair && typeof answer.data.repair === 'object') {
            node = this.renderPlan(answer.data, view);
            const turn = planTurn(answer.data);
            this.storeConversation({
                conversation: conversationId,
                turns: [...stored.turns, turn].slice(-limits.maxHistoryTurns),
                selection: typeof answer.data.repair.selection === 'string' ? answer.data.repair.selection : stored.selection,
            }, conversationScope);
        } else {
            const turn = {...answer.data};
            node = this.turn(root, turn);
            this.storeConversation({
                conversation: conversationId,
                turns: [...stored.turns, turn].slice(-limits.maxHistoryTurns),
                selection: stored.selection,
            }, conversationScope);
            this.showUsage(root, form, answer.data.conversation, stored);
        }
        if (log) {
            log.hidden = false;
            if (plan && plan.parentElement === log) {
                plan.replaceWith(node);
            } else {
                log.append(node);
            }
            // The start of the new answer, not its end: a long plan is read from its headline down.
            this.revealInLog(log, node, this.dialog?.open || this.presentation === 'dock' ? 'start' : 'nearest');
        }
        if (input && intent === '') {
            input.value = '';
        }
        form.querySelector('[data-aqt-ask-clear]')?.removeAttribute('hidden');
        this.syncAskEmpty(log?.closest('[data-aqt-ask]') ?? root.querySelector('[data-aqt-ask]'));

        return node;
    }

    /**
     * A plan answer — the numbered problems of a page, element or file ("What problems does this page have?"), the
     * selected problem ("Fix problem 1") or every problem ("Fix all problems on this page") — as finding cards with
     * their real state in AI Fixes, the concrete next step and the actions that exist for each one. Built as text nodes
     * only; every link is a server-built backend URL (checked again by safeBackendHref()).
     *
     * A plan restored from this tab's storage after a reload is a record of what was shown: its actions are replaced by
     * "Refresh status", which asks the server again (free, no AI) before anything can be prepared.
     */
    renderPlan(data, view, {stored = false} = {}) {
        const root = this.root ?? this.slot()?.querySelector('[data-aqt-assistant-root]');
        const repair = data.repair;
        const items = Array.isArray(repair.items) ? repair.items : [];
        const ai = repair.ai && typeof repair.ai === 'object' ? repair.ai : {};
        const counts = repair.counts && typeof repair.counts === 'object' ? repair.counts : {};
        this.repairSequence = (this.repairSequence ?? 0) + 1;
        const id = `aqt-repair-${this.repairSequence}`;
        const article = el('article', {
            class: `aqt-turn aqt-turn--repair aqt-plan${stored ? ' aqt-plan--stored' : ''}`,
            'aria-labelledby': `${id}-head`,
            'data-aqt-repair': 'true',
            'data-aqt-repair-target': String(repair.target ?? ''),
            'data-aqt-plan-mode': repair.mode === 'list' ? 'list' : 'repair',
        });
        const status = el('p', {class: 'aqt-repair__status', role: 'status', 'aria-live': 'polite', 'data-aqt-repair-status': 'true'});
        const plan = {data, view, status, id};
        this.plans ??= new WeakMap();
        this.plans.set(article, plan);

        article.append(this.questionBubble(root, String(data.question ?? '')));
        const answer = el('div', {class: 'aqt-answer'});
        article.append(answer);
        answer.append(el('p', {class: 'aqt-answer__who'},
            el('span', {class: 'aqt-answer__badge', 'aria-hidden': 'true', text: 'AQG'}),
            el('span', {text: [this.label(root, 'answerAqg'), this.label(root, repair.mode === 'list' ? 'planListTitle' : 'repairTitle')].filter(Boolean).join(' · ')}),
        ));
        answer.append(el('h3', {class: 'aqt-plan__head', id: `${id}-head`, tabindex: '-1', text: String(repair.headline ?? '')}));
        const targetLine = el('p', {class: 'aqt-plan__target'}, el('span', {class: 'aqt-chip', text: String(repair.targetLabel ?? '')}));
        const alternatives = Array.isArray(repair.alternatives) ? repair.alternatives : [];
        if (!stored && alternatives.length > 0) {
            targetLine.append(...alternatives.map((alternative) => el('button', {
                type: 'button',
                class: 'aqt-btn aqt-btn--quiet',
                'data-aqt-repair-switch': String(alternative.target ?? ''),
                text: String(alternative.label ?? ''),
                onclick: (event) => this.planAsk(article, {intent: repair.mode === 'list' ? 'list' : 'repair', repairTarget: String(alternative.target ?? ''), button: event.currentTarget}),
            })));
        }
        answer.append(targetLine);
        const notices = Array.isArray(repair.notices) ? repair.notices.map(String).filter(Boolean) : [];
        if (notices.length > 0) {
            answer.append(el('div', {class: `aqt-callout${repair.clarify ? ' aqt-callout--ask' : ''}`, 'data-aqt-repair-notices': 'true'}, notices.map((notice) => el('p', {text: notice}))));
        }
        if (stored) {
            answer.append(el('p', {class: 'aqt-callout aqt-callout--muted'},
                el('span', {text: this.label(root, 'planStored', 'This is the status from earlier. Refresh it before you act on it.') + ' '}),
                el('button', {type: 'button', class: 'aqt-btn', 'data-aqt-repair-refresh': 'true', text: this.label(root, 'planRefresh', 'Refresh status'), onclick: (event) => this.planRefresh(article, event.currentTarget)}),
            ));
        }
        const chips = this.stateChips(root, counts);
        if (chips) {
            answer.append(chips);
        }
        if (!ai.ready && ai.reason && items.some((item) => item.state === 'ready' || item.state === 'blocked')) {
            answer.append(el('p', {class: 'aqt-callout aqt-callout--muted aqt-repair__ai', text: String(ai.reason)}));
        }

        const list = el('ol', {class: 'aqt-cards aqt-repair__items', 'aria-label': this.label(root, 'planCards', 'Problems')});
        const openGuidance = items.length <= 2 || Boolean(repair.single);
        items.forEach((item) => list.append(this.planCard(root, item, article, plan, {stored, openGuidance})));
        if (items.length > 0) {
            answer.append(list);
        }

        const ready = Array.isArray(repair.prepareIds) ? repair.prepareIds.map(Number).filter((value) => value > 0) : [];
        if (!stored && ready.length > 0 && ai.ready) {
            const cost = repair.cost && typeof repair.cost === 'object' ? repair.cost : {};
            const costText = cost.total
                ? format(this.label(root, ready.length === 1 ? 'repairCostOne' : 'repairCost'), String(cost.total), ready.length)
                : (cost.unknown ? this.label(root, 'repairCostUnknown') : '');
            const costId = `${id}-cost`;
            if (ready.length > 1 && repair.bulk) {
                // One step for every ready finding of this target: only with the AI queue permission, as in Page Detail.
                const bar = el('div', {class: 'aqt-plan__bulk'});
                bar.append(el('button', {
                    type: 'button',
                    class: 'aqt-btn aqt-btn--primary',
                    'data-aqt-repair-prepare': 'all',
                    'aria-describedby': costText !== '' ? costId : null,
                    text: format(this.label(root, 'repairPrepare'), ready.length),
                    onclick: (event) => this.repairPrepare(root, article, repair, view, ready, event.currentTarget, status),
                }));
                if (costText !== '') {
                    bar.append(el('p', {class: 'aqt-repair__cost aqt-assistant__muted', id: costId, text: costText}));
                }
                answer.append(bar);
            } else if (costText !== '') {
                answer.append(el('p', {class: 'aqt-repair__cost aqt-assistant__muted', id: costId, text: costText}));
            }
        }

        const freshness = repair.freshness && typeof repair.freshness === 'object' ? repair.freshness : {};
        const foot = el('div', {class: 'aqt-plan__foot'});
        if (freshness.text) {
            const warn = Boolean(freshness.stale || freshness.never);
            foot.append(el('p', {class: `aqt-repair__fresh${warn ? ' aqt-repair__fresh--warning' : ''}`, text: String(freshness.text)}));
        }
        const footActions = el('p', {class: 'aqt-repair__actions'});
        // Offered whenever the user may check this page (the server sends pageUid only then): after a save it is the step
        // that turns "saved" into verified or still open. Emphasised when the stored check is stale.
        if (!stored && Number(freshness.pageUid) > 0) {
            footActions.append(el('button', {
                type: 'button',
                class: 'aqt-btn',
                'data-aqt-repair-check': 'true',
                text: this.label(root, 'repairCheck'),
                onclick: (event) => this.planCheck(article, event.currentTarget),
            }));
        }
        if (repair.queueUrl && safeBackendHref(repair.queueUrl, this.win) && ['queued', 'review', 'apply', 'nosuggestion', 'saved', 'ready'].some((key) => Number(counts[key] ?? 0) > 0)) {
            footActions.append(el('a', {href: repair.queueUrl, class: 'aqt-turn__link', 'data-aqt-navigate': 'true', text: this.label(root, 'repairOpenQueue')}));
        }
        if (repair.editUrl && safeBackendHref(repair.editUrl, this.win)) {
            footActions.append(el('a', {href: repair.editUrl, class: 'aqt-turn__link', 'data-aqt-navigate': 'true', 'data-aqt-return': 'true', text: this.label(root, 'repairEditElement')}));
        }
        if (footActions.childNodes.length > 0) {
            foot.append(footActions);
        }
        foot.append(el('p', {class: 'aqt-repair__safety', text: this.label(root, 'repairSafety')}), status);
        foot.append(el('p', {class: 'aqt-turn__basis', text: this.label(root, 'repairBasis')}));
        answer.append(foot);

        return article;
    }

    /** "You asked …" as its own bubble, so the question and AQG's answer read as two turns. */
    questionBubble(root, question) {
        return el('p', {class: 'aqt-turn__q aqt-bubble'}, el('span', {class: 'visually-hidden', text: `${this.label(root, 'youAsked')}: `}), question);
    }

    /** Compact counts per state ("2 ready to prepare · 1 needs manual work"), the plan's overview line. */
    stateChips(root, counts) {
        const order = [
            ['ready', 'planCountReady', 'Ready to prepare: %d'],
            // AQA-05: what an AI Fixes item needs, not only that one exists.
            ['review', 'planCountReview', 'Needs review: %d'],
            ['apply', 'planCountApply', 'Approved, apply in editor: %d'],
            ['nosuggestion', 'planCountNosuggestion', 'No usable suggestion: %d'],
            ['queued', 'planCountQueued', 'In AI Fixes: %d'],
            ['saved', 'planCountSaved', 'Saved, not verified: %d'],
            ['manual', 'planCountManual', 'Manual work: %d'],
            ['blocked', 'planCountBlocked', 'Not possible now: %d'],
            ['stale', 'planCountStale', 'May be fixed already: %d'],
        ];
        const chips = order.filter(([key]) => Number(counts?.[key] ?? 0) > 0)
            .map(([key, label, fallback]) => el('li', {class: `aqt-state aqt-state--${key}`, text: format(this.label(root, label, fallback), Number(counts[key]))}));

        return chips.length > 0 ? el('ul', {class: 'aqt-plan__states', 'aria-label': this.label(root, 'planStates', 'Overview')}, chips) : null;
    }

    /**
     * One finding card: its number in this conversation, title, severity, where it is, its state and reason, the
     * guidance for manual work, and the actions that exist for it (Prepare fix, Review, Show in form / Open editor,
     * Details). The data-aqt-repair-* hooks are the contract with the plan's own updates.
     */
    planCard(root, item, article, plan, {stored = false, openGuidance = false} = {}) {
        const state = ['ready', 'queued', 'saved', 'manual', 'blocked', 'stale'].includes(item.state) ? item.state : 'manual';
        const number = Number(item.number) > 0 ? Number(item.number) : 0;
        const card = el('li', {
            class: `aqt-card aqt-repair__item aqt-repair__item--${state}`,
            'data-aqt-repair-item': String(Number(item.id) || 0),
            'data-aqt-number': String(number),
            // AQA-07: the AI Fixes item of this card; a change of that item in AI Fixes refreshes the plan once.
            'data-aqt-item-uid': String(Number(item.itemUid) || 0),
        });
        const tone = ['critical', 'warning', 'info'].includes(item.tone) ? item.tone : 'info';
        const titleId = `${plan.id}-item-${Number(item.id) || 0}`;
        card.setAttribute('aria-labelledby', titleId);
        card.append(el('div', {class: 'aqt-card__head'},
            number > 0 ? el('span', {class: 'aqt-card__num', 'aria-hidden': 'true', text: String(number)}) : null,
            el('div', {class: 'aqt-card__titles'},
                el('h4', {class: 'aqt-card__title aqt-repair__item-title', id: titleId},
                    number > 0 ? el('span', {class: 'visually-hidden', text: format(this.label(root, 'planNumber', 'Problem %d: '), number)}) : null,
                    String(item.title ?? '')),
                el('p', {class: 'aqt-card__meta'},
                    el('span', {class: `aqt-sev aqt-sev--${tone}`}, el('span', {class: 'aqt-sev__dot', 'aria-hidden': 'true'}), String(item.severityLabel ?? '')),
                    item.location ? el('span', {class: 'aqt-card__where', text: String(item.location)}) : null,
                    item.source === 'rendered' ? el('span', {class: 'aqt-tag', text: this.label(root, 'planSourceRendered', 'Page output')}) : null,
                ),
            ),
        ));
        card.append(el('p', {class: 'aqt-card__status aqt-repair__state-line'},
            el('span', {class: `aqt-state aqt-state--${state} aqt-repair__state aqt-repair__state--${state}`, 'data-aqt-repair-state': 'true', text: String(item.stateLabel ?? '')}),
        ));
        card.append(el('p', {class: 'aqt-repair__reason', 'data-aqt-repair-result': 'true', text: String(item.reason ?? '')}));

        const guidance = item.guidance && typeof item.guidance === 'object' ? item.guidance : null;
        if (guidance) {
            card.append(this.guidancePanel(root, guidance, openGuidance, String(item.location ?? '')));
        } else if (['manual', 'blocked', 'stale'].includes(state) && item.howToFix) {
            card.append(el('p', {class: 'aqt-assistant__muted', text: String(item.howToFix)}));
        }
        if (item.why) {
            card.append(el('details', {class: 'aqt-card__why'}, el('summary', {text: this.label(root, 'planWhy', 'Why it matters')}), el('p', {text: String(item.why)})));
        }
        // The outcome of Prepare / Approve for this card (Before/After, the next step); filled in by the plan's actions.
        card.append(el('div', {class: 'aqt-card__outcome', 'data-aqt-card-outcome': 'true', hidden: true}));

        const links = el('p', {class: 'aqt-card__actions aqt-repair__links', 'data-aqt-repair-links': 'true'});
        const repair = plan.data.repair;
        let costId = null;
        if (!stored && state === 'ready' && repair.ai?.ready) {
            // The estimate stays with the step that spends it, quietly: the prepare button describes itself with it.
            costId = item.cost ? `${titleId}-cost` : null;
            links.append(el('button', {
                type: 'button',
                class: 'aqt-btn aqt-btn--primary aqt-btn--ai',
                'data-aqt-repair-prepare': String(Number(item.id)),
                'aria-describedby': costId,
                text: this.label(root, 'planPrepareFix', 'Prepare fix'),
                onclick: (event) => this.repairPrepare(root, article, repair, plan.view, [Number(item.id)], event.currentTarget, plan.status),
            }));
        }
        if (!stored && item.applyUrl && safeBackendHref(item.applyUrl, this.win)) {
            // Approved for the editor, not applied yet: back to the editor with the staged change (then TYPO3 Save).
            links.append(el('a', {href: item.applyUrl, class: 'aqt-btn aqt-btn--primary', 'data-aqt-navigate': 'true', 'data-aqt-return': 'true', 'data-aqt-apply': 'true', text: this.label(root, 'planOpenToApply', 'Open in editor to apply')}));
        }
        // AQA-06: without a usable suggestion the record is the place to fix it; AI Fixes is only a secondary link.
        const noSuggestion = item.queueKind === 'nosuggestion';
        if (item.reviewUrl && safeBackendHref(item.reviewUrl, this.win) && noSuggestion) {
            if (!item.formField && item.editUrl && safeBackendHref(item.editUrl, this.win)) {
                links.append(el('a', {href: item.editUrl, class: 'aqt-btn aqt-btn--primary', 'data-aqt-navigate': 'true', 'data-aqt-return': 'true', text: this.label(root, 'planOpenEditor', this.label(root, 'edit'))}));
            }
            links.append(el('a', {href: item.reviewUrl, class: 'aqt-turn__link aqt-card__more', 'data-aqt-navigate': 'true', 'data-aqt-repair-review': 'true', text: this.label(root, 'repairOpenItem')}));
        } else if (item.reviewUrl && safeBackendHref(item.reviewUrl, this.win)) {
            // "Review suggestion" only for a proposal waiting for review; any other item is opened as it is.
            links.append(el('a', {href: item.reviewUrl, class: `aqt-btn ${item.itemStatus === 'pending_review' ? 'aqt-btn--primary' : ''}`, 'data-aqt-navigate': 'true', 'data-aqt-repair-review': 'true', text: this.label(root, item.itemStatus === 'pending_review' ? 'repairReview' : 'repairOpenItem')}));
        }
        if (item.formField) {
            links.append(el('button', {type: 'button', class: 'aqt-btn', 'data-aqt-show-field': String(item.formField), text: this.label(root, 'planShowField', 'Show in form'), onclick: () => this.showFieldFor(card, String(item.formField))}));
        } else if (state !== 'queued' && item.editUrl && safeBackendHref(item.editUrl, this.win)) {
            links.append(el('a', {href: item.editUrl, class: 'aqt-btn', 'data-aqt-navigate': 'true', 'data-aqt-return': 'true', text: this.label(root, 'planOpenEditor', this.label(root, 'edit'))}));
        }
        if (item.detailUrl && safeBackendHref(item.detailUrl, this.win)) {
            links.append(el('a', {href: item.detailUrl, class: 'aqt-turn__link aqt-card__more', 'data-aqt-navigate': 'true', text: this.label(root, 'details')}));
        }
        if (costId) {
            links.append(el('span', {class: 'aqt-card__cost', id: costId, text: format(this.label(root, 'repairItemCost', 'max. %s'), String(item.cost))}));
        }
        if (links.childNodes.length > 0) {
            card.append(links);
        }
        this.oneMainAction(card);

        return card;
    }

    /** Manual work, spelled out: what is wrong, where, the change, the editor step, what AQG cannot know, how it checks. */
    guidancePanel(root, guidance, open, location = '') {
        const rows = [
            ['problem', 'guideProblem', 'What is wrong'],
            ['where', 'guideWhere', 'Where'],
            ['recommendation', 'guideChange', 'Recommended change'],
            ['action', 'guideAction', 'How to do it'],
            ['missing', 'guideMissing', 'What AQG cannot tell'],
            ['verify', 'guideVerify', 'How AQG confirms the fix'],
        ];
        const list = el('dl', {class: 'aqt-guide__list'});
        rows.forEach(([key, label, fallback]) => {
            const value = String(guidance[key] ?? '').trim();
            // The card already names the place; the row repeats it only when the guidance knows more.
            if (value !== '' && !(key === 'where' && value === location)) {
                list.append(el('div', {class: `aqt-guide__row aqt-guide__row--${key}`}, el('dt', {text: this.label(root, label, fallback)}), el('dd', {text: value})));
            }
        });
        const outline = Array.isArray(guidance.outline) ? guidance.outline : [];
        const body = [list];
        if (outline.length > 0) {
            body.push(el('p', {class: 'aqt-guide__outline-title', text: this.label(root, 'guideOutline', 'Headings in this text, as saved')}));
            body.push(el('ol', {class: 'aqt-guide__outline'}, outline.map((heading) => {
                const level = Math.min(6, Math.max(1, Number(heading.level) || 1));
                return el('li', {class: `aqt-guide__h aqt-guide__h--${level}${heading.flagged ? ' aqt-guide__h--flagged' : ''}`},
                    el('span', {class: 'aqt-guide__level', text: `H${level}`}),
                    el('span', {class: 'aqt-guide__text', text: String(heading.text ?? '')}),
                    heading.flagged ? el('span', {class: 'aqt-guide__flag', text: this.label(root, 'guideFlagged', 'skips a level')}) : null,
                );
            })));
        }

        return el('details', {class: 'aqt-guide', open: open ? true : null, 'data-aqt-guidance': 'true'},
            el('summary', {text: this.label(root, 'guideTitle', 'How to fix it')}),
            ...body,
        );
    }

    /**
     * Scrolls the conversation log only. Element.scrollIntoView() also scrolls every scrollable ancestor — in the
     * backend that includes TYPO3's own scaffold, which then hides the module's docheader (Close / Save) and the dock's
     * controls (seen with the docked panel).
     */
    revealInLog(log, node, block = 'nearest') {
        if (!log || !node || !log.contains(node)) {
            return;
        }
        const logBox = log.getBoundingClientRect();
        const box = node.getBoundingClientRect();
        if (block === 'start' || box.height > logBox.height) {
            log.scrollTop += box.top - logBox.top;
        } else if (box.bottom > logBox.bottom) {
            log.scrollTop += box.bottom - logBox.bottom;
        } else if (box.top < logBox.top) {
            log.scrollTop += box.top - logBox.top;
        }
        this.keepShellInPlace();
    }

    /** TYPO3's scaffold never scrolls: a scroll that reached it (from inside the module frame) is undone. */
    keepShellInPlace() {
        this.win.document.querySelectorAll('.scaffold, .scaffold-content').forEach((element) => {
            if (element.scrollTop !== 0 || element.scrollLeft !== 0) {
                element.scrollTop = 0;
                element.scrollLeft = 0;
            }
        });
    }

    /** "Show in form": the finding's field in the editing form that is open (the toolbar or dialog closes first). */
    showFieldFor(card, fieldName) {
        const frameWindow = contentWindowOf(this.win);
        const field = frameWindow ? findFormField(frameWindow.document, fieldName) : null;
        const root = this.root;
        if (!field) {
            const result = card.querySelector('[data-aqt-repair-result]');
            if (result) {
                result.textContent = this.label(root, 'fieldMissing');
            }
            return;
        }
        // The dock stays open next to the form; only the blocking fallback dialog closes first.
        if (this.dialog?.open) {
            this.collapse({restoreFocus: false});
        }
        field.item.scrollIntoView({block: 'center'});
        this.keepShellInPlace();
        this.navigate?.(null);
        field.focusTarget?.focus?.({preventScroll: true});
    }

    /** Asks again from a plan (switch target, refresh) with the conversation's own list; the old plan is replaced. */
    async planAsk(article, {intent, repairTarget = '', pick = [], button = null, replace = repairTarget === '', ownList = false}) {
        const current = this.currentAsk();
        const plan = this.plans?.get(article);
        if (!current || !plan) {
            return null;
        }
        // A plan of another context is never re-asked here: its view no longer matches the open form.
        const view = this.askViews?.get(current.form);
        if (!view || view.scope !== plan.view.scope) {
            plan.status.textContent = this.label(current.root, 'planContextChanged', 'You moved to another page or record. Ask again there.');
            return null;
        }
        const question = String(plan.data.question ?? '');
        return this.ask(current.root, current.form, {intent, question: question.length >= 3 ? question : this.label(current.root, 'planRefresh', 'Refresh status'), repairTarget, pick, button, plan: replace ? article : null,
            selection: ownList && typeof plan.data.repair?.selection === 'string' ? plan.data.repair.selection : null});
    }

    /**
     * AQA-07: an AI Fixes item changed elsewhere (usually after "Review suggestion" led there, so the plan in the dock is
     * the restored record of the conversation). The newest plan showing a card for it is asked again once — the same
     * free "Refresh status" request, no AI — debounced, so several events of one action cause one request.
     */
    onRemediationChanged(itemUid, findingUid = 0) {
        if (!(itemUid > 0)) {
            return;
        }
        this.pendingRemediationItems ??= new Map();
        this.pendingRemediationItems.set(itemUid, findingUid > 0 ? Math.trunc(findingUid) : 0);
        this.win.clearTimeout(this.remediationTimer);
        this.remediationTimer = this.win.setTimeout(() => {
            if (this.askPending) {
                // A question is being answered right now: try again shortly instead of dropping the refresh.
                this.onRemediationChanged(itemUid, findingUid);
                return;
            }
            const changes = [...this.pendingRemediationItems];
            this.pendingRemediationItems.clear();
            const articles = new Set();
            for (const [uid, finding] of changes) {
                // A card names the item it shows, or (prepared again, rejected) only its finding.
                const selector = `.aqt-plan [data-aqt-item-uid="${Math.trunc(uid)}"]` + (finding > 0 ? `, .aqt-plan [data-aqt-repair-item="${finding}"]` : '');
                const cards = [...this.win.document.querySelectorAll(selector)];
                const article = cards.map((card) => card.closest('[data-aqt-repair]')).filter((candidate) => candidate && this.plans?.has(candidate)).pop();
                if (article) {
                    articles.add(article);
                }
            }
            articles.forEach((article) => this.planRefresh(article, null));
        }, 400);
    }

    /** "Refresh status" of a restored plan: the same request again (no AI), replacing the stale record. */
    planRefresh(article, button) {
        const plan = this.plans?.get(article);
        if (!plan) {
            return null;
        }
        const repair = plan.data.repair ?? {};
        const numbers = (repair.items ?? []).map((item) => Number(item.number)).filter((number) => number > 0);

        return this.planAsk(article, {intent: repair.mode === 'list' ? 'list' : 'repair', pick: repair.single ? numbers : [], button, ownList: true});
    }

    /**
     * "Check saved page": the existing content check of the plan's page (Scan now permission and edit access checked
     * again; one content scan at a time, shared with the toolbar's own "Check saved page now"), then the plan is asked
     * again so each problem reads Resolved or Still open from the new check — never from a staged or generated change.
     */
    async planCheck(article, button) {
        const plan = this.plans?.get(article);
        if (!plan || button.getAttribute('aria-busy') === 'true') {
            return;
        }
        const root = this.root;
        const freshness = plan.data.repair?.freshness ?? {};
        const target = {pageUid: Number(freshness.pageUid) || 0, languageUid: Number(freshness.languageUid) || 0};
        const state = this.contentScanState;
        if (target.pageUid <= 0) {
            plan.status.textContent = this.label(root, 'repairCheckUnavailable');
            return;
        }
        if (state.busy) {
            plan.status.textContent = this.label(root, 'waitRunning', this.label(root, 'alreadyRunning'));
            return;
        }
        // A check of changed content stores a problem again under a new id: problems are compared by rule + record +
        // field (`key`), counted, so one of two identical problems fixed is never reported as both fixed.
        const keyOf = (item) => String(item.key ?? item.id);
        const before = (plan.data.repair?.items ?? []).map((item) => ({key: keyOf(item), title: String(item.title ?? '')}));
        button.setAttribute('aria-busy', 'true');
        state.busy = true;
        state.active = target;
        plan.status.textContent = this.label(root, 'scanning');
        this.syncContentScan();
        const answer = await postForm(this.ajaxUrls().a11y_scan_page, {pageUid: String(target.pageUid), languageUid: String(target.languageUid)});
        state.busy = false;
        state.active = null;
        button.removeAttribute('aria-busy');
        this.syncContentScan();
        this.onScanFinished({contentScan: true, refocus: false});
        if (!answer.ok) {
            const key = errorLabelKey(answer.code);
            plan.status.textContent = this.label(root, key === 'alreadyRunning' ? 'alreadyRunning' : key, this.label(root, 'error'));
            return;
        }
        // Items saved through AI Fixes are re-checked by AI Fixes too, so its queue shows Verified / Not verified.
        for (const item of plan.data.repair?.items ?? []) {
            if (item.state === 'saved' && Number(item.itemUid) > 0) {
                await postRemediation(this.ajaxUrls().a11y_remediation_refresh, {item: Number(item.itemUid), detailed: false});
            }
        }
        const scanned = contentScanResultMessage(answer.data, (key, fallback = '') => this.label(root, key, fallback));
        const repair = plan.data.repair ?? {};
        const fresh = await this.planAsk(article, {intent: 'list', repairTarget: String(repair.target ?? ''), button, replace: true});
        const freshPlan = fresh ? this.plans?.get(fresh) : null;
        if (!freshPlan) {
            plan.status.textContent = scanned;
            return;
        }
        const open = new Map();
        (freshPlan.data.repair?.items ?? []).forEach((item) => open.set(keyOf(item), (open.get(keyOf(item)) ?? 0) + 1));
        const resolved = [];
        const still = [];
        before.forEach((item) => {
            const left = open.get(item.key) ?? 0;
            if (left > 0) {
                open.set(item.key, left - 1);
                still.push(item);
            } else {
                resolved.push(item);
            }
        });
        const summary = el('div', {class: 'aqt-verify', 'data-aqt-verify': 'true'},
            // The Save itself may have closed the problem already: the head names the check, the lines its outcome.
            el('p', {class: 'aqt-verify__head', text: resolved.length > 0 ? this.label(root, 'verifyChecked', 'Checked the saved content just now.') : scanned}),
            resolved.length > 0 ? el('p', {class: 'aqt-verify__ok'}, el('strong', {text: format(this.label(root, 'verifyResolved', 'Verified fixed (%d):'), resolved.length) + ' '}), resolved.map((item) => item.title).join(', ')) : null,
            still.length > 0 ? el('p', {class: 'aqt-verify__open'}, el('strong', {text: format(this.label(root, 'verifyOpen', 'Still open (%d):'), still.length) + ' '}), still.map((item) => item.title).join(', ')) : null,
        );
        fresh.querySelector('.aqt-answer')?.prepend(summary);
    }

    /**
     * "Prepare fix" / "Prepare all": creates the AI Fixes items through the toolbar route (scope re-checked, no AI
     * request), then asks the existing generate route once per new item with the row version the server returned. A
     * second click is ignored (aria-busy); a retry with an old row version is refused by the workflow, so nothing is paid
     * twice. Each card then shows the real outcome: the suggestion (Before/After) with Approve, or why there is none.
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
        if (ids.length > 1 || !article.querySelector('[data-aqt-repair-prepare]:not([data-aqt-repair-prepare="all"])')) {
            article.querySelector('[data-aqt-repair-prepare="all"]')?.closest('.aqt-plan__bulk')?.remove();
        }
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
            // Single finding: focus the suggestion just shown; else the status that summarises the run.
            const outcome = ids.length === 1 ? article.querySelector(`[data-aqt-repair-item="${Number(ids[0])}"] [data-aqt-card-outcome]:not([hidden])`) : null;
            const target = outcome ?? status;
            target.setAttribute('tabindex', '-1');
            target.focus({preventScroll: true});
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
            this.showSuggestion(root, row, item, result.reviewUrl);
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
            aiReason: aiDeclineReason(item),
            reviewUrl: result.reviewUrl,
        });
    }

    /**
     * The suggestion, reviewed in place: Before → After as text, the steps still ahead (Approve, apply in the editor and
     * save, check) and Approve itself. Approving here is AI Fixes' own approve step for exactly this item and row
     * version: an image text or a rich-text element AQG can prove is saved by the workflow ("Apply and verify", with what
     * changes, why, the write note and "Apply in the editor instead"); otherwise it is approved for the editor and
     * applied there. Nothing is "fixed" before TYPO3 saved it and a check confirmed it.
     */
    showSuggestion(root, row, item, reviewUrl) {
        const outcome = row?.querySelector('[data-aqt-card-outcome]');
        if (!outcome) {
            return;
        }
        const before = String(item.originalValue ?? '');
        const after = String(item.finalValue ?? item.proposedValue ?? '');
        const nodes = [
            this.stepper(root, item.status, item.applyMode),
            el('div', {class: 'aqt-diff', role: 'group', 'aria-label': this.label(root, 'planSuggestion', 'Suggested change')},
                el('div', {class: 'aqt-diff__side aqt-diff__side--before'}, el('p', {class: 'aqt-diff__label', text: this.label(root, 'planBefore', 'Before')}), el('p', {class: 'aqt-diff__value', text: before !== '' ? before : this.label(root, 'planEmpty', '(empty)')})),
                el('div', {class: 'aqt-diff__side aqt-diff__side--after'}, el('p', {class: 'aqt-diff__label', text: this.label(root, 'planAfter', 'After')}), el('p', {class: 'aqt-diff__value', text: after})),
            ),
        ];
        const richText = item.kind === 'rich_text';
        const where = [item.fieldLabel, item.targetLabel].filter(Boolean).join(' · ');
        if (where) {
            nodes.splice(1, 0, el('p', {class: 'aqt-outcome__reason', 'data-aqt-review-target': 'true'}, el('strong', {text: `${this.label(root, 'planChanges', 'Changes')}: `}), where));
        }
        if (item.explanation) {
            nodes.push(el('p', {class: 'aqt-outcome__reason', text: String(item.explanation)}));
        }
        const actions = el('p', {class: 'aqt-card__actions'});
        if (item.actions?.approve && Number(item.proposalVersion) > 0) {
            const saves = directSave(item);
            if (saves && richText) {
                nodes.push(el('p', {class: 'aqt-outcome__reason', 'data-aqt-write-note': 'true', text: this.label(root, 'planRichTextNote', 'Changes only this element of the field through TYPO3 DataHandler, with your permissions, then checks the saved record. Live, the change is on the website at once; in a workspace it is published with the workspace. Reversible in the record history.')}));
            }
            actions.append(el('button', {
                type: 'button',
                class: 'aqt-btn aqt-btn--primary',
                'data-aqt-approve': String(Number(item.uid)),
                text: saves
                    ? (richText ? this.label(root, 'planApplyVerify', 'Apply and verify') : this.label(root, 'planApproveSave', 'Approve and save'))
                    : this.label(root, 'planApproveEditor', 'Approve for the editor'),
                onclick: (event) => this.approveSuggestion(root, row, item, reviewUrl, event.currentTarget),
            }));
            if (saves && richText && item.editorAlternative && item.status === 'pending_review') {
                actions.append(el('button', {
                    type: 'button',
                    class: 'aqt-btn',
                    'data-aqt-approve-editor': String(Number(item.uid)),
                    text: this.label(root, 'planApplyInstead', 'Apply in the editor instead'),
                    onclick: (event) => this.approveSuggestion(root, row, item, reviewUrl, event.currentTarget, {direct: false}),
                }));
            }
        }
        if (reviewUrl && safeBackendHref(reviewUrl, this.win)) {
            actions.append(el('a', {href: reviewUrl, class: 'aqt-btn', 'data-aqt-navigate': 'true', 'data-aqt-repair-review': 'true', text: this.label(root, 'planEditInFixes', 'Adjust in AI Fixes')}));
        }
        if (actions.childNodes.length > 0) {
            nodes.push(actions);
        }
        outcome.hidden = false;
        outcome.replaceChildren(...nodes);
        // Reviewed right here: the card's separate "Review suggestion" link would only repeat "Adjust in AI Fixes".
        if (actions.querySelector('a')) {
            row.querySelector('[data-aqt-repair-links] [data-aqt-repair-review]')?.remove();
        }
        // Prepared: "Prepare fix" is done; Approve is the card's one main action now.
        row.querySelector('[data-aqt-repair-links] [data-aqt-repair-prepare]')?.remove();
        row.querySelector('.aqt-card__cost')?.remove();
        this.oneMainAction(row);
        const result = row.querySelector('[data-aqt-repair-result]');
        if (result) {
            result.textContent = this.label(root, 'planReviewBelow', 'Check the suggestion below, then approve it or adjust it in AI Fixes.');
        }
    }

    /**
     * Where the item stands, as the Atlas outcome ladder Suggested → Approved → Saved → Verified: the current step is the
     * state the item has reached (never the next verb), earlier steps are done, Verified turns green only when verified.
     */
    stepper(root, status, applyMode) {
        const steps = [
            ['suggested', 'stepSuggested', 'Suggested'],
            ['reviewed', 'stepReviewed', 'Approved'],
            ['applied', applyMode === 'server' ? 'stepSaved' : 'stepEditor', applyMode === 'server' ? 'Saved' : 'Saved in TYPO3'],
            ['verified', 'stepVerified', 'Verified'],
        ];
        const current = {preparing: -1, no_suggestion: -1, pending_review: 0, approved: 1, in_editor: 1, applying: 1, saved: 2, not_verified: 2, verified: 3}[String(status)] ?? 0;
        return el('ol', {class: `aqt-steps${String(status) === 'not_verified' ? ' aqt-steps--not-verified' : ''}`, 'aria-label': this.label(root, 'stepTitle', 'Progress')}, steps.map(([key, label, fallback], index) => el('li', {
            class: `aqt-steps__step${index < current ? ' aqt-steps__step--done' : ''}${index === current ? ' aqt-steps__step--current' : ''}${index === current && key === 'verified' ? ' aqt-steps__step--verified' : ''}`,
            'aria-current': index === current ? 'step' : null,
            'data-step': key,
        }, el('span', {class: 'aqt-steps__dot', 'aria-hidden': 'true'}), el('span', {text: this.label(root, label, fallback)}))));
    }

    /**
     * One main action per card, chosen by what the card's state offers next (Prepare fix, Review, Approve, Open in editor
     * to apply, Open editor): the first primary in reading order stays; every other button becomes a quiet one. Without a
     * primary, the first button (the editor for manual work) becomes it. Links stay links.
     */
    oneMainAction(card) {
        const buttons = [...card.querySelectorAll('.aqt-card__outcome:not([hidden]) .aqt-btn, [data-aqt-repair-links] .aqt-btn')];
        const main = buttons.find((button) => button.classList.contains('aqt-btn--primary')) ?? buttons[0];
        buttons.forEach((button) => {
            button.classList.toggle('aqt-btn--primary', button === main);
            button.classList.toggle('aqt-btn--secondary', button !== main);
        });
    }

    /** Approve one suggestion: recorded as seen (it is on screen), then AI Fixes' approve with the returned row version. */
    async approveSuggestion(root, row, item, reviewUrl, button, {direct = true} = {}) {
        if (button.getAttribute('aria-busy') === 'true') {
            return;
        }
        // One decision per card: while it runs, neither "Apply and verify" nor the editor alternative takes a second click.
        const choices = [...(row?.querySelectorAll('[data-aqt-approve], [data-aqt-approve-editor]') ?? [button])];
        choices.forEach((choice) => choice.setAttribute('aria-busy', 'true'));
        const urls = this.ajaxUrls();
        const result = row.querySelector('[data-aqt-repair-result]');
        const seen = await postRemediation(urls.a11y_remediation_view, {item: Number(item.uid), proposalVersion: Number(item.proposalVersion), detailed: false});
        const current = seen.ok ? (seen.data.item ?? item) : item;
        // "Approve and save" (the button the user saw) applies on the server, unless the item now says otherwise.
        const approved = await postRemediation(urls.a11y_remediation_approve, {item: Number(item.uid), rowVersion: Number(current.rowVersion), bulk: false, detailed: false, ...(direct && directSave(item) && directSave({...item, ...current}) ? {mode: 'apply'} : {})});
        choices.forEach((choice) => choice.removeAttribute('aria-busy'));
        if (!approved.ok) {
            if (result) {
                result.textContent = this.label(root, approved.code === 'changed_concurrently' ? 'repairChanged' : errorLabelKey(approved.code), this.label(root, 'error'));
            }
            return;
        }
        const next = approved.data.item ?? {};
        const status = String(next.status ?? '');
        this.repairRow(root, row, {state: ['saved', 'verified'].includes(status) ? 'saved' : 'queued', stateLabel: String(next.statusLabel ?? ''), reason: ''});
        const outcome = row.querySelector('[data-aqt-card-outcome]');
        const nodes = [this.stepper(root, status, next.applyMode ?? item.applyMode)];
        if (status === 'saved' || status === 'verified') {
            nodes.push(el('p', {class: 'aqt-result aqt-result--ok', text: this.label(root, 'planSavedCheck', 'Saved. Check the saved page to confirm the problem is gone.')}));
        } else if (next.editUrl && safeBackendHref(next.editUrl, this.win)) {
            nodes.push(el('p', {class: 'aqt-result', text: String(next.statusReasonLabel || '') || this.label(root, 'planApplyInEditor', 'Approved. Open the element: AQG puts the change into the field, and you save it with TYPO3’s Save button.')}));
            nodes.push(el('p', {class: 'aqt-card__actions'}, el('a', {href: next.editUrl, class: 'aqt-btn aqt-btn--primary', 'data-aqt-navigate': 'true', 'data-aqt-return': 'true', text: this.label(root, 'planOpenToApply', 'Open in editor to apply')})));
        } else {
            nodes.push(el('p', {class: 'aqt-result', text: String(next.statusReasonLabel ?? next.statusLabel ?? '')}));
        }
        if (reviewUrl && safeBackendHref(reviewUrl, this.win)) {
            nodes.push(el('p', {}, el('a', {href: reviewUrl, class: 'aqt-turn__link', 'data-aqt-navigate': 'true', text: this.label(root, 'repairOpenItem')})));
        }
        if (outcome) {
            outcome.hidden = false;
            outcome.replaceChildren(...nodes);
            outcome.setAttribute('tabindex', '-1');
            outcome.focus({preventScroll: true});
            this.oneMainAction(row);
        }
        const live = (this.root ?? root).querySelector('[data-aqt-live]');
        if (live) {
            live.textContent = String(next.statusLabel ?? '');
        }
    }

    /** Updates one finding card in place (state, reason, review link); its other links stay. */
    repairRow(root, row, {state, stateLabel, reason, aiReason = '', reviewUrl = '', ready = false}) {
        if (!row) {
            return;
        }
        row.className = `aqt-card aqt-repair__item aqt-repair__item--${state}`;
        const badge = row.querySelector('[data-aqt-repair-state]');
        if (badge) {
            badge.className = `aqt-state aqt-state--${state} aqt-repair__state aqt-repair__state--${state}`;
            badge.textContent = stateLabel;
        }
        const result = row.querySelector('[data-aqt-repair-result]');
        if (result) {
            result.textContent = reason;
            row.querySelector('[data-aqt-ai-reason]')?.remove();
            if (aiReason !== '') {
                result.after(this.aiReasonLine(root, aiReason));
            }
        }
        if (reviewUrl && safeBackendHref(reviewUrl, this.win) && !row.querySelector('[data-aqt-repair-review]')) {
            let links = row.querySelector('[data-aqt-repair-links]');
            if (!links) {
                links = el('p', {class: 'aqt-card__actions aqt-repair__links', 'data-aqt-repair-links': 'true'});
                row.append(links);
            }
            links.querySelectorAll('a[href]').forEach((link) => {
                if (link.getAttribute('href') === reviewUrl) {
                    link.remove();
                }
            });
            links.prepend(el('a', {href: reviewUrl, class: 'aqt-btn', 'data-aqt-navigate': 'true', 'data-aqt-repair-review': 'true', text: this.label(root, ready ? 'repairReview' : 'repairOpenItem')}));
        }
    }

    /**
     * "Expand": the same assistant (one DOM, one conversation, one set of pending requests) moves into a large modal
     * dialog and back. The native dialog keeps focus inside, closes on Escape and makes the rest of the backend inert,
     * so the context cannot change underneath an open plan. Closing returns the assistant to the toolbar slot and focus
     * to the AQG toolbar button.
     */
    expand(trigger = null) {
        const root = this.root;
        const doc = this.win.document;
        if (!root || this.dialog?.open) {
            return;
        }
        // The non-blocking dock wherever it fits next to the TYPO3 workspace; the dialog only as the fallback.
        if (this.dock.available()) {
            this.openDock(trigger);
            return;
        }
        let dialog = this.dialog;
        if (!dialog) {
            dialog = el('dialog', {class: 'aqt-dialog', 'aria-labelledby': 'aqt-assistant-title', 'data-aqt-dialog': 'true'});
            const body = el('div', {class: 'aqt-dialog__body', 'data-aqt-dialog-body': 'true'});
            dialog.append(body);
            dialog.addEventListener('close', () => this.afterCollapse());
            dialog.addEventListener('click', (event) => {
                const link = event.target instanceof this.win.Element ? event.target.closest('a[data-aqt-navigate]') : null;
                if (link && event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey) {
                    // Inside the dialog the toolbar's own link handler cannot see the click: close, then open the URL.
                    event.preventDefault();
                    this.collapse({restoreFocus: false});
                    this.navigate?.(link.getAttribute('href') ?? '', link.hasAttribute('data-aqt-return'));
                    return;
                }
                if (event.target === dialog) {
                    // A click on the backdrop closes like Escape.
                    this.collapse();
                }
            });
            doc.body.append(dialog);
            this.dialog = dialog;
        }
        const toolbar = doc.querySelector('#priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem');
        dialog.classList.toggle('aqt-dialog--theme-dark', Boolean(toolbar?.classList.contains('aqt-toolbar--theme-dark')));
        dialog.classList.toggle('aqt-dialog--theme-light', Boolean(toolbar?.classList.contains('aqt-toolbar--theme-light')));
        const focused = root.contains(doc.activeElement) && doc.activeElement !== trigger ? doc.activeElement : null;
        // As in openDock(): a dropdown render still on its way must not replace the assistant the dialog now holds.
        this.request++;
        this.expandedFrom = root.parentElement;
        this.navigate?.(null);
        dialog.querySelector('[data-aqt-dialog-body]').append(root);
        root.classList.add('aqt-assistant--expanded');
        root.querySelectorAll('[data-aqt-expand]').forEach((button) => {
            button.setAttribute('aria-expanded', 'true');
            button.textContent = this.label(root, 'collapse', 'Back to toolbar');
        });
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
        (focused ?? root.querySelector('[data-aqt-ask-input]') ?? root.querySelector('#aqt-assistant-title'))?.focus({preventScroll: true});
        const log = root.querySelector('[data-aqt-ask-log]');
        if (log) {
            // The latest turn, completely: the conversation continues where the toolbar left it.
            log.scrollTop = log.scrollHeight;
        }
    }

    /**
     * Moves the assistant's one root into the docked column beside the workspace. Nothing is re-rendered or re-sent:
     * the same conversation, pending request and plans continue there, and the TYPO3 workspace stays interactive.
     */
    openDock(trigger = null, {focus = true} = {}) {
        const root = this.root;
        const doc = this.win.document;
        const principal = root?.querySelector('[data-aqt-ask]')?.dataset.principal ?? '';
        const body = this.dock.open(principal);
        if (!root || !body) {
            return false;
        }
        const focused = root.contains(doc.activeElement) && doc.activeElement !== trigger ? doc.activeElement : null;
        // A render still on its way (the dropdown refreshes while its assistant stays clickable) belongs to the place the
        // assistant just left: it must not put a second root there and take the live conversation out of the dock.
        this.request++;
        this.presentation = 'dock';
        this.navigate?.(null);
        body.append(root);
        root.classList.add('aqt-assistant--expanded', 'aqt-assistant--docked');
        this.syncTheme();
        this.syncExpandButtons(root);
        this.showDockedNotice();
        if (focus) {
            (focused ?? root.querySelector('[data-aqt-ask-input]') ?? root.querySelector('#aqt-assistant-title'))?.focus({preventScroll: true});
        }
        const log = root.querySelector('[data-aqt-ask-log]');
        if (log) {
            log.scrollTop = log.scrollHeight;
        }
        this.win.document.dispatchEvent(new CustomEvent('aqg:ask-presentation', {detail: {presentation: 'dock'}}));

        return true;
    }

    /**
     * After a full backend reload: when this tab had Ask AQG docked (sessionStorage, per principal), it is rendered
     * for what is open now and docked again — only for the same principal (user + login session) and only where it fits.
     */
    async restoreDock() {
        let candidate = false;
        try {
            for (let index = 0; index < this.win.sessionStorage.length; index++) {
                const key = this.win.sessionStorage.key(index) ?? '';
                if (key.startsWith('aqg-ask-dock:') && JSON.parse(this.win.sessionStorage.getItem(key) ?? '{}')?.open === true) {
                    candidate = true;
                    break;
                }
            }
        } catch {
            candidate = false;
        }
        if (!candidate || this.presentation !== 'toolbar' || !this.dock.available()) {
            return;
        }
        await this.load();
        const principal = this.root?.querySelector('[data-aqt-ask]')?.dataset.principal ?? '';
        const stored = principal !== '' ? this.dock.stored(principal) : null;
        if (stored?.open && this.presentation === 'toolbar' && this.openDock(null, {focus: false}) && stored.minimized) {
            this.dock.setMinimized(true, {silent: true});
        }
    }

    /** Back to the toolbar: the dock is removed, TYPO3's layout returns, the same root goes back into the dropdown. */
    closeDock({restoreFocus = true} = {}) {
        const root = this.root;
        if (this.presentation !== 'dock') {
            return;
        }
        this.presentation = 'toolbar';
        // A render for the dock still on its way would land in the removed column (see openDock()).
        this.request++;
        this.dock.close();
        if (root) {
            root.classList.remove('aqt-assistant--expanded', 'aqt-assistant--docked');
            const slot = this.slot();
            if (slot) {
                slot.replaceChildren(root);
                slot.hidden = false;
            }
            this.syncExpandButtons(root);
        }
        if (restoreFocus) {
            this.win.document.querySelector('#priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem .dropdown-toggle')?.focus({preventScroll: true});
        }
        this.win.document.dispatchEvent(new CustomEvent('aqg:ask-presentation', {detail: {presentation: 'toolbar'}}));
    }

    /** The dropdown while the assistant is docked: where it is, and the way to it or back (no second conversation). */
    showDockedNotice() {
        const slot = this.slot();
        if (!slot || this.presentation !== 'dock') {
            return;
        }
        const show = el('button', {type: 'button', class: 'aqt-btn aqt-btn--primary', 'data-aqt-dock-show': 'true', text: this.label(slot, 'dockShow', 'Show panel')});
        const back = el('button', {type: 'button', class: 'aqt-btn', 'data-aqt-dock-back': 'true', text: this.label(slot, 'dockBack', 'Back to toolbar')});
        show.addEventListener('click', () => {
            this.navigate?.(null);
            if (this.dock.minimized) {
                this.dock.setMinimized(false);
            }
            (this.root?.querySelector('[data-aqt-ask-input]') ?? this.root)?.focus?.({preventScroll: true});
        });
        back.addEventListener('click', () => this.closeDock());
        slot.hidden = false;
        slot.replaceChildren(el('div', {class: 'aqt-assistant aqt-assistant__docked-note', 'data-aqt-docked-note': 'true'},
            el('p', {role: 'status', text: this.label(slot, 'dockOpenHere', 'Ask AQG is open in the panel on the right.')}),
            el('p', {class: 'aqt-assistant__actions'}, show, back),
        ));
    }

    /** The root's own Expand button names what it does in the current presentation. */
    syncExpandButtons(root) {
        root?.querySelectorAll('[data-aqt-expand]').forEach((button) => {
            const expanded = this.presentation !== 'toolbar' || Boolean(this.dialog?.open);
            button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            button.textContent = this.label(root, expanded ? 'collapse' : 'expand', expanded ? 'Back to toolbar' : 'Expand');
            // The dock's own controls (Minimize, Close panel) replace it there.
            button.hidden = this.presentation === 'dock';
        });
    }

    /** The backend colour scheme the toolbar resolved, applied to the dock and the dialog (they live outside it). */
    syncTheme() {
        const toolbar = this.win.document.querySelector('#priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem');
        const dark = Boolean(toolbar?.classList.contains('aqt-toolbar--theme-dark'));
        const light = Boolean(toolbar?.classList.contains('aqt-toolbar--theme-light'));
        this.dock.setTheme(dark, light);
        this.dialog?.classList.toggle('aqt-dialog--theme-dark', dark);
        this.dialog?.classList.toggle('aqt-dialog--theme-light', light);
    }

    collapse({restoreFocus = true} = {}) {
        this.restoreFocusOnCollapse = restoreFocus;
        if (this.dialog?.open && typeof this.dialog.close === 'function') {
            this.dialog.close();
        } else if (this.dialog?.hasAttribute('open')) {
            this.dialog.removeAttribute('open');
            this.afterCollapse();
        }
    }

    afterCollapse() {
        const root = this.root;
        const restore = this.restoreFocusOnCollapse !== false;
        this.restoreFocusOnCollapse = true;
        if (!root) {
            return;
        }
        root.classList.remove('aqt-assistant--expanded');
        root.querySelectorAll('[data-aqt-expand]').forEach((button) => {
            button.setAttribute('aria-expanded', 'false');
            button.textContent = this.label(root, 'expand', 'Expand');
        });
        const slot = this.slot();
        if (slot && !slot.contains(root)) {
            slot.replaceChildren(root);
            slot.hidden = false;
        }
        if (restore) {
            this.win.document.querySelector('#priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem .dropdown-toggle')?.focus({preventScroll: true});
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
            // The same bubble as a plan's question: typed, suggested, follow-up and restored questions all read as the user's turn.
            this.questionBubble(root, String(data.question ?? '')),
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
        this.keepShellInPlace();
        this.navigate?.(null);
        field.focusTarget?.focus?.({preventScroll: true});
    }
}
