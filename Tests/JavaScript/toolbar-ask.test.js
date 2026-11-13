// @vitest-environment jsdom

import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {
    answerNodes,
    askErrorLabelKey,
    askLimits,
    historyFor,
    money,
    truncate,
    safeBackendHref,
    ToolbarAssistant,
} from '../../Resources/Public/JavaScript/toolbar/a11y-toolbar-assistant.js';
import {
    finishedAnnouncement,
    restoreDisclosures,
    sectionStates,
    setDisclosure,
    statusUpdateTargets,
} from '../../Resources/Public/JavaScript/toolbar/a11y-scan-toolbar-menu.js';

const section = (key, state, label, summary) => `<section class="aqt-scan" data-aqt-source="${key}" data-aqt-state="${state}" data-aqt-status-label="${label}">
    <h2><button type="button" id="t-${key}" aria-expanded="false" aria-controls="p-${key}" data-aqt-disclosure="${key}"><span class="aqt-scan__name">${key} scan</span><span class="aqt-scan__summary"><span>, </span>${summary}</span></button></h2>
    <div id="p-${key}" hidden>${summary}</div>
</section>`;

describe('collapsible scan sections', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('refreshes the header and the sections, not the assistant between them', () => {
        document.body.innerHTML = '<div id="menu"><div data-aqt-refresh="head">old head</div><div data-aqt-assistant>assistant</div><div data-aqt-refresh="scans" data-aqt-status>old</div></div>';
        const parsed = document.createElement('div');
        parsed.innerHTML = '<div data-aqt-refresh="head">new head</div><div data-aqt-assistant></div><div data-aqt-refresh="scans" data-aqt-status>new</div>';

        const targets = statusUpdateTargets(document.getElementById('menu'), parsed);

        expect(targets.map(({element}) => element.getAttribute('data-aqt-refresh'))).toEqual(['head', 'scans']);
        expect(targets.map(({html}) => html)).toEqual(['new head', 'new']);
    });

    it('is collapsed by default and keeps a section the user opened open across a refresh', () => {
        document.body.innerHTML = `<div id="scans">${section('content', 'open', 'Complete', '0 new · 300 open')}${section('frontend', 'running', 'Running', '2 of 8 pages')}</div>`;
        const button = document.querySelector('[data-aqt-disclosure="content"]');
        expect(button.getAttribute('aria-expanded')).toBe('false');
        expect(document.getElementById('p-content').hidden).toBe(true);

        setDisclosure(button, true);
        expect(document.getElementById('p-content').hidden).toBe(false);

        // A refresh replaces the markup with the server's collapsed state; the menu re-applies what the user opened.
        document.getElementById('scans').innerHTML = `${section('content', 'open', 'Complete', '1 new · 301 open')}${section('frontend', 'running', 'Running', '3 of 8 pages')}`;
        restoreDisclosures(document.getElementById('scans'), new Set(['content']));

        expect(document.querySelector('[data-aqt-disclosure="content"]').getAttribute('aria-expanded')).toBe('true');
        expect(document.getElementById('p-content').hidden).toBe(false);
        expect(document.querySelector('[data-aqt-disclosure="frontend"]').getAttribute('aria-expanded')).toBe('false');
        expect(document.getElementById('p-frontend').hidden).toBe(true);
    });

    it('announces a scan only when it stops running, once', () => {
        document.body.innerHTML = `<div id="a">${section('frontend', 'running', 'Running', '3 of 8 pages')}</div><div id="b">${section('frontend', 'open', 'Complete', '42 occurrences')}</div>`;
        const running = sectionStates(document.getElementById('a'));
        const done = sectionStates(document.getElementById('b'));

        expect(finishedAnnouncement(running, done)).toBe('frontend scan, Complete, 42 occurrences');
        expect(finishedAnnouncement(done, done)).toBe('', 'routine refreshes stay silent');
        expect(finishedAnnouncement(running, running)).toBe('');
    });
});

describe('Ask AQG', () => {
    let fetchMock;
    const limits = JSON.stringify({maxQuestionChars: 400, minQuestionChars: 3, maxHistoryTurns: 3, maxHistoryAnswerChars: 700, maxHistoryChars: 3300, maxSecondsPerQuestion: 45, maxAiQuestionsPerConversation: 10});
    const principal = 'a1'.repeat(20);
    const scope = 'c3'.repeat(20);
    const askHtml = (ai = true, view = {principal, scope}) => `<section data-aqt-assistant-root="true" data-label-asking="Asking" data-label-answering="Looking" data-label-you-asked="You asked" data-label-answer="AQG answer"
            data-label-answer-aqg="AQG" data-label-deterministic="From your findings, no AI" data-label-based-on="Based on findings" data-label-general="General advice"
            data-label-no-findings-used="No finding" data-label-sources="Findings used" data-label-occurrences="%d occurrences" data-label-occurrence="1 occurrence"
            data-label-edit="Edit" data-label-error-scope="No site access" data-label-error-question="Too short" data-label-error-busy="Busy"
            data-label-error-conversation-limit="Conversation full" data-label-usage="AI questions: %1$d of %2$d" data-label-usage-cost="%1$s of %2$s"
            data-label-limit-reached="limit reached" data-label-cleared="Cleared" data-label-error="Error" data-label-next-step="Next step" data-label-pages="on %d pages" data-label-most-on="most on %s">
        <section data-aqt-ask="true" data-ai="${ai ? '1' : '0'}" data-limits='${limits}' data-principal="${view.principal}" data-scope="${view.scope}">
            <div data-aqt-ask-log="true" role="log" hidden></div>
            <form data-aqt-ask-form="true">
                ${ai ? '<label><input type="radio" name="aqt-ask-scope" value="page" checked> Page</label><label><input type="radio" name="aqt-ask-scope" value="site"> Site</label>' : ''}
                <textarea data-aqt-ask-input="true"></textarea><button type="submit" data-aqt-ask-submit="true">Ask</button>
                <button type="button" data-aqt-ask-clear="true" hidden>New conversation</button>
                <p data-aqt-ask-usage="true" hidden></p>
                <p data-aqt-ask-status="true" role="status"></p>
            </form>
        </section>
        <p data-aqt-live="true"></p>
    </section>`;
    const json = (status, body) => ({ok: status < 400, status, redirected: false, headers: {get: () => 'application/json'}, json: async () => body});
    const aiAnswer = (extra = {}) => ({
        success: true, status: 'answer', source: 'ai', question: 'What first?', answer: '<img src=x onerror=alert(1)> Fix images.\n- Add alt text\n- Check links',
        nextStep: 'Open the element.', basedOnFindings: true, generalAdvice: true,
        citations: [
            {ref: 'F1', title: 'Image without alt', tone: 'critical', severityLabel: 'Critical', count: 4, detailUrl: '/typo3/module/content/a11y/page?x=1', editUrl: 'https://evil.test/typo3/x'},
            {ref: 'F2', title: 'Script link', tone: 'bogus', severityLabel: 'Warning', count: 1, detailUrl: 'javascript:alert(1)', editUrl: ''},
        ],
        notes: ['Based on the content check of 04.11.2026 10:00.'], sent: ['Your question'],
        conversation: {id: 'v2.' + 'a'.repeat(24) + '.1.' + 'b'.repeat(64), aiQuestions: 1, maxAiQuestions: 10, spentMicroUsd: 1200, costUnknown: false, maxCostMicroUsd: 50000, limitReached: false},
        history: {question: extra.question ?? 'What first?', answer: 'Fix images.', token: 'd'.repeat(64)},
        ...extra,
    });

    beforeEach(() => {
        document.body.innerHTML = '<div id="slot" data-aqt-assistant></div>';
        fetchMock = vi.fn();
        globalThis.fetch = fetchMock;
        window.sessionStorage.clear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    const loaded = async (ai = true) => {
        fetchMock.mockResolvedValueOnce({ok: true, text: async () => askHtml(ai)});
        const instance = new ToolbarAssistant({
            win: window,
            slotSelector: '#slot',
            ajaxUrls: () => ({a11y_toolbar_assistant: '/typo3/ajax/a11y/toolbar/assistant', a11y_toolbar_ask: '/typo3/ajax/a11y/toolbar/ask?token=t'}),
        });
        await instance.load();
        return instance;
    };
    const submit = () => document.querySelector('[data-aqt-ask-form]').dispatchEvent(new Event('submit', {cancelable: true}));

    it('sends nothing on open; without AI a typed question gets an answer from the findings', async () => {
        await loaded(false);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(document.querySelector('[data-aqt-ask-input]')).not.toBeNull();
        expect(document.querySelector('.aqt-turn')).toBeNull();
        fetchMock.mockResolvedValueOnce(json(200, {success: true, status: 'answer', source: 'aqg', intent: 'first', question: 'What now?', answer: 'Fix these first:\n- Critical: Image without alt', citations: [], notes: ['Answered from your AQG findings without AI.'], sent: []}));

        document.querySelector('[data-aqt-ask-input]').value = 'What now?';
        submit();

        await vi.waitFor(() => expect(document.querySelector('.aqt-turn')).not.toBeNull());
        expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({question: 'What now?', scope: 'page', intent: '', context: {}, history: [], conversation: ''});
        expect(document.querySelector('.aqt-turn').textContent).toContain('From your findings, no AI');
        expect(document.querySelector('[data-aqt-ask-usage]').hidden).toBe(true, 'no AI usage for an answer from the findings');
    });

    it('keeps the question readable but read-only while it is answered, and labels a findings answer after a provider failure', async () => {
        await loaded();
        let release;
        fetchMock.mockReturnValueOnce(new Promise((resolve) => {
            release = resolve;
        }));
        const input = document.querySelector('[data-aqt-ask-input]');
        input.value = 'Is this page fine?';
        submit();

        await vi.waitFor(() => expect(input.readOnly).toBe(true));
        expect(document.querySelector('[data-aqt-ask-form]').getAttribute('aria-busy')).toBe('true');
        expect(input.disabled).toBe(false, 'focusable while loading');

        // AskAqgService answered from the findings because the provider timed out: never shown as an AI answer.
        release(json(200, {success: true, status: 'answer', source: 'aqg', intent: 'first', aiFailure: 'provider_timeout', question: 'Is this page fine?', answer: 'Fix these first:\n- Critical: Image without alt', citations: [],
            notes: ['The AI provider did not answer in time. Answered from your AQG findings without AI. Ask again later for an AI answer.'], sent: []}));
        await vi.waitFor(() => expect(document.querySelector('.aqt-turn')).not.toBeNull());
        expect(input.readOnly).toBe(false);
        expect(document.querySelector('[data-aqt-ask-form]').hasAttribute('aria-busy')).toBe(false);
        const turn = document.querySelector('.aqt-turn');
        expect(turn.textContent).toContain('From your findings, no AI');
        expect(turn.textContent).not.toContain('AQG answer');
        expect(turn.querySelector('.aqt-turn__notes').textContent).toContain('did not answer in time');
    });

    it('sends only the question, scope, context ids, the bounded history and the signed id, and renders text only', async () => {
        await loaded();
        fetchMock.mockResolvedValueOnce(json(200, aiAnswer()));
        document.querySelector('[data-aqt-ask-input]').value = '  What   first? ';
        submit();

        await vi.waitFor(() => expect(document.querySelector('.aqt-turn')).not.toBeNull());
        const [url, options] = fetchMock.mock.calls[1];
        expect(url).toBe('/typo3/ajax/a11y/toolbar/ask?token=t');
        expect(JSON.parse(options.body)).toEqual({question: 'What first?', scope: 'page', intent: '', context: {}, history: [], conversation: ''});
        const turn = document.querySelector('.aqt-turn');
        expect(turn.querySelector('img')).toBeNull();
        expect(turn.textContent).toContain('<img src=x onerror=alert(1)> Fix images.');
        expect(turn.querySelectorAll('.aqt-turn__list li')).toHaveLength(2);
        expect(Array.from(turn.querySelectorAll('a')).map((link) => link.getAttribute('href'))).toEqual(['/typo3/module/content/a11y/page?x=1']);
        expect(document.querySelector('[data-aqt-ask-usage]').textContent).toBe('AI questions: 1 of 10 · $0.001 of $0.050');
        expect(money(366)).toBe('<$0.001');
        expect(document.querySelector('[data-aqt-ask-status]').textContent).toBe('');

        // The follow-up sends the earlier turn back (from the browser) and the server-signed id.
        fetchMock.mockResolvedValueOnce(json(200, aiAnswer({question: 'And then?'})));
        document.querySelector('[data-aqt-ask-input]').value = 'And then?';
        submit();
        await vi.waitFor(() => expect(document.querySelectorAll('.aqt-turn')).toHaveLength(2));
        const body = JSON.parse(fetchMock.mock.calls[2][1].body);
        expect(body.history).toEqual([{question: 'What first?', answer: 'Fix images.', token: 'd'.repeat(64)}], 'the server-signed history form, unchanged');
        expect(body.conversation).toMatch(/^v2\./);
    });

    it('keeps the conversation in sessionStorage only and clears it locally', async () => {
        await loaded();
        fetchMock.mockResolvedValueOnce(json(200, aiAnswer()));
        document.querySelector('[data-aqt-ask-input]').value = 'What first?';
        submit();
        await vi.waitFor(() => expect(document.querySelector('.aqt-turn')).not.toBeNull());
        expect(Object.keys({...window.sessionStorage}).sort()).toEqual(['aqg-toolbar-ask-principal', 'aqg-toolbar-ask:' + scope], 'one conversation under the server scope, plus the principal marker');

        // Reopening the dropdown restores the turns from this tab without asking again.
        await loaded();
        expect(document.querySelectorAll('.aqt-turn')).toHaveLength(1);
        const calls = fetchMock.mock.calls.length;
        document.querySelector('[data-aqt-ask-clear]').click();
        expect(fetchMock.mock.calls.length).toBe(calls, 'clearing needs no request: nothing is stored on the server');
        expect(document.querySelectorAll('.aqt-turn')).toHaveLength(0);
        expect(JSON.parse(window.sessionStorage.getItem('aqg-toolbar-ask:' + scope))).toEqual({conversation: '', turns: []});
    });

    it('refuses a too short question locally and explains server refusals', async () => {
        await loaded();
        document.querySelector('[data-aqt-ask-input]').value = 'ok';
        submit();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(document.querySelector('[data-aqt-ask-status]').textContent).toBe('Too short');
        expect(document.querySelector('[data-aqt-ask-input]').getAttribute('aria-invalid')).toBe('true');

        for (const [code, label] of [['scope_restricted', 'No site access'], ['busy', 'Busy'], ['conversation_limit', 'Conversation full']]) {
            document.querySelector('[data-aqt-ask-input]').value = 'How is the website?';
            fetchMock.mockResolvedValueOnce(json(code === 'scope_restricted' ? 403 : 429, {success: false, code}));
            submit();
            await vi.waitFor(() => expect(document.querySelector('[data-aqt-ask-status]').textContent).toBe(label));
        }
        expect(document.querySelector('[data-aqt-ask-clear]').hidden).toBe(false, 'a full conversation offers a new one');
        expect(document.querySelector('.aqt-turn')).toBeNull();
    });

    it('bounds the history it sends by the published limits', () => {
        const limits = {maxHistoryTurns: 3, maxHistoryAnswerChars: 700, maxHistoryChars: 3300, maxQuestionChars: 400, minQuestionChars: 3};
        const turns = Array.from({length: 5}, (unused, index) => ({source: 'ai', question: `Question ${index}?`, answer: 'a'.repeat(900), citations: [{detailUrl: '/x'}],
            history: {question: `Question ${index}?`, answer: 'a'.repeat(699) + '…', token: String(index).repeat(64)}}));

        const history = historyFor(turns, limits);

        expect(history.map((turn) => turn.question)).toEqual(['Question 2?', 'Question 3?', 'Question 4?']);
        expect(history.every((turn) => turn.answer.length <= 700 && Object.keys(turn).join() === 'question,answer,token')).toBe(true);
        expect(historyFor(turns, {...limits, maxHistoryChars: 800})).toHaveLength(1);
        expect(historyFor([{source: 'aqg', question: 'Where?', answer: 'Teaser "Secret project": Image', history: {question: 'Where?', answer: 'x', token: 'e'.repeat(64)}}, ...turns.slice(0, 1)], limits).map((turn) => turn.question)).toEqual(['Question 0?'], 'answers from the findings never go to the provider');
        expect(historyFor([{source: 'ai', question: 'Unsigned turn?', answer: 'Kept in the browser only.'}], limits)).toEqual([], 'a turn without the server token is never sent');
        expect(truncate('😀😀😀', 2)).toBe('😀…');
        expect(() => JSON.parse(JSON.stringify(truncate('a😀'.repeat(400), 699)))).not.toThrow();
        expect(askLimits({dataset: {limits: '{"maxHistoryTurns":2,"maxHistoryChars":"x"}'}}).maxHistoryTurns).toBe(2);
    });

    it('maps refusal codes and keeps model text as text', () => {
        expect(askErrorLabelKey('scope_restricted')).toBe('errorScope');
        expect(askErrorLabelKey('budget_reached')).toBe('errorBudget');
        expect(askErrorLabelKey('conversation_budget')).toBe('errorConversationBudget');
        expect(askErrorLabelKey('cancelled')).toBe('errorTimeout');
        expect(askErrorLabelKey('whatever')).toBe('error');
        const nodes = answerNodes('<b>x</b>\n- a\n• b\nend');
        expect(nodes.map((node) => node.tagName)).toEqual(['P', 'UL', 'P']);
        expect(nodes[0].innerHTML).toBe('&lt;b&gt;x&lt;/b&gt;');
        expect(safeBackendHref('/typo3/module/x')).toBe(true);
        expect(safeBackendHref('//evil.test/typo3/x')).toBe(false);
        expect(safeBackendHref('/other/x')).toBe(false);
    });
});
