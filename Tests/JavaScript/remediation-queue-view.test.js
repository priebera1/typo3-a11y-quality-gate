// @vitest-environment jsdom
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {conflictView, filtersActive, logRow, rangeLabel, readQueueFilters, resultCountText, siteScope} from '@priebera/a11y-quality-gate/remediation/remediation-filters.js';
import {RemediationQueue} from '@priebera/a11y-quality-gate/remediation/remediation-queue.js';

const l = (key, fallback) => fallback;

describe('queue filters', () => {
    const form = (html) => {
        document.body.innerHTML = `<form>${html}</form>`;
        return document.querySelector('form');
    };

    it('reads search, status, owner, source and severity; the page filter comes from the chip, not a field', () => {
        const filters = readQueueFilters(form(`
            <input name="query" value="  Water   tariffs ">
            <select name="status"><option value="conflict" selected>Conflict</option></select>
            <select name="owner"><option value="">All</option></select>
            <select name="severity"><option value="-1" selected>All</option></select>
            <select name="origin"><option value="bulk" selected>Bulk</option></select>`), 41);
        expect(filters).toEqual({query: 'Water tariffs', status: 'conflict', owner: '', severity: -1, origin: 'bulk', pageUid: 41});
    });

    it('caps the search text at 100 characters', () => {
        expect(readQueueFilters(form(`<input name="query" value="${'a'.repeat(150)}">`)).query).toHaveLength(100);
    });

    it('treats an untouched form as "no filter", so an empty list is "nothing pending", not "no match"', () => {
        expect(filtersActive(readQueueFilters(form('<input name="query" value=""><select name="severity"><option value="-1" selected>All</option></select>')))).toBe(false);
        expect(filtersActive({query: 'x'})).toBe(true);
        expect(filtersActive({severity: 1})).toBe(true);
        expect(filtersActive({pageUid: 41})).toBe(true);
    });

    it('scopes sites to the chosen one only when the server offered it', () => {
        expect(siteScope('__all', ['a', 'b'], ['a'])).toEqual(['a', 'b']);
        expect(siteScope('b', ['a', 'b'], ['a'])).toEqual(['b']);
        expect(siteScope('forged', ['a', 'b'], ['a'])).toEqual([]);
        expect(siteScope('', ['a', 'b'], ['a'])).toEqual(['a']);
    });
});

describe('counts and pagination copy', () => {
    it('says "1–25 of 40" and "26–40 of 40"', () => {
        expect(rangeLabel('%1$d–%2$d of %3$d', 1, 25, 40)).toBe('1–25 of 40');
        expect(rangeLabel('%1$d–%2$d of %3$d', 2, 25, 40)).toBe('26–40 of 40');
        expect(rangeLabel('%1$d–%2$d of %3$d', 1, 25, 0)).toBe('0–0 of 0');
    });

    it('reports "n of total shown" only while filters narrow the list', () => {
        const labels = {resultCount: '%d items', resultCountOf: '%1$d of %2$d shown'};
        expect(resultCountText(labels, 14, 14, false)).toBe('14 items');
        expect(resultCountText(labels, 3, 14, true)).toBe('3 of 14 shown');
    });
});

describe('three-way conflict', () => {
    it('shows base, saved now and suggested as text', () => {
        const node = conflictView({base: '', current: '<b>Chart</b>', currentKnown: true, currentDecorative: false, proposed: 'Bar chart of prices'}, l);
        const columns = [...node.querySelectorAll('.aqg-remediation__threeway-col')];
        expect(columns.map((column) => column.querySelector('h4').textContent)).toEqual(['Suggestion base', 'Saved now', 'Suggested']);
        expect(columns[0].textContent).toContain('(empty)');
        expect(columns[1].classList.contains('is-current')).toBe(true);
        expect(columns[1].querySelector('b')).toBeNull();
        expect(columns[1].textContent).toContain('<b>Chart</b>');
        expect(columns[2].textContent).toContain('Bar chart of prices');
    });

    it('says when the current value cannot be read instead of guessing it', () => {
        const node = conflictView({base: 'logo', current: '', currentKnown: false, proposed: 'Company logo'}, l);
        expect(node.querySelectorAll('.aqg-remediation__threeway-col')[1].textContent).toContain('can no longer locate');
        expect(conflictView(null, l)).toBeNull();
    });
});

describe('change log row', () => {
    it('shows the step, status before → after and the short hash, never a value', () => {
        const row = logRow({
            at: 1760000000, by: 'Eva Editor', action: 'saved', actionLabel: 'Saved', fromLabel: 'Saving…', toLabel: 'Saved · checking',
            valueHash: '9c41…07ad', pageUid: 41, pageTitle: 'Water tariffs', ruleTitle: 'Image has no alternative text', fieldLabel: 'Image · Alternative text',
            model: 'claude-sonnet', providerLabel: 'Anthropic', originLabel: 'Bulk preparation', outcome: 'verified', outcomeLabel: 'Verified',
        }, l, () => '19.10. 14:02');
        const cells = [...row.querySelectorAll('td')].map((cell) => cell.textContent);
        expect(cells[0]).toBe('19.10. 14:02');
        expect(cells[1]).toBe('Eva Editor');
        expect(cells[3]).toContain('Saved');
        expect(cells[3]).toContain('Saving…');
        expect(cells[3]).toContain('Value hash 9c41…07ad');
        expect(cells[4]).toContain('Anthropic · claude-sonnet');
        expect(cells[5]).toBe('Verified');
    });
});

describe('queue view', () => {
    let requests;
    let answers;

    const item = (overrides = {}) => ({
        uid: 1, rowVersion: 0, proposalVersion: 1, status: 'pending_review', statusLabel: 'Pending review', isDone: false, applyMode: 'server',
        ruleTitle: 'Image has no alternative text', severity: 'critical', severityLabel: 'Critical', owner: 'editor', ownerLabel: 'Editor',
        origin: 'bulk', originLabel: 'Bulk preparation', pageUid: 41, pageTitle: 'Water tariffs', fieldLabel: 'Image', targetLabel: 'chart.png',
        originalValue: '', finalValue: 'Bar chart', seen: true, actions: {}, updatedAt: 0, createdAt: 0, ...overrides,
    });

    const mount = (config = {}) => {
        document.body.innerHTML = `
            <div data-aqg-remediation-queue>
                <script type="application/json" data-aqg-remediation-config>${JSON.stringify({
                    sites: ['main'], allSites: ['main', 'second'], pageUid: 41, view: 'open', showCount: true, canBulk: false,
                    urls: {list: '/list', item: '/item', view: '/view', changelog: '/changelog', reject: '/reject', generate: '/generate'},
                    labels: {}, ...config,
                })}</script>
                <nav><span data-aqg-rq-count="nav" hidden></span></nav>
                <button data-aqg-rq-tab="open"></button><button data-aqg-rq-tab="log"></button>
                <p data-aqg-rq-resultcount></p>
                <div data-aqg-rq-queueview>
                    <form data-aqg-rq-filters>
                        <input name="query" id="q">
                        <select name="status"><option value="" selected>All</option><option value="conflict">Conflict</option></select>
                        <select name="site"><option value="__all" selected>All sites</option><option value="main">Main</option></select>
                        <select name="severity"><option value="-1" selected>All</option></select>
                    </form>
                    <ul><li data-aqg-rq-pagechip><button data-aqg-rq-pagechip-remove></button></li></ul>
                    <div data-aqg-rq-list></div>
                    <nav data-aqg-rq-pagination hidden></nav>
                </div>
                <section data-aqg-rq-logview hidden>
                    <form data-aqg-rq-log-filters><select name="period"><option value="30" selected>30</option></select><select name="person"><option value="0">All</option></select></form>
                    <div data-aqg-rq-log></div><div data-aqg-rq-log-pagination hidden></div>
                </section>
                <div data-aqg-rq-drawer hidden><div data-aqg-rq-drawer-panel></div></div>
                <div data-aqg-rq-live></div>
            </div>`;
        const root = document.querySelector('[data-aqg-remediation-queue]');
        const queue = new RemediationQueue(root);
        queue.init();
        return {root, queue};
    };

    const flush = async () => {
        for (let i = 0; i < 5; i++) {
            await Promise.resolve();
        }
    };

    beforeEach(() => {
        requests = [];
        answers = {
            '/list': () => ({success: true, items: [item()], total: 30, page: 1, pages: 2, perPage: 25, counts: {open: 30, mine: 2, done: 4}}),
            '/changelog': () => ({success: true, entries: [], total: 0, page: 1, pages: 1, perPage: 25, people: [{uid: 3, name: 'Eva Editor'}]}),
        };
        vi.stubGlobal('fetch', vi.fn(async (url, options) => {
            const body = JSON.parse(options.body);
            requests.push({url, body});
            return {ok: true, status: 200, redirected: false, headers: {get: () => 'application/json'}, json: async () => (answers[url] ?? (() => ({success: true})))(body)};
        }));
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('opens exactly the item named by a Page Detail "Review suggestion" link', async () => {
        answers['/item'] = (body) => ({success: true, status: 'item', item: item({uid: body.item, finalValue: 'Harbour at dawn'})});
        const {root} = mount({openItem: 912023});
        await flush();
        await flush();
        expect(requests.filter((request) => request.url === '/item').map((request) => request.body.item)).toEqual([912023]);
        expect(root.querySelector('[data-aqg-rq-drawer]').hidden).toBe(false);
    });

    it('opens no drawer without an item in the link', async () => {
        mount();
        await flush();
        expect(requests.some((request) => request.url === '/item')).toBe(false);
    });

    it('loads with the deep-link page filter and shows "1–25 of 30" and the open count in the sub-navigation', async () => {
        const {root} = mount();
        await flush();
        expect(requests[0]).toMatchObject({url: '/list', body: {pageUid: 41, sites: ['main'], tab: 'open'}});
        expect(root.querySelector('[data-aqg-rq-pagination]').textContent).toContain('1–25 of 30');
        const nav = root.querySelector('[data-aqg-rq-count="nav"]');
        expect(nav.textContent).toBe('30');
        expect(nav.hidden).toBe(false);
        expect(root.querySelector('[data-aqg-rq-resultcount]').textContent).toBe('30 of 30 shown');
    });

    it('keeps the sub-navigation count hidden without AI or licence', async () => {
        const {root} = mount({showCount: false});
        await flush();
        expect(root.querySelector('[data-aqg-rq-count="nav"]').hidden).toBe(true);
    });

    it('searches after a pause in typing, not on every key', async () => {
        vi.useFakeTimers();
        const {root} = mount();
        await flush();
        const input = root.querySelector('#q');
        input.value = 'wat';
        input.dispatchEvent(new Event('input'));
        input.value = 'water';
        input.dispatchEvent(new Event('input'));
        expect(requests).toHaveLength(1);
        vi.advanceTimersByTime(350);
        await flush();
        expect(requests).toHaveLength(2);
        expect(requests[1].body.query).toBe('water');
        expect(requests[1].body.page).toBe(1);
    });

    it('removes the page chip and widens the list; the site select scopes the request', async () => {
        const {root} = mount();
        await flush();
        root.querySelector('[data-aqg-rq-pagechip-remove]').click();
        await flush();
        expect(root.querySelector('[data-aqg-rq-pagechip]')).toBeNull();
        expect(requests.at(-1).body).toMatchObject({pageUid: 0, sites: ['main', 'second']});
        const site = root.querySelector('[name="site"]');
        site.value = 'main';
        site.dispatchEvent(new Event('change', {bubbles: true}));
        await flush();
        expect(requests.at(-1).body.sites).toEqual(['main']);
    });

    it('shows "No items match these filters" with Clear filters, which resets everything', async () => {
        answers['/list'] = () => ({success: true, items: [], total: 0, page: 1, pages: 1, perPage: 25, counts: {open: 30, mine: 2, done: 4}});
        const {root} = mount();
        await flush();
        expect(root.querySelector('[data-aqg-rq-list]').textContent).toContain('No items match these filters');
        [...root.querySelectorAll('[data-aqg-rq-list] button')].find((button) => button.textContent === 'Clear filters').click();
        await flush();
        expect(requests.at(-1).body).toMatchObject({pageUid: 0, query: '', status: ''});
    });

    it('switches to the change log and fills the person filter from the answer', async () => {
        const {root} = mount();
        await flush();
        root.querySelector('[data-aqg-rq-tab="log"]').click();
        await flush();
        expect(requests.at(-1)).toMatchObject({url: '/changelog', body: {period: '30', person: 0, page: 1}});
        expect(root.querySelector('[data-aqg-rq-queueview]').hidden).toBe(true);
        expect(root.querySelector('[data-aqg-rq-logview]').hidden).toBe(false);
        expect([...root.querySelectorAll('[name="person"] option')].map((option) => option.textContent)).toEqual(['All', 'Eva Editor']);
        expect(root.querySelector('[data-aqg-rq-log]').textContent).toContain('No recorded changes');
    });

    it('renders a conflict as three columns with only the existing steps: regenerate, discard, compare in the record', async () => {
        const conflictItem = item({
            status: 'conflict', statusLabel: 'Conflict', recordUrl: '/record',
            actions: {regenerate: true, reject: true, approve: false},
            conflict: {base: '', current: 'Chart water prices', currentKnown: true, currentDecorative: false, proposed: 'Bar chart'},
        });
        answers['/item'] = () => ({success: true, item: conflictItem});
        const {root, queue} = mount();
        await flush();
        await queue.openItem(1);
        const drawer = root.querySelector('[data-aqg-rq-drawer-panel]');
        expect(drawer.querySelectorAll('.aqg-remediation__threeway-col')).toHaveLength(3);
        const labels = [...drawer.querySelectorAll('.aqg-remediation__actions button, .aqg-remediation__actions a')].map((node) => node.textContent);
        expect(labels).toEqual(['Regenerate from current text', 'Discard suggestion', 'Compare in editor']);
        expect(labels).not.toContain('Approve and apply');
    });
});

describe('empty AI fixes (AQG-300-UX-02/03)', () => {
    const state = (config) => RemediationQueue.prototype.firstUseState.call({config, l});

    it('explains where items come from and opens a page that offers "Fix with AI"', () => {
        const node = state({firstEligible: {url: '/page?id=39', title: 'Plain HTML page', findings: 3}, overviewUrl: '/overview'});
        expect(node.classList.contains('aqg-empty')).toBe(true);
        expect(node.querySelector('.aqg-empty__title').textContent).toBe('No AI fixes prepared yet');
        expect(node.textContent).toContain('“Explain with AI” and suggestions inside the editor do not create items.');
        const link = node.querySelector('.aqg-empty__actions a');
        expect(link.getAttribute('href')).toBe('/page?id=39');
        expect(link.textContent).toBe('Open Plain HTML page');
        expect(node.textContent).toContain(': 3.');
    });

    it('falls back to the Overview and says that nothing can be prepared right now', () => {
        const node = state({firstEligible: null, overviewUrl: '/overview'});
        expect(node.querySelector('.aqg-empty__actions a').getAttribute('href')).toBe('/overview');
        expect(node.textContent).toContain('No open issue on these sites can be prepared with AI right now.');
    });
});
