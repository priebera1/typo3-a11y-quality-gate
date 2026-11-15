// @vitest-environment jsdom

import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {ToolbarAssistant, planTurn} from '../../Resources/Public/JavaScript/toolbar/a11y-toolbar-assistant.js';

/**
 * Ask AQG 3.0.0 as a conversation: numbered finding cards, "Fix problem N" through the signed list of the conversation,
 * one conversation shared by the toolbar and the expanded dialog (the same DOM is moved, never copied), guidance for
 * manual work, Before/After with AI Fixes' own approve, and "New conversation" discarding late answers.
 */
describe('Ask AQG plans and expanded view', () => {
    let fetchMock;
    const limits = JSON.stringify({maxQuestionChars: 400, minQuestionChars: 3, maxHistoryTurns: 3, maxHistoryAnswerChars: 700, maxHistoryChars: 2400, maxSecondsPerQuestion: 45});
    const principal = 'a1'.repeat(20);
    const scope = 'c3'.repeat(20);
    const otherScope = 'd4'.repeat(20);
    const selection = 's1.page.1000.501-77.' + 'f'.repeat(64);
    const html = (viewScope = scope) => `<section data-aqt-assistant-root="true" data-label-expand="Expand" data-label-collapse="Back to toolbar" data-label-cleared="Cleared">
        <h2 id="aqt-assistant-title">Ask AQG</h2><button type="button" data-aqt-expand="true">Expand</button>
        <section data-aqt-ask="true" data-ai="1" data-limits='${limits}' data-principal="${principal}" data-scope="${viewScope}">
            <div data-aqt-ask-log="true" role="log" hidden></div>
            <button type="button" data-aqt-ask-intent="list">What problems does this page have?</button>
            <form data-aqt-ask-form="true">
                <input type="hidden" name="aqt-ask-scope" value="page">
                <textarea data-aqt-ask-input="true"></textarea><button type="submit" data-aqt-ask-submit="true">Ask</button>
                <button type="button" data-aqt-ask-clear="true" hidden>New conversation</button>
                <p data-aqt-ask-usage="true" hidden></p>
                <p data-aqt-ask-status="true" role="status"></p>
            </form>
        </section>
        <p data-aqt-live="true"></p>
    </section>`;
    const json = (status, body) => ({ok: status < 400, status, redirected: false, headers: {get: () => 'application/json'}, json: async () => body});
    const urls = {
        a11y_toolbar_assistant: '/typo3/ajax/a11y/toolbar/assistant',
        a11y_toolbar_ask: '/typo3/ajax/a11y/toolbar/ask?token=t',
        a11y_toolbar_repair_prepare: '/typo3/ajax/a11y/toolbar/repair/prepare?token=p',
        a11y_remediation_generate: '/typo3/ajax/a11y/remediation/generate?token=g',
        a11y_remediation_view: '/typo3/ajax/a11y/remediation/view?token=v',
        a11y_remediation_approve: '/typo3/ajax/a11y/remediation/approve?token=a',
    };
    const item = (id, number, extra = {}) => ({id, number, key: `k${id}`, ruleId: 'rte.non_descriptive_link', title: `Problem title ${number}`, tone: 'warning', severityLabel: 'Warning', location: 'Block · Text', kind: 'link_text', state: 'ready', stateLabel: 'Can be prepared', reason: 'Found in the saved content just now.', itemUid: 0, reviewUrl: '', cost: '<$0.02', detailUrl: '/typo3/module/web/a11y/page?pageUid=40', editUrl: '/typo3/record/edit?edit[tt_content][90]=edit', source: 'content', guidance: null, ...extra});
    const plan = (extra = {}) => ({
        success: true, status: 'answer', source: 'aqg', intent: 'repair', scope: 'page', question: 'What problems does this page have?',
        answer: '2 problems on this page.', citations: [], notes: [], sent: [], conversation: {id: 'v2.conversation'},
        repair: {
            mode: 'list', clarify: '', single: false, target: 'page', targetLabel: 'Page: Kontakt', notices: [], alternatives: [],
            counts: {found: 2, ready: 1, manual: 1}, headline: '2 problems on this page, most severe first.',
            freshness: {text: 'Based on the content check of today.', stale: false, never: false, canCheck: false, pageUid: 40, languageUid: 0},
            ai: {ready: true, state: 'ready', licence: true, reason: ''}, bulk: true, cost: {total: '<$0.02', unknown: false, requests: 1},
            items: [item(501, 1), item(77, 2, {state: 'manual', stateLabel: 'Needs manual work', ruleId: 'rte.heading_hierarchy_jump', title: 'Heading levels are not sequential', guidance: {
                problem: 'The heading “Details” is an H5, but the heading before it in this text, “Overview”, is an H2.',
                where: 'Block · Text', recommendation: 'Change “Details” from H5 to H3, one level below the H2 before it.', action: 'Open the text in the editor.',
                missing: 'AQG checked this text field on its own.', verify: 'After you save, choose “Recheck saved content”.',
                outline: [{level: 2, text: 'Overview', flagged: false}, {level: 5, text: 'Details', flagged: true}],
            }})],
            prepareIds: [501], queueUrl: '/typo3/module/web/a11y/remediation?id=40&pageUid=40', editUrl: '', selection,
            ...extra,
        },
    });

    beforeEach(() => {
        document.body.innerHTML = '<div id="priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem"><button class="dropdown-toggle">AQG</button><div class="aqt-pop"><div id="slot" data-aqt-assistant></div></div></div>';
        fetchMock = vi.fn();
        globalThis.fetch = fetchMock;
        window.sessionStorage.clear();
        // jsdom has no modal dialog behaviour: emulate open/close the way the browser does (close fires "close").
        if (!HTMLDialogElement.prototype.showModal) {
            HTMLDialogElement.prototype.showModal = function showModal() { this.setAttribute('open', ''); };
        }
        if (!HTMLDialogElement.prototype.close || !('open' in HTMLDialogElement.prototype)) {
            HTMLDialogElement.prototype.close = function close() { this.removeAttribute('open'); this.dispatchEvent(new Event('close')); };
        }
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    const loaded = async (viewScope = scope) => {
        fetchMock.mockResolvedValueOnce({ok: true, text: async () => html(viewScope)});
        const navigate = vi.fn();
        const instance = new ToolbarAssistant({win: window, slotSelector: '#slot', ajaxUrls: () => urls, navigate});
        await instance.load();
        instance.navigateMock = navigate;
        return instance;
    };
    const ask = async (question, answer) => {
        const before = document.querySelectorAll('[data-aqt-repair]').length;
        fetchMock.mockResolvedValueOnce(json(200, answer));
        document.querySelector('[data-aqt-ask-input]').value = question;
        document.querySelector('[data-aqt-ask-form]').dispatchEvent(new Event('submit', {cancelable: true}));
        await vi.waitFor(() => expect(document.querySelectorAll('[data-aqt-repair]').length).toBeGreaterThan(before));
        return [...document.querySelectorAll('[data-aqt-repair]')].pop();
    };
    const lastBody = () => JSON.parse(fetchMock.mock.calls.at(-1)[1].body);

    it('shows numbered cards and sends the signed list with "Fix problem 1"', async () => {
        await loaded();
        const list = await ask('What problems does this page have?', plan());
        expect([...list.querySelectorAll('[data-aqt-number]')].map((card) => card.dataset.aqtNumber)).toEqual(['1', '2']);
        expect(list.querySelector('.aqt-card__num').textContent).toBe('1');
        expect(list.querySelector('[data-aqt-repair-item="501"] [data-aqt-repair-prepare="501"]')).not.toBeNull();
        const stored = JSON.parse(window.sessionStorage.getItem('aqg-toolbar-ask:' + scope));
        expect([stored.conversation, stored.selection]).toEqual(['v2.conversation', selection]);

        await ask('Fix problem 1', plan({mode: 'repair', single: true, items: [item(501, 1)]}));
        const body = lastBody();
        expect(body.selection).toBe(selection);
        expect(body.conversation).toBe('v2.conversation');
        expect(body.question).toBe('Fix problem 1');
    });

    it('gives every card exactly one main action chosen by its state, and a heading for its title', async () => {
        await loaded();
        const list = await ask('What problems does this page have?', plan());
        const ready = list.querySelector('[data-aqt-repair-item="501"]');
        const manual = list.querySelector('[data-aqt-repair-item="77"]');
        // Ready for AI: Prepare fix leads (even with several ready cards); the editor and Details step back.
        expect([...ready.querySelectorAll('.aqt-btn--primary')].map((node) => node.dataset.aqtRepairPrepare)).toEqual(['501']);
        expect(ready.querySelector('a[href*="record/edit"]').classList.contains('aqt-btn--secondary')).toBe(true);
        // Manual work: the editor is the next step, so it becomes the one primary action.
        const manualPrimary = [...manual.querySelectorAll('.aqt-btn--primary')];
        expect(manualPrimary).toHaveLength(1);
        expect(manualPrimary[0].getAttribute('href')).toContain('record/edit');
        // Titles are headings below the answer's h3, so heading navigation reaches every problem.
        expect(ready.querySelector('.aqt-card__title').tagName).toBe('H4');
        // The estimate describes the prepare step instead of standing next to the card's state.
        const prepare = ready.querySelector('[data-aqt-repair-prepare="501"]');
        expect(document.getElementById(prepare.getAttribute('aria-describedby')).textContent).toContain('<$0.02');
        expect(ready.querySelector('.aqt-card__status').textContent).not.toContain('<$0.02');
    });

    it('marks only a verified item green on the outcome ladder', () => {
        const assistant = new ToolbarAssistant({win: window, slotSelector: '#slot', ajaxUrls: () => urls});
        const ladder = (status) => {
            const steps = assistant.stepper(document.body, status, 'editor');
            return {current: steps.querySelector('[aria-current="step"]')?.dataset.step ?? null, done: [...steps.querySelectorAll('.aqt-steps__step--done')].map((step) => step.dataset.step), verified: steps.querySelector('.aqt-steps__step--verified')?.dataset.step ?? null, notVerified: steps.classList.contains('aqt-steps--not-verified')};
        };
        expect(ladder('no_suggestion')).toEqual({current: null, done: [], verified: null, notVerified: false});
        expect(ladder('pending_review')).toEqual({current: 'suggested', done: [], verified: null, notVerified: false});
        expect(ladder('in_editor')).toEqual({current: 'reviewed', done: ['suggested'], verified: null, notVerified: false});
        expect(ladder('saved')).toEqual({current: 'applied', done: ['suggested', 'reviewed'], verified: null, notVerified: false});
        expect(ladder('not_verified')).toEqual({current: 'applied', done: ['suggested', 'reviewed'], verified: null, notVerified: true});
        expect(ladder('verified')).toEqual({current: 'verified', done: ['suggested', 'reviewed', 'applied'], verified: 'verified', notVerified: false});
    });

    it('turns manual work into concrete guidance with the saved heading outline', async () => {
        await loaded();
        const list = await ask('What problems does this page have?', plan());
        const manual = list.querySelector('[data-aqt-repair-item="77"]');
        const guide = manual.querySelector('[data-aqt-guidance]');
        expect(guide.open).toBe(true);
        expect(guide.textContent).toContain('Change “Details” from H5 to H3');
        expect(guide.textContent).toContain('What AQG cannot tell');
        expect(guide.querySelector('.aqt-guide__h--flagged').textContent).toContain('Details');
        expect(manual.querySelector('[data-aqt-repair-prepare]')).toBeNull();
    });

    it('moves the one conversation into the dialog and back, without a second request', async () => {
        const instance = await loaded();
        await ask('What problems does this page have?', plan());
        const root = document.querySelector('[data-aqt-assistant-root]');
        const log = root.querySelector('[data-aqt-ask-log]');
        const calls = fetchMock.mock.calls.length;

        root.querySelector('[data-aqt-expand]').click();
        const dialog = document.querySelector('dialog[data-aqt-dialog]');
        expect(dialog.hasAttribute('open')).toBe(true);
        expect(dialog.contains(root)).toBe(true);
        expect(dialog.querySelector('[data-aqt-ask-log]')).toBe(log);
        expect(root.querySelector('[data-aqt-expand]').getAttribute('aria-expanded')).toBe('true');
        expect(instance.navigateMock).toHaveBeenCalledWith(null);

        // A question asked in the dialog lands in the same log.
        await ask('Fix problem 1', plan({mode: 'repair', single: true, items: [item(501, 1)]}));
        expect(log.querySelectorAll('[data-aqt-repair]')).toHaveLength(2);

        instance.collapse();
        expect(dialog.hasAttribute('open')).toBe(false);
        expect(document.querySelector('#slot').contains(root)).toBe(true);
        expect(document.activeElement).toBe(document.querySelector('.dropdown-toggle'));
        expect(fetchMock.mock.calls.length).toBe(calls + 1);

        // Opening the toolbar again for the same context keeps the live conversation node (no rebuild, no request).
        fetchMock.mockResolvedValueOnce({ok: true, text: async () => html()});
        await instance.load();
        expect(document.querySelector('[data-aqt-ask-log]')).toBe(log);
        expect(log.querySelectorAll('[data-aqt-repair]')).toHaveLength(2);
    });

    it('never carries a live plan into another context', async () => {
        const instance = await loaded();
        await ask('What problems does this page have?', plan());
        fetchMock.mockResolvedValueOnce({ok: true, text: async () => html(otherScope)});
        await instance.load();
        expect(document.querySelectorAll('[data-aqt-repair]')).toHaveLength(0);
        expect(window.sessionStorage.getItem('aqg-toolbar-ask:' + otherScope)).toBeNull();
    });

    it('drops an answer that arrives after "New conversation"', async () => {
        await loaded();
        let resolve;
        fetchMock.mockReturnValueOnce(new Promise((done) => { resolve = done; }));
        document.querySelector('[data-aqt-ask-input]').value = 'What problems does this page have?';
        document.querySelector('[data-aqt-ask-form]').dispatchEvent(new Event('submit', {cancelable: true}));
        document.querySelector('[data-aqt-ask-clear]').hidden = false;
        document.querySelector('[data-aqt-ask-clear]').click();
        resolve(json(200, plan()));
        await new Promise((done) => setTimeout(done, 20));
        expect(document.querySelectorAll('[data-aqt-repair]')).toHaveLength(0);
        expect(JSON.parse(window.sessionStorage.getItem('aqg-toolbar-ask:' + scope))).toEqual({conversation: '', turns: [], selection: ''});
    });

    it('shows Before/After and approves through AI Fixes with the returned row version', async () => {
        await loaded();
        const list = await ask('Fix problem 1', plan({mode: 'repair', single: true, items: [item(501, 1)]}));
        fetchMock
            .mockResolvedValueOnce(json(200, {success: true, status: 'prepared', items: [{id: 501, outcome: 'queued', itemUid: 9, rowVersion: 1, status: 'preparing', generate: true, reviewUrl: '/typo3/module/web/a11y/remediation?item=9'}]}))
            .mockResolvedValueOnce(json(200, {success: true, item: {uid: 9, status: 'pending_review', statusLabel: 'Pending review', proposalVersion: 1, rowVersion: 2, applyMode: 'server', kind: 'rich_text', editorAlternative: true, fieldLabel: 'Text (rich text)', targetLabel: 'Link 1 of 1', originalValue: 'click here', finalValue: 'Download the price sheet (PDF)', actions: {approve: true}}}));
        list.querySelector('[data-aqt-repair-prepare="501"]').click();
        await vi.waitFor(() => expect(list.querySelector('.aqt-diff')).not.toBeNull());
        expect(list.querySelector('.aqt-diff__side--before').textContent).toContain('click here');
        expect(list.querySelector('.aqt-diff__side--after').textContent).toContain('Download the price sheet (PDF)');
        // The ladder names the state reached (Suggested), never the next verb; nothing is approved yet.
        expect(list.querySelector('[aria-current="step"]').dataset.step).toBe('suggested');
        expect(list.querySelector('.aqt-steps__step--done')).toBeNull();
        // One main action on the card: Approve. Prepare fix is gone, every other button steps back.
        const card = list.querySelector('[data-aqt-repair-item="501"]');
        expect([...card.querySelectorAll('.aqt-btn--primary')].map((button) => button.dataset.aqtApprove)).toEqual(['9']);
        expect(card.querySelector('[data-aqt-repair-prepare]')).toBeNull();
        // The same contract as AI fixes and Page Detail: what changes, the write note, Apply and verify, the editor alternative.
        expect(card.querySelector('[data-aqt-approve="9"]').textContent).toBe('Apply and verify');
        expect(card.querySelector('[data-aqt-review-target]').textContent).toBe('Changes: Text (rich text) · Link 1 of 1');
        expect(card.querySelector('[data-aqt-write-note]')).not.toBeNull();
        expect(card.querySelector('[data-aqt-approve-editor="9"]').textContent).toBe('Apply in the editor instead');

        fetchMock
            .mockResolvedValueOnce(json(200, {success: true, item: {uid: 9, status: 'pending_review', rowVersion: 3, applyMode: 'server', kind: 'rich_text'}}))
            .mockResolvedValueOnce(json(200, {success: true, item: {uid: 9, status: 'approved', statusLabel: 'Approved', applyMode: 'editor', editUrl: '/typo3/record/edit?edit[tt_content][90]=edit&aqgRemediation=9'}}));
        list.querySelector('[data-aqt-approve-editor="9"]').click();
        await vi.waitFor(() => expect(list.querySelector('[data-aqt-card-outcome] a.aqt-btn--primary')).not.toBeNull());
        const [viewCall, approveCall] = fetchMock.mock.calls.slice(-2);
        expect(viewCall[0]).toBe(urls.a11y_remediation_view);
        expect(JSON.parse(viewCall[1].body)).toEqual({item: 9, proposalVersion: 1, detailed: false});
        expect(approveCall[0]).toBe(urls.a11y_remediation_approve);
        // "Apply in the editor instead" (clicked above) sends no apply mode: approved for the editor.
        expect(JSON.parse(approveCall[1].body)).toEqual({item: 9, rowVersion: 3, bulk: false, detailed: false});
        expect(list.querySelector('[data-aqt-card-outcome] a.aqt-btn--primary').getAttribute('href')).toContain('aqgRemediation=9');
        // Approved is not fixed: the ladder stops at Approved (not saved, not verified) and the editor is the next step.
        expect(list.querySelector('[aria-current="step"]').dataset.step).toBe('reviewed');
        expect(list.querySelector('.aqt-steps__step--verified')).toBeNull();
    });

    it('applies a reviewed rich-text change on the server with Apply and verify, as AI Fixes and Page Detail do', async () => {
        await loaded();
        const list = await ask('Fix problem 1', plan({mode: 'repair', single: true, items: [item(501, 1)]}));
        fetchMock
            .mockResolvedValueOnce(json(200, {success: true, status: 'prepared', items: [{id: 501, outcome: 'queued', itemUid: 9, rowVersion: 1, status: 'preparing', generate: true, reviewUrl: '/typo3/module/web/a11y/remediation?item=9'}]}))
            .mockResolvedValueOnce(json(200, {success: true, item: {uid: 9, status: 'pending_review', statusLabel: 'Pending review', proposalVersion: 1, rowVersion: 2, applyMode: 'server', kind: 'rich_text', editorAlternative: true, originalValue: 'Click here', finalValue: 'Visit the Priebera company website', actions: {approve: true}}}));
        list.querySelector('[data-aqt-repair-prepare="501"]').click();
        await vi.waitFor(() => expect(list.querySelector('[data-aqt-approve="9"]')).not.toBeNull());
        fetchMock
            .mockResolvedValueOnce(json(200, {success: true, item: {uid: 9, status: 'pending_review', rowVersion: 3, applyMode: 'server', kind: 'rich_text'}}))
            .mockResolvedValueOnce(json(200, {success: true, item: {uid: 9, status: 'verified', statusLabel: 'Verified', applyMode: 'server', kind: 'rich_text'}}));
        list.querySelector('[data-aqt-approve="9"]').click();
        await vi.waitFor(() => expect(list.querySelector('[aria-current="step"]')?.dataset.step).toBe('verified'));
        const approveCall = fetchMock.mock.calls.at(-1);
        expect(approveCall[0]).toBe(urls.a11y_remediation_approve);
        expect(JSON.parse(approveCall[1].body)).toEqual({item: 9, rowVersion: 3, bulk: false, detailed: false, mode: 'apply'});
    });

    it('stores a plan as a record that must be refreshed before acting on it', () => {
        const stored = planTurn(plan());
        expect(stored.source).toBe('aqg');
        expect(stored.repair.selection).toBe(selection, 'Its own list, so a refresh resolves the same numbers.');
        expect(stored.repair.items).toHaveLength(2);
    });
    it('refreshes a stored plan against its own list, never a newer one', async () => {
        await loaded();
        const own = 's1.page.1000.77-501.' + 'a'.repeat(64);
        const newer = 's1.page.2000.9-8.' + 'b'.repeat(64);
        window.sessionStorage.setItem('aqg-toolbar-ask:' + scope, JSON.stringify({conversation: 'v2.conversation', selection: newer,
            turns: [planTurn(plan({mode: 'repair', single: true, items: [item(501, 2)], selection: own}))]}));
        fetchMock.mockResolvedValueOnce({ok: true, text: async () => html()});
        const instance = new ToolbarAssistant({win: window, slotSelector: '#slot', ajaxUrls: () => urls});
        await instance.load();
        fetchMock.mockResolvedValueOnce(json(200, plan({mode: 'repair', single: true, items: [item(501, 2)], selection: own})));
        document.querySelector('[data-aqt-repair-refresh]').click();
        await vi.waitFor(() => expect(document.querySelector('.aqt-plan--stored')).toBeNull());
        const body = lastBody();
        expect(body.selection).toBe(own);
        expect(body.pick).toEqual([2]);
    });

    it('shows an answer in the live conversation even when the dropdown re-rendered meanwhile', async () => {
        const instance = await loaded();
        let resolve;
        fetchMock.mockReturnValueOnce(new Promise((done) => { resolve = done; }));
        document.querySelector('[data-aqt-ask-input]').value = 'What problems does this page have?';
        document.querySelector('[data-aqt-ask-form]').dispatchEvent(new Event('submit', {cancelable: true}));
        fetchMock.mockResolvedValueOnce({ok: true, text: async () => html()});
        await instance.load();
        resolve(json(200, plan()));
        await vi.waitFor(() => expect(document.querySelectorAll('#slot [data-aqt-repair]')).toHaveLength(1));
    });
});
