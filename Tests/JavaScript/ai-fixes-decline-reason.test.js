// @vitest-environment jsdom
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {aiDeclineReason} from '@priebera/a11y-quality-gate/remediation/remediation-api.js';
import {RemediationQueue} from '@priebera/a11y-quality-gate/remediation/remediation-queue.js';
import {PageDetailRemediation} from '@priebera/a11y-quality-gate/remediation/page-detail-remediation.js';
import {ToolbarAssistant} from '../../Resources/Public/JavaScript/toolbar/a11y-toolbar-assistant.js';

/**
 * Live QA 2026-11-14, finding 75325 (rte.non_descriptive_link, "read more" → /info on typo3test14): the model declined
 * with a concrete reason ("the link target is only '/info' …"), AQG stored it, but every surface showed only "The AI
 * found no safe suggestion. Please fix it yourself." The decline reason is now shown — as text, under a translated
 * label — wherever a "No safe suggestion" outcome is shown. A rejected value (invalid_response) stores no reason and
 * shows none; failed and not-applicable items keep their own texts.
 */
const REASON = "The surrounding text is in Slovak and the link target is only '/info', so the destination's topic cannot be determined safely; the editor should name the page the link points to.";
const declined = (overrides = {}) => ({
    uid: 7, rowVersion: 2, proposalVersion: 0, status: 'no_suggestion', statusLabel: 'No safe suggestion', statusReason: 'needs_review',
    statusReasonLabel: 'The AI found no safe suggestion. Please fix it yourself.', explanation: REASON, provider: 'anthropic', model: 'claude-haiku-5-5', isDone: false, isSaved: false,
    isVerified: false, applyMode: 'editor', ruleTitle: 'Link text is not descriptive', severity: 'warning', severityLabel: 'Warning',
    owner: 'editor', ownerLabel: 'Editor', origin: 'editor_request', originLabel: 'Editor request', pageUid: 730, pageTitle: 'AQG 02 - Local Mixed',
    fieldLabel: 'Text (rich text)', targetLabel: 'Content element [939]', originalValue: 'read more', finalValue: '', proposedValue: '',
    seen: true, permissionLine: 'can_apply', actions: {approve: false, edit: false, reject: true, regenerate: true}, recordUrl: '/record/edit',
    pageDetailUrl: '/page-detail', updatedAt: 0, createdAt: 0,
    ...overrides,
});

describe('aiDeclineReason', () => {
    it('returns the model reason of a decline only', () => {
        expect(aiDeclineReason(declined())).toBe(REASON);
        // A value the validator rejected stores no reason; nothing to show.
        expect(aiDeclineReason(declined({statusReason: 'invalid_response', explanation: ''}))).toBe('');
        expect(aiDeclineReason(declined({statusReason: 'invalid_response', explanation: 'leftover'}))).toBe('');
        // Other states have their own text: a pending proposal's "why" is shown with the proposal, a failure says why it failed.
        expect(aiDeclineReason(declined({status: 'pending_review'}))).toBe('');
        expect(aiDeclineReason(declined({status: 'failed', statusReason: 'provider_timeout'}))).toBe('');
        expect(aiDeclineReason(declined({explanation: '   '}))).toBe('');
        expect(aiDeclineReason(null)).toBe('');
    });

    // Review 2026-11-14: an empty heading with nothing after it is declined by AQG itself, without a provider request;
    // its guidance is AQG's text, never "the AI's reason".
    it('never presents AQG\'s own reason as the AI\'s', () => {
        const noBasis = declined({provider: '', model: '', explanation: 'Nothing follows this empty heading. Remove it in the editor.'});
        expect(aiDeclineReason(noBasis)).toBe('');
        expect(aiDeclineReason(noBasis, {fromAiOnly: false})).toBe('Nothing follows this empty heading. Remove it in the editor.');
    });
});

describe('AI fixes drawer: No safe suggestion', () => {
    let answers;
    const mount = () => {
        document.body.innerHTML = `
            <div data-aqg-remediation-queue>
                <script type="application/json" data-aqg-remediation-config>${JSON.stringify({
                    sites: ['main'], allSites: ['main'], pageUid: 0, view: 'open', showCount: true, canBulk: false, locale: 'en-GB',
                    urls: {list: '/list', item: '/item', view: '/view', reject: '/reject', generate: '/generate', changelog: '/changelog'}, labels: {'drawer.why': 'Why'},
                })}</script>
                <p data-aqg-rq-resultcount></p>
                <div data-aqg-rq-queueview><form data-aqg-rq-filters></form><div data-aqg-rq-list></div><nav data-aqg-rq-pagination hidden></nav></div>
                <div data-aqg-rq-drawer hidden><div data-aqg-rq-drawer-panel></div></div>
                <div data-aqg-rq-live></div>
            </div>`;
        const queue = new RemediationQueue(document.querySelector('[data-aqg-remediation-queue]'));
        queue.init();
        return queue;
    };
    const flush = async () => { for (let i = 0; i < 8; i++) { await Promise.resolve(); } };

    beforeEach(() => {
        answers = {'/list': () => ({success: true, items: [declined()], total: 1, page: 1, pages: 1, perPage: 25, counts: {open: 1, mine: 1, done: 0}})};
        vi.stubGlobal('fetch', vi.fn(async (url, options) => {
            const body = JSON.parse(options.body);
            return {ok: true, status: 200, redirected: false, headers: {get: () => 'application/json'}, json: async () => (answers[url] ?? (() => ({success: true})))(body)};
        }));
    });
    afterEach(() => { vi.unstubAllGlobals(); document.body.innerHTML = ''; });

    it('names the status, the generic reason and the model reason as text', async () => {
        answers['/item'] = () => ({success: true, item: declined({explanation: `<img src=x onerror=alert(1)> ${REASON}`})});
        const queue = mount();
        await flush();
        await queue.openItem(7);
        const notice = document.querySelector('[data-aqg-rq-drawer-panel] .aqg-remediation__panel.is-notice');
        expect(notice.textContent).toContain('No safe suggestion');
        expect(notice.textContent).toContain('The AI found no safe suggestion. Please fix it yourself.');
        const why = notice.querySelector('.aqg-remediation__why');
        expect(why.textContent).toBe(`Why: <img src=x onerror=alert(1)> ${REASON}`);
        expect(why.querySelector('img')).toBeNull();
        // Still no proposal, no approval.
        expect(document.querySelector('[data-aqg-rq-drawer-panel] .aqg-remediation__suggestion')).toBeNull();
    });

    it('shows AQG\'s own reason under the neutral "Why" when no provider was asked', async () => {
        answers['/item'] = () => ({success: true, item: declined({provider: '', model: '', explanation: 'Nothing follows this empty heading. Remove it in the editor.'})});
        const queue = mount();
        await flush();
        await queue.openItem(7);
        expect(document.querySelector('[data-aqg-rq-drawer-panel] .aqg-remediation__why').textContent).toBe('Why: Nothing follows this empty heading. Remove it in the editor.');
    });

    it('adds no reason line to a rejected value', async () => {
        answers['/item'] = () => ({success: true, item: declined({statusReason: 'invalid_response', statusReasonLabel: 'The AI answer could not be used.', explanation: ''})});
        const queue = mount();
        await flush();
        await queue.openItem(7);
        expect(document.querySelector('[data-aqg-rq-drawer-panel] .aqg-remediation__why')).toBeNull();
    });
});

describe('Page Detail "Fix with AI": No usable suggestion', () => {
    const mount = (labels) => {
        document.body.innerHTML = `
            <div class="a11y-page-detail" data-a11y-language-uid="0">
                <section data-aqg-remediation-page="true">
                    <script type="application/json" data-aqg-remediation-page-config="true">${JSON.stringify({
                        pageUid: 730, canBulk: false, maxBatch: 50, queueUrl: '/queue', urls: {plan: '/plan', prepare: '/prepare', explain: '/explain', item: '/item', generate: '/generate'},
                        queue: {pending: 0, inEditor: 0, saved: 0, notVerified: 0}, labels,
                    })}</script>
                    <div data-aqg-remediation-page-live="true"></div>
                </section>
                <div data-aqg-remediation-occ="75325" data-rule-id="rte.non_descriptive_link" data-aqg-remediation-eligible="1" data-aqg-edit-url="/edit/939">
                    <section class="aqg-ai-result aqg-ai-result--fix" id="aqg-rem-fix-75325" data-aqg-remediation-fixresult="75325" hidden></section>
                    <span data-aqg-remediation-status="75325"></span>
                </div>
            </div>`;
        const view = new PageDetailRemediation(document.querySelector('[data-aqg-remediation-page]'));
        view.init();
        return view;
    };
    afterEach(() => { document.body.innerHTML = ''; });

    it('shows the model reason under its translated label', () => {
        const view = mount({'pd.result.aiReason': 'Begründung der KI'});
        const region = view.renderFixResult(75325, {...view.outcomeOf(declined(), {sent: true}), usage: {sent: true}});
        expect(region.querySelector('[data-aqg-ai-reason]').textContent).toBe(`Begründung der KI: ${REASON}`);
        expect(region.textContent).toContain('The AI found no safe suggestion. Please fix it yourself.');
    });

    it('shows no untranslated reason line while the label is not delivered', () => {
        const view = mount({});
        const region = view.renderFixResult(75325, {...view.outcomeOf(declined(), {sent: true}), usage: {sent: true}});
        expect(region.querySelector('[data-aqg-ai-reason]')).toBeNull();
        expect(region.textContent).toContain('The AI found no safe suggestion. Please fix it yourself.');
    });
});

describe('Ask AQG: Prepare outcomes', () => {
    let fetchMock;
    const json = (status, body) => ({ok: status < 400, status, redirected: false, headers: {get: () => 'application/json'}, json: async () => body});
    const html = `<section data-aqt-assistant-root="true" data-label-preparing="Preparing" data-label-prepared="Ready" data-label-queued="Queued"
            data-label-prepare-none="AI did not produce a usable suggestion." data-label-ai-reason="Reason given by the AI" data-label-error="Error"
            data-label-error-provider-timeout="The AI provider did not answer in time." data-label-review="Review in AI fixes">
        <a data-aqt-queue href="/typo3/module/web/a11y/remediation">AI fixes</a>
        <div data-aqt-live></div>
        <article data-aqt-finding="75325" data-finding-uid="75325">
            <button type="button" data-aqt-action="prepare" data-finding-uid="75325">Fix with AI</button>
            <div data-aqt-result="true" hidden></div>
        </article>
    </section>`;
    const urls = {a11y_toolbar_assistant: '/assistant', a11y_remediation_prepare: '/prepare', a11y_remediation_item: '/item', a11y_remediation_generate: '/generate'};

    beforeEach(() => {
        document.body.innerHTML = '<div id="slot" data-aqt-assistant></div>';
        fetchMock = vi.fn();
        globalThis.fetch = fetchMock;
    });
    afterEach(() => { vi.restoreAllMocks(); document.body.innerHTML = ''; });

    const prepare = async (generated) => {
        fetchMock.mockResolvedValueOnce({ok: true, text: async () => html});
        const assistant = new ToolbarAssistant({win: window, slotSelector: '#slot', ajaxUrls: () => urls});
        await assistant.load();
        const finding = document.querySelector('[data-aqt-finding]');
        fetchMock
            .mockResolvedValueOnce(json(200, {success: true, items: [{findingUid: 75325, outcome: 'queued', itemUid: 7}]}))
            .mockResolvedValueOnce(json(200, {success: true, status: 'item', item: {uid: 7, status: 'preparing', rowVersion: 1}}))
            .mockResolvedValueOnce(generated);
        await assistant.prepare(document.querySelector('[data-aqt-assistant-root]'), finding, 75325);
        return finding.querySelector('[data-aqt-result]');
    };

    it('shows the AI\'s reason with a declined suggestion, as text', async () => {
        const result = await prepare(json(200, {success: true, status: 'item', item: declined()}));
        expect(result.querySelector('.aqt-result--warning').textContent).toBe('AI did not produce a usable suggestion.');
        expect(result.querySelector('[data-aqt-ai-reason]').textContent).toBe(`Reason given by the AI: ${REASON}`);
        // Screen readers hear the reason, not only the generic line.
        expect(document.querySelector('[data-aqt-live]').textContent).toBe(`AI did not produce a usable suggestion. Reason given by the AI: ${REASON}`);
        expect(fetchMock.mock.calls[3][0]).toBe('/generate');
        expect(JSON.parse(fetchMock.mock.calls[3][1].body)).toEqual({item: 7, rowVersion: 1, detailed: false});
    });

    it('labels nothing as the AI\'s reason when AQG declined without asking a provider', async () => {
        const result = await prepare(json(200, {success: true, status: 'item', item: declined({provider: '', model: '', explanation: 'Nothing follows this empty heading.'})}));
        expect(result.querySelector('.aqt-result--warning').textContent).toBe('AI did not produce a usable suggestion.');
        expect(result.querySelector('[data-aqt-ai-reason]')).toBeNull();
    });

    it('keeps a failed generation apart from "no suggestion"', async () => {
        const result = await prepare(json(200, {success: true, status: 'item', item: declined({status: 'failed', statusLabel: 'Failed', statusReason: 'provider_timeout', statusReasonLabel: 'The AI provider did not answer in time.', explanation: ''})}));
        expect(result.querySelector('.aqt-result--error').textContent).toBe('Failed: The AI provider did not answer in time.');
        expect(result.querySelector('[data-aqt-ai-reason]')).toBeNull();
    });

    it('never calls a refused generate request "queued"', async () => {
        const result = await prepare(json(502, {success: false, code: 'provider_timeout'}));
        expect(result.textContent).not.toBe('Queued');
        expect(result.querySelector('.aqt-result--error')).not.toBeNull();
    });

    it('still says "queued" while another request generates the same item', async () => {
        const result = await prepare(json(409, {success: false, code: 'in_progress'}));
        expect(result.querySelector('.aqt-result--info').textContent).toBe('Queued');
    });
});

describe('Ask AQG repair plan: a declined row', () => {
    it('adds the reason line once and replaces it on the next outcome', () => {
        document.body.innerHTML = `<section data-aqt-assistant-root="true" data-label-ai-reason="Reason given by the AI">
            <article data-aqt-repair-item="1"><span data-aqt-repair-state></span><p data-aqt-repair-result></p></article></section>`;
        const assistant = new ToolbarAssistant({win: window, slotSelector: '#slot', ajaxUrls: () => ({})});
        const root = document.querySelector('[data-aqt-assistant-root]');
        const row = root.querySelector('[data-aqt-repair-item]');
        assistant.repairGenerated(root, row, {reviewUrl: ''}, {ok: true, data: {item: declined()}}, {review: 0, attention: 0});
        expect(row.querySelector('[data-aqt-repair-state]').textContent).toBe('No safe suggestion');
        expect(row.querySelector('[data-aqt-repair-result]').textContent).toBe('The AI found no safe suggestion. Please fix it yourself.');
        expect(row.querySelectorAll('[data-aqt-ai-reason]')).toHaveLength(1);
        expect(row.querySelector('[data-aqt-ai-reason]').textContent).toBe(`Reason given by the AI: ${REASON}`);

        assistant.repairGenerated(root, row, {reviewUrl: ''}, {ok: true, data: {item: declined({statusReason: 'invalid_response', statusReasonLabel: 'The AI answer could not be used.', explanation: ''})}}, {review: 0, attention: 0});
        expect(row.querySelector('[data-aqt-ai-reason]')).toBeNull();
    });
});
