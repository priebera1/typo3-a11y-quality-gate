// @vitest-environment jsdom
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {hasProposal, resultKind} from '@priebera/a11y-quality-gate/remediation/remediation-filters.js';
import {RemediationQueue} from '@priebera/a11y-quality-gate/remediation/remediation-queue.js';

/**
 * Owner UX pass 3.0.0 (AI fixes list and drawer): a failed preparation is an error, never a suggestion; its reason is
 * shown once; it has no lifecycle ladder and offers the record, not approval. The list has four reading columns.
 */
const IMAGE_MISSING = 'The image file does not exist in a TYPO3 file storage, so AQG cannot look at it. Correct the image path in the record, or write the alternative text yourself.';

const base = (overrides = {}) => ({
    uid: 7, rowVersion: 2, proposalVersion: 1, status: 'pending_review', statusLabel: 'Pending review', statusReason: '', statusReasonLabel: '',
    isDone: false, isSaved: false, isVerified: false, applyMode: 'editor', ruleTitle: 'Image is missing an alt text attribute', severity: 'critical',
    severityLabel: 'Critical', owner: 'editor', ownerLabel: 'Editor', origin: 'editor_request', originLabel: 'Editor request', pageUid: 35,
    pageTitle: 'Problems page', fieldLabel: 'Text (rich text)', targetLabel: 'UX4 fixture: team [900003]', originalValue: 'click here',
    finalValue: 'Opening hours', proposedValue: 'Opening hours', seen: true, permissionLine: 'can_apply', actions: {approve: true, edit: true, reject: true, regenerate: true},
    recordUrl: '/record/edit', pageDetailUrl: '/page-detail', updatedAt: 0, createdAt: 0,
    ...overrides,
});

const failedPreparation = (overrides = {}) => base({
    proposalVersion: 0, status: 'failed', statusLabel: 'Failed', statusReason: 'image_missing', statusReasonLabel: IMAGE_MISSING,
    originalValue: '', finalValue: '', proposedValue: '', actions: {approve: false, edit: false, reject: true, regenerate: true, openInEditor: false},
    ...overrides,
});

describe('resultKind', () => {
    it('separates a real proposal from a failed preparation, a missing suggestion and a waiting item', () => {
        expect(resultKind(base())).toBe('proposal');
        expect(resultKind(failedPreparation())).toBe('prepFailed');
        expect(resultKind(base({status: 'no_suggestion', proposalVersion: 0, finalValue: '', proposedValue: ''}))).toBe('noSuggestion');
        expect(resultKind(base({status: 'preparing', proposalVersion: 0, finalValue: '', proposedValue: ''}))).toBe('preparing');
        expect(resultKind(base({status: 'conflict'}))).toBe('conflict');
        expect(resultKind(base({status: 'rejected', proposalVersion: 0, finalValue: '', proposedValue: ''}))).toBe('closed');
    });

    it('keeps a failed apply as a proposal (the reviewed value still exists and can be retried)', () => {
        expect(resultKind(base({status: 'failed', statusReason: 'write_failed'}))).toBe('proposal');
        expect(hasProposal(failedPreparation())).toBe(false);
    });
});

describe('AI fixes list and drawer', () => {
    let answers;

    const mount = (canBulk = false) => {
        document.body.innerHTML = `
            <div data-aqg-remediation-queue>
                <script type="application/json" data-aqg-remediation-config>${JSON.stringify({
                    sites: ['main'], allSites: ['main'], pageUid: 0, view: 'open', showCount: true, canBulk, locale: 'en-GB',
                    urls: {list: '/list', item: '/item', view: '/view', reject: '/reject', generate: '/generate', changelog: '/changelog'}, labels: {},
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
        answers = {
            '/list': () => ({success: true, items: [failedPreparation(), base({uid: 8})], total: 2, page: 1, pages: 1, perPage: 25, counts: {open: 2, mine: 2, done: 0}}),
            '/item': ({item}) => ({success: true, item: item === 7 ? failedPreparation() : base({uid: 8})}),
        };
        vi.stubGlobal('fetch', vi.fn(async (url, options) => {
            const body = JSON.parse(options.body);
            return {ok: true, status: 200, redirected: false, headers: {get: () => 'application/json'}, json: async () => (answers[url] ?? (() => ({success: true})))(body)};
        }));
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('lists where, issue, proposed change and status, and says the failure reason only once per row', async () => {
        const {root} = mount();
        await flush();
        const headers = [...root.querySelectorAll('thead th')].map((th) => th.textContent);
        expect(headers).toEqual(['Page and record', 'Issue', 'Proposed change', 'Status', 'Actions']);
        const [failedRow, pendingRow] = root.querySelectorAll('tbody tr');
        expect(failedRow.textContent.split(IMAGE_MISSING)).toHaveLength(2);
        expect(failedRow.querySelector('.aqg-remediation__nochange.is-prepFailed').textContent).toBe(IMAGE_MISSING);
        expect(failedRow.querySelector('.aqg-remediation__reason')).toBeNull();
        expect(failedRow.querySelector('.aqg-remediation__col-action button').textContent).toContain('Open');
        expect(pendingRow.querySelector('.aqg-remediation__col-action button').textContent).toContain('Review');
        expect(pendingRow.querySelector('.aqg-remediation__rowmeta').textContent).toContain('Editor request');
    });

    // U-09 (2026-11-05 company acceptance): a bulk-prepared item stayed in "My queue" after its owner lost bulk rights;
    // Approve answered 403 and nothing in the list said why. The row names the reason and what is still possible.
    it('says in the row why an item cannot be acted on', async () => {
        answers['/list'] = () => ({success: true, items: [base({uid: 9, origin: 'bulk', originLabel: 'Bulk', permissionLine: 'bulk_permission_lost', actions: {approve: false, edit: false, reject: true}})], total: 1, page: 1, pages: 1, perPage: 25, counts: {open: 1, mine: 1, done: 0}});
        const {root} = mount();
        await flush();
        const row = root.querySelector('tbody tr');
        expect(row.querySelector('.aqg-remediation__rowblock').textContent).toBe('You prepared this with bulk AI fixes, which your account no longer has. Reject it, or ask someone with bulk access to review it.');
        expect(root.querySelectorAll('tbody tr')[0].querySelector('.aqg-remediation__col-action button').textContent).toContain('Open');
    });

    it('adds no row note to an item the user can act on', async () => {
        const {root} = mount();
        await flush();
        expect(root.querySelector('.aqg-remediation__rowblock')).toBeNull();
    });

    it('shows a failed image preparation as one error with the record as next step, never as a suggestion', async () => {
        const {root, queue} = mount();
        await flush();
        await queue.openItem(7);
        const panel = root.querySelector('[data-aqg-rq-drawer-panel]');
        expect(panel.querySelector('[data-aqg-rq-kind]').getAttribute('data-aqg-rq-kind')).toBe('prepFailed');
        expect(panel.textContent.split(IMAGE_MISSING)).toHaveLength(2);
        expect(panel.querySelector('.aqg-remediation__panel.is-error').textContent).toContain('No suggestion could be prepared');
        expect(panel.querySelector('.aqg-remediation__suggestion')).toBeNull();
        expect(panel.textContent).not.toContain('Proposed change');
        expect(panel.querySelector('.aqg-ladder')).toBeNull();
        expect(panel.textContent).not.toContain('You can apply this change.');
        expect(panel.textContent).not.toContain('The record opens with this change staged');
        const foot = panel.querySelector('.aqg-remediation__drawer-foot');
        const primary = foot.querySelector('.btn-primary');
        expect(primary.tagName).toBe('A');
        expect(primary.textContent).toBe('Open record to fix');
        expect(primary.getAttribute('href')).toBe('/record/edit');
        const labels = [...foot.querySelectorAll('button, a')].map((node) => node.textContent);
        expect(labels).toEqual(['Open record to fix', 'Prepare again', 'Dismiss', 'Page issues']);
        expect(labels).not.toContain('Approve and open in editor');
        expect(labels).not.toContain('Reject');
    });

    it('words the reject step as dismissing an item that has no proposal', async () => {
        const {root, queue} = mount();
        await flush();
        await queue.openItem(7);
        const panel = root.querySelector('[data-aqg-rq-drawer-panel]');
        [...panel.querySelectorAll('button')].find((button) => button.textContent === 'Dismiss').click();
        expect(panel.querySelector('.aqg-remediation__reject h3').textContent).toBe('Dismiss this item');
        expect([...panel.querySelectorAll('.aqg-remediation__reject button')].map((button) => button.textContent)).toEqual(['Cancel', 'Dismiss item']);
    });

    it('keeps the full review flow for a real proposal: ladder, proposed change, approval and the staged-change note', async () => {
        const {root, queue} = mount();
        await flush();
        await queue.openItem(8);
        const panel = root.querySelector('[data-aqg-rq-drawer-panel]');
        expect(panel.querySelector('[data-aqg-rq-kind]').getAttribute('data-aqg-rq-kind')).toBe('proposal');
        expect(panel.querySelector('.aqg-ladder')).not.toBeNull();
        expect(panel.querySelector('.aqg-remediation__suggestion h3').textContent).toBe('Proposed change');
        expect(panel.querySelector('.aqg-remediation__statussection').textContent).toContain('You can apply this change.');
        const foot = panel.querySelector('.aqg-remediation__drawer-foot');
        expect(foot.querySelector('[data-aqg-rq-primary]').textContent).toBe('Approve and open in editor');
        expect([...foot.querySelectorAll('.aqg-remediation__links a')].map((node) => node.textContent)).toEqual(['Open record', 'Page issues']);
        expect(foot.textContent).toContain('The record opens with this change staged');
    });
});
