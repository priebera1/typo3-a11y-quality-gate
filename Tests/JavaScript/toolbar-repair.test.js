// @vitest-environment jsdom

import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {ToolbarAssistant} from '../../Resources/Public/JavaScript/toolbar/a11y-toolbar-assistant.js';

/**
 * Ask AQG repair workflow in the toolbar: an explicit "fix this" answer is a plan with real controls; "Prepare" goes
 * through the toolbar prepare route, then the existing AI Fixes generate route once per new item, with the row version
 * the server returned. Nothing is stored in the conversation, nothing is applied.
 */
describe('Ask AQG repair workflow', () => {
    let fetchMock;
    const limits = JSON.stringify({maxQuestionChars: 400, minQuestionChars: 3, maxHistoryTurns: 3, maxHistoryAnswerChars: 700, maxHistoryChars: 2400, maxSecondsPerQuestion: 45});
    const principal = 'a1'.repeat(20);
    const scope = 'c3'.repeat(20);
    const labels = {
        'you-asked': 'You asked', 'answer-aqg': 'AQG', 'repair-title': 'Repair plan', 'repair-check': 'Check saved page now',
        'repair-check-started': 'Check started', 'repair-check-unavailable': 'Cannot check',
        'repair-cost': 'Max cost %1$s for %2$d requests', 'repair-cost-one': 'Max cost %s for 1 request', 'repair-cost-unknown': 'Cost unknown',
        'repair-prepare': 'Prepare %d suggestions', 'repair-prepare-one': 'Prepare suggestion', 'repair-one-by-one': 'One at a time',
        'repair-open-queue': 'Open AI fixes', 'repair-edit-element': 'Edit element', 'repair-safety': 'Nothing is applied or published.',
        'repair-basis': 'No AI used', 'repair-item-cost': 'max. %s', 'repair-review': 'Review suggestion', 'repair-open-item': 'Open in AI fixes',
        'repair-preparing': 'Preparing…', 'repair-generating': 'Asking AI…', 'repair-progress': 'Suggestion %1$d of %2$d',
        'repair-already-queued': 'Already in AI fixes', 'repair-not-prepared': 'Not prepared', 'repair-done': 'Finished: %1$d ready, %2$d need attention.',
        'repair-cost-recorded': 'Recorded: %s.', 'repair-cost-recorded-unknown': 'Cost unknown.', 'repair-nothing-applied': 'Nothing was applied or published.',
        'repair-in-progress': 'Being prepared already', 'repair-changed': 'Changed meanwhile', 'repair-ready': 'Suggestion ready',
        'repair-retry-later': 'Retry in %d s', 'repair-no-suggestion': 'No safe value', 'repair-failed': 'Failed', 'repair-busy': 'Busy preparing',
        edit: 'Edit', details: 'Details', error: 'Error', 'error-permission': 'Not allowed', 'error-question': 'Too short',
    };
    const html = () => `<section data-aqt-assistant-root="true" ${Object.entries(labels).map(([key, value]) => `data-label-${key}="${value}"`).join(' ')}>
        <section data-aqt-ask="true" data-ai="1" data-limits='${limits}' data-principal="${principal}" data-scope="${scope}">
            <div data-aqt-ask-log="true" role="log" hidden></div>
            <form data-aqt-ask-form="true">
                <input type="hidden" name="aqt-ask-scope" value="page">
                <textarea data-aqt-ask-input="true"></textarea><button type="submit" data-aqt-ask-submit="true">Ask</button>
                <button type="button" data-aqt-ask-clear="true" hidden>New conversation</button>
                <p data-aqt-ask-usage="true" hidden></p>
                <p data-aqt-ask-status="true" role="status"></p>
            </form>
        </section>
    </section>`;
    const json = (status, body) => ({ok: status < 400, status, redirected: false, headers: {get: () => 'application/json'}, json: async () => body});
    const reviewUrl = (item) => `/typo3/module/web/a11y/remediation?id=40&pageUid=40&item=${item}`;

    /** The reported case: a Plain HTML element with a button, an iframe and an image finding, stale check. */
    const plan = (extra = {}) => ({
        success: true, status: 'answer', source: 'aqg', intent: 'repair', scope: 'page', question: 'oprav mi tuto stranku',
        answer: 'I found 3 problems in this content element.', citations: [], notes: [], sent: [],
        repair: {
            target: 'record', targetLabel: 'Content element: AQG 09 - Local cancel 05', selectedTarget: 'record',
            notices: ['You asked about the page, but the selected scope is this content element.'],
            alternatives: [{target: 'page', label: 'Show fixes for the whole page'}],
            counts: {found: 3, ready: 2, queued: 0, saved: 0, manual: 0, blocked: 1, stale: 0},
            headline: 'I found 3 problems in this content element.',
            summary: ['2 AI suggestions can be prepared for your review.', '1 cannot be prepared now.'],
            freshness: {label: '01.10.2026 10:00', stale: true, never: false, canCheck: true, pageUid: 40, languageUid: 0, text: 'The last content check is older than 14 days.'},
            ai: {ready: true, state: 'ready', licence: true, reason: ''},
            bulk: true,
            cost: {total: '<$0.04', unknown: false, requests: 2},
            items: [
                {id: 11, ruleId: 'rte.button_label_missing', title: 'Button has no accessible name', tone: 'critical', severityLabel: 'Critical', location: 'AQG 09 · HTML', kind: 'button_label', state: 'ready', stateLabel: 'Can be prepared', reason: 'Found in the saved content just now.', itemUid: 0, reviewUrl: '', cost: '<$0.02', detailUrl: '/typo3/module/web/a11y/page?pageUid=40', editUrl: '/typo3/record/edit?edit[tt_content][90]=edit'},
                {id: 12, ruleId: 'rte.iframe_missing_title', title: 'Iframe is missing a title attribute', tone: 'critical', severityLabel: 'Critical', location: 'AQG 09 · HTML', kind: 'iframe_title', state: 'ready', stateLabel: 'Can be prepared', reason: 'Found in the saved content just now.', itemUid: 0, reviewUrl: '', cost: '<$0.02', detailUrl: '', editUrl: ''},
                {id: 13, ruleId: 'rte.img_alt_missing', title: 'Image is missing an alt text attribute', tone: 'critical', severityLabel: 'Critical', location: 'AQG 09 · HTML', kind: 'image_alt', state: 'blocked', stateLabel: 'Cannot be prepared now', reason: 'The image file does not exist in a TYPO3 file storage.', itemUid: 0, reviewUrl: '', cost: '', detailUrl: '', editUrl: '/typo3/record/edit?edit[tt_content][90]=edit', howToFix: 'Add descriptive alt text.'},
            ],
            prepareIds: [11, 12],
            queueUrl: '/typo3/module/web/a11y/remediation?id=40&pageUid=40',
            editUrl: '',
            ...extra,
        },
    });

    beforeEach(() => {
        document.body.innerHTML = '<div class="aqt-pop"><div id="slot" data-aqt-assistant></div></div>';
        fetchMock = vi.fn();
        globalThis.fetch = fetchMock;
        window.sessionStorage.clear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    const urls = {
        a11y_toolbar_assistant: '/typo3/ajax/a11y/toolbar/assistant',
        a11y_toolbar_ask: '/typo3/ajax/a11y/toolbar/ask?token=t',
        a11y_toolbar_repair_prepare: '/typo3/ajax/a11y/toolbar/repair/prepare?token=p',
        a11y_remediation_generate: '/typo3/ajax/a11y/remediation/generate?token=g',
    };
    const loaded = async () => {
        fetchMock.mockResolvedValueOnce({ok: true, text: async () => html()});
        const instance = new ToolbarAssistant({win: window, slotSelector: '#slot', ajaxUrls: () => urls});
        await instance.load();
        return instance;
    };
    const ask = async (question, answer) => {
        const before = document.querySelectorAll('[data-aqt-repair]').length;
        fetchMock.mockResolvedValueOnce(json(200, answer));
        document.querySelector('[data-aqt-ask-input]').value = question;
        document.querySelector('[data-aqt-ask-form]').dispatchEvent(new Event('submit', {cancelable: true}));
        await vi.waitFor(() => expect(document.querySelectorAll('[data-aqt-repair]')).toHaveLength(before + 1));
        return document.querySelectorAll('[data-aqt-repair]')[before];
    };
    const bodyOf = (call) => JSON.parse(call[1].body);

    it('shows the plan with counts, staleness, cost and a real Prepare action, and stores nothing', async () => {
        await loaded();
        const article = await ask('oprav mi tuto stranku', plan());

        expect(article.textContent).toContain('I found 3 problems in this content element.');
        expect(article.textContent).toContain('2 AI suggestions can be prepared for your review.');
        expect(article.textContent).toContain('You asked about the page');
        expect(article.textContent).toContain('older than 14 days');
        expect(article.textContent).toContain('Max cost <$0.04 for 2 requests');
        expect(article.textContent).toContain('Nothing is applied or published.');
        expect(article.querySelectorAll('[data-aqt-repair-item]')).toHaveLength(3);
        expect(article.querySelector('[data-aqt-repair-item="13"]').textContent).toContain('The image file does not exist');
        const prepare = article.querySelector('[data-aqt-repair-prepare="all"]');
        expect(prepare.textContent).toBe('Prepare 2 suggestions');
        expect(prepare.getAttribute('aria-describedby')).toBe(article.querySelector('.aqt-repair__cost').id);
        expect(article.querySelector('[data-aqt-repair-check]')).not.toBeNull();
        // The manual path for the finding AQG cannot prepare: Edit and Page Detail links only from the server.
        expect(Array.from(article.querySelector('[data-aqt-repair-item="13"]').querySelectorAll('a')).map((a) => a.textContent)).toEqual(['Edit']);
        // Only the plan request was made; nothing was prepared or generated.
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(window.sessionStorage.getItem('aqg-toolbar-ask:' + scope)).toBeNull();
    });

    it('prepares once, generates each new item with its row version and links the exact AI Fixes item', async () => {
        await loaded();
        const article = await ask('Fix this element', plan());
        fetchMock
            .mockResolvedValueOnce(json(200, {success: true, status: 'prepared', target: 'record', items: [
                {id: 11, outcome: 'queued', reason: '', reasonLabel: '', itemUid: 501, rowVersion: 1, status: 'preparing', statusLabel: 'Preparing', generate: true, reviewUrl: reviewUrl(501)},
                {id: 12, outcome: 'queued', reason: '', reasonLabel: '', itemUid: 502, rowVersion: 1, status: 'preparing', statusLabel: 'Preparing', generate: true, reviewUrl: reviewUrl(502)},
            ]}))
            .mockResolvedValueOnce(json(200, {success: true, status: 'item', item: {uid: 501, status: 'pending_review', statusLabel: 'Pending review', statusReasonLabel: ''}, aiUsage: {sent: true, costKnown: true, cost: '< $0.01', costMicro: 6000}}))
            .mockResolvedValueOnce(json(200, {success: true, status: 'item', item: {uid: 502, status: 'no_suggestion', statusLabel: 'No suggestion', statusReasonLabel: ''}, aiUsage: {sent: true, costKnown: true, cost: '< $0.01', costMicro: 5000}}));

        const button = article.querySelector('[data-aqt-repair-prepare="all"]');
        button.focus();
        button.click();
        button.click();

        await vi.waitFor(() => expect(article.querySelector('[data-aqt-repair-status]').textContent).toContain('Finished'));
        // 1 load + 1 ask + 1 prepare + 2 generate: the second click did nothing.
        expect(fetchMock).toHaveBeenCalledTimes(5);
        expect(fetchMock.mock.calls[2][0]).toBe(urls.a11y_toolbar_repair_prepare);
        expect(bodyOf(fetchMock.mock.calls[2])).toEqual({context: {}, target: 'record', findingIds: [11, 12]});
        expect(fetchMock.mock.calls[3][0]).toBe(urls.a11y_remediation_generate);
        expect(bodyOf(fetchMock.mock.calls[3])).toEqual({item: 501, rowVersion: 1, detailed: false});
        expect(bodyOf(fetchMock.mock.calls[4])).toEqual({item: 502, rowVersion: 1, detailed: false});

        const first = article.querySelector('[data-aqt-repair-item="11"]');
        expect(first.querySelector('[data-aqt-repair-state]').textContent).toBe('Pending review');
        expect(first.querySelector('[data-aqt-repair-review]').getAttribute('href')).toBe(reviewUrl(501));
        const second = article.querySelector('[data-aqt-repair-item="12"]');
        expect(second.querySelector('[data-aqt-repair-state]').textContent).toBe('No suggestion');
        expect(second.textContent).toContain('No safe value');
        // Only a proposal waiting for review is "Review suggestion"; another item is opened as it is.
        expect(first.querySelector('[data-aqt-repair-review]').textContent).toBe('Review suggestion');
        expect(second.querySelector('[data-aqt-repair-review]').textContent).toBe('Open in AI fixes');
        const status = article.querySelector('[data-aqt-repair-status]');
        // Partial success is never presented as overall success; the recorded cost and "nothing applied" are stated.
        // U-05: one total of the recorded costs, not "< $0.01 + < $0.01" (6000 + 5000 micro-USD = $0.011 → $0.01).
        expect(status.textContent).toBe('Finished: 1 ready, 1 need attention. Recorded: $0.01. Nothing was applied or published.');
        expect(status.classList.contains('aqt-repair__status--partial')).toBe(true);
        expect(document.activeElement).toBe(status);
        expect(article.querySelector('[data-aqt-repair-prepare]')).toBeNull();
    });

    it('links an item that is already in AI Fixes without generating it again', async () => {
        await loaded();
        const article = await ask('Fix this element', plan({prepareIds: [11], items: [plan().repair.items[0]]}));
        fetchMock.mockResolvedValueOnce(json(200, {success: true, status: 'prepared', items: [
            {id: 11, outcome: 'already_queued', reason: '', reasonLabel: '', itemUid: 501, rowVersion: 4, status: 'pending_review', statusLabel: 'Pending review', generate: false, reviewUrl: reviewUrl(501)},
        ]}));
        article.querySelector('[data-aqt-repair-prepare="all"]').click();

        await vi.waitFor(() => expect(article.querySelector('[data-aqt-repair-status]').textContent).toContain('Finished'));
        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(fetchMock.mock.calls.some(([url]) => url === urls.a11y_remediation_generate)).toBe(false);
        const row = article.querySelector('[data-aqt-repair-item="11"]');
        expect(row.textContent).toContain('Already in AI fixes');
        expect(row.querySelector('[data-aqt-repair-review]').getAttribute('href')).toBe(reviewUrl(501));
    });

    it('reports a generation that another request runs already, and a refused prepare, without success', async () => {
        await loaded();
        const article = await ask('Fix this element', plan());
        fetchMock
            .mockResolvedValueOnce(json(200, {success: true, status: 'prepared', items: [
                {id: 11, outcome: 'queued', itemUid: 501, rowVersion: 1, status: 'preparing', generate: true, reviewUrl: reviewUrl(501)},
                {id: 12, outcome: 'skipped', reason: 'link_text_disabled', reasonLabel: 'AI link and iframe title suggestions are disabled for this site.', itemUid: 0, rowVersion: 0, generate: false, reviewUrl: ''},
            ]}))
            .mockResolvedValueOnce(json(409, {success: false, code: 'in_progress'}));
        article.querySelector('[data-aqt-repair-prepare="all"]').click();

        await vi.waitFor(() => expect(article.querySelector('[data-aqt-repair-status]').textContent).toContain('Finished'));
        expect(article.querySelector('[data-aqt-repair-item="11"]').textContent).toContain('Being prepared already');
        expect(article.querySelector('[data-aqt-repair-item="12"]').textContent).toContain('disabled for this site');
        expect(article.querySelector('[data-aqt-repair-status]').textContent).toContain('0 ready, 2 need attention');
    });

    it('offers one request per finding without the AI queue permission, and none without AI', async () => {
        await loaded();
        const article = await ask('Fix this element', plan({bulk: false}));
        expect(article.querySelector('[data-aqt-repair-prepare="all"]')).toBeNull();
        expect(article.querySelectorAll('[data-aqt-repair-prepare]')).toHaveLength(2);
        fetchMock
            .mockResolvedValueOnce(json(200, {success: true, status: 'prepared', items: [{id: 12, outcome: 'queued', itemUid: 502, rowVersion: 1, status: 'preparing', generate: true, reviewUrl: reviewUrl(502)}]}))
            .mockResolvedValueOnce(json(200, {success: true, status: 'item', item: {uid: 502, status: 'pending_review', statusLabel: 'Pending review'}, aiUsage: {sent: true, costKnown: false}}));
        article.querySelector('[data-aqt-repair-prepare="12"]').click();
        await vi.waitFor(() => expect(article.querySelector('[data-aqt-repair-status]').textContent).toContain('Finished'));
        expect(bodyOf(fetchMock.mock.calls[2]).findingIds).toEqual([12]);
        expect(article.querySelector('[data-aqt-repair-prepare="11"]')).not.toBeNull();
        expect(article.querySelector('[data-aqt-repair-status]').textContent).toContain('Cost unknown.');

        const last = await ask('Fix this element', plan({ai: {ready: false, state: 'no_licence', licence: false, reason: 'AI fixes need a PRO or Agency licence for this site.'}}));
        expect(last.querySelector('[data-aqt-repair-prepare]')).toBeNull();
        expect(last.textContent).toContain('PRO or Agency licence');
    });

    it('asks again for the whole page only when the editor chooses it', async () => {
        await loaded();
        const article = await ask('oprav mi tuto stranku', plan());
        fetchMock.mockResolvedValueOnce(json(200, plan({target: 'page', targetLabel: 'Page: AQG 09', alternatives: [], notices: []})));
        Array.from(article.querySelectorAll('.aqt-repair__switch button')).find((b) => b.textContent === 'Show fixes for the whole page').click();
        await vi.waitFor(() => expect(document.querySelectorAll('[data-aqt-repair]')).toHaveLength(2));
        const body = bodyOf(fetchMock.mock.calls[2]);
        expect(body.intent).toBe('repair');
        expect(body.repairTarget).toBe('page');
        expect(body.question).toBe('oprav mi tuto stranku');
    });

    it('starts the Content scan section check instead of scanning on its own', async () => {
        const instance = await loaded();
        const article = await ask('Fix this element', plan());
        const spy = vi.spyOn(instance, 'contentScan').mockResolvedValue(undefined);
        // Without the section (no scan permission or not rendered) nothing is requested.
        article.querySelector('[data-aqt-repair-check]').click();
        expect(spy).not.toHaveBeenCalled();
        expect(article.querySelector('[data-aqt-repair-status]').textContent).toBe('Cannot check');

        document.querySelector('.aqt-pop').insertAdjacentHTML('beforeend', '<button type="button" data-aqt-disclosure="content" aria-expanded="false"></button><div data-aqt-content-scan="true"><button type="button" data-aqt-content-scan-button="true">Check</button></div>');
        article.querySelector('[data-aqt-repair-check]').click();
        expect(spy).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
});
