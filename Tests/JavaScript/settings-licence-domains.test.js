// @vitest-environment jsdom

import {afterEach, describe, expect, it, vi} from 'vitest';
import {
    PAGE_SIZE,
    filterCounts,
    filterDomains,
    formatLabel,
    initializeLicenceDomains,
} from '../../Resources/Public/JavaScript/backend/settings-licence-domains.js';

const renderRoot = () => {
    document.body.innerHTML = `
        <section data-aqg-licence-domains="true" data-list-url="/domains" data-update-url="/domains/update">
            <button type="button" data-aqg-domains-refresh="true">Refresh</button>
            <p data-aqg-domains-status="true" hidden></p>
            <div data-aqg-domains-content="true" hidden>
                <ul data-aqg-domains-summary="true"></ul>
                <p data-aqg-domains-notes="true" hidden></p>
                <input type="search" id="search" data-aqg-domains-search="true">
                <button type="button" data-aqg-domains-filter="all" aria-pressed="true">All <span data-aqg-domains-filter-count="true"></span></button>
                <button type="button" data-aqg-domains-filter="active" aria-pressed="false">Active <span data-aqg-domains-filter-count="true"></span></button>
                <button type="button" data-aqg-domains-filter="available" aria-pressed="false">Available <span data-aqg-domains-filter-count="true"></span></button>
                <button type="button" data-aqg-domains-filter="not_detected" aria-pressed="false">Not detected <span data-aqg-domains-filter-count="true"></span></button>
                <button type="button" data-aqg-domains-filter="unavailable" aria-pressed="false">Unavailable <span data-aqg-domains-filter-count="true"></span></button>
                <span data-aqg-domains-selected="true"></span>
                <button type="button" data-aqg-domains-bulk="activate" disabled>Activate selected</button>
                <button type="button" data-aqg-domains-bulk="deactivate" disabled>Deactivate selected</button>
                <button type="button" data-aqg-domains-bulk="activate-all" hidden>Activate all detected</button>
                <div data-aqg-domains-confirm="true" hidden>
                    <p data-aqg-domains-confirm-text="true"></p>
                    <button type="button" data-aqg-domains-confirm-accept="true">Confirm</button>
                    <button type="button" data-aqg-domains-confirm-cancel="true">Cancel</button>
                </div>
                <table tabindex="-1" data-aqg-domains-table="true">
                    <thead><tr><th><input type="checkbox" id="all" data-aqg-domains-select-all="true"></th></tr></thead>
                    <tbody data-aqg-domains-rows="true"></tbody>
                </table>
                <p data-aqg-domains-empty="true" hidden></p>
                <nav data-aqg-domains-pager="true" hidden>
                    <button type="button" data-aqg-domains-page="previous">Previous</button>
                    <span data-aqg-domains-range="true"></span>
                    <button type="button" data-aqg-domains-page="next">Next</button>
                </nav>
            </div>
        </section>
    `;

    return document.querySelector('[data-aqg-licence-domains="true"]');
};

const domain = (name, state, extra = {}) => ({
    domain: name,
    state,
    reason: null,
    sites: [name.split('.')[0]],
    lockedUntil: null,
    activatedAt: null,
    canActivate: state === 'available',
    canDeactivate: state === 'active' || state === 'active_not_detected',
    deactivateBlockedBy: null,
    ...extra,
});

const overview = (domains, extra = {}) => ({
    success: true,
    plan: 'pro',
    multiProject: false,
    maxDomains: 3,
    activeDomains: domains.filter((item) => item.state.startsWith('active')).length,
    remainingSlots: 3 - domains.filter((item) => item.state.startsWith('active')).length,
    activationLockDays: 90,
    counts: {
        all: domains.length,
        active: domains.filter((item) => item.state === 'active').length,
        activeNotDetected: domains.filter((item) => item.state === 'active_not_detected').length,
        available: domains.filter((item) => item.state === 'available').length,
        unavailable: domains.filter((item) => item.state === 'unavailable').length,
    },
    domains,
    results: [],
    ...extra,
});

const respond = (data, status = 200) => ({ok: status >= 200 && status < 300, status, json: async () => data});

const flush = async () => {
    for (let i = 0; i < 5; i++) {
        await Promise.resolve();
    }
};

const mount = async (data) => {
    const root = renderRoot();
    globalThis.fetch = vi.fn().mockResolvedValue(respond(data));
    const manager = initializeLicenceDomains(root);
    await flush();

    return {root, manager};
};

const rowNames = () => Array.from(document.querySelectorAll('[data-aqg-domains-row]')).map((row) => row.dataset.aqgDomainsRow);

afterEach(() => {
    document.body.innerHTML = '';
    delete globalThis.fetch;
});

describe('Licence domain manager helpers', () => {
    it('formats positional and sequential placeholders', () => {
        expect(formatLabel('%1$s of %2$s domains active', 2, 3)).toBe('2 of 3 domains active');
        expect(formatLabel('Select %s', 'example.com')).toBe('Select example.com');
    });

    it('filters by state and search across domains and site identifiers', () => {
        const domains = [
            domain('a.example', 'active'),
            domain('b.example', 'active_not_detected', {sites: []}),
            domain('c.example', 'available', {sites: ['shop']}),
            domain('d.example', 'unavailable'),
        ];
        expect(filterDomains(domains, 'active', '').map((item) => item.domain)).toEqual(['a.example', 'b.example']);
        expect(filterDomains(domains, 'not_detected', '').map((item) => item.domain)).toEqual(['b.example']);
        expect(filterDomains(domains, 'all', 'SHOP').map((item) => item.domain)).toEqual(['c.example']);
        expect(filterCounts(overview(domains))).toEqual({all: 4, active: 2, available: 1, not_detected: 1, unavailable: 1});
    });
});

describe('Licence domain manager', () => {
    it('shows the summary, states and only the actions the service allows', async () => {
        await mount(overview([
            domain('a.example', 'active', {canDeactivate: false, deactivateBlockedBy: 'locked', lockedUntil: '2026-12-30T00:00:00.000Z'}),
            domain('b.example', 'available'),
            domain('c.example', 'unavailable', {reason: 'plan_limit', canActivate: false}),
        ]));

        expect(globalThis.fetch).toHaveBeenCalledWith('/domains', expect.objectContaining({method: 'POST'}));
        expect(document.querySelector('[data-aqg-domains-content]').hidden).toBe(false);
        expect(document.querySelector('[data-aqg-domains-summary]').textContent).toContain('1 of 3 domains active');
        expect(document.querySelector('[data-aqg-domains-notes]').textContent).toContain('90 days');

        const locked = document.querySelector('[data-aqg-domains-row="a.example"]');
        expect(locked.textContent).toContain('Can be deactivated from');
        expect(locked.querySelector('button')).toBeNull();
        expect(locked.querySelector('input[type="checkbox"]')).toBeNull();

        const available = document.querySelector('[data-aqg-domains-row="b.example"]');
        expect(available.querySelector('[data-aqg-domains-row-action="activate"]')).not.toBeNull();
        const checkbox = available.querySelector('input[type="checkbox"]');
        expect(document.querySelector(`label[for="${checkbox.id}"]`).textContent).toBe('Select b.example');

        const unavailable = document.querySelector('[data-aqg-domains-row="c.example"]');
        expect(unavailable.textContent).toContain('choose Agency');
        expect(unavailable.querySelector('button')).toBeNull();
    });

    it('renders domain names as text, never as markup', async () => {
        await mount(overview([domain('<img src=x onerror=alert(1)>.example', 'available')]));

        expect(document.querySelector('[data-aqg-domains-rows] img')).toBeNull();
        expect(document.querySelector('.aqg-licence-domains__name, th span').textContent).toContain('<img');
    });

    it('confirms a PRO activation with its lock period and posts the domain', async () => {
        await mount(overview([domain('a.example', 'active'), domain('b.example', 'available')]));
        globalThis.fetch.mockResolvedValueOnce(respond(overview(
            [domain('a.example', 'active'), domain('b.example', 'active')],
            {results: [{domain: 'b.example', result: 'activated'}]},
        )));

        document.querySelector('[data-aqg-domains-row-action="activate"]').click();
        const confirm = document.querySelector('[data-aqg-domains-confirm]');
        expect(confirm.hidden).toBe(false);
        expect(confirm.textContent).toContain('Activate b.example?');
        expect(confirm.textContent).toContain('90 days');
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);

        document.querySelector('[data-aqg-domains-confirm-accept]').click();
        await flush();

        expect(globalThis.fetch).toHaveBeenCalledTimes(2);
        const [url, init] = globalThis.fetch.mock.calls[1];
        expect(url).toBe('/domains/update');
        expect(init.body.get('action')).toBe('activate');
        expect(init.body.getAll('domains[]')).toEqual(['b.example']);
        expect(init.body.has('all')).toBe(false);
        expect(document.querySelector('[data-aqg-domains-status]').textContent).toBe('Activated: b.example.');
        expect(document.querySelector('[data-aqg-domains-row="b.example"]').dataset.state).toBe('active');
    });

    it('cancels a confirmation without a request and returns focus', async () => {
        await mount(overview([domain('a.example', 'active'), domain('b.example', 'active')]));

        const button = document.querySelector('[data-aqg-domains-row="b.example"] [data-aqg-domains-row-action="deactivate"]');
        button.focus();
        button.click();
        expect(document.querySelector('[data-aqg-domains-confirm-text]').textContent).toContain('Deactivate b.example?');

        document.querySelector('[data-aqg-domains-confirm-cancel]').click();
        expect(document.querySelector('[data-aqg-domains-confirm]').hidden).toBe(true);
        expect(document.activeElement).toBe(button);
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
    });

    it('reports the domains the service refused in a bulk activation', async () => {
        await mount(overview([domain('a.example', 'active'), domain('b.example', 'available'), domain('c.example', 'available'), domain('d.example', 'available')], {activationLockDays: null}));
        globalThis.fetch.mockResolvedValueOnce(respond(overview(
            [domain('a.example', 'active'), domain('b.example', 'active'), domain('c.example', 'active'), domain('d.example', 'unavailable', {reason: 'plan_limit', canActivate: false})],
            {results: [
                {domain: 'b.example', result: 'activated'},
                {domain: 'c.example', result: 'activated'},
                {domain: 'd.example', result: 'plan_limit'},
            ], activationLockDays: null},
        )));

        const selectAll = document.querySelector('[data-aqg-domains-select-all]');
        selectAll.checked = true;
        selectAll.dispatchEvent(new Event('change'));
        expect(document.querySelector('[data-aqg-domains-selected]').textContent).toBe('4 selected');

        const bulkActivate = document.querySelector('[data-aqg-domains-bulk="activate"]');
        expect(bulkActivate.disabled).toBe(false);
        bulkActivate.click();
        await flush();

        // Only the selected domains that can be activated are requested; the service enforces the limit.
        expect(globalThis.fetch.mock.calls[1][1].body.getAll('domains[]')).toEqual(['b.example', 'c.example', 'd.example']);
        const status = document.querySelector('[data-aqg-domains-status]');
        expect(status.textContent).toContain('Activated: b.example, c.example.');
        expect(status.textContent).toContain('domain limit of the plan is reached: d.example.');
        expect(status.className).toContain('aqg-inline-status--error');
        expect(document.querySelector('[data-aqg-domains-selected]').textContent).toBe('No domain selected');
    });

    it('counts this project in the domain section and the whole Agency licence next to the projects', async () => {
        const agency = overview(
            [domain('a.example', 'active'), domain('b.example', 'active_not_detected'), domain('c.example', 'available')],
            // The service counts active domains licence-wide: three more belong to other projects of the licence.
            {plan: 'agency', multiProject: true, maxDomains: null, remainingSlots: null, activationLockDays: null, activeDomains: 5},
        );
        document.body.innerHTML = '';
        const status = document.createElement('dl');
        status.innerHTML = '<dt data-aqg-licence-domains-total-term="true" hidden>Active domains</dt>'
            + '<dd data-aqg-licence-domains-total="true" data-label="%s across all projects" hidden></dd>';
        const root = renderRoot();
        document.body.prepend(status);
        globalThis.fetch = vi.fn().mockResolvedValue(respond(agency));
        initializeLicenceDomains(root);
        await flush();

        const summary = document.querySelector('[data-aqg-domains-summary]').textContent;
        expect(summary).toContain('2 domains active in this TYPO3 project, no domain limit');
        expect(summary).not.toContain('5');
        const total = document.querySelector('[data-aqg-licence-domains-total]');
        expect(total.hidden).toBe(false);
        expect(total.textContent).toBe('5 across all projects');
        expect(document.querySelector('[data-aqg-licence-domains-total-term]').hidden).toBe(false);
    });

    it('shows no licence-wide total for a single-installation plan', async () => {
        document.body.innerHTML = '';
        const status = document.createElement('dl');
        status.innerHTML = '<dt data-aqg-licence-domains-total-term="true" hidden></dt><dd data-aqg-licence-domains-total="true" hidden></dd>';
        const root = renderRoot();
        document.body.prepend(status);
        globalThis.fetch = vi.fn().mockResolvedValue(respond(overview([domain('a.example', 'active')])));
        initializeLicenceDomains(root);
        await flush();

        expect(document.querySelector('[data-aqg-domains-summary]').textContent).toContain('1 of 3 domains active');
        expect(document.querySelector('[data-aqg-licence-domains-total]').hidden).toBe(true);
    });

    it('offers Activate all detected only for Agency and sends no domain list', async () => {
        const agency = overview(
            Array.from({length: 45}, (_item, index) => domain(`site${String(index).padStart(2, '0')}.example`, index === 0 ? 'active' : 'available')),
            {plan: 'agency', multiProject: true, maxDomains: null, remainingSlots: null, activationLockDays: null},
        );
        await mount(agency);

        const activateAll = document.querySelector('[data-aqg-domains-bulk="activate-all"]');
        expect(activateAll.hidden).toBe(false);
        expect(document.querySelector('[data-aqg-domains-summary]').textContent).toContain('1 domains active in this TYPO3 project, no domain limit');
        expect(rowNames()).toHaveLength(10);
        expect(document.querySelector('[data-aqg-domains-pager]').hidden).toBe(false);
        expect(document.querySelector('[data-aqg-domains-range]').textContent).toBe('Showing 1–10 of 45');

        document.querySelector('[data-aqg-domains-page="next"]').click();
        expect(rowNames()).toHaveLength(10);
        expect(document.querySelector('[data-aqg-domains-range]').textContent).toBe('Showing 11–20 of 45');

        globalThis.fetch.mockResolvedValueOnce(respond(agency));
        activateAll.click();
        await flush();
        const body = globalThis.fetch.mock.calls[1][1].body;
        expect(body.get('all')).toBe('1');
        expect(body.getAll('domains[]')).toEqual([]);
    });

    it('hides Activate all detected for PRO', async () => {
        await mount(overview([domain('a.example', 'active'), domain('b.example', 'available')]));

        expect(document.querySelector('[data-aqg-domains-bulk="activate-all"]').hidden).toBe(true);
    });

    it('filters and searches without a request', async () => {
        await mount(overview([
            domain('a.example', 'active'),
            domain('old.example', 'active_not_detected', {sites: []}),
            domain('shop.example', 'available'),
        ]));

        document.querySelector('[data-aqg-domains-filter="not_detected"]').click();
        expect(rowNames()).toEqual(['old.example']);
        expect(document.querySelector('[data-aqg-domains-row="old.example"]').textContent).toContain('keeps its slot until you deactivate it');
        expect(document.querySelector('[data-aqg-domains-filter="not_detected"]').getAttribute('aria-pressed')).toBe('true');

        document.querySelector('[data-aqg-domains-filter="all"]').click();
        const search = document.querySelector('[data-aqg-domains-search]');
        search.value = 'shop';
        search.dispatchEvent(new Event('input'));
        expect(rowNames()).toEqual(['shop.example']);

        search.value = 'nothing';
        search.dispatchEvent(new Event('input'));
        expect(rowNames()).toEqual([]);
        expect(document.querySelector('[data-aqg-domains-empty]').hidden).toBe(false);
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
    });

    it('shows the server message on a refusal and a generic one on a transport error', async () => {
        const root = renderRoot();
        globalThis.fetch = vi.fn().mockResolvedValue(respond({success: false, code: 'licence_project_mismatch', message: 'Registered to another installation.'}, 409));
        initializeLicenceDomains(root);
        await flush();

        const status = document.querySelector('[data-aqg-domains-status]');
        expect(status.textContent).toBe('Registered to another installation.');
        expect(document.querySelector('[data-aqg-domains-content]').hidden).toBe(true);

        globalThis.fetch.mockRejectedValueOnce(new TypeError('NetworkError when attempting to fetch resource at https://internal'));
        document.querySelector('[data-aqg-domains-refresh]').click();
        await flush();
        expect(status.textContent).toBe('The domains could not be loaded or changed. Try again later.');
        expect(status.textContent).not.toContain('internal');
    });

    it('uses the labels rendered by the template', async () => {
        const root = renderRoot();
        root.dataset.labelSummaryActiveOfMax = '%1$s von %2$s Domains aktiv';
        globalThis.fetch = vi.fn().mockResolvedValue(respond(overview([domain('a.example', 'active')])));
        initializeLicenceDomains(root);
        await flush();

        expect(document.querySelector('[data-aqg-domains-summary]').textContent).toContain('1 von 3 Domains aktiv');
    });

    it('shows 10 domains per page while search, filters and counts cover every domain', async () => {
        expect(PAGE_SIZE).toBe(10);
        const names = Array.from({length: 47}, (_item, index) => `site${String(index + 1).padStart(2, '0')}.example`);
        await mount(overview(
            names.map((name, index) => domain(name, index < 3 ? 'active' : (index < 40 ? 'available' : 'unavailable'), index >= 40 ? {reason: 'plan_limit', canActivate: false} : {})),
            {plan: 'agency', multiProject: true, maxDomains: null, remainingSlots: null, activationLockDays: null},
        ));
        const range = () => document.querySelector('[data-aqg-domains-range]').textContent;
        const count = (filter) => document.querySelector(`[data-aqg-domains-filter="${filter}"] [data-aqg-domains-filter-count]`).textContent;
        const next = document.querySelector('[data-aqg-domains-page="next"]');
        const previous = document.querySelector('[data-aqg-domains-page="previous"]');

        expect(rowNames()).toEqual(names.slice(0, 10));
        expect(range()).toBe('Showing 1–10 of 47');
        expect(previous.disabled).toBe(true);

        // Keyboard users can keep pressing Next: focus stays on it until the last page disables it.
        next.focus();
        for (let page = 2; page <= 5; page++) {
            next.click();
            expect(document.activeElement).toBe(page < 5 ? next : previous);
        }
        expect(rowNames()).toEqual(names.slice(40));
        expect(range()).toBe('Showing 41–47 of 47');
        expect(next.disabled).toBe(true);

        // Search and filters search every domain and return to page 1; the filter counts stay global.
        const search = document.querySelector('[data-aqg-domains-search]');
        search.value = 'site4';
        search.dispatchEvent(new Event('input'));
        expect(rowNames()).toEqual(names.slice(39, 47));
        expect(document.querySelector('[data-aqg-domains-pager]').hidden).toBe(true);
        expect([count('all'), count('active'), count('available'), count('unavailable')]).toEqual(['47', '3', '37', '7']);

        search.value = '';
        search.dispatchEvent(new Event('input'));
        next.click();
        document.querySelector('[data-aqg-domains-filter="available"]').click();
        expect(range()).toBe('Showing 1–10 of 37');
        expect(rowNames()[0]).toBe('site04.example');
        expect(count('all')).toBe('47');
    });

    it('clears the selection when the page changes, so a bulk action only covers visible domains', async () => {
        await mount(overview(
            Array.from({length: 12}, (_item, index) => domain(`site${String(index + 1).padStart(2, '0')}.example`, index === 0 ? 'active' : 'available')),
            {plan: 'agency', multiProject: true, maxDomains: null, remainingSlots: null, activationLockDays: null},
        ));
        const selectAll = document.querySelector('[data-aqg-domains-select-all]');
        selectAll.checked = true;
        selectAll.dispatchEvent(new Event('change'));
        expect(document.querySelector('[data-aqg-domains-selected]').textContent).toBe('10 selected');

        document.querySelector('[data-aqg-domains-page="next"]').click();

        expect(document.querySelector('[data-aqg-domains-selected]').textContent).toBe('No domain selected');
        expect(document.querySelector('[data-aqg-domains-bulk="activate"]').disabled).toBe(true);
        expect(selectAll.checked).toBe(false);
    });

    it('needs no pager for 10 domains or fewer', async () => {
        await mount(overview(Array.from({length: 10}, (_item, index) => domain(`d${index}.example`, 'available'))));

        expect(rowNames()).toHaveLength(10);
        expect(document.querySelector('[data-aqg-domains-pager]').hidden).toBe(true);
    });

    it('puts every row checkbox in a .form-check wrapper, which carries the TYPO3 checkbox size', async () => {
        await mount(overview([domain('a.example', 'active'), domain('b.example', 'available')]));

        const boxes = [...document.querySelectorAll('[data-aqg-domains-rows] input[type="checkbox"]')];
        expect(boxes).toHaveLength(2);
        boxes.forEach((box) => expect(box.parentElement.classList.contains('form-check')).toBe(true));
    });
});
