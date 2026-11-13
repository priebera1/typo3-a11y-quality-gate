// @vitest-environment jsdom
import {afterEach, describe, expect, it, vi} from 'vitest';
import {PageDetailRemediation} from '@priebera/a11y-quality-gate/remediation/page-detail-remediation.js';

const labels = {
    'pd.queued': 'In AI fixes:',
    'reason.image_missing': 'The image file does not exist in a TYPO3 file storage.',
    'error.budget_reached': 'The monthly AI budget of this site is reached. Nothing was sent.',
    'pd.result.nothingChanged': 'Nothing was changed.',
    'pd.summaryQueue': '%1$d waiting · %2$d in editor · %3$d saved · %4$d not verified',
    'pd.review': 'Review in queue',
    'plan.occurrence': '%d occurrence',
    'plan.occurrences': '%d occurrences',
    'plan.goTo': 'Go to issue',
    'plan.sendEditor': 'Send editor steps to queue (%d)',
    'plan.copyTicket': 'Copy as ticket',
};

function mount({canBulk = true} = {}) {
    document.body.innerHTML = `
        <div class="a11y-page-detail" data-a11y-language-uid="0">
            <section data-aqg-remediation-page="true">
                <script type="application/json" data-aqg-remediation-page-config="true">${JSON.stringify({
                    pageUid: 3, canBulk, maxBatch: 50, queueUrl: '/queue?token=t', urls: {plan: '/plan', prepare: '/prepare', explain: '/explain', item: '/item', generate: '/generate'},
                    queue: {pending: 2, inEditor: 0, saved: 1, notVerified: 0}, labels,
                })}</script>
                <p data-aqg-remediation-summary-queue="true">static</p>
                <div data-aqg-remediation-page-live="true"></div>
                <button type="button" data-aqg-remediation-plan="true">Plan</button>
                <dialog data-aqg-remediation-plan-dialog="true"></dialog>
            </section>
            <article class="aqg-rulecard" id="rule-img" data-rule-id="rte.img_alt_missing"><h3><button data-aqg-rule-toggle="true" aria-expanded="true">Image</button></h3></article>
            <div data-aqg-remediation-occ="11" data-rule-id="rte.img_alt_missing" data-aqg-remediation-eligible="1">
                <span data-aqg-remediation-status="11"></span>
            </div>
            <div data-aqg-remediation-occ="12" data-rule-id="rte.empty_link" data-aqg-remediation-eligible="1" data-aqg-rule-title="Link has no accessible name" data-aqg-edit-url="/edit/12">
                <div class="aqg-remediation-occ__actions"><button class="aqg-remediation-occ__fix" data-aqg-remediation-prepare="12">Fix with AI<span class="aqg-ai-cost">&lt;$0.02</span></button></div>
                <section class="aqg-ai-result aqg-ai-result--fix" id="aqg-rem-fix-12" data-aqg-remediation-fixresult="12" hidden></section>
                <span data-aqg-remediation-status="12"></span>
            </div>
            <div data-aqg-remediation-occ="13" data-rule-id="rendered.landmark" data-aqg-remediation-eligible="0"><span data-aqg-remediation-status="13"></span></div>
            <div data-aqg-remediation-occ="14" data-rule-id="rte.empty_heading" data-aqg-remediation-eligible="1" data-aqg-rule-title="Heading is empty" data-aqg-edit-url="">
                <div class="aqg-remediation-occ__actions"><button type="button" data-aqg-remediation-explain="14" aria-expanded="false">Explain with AI (&lt;$0.008)</button></div>
                <section class="aqg-ai-result aqg-ai-result--explain" id="aqg-rem-explain-14" data-aqg-remediation-explanation="14" hidden></section>
                <span data-aqg-remediation-status="14"></span>
            </div>
            <div data-aqg-remediation-occ="15" data-rule-id="rte.img_alt_missing" data-aqg-remediation-eligible="1" data-aqg-rule-title="Image is missing an alt text attribute" data-aqg-edit-url="/edit/15">
                <div class="aqg-remediation-occ__actions"><button class="aqg-remediation-occ__fix" data-aqg-remediation-prepare="15">Fix with AI</button></div>
                <section class="aqg-ai-result aqg-ai-result--fix" id="aqg-rem-fix-15" data-aqg-remediation-fixresult="15" hidden></section>
                <span data-aqg-remediation-status="15"></span>
            </div>
        </div>`;
    const dialog = document.querySelector('dialog');
    dialog.showModal = function () { this.setAttribute('open', ''); };
    dialog.close = function () { this.removeAttribute('open'); this.dispatchEvent(new Event('close')); };
    const view = new PageDetailRemediation(document.querySelector('[data-aqg-remediation-page]'));
    view.init();
    return view;
}

const plan = {
    total: 3,
    text: 'Remediation plan: Problems page [3]',
    groups: [
        {owner: 'editor', ownerLabel: 'Editor', steps: [
            {ruleId: 'rte.img_alt_missing', title: 'Image is missing an alt text attribute', occurrences: 1, fixPath: 'ai_text', fixPathLabel: 'AI suggestion', queued: 0, howToFix: ''},
            {ruleId: 'rte.empty_link', title: 'Link has no accessible name', occurrences: 2, fixPath: 'ai_text', fixPathLabel: 'AI suggestion', queued: 1, howToFix: 'Name the link.'},
        ]},
        {owner: 'developer', ownerLabel: 'Developer', steps: [
            {ruleId: 'rendered.landmark', title: 'Page has no main landmark', occurrences: 1, fixPath: 'developer', fixPathLabel: 'Developer guidance', queued: 0, howToFix: ''},
        ]},
    ],
};

afterEach(() => {
    vi.restoreAllMocks();
});

describe('Page Detail remediation', () => {
    it('counts occurrences with an inline AI flow as eligible for the bulk path although they have no own button', () => {
        const view = mount();
        expect(view.isEligible(11)).toBe(true);
        expect(view.isEligible(12)).toBe(true);
        expect(view.isEligible(13)).toBe(false);
    });

    it('updates the summary queue line and the occurrence after a suggestion was prepared (F-32)', () => {
        const view = mount();
        view.markQueued(12, {uid: 7, status: 'pending_review', statusLabel: 'Waiting for review'});
        view.markQueued(11);
        view.addPending(2);
        expect(document.querySelector('[data-aqg-remediation-summary-queue]').textContent).toBe('4 waiting · 0 in editor · 1 saved · 0 not verified');
        expect(document.querySelector('[data-aqg-remediation-prepare="12"]')).toBeNull();
        expect(document.querySelector('[data-aqg-remediation-item-state="12"]').textContent).toBe('In AI fixes: Waiting for review');
        expect(document.querySelector('[data-aqg-remediation-item-state="12"]').className).toContain('aqg-remediation__status--pending_review');
        expect(view.isEligible(11)).toBe(false);
        expect(view.isEligible(12)).toBe(false);
    });

    it('links to exactly one item of the AI fixes queue', () => {
        const view = mount();
        expect(view.itemUrl(9)).toBe('/queue?token=t&item=9');
        view.config.queueUrl = '/queue';
        expect(view.itemUrl(9)).toBe('/queue?item=9');
        expect(view.itemUrl(0)).toBe('/queue');
    });

    it('renders the plan as a drawer grouped by owner with Go to issue, Copy as ticket and editor steps (F-24)', async () => {
        const view = mount();
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({success: true, ...plan}), {status: 200, headers: {'Content-Type': 'application/json'}}));
        await view.openPlan(document.querySelector('[data-aqg-remediation-plan]'));
        const dialog = document.querySelector('dialog');
        expect(dialog.classList.contains('aqg-remediation__dialog--drawer')).toBe(true);
        expect([...dialog.querySelectorAll('[data-aqg-plan-owner]')].map((group) => group.getAttribute('data-aqg-plan-owner'))).toEqual(['editor', 'developer']);
        expect(dialog.querySelector('.aqg-remediation-plan__meta').textContent).toBe('1 occurrence');
        expect(dialog.querySelectorAll('.aqg-remediation-plan__meta')[1].textContent).toBe('2 occurrences');
        // Only rules whose card is on this page get a link.
        expect([...dialog.querySelectorAll('[data-aqg-plan-goto]')].map((link) => link.getAttribute('href'))).toEqual(['#rule-img']);
        expect(dialog.querySelector('[data-aqg-plan-copy]').textContent).toBe('Copy as ticket');
        expect(dialog.querySelector('[data-aqg-plan-send-editor]').textContent).toBe('Send editor steps to queue (3)');
    });

    it('offers no editor-steps button without bulk permission', async () => {
        const view = mount({canBulk: false});
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({success: true, ...plan}), {status: 200, headers: {'Content-Type': 'application/json'}}));
        await view.openPlan(null);
        expect(document.querySelector('[data-aqg-plan-send-editor]')).toBeNull();
    });
});

describe('Page Detail "Explain with AI" (AQG-300-UX-04)', () => {
    const answer = (body, status = 200) => ({ok: status < 300, status, json: async () => body, headers: {get: () => 'application/json'}, redirected: false});

    it('shows the explanation under the occurrence, keeps the focus and never asks twice', async () => {
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(answer({
            success: true, status: 'explanation', explanation: 'The heading is empty.', howToFix: 'Name the section.',
            modelLabel: 'Claude Haiku 5.5', providerLabel: 'Anthropic Claude', sent: ['elementSummary', 'surroundingText'],
        }));
        const view = mount();
        const button = document.querySelector('[data-aqg-remediation-explain="14"]');
        button.focus();
        await view.explainOne(button);

        const region = document.querySelector('[data-aqg-remediation-explanation="14"]');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({findingUid: 14});
        expect(region.hidden).toBe(false);
        expect(region.textContent).toContain('The heading is empty.');
        expect(region.textContent).toContain('Name the section.');
        expect(region.querySelectorAll('details li').length).toBe(2);
        expect(region.querySelector('h4').textContent).toBe('AI explanation');
        expect(region.textContent).toContain('Explains: Heading is empty');
        expect(region.textContent).toContain('Nothing was changed.');
        expect(button.getAttribute('aria-expanded')).toBe('true');
        expect(button.disabled).toBe(false);
        expect(button.hasAttribute('aria-busy')).toBe(false);
        // The answer takes the focus (its heading); the button is never disabled, so nothing drops the focus.
        expect(document.activeElement).toBe(region.querySelector('h4'));

        await view.explainOne(button);
        expect(region.hidden).toBe(true);
        expect(button.getAttribute('aria-expanded')).toBe('false');
        await view.explainOne(button);
        expect(region.hidden).toBe(false);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('says so when the AI has no reliable explanation, and does not pay for the same answer twice', async () => {
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(answer({success: true, status: 'needs_review', explanation: ''}));
        const view = mount();
        const button = document.querySelector('[data-aqg-remediation-explain="14"]');
        await view.explainOne(button);
        expect(document.querySelector('[data-aqg-remediation-explanation="14"]').textContent).toContain('No reliable explanation');
        await view.explainOne(button);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('lets a refused request be retried', async () => {
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(answer({success: false, status: 'error', code: 'provider_timeout'}, 502));
        const view = mount();
        const button = document.querySelector('[data-aqg-remediation-explain="14"]');
        await view.explainOne(button);
        await view.explainOne(button);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('shows a stable error text for a refused request (stale content)', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(answer({success: false, status: 'error', code: 'stale', aiUsage: {sent: false}}, 409));
        const view = mount();
        view.labels['error.stale'] = 'The content changed since the last scan.';
        await view.explainOne(document.querySelector('[data-aqg-remediation-explain="14"]'));
        const region = document.querySelector('[data-aqg-remediation-explanation="14"]');
        expect(region.textContent).toContain('The content changed since the last scan.');
        expect(region.className).toContain('aqg-ai-result--err');
        // A refusal never reached the provider: nothing was billed.
        expect(region.querySelector('.aqg-ai-result__usage').textContent).toBe('No AI request was sent, so nothing was billed.');
    });
});

describe('Page Detail "Fix with AI" outcomes', () => {
    const json = (body, status = 200) => ({ok: status < 300, status, json: async () => body, headers: {get: () => 'application/json'}, redirected: false});
    const routes = (table) => vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, options) => {
        const body = JSON.parse(options.body);
        const answer = table[url];
        const value = typeof answer === 'function' ? answer(body) : answer;
        return Array.isArray(value) ? json(value[0], value[1]) : json(value);
    });
    const item = (overrides = {}) => ({uid: 70, rowVersion: 0, status: 'preparing', statusLabel: 'Waiting for suggestion', statusReason: '', statusReasonLabel: '', ...overrides});
    const calls = (mock, url) => mock.mock.calls.filter(([called]) => called === url).map(([, options]) => JSON.parse(options.body));

    it('reports an unreadable image as refused before anything was queued or sent, with the exact reason', async () => {
        const fetchMock = routes({'/prepare': {success: true, items: [{findingUid: 15, itemUid: 0, outcome: 'skipped', reason: 'image_missing'}]}});
        const view = mount();
        const button = document.querySelector('[data-aqg-remediation-prepare="15"]');
        await view.prepareOne(button);

        const region = document.querySelector('[data-aqg-remediation-fixresult="15"]');
        expect(region.hidden).toBe(false);
        expect(region.className).toContain('aqg-ai-result--warn');
        expect(region.textContent).toContain('The image file does not exist in a TYPO3 file storage.');
        expect(region.textContent).not.toContain('image_missing');
        expect(region.textContent).not.toContain('Suggestion ready');
        expect(region.querySelector('.aqg-ai-result__usage').textContent).toBe('No AI request was sent, so nothing was billed.');
        expect(region.querySelector('a[href="/edit/15"]').textContent).toBe('Edit record');
        expect(calls(fetchMock, '/generate')).toEqual([]);
        // No item exists, so the occurrence stays eligible and keeps its button; focus is on the outcome.
        expect(view.isEligible(15)).toBe(true);
        expect(document.activeElement).toBe(region.querySelector('h4'));
        expect(button.hasAttribute('aria-busy')).toBe(false);
    });

    it('says "Suggestion ready" only for a suggestion that exists, and links to that exact item', async () => {
        routes({
            '/prepare': {success: true, items: [{findingUid: 12, itemUid: 70, outcome: 'queued', reason: ''}]},
            '/item': {success: true, item: item()},
            '/generate': {success: true, item: item({status: 'pending_review', statusLabel: 'Waiting for review'}), aiUsage: {sent: true, outcome: 'success', basis: 'reported', costKnown: true, cost: '< $0.01'}},
        });
        const view = mount();
        await view.prepareOne(document.querySelector('[data-aqg-remediation-prepare="12"]'));

        const region = document.querySelector('[data-aqg-remediation-fixresult="12"]');
        expect(region.querySelector('h4').textContent).toBe('Suggestion ready for review');
        expect(region.className).toContain('aqg-ai-result--ok');
        const link = region.querySelector('a[data-aqg-remediation-item-link]');
        expect(link.getAttribute('href')).toBe('/queue?token=t&item=70');
        expect(region.querySelector('.aqg-ai-result__usage').textContent).toBe('Recorded AI usage: < $0.01.');
        expect(document.querySelector('[data-aqg-remediation-item-state="12"]').textContent).toBe('In AI fixes: Waiting for review');
        expect(view.isEligible(12)).toBe(false);
    });

    it('never claims success for an item that already exists: it shows the item\'s real status (here Failed)', async () => {
        const fetchMock = routes({
            '/prepare': {success: true, items: [{findingUid: 12, itemUid: 70, outcome: 'already_queued', reason: ''}]},
            '/item': {success: true, item: item({status: 'failed', statusLabel: 'Failed', statusReason: 'provider_timeout', statusReasonLabel: 'The AI provider did not answer in time.'})},
        });
        const view = mount();
        await view.prepareOne(document.querySelector('[data-aqg-remediation-prepare="12"]'));

        const region = document.querySelector('[data-aqg-remediation-fixresult="12"]');
        expect(region.querySelector('h4').textContent).toBe('Already in AI fixes');
        expect(region.textContent).toContain('Status: Failed');
        expect(region.textContent).toContain('The AI provider did not answer in time.');
        expect(region.textContent).not.toContain('Suggestion ready');
        expect(region.querySelector('a[data-aqg-remediation-item-link]').getAttribute('href')).toBe('/queue?token=t&item=70');
        expect(calls(fetchMock, '/generate')).toEqual([]);
    });

    it('reports a failed generation that reached the provider, and "Try again" regenerates the same item', async () => {
        let generated = 0;
        const fetchMock = routes({
            '/prepare': {success: true, items: [{findingUid: 12, itemUid: 70, outcome: 'queued', reason: ''}]},
            '/item': () => ({success: true, item: generated === 1 ? item({status: 'failed', rowVersion: 2, statusLabel: 'Failed', statusReason: 'provider_unavailable', statusReasonLabel: 'The AI provider is unavailable right now. Nothing was changed.'}) : item()}),
            '/generate': () => {
                generated++;
                return generated === 1
                    ? {success: true, item: item({status: 'failed', rowVersion: 2, statusLabel: 'Failed', statusReason: 'provider_unavailable', statusReasonLabel: 'The AI provider is unavailable right now. Nothing was changed.'}), aiUsage: {sent: true, outcome: 'failed', basis: 'bound', costKnown: true, cost: '< $0.01'}}
                    : {success: true, item: item({status: 'pending_review', rowVersion: 4, statusLabel: 'Waiting for review'}), aiUsage: {sent: true, outcome: 'success', basis: 'reported', costKnown: true, cost: '< $0.01'}};
            },
        });
        const view = mount();
        await view.prepareOne(document.querySelector('[data-aqg-remediation-prepare="12"]'));

        const region = document.querySelector('[data-aqg-remediation-fixresult="12"]');
        expect(region.querySelector('h4').textContent).toBe('AI request failed');
        expect(region.className).toContain('aqg-ai-result--err');
        // The reason's own "Nothing was changed." is said once, in the bold line.
        expect(region.textContent.match(/Nothing was changed\./g)).toBeNull();
        expect(region.querySelector('.aqg-ai-result__usage').textContent).toContain('may bill it although it failed');
        expect(document.querySelector('[data-aqg-remediation-item-state="12"]').textContent).toContain('In AI fixes: Failed');

        const retry = region.querySelector('[data-aqg-remediation-retry="12"]');
        expect(retry.textContent).toContain('<$0.02');
        await view.retryOne(12, 70, retry);
        expect(calls(fetchMock, '/generate')).toEqual([
            {item: 70, rowVersion: 0, detailed: false},
            {item: 70, rowVersion: 2, detailed: false},
        ]);
        expect(region.querySelector('h4').textContent).toBe('Suggestion ready for review');
    });

    it('reports a refused prepare request (budget) without inventing an item', async () => {
        routes({'/prepare': [{success: false, status: 'error', code: 'budget_reached'}, 422]});
        const view = mount();
        await view.prepareOne(document.querySelector('[data-aqg-remediation-prepare="12"]'));
        const region = document.querySelector('[data-aqg-remediation-fixresult="12"]');
        expect(region.textContent).toContain('The monthly AI budget of this site is reached.');
        expect(region.querySelector('a[data-aqg-remediation-item-link]')).toBeNull();
        expect(view.isEligible(12)).toBe(true);
    });

    it('asks once while a request runs (aria-busy, never disabled)', async () => {
        let release;
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise((resolve) => {
            release = () => resolve(json({success: true, items: [{findingUid: 15, itemUid: 0, outcome: 'skipped', reason: 'image_missing'}]}));
        }));
        const view = mount();
        const button = document.querySelector('[data-aqg-remediation-prepare="15"]');
        const first = view.prepareOne(button);
        expect(button.getAttribute('aria-busy')).toBe('true');
        expect(button.getAttribute('aria-disabled')).toBe('true');
        expect(button.disabled).toBe(false);
        expect(document.querySelector('[data-aqg-remediation-fixresult="15"]').getAttribute('aria-busy')).toBe('true');
        await view.prepareOne(button);
        release();
        await first;
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('never claims "nothing was sent" when the answer is lost (gateway timeout, network error)', async () => {
        routes({
            '/prepare': {success: true, items: [{findingUid: 12, itemUid: 70, outcome: 'queued', reason: ''}]},
            '/item': {success: true, item: item()},
            '/generate': [{}, 504],
        });
        const view = mount();
        view.labels['pd.usage.unconfirmed'] = 'AQG could not confirm whether a request reached the AI provider.';
        await view.prepareOne(document.querySelector('[data-aqg-remediation-prepare="12"]'));
        const region = document.querySelector('[data-aqg-remediation-fixresult="12"]');
        expect(region.querySelector('h4').textContent).toBe('AI request failed');
        expect(region.querySelector('.aqg-ai-result__usage').textContent).toContain('could not confirm');
        expect(region.textContent).not.toContain('nothing was billed');
    });
});
