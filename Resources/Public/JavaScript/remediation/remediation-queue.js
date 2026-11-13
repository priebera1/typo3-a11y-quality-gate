import {postRemediation, el, format, relativeAge} from '@priebera/a11y-quality-gate/remediation/remediation-api.js';
import {classifySelection, isBulkEligible, summarize, retryable, outcomeOf} from '@priebera/a11y-quality-gate/remediation/remediation-bulk.js';
import {conflictView, countText, filtersActive, formatDateTime, hasProposal, ladderView, logRow, outcomeChip, permissionLineVisible, rangeLabel, readQueueFilters, resultCountText, resultKind, siteScope} from '@priebera/a11y-quality-gate/remediation/remediation-filters.js';

// Stroked 16px icons of the shared empty-state ring (static path data, never content).
const EMPTY_ICONS = {
    check: ['M4.6 8.3 L7 10.6 L11.5 5.6'],
    search: ['M7 2.8 A4.2 4.2 0 1 0 7 11.2 A4.2 4.2 0 1 0 7 2.8 Z', 'M10.1 10.1 L13.2 13.2'],
    clock: ['M8 2.4 A5.6 5.6 0 1 0 8 13.6 A5.6 5.6 0 1 0 8 2.4 Z', 'M8 5 V8.2 L10.2 9.6'],
};

/** Empty state in the shared .aqg-empty anatomy (Atlas): dashed icon ring, title, text and an optional action. */
function emptyState(icon, title, text, action = null) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 16 16');
    svg.setAttribute('width', '16');
    svg.setAttribute('height', '16');
    svg.setAttribute('focusable', 'false');
    for (const d of EMPTY_ICONS[icon] ?? []) {
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', d);
        path.setAttribute('fill', 'none');
        path.setAttribute('stroke', 'currentColor');
        path.setAttribute('stroke-width', '1.4');
        path.setAttribute('stroke-linecap', 'round');
        path.setAttribute('stroke-linejoin', 'round');
        svg.append(path);
    }

    return el('div', {class: 'aqg-remediation__state aqg-empty'},
        el('span', {class: 'aqg-empty__icon', 'aria-hidden': 'true'}, svg),
        el('h2', {class: 'aqg-remediation__state-title aqg-empty__title', text: title}),
        el('p', {class: 'aqg-empty__body', text}),
        action ? el('div', {class: 'aqg-empty__actions'}, action) : null);
}

/**
 * Accessibility › AI fixes. The list, the review drawer and the bulk review of the remediation queue.
 *
 * The server decides every step (RemediationAjaxController): this script sends an item uid with the row version it
 * shows and replaces the item with the server's answer. A suggestion is approvable only after its complete change was
 * displayed here (the drawer reports the view; the server refuses an approval without it).
 */
export class RemediationQueue {
    constructor(root) {
        this.root = root;
        this.config = JSON.parse(root.querySelector('[data-aqg-remediation-config]')?.textContent ?? '{}');
        this.labels = this.config.labels ?? {};
        // Backend language locale ("de-DE", "en-GB") for dates and numbers; never the browser's.
        this.locale = String(this.config.locale ?? '') || 'en-GB';
        this.urls = this.config.urls ?? {};
        this.state = {tab: ['open', 'mine', 'done', 'log'].includes(this.config.view) ? this.config.view : 'open', page: 1, filters: {}, sites: [...(this.config.sites ?? [])]};
        this.pageUid = Number(this.config.pageUid) > 0 ? Number(this.config.pageUid) : 0;
        this.counts = {};
        this.logState = {page: 1};
        this.logSequence = 0;
        this.searchTimer = null;
        this.items = [];
        this.selected = new Set();
        this.drawerIndex = -1;
        this.drawerItem = null;
        this.drawerReturnFocus = null;
        this.editing = false;
        this.pollTimer = null;
        this.bulkRun = null;
        this.loadSequence = 0;
    }

    l(key, fallback = '') {
        const value = this.labels[key];
        return value && value !== `remediation.js.${key}` ? value : fallback;
    }

    init() {
        this.list = this.root.querySelector('[data-aqg-rq-list]');
        this.resultCount = this.root.querySelector('[data-aqg-rq-resultcount]');
        this.live = this.root.querySelector('[data-aqg-rq-live]');
        this.drawer = this.root.querySelector('[data-aqg-rq-drawer]');
        this.drawerPanel = this.root.querySelector('[data-aqg-rq-drawer-panel]');
        this.bulkBar = this.root.querySelector('[data-aqg-rq-bulkbar]');
        this.bulkDialog = this.root.querySelector('[data-aqg-rq-bulk-dialog]');
        this.pagination = this.root.querySelector('[data-aqg-rq-pagination]');
        this.queueView = this.root.querySelector('[data-aqg-rq-queueview]');
        this.logView = this.root.querySelector('[data-aqg-rq-logview]');
        this.logList = this.root.querySelector('[data-aqg-rq-log]');
        this.logPagination = this.root.querySelector('[data-aqg-rq-log-pagination]');
        if (!this.list) {
            return;
        }
        const filters = this.root.querySelector('[data-aqg-rq-filters]');
        this.filterForm = filters;
        filters?.addEventListener('change', (event) => {
            if (event.target?.name !== 'query') {
                this.applyFilters(filters);
            }
        });
        // Search narrows while typing; the request waits for a short pause so every key is not a round trip.
        filters?.querySelector('[name="query"]')?.addEventListener('input', () => {
            window.clearTimeout(this.searchTimer ?? undefined);
            this.searchTimer = window.setTimeout(() => this.applyFilters(filters), 300);
        });
        filters?.addEventListener('submit', (event) => {
            event.preventDefault();
            window.clearTimeout(this.searchTimer ?? undefined);
            this.applyFilters(filters);
        });
        this.state.filters = readQueueFilters(filters, this.pageUid);
        this.root.querySelector('[data-aqg-rq-pagechip-remove]')?.addEventListener('click', () => this.removePageFilter());
        this.root.querySelectorAll('[data-aqg-rq-tab]').forEach((tab) => tab.addEventListener('click', () => this.selectTab(tab.getAttribute('data-aqg-rq-tab'))));
        const logFilters = this.root.querySelector('[data-aqg-rq-log-filters]');
        logFilters?.addEventListener('change', () => {
            this.logState.page = 1;
            this.loadLog();
        });
        logFilters?.addEventListener('submit', (event) => {
            event.preventDefault();
            this.loadLog();
        });
        this.bulkBar?.addEventListener('click', (event) => {
            const action = event.target.closest('[data-aqg-rq-bulk]')?.getAttribute('data-aqg-rq-bulk');
            if (action === 'clear') {
                this.selected.clear();
                this.renderList();
            } else if (action === 'review') {
                this.openBulkDialog(event.target.closest('button'));
            }
        });
        this.drawer?.addEventListener('keydown', (event) => this.onDrawerKey(event));
        this.drawer?.addEventListener('click', (event) => {
            if (event.target === this.drawer) {
                this.closeDrawer();
            }
        });
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.load({quiet: true});
            }
        });
        window.addEventListener('focus', () => this.load({quiet: true}));
        this.selectTab(this.state.tab, {initial: true});
        // A link from Page Detail ("Review suggestion") names one item: its drawer opens right away.
        if (Number(this.config.openItem) > 0) {
            this.openItem(Number(this.config.openItem));
        }
        this.schedulePoll();
    }

    /** Queue views (All open · My queue · Done) share the list; Change log is its own read-only view (Atlas 08·E). */
    selectTab(tab, {initial = false} = {}) {
        this.state.tab = ['open', 'mine', 'done', 'log'].includes(tab) ? tab : 'open';
        this.root.querySelectorAll('[data-aqg-rq-tab]').forEach((other) => {
            if (other.getAttribute('data-aqg-rq-tab') === this.state.tab) {
                other.setAttribute('aria-current', 'page');
            } else {
                other.removeAttribute('aria-current');
            }
        });
        const log = this.state.tab === 'log';
        this.queueView?.toggleAttribute('hidden', log);
        this.logView?.toggleAttribute('hidden', !log);
        this.root.querySelector('[data-aqg-rq-mine-note]')?.toggleAttribute('hidden', this.state.tab !== 'mine');
        if (log) {
            this.logState.page = 1;
            this.loadLog();
            // The counts of the tabs and the sub-navigation still come from the queue.
            if (initial) {
                this.load({quiet: true});
            }
            return;
        }
        this.state.page = 1;
        this.load();
    }

    applyFilters(form) {
        this.state.filters = readQueueFilters(form, this.pageUid);
        const site = form?.querySelector('[name="site"]');
        if (site) {
            this.state.sites = siteScope(site.value, this.config.allSites ?? [], this.config.sites ?? []);
        }
        this.state.page = 1;
        this.load();
    }

    /** The page filter of a deep link (Page Detail) is a visible chip; removing it widens the list to the site. */
    removePageFilter() {
        this.pageUid = 0;
        this.root.querySelector('[data-aqg-rq-pagechip]')?.remove();
        this.applyFilters(this.filterForm);
        this.filterForm?.querySelector('[name="query"]')?.focus();
    }

    schedulePoll() {
        window.clearInterval(this.pollTimer ?? undefined);
        // Backend modules have no push channel: the list refreshes while it is visible (Atlas 08, TYPO3 limitation).
        this.pollTimer = window.setInterval(() => {
            if (!document.hidden && !this.bulkRun && !this.editing && this.state.tab !== 'log') {
                this.load({quiet: true});
            }
        }, Math.max(10, Number(this.config.pollSeconds ?? 20)) * 1000);
    }

    announce(text) {
        if (!this.live || !text) {
            return;
        }
        this.live.textContent = '';
        window.setTimeout(() => {
            this.live.textContent = text;
        }, 30);
    }

    async load({quiet = false} = {}) {
        const sequence = ++this.loadSequence;
        if (!quiet) {
            this.list.setAttribute('aria-busy', 'true');
        }
        const response = await postRemediation(this.urls.list, {
            sites: this.state.sites,
            tab: this.state.tab,
            page: this.state.page,
            returnUrl: this.config.returnUrl ?? '',
            ...this.state.filters,
        });
        if (sequence !== this.loadSequence) {
            return;
        }
        this.list.removeAttribute('aria-busy');
        if (!response.ok) {
            if (!quiet) {
                this.renderError(response.code);
            }
            return;
        }
        this.items = response.data.items ?? [];
        this.meta = {total: response.data.total ?? 0, page: response.data.page ?? 1, pages: response.data.pages ?? 1, perPage: response.data.perPage ?? 25};
        const visibleUids = new Set(this.items.map((item) => item.uid));
        for (const uid of [...this.selected]) {
            if (!visibleUids.has(uid)) {
                this.selected.delete(uid);
            }
        }
        this.counts = response.data.counts ?? {};
        for (const [tab, count] of Object.entries(this.counts)) {
            const target = this.root.querySelector(`[data-aqg-rq-count="${tab}"]`);
            if (target) {
                target.textContent = String(count);
            }
        }
        // Sub-navigation "AI fixes (n)": open items; hidden when there are none, without AI or without the licence.
        const nav = this.root.querySelector('[data-aqg-rq-count="nav"]');
        if (nav) {
            const open = Number(this.counts.open ?? 0);
            nav.textContent = String(open);
            nav.hidden = !this.config.showCount || open === 0;
        }
        if (this.state.tab === 'log') {
            return;
        }
        this.renderList();
        if (this.drawerItem) {
            const fresh = this.items.find((item) => item.uid === this.drawerItem.uid);
            if (fresh && (fresh.rowVersion !== this.drawerItem.rowVersion) && !this.editing) {
                this.openItem(this.drawerItem.uid, {keepFocus: true});
            }
        }
    }

    renderError(code) {
        this.list.replaceChildren(el('div', {class: 'aqg-remediation__state', role: 'alert'},
            el('p', {text: this.errorText(code)}),
            el('button', {type: 'button', class: 'btn btn-default btn-sm', text: this.l('action.retry', 'Try again'), onclick: () => this.load()}),
        ));
    }

    errorText(code, data = {}) {
        if (code === 'rate_limited' && data.retryAfter) {
            return format(this.l('error.rate_limited', 'Too many AI requests. Try again in %d seconds.'), data.retryAfter);
        }
        return this.l(`error.${code}`, '') || this.l('error.generic', 'Something went wrong. Nothing was changed.');
    }

    /**
     * The empty queue (no filter): why it is empty and where the first item comes from. "Fix with AI" in Page Detail
     * prepares items; explanations and editor suggestions never do. The action opens a page where this person is
     * offered "Fix with AI" (computed by the server), else the Overview.
     */
    firstUseState() {
        const first = this.config.firstEligible ?? null;
        const action = first?.url
            ? el('a', {class: 'btn btn-primary btn-sm', href: first.url, text: format(this.l('empty.firstAction', 'Open %s'), first.title || '')})
            : (this.config.overviewUrl ? el('a', {class: 'btn btn-default btn-sm', href: this.config.overviewUrl, text: this.l('empty.overview', 'Go to Overview')}) : null);
        const state = emptyState('check', this.l('empty.title', 'No AI fixes prepared yet'),
            this.l('empty.text', 'Choose “Fix with AI” on an issue in Page Detail to prepare a suggestion here. You review it before anything changes. “Explain with AI” and suggestions inside the editor do not create items.'),
            action);
        state.querySelector('.aqg-empty__actions')?.after(el('p', {class: 'aqg-empty__body aqg-remediation__muted', text: first?.url
            ? format(this.l('empty.firstNote', 'Open issues there that AQG can prepare a fix for: %d. Look for “Fix with AI” on the issue.'), Number(first.findings ?? 0))
            : this.l('empty.noEligible', 'No open issue on these sites can be prepared with AI right now. Other issues keep their how-to-fix guidance in Page Detail.')}));

        return state;
    }

    renderList() {
        const total = this.meta?.total ?? 0;
        const filtered = filtersActive(this.state.filters);
        if (this.resultCount) {
            this.resultCount.textContent = resultCountText(this.labels, total, Number(this.counts[this.state.tab] ?? total), filtered, this.locale);
        }
        if (this.items.length === 0) {
            this.list.replaceChildren(filtered
                ? emptyState('search', this.l('noMatch.title', 'No items match these filters'),
                    this.l('noMatch.text', 'Search covers pages you are allowed to see.'),
                    el('button', {type: 'button', class: 'btn btn-default btn-sm', text: this.l('clearFilters', 'Clear filters'), onclick: () => this.clearFilters()}))
                : this.firstUseState());
            this.pagination?.setAttribute('hidden', 'hidden');
            this.renderBulkBar();
            return;
        }

        // Owner UX pass 3.0.0: four reading columns instead of eight equal ones. Where (page, record, field) → issue
        // (rule, severity) → proposed change (or the one reason there is none) → status (chip; owner, source and age as
        // one quiet line) → the next step. Rows stack into labelled blocks in a narrow module (container query).
        const table = el('table', {class: 'aqg-remediation__table'},
            el('caption', {class: 'visually-hidden', text: countText(total, this.l('resultCountOne', '%d item'), this.l('resultCount', '%d items'), this.locale)}),
            el('thead', {}, el('tr', {},
                this.config.canBulk ? el('th', {scope: 'col', class: 'aqg-remediation__col-select'}, el('span', {class: 'visually-hidden', text: this.l('col.select', 'Select')})) : null,
                el('th', {scope: 'col', class: 'aqg-remediation__col-where', text: this.l('col.where', 'Page and record')}),
                el('th', {scope: 'col', class: 'aqg-remediation__col-issue', text: this.l('col.issue', 'Issue')}),
                el('th', {scope: 'col', class: 'aqg-remediation__col-change', text: this.l('col.change', 'Proposed change')}),
                el('th', {scope: 'col', class: 'aqg-remediation__col-status', text: this.l('col.status', 'Status')}),
                el('th', {scope: 'col', class: 'aqg-remediation__col-action'}, el('span', {class: 'visually-hidden', text: this.l('col.actions', 'Actions')})),
            )),
            el('tbody', {}, ...this.items.map((item, index) => this.renderRow(item, index))),
        );
        this.list.replaceChildren(el('div', {class: 'aqg-remediation__tablewrap'}, table));
        this.renderPagination();
        this.renderBulkBar();
    }

    renderRow(item, index) {
        const select = this.config.canBulk
            ? el('td', {class: 'aqg-remediation__col-select'}, isBulkEligible(item)
                ? el('input', {
                    type: 'checkbox',
                    class: 'form-check-input',
                    'aria-label': format(this.l('bulk.selectRow', 'Select %s'), item.ruleTitle),
                    checked: this.selected.has(item.uid),
                    onchange: (event) => {
                        event.target.checked ? this.selected.add(item.uid) : this.selected.delete(item.uid);
                        this.renderBulkBar();
                    },
                })
                : null)
            : null;
        const open = (event) => {
            this.drawerReturnFocus = event.currentTarget;
            this.drawerIndex = index;
            this.openItem(item.uid);
        };
        const titleButton = el('button', {type: 'button', class: 'aqg-remediation__title', 'data-aqg-rq-open': item.uid, onclick: open}, item.ruleTitle);
        // The next step in words: a reviewable proposal is reviewed, everything else is opened.
        // Only a proposal this person may approve or edit is "Review"; anything else is opened (U-09).
        const review = item.status === 'pending_review' && hasProposal(item) && Boolean(item.actions?.approve || item.actions?.edit);
        const next = el('button', {type: 'button', class: `btn btn-sm ${review ? 'btn-default aqg-remediation__next is-review' : 'btn-default aqg-remediation__next'}`, onclick: open},
            el('span', {text: review ? this.l('row.review', 'Review') : this.l('row.open', 'Open')}),
            el('span', {class: 'visually-hidden', text: ` ${item.ruleTitle} · ${item.pageTitle}`}));
        const target = [item.targetLabel, item.fieldLabel].filter(Boolean).join(' · ');

        return el('tr', {class: `aqg-remediation__row is-${item.status}`},
            select,
            el('td', {class: 'aqg-remediation__col-where', 'data-label': this.l('col.where', 'Page and record')},
                el('span', {class: 'aqg-remediation__page'}, el('span', {text: item.pageTitle}), ' ', el('span', {class: 'aqg-remediation__uid', text: `[${item.pageUid}]`})),
                target ? el('span', {class: 'aqg-remediation__target', text: target}) : null),
            el('td', {class: 'aqg-remediation__col-issue', 'data-label': this.l('col.issue', 'Issue')},
                el('span', {class: 'aqg-remediation__issue'}, this.severityChip(item), titleButton)),
            el('td', {'data-label': this.l('col.change', 'Proposed change'), class: 'aqg-remediation__change'}, this.changeSummary(item)),
            el('td', {class: 'aqg-remediation__col-status', 'data-label': this.l('col.status', 'Status')},
                this.statusChip(item),
                el('span', {class: 'aqg-remediation__rowmeta'},
                    el('span', {class: `aqg-remediation__owner-text aqg-remediation__owner-text--${item.owner}`, text: item.ownerLabel}),
                    el('span', {'aria-hidden': 'true', text: ' · '}),
                    el('span', {class: 'aqg-remediation__col-source', text: item.originLabel}),
                    el('span', {'aria-hidden': 'true', text: ' · '}),
                    el('span', {class: 'aqg-remediation__age', text: relativeAge(item.updatedAt, this.labels)})),
                // U-09: an open item this person cannot act on says why in the list, not only after a refused click.
                this.rowBlock(item)),
            el('td', {class: 'aqg-remediation__col-action'}, next),
        );
    }

    /** The proposed change; without a proposal, the one reason there is none (the status column then shows only the chip). */
    changeSummary(item) {
        if (!item.finalValue) {
            const kind = resultKind(item);
            const text = item.statusReasonLabel || (kind === 'preparing' ? this.l('generate.queued', 'Waiting for a suggestion.') : this.l('change.none', 'No proposed change'));
            return el('span', {class: `aqg-remediation__nochange is-${kind}`, text});
        }
        return el('span', {class: 'aqg-remediation__diffline'},
            el('span', {class: 'aqg-remediation__before', text: item.originalValue || this.l('drawer.empty', '(empty)')}),
            el('span', {'aria-hidden': 'true', text: ' → '}),
            el('span', {class: 'visually-hidden', text: ` ${this.l('change.to', 'changes to')} `}),
            el('span', {class: 'aqg-remediation__after', text: item.finalValue}),
        );
    }

    severityChip(item) {
        return el('span', {class: `aqg-remediation__sev aqg-remediation__sev--${item.severity}`, text: item.severityLabel});
    }

    ownerChip(item) {
        return el('span', {class: `aqg-remediation__owner aqg-remediation__owner--${item.owner}`, text: item.ownerLabel});
    }

    /** Outcome chip (shared .aqg-outcome--*, Atlas 15): suggested, approved, saved and verified never share a tone. */
    statusChip(item) {
        const chip = outcomeChip(item.status, item.statusLabel);
        // The reason is shown once: here only when the change column shows a value instead of it.
        return item.statusReasonLabel && !item.isDone && item.finalValue
            ? el('span', {class: 'aqg-remediation__statuswrap'}, chip, el('span', {class: 'aqg-remediation__reason', text: item.statusReasonLabel}))
            : chip;
    }

    renderPagination() {
        if (!this.pagination) {
            return;
        }
        const {page, pages, perPage, total} = this.meta ?? {page: 1, pages: 1, perPage: 25, total: 0};
        if (pages <= 1) {
            this.pagination.setAttribute('hidden', 'hidden');
            return;
        }
        this.pagination.removeAttribute('hidden');
        this.pagination.replaceChildren(...this.pager(rangeLabel(this.l('pagination.range', '%1$d–%2$d of %3$d'), page, perPage, total), page, pages, (target) => this.goTo(target)));
    }

    /** "1–25 of 40" and Previous / Next (Atlas copy deck); the range is the first thing a screen reader hears. */
    pager(range, page, pages, go) {
        // Shared `.aqg-pagination` footer markup (components/_pagination.scss): range left, controls grouped right.
        const step = (direction, disabled, label, target) => el('li', {},
            el('button', {type: 'button', class: `btn btn-default btn-sm aqg-pagination__link aqg-pagination__link--${direction}`, disabled, onclick: () => go(target)},
                el('span', {class: 'aqg-pagination__step-label', text: label}),
            ),
        );
        return [
            el('p', {class: 'aqg-pagination__info', text: range}),
            el('ul', {class: 'aqg-pagination__list'},
                step('prev', page <= 1, this.l('pagination.previous', 'Previous'), page - 1),
                step('next', page >= pages, this.l('pagination.next', 'Next'), page + 1),
            ),
        ];
    }

    goTo(page) {
        this.state.page = page;
        this.load();
    }

    clearFilters() {
        const form = this.root.querySelector('[data-aqg-rq-filters]');
        form?.reset();
        this.pageUid = 0;
        this.root.querySelector('[data-aqg-rq-pagechip]')?.remove();
        this.applyFilters(form);
        form?.querySelector('[name="query"]')?.focus();
    }

    // ---------------------------------------------------------------- change log

    async loadLog() {
        if (!this.logList) {
            return;
        }
        const sequence = ++this.logSequence;
        const form = this.root.querySelector('[data-aqg-rq-log-filters]');
        const data = form ? new FormData(form) : new FormData();
        this.logList.setAttribute('aria-busy', 'true');
        const response = await postRemediation(this.urls.changelog, {
            sites: this.state.sites,
            period: String(data.get('period') ?? '30'),
            person: Number(data.get('person') ?? 0),
            action: String(data.get('action') ?? ''),
            origin: String(data.get('origin') ?? ''),
            page: this.logState.page,
        });
        if (sequence !== this.logSequence) {
            return;
        }
        this.logList.removeAttribute('aria-busy');
        if (!response.ok) {
            this.logList.replaceChildren(el('div', {class: 'aqg-remediation__state', role: 'alert'},
                el('p', {text: this.errorText(response.code)}),
                el('button', {type: 'button', class: 'btn btn-default btn-sm', text: this.l('action.retry', 'Try again'), onclick: () => this.loadLog()}),
            ));
            return;
        }
        this.renderLog(response.data);
    }

    renderLog(data) {
        const entries = data.entries ?? [];
        const total = Number(data.total ?? 0);
        this.syncPeople(data.people ?? []);
        if (this.resultCount) {
            this.resultCount.textContent = countText(total, this.l('log.countOne', '%d entry'), this.l('log.count', '%d entries'), this.locale);
        }
        if (entries.length === 0) {
            this.logList.replaceChildren(emptyState('clock', this.l('log.empty.title', 'No recorded changes'),
                this.l('log.empty.text', 'Nothing matches this period and these filters.')));
            this.logPagination?.setAttribute('hidden', 'hidden');
            return;
        }
        const columns = ['time', 'person', 'page', 'change', 'model', 'outcome'];
        const fallbacks = {time: 'Time', person: 'Person', page: 'Page · field', change: 'Change (before → after)', model: 'Model · source', outcome: 'Outcome'};
        this.logList.replaceChildren(el('table', {class: 'aqg-remediation__table aqg-remediation__logtable'},
            el('caption', {class: 'visually-hidden', text: this.l('log.label', 'Change log')}),
            el('thead', {}, el('tr', {}, ...columns.map((key) => el('th', {scope: 'col', text: this.l(`log.col.${key}`, fallbacks[key])})))),
            el('tbody', {}, ...entries.map((entry) => logRow(entry, (key, fallback) => this.l(key, fallback), (timestamp) => formatDateTime(timestamp, this.locale)))),
        ));
        const page = Number(data.page ?? 1);
        const pages = Number(data.pages ?? 1);
        if (!this.logPagination) {
            return;
        }
        this.logPagination.removeAttribute('hidden');
        this.logPagination.replaceChildren(...this.pager(rangeLabel(this.l('log.range', '%1$d–%2$d of %3$d entries'), page, Number(data.perPage ?? 25), total), page, pages, (target) => {
            this.logState.page = target;
            this.loadLog();
        }).slice(0, pages > 1 ? 2 : 1));
    }

    /** The person filter offers the people who appear in this scope; the current choice is kept. */
    syncPeople(people) {
        const select = this.root.querySelector('[data-aqg-rq-log-filters] [name="person"]');
        if (!select) {
            return;
        }
        const current = select.value;
        [...select.querySelectorAll('option[data-person]')].forEach((option) => option.remove());
        for (const person of people) {
            select.append(el('option', {value: String(person.uid), 'data-person': 'true', text: person.name || `#${person.uid}`}));
        }
        select.value = [...select.options].some((option) => option.value === current) ? current : '0';
    }

    // ---------------------------------------------------------------- drawer

    async openItem(uid, {keepFocus = false} = {}) {
        const response = await postRemediation(this.urls.item, {item: uid, detailed: true, returnUrl: this.config.returnUrl ?? ''});
        if (!response.ok) {
            this.announce(this.errorText(response.code));
            if (response.code === 'not_found') {
                this.closeDrawer({force: true});
                this.load({quiet: true});
            }
            return;
        }
        this.showDrawer(response.data.item, {keepFocus});
    }

    showDrawer(item, {keepFocus = false} = {}) {
        this.drawerItem = item;
        this.editing = false;
        const index = this.items.findIndex((candidate) => candidate.uid === item.uid);
        if (index >= 0) {
            this.drawerIndex = index;
            this.items[index] = {...this.items[index], ...item};
        }
        this.drawerPanel.replaceChildren(this.renderDrawer(item));
        if (this.drawer.hidden) {
            this.drawer.hidden = false;
            document.body.classList.add('aqg-remediation-drawer-open');
        }
        if (!keepFocus) {
            this.drawerPanel.querySelector('#aqg-rq-drawer-title')?.focus();
        }
        this.reportView(item);
    }

    /** The complete change is on screen now: record the view for this exact version (the bulk guard reads it). */
    async reportView(item) {
        if (!item.proposalVersion || item.seen || !item.finalValue) {
            return;
        }
        const response = await postRemediation(this.urls.view, {item: item.uid, proposalVersion: item.proposalVersion, detailed: true});
        if (response.ok) {
            this.drawerItem = response.data.item;
            const index = this.items.findIndex((candidate) => candidate.uid === item.uid);
            if (index >= 0) {
                this.items[index] = {...this.items[index], seen: true};
            }
            this.renderBulkBar();
        }
    }

    closeDrawer({force = false} = {}) {
        if (!this.drawer || this.drawer.hidden) {
            return;
        }
        if (!force && !this.confirmDiscardEdit()) {
            return;
        }
        this.drawer.hidden = true;
        document.body.classList.remove('aqg-remediation-drawer-open');
        this.editing = false;
        const uid = this.drawerItem?.uid;
        this.drawerItem = null;
        this.renderList();
        this.returnFocus(uid);
    }

    /**
     * Focus after the drawer closes: the row's title while the item is still in this view, else the result count
     * above the list (the item left the tab after it was applied or rejected), never the document body (F-33).
     */
    returnFocus(uid) {
        const row = this.drawerReturnFocus?.isConnected ? this.drawerReturnFocus : (uid ? this.list.querySelector(`[data-aqg-rq-open="${uid}"]`) : null);
        if (row) {
            row.focus();
            return row;
        }
        const fallback = this.resultCount ?? this.root.querySelector('h1');
        if (fallback) {
            fallback.setAttribute('tabindex', '-1');
            fallback.focus();
        }
        return fallback;
    }

    onDrawerKey(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.closeDrawer();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }
        const focusable = [...this.drawerPanel.querySelectorAll('a[href], button:not([disabled]), textarea, select, input, summary, [tabindex="0"]')].filter((node) => node.offsetParent !== null || node === document.activeElement);
        if (focusable.length === 0) {
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    /**
     * Drawer (owner UX pass 3.0.0), one reading order: finding and severity → target → current status → the proposed
     * change, only when a real proposal exists → the error or unsupported state → next actions (sticky foot) →
     * provenance in a closed disclosure. A failed preparation shows its reason once, as an error, with no ladder.
     */
    renderDrawer(item) {
        const kind = resultKind(item);
        const position = this.drawerIndex >= 0
            ? format(this.l('drawer.position', '%1$d of %2$d'), this.drawerIndex + 1, this.items.length)
            : '';
        const head = el('div', {class: 'aqg-remediation__drawer-head'},
            el('div', {class: 'aqg-remediation__drawer-titlerow'},
                el('h2', {id: 'aqg-rq-drawer-title', class: 'aqg-remediation__drawer-title', tabindex: '-1', text: item.ruleTitle}),
                el('button', {type: 'button', class: 'btn btn-default btn-sm aqg-remediation__drawer-close', 'aria-label': this.l('drawer.close', 'Close'), onclick: () => this.closeDrawer()},
                    el('span', {'aria-hidden': 'true', text: '×'})),
            ),
            el('div', {class: 'aqg-remediation__drawer-subrow'},
                this.severityChip(item),
                el('div', {class: 'aqg-remediation__drawer-nav'},
                    position ? el('span', {class: 'aqg-remediation__muted aqg-remediation__position', text: position}) : null,
                    el('button', {type: 'button', class: 'btn btn-default btn-sm', disabled: this.drawerIndex <= 0, text: this.l('drawer.prev', 'Previous'), onclick: () => this.step(-1)}),
                    el('button', {type: 'button', class: 'btn btn-default btn-sm', disabled: this.drawerIndex < 0 || this.drawerIndex >= this.items.length - 1, text: this.l('drawer.next', 'Next item'), onclick: () => this.step(1)}),
                ),
            ),
        );

        const facts = el('section', {class: 'aqg-remediation__section aqg-remediation__targetsection', 'aria-labelledby': 'aqg-rq-target-h'},
            el('h3', {id: 'aqg-rq-target-h', class: 'aqg-remediation__h3', text: this.l('drawer.target', 'Target')}),
            el('dl', {class: 'aqg-remediation__facts'},
                el('dt', {text: this.l('drawer.page', 'Page')}), el('dd', {}, el('span', {text: item.pageTitle}), ' ', el('span', {class: 'aqg-remediation__uid', text: `[${item.pageUid}]`})),
                el('dt', {text: this.l('drawer.record', 'Record')}), el('dd', {text: item.targetLabel || '–'}),
                el('dt', {text: this.l('drawer.field', 'Field')}), el('dd', {text: item.fieldLabel}),
            ),
        );

        const permission = permissionLineVisible(item) && ['proposal', 'preparing'].includes(kind)
            ? el('p', {class: `aqg-remediation__permission is-${item.permissionLine}`, text: this.permissionText(item.permissionLine)})
            : null;

        return el('div', {class: 'aqg-remediation__drawer-body', 'data-aqg-rq-kind': kind},
            head,
            el('div', {class: 'aqg-remediation__drawer-scroll'},
                facts,
                this.renderStatus(item, kind, permission),
                kind === 'conflict' && item.conflict ? conflictView(item.conflict, (key, fallback) => this.l(key, fallback)) : null,
                kind === 'proposal' ? this.renderSuggestion(item) : null,
                this.renderProvenance(item),
            ),
            el('div', {class: 'aqg-remediation__drawer-foot'}, this.renderActions(item, kind)),
        );
    }

    rowBlock(item) {
        if (!item || item.isDone || item.isSaved || ['can_apply', 'workspace_apply_in_record', ''].includes(String(item.permissionLine || ''))) {
            return null;
        }
        return el('span', {class: 'aqg-remediation__rowblock', text: this.permissionText(item.permissionLine)});
    }

    permissionText(code) {
        switch (code) {
            case 'bulk_permission_lost':
                return this.l('drawer.bulkLost', 'You prepared this with bulk AI fixes, which your account no longer has. Reject it, or ask someone with bulk access to review it.');
            case 'can_apply':
                return this.l('drawer.canApply', 'You can apply this change.');
            case 'no_edit_access':
                return this.l('drawer.noEditAccess', 'No edit access — someone who can edit this record must apply it.');
            case 'agent_approval_disabled':
                return this.l('drawer.agentDisabled', 'Prepared by an agent: approving agent suggestions is not enabled.');
            case 'no_licence':
                return this.l('drawer.noLicence', 'AI fixes need an active PRO or Agency licence for this site.');
            case 'workspace_apply_in_record':
                return this.l('drawer.workspaceApply', 'In a workspace, AQG does not write this text itself: open the record, paste the reviewed text and save it there.');
            default:
                return this.l('error.permission_denied', 'You may not apply this item.');
        }
    }

    /** The proposed change. Only called when a real proposal exists (resultKind 'proposal'). */
    renderSuggestion(item) {
        const heading = el('div', {class: 'aqg-remediation__suggestion-head'},
            el('h3', {class: 'aqg-remediation__h3', text: this.l('drawer.proposal', 'Proposed change')}),
            item.isEdited ? el('span', {class: 'aqg-remediation__badge', text: this.l('drawer.edited', 'Edited by you')}) : null,
            item.model ? el('span', {class: 'aqg-remediation__muted aqg-remediation__model', text: [item.providerLabel, item.modelLabel || item.model].filter(Boolean).join(' · ')}) : null,
        );
        // Atlas 08·B: the After value of a pending suggestion is the editable field itself, no "Edit value" step. Its
        // text is saved when the person approves, so it is only editable where Approve is offered (never a field whose
        // text nothing would save, e.g. an image use in a workspace).
        const editable = Boolean(item.actions?.edit && item.actions?.approve);
        const afterId = `aqg-rq-after-${item.uid}`;
        const diff = el('dl', {class: 'aqg-remediation__diff'},
            el('dt', {text: this.l('drawer.before', 'Before')}),
            el('dd', {class: 'aqg-remediation__diff-before'}, el('span', {text: item.originalValue || this.l('drawer.empty', '(empty)')})),
            el('dt', {id: `${afterId}-label`}, editable ? el('label', {for: afterId, text: this.l('drawer.after', 'After')}) : this.l('drawer.after', 'After')),
            el('dd', {class: `aqg-remediation__diff-after${editable ? ' is-editable' : ''}`, 'data-aqg-rq-after': 'true'}, editable ? this.renderEditor(item, afterId) : el('span', {text: item.finalValue})),
        );
        const preview = item.previewUrl
            ? el('img', {class: 'aqg-remediation__preview', src: item.previewUrl, alt: this.l('drawer.preview', 'Preview of the image'), loading: 'lazy', width: '96', height: '96'})
            : null;
        const section = el('section', {class: 'aqg-remediation__section aqg-remediation__suggestion'}, heading, preview ? el('div', {class: 'aqg-remediation__diffwrap'}, preview, diff) : diff);
        if (item.explanation) {
            section.append(el('p', {class: 'aqg-remediation__why'}, el('strong', {text: `${this.l('drawer.why', 'Why')}: `}), item.explanation));
        }
        if (item.usage?.inputTokens || item.usage?.outputTokens) {
            section.append(el('p', {class: 'aqg-remediation__usage', text: format(this.l('drawer.tokens', '%1$d tokens in · %2$d out'), item.usage.inputTokens, item.usage.outputTokens)}));
        }
        if (Array.isArray(item.sent) && item.sent.length > 0) {
            section.append(el('details', {class: 'aqg-remediation__sent'},
                el('summary', {text: this.l('drawer.whatWasSent', 'What was sent?')}),
                el('ul', {}, ...item.sent.map((entry) => el('li', {text: this.l(`sent.${entry}`, entry)}))),
                el('p', {class: 'aqg-remediation__muted', text: this.l('drawer.notSent', 'Not sent: user names, other pages, drafts.')}),
            ));
        }
        return section;
    }

    /**
     * The editable After value. Typing only changes the field; the server sees the text when the person approves:
     * a changed value is saved as their edit first (validated there, viewed for its new version) and then applied.
     */
    renderEditor(item, id) {
        const textarea = el('textarea', {id, class: 'form-control aqg-remediation__aftervalue', rows: '3', maxlength: '1000', 'data-aqg-rq-value': 'true'});
        textarea.value = item.finalValue;
        textarea.addEventListener('input', () => {
            // A pending edit must not be replaced by the background refresh.
            this.editing = textarea.value !== item.finalValue;
            textarea.removeAttribute('aria-invalid');
            const error = this.drawerPanel.querySelector(`#${id}-error`);
            if (error) {
                error.hidden = true;
            }
        });
        return el('div', {class: 'aqg-remediation__edit'},
            textarea,
            el('p', {class: 'aqg-remediation__fielderror', id: `${id}-error`, hidden: true}),
        );
    }

    /** Saves a changed After value as the person's edit; returns the fresh item, or null when the server refused it. */
    async saveEditedValue(item) {
        const textarea = this.drawerPanel.querySelector('[data-aqg-rq-value]');
        if (!textarea || textarea.value === item.finalValue) {
            return item;
        }
        const response = await postRemediation(this.urls.edit, {item: item.uid, rowVersion: item.rowVersion, value: textarea.value, detailed: true, returnUrl: this.config.returnUrl ?? ''});
        if (!response.ok) {
            const error = this.drawerPanel.querySelector(`#${textarea.id}-error`);
            if (error) {
                error.hidden = false;
                error.textContent = this.errorText(response.code, response.data);
                textarea.setAttribute('aria-invalid', 'true');
                textarea.setAttribute('aria-describedby', error.id);
            }
            textarea.focus();
            if (response.code === 'changed_concurrently') {
                this.editing = false;
                this.openItem(item.uid, {keepFocus: true});
            }
            return null;
        }
        this.editing = false;
        this.announce(this.l('announce.edited', 'Your value is ready to approve.'));
        return response.data.item;
    }

    /**
     * Current status: the outcome chip, the owner, the honest ladder (only once a proposal exists) and the state in
     * words. A failed preparation, a missing suggestion or a failed apply is one error / notice panel with its reason,
     * shown here and nowhere else in the drawer.
     */
    renderStatus(item, kind = resultKind(item), permission = null) {
        const section = el('section', {class: 'aqg-remediation__section aqg-remediation__statussection', 'aria-labelledby': 'aqg-rq-status-h'},
            el('div', {class: 'aqg-remediation__status-head'},
                el('h3', {id: 'aqg-rq-status-h', class: 'aqg-remediation__h3', text: this.l('drawer.status', 'Status')}),
                outcomeChip(item.status, item.statusLabel),
                el('span', {class: 'aqg-remediation__muted aqg-remediation__ownerline'},
                    el('span', {text: `${this.l('drawer.owner', 'Owner')}: `}), this.ownerChip(item)),
            ),
        );
        if (kind === 'proposal' || kind === 'conflict') {
            section.append(ladderView(item, (key, fallback) => this.l(key, fallback)) ?? '');
        }
        const panel = (tone, title, text) => el('div', {class: `aqg-remediation__panel is-${tone}`, role: tone === 'error' ? 'alert' : 'status'},
            el('p', {class: 'aqg-remediation__panel-title', text: title}),
            text ? el('p', {class: 'aqg-remediation__panel-text', text}) : null);
        if (kind === 'prepFailed') {
            section.append(panel('error', this.l('drawer.prepFailed', 'No suggestion could be prepared'),
                item.statusReasonLabel || this.l('drawer.noSuggestion', 'There is no suggestion to review. Nothing was changed.')));
            return section;
        }
        if (kind === 'noSuggestion') {
            section.append(panel('notice', item.statusLabel, item.statusReasonLabel || this.l('drawer.noSuggestion', 'There is no suggestion to review. Nothing was changed.')));
            return section;
        }
        if (kind === 'preparing') {
            section.append(panel('pending', this.l('generate.queued', 'Waiting for a suggestion.'), item.statusReasonLabel));
            if (permission) {
                section.append(permission);
            }
            return section;
        }
        if (kind === 'proposal' && item.status === 'failed') {
            section.append(panel('error', this.l('drawer.applyFailed', 'Not applied'), `${this.l('outcome.failed', 'Failed. Nothing was written.')} ${item.statusReasonLabel}`.trim()));
            return section;
        }
        // The conflict view states the conflict itself.
        const message = kind === 'conflict' ? '' : this.outcomeMessage(item);
        if (message) {
            section.append(el('p', {class: `aqg-remediation__outcome-text is-${item.status}`, text: message}));
        }
        if (permission) {
            section.append(permission);
        }
        return section;
    }

    outcomeMessage(item) {
        switch (item.status) {
            case 'saved':
                return this.l('outcome.savedChecking', 'Saved to TYPO3. AQG is checking the saved record; the issue counts as resolved only after that.');
            case 'verified':
                return this.l('outcome.verified', 'Saved and verified: the check of the saved record no longer reports this issue.');
            case 'not_verified':
                return `${this.l('outcome.notVerified', 'Saved, not verified.')} ${item.statusReasonLabel}`.trim();
            case 'rejected':
                return this.l('outcome.rejected', 'Suggestion rejected. Nothing in the record was changed.');
            case 'conflict':
                return this.l('outcome.conflict', 'The record changed after this suggestion was made. Applying would overwrite that change, so it is blocked.');
            case 'in_editor':
                return this.l('outcome.inEditor', 'Opened in the editor, not saved. Save it there; closing without saving returns the item to Approved.');
            case 'approved':
                return this.l('outcome.approved', 'Approved for the editor. Open it to apply the change and save with TYPO3 Save.');
            case 'failed':
                return `${this.l('outcome.failed', 'Failed. Nothing was written.')} ${item.statusReasonLabel}`.trim();
            case 'applying':
                return this.l('outcome.saving', 'Saving to TYPO3…');
            case 'not_applicable':
                return item.statusReasonLabel;
            default:
                return '';
        }
    }

    /**
     * Next actions for the item's real state; the server still decides each one (RemediationItemPresenter::actions and
     * RemediationWorkflowService). Without a proposal there is nothing to approve: the record is the place to fix it,
     * "Prepare again" retries after the record was corrected, and "Dismiss" takes the item out of the queue.
     */
    renderActions(item, kind = resultKind(item)) {
        const actions = el('div', {class: 'aqg-remediation__actions'});
        // Navigation links (record, Page issues) sit together at the end, apart from the decisions.
        const links = el('span', {class: 'aqg-remediation__links'});
        const primary = (label, handler) => el('button', {type: 'button', class: 'btn btn-primary btn-sm', 'data-aqg-rq-primary': 'true', text: label, onclick: handler});
        const secondary = (label, handler) => el('button', {type: 'button', class: 'btn btn-default btn-sm', text: label, onclick: handler});
        const link = (label, href, cls = 'btn btn-default btn-sm') => el('a', {class: cls, href, text: label});
        const endLinks = () => {
            if (links.childElementCount > 0) {
                actions.append(links);
            }
        };
        const conflict = kind === 'conflict';
        const withoutProposal = ['prepFailed', 'noSuggestion', 'closed'].includes(kind);

        if (withoutProposal) {
            if (item.recordUrl && !item.isDone) {
                actions.append(link(this.l('action.fixInRecord', 'Open record to fix'), item.recordUrl, 'btn btn-primary btn-sm'));
            }
            if (item.actions?.regenerate) {
                actions.append(secondary(this.l('action.prepareAgain', 'Prepare again'), (event) => this.generate(item, event.currentTarget)));
            }
            if (item.actions?.reopen) {
                actions.append(secondary(this.l('action.reopen', 'Reopen'), () => this.simple('reopen', item)));
            }
            if (item.actions?.reject) {
                actions.append(secondary(this.l('action.dismiss', 'Dismiss'), () => this.showRejectForm(item, actions, {dismiss: true})));
            }
            if (item.recordUrl && item.isDone) {
                links.append(link(this.l('drawer.openRecord', 'Open record'), item.recordUrl, 'btn btn-link btn-sm'));
            }
            if (item.pageDetailUrl) {
                links.append(link(this.l('drawer.openPageDetail', 'Page issues'), item.pageDetailUrl, 'btn btn-link btn-sm'));
            }
            endLinks();
            if (kind === 'prepFailed' && item.actions?.regenerate) {
                actions.append(el('p', {class: 'aqg-remediation__note', text: this.l('drawer.prepFailedNote', 'Correct the record first, then prepare the suggestion again. Nothing was changed.')}));
            }
            return actions;
        }

        if (item.actions?.approve && item.applyMode === 'server') {
            actions.append(primary(item.actions.retry ? this.l('action.retry', 'Try again') : this.l('action.approveApply', 'Approve and apply'), (event) => this.approve(item, event.currentTarget)));
        }
        if (item.actions?.approve && item.applyMode === 'editor' && item.status === 'pending_review') {
            actions.append(primary(this.l('action.approveEditor', 'Approve and open in editor'), (event) => this.approve(item, event.currentTarget)));
        }
        if (item.editUrl && ['approved', 'in_editor'].includes(item.status)) {
            actions.append(link(this.l('action.openEditor', 'Open in editor to apply'), item.editUrl, 'btn btn-primary btn-sm'));
        }
        if (item.actions?.generate) {
            actions.append(primary(this.l('action.generate', 'Prepare suggestion'), (event) => this.generate(item, event.currentTarget)));
        }
        if (item.actions?.regenerate) {
            actions.append(secondary(conflict ? this.l('action.regenerateCurrent', 'Regenerate from current text') : this.l('action.regenerate', 'Regenerate'), (event) => this.generate(item, event.currentTarget)));
        }
        if (item.actions?.verify) {
            actions.append(secondary(this.l('action.verify', 'Check again'), (event) => this.refresh(item, event.currentTarget)));
        }
        if (item.actions?.reopen) {
            actions.append(secondary(this.l('action.reopen', 'Reopen'), () => this.simple('reopen', item)));
        }
        if (item.actions?.reject) {
            const dismiss = kind === 'preparing';
            actions.append(secondary(conflict ? this.l('action.discard', 'Discard suggestion') : (dismiss ? this.l('action.dismiss', 'Dismiss') : this.l('action.reject', 'Reject')), () => this.showRejectForm(item, actions, {dismiss})));
        }
        if (item.recordUrl) {
            // A conflict is compared in the record itself: it opens without any staged change.
            if (conflict) {
                actions.append(link(this.l('action.compare', 'Compare in editor'), item.recordUrl, 'btn btn-default btn-sm'));
            } else {
                links.append(link(this.l('drawer.openRecord', 'Open record'), item.recordUrl, 'btn btn-link btn-sm'));
            }
        }
        if (item.pageDetailUrl) {
            links.append(link(this.l('drawer.openPageDetail', 'Page issues'), item.pageDetailUrl, 'btn btn-link btn-sm'));
        }
        endLinks();
        if (item.applyMode === 'server' && item.actions?.approve) {
            actions.append(el('p', {class: 'aqg-remediation__note', text: this.l('drawer.serverWrite', 'Writes only this image use\'s alternative text through TYPO3 DataHandler. Reversible in the record history.')}));
        } else if (item.applyMode === 'editor' && !item.isDone && kind === 'proposal') {
            actions.append(el('p', {class: 'aqg-remediation__note', text: this.l('drawer.editorApply', 'AQG never rewrites rich text or HTML on the server. The record opens with this change staged; you check it there and save with TYPO3 Save.')}));
        }

        return actions;
    }

    /** Provenance and history stay in a closed disclosure (information-density rule): one visible line, details on demand. */
    renderProvenance(item) {
        const when = (timestamp) => formatDateTime(timestamp, this.locale);
        const line = [item.originLabel, item.createdBy, relativeAge(item.createdAt, this.labels)].filter(Boolean).join(' · ');
        const facts = el('dl', {class: 'aqg-remediation__facts'});
        const add = (label, value) => {
            if (value) {
                facts.append(el('dt', {text: label}), el('dd', {text: value}));
            }
        };
        add(this.l('drawer.model', 'Model'), item.model ? [item.providerLabel, item.modelLabel || item.model].filter(Boolean).join(' · ') : '');
        add(this.l('drawer.modelId', 'Model ID'), item.modelLabel && item.modelLabel !== item.model ? item.model : '');
        add(this.l('drawer.prompt', 'Prompt'), item.promptVersion);
        add(this.l('drawer.rule', 'Rule'), item.ruleId);
        add(this.l('drawer.finding', 'Finding ID'), item.findingUid ? `#${item.findingUid}` : '');
        add(this.l('drawer.createdBy', 'Prepared by'), [item.createdBy, when(item.createdAt)].filter(Boolean).join(' · '));
        add(this.l('drawer.reviewedBy', 'Reviewed by'), [item.reviewedBy, when(item.reviewedAt)].filter(Boolean).join(' · '));
        add(this.l('drawer.appliedBy', 'Applied by'), [item.appliedBy, when(item.appliedAt)].filter(Boolean).join(' · '));
        const history = Array.isArray(item.history) && item.history.length > 0
            ? el('div', {},
                el('h3', {class: 'aqg-remediation__h3', text: format(this.l('drawer.history', 'History (%d)'), item.history.length)}),
                el('ol', {class: 'aqg-remediation__history'}, ...item.history.map((event) => el('li', {},
                    el('span', {text: event.actionLabel}),
                    event.by ? el('span', {class: 'aqg-remediation__muted', text: ` · ${event.by}`}) : null,
                    el('span', {class: 'aqg-remediation__muted', text: ` · ${when(event.at)}`}),
                ))))
            : null;

        return el('section', {class: 'aqg-remediation__provenance'},
            el('p', {class: 'aqg-remediation__muted', text: line}),
            el('details', {}, el('summary', {text: this.l('drawer.provenance', 'Provenance and technical details')}), facts, history),
        );
    }

    /** Reject a proposal, or dismiss an item that has none (same server step; the wording follows the state). */
    showRejectForm(item, container, {dismiss = false} = {}) {
        const id = `aqg-rq-reject-${item.uid}`;
        const select = el('select', {id, class: 'form-select form-select-sm'},
            ...['none', 'factually_wrong', 'decorative', 'solved_differently', 'other'].map((reason) => el('option', {value: reason, text: this.l(`reject.${reason}`, reason)})));
        const form = el('div', {class: 'aqg-remediation__reject', role: 'group', 'aria-labelledby': `${id}-h`},
            el('h3', {id: `${id}-h`, class: 'aqg-remediation__h3', text: dismiss ? this.l('reject.dismissTitle', 'Dismiss this item') : this.l('reject.title', 'Reject suggestion')}),
            el('label', {for: id, text: this.l('reject.reason', 'Reason (optional)')}),
            select,
            el('p', {class: 'aqg-remediation__note', text: dismiss ? this.l('reject.dismissNote', 'The issue stays open with its how-to-fix guidance in Page Detail. Nothing in the record is changed.') : this.l('reject.note', 'The issue stays open. Nothing in the record is changed.')}),
            el('div', {class: 'aqg-remediation__edit-actions'},
                el('button', {type: 'button', class: 'btn btn-default btn-sm', text: this.l('action.cancel', 'Cancel'), onclick: () => this.showDrawer(item, {keepFocus: false})}),
                el('button', {type: 'button', class: 'btn btn-warning btn-sm', text: dismiss ? this.l('action.confirmDismiss', 'Dismiss item') : this.l('action.confirmReject', 'Reject suggestion'), onclick: () => this.simple('reject', item, {reason: select.value})}),
            ),
        );
        container.replaceChildren(form);
        select.focus();
    }

    async approve(item, button) {
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        const edited = await this.saveEditedValue(item);
        if (!edited) {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            return;
        }
        item = edited;
        this.announce(this.l('announce.applying', 'Applying.'));
        const response = await postRemediation(this.urls.approve, {item: item.uid, rowVersion: item.rowVersion, bulk: false, detailed: true, returnUrl: this.config.returnUrl ?? ''});
        if (!response.ok) {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            this.handleActionError(item, response);
            return;
        }
        const fresh = response.data.item;
        this.showDrawer(fresh, {keepFocus: true});
        this.drawerPanel.querySelector('#aqg-rq-drawer-title')?.focus();
        this.announceOutcome(fresh);
        if (fresh.applyMode === 'editor' && fresh.status === 'approved' && fresh.editUrl) {
            window.location.href = fresh.editUrl;
            return;
        }
        this.load({quiet: true});
    }

    announceOutcome(item) {
        const key = {
            saved: 'announce.saved', verified: 'announce.verified', not_verified: 'announce.notVerified',
            rejected: 'announce.rejected', conflict: 'announce.conflict', in_editor: 'announce.inEditor', failed: 'announce.failed',
        }[item.status];
        if (key) {
            this.announce(this.l(key, item.statusLabel));
        }
    }

    async generate(item, button) {
        if (!this.confirmDiscardEdit()) {
            return;
        }
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        this.announce(this.l('generate.preparing', 'Preparing AI suggestion.'));
        const response = await postRemediation(this.urls.generate, {item: item.uid, rowVersion: item.rowVersion, detailed: true, returnUrl: this.config.returnUrl ?? ''});
        if (!response.ok) {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            this.handleActionError(item, response);
            return;
        }
        if (response.data.retryAfter) {
            this.announce(format(this.l('error.rate_limited', 'Too many AI requests. Try again in %d seconds.'), response.data.retryAfter));
        }
        this.showDrawer(response.data.item, {keepFocus: true});
        this.drawerPanel.querySelector('#aqg-rq-drawer-title')?.focus();
        this.load({quiet: true});
    }

    async refresh(item, button) {
        button.disabled = true;
        const response = await postRemediation(this.urls.refresh, {item: item.uid, detailed: true, returnUrl: this.config.returnUrl ?? ''});
        button.disabled = false;
        if (!response.ok) {
            this.handleActionError(item, response);
            return;
        }
        this.showDrawer(response.data.item, {keepFocus: true});
        this.announceOutcome(response.data.item);
        this.load({quiet: true});
    }

    /** A changed After value is only saved by Approve: closing, rejecting or regenerating asks before it is lost. */
    confirmDiscardEdit() {
        return !this.editing || window.confirm(this.l('drawer.discardEdit', 'Discard your changed value? It has not been saved.'));
    }

    async simple(action, item, extra = {}) {
        if (action === 'reject' && !this.confirmDiscardEdit()) {
            return;
        }
        const response = await postRemediation(this.urls[action], {item: item.uid, rowVersion: item.rowVersion, detailed: true, returnUrl: this.config.returnUrl ?? '', ...extra});
        if (!response.ok) {
            this.handleActionError(item, response);
            return;
        }
        this.showDrawer(response.data.item, {keepFocus: false});
        this.announceOutcome(response.data.item);
        this.load({quiet: true});
    }

    handleActionError(item, response) {
        const text = this.errorText(response.code, response.data);
        this.announce(text);
        const slot = this.drawerPanel.querySelector('.aqg-remediation__actions');
        slot?.prepend(el('p', {class: 'aqg-remediation__error', role: 'alert', text}));
        if (['changed_concurrently', 'stale_view', 'invalid_state', 'already_done', 'in_progress'].includes(response.code)) {
            window.setTimeout(() => this.openItem(item.uid, {keepFocus: true}), 600);
        }
    }

    step(direction) {
        const next = this.drawerIndex + direction;
        if (next < 0 || next >= this.items.length) {
            return;
        }
        this.drawerIndex = next;
        this.openItem(this.items[next].uid);
    }

    // ---------------------------------------------------------------- bulk

    selectedItems() {
        return this.items.filter((item) => this.selected.has(item.uid));
    }

    renderBulkBar() {
        if (!this.bulkBar) {
            return;
        }
        const selected = this.selectedItems();
        if (selected.length === 0 || !this.config.canBulk) {
            this.bulkBar.hidden = true;
            return;
        }
        const {eligible, unseen, label} = classifySelection(selected);
        this.bulkBar.hidden = false;
        this.bulkBar.querySelector('[data-aqg-rq-bulkcount]').textContent = format(this.l('bulk.selected', '%d selected'), selected.length);
        this.bulkBar.querySelector('[data-aqg-rq-bulknote]').textContent = unseen.length > 0
            ? format(this.l('bulk.notViewed', '%d not viewed yet'), unseen.length)
            : this.l('bulk.allViewed', 'all changes viewed');
        const button = this.bulkBar.querySelector('[data-aqg-rq-bulk="review"]');
        button.textContent = label === 'apply'
            ? format(this.l('bulk.applyN', 'Approve and apply %d'), eligible.length)
            : countText(eligible.length, this.l('bulk.reviewOne', 'Review %d change…'), this.l('bulk.review', 'Review %d changes…'), this.locale);
        button.dataset.mode = label;
        button.disabled = eligible.length === 0;
    }

    async openBulkDialog(invoker) {
        const selected = this.selectedItems();
        const {eligible, excluded} = classifySelection(selected);
        if (eligible.length === 0 || !this.bulkDialog) {
            return;
        }
        if (eligible.length > Number(this.config.maxBatch ?? 50)) {
            this.announce(format(this.l('bulk.cap', 'At most %d items per run.'), this.config.maxBatch ?? 50));
            return;
        }
        // Always fresh: the dialog shows each item as the server holds it now.
        const fresh = [];
        for (const item of eligible) {
            const response = await postRemediation(this.urls.item, {item: item.uid, detailed: false});
            if (response.ok && isBulkEligible(response.data.item)) {
                fresh.push(response.data.item);
            }
        }
        this.bulkItems = fresh;
        this.bulkDecisions = new Map(fresh.map((item) => [item.uid, item.seen ? 'approve' : 'undecided']));
        this.bulkInvoker = invoker;
        this.renderBulkReview(excluded);
        this.bulkDialog.showModal();
        this.bulkDialog.querySelector('#aqg-rq-bulk-title')?.focus();
        this.bulkDialog.addEventListener('cancel', (event) => {
            if (this.bulkRun) {
                event.preventDefault();
            }
        }, {once: true});
        this.bulkDialog.addEventListener('close', () => {
            this.bulkInvoker?.focus();
            this.load({quiet: true});
        }, {once: true});
    }

    renderBulkReview(excluded = []) {
        const approvedCount = () => [...this.bulkDecisions.values()].filter((decision) => decision === 'approve').length;
        const primary = el('button', {type: 'button', class: 'btn btn-primary btn-sm'});
        const pendingNote = el('p', {class: 'aqg-remediation__note', 'aria-live': 'polite'});
        const updatePrimary = () => {
            const count = approvedCount();
            primary.textContent = format(this.l('bulk.applyN', 'Approve and apply %d'), count);
            primary.disabled = count === 0;
            pendingNote.textContent = format(this.l('bulk.dialogText', 'Open each change to approve it. Items you have not opened stay in the queue (%d).'), this.bulkItems.length - count);
        };
        const list = el('ol', {class: 'aqg-remediation__bulklist'}, ...this.bulkItems.map((item) => {
            const approve = el('input', {type: 'checkbox', class: 'form-check-input', id: `aqg-rq-bulk-${item.uid}`, checked: this.bulkDecisions.get(item.uid) === 'approve', 'aria-disabled': item.seen ? null : 'true', 'aria-describedby': item.seen ? null : `aqg-rq-bulk-${item.uid}-hint`});
            const hint = el('span', {class: 'aqg-remediation__muted', id: `aqg-rq-bulk-${item.uid}-hint`, text: item.seen ? '' : this.l('bulk.openFirst', 'Open the change first')});
            approve.addEventListener('click', (event) => {
                if (approve.getAttribute('aria-disabled') === 'true') {
                    event.preventDefault();
                    return;
                }
                this.bulkDecisions.set(item.uid, approve.checked ? 'approve' : 'undecided');
                updatePrimary();
            });
            const details = el('details', {class: 'aqg-remediation__bulkitem'},
                el('summary', {}, el('span', {class: 'aqg-remediation__bulkitem-title', text: `${item.pageTitle} · ${item.targetLabel}`}), ' ', this.severityChip(item)),
                el('dl', {class: 'aqg-remediation__diff'},
                    el('dt', {text: this.l('drawer.before', 'Before')}), el('dd', {class: 'aqg-remediation__diff-before'}, el('span', {text: item.originalValue || this.l('drawer.empty', '(empty)')})),
                    el('dt', {text: this.l('drawer.after', 'After')}), el('dd', {class: 'aqg-remediation__diff-after'}, el('span', {text: item.finalValue})),
                ),
            );
            details.addEventListener('toggle', async () => {
                if (!details.open || item.seen) {
                    return;
                }
                const response = await postRemediation(this.urls.view, {item: item.uid, proposalVersion: item.proposalVersion, detailed: false});
                if (response.ok) {
                    item.seen = true;
                    approve.removeAttribute('aria-disabled');
                    approve.removeAttribute('aria-describedby');
                    hint.textContent = '';
                }
            });
            return el('li', {}, details, el('div', {class: 'aqg-remediation__bulkdecision'},
                approve, el('label', {for: approve.id, text: this.l('bulk.approveItem', 'Approve this change')}), ' ', hint));
        }));
        primary.addEventListener('click', () => this.runBulk(primary));
        updatePrimary();
        this.bulkDialog.replaceChildren(
            el('div', {class: 'aqg-remediation__dialog-head'},
                el('h2', {id: 'aqg-rq-bulk-title', class: 'aqg-remediation__drawer-title', tabindex: '-1', text: countText(this.bulkItems.length, this.l('bulk.dialogTitleOne', 'Review %d image text'), this.l('bulk.dialogTitle', 'Review %d image texts'), this.locale)}),
            ),
            el('div', {class: 'aqg-remediation__dialog-body'},
                excluded.length > 0 ? el('p', {class: 'aqg-remediation__notice', text: countText(excluded.length, this.l('bulk.richTextExcludedOne', '%d item is not applied in bulk (rich text, other states or no permission). It stays in the queue.'), this.l('bulk.richTextExcluded', '%d items are not applied in bulk (rich text, other states or no permission). They stay in the queue.'), this.locale)}) : null,
                list,
                pendingNote,
                el('div', {class: 'aqg-remediation__bulkprogress', 'data-aqg-rq-bulkprogress': 'true', 'aria-live': 'polite'}),
            ),
            el('div', {class: 'aqg-remediation__dialog-foot'},
                el('button', {type: 'button', class: 'btn btn-default btn-sm', 'data-aqg-rq-bulk-cancel': 'true', text: this.l('action.cancel', 'Cancel'), onclick: () => {
                    if (this.bulkRun) {
                        this.bulkRun.stop = true;
                    } else {
                        this.bulkDialog.close();
                    }
                }}),
                primary,
            ),
        );
    }

    async runBulk(primary, retryItems = null) {
        const toApply = retryItems ?? this.bulkItems.filter((item) => this.bulkDecisions.get(item.uid) === 'approve');
        if (toApply.length === 0) {
            return;
        }
        primary.disabled = true;
        const cancel = this.bulkDialog.querySelector('[data-aqg-rq-bulk-cancel]');
        if (cancel) {
            cancel.textContent = this.l('bulk.stop', 'Stop after current item');
        }
        const progress = this.bulkDialog.querySelector('[data-aqg-rq-bulkprogress]');
        const batchId = Array.from(crypto.getRandomValues(new Uint8Array(8)), (byte) => byte.toString(16).padStart(2, '0')).join('');
        this.bulkRun = {stop: false, results: retryItems ? this.bulkRun?.results ?? [] : []};
        const results = retryItems ? (this.bulkResults ?? []).filter((result) => !toApply.some((item) => item.uid === result.uid)) : [];
        let index = 0;
        for (const item of toApply) {
            if (this.bulkRun.stop) {
                break;
            }
            index++;
            progress.textContent = format(this.l('bulk.applyingN', 'Applying %1$d of %2$d…'), index, toApply.length);
            // One request per item, each with its own row version: a conflict or failure never stops the others.
            const response = await postRemediation(this.urls.approve, {item: item.uid, rowVersion: item.rowVersion, bulk: true, batchId, detailed: false});
            results.push(response.ok
                ? {uid: item.uid, label: `${item.pageTitle} · ${item.targetLabel}`, item: response.data.item}
                : {uid: item.uid, label: `${item.pageTitle} · ${item.targetLabel}`, error: response.code === 'network' ? 'network' : response.code, item});
        }
        const stopped = this.bulkRun.stop;
        this.bulkRun = null;
        this.bulkResults = results;
        this.renderBulkSummary(results, stopped);
    }

    renderBulkSummary(results, stopped) {
        const counts = summarize(results);
        const summary = format(this.l('bulk.summary', 'Finished: %1$d saved, %2$d verified, %3$d not verified, %4$d conflicts, %5$d failed, %6$d skipped.'),
            counts.saved, counts.verified, counts.notVerified, counts.conflict, counts.failed, counts.skipped);
        const failed = retryable(results);
        const body = this.bulkDialog.querySelector('.aqg-remediation__dialog-body');
        body.replaceChildren(
            el('p', {class: 'aqg-remediation__summary', role: 'status', text: summary + (stopped ? ` ${this.l('bulk.stopped', 'Stopped: the remaining items stay pending.')}` : '')}),
            el('ul', {class: 'aqg-remediation__results'}, ...results.map((result) => el('li', {class: `is-${outcomeOf(result)}`},
                el('span', {text: result.label}), ' — ', el('strong', {text: this.l(`bulk.result.${outcomeOf(result) === 'not_verified' ? 'notVerified' : outcomeOf(result)}`, outcomeOf(result))}),
                result.item?.statusReasonLabel ? el('span', {class: 'aqg-remediation__muted', text: ` (${result.item.statusReasonLabel})`}) : null,
                result.error ? el('span', {class: 'aqg-remediation__muted', text: ` (${this.errorText(result.error)})`}) : null,
            ))),
            el('p', {class: 'aqg-remediation__note', text: this.l('bulk.conflictsNote', 'Conflicts are never retried automatically: they need a new suggestion.')}),
        );
        this.announce(summary);
        const foot = this.bulkDialog.querySelector('.aqg-remediation__dialog-foot');
        foot.replaceChildren(...[
            failed.length > 0 ? el('button', {type: 'button', class: 'btn btn-default btn-sm', text: format(this.l('bulk.retryFailed', 'Retry failed (%d)'), failed.length), onclick: (event) => {
                const retryItems = failed.map((result) => result.item).filter(Boolean);
                this.runBulk(event.currentTarget, retryItems);
            }}) : null,
            el('button', {type: 'button', class: 'btn btn-primary btn-sm', text: this.l('drawer.close', 'Close'), onclick: () => {
                this.selected.clear();
                this.bulkDialog.close();
            }}),
        ].filter(Boolean));
        body.querySelector('[role="status"]')?.setAttribute('tabindex', '-1');
        body.querySelector('[role="status"]')?.focus();
    }
}

function boot() {
    document.querySelectorAll('[data-aqg-remediation-queue]').forEach((root) => {
        if (!root.dataset.aqgBooted) {
            root.dataset.aqgBooted = '1';
            new RemediationQueue(root).init();
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
