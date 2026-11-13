// @vitest-environment jsdom
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {countText, formatDateTime, formatNumber, ladderSteps, ladderView, logRow, outcomeTone, permissionLineVisible, resultCountText} from '@priebera/a11y-quality-gate/remediation/remediation-filters.js';
import {RemediationQueue} from '@priebera/a11y-quality-gate/remediation/remediation-queue.js';

/** Atlas correction pass (audit F-11, F-12, F-16, F-22, F-26, F-31, F-33, F-10): the queue's drawer, list and log. */
const l = (key, fallback) => fallback;
const states = (item) => ladderSteps(item).map((step) => `${step.key}:${step.state}${step.variant ? `:${step.variant}` : ''}`);

describe('honest-outcome ladder', () => {
    it('marks Suggested current while a suggestion waits, is approved or is being saved', () => {
        for (const status of ['pending_review', 'approved', 'applying', 'conflict', 'failed']) {
            expect(states({status, applyMode: 'server', proposalVersion: 1})).toEqual(['pending_review:current', 'saved:todo', 'verified:todo']);
        }
    });

    it('has no reached step before a suggestion exists', () => {
        expect(states({status: 'preparing', applyMode: 'server', proposalVersion: 0})).toEqual(['pending_review:todo', 'saved:todo', 'verified:todo']);
    });

    it('distinguishes saved, verified and not verified', () => {
        expect(states({status: 'saved', applyMode: 'server', proposalVersion: 1})).toEqual(['pending_review:done', 'saved:current', 'verified:todo']);
        expect(states({status: 'verified', applyMode: 'server', proposalVersion: 1})).toEqual(['pending_review:done', 'saved:done', 'verified:current:verified']);
        expect(states({status: 'not_verified', applyMode: 'server', proposalVersion: 1})).toEqual(['pending_review:done', 'saved:done', 'not_verified:current:notverified']);
    });

    it('adds the editor step for rich text and shows no ladder for rejected items', () => {
        expect(states({status: 'in_editor', applyMode: 'editor', proposalVersion: 1})).toEqual(['pending_review:done', 'in_editor:current', 'saved:todo', 'verified:todo']);
        expect(ladderSteps({status: 'rejected', applyMode: 'server'})).toBeNull();
    });

    it('renders the shared .aqg-ladder markup with done/current classes and no glyphs', () => {
        const node = ladderView({status: 'verified', applyMode: 'server', proposalVersion: 1}, l);
        expect(node.className).toBe('aqg-ladder');
        const items = [...node.querySelectorAll('li')];
        expect(items.map((li) => li.className)).toEqual(['is-done', 'is-done', 'is-current is-verified']);
        expect(items[2].getAttribute('aria-current')).toBe('step');
        expect(node.textContent).toBe('SuggestedSavedVerified');
        expect(node.querySelectorAll('.aqg-ladder__dot[aria-hidden="true"]')).toHaveLength(3);
    });
});

describe('outcome chips and state copy', () => {
    it('never gives AI-generated, approved, saved and verified the same tone', () => {
        const tones = ['pending_review', 'approved', 'saved', 'verified'].map(outcomeTone);
        expect(new Set(tones).size).toBe(4);
        expect(outcomeTone('not_verified')).toBe('notverified');
    });

    it('drops "You can apply this change." once nothing is left to apply', () => {
        const base = {permissionLine: 'can_apply', actions: {approve: true, edit: true}, isDone: false, isSaved: false};
        expect(permissionLineVisible(base)).toBe(true);
        expect(permissionLineVisible({...base, status: 'verified', isDone: true, isSaved: true, actions: {}})).toBe(false);
        expect(permissionLineVisible({...base, status: 'saved', isSaved: true, actions: {verify: true}})).toBe(false);
        expect(permissionLineVisible({...base, permissionLine: 'no_edit_access', actions: {}})).toBe(true);
    });
});

describe('backend locale and plurals', () => {
    it('formats dates in the backend language, not the browser locale', () => {
        const timestamp = Date.UTC(2026, 10, 2, 18, 39) / 1000;
        const german = formatDateTime(timestamp, 'de-DE');
        expect(german).toMatch(/^\d{2}\.11\.2026, \d{2}:39$/);
        expect(formatDateTime(timestamp, 'en-GB')).toMatch(/^2 Nov 2026, \d{2}:39$/);
        expect(formatDateTime(0, 'de-DE')).toBe('');
        expect(formatNumber(1332, 'de-DE')).toBe('1.332');
    });

    it('says "1 item" and "0 items"', () => {
        const labels = {resultCount: '%d items', resultCountOne: '%d item', resultCountOf: '%1$d of %2$d shown'};
        expect(resultCountText(labels, 1, 1, false)).toBe('1 item');
        expect(resultCountText(labels, 0, 0, false)).toBe('0 items');
        expect(resultCountText({resultCount: '%d Einträge', resultCountOne: '%d Eintrag'}, 1, 1, false, 'de-DE')).toBe('1 Eintrag');
        expect(countText(2, '%d entry', '%d entries')).toBe('2 entries');
    });
});

describe('change log row', () => {
    const entry = (overrides = {}) => ({
        at: 1760000000, by: 'Eva Editor', action: 'approved', actionLabel: 'Approved', fromLabel: 'Pending review', toLabel: 'Saving…',
        valueHash: '9c41…07ad', pageUid: 41, pageTitle: 'Water tariffs', ruleTitle: 'Image file reference is missing alt text', fieldLabel: 'Image',
        model: 'claude-haiku-5-5', modelLabel: 'Claude Haiku 5.5', providerLabel: 'Anthropic Claude', originLabel: 'Editor request', outcome: '', outcomeLabel: '',
        ...overrides,
    });

    it('leaves the outcome empty for a step that reached none, and shows the event outcome as a chip', () => {
        expect(logRow(entry(), l).querySelectorAll('td')[5].textContent).toBe('');
        const verified = logRow(entry({outcome: 'verified', outcomeLabel: 'Verified'}), l).querySelectorAll('td')[5];
        expect(verified.querySelector('.aqg-outcome--verified').textContent).toBe('Verified');
    });

    it('keeps the value hash in a closed disclosure and names the model by its display name', () => {
        const cells = logRow(entry(), l).querySelectorAll('td');
        const details = cells[3].querySelector('details');
        expect(details.open).toBe(false);
        expect(details.textContent).toContain('Value hash 9c41…07ad');
        expect(cells[4].textContent).toContain('Anthropic Claude · Claude Haiku 5.5');
        expect(cells[4].textContent).not.toContain('claude-haiku-5-5');
    });
});

describe('drawer', () => {
    let requests;
    let answers;

    const item = (overrides = {}) => ({
        uid: 1, rowVersion: 3, proposalVersion: 1, status: 'pending_review', statusLabel: 'Pending review', isDone: false, isSaved: false, isVerified: false,
        applyMode: 'server', ruleTitle: 'Image file reference is missing alt text', severity: 'critical', severityLabel: 'Critical', owner: 'editor', ownerLabel: 'Editor',
        origin: 'editor_request', originLabel: 'Editor request', pageUid: 3, pageTitle: 'Problems page', fieldLabel: 'Image', targetLabel: 'photo.png',
        originalValue: '', finalValue: 'QA mock alternative', seen: true, permissionLine: 'can_apply', provider: 'anthropic', providerLabel: 'Anthropic Claude',
        model: 'claude-haiku-5-5', modelLabel: 'Claude Haiku 5.5', actions: {approve: true, edit: true, reject: true}, updatedAt: 0, createdAt: 1762108740,
        ...overrides,
    });

    const mount = () => {
        document.body.innerHTML = `
            <div data-aqg-remediation-queue>
                <script type="application/json" data-aqg-remediation-config>${JSON.stringify({
                    sites: ['main'], allSites: ['main'], pageUid: 0, view: 'open', showCount: true, canBulk: false, locale: 'de-DE',
                    urls: {list: '/list', item: '/item', view: '/view', edit: '/edit', approve: '/approve', changelog: '/changelog'},
                    labels: {},
                })}</script>
                <h1>AI fixes</h1>
                <p data-aqg-rq-resultcount></p>
                <div data-aqg-rq-queueview><form data-aqg-rq-filters></form><div data-aqg-rq-list></div><nav data-aqg-rq-pagination hidden></nav></div>
                <div data-aqg-rq-drawer hidden><div data-aqg-rq-drawer-panel></div></div>
                <div data-aqg-rq-live></div>
            </div>`;
        const root = document.querySelector('[data-aqg-remediation-queue]');
        const queue = new RemediationQueue(root);
        queue.init();
        return {root, queue};
    };

    const flush = async () => {
        for (let i = 0; i < 8; i++) {
            await Promise.resolve();
        }
    };

    beforeEach(() => {
        requests = [];
        answers = {
            '/list': () => ({success: true, items: [item()], total: 1, page: 1, pages: 1, perPage: 25, counts: {open: 1, mine: 1, done: 0}}),
            '/item': () => ({success: true, item: item()}),
        };
        vi.stubGlobal('fetch', vi.fn(async (url, options) => {
            const body = JSON.parse(options.body);
            requests.push({url, body});
            return {ok: true, status: 200, redirected: false, headers: {get: () => 'application/json'}, json: async () => (answers[url] ?? (() => ({success: true})))(body)};
        }));
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('shows the rule title, the model display name, an editable After value and a sticky foot with the actions', async () => {
        const {root, queue} = mount();
        await flush();
        expect(root.querySelector('[data-aqg-rq-resultcount]').textContent).toBe('1 item');
        await queue.openItem(1);
        const panel = root.querySelector('[data-aqg-rq-drawer-panel]');
        expect(panel.querySelector('#aqg-rq-drawer-title').textContent).toBe('Image file reference is missing alt text');
        expect(panel.querySelector('.aqg-remediation__model').textContent).toBe('Anthropic Claude · Claude Haiku 5.5');
        const textarea = panel.querySelector('textarea[data-aqg-rq-value]');
        expect(textarea.value).toBe('QA mock alternative');
        expect(panel.querySelector(`label[for="${textarea.id}"]`).textContent).toBe('After');
        expect([...panel.querySelectorAll('button')].map((button) => button.textContent)).not.toContain('Edit value');
        expect(panel.querySelector('.aqg-remediation__drawer-foot .aqg-remediation__actions button').textContent).toBe('Approve and apply');
        expect(panel.querySelector('.aqg-remediation__drawer-scroll .aqg-remediation__actions')).toBeNull();
        expect(panel.querySelector('.aqg-ladder')).not.toBeNull();
        expect(panel.textContent).toContain('You can apply this change.');
    });

    it('saves a changed After value as the person\'s edit before applying it, with the version the edit returned', async () => {
        answers['/edit'] = ({value}) => ({success: true, item: item({rowVersion: 4, proposalVersion: 2, finalValue: value, isEdited: true})});
        answers['/approve'] = () => ({success: true, item: item({status: 'verified', isDone: true, isSaved: true, isVerified: true, actions: {}})});
        const {root, queue} = mount();
        await flush();
        await queue.openItem(1);
        const panel = root.querySelector('[data-aqg-rq-drawer-panel]');
        const textarea = panel.querySelector('textarea[data-aqg-rq-value]');
        textarea.value = 'Bar chart of water prices';
        textarea.dispatchEvent(new Event('input'));
        panel.querySelector('[data-aqg-rq-primary]').click();
        await flush();
        const order = requests.map((request) => request.url).filter((url) => ['/edit', '/approve'].includes(url));
        expect(order).toEqual(['/edit', '/approve']);
        expect(requests.find((request) => request.url === '/edit').body).toMatchObject({item: 1, rowVersion: 3, value: 'Bar chart of water prices'});
        expect(requests.find((request) => request.url === '/approve').body).toMatchObject({item: 1, rowVersion: 4});
    });

    it('shows a read-only After value where Approve is not offered, so no edit can be typed and lost', async () => {
        // Independent review of the Atlas pass: an image use in a workspace may be edited but not approved here.
        answers['/item'] = () => ({success: true, item: item({actions: {edit: true, approve: false, reject: true}})});
        const {root, queue} = mount();
        await flush();
        await queue.openItem(1);
        const panel = root.querySelector('[data-aqg-rq-drawer-panel]');
        expect(panel.querySelector('textarea[data-aqg-rq-value]')).toBeNull();
        expect(panel.querySelector('.aqg-remediation__diff-after').textContent).toBe('QA mock alternative');
    });

    it('asks before a changed After value is discarded by closing the drawer', async () => {
        const {root, queue} = mount();
        await flush();
        await queue.openItem(1);
        const panel = root.querySelector('[data-aqg-rq-drawer-panel]');
        const drawer = root.querySelector('[data-aqg-rq-drawer]');
        const textarea = panel.querySelector('textarea[data-aqg-rq-value]');
        textarea.value = 'Changed';
        textarea.dispatchEvent(new Event('input'));
        const confirm = vi.fn(() => false);
        vi.stubGlobal('confirm', confirm);
        queue.closeDrawer();
        expect(confirm).toHaveBeenCalledTimes(1);
        expect(drawer.hidden).toBe(false);
        confirm.mockReturnValue(true);
        queue.closeDrawer();
        expect(drawer.hidden).toBe(true);
    });

    it('does not apply when the server refuses the edited value', async () => {
        answers['/edit'] = () => ({success: false, code: 'invalid_value'});
        const {root, queue} = mount();
        await flush();
        await queue.openItem(1);
        const panel = root.querySelector('[data-aqg-rq-drawer-panel]');
        const textarea = panel.querySelector('textarea[data-aqg-rq-value]');
        textarea.value = 'x'.repeat(5);
        textarea.dispatchEvent(new Event('input'));
        panel.querySelector('[data-aqg-rq-primary]').click();
        await flush();
        expect(requests.some((request) => request.url === '/approve')).toBe(false);
        expect(textarea.getAttribute('aria-invalid')).toBe('true');
    });

    it('after verification: ladder at Verified, no "You can apply", German dates in the provenance', async () => {
        answers['/item'] = () => ({success: true, item: item({status: 'verified', statusLabel: 'Verified', isDone: true, isSaved: true, isVerified: true, actions: {}, reviewedBy: 'claude-qa', reviewedAt: Date.UTC(2026, 10, 2, 18, 39) / 1000})});
        const {root, queue} = mount();
        await flush();
        await queue.openItem(1);
        const panel = root.querySelector('[data-aqg-rq-drawer-panel]');
        expect(panel.textContent).not.toContain('You can apply this change.');
        expect(panel.querySelector('.aqg-ladder li.is-current.is-verified').textContent).toBe('Verified');
        expect(panel.querySelector('textarea')).toBeNull();
        expect(panel.querySelector('.aqg-remediation__provenance').textContent).toMatch(/claude-qa · \d{2}\.11\.2026, \d{2}:39/);
        expect(panel.querySelector('.aqg-remediation__provenance').textContent).toContain('Model ID');
    });

    it('returns focus to the row while it exists, else to the result count — never to the body', async () => {
        const {root, queue} = mount();
        await flush();
        root.querySelector('[data-aqg-rq-open="1"]').click();
        await flush();
        queue.onDrawerKey({key: 'Escape', preventDefault() {}});
        expect(document.activeElement).toBe(root.querySelector('[data-aqg-rq-open="1"]'));

        await queue.openItem(1);
        // The item left this tab (applied): the next list no longer holds it.
        queue.items = [];
        queue.meta = {total: 0, page: 1, pages: 1, perPage: 25};
        queue.onDrawerKey({key: 'Escape', preventDefault() {}});
        const count = root.querySelector('[data-aqg-rq-resultcount]');
        expect(document.activeElement).toBe(count);
        expect(count.getAttribute('tabindex')).toBe('-1');
        expect(document.activeElement).not.toBe(document.body);
    });
});

describe('bulk copy', () => {
    it('uses the singular for one change and one image text', () => {
        expect(countText(1, 'Review %d change…', 'Review %d changes…')).toBe('Review 1 change…');
        expect(countText(3, 'Review %d image text', 'Review %d image texts')).toBe('Review 3 image texts');
    });
});
