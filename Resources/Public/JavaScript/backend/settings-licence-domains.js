/**
 * Licence tab: the licence's domains as the AQG service reports them for this installation. The service holds the
 * state (the customer portal shows the same list); this module only asks for it and requests changes. Which domain
 * may be activated, the plan's limit and the activation lock are decided by the service, never here.
 */
export const PAGE_SIZE = 10;
const FILTERS = ['all', 'active', 'available', 'not_detected', 'unavailable'];
const RESULT_LIST_LIMIT = 5;

const DEFAULT_LABELS = {
    loading: 'Loading domains…',
    unreachable: 'The domains could not be loaded or changed. Try again later.',
    summaryActiveOfMax: '%1$s of %2$s domains active',
    summaryActiveUnlimited: '%s domains active, no domain limit',
    summaryActiveProject: '%s domains active in this TYPO3 project, no domain limit',
    summaryAvailable: '%s available',
    summaryNotDetected: '%s no longer detected',
    summaryUnavailable: '%s need another plan',
    lockNote: 'An activated domain keeps its slot for %s days before it can be deactivated.',
    trialNote: 'A trial covers the domain it was first used on. Choose PRO to scan more sites.',
    stateActive: 'Active',
    stateActiveNotDetected: 'Active, not detected',
    stateAvailable: 'Available',
    stateUnavailable: 'Unavailable',
    detailNotDetected: 'No TYPO3 site uses this domain any more. It keeps its slot until you deactivate it.',
    detailPlanLimit: 'Every domain of the plan is in use. Deactivate one, or choose Agency for unlimited domains.',
    detailTrial: 'A trial covers one domain. Choose PRO to scan this site.',
    detailLocked: 'Can be deactivated from %s.',
    detailLastDomain: 'The licence keeps at least one active domain.',
    select: 'Select %s',
    activate: 'Activate',
    deactivate: 'Deactivate',
    selected: '%s selected',
    noneSelected: 'No domain selected',
    empty: 'No domain matches the search or filter.',
    emptyAll: 'None of the sites in this installation has a public domain. Development hosts such as .ddev.site need no activation.',
    range: 'Showing %1$s–%2$s of %3$s',
    moreSites: '+%s',
    confirmActivate: 'Activate %1$s? Each activated domain keeps its slot for %2$s days before it can be deactivated.',
    confirmDeactivate: 'Deactivate %s? Scans of a deactivated domain stop at once.',
    resultActivated: 'Activated: %s.',
    resultDeactivated: 'Deactivated: %s.',
    resultUnchanged: 'Unchanged: %s.',
    resultPlanLimit: 'Not activated, the domain limit of the plan is reached: %s.',
    resultNotDetected: 'Not activated, no site of this installation uses it: %s.',
    resultLocked: 'Not deactivated yet, still locked: %s.',
    resultLastDomain: 'Not deactivated, the licence keeps one active domain: %s.',
    resultSkipped: 'Skipped: %s.',
    resultMore: '%s more',
};

const RESULT_GROUPS = [
    ['activated', 'resultActivated', 'ok'],
    ['deactivated', 'resultDeactivated', 'ok'],
    ['already_active', 'resultUnchanged', 'muted'],
    ['not_active', 'resultUnchanged', 'muted'],
    ['plan_limit', 'resultPlanLimit', 'error'],
    ['not_detected', 'resultNotDetected', 'error'],
    ['locked', 'resultLocked', 'error'],
    ['last_domain', 'resultLastDomain', 'error'],
    ['development_host', 'resultSkipped', 'muted'],
    ['invalid_domain', 'resultSkipped', 'muted'],
];

const STATE_TONES = {
    active: 'ok',
    active_not_detected: 'warning',
    available: 'none',
    unavailable: 'error',
};

/** sprintf-style `%s` / `%1$s` placeholders, as the XLIFF labels use them. */
export function formatLabel(template, ...values) {
    let next = 0;

    return String(template).replace(/%(?:(\d+)\$)?s/g, (_match, position) => {
        const index = position ? Number(position) - 1 : next++;

        return values[index] === undefined ? '' : String(values[index]);
    });
}

export function matchesFilter(item, filter) {
    switch (filter) {
        case 'active':
            return item.state === 'active' || item.state === 'active_not_detected';
        case 'available':
            return item.state === 'available';
        case 'not_detected':
            return item.state === 'active_not_detected';
        case 'unavailable':
            return item.state === 'unavailable';
        default:
            return true;
    }
}

export function filterDomains(domains, filter, query) {
    const needle = String(query || '').trim().toLowerCase();

    return domains.filter((item) => matchesFilter(item, filter) && (
        needle === ''
        || item.domain.includes(needle)
        || item.sites.some((site) => site.toLowerCase().includes(needle))
    ));
}

export function filterCounts(overview) {
    const counts = overview?.counts || {};

    return {
        all: Number(counts.all || 0),
        active: Number(counts.active || 0) + Number(counts.activeNotDetected || 0),
        available: Number(counts.available || 0),
        not_detected: Number(counts.activeNotDetected || 0),
        unavailable: Number(counts.unavailable || 0),
    };
}

export class AqgLicenceDomains {
    constructor(root) {
        this.root = root;
        this.listUrl = root.dataset.listUrl || '';
        this.updateUrl = root.dataset.updateUrl || '';
        this.status = root.querySelector('[data-aqg-domains-status]');
        this.content = root.querySelector('[data-aqg-domains-content]');
        this.summary = root.querySelector('[data-aqg-domains-summary]');
        this.notes = root.querySelector('[data-aqg-domains-notes]');
        this.search = root.querySelector('[data-aqg-domains-search]');
        this.filterButtons = Array.from(root.querySelectorAll('[data-aqg-domains-filter]'));
        this.table = root.querySelector('[data-aqg-domains-table]');
        this.rows = root.querySelector('[data-aqg-domains-rows]');
        this.empty = root.querySelector('[data-aqg-domains-empty]');
        this.selectAll = root.querySelector('[data-aqg-domains-select-all]');
        this.selectedCount = root.querySelector('[data-aqg-domains-selected]');
        this.bulkActivate = root.querySelector('[data-aqg-domains-bulk="activate"]');
        this.bulkDeactivate = root.querySelector('[data-aqg-domains-bulk="deactivate"]');
        this.activateAll = root.querySelector('[data-aqg-domains-bulk="activate-all"]');
        this.confirmPanel = root.querySelector('[data-aqg-domains-confirm]');
        this.confirmText = root.querySelector('[data-aqg-domains-confirm-text]');
        this.confirmButton = root.querySelector('[data-aqg-domains-confirm-accept]');
        this.cancelButton = root.querySelector('[data-aqg-domains-confirm-cancel]');
        this.pager = root.querySelector('[data-aqg-domains-pager]');
        this.range = root.querySelector('[data-aqg-domains-range]');
        this.previous = root.querySelector('[data-aqg-domains-page="previous"]');
        this.next = root.querySelector('[data-aqg-domains-page="next"]');
        this.refreshButton = root.querySelector('[data-aqg-domains-refresh]');

        this.overview = null;
        this.filter = 'all';
        this.query = '';
        this.page = 0;
        this.selected = new Set();
        this.busy = false;
        this.pendingConfirm = null;

        this.bindEvents();
    }

    label(name, ...values) {
        const key = `label${name.charAt(0).toUpperCase()}${name.slice(1)}`;
        const template = this.root.dataset[key] || DEFAULT_LABELS[name] || '';

        return values.length > 0 ? formatLabel(template, ...values) : template;
    }

    bindEvents() {
        this.search?.addEventListener('input', () => {
            this.query = this.search.value;
            this.resetView();
        });
        this.search?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });
        this.filterButtons.forEach((button) => button.addEventListener('click', () => {
            const filter = button.dataset.aqgDomainsFilter;
            if (FILTERS.includes(filter)) {
                this.filter = filter;
                this.resetView();
            }
        }));
        this.selectAll?.addEventListener('change', () => {
            this.pageItems().filter((item) => this.isSelectable(item)).forEach((item) => {
                if (this.selectAll.checked) {
                    this.selected.add(item.domain);
                } else {
                    this.selected.delete(item.domain);
                }
            });
            this.render();
        });
        this.rows?.addEventListener('change', (event) => {
            const checkbox = event.target;
            if (!(checkbox instanceof HTMLInputElement) || !checkbox.dataset.aqgDomainsSelect) {
                return;
            }
            if (checkbox.checked) {
                this.selected.add(checkbox.dataset.aqgDomainsSelect);
            } else {
                this.selected.delete(checkbox.dataset.aqgDomainsSelect);
            }
            this.renderSelection();
        });
        this.rows?.addEventListener('click', (event) => {
            const button = event.target instanceof Element ? event.target.closest('[data-aqg-domains-row-action]') : null;
            if (button instanceof HTMLButtonElement) {
                this.request(button.dataset.aqgDomainsRowAction, [button.dataset.domain], false, button);
            }
        });
        this.bulkActivate?.addEventListener('click', () => {
            this.request('activate', this.selectedWhere((item) => item.canActivate), false, this.bulkActivate);
        });
        this.bulkDeactivate?.addEventListener('click', () => {
            this.request('deactivate', this.selectedWhere((item) => item.canDeactivate), false, this.bulkDeactivate);
        });
        this.activateAll?.addEventListener('click', () => {
            this.request('activate', [], true, this.activateAll);
        });
        this.confirmButton?.addEventListener('click', () => {
            const pending = this.pendingConfirm;
            this.closeConfirm(false);
            if (pending) {
                this.send(pending.action, pending.domains, pending.all);
            }
        });
        this.cancelButton?.addEventListener('click', () => this.closeConfirm(true));
        this.confirmPanel?.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                this.closeConfirm(true);
            }
        });
        this.previous?.addEventListener('click', () => this.goToPage(this.page - 1, this.previous));
        this.next?.addEventListener('click', () => this.goToPage(this.page + 1, this.next));
        this.refreshButton?.addEventListener('click', () => this.load());
    }

    resetView() {
        this.page = 0;
        this.selected.clear();
        this.render();
    }

    /**
     * Search, filters and actions work on every matching domain; a page only limits what is shown. A selection
     * covers the visible page only, so a bulk action never includes domains the user can no longer see.
     */
    goToPage(page, trigger = null) {
        this.page = Math.max(0, Math.min(page, this.pageCount() - 1));
        this.selected.clear();
        this.render();
        // Focus stays on the pressed button; on the first or last page it is disabled and the other one takes it.
        const other = trigger === this.previous ? this.next : this.previous;
        const target = [trigger, other].find((button) => button instanceof HTMLButtonElement && !button.disabled && !this.pager?.hidden);
        (target || this.table)?.focus();
    }

    domains() {
        return Array.isArray(this.overview?.domains) ? this.overview.domains : [];
    }

    visibleItems() {
        return filterDomains(this.domains(), this.filter, this.query);
    }

    pageCount() {
        return Math.max(1, Math.ceil(this.visibleItems().length / PAGE_SIZE));
    }

    pageItems() {
        return this.visibleItems().slice(this.page * PAGE_SIZE, (this.page + 1) * PAGE_SIZE);
    }

    isSelectable(item) {
        return item.canActivate === true || item.canDeactivate === true;
    }

    selectedWhere(predicate) {
        return this.domains().filter((item) => this.selected.has(item.domain) && predicate(item)).map((item) => item.domain);
    }

    async load() {
        this.setStatus(this.label('loading'), 'muted');
        const data = await this.post(this.listUrl, new URLSearchParams());
        if (!data) {
            return;
        }
        this.applyOverview(data);
        this.setStatus('', 'muted');
    }

    request(action, domains, all, trigger) {
        if (this.busy || (!all && domains.length === 0)) {
            return;
        }
        const lockDays = Number(this.overview?.activationLockDays || 0);
        const count = all ? this.filterCountsNow().available : domains.length;
        const subject = all || domains.length !== 1 ? String(count) : domains[0];

        if (action === 'deactivate') {
            this.openConfirm(this.label('confirmDeactivate', subject), {action, domains, all}, trigger);
            return;
        }
        if (lockDays > 0) {
            this.openConfirm(this.label('confirmActivate', subject, lockDays), {action, domains, all}, trigger);
            return;
        }
        this.send(action, domains, all);
    }

    filterCountsNow() {
        return filterCounts(this.overview);
    }

    openConfirm(message, pending, trigger) {
        if (!this.confirmPanel || !this.confirmText) {
            this.send(pending.action, pending.domains, pending.all);
            return;
        }
        this.pendingConfirm = {...pending, trigger};
        this.confirmText.textContent = message;
        this.confirmPanel.hidden = false;
        this.confirmButton?.focus();
    }

    closeConfirm(restoreFocus) {
        const trigger = this.pendingConfirm?.trigger;
        this.pendingConfirm = null;
        if (this.confirmPanel) {
            this.confirmPanel.hidden = true;
        }
        if (restoreFocus && trigger instanceof HTMLElement && trigger.isConnected) {
            trigger.focus();
        }
    }

    async send(action, domains, all) {
        const body = new URLSearchParams();
        body.set('action', action === 'deactivate' ? 'deactivate' : 'activate');
        if (all) {
            body.set('all', '1');
        }
        domains.forEach((domain) => body.append('domains[]', domain));

        const data = await this.post(this.updateUrl, body);
        if (!data) {
            return;
        }
        this.selected.clear();
        this.applyOverview(data);
        const [message, tone] = this.describeResults(Array.isArray(data.results) ? data.results : []);
        this.setStatus(message, tone);
    }

    async post(url, body) {
        if (!url) {
            this.setStatus(this.label('unreachable'), 'error');
            return null;
        }
        this.setBusy(true);
        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body,
            });
            const data = await response.json().catch(() => null);
            if (!response.ok || !data || data.success !== true) {
                // The server's message is bounded and translated; transport details never reach the page.
                const message = data && typeof data.message === 'string' && data.message !== '' ? data.message : this.label('unreachable');
                this.setStatus(message, 'error');
                return null;
            }

            return data;
        } catch {
            this.setStatus(this.label('unreachable'), 'error');
            return null;
        } finally {
            this.setBusy(false);
        }
    }

    applyOverview(data) {
        this.overview = {
            plan: String(data.plan || ''),
            multiProject: data.multiProject === true,
            maxDomains: typeof data.maxDomains === 'number' ? data.maxDomains : null,
            activeDomains: Number(data.activeDomains || 0),
            remainingSlots: typeof data.remainingSlots === 'number' ? data.remainingSlots : null,
            activationLockDays: typeof data.activationLockDays === 'number' ? data.activationLockDays : null,
            counts: data.counts || {},
            domains: (Array.isArray(data.domains) ? data.domains : []).map((item) => ({
                domain: String(item.domain || ''),
                state: String(item.state || ''),
                reason: item.reason || null,
                sites: Array.isArray(item.sites) ? item.sites.map(String) : [],
                lockedUntil: item.lockedUntil || null,
                canActivate: item.canActivate === true,
                canDeactivate: item.canDeactivate === true,
                deactivateBlockedBy: item.deactivateBlockedBy || null,
            })).filter((item) => item.domain !== ''),
        };
        this.page = Math.min(this.page, this.pageCount() - 1);
        if (this.content) {
            this.content.hidden = false;
        }
        this.render();
    }

    describeResults(results) {
        const messages = [];
        let tone = 'ok';
        RESULT_GROUPS.forEach(([result, labelName, groupTone]) => {
            const domains = results.filter((item) => item.result === result).map((item) => String(item.domain));
            if (domains.length === 0) {
                return;
            }
            const shown = domains.slice(0, RESULT_LIST_LIMIT).join(', ');
            const list = domains.length > RESULT_LIST_LIMIT
                ? `${shown}, ${this.label('resultMore', domains.length - RESULT_LIST_LIMIT)}`
                : shown;
            messages.push(this.label(labelName, list));
            if (groupTone === 'error') {
                tone = 'error';
            }
        });

        return [messages.join(' '), tone];
    }

    setBusy(busy) {
        this.busy = busy;
        this.root.setAttribute('aria-busy', busy ? 'true' : 'false');
        this.root.querySelectorAll('button, input').forEach((control) => {
            if (busy) {
                control.dataset.aqgDomainsWasDisabled = control.disabled ? '1' : '0';
                control.disabled = true;
            } else if (control.dataset.aqgDomainsWasDisabled !== undefined) {
                control.disabled = control.dataset.aqgDomainsWasDisabled === '1';
                delete control.dataset.aqgDomainsWasDisabled;
            }
        });
        if (!busy && this.overview) {
            this.renderSelection();
            this.renderPager();
        }
    }

    setStatus(message, tone) {
        if (!this.status) {
            return;
        }
        this.status.textContent = message;
        this.status.hidden = message === '';
        this.status.className = `aqg-inline-status aqg-licence-domains__status aqg-inline-status--${tone === 'ok' ? 'ok' : (tone === 'error' ? 'error' : 'muted')}`;
    }

    render() {
        if (!this.overview) {
            return;
        }
        this.renderSummary();
        this.renderFilters();
        this.renderRows();
        this.renderSelection();
        this.renderPager();
    }

    renderSummary() {
        const overview = this.overview;
        const counts = filterCounts(overview);
        if (this.summary) {
            // The service counts active domains across the whole licence (the PRO limit applies to all of them) and
            // lists this project's domains. An Agency licence spans several projects: this section counts this
            // project's domains, and the licence-wide number stands next to the TYPO3 projects in the licence status.
            const parts = [overview.maxDomains !== null
                ? this.label('summaryActiveOfMax', overview.activeDomains, overview.maxDomains)
                : (overview.multiProject
                    ? this.label('summaryActiveProject', counts.active)
                    : this.label('summaryActiveUnlimited', overview.activeDomains))];
            if (counts.available > 0) {
                parts.push(this.label('summaryAvailable', counts.available));
            }
            if (counts.not_detected > 0) {
                parts.push(this.label('summaryNotDetected', counts.not_detected));
            }
            if (counts.unavailable > 0) {
                parts.push(this.label('summaryUnavailable', counts.unavailable));
            }
            this.summary.replaceChildren(...parts.map((text, index) => {
                const item = document.createElement('li');
                item.className = index === 0 ? 'aqg-licence-domains__summary-main' : 'aqg-licence-domains__summary-item';
                item.textContent = text;
                return item;
            }));
        }
        if (this.notes) {
            const notes = [];
            if (overview.plan === 'trial') {
                notes.push(this.label('trialNote'));
            }
            if (Number(overview.activationLockDays || 0) > 0) {
                notes.push(this.label('lockNote', overview.activationLockDays));
            }
            this.notes.textContent = notes.join(' ');
            this.notes.hidden = notes.length === 0;
        }
        if (this.activateAll) {
            this.activateAll.hidden = !(overview.multiProject && counts.available > 0);
        }
        this.renderLicenceTotal();
    }

    renderLicenceTotal() {
        const total = document.querySelector('[data-aqg-licence-domains-total]');
        const term = document.querySelector('[data-aqg-licence-domains-total-term]');
        if (!total) {
            return;
        }

        const show = this.overview?.multiProject === true;
        total.textContent = show ? formatLabel(total.dataset.label || '%s across all projects', Number(this.overview.activeDomains || 0)) : '';
        total.hidden = !show;
        if (term) {
            term.hidden = !show;
        }
    }

    renderFilters() {
        const counts = filterCounts(this.overview);
        this.filterButtons.forEach((button) => {
            const filter = button.dataset.aqgDomainsFilter;
            const count = button.querySelector('[data-aqg-domains-filter-count]');
            if (count) {
                count.textContent = String(counts[filter] ?? 0);
            }
            button.setAttribute('aria-pressed', filter === this.filter ? 'true' : 'false');
            button.classList.toggle('is-active', filter === this.filter);
        });
    }

    renderRows() {
        if (!this.rows) {
            return;
        }
        const items = this.pageItems();
        this.rows.replaceChildren(...items.map((item, index) => this.buildRow(item, this.page * PAGE_SIZE + index)));
        if (this.empty) {
            this.empty.hidden = items.length > 0;
            this.empty.textContent = this.domains().length === 0 ? this.label('emptyAll') : this.label('empty');
        }
        if (this.table) {
            this.table.hidden = items.length === 0;
        }
    }

    buildRow(item, index) {
        const row = document.createElement('tr');
        row.dataset.aqgDomainsRow = item.domain;
        row.dataset.state = item.state;

        const selectCell = document.createElement('td');
        selectCell.className = 'aqg-licence-domains__select';
        if (this.isSelectable(item)) {
            const id = `aqg-licence-domain-select-${index}`;
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'form-check-input';
            checkbox.id = id;
            checkbox.dataset.aqgDomainsSelect = item.domain;
            checkbox.checked = this.selected.has(item.domain);
            // TYPO3 sizes .form-check-input through variables set on .form-check; without the wrapper it has no size.
            const wrapper = document.createElement('span');
            wrapper.className = 'form-check aqg-licence-domains__check';
            wrapper.append(checkbox);
            const label = document.createElement('label');
            label.className = 'visually-hidden';
            label.htmlFor = id;
            label.textContent = this.label('select', item.domain);
            selectCell.append(wrapper, label);
        }

        const domainCell = document.createElement('th');
        domainCell.scope = 'row';
        domainCell.className = 'aqg-licence-domains__domain';
        const name = document.createElement('span');
        name.className = 'aqg-licence-domains__name';
        name.textContent = item.domain;
        domainCell.append(name);
        const detail = this.detailFor(item);
        if (detail !== '') {
            const detailText = document.createElement('span');
            detailText.className = 'aqg-licence-domains__detail';
            detailText.textContent = detail;
            domainCell.append(detailText);
        }

        const stateCell = document.createElement('td');
        const badge = document.createElement('span');
        badge.className = `aqg-licence-domains__state tone-${STATE_TONES[item.state] || 'none'}`;
        badge.textContent = this.stateLabel(item.state);
        stateCell.append(badge);

        const sitesCell = document.createElement('td');
        sitesCell.className = 'aqg-licence-domains__sites';
        const shownSites = item.sites.slice(0, 3);
        sitesCell.textContent = shownSites.length === 0 ? '—' : shownSites.join(', ');
        if (item.sites.length > shownSites.length) {
            const more = document.createElement('span');
            more.className = 'aqg-licence-domains__more';
            more.textContent = ` ${this.label('moreSites', item.sites.length - shownSites.length)}`;
            more.title = item.sites.join(', ');
            sitesCell.append(more);
        }

        const actionCell = document.createElement('td');
        actionCell.className = 'aqg-licence-domains__action';
        const action = item.canActivate ? 'activate' : (item.canDeactivate ? 'deactivate' : '');
        if (action !== '') {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `btn btn-sm ${action === 'activate' ? 'btn-primary' : 'btn-default'}`;
            button.dataset.aqgDomainsRowAction = action;
            button.dataset.domain = item.domain;
            button.textContent = this.label(action);
            const hidden = document.createElement('span');
            hidden.className = 'visually-hidden';
            hidden.textContent = ` ${item.domain}`;
            button.append(hidden);
            actionCell.append(button);
        }

        row.append(selectCell, domainCell, stateCell, sitesCell, actionCell);

        return row;
    }

    stateLabel(state) {
        switch (state) {
            case 'active':
                return this.label('stateActive');
            case 'active_not_detected':
                return this.label('stateActiveNotDetected');
            case 'available':
                return this.label('stateAvailable');
            default:
                return this.label('stateUnavailable');
        }
    }

    detailFor(item) {
        if (item.state === 'active_not_detected') {
            return this.label('detailNotDetected');
        }
        if (item.state === 'unavailable') {
            return item.reason === 'trial_single_domain' ? this.label('detailTrial') : this.label('detailPlanLimit');
        }
        if (item.deactivateBlockedBy === 'locked' && item.lockedUntil) {
            return this.label('detailLocked', this.formatDate(item.lockedUntil));
        }
        if (item.deactivateBlockedBy === 'last_domain') {
            return this.label('detailLastDomain');
        }

        return '';
    }

    formatDate(value) {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return '';
        }
        const locale = document.documentElement.lang || undefined;
        try {
            return date.toLocaleDateString(locale, {year: 'numeric', month: 'short', day: 'numeric'});
        } catch {
            return date.toISOString().slice(0, 10);
        }
    }

    renderSelection() {
        const activatable = this.selectedWhere((item) => item.canActivate).length;
        const deactivatable = this.selectedWhere((item) => item.canDeactivate).length;
        if (this.selectedCount) {
            this.selectedCount.textContent = this.selected.size > 0 ? this.label('selected', this.selected.size) : this.label('noneSelected');
        }
        if (this.bulkActivate) {
            this.bulkActivate.disabled = this.busy || activatable === 0;
        }
        if (this.bulkDeactivate) {
            this.bulkDeactivate.disabled = this.busy || deactivatable === 0;
        }
        if (this.selectAll) {
            const selectable = this.pageItems().filter((item) => this.isSelectable(item));
            const chosen = selectable.filter((item) => this.selected.has(item.domain)).length;
            this.selectAll.disabled = this.busy || selectable.length === 0;
            this.selectAll.checked = selectable.length > 0 && chosen === selectable.length;
            this.selectAll.indeterminate = chosen > 0 && chosen < selectable.length;
        }
    }

    renderPager() {
        const total = this.visibleItems().length;
        if (this.pager) {
            this.pager.hidden = total <= PAGE_SIZE;
        }
        if (this.range) {
            const first = total === 0 ? 0 : this.page * PAGE_SIZE + 1;
            this.range.textContent = this.label('range', first, Math.min(total, (this.page + 1) * PAGE_SIZE), total);
        }
        if (this.previous) {
            this.previous.disabled = this.busy || this.page === 0;
        }
        if (this.next) {
            this.next.disabled = this.busy || this.page >= this.pageCount() - 1;
        }
    }
}

export function initializeLicenceDomains(root, {autoload = true} = {}) {
    const manager = new AqgLicenceDomains(root);
    if (autoload) {
        manager.load();
    }

    return manager;
}

document.querySelectorAll('[data-aqg-licence-domains="true"]').forEach((root) => {
    initializeLicenceDomains(root);
});
