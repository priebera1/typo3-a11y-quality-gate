// @vitest-environment jsdom

/*
 * PI-01 / PI-02 / PI-04 (AQG 3.0 post-integration review): "Ask AQG" history never crosses backend users, login
 * sessions or contexts, and "Check saved page now" always targets the latest offered page. The SOL reproductions are
 * repeated here against the server-provided opaque scope (data-principal / data-scope of the Ask section).
 */
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {ASK_PRINCIPAL_KEY, ASK_STORAGE_PREFIX, ToolbarAssistant} from '../../Resources/Public/JavaScript/toolbar/a11y-toolbar-assistant.js';

const ADMIN = 'a0'.repeat(20);
const EDITOR = 'e0'.repeat(20);
const ADMIN_RELOGIN = 'a9'.repeat(20);
const scopeOf = (principal, page) => (principal.slice(0, 2) + String(page).padStart(2, '0')).repeat(10);
const limits = JSON.stringify({maxQuestionChars: 400, minQuestionChars: 3, maxHistoryTurns: 3, maxHistoryAnswerChars: 700, maxHistoryChars: 2400, maxSecondsPerQuestion: 45});

const view = (principal, scope) => `<section data-aqt-assistant-root="true" data-label-you-asked="You asked" data-label-answer="AQG answer" data-label-sources="Findings used" data-label-most-on="most on %s" data-label-occurrences="%d occurrences" data-label-error="Error">
    <section data-aqt-ask="true" data-ai="1" data-limits='${limits}' data-principal="${principal}" data-scope="${scope}">
        <div data-aqt-ask-log="true" role="log" hidden></div>
        <form data-aqt-ask-form="true">
            <textarea data-aqt-ask-input="true"></textarea><button type="submit" data-aqt-ask-submit="true">Ask</button>
            <button type="button" data-aqt-ask-clear="true" hidden>New conversation</button>
            <p data-aqt-ask-usage="true" hidden></p><p data-aqt-ask-status="true" role="status"></p>
        </form>
    </section>
</section>`;
const json = (body) => ({ok: true, status: 200, redirected: false, headers: {get: () => 'application/json'}, json: async () => body});
const aiAnswer = (question, answer, extra = {}) => ({
    success: true, status: 'answer', source: 'ai', question, answer, citations: [], notes: [], sent: [],
    conversation: {id: 'v2.' + 'a'.repeat(24) + '.1.' + 'b'.repeat(64), aiQuestions: 1, maxAiQuestions: 10, spentMicroUsd: 0, costUnknown: true, maxCostMicroUsd: 50000, limitReached: false},
    history: {question, answer, token: 'f'.repeat(64)},
    ...extra,
});

describe('Ask AQG conversation isolation', () => {
    let fetchMock;
    const instance = (win = window) => new ToolbarAssistant({
        win,
        slotSelector: '#slot',
        ajaxUrls: () => ({a11y_toolbar_assistant: '/typo3/ajax/a11y/toolbar/assistant', a11y_toolbar_ask: '/typo3/ajax/a11y/toolbar/ask'}),
    });
    const open = async (assistant, principal, scope) => {
        fetchMock.mockResolvedValueOnce({ok: true, text: async () => view(principal, scope)});
        await assistant.load();
    };
    const ask = async (question, response) => {
        fetchMock.mockResolvedValueOnce(json(response));
        document.querySelector('[data-aqt-ask-input]').value = question;
        document.querySelector('[data-aqt-ask-form]').dispatchEvent(new Event('submit', {cancelable: true}));
        await vi.waitFor(() => expect(document.querySelector('[data-aqt-ask-input]').value).toBe(''));
        return JSON.parse(fetchMock.mock.calls.at(-1)[1].body);
    };
    const lastBody = () => JSON.parse(fetchMock.mock.calls.at(-1)[1].body);
    const turns = () => Array.from(document.querySelectorAll('.aqt-turn')).map((turn) => turn.textContent);

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

    it('positive: the same user and login keep their conversation of this context across reopening and new instances', async () => {
        const first = instance();
        await open(first, ADMIN, scopeOf(ADMIN, 2));
        await ask('QA TEST QUESTION', aiAnswer('QA TEST QUESTION', 'QA TEST ANSWER'));

        await open(instance(), ADMIN, scopeOf(ADMIN, 2));
        expect(turns()).toHaveLength(1);
        expect(turns()[0]).toContain('QA TEST ANSWER');
    });

    it('refusal (PI-01): after logout/login as another user the previous answer, incl. a refused page title, is not replayed', async () => {
        const admin = instance();
        await open(admin, ADMIN, scopeOf(ADMIN, 2));
        await ask('Which pages need work?', aiAnswer('Which pages need work?', 'Start on QA TEST REFUSED.', {
            citations: [{ref: 'F1', title: 'Image without alt', tone: 'critical', severityLabel: 'Critical', count: 2, pageTitle: 'QA TEST REFUSED', detailUrl: '/typo3/x'}],
        }));
        expect(document.querySelector('.aqt-turn').textContent).toContain('QA TEST REFUSED');

        // The tab reloads into the login form and back: a new toolbar instance, the editor's principal, same page.
        document.body.innerHTML = '<div id="slot" data-aqt-assistant></div>';
        await open(instance(), EDITOR, scopeOf(EDITOR, 2));
        expect(turns()).toEqual([]);
        expect(document.body.textContent).not.toContain('QA TEST REFUSED');
        expect(Object.keys({...window.sessionStorage})).toEqual([ASK_PRINCIPAL_KEY], 'the administrator\'s stored conversations are gone');
        expect(window.sessionStorage.getItem(ASK_PRINCIPAL_KEY)).toBe(EDITOR);

        const body = await ask('What should I do here?', aiAnswer('What should I do here?', 'Fresh editor answer.'));
        expect([body.history, body.conversation]).toEqual([[], ''], 'nothing of the administrator is sent either');
    });

    it('refusal (PI-01): an already mounted toolbar drops its in-memory conversations when the principal changes', async () => {
        const assistant = instance();
        await open(assistant, ADMIN, scopeOf(ADMIN, 2));
        await ask('QA TEST ADMIN PRIVATE', aiAnswer('QA TEST ADMIN PRIVATE', 'QA TEST PRIVATE ANSWER'));
        expect(assistant.memoryConversations.size).toBe(1);

        // Even a forged scope equal to the admin's cannot read it once the principal changed.
        await open(assistant, EDITOR, scopeOf(ADMIN, 2));
        expect(assistant.memoryConversations.size).toBe(0);
        expect(turns()).toEqual([]);
        expect(assistant.storedConversation().turns).toEqual([]);
    });

    it('refusal (PI-01): the same user in a new login session starts without the earlier session\'s history', async () => {
        await open(instance(), ADMIN, scopeOf(ADMIN, 2));
        await ask('QA TEST BEFORE LOGOUT', aiAnswer('QA TEST BEFORE LOGOUT', 'Earlier answer.'));

        document.body.innerHTML = '<div id="slot" data-aqt-assistant></div>';
        await open(instance(), ADMIN_RELOGIN, scopeOf(ADMIN_RELOGIN, 2));
        expect(turns()).toEqual([]);
    });

    it('refusal (PI-02): a new page without history starts empty and its POST carries no earlier history', async () => {
        const assistant = instance();
        await open(assistant, ADMIN, scopeOf(ADMIN, 2));
        await ask('QA TEST SITE A PRIVATE', aiAnswer('QA TEST SITE A PRIVATE', 'QA TEST PRIVATE ANSWER'));

        await open(assistant, ADMIN, scopeOf(ADMIN, 3));
        expect(turns()).toEqual([]);
        expect(assistant.storedConversation().turns).toEqual([]);
        await ask('QA TEST SITE B QUESTION', aiAnswer('QA TEST SITE B QUESTION', 'Answer B.'));
        expect(lastBody().history).toEqual([]);
        expect(lastBody().conversation).toBe('');

        // Positive: back on page 2, its own conversation continues (same-context follow-up keeps its signed history).
        await open(assistant, ADMIN, scopeOf(ADMIN, 2));
        expect(turns()).toHaveLength(1);
        await ask('And then?', aiAnswer('And then?', 'Next.'));
        expect(lastBody().history.map((turn) => turn.question)).toEqual(['QA TEST SITE A PRIVATE']);
        expect(lastBody().conversation).toMatch(/^v2\./);
    });

    it('refusal (PI-02): without sessionStorage the in-memory fallback is kept per scope, never reused for another one', async () => {
        const storage = window.sessionStorage;
        Object.defineProperty(window, 'sessionStorage', {configurable: true, get: () => {
            throw new DOMException('blocked', 'SecurityError');
        }});
        try {
            const assistant = instance();
            await open(assistant, ADMIN, scopeOf(ADMIN, 2));
            await ask('QA TEST MEMORY ONLY', aiAnswer('QA TEST MEMORY ONLY', 'Memory answer.'));

            await open(assistant, ADMIN, scopeOf(ADMIN, 3));
            expect(turns()).toEqual([]);
            await ask('QA TEST OTHER PAGE', aiAnswer('QA TEST OTHER PAGE', 'Other.'));
            expect(lastBody().history).toEqual([]);

            await open(assistant, ADMIN, scopeOf(ADMIN, 2));
            expect(turns()).toHaveLength(1, 'the same scope still continues from memory');
            await open(assistant, EDITOR, scopeOf(EDITOR, 2));
            expect(turns()).toEqual([]);
            expect(assistant.memoryConversations.size).toBe(0);
        } finally {
            Object.defineProperty(window, 'sessionStorage', {configurable: true, value: storage});
        }
    });

    it('refusal (PI-02): a view without a server scope neither replays nor stores anything; unsigned turns are never sent', async () => {
        const assistant = instance();
        window.sessionStorage.setItem(ASK_PRINCIPAL_KEY, ADMIN);
        window.sessionStorage.setItem(ASK_STORAGE_PREFIX + scopeOf(ADMIN, 2), JSON.stringify({conversation: 'v2.x', turns: [{source: 'ai', question: 'QA TEST INJECTED', answer: 'Injected.'}]}));
        await open(assistant, '', '');
        expect(turns()).toEqual([]);
        expect(assistant.storedConversation().turns).toEqual([]);

        await open(assistant, ADMIN, scopeOf(ADMIN, 2));
        expect(turns()).toHaveLength(1, 'a turn stored for this scope is shown');
        await ask('Follow-up?', aiAnswer('Follow-up?', 'x'));
        expect(lastBody().history).toEqual([], 'but without the server token it is never sent');
    });

    it('a tab without a principal marker (stored before this fix, unkeyed) drops every earlier conversation', async () => {
        window.sessionStorage.setItem(ASK_STORAGE_PREFIX + '[["id","2"],["language","0"]]', JSON.stringify({conversation: 'v1.x', turns: [{source: 'ai', question: 'QA TEST LEGACY', answer: 'Legacy.'}]}));
        await open(instance(), ADMIN, scopeOf(ADMIN, 2));
        expect(turns()).toEqual([]);
        expect(Object.keys({...window.sessionStorage})).toEqual([ASK_PRINCIPAL_KEY]);
    });

    it('a question asked on a view that is still on screen while the next one loads keeps that view\'s context and scope', async () => {
        const assistant = instance();
        await open(assistant, ADMIN, scopeOf(ADMIN, 2));
        assistant.context = {id: '2', language: '0'};
        const form = document.querySelector('[data-aqt-ask-form]');
        assistant.askViews.set(form, {context: {id: '2', language: '0'}, scope: scopeOf(ADMIN, 2)});
        // load() of page 3 has started: the context moved, the old view is still clickable.
        assistant.context = {id: '3', language: '0'};
        await ask('Question on the old view?', aiAnswer('Question on the old view?', 'Answer about page 2.'));
        expect(lastBody().context).toEqual({id: '2', language: '0'});
        expect(assistant.storedConversation(scopeOf(ADMIN, 2)).turns).toHaveLength(1);
    });

    it('stores an answer under the scope of the view the question was asked in, even after moving on', async () => {
        const assistant = instance();
        await open(assistant, ADMIN, scopeOf(ADMIN, 2));
        let release;
        fetchMock.mockReturnValueOnce(new Promise((resolve) => {
            release = resolve;
        }));
        document.querySelector('[data-aqt-ask-input]').value = 'Slow question?';
        document.querySelector('[data-aqt-ask-form]').dispatchEvent(new Event('submit', {cancelable: true}));
        assistant.setConversationScope(ADMIN, scopeOf(ADMIN, 3));
        release(json(aiAnswer('Slow question?', 'Late answer.')));
        await vi.waitFor(() => expect(assistant.storedConversation(scopeOf(ADMIN, 2)).turns).toHaveLength(1));
        expect(assistant.storedConversation(scopeOf(ADMIN, 3)).turns).toEqual([]);
    });
});

describe('Check saved page now while navigating (PI-04)', () => {
    const setup = () => {
        const frame = document.createElement('iframe');
        document.body.append(frame);
        const win = frame.contentWindow;
        win.document.body.innerHTML = '<div class="aqt-pop"><div data-aqt-assistant></div><div data-aqt-content-scan data-label-finished="Done" data-label-scanning="Checking" data-label-error="Error" data-label-error-permission="No permission"><button data-aqt-content-scan-button>Check saved page now</button><p data-aqt-content-scan-status></p></div></div>';
        const onScanFinished = vi.fn();
        const assistant = new ToolbarAssistant({win, slotSelector: '[data-aqt-assistant]', ajaxUrls: () => ({a11y_scan_page: '/typo3/ajax/a11y/scan/page'}), onScanFinished});
        return {assistant, win, onScanFinished, button: win.document.querySelector('button'), status: win.document.querySelector('[data-aqt-content-scan-status]')};
    };
    const ok = () => ({ok: true, status: 200, json: async () => ({success: true})});
    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('positive: an idle context change targets the newly offered page', () => {
        const {assistant, button} = setup();
        assistant.setContentScanOffer(42, 0);
        assistant.setContentScanOffer(43, 1);
        expect([button.dataset.pageUid, button.dataset.languageUid]).toEqual(['43', '1']);
    });

    it('refusal: navigating while a scan runs makes the next click target the new page, without the old result', async () => {
        const {assistant, button, status, onScanFinished} = setup();
        let finish;
        const fetchMock = vi.fn().mockImplementationOnce(() => new Promise((resolve) => {
            finish = resolve;
        })).mockResolvedValue(ok());
        vi.stubGlobal('fetch', fetchMock);

        assistant.setContentScanOffer(42, 0);
        button.focus();
        const running = assistant.contentScan(button);
        expect(button.getAttribute('aria-busy')).toBe('true');
        assistant.setContentScanOffer(43, 0);
        expect(button.dataset.pageUid).toBe('43', 'the offer applies while the scan runs');
        expect(button.getAttribute('aria-busy')).toBe('true', 'loading state is kept until the running scan ends');
        finish(ok());
        await running;

        expect(status.textContent).toBe('', 'page 42\'s completion is not shown as page 43\'s result');
        expect(status.hidden).toBe(true);
        expect(button.hasAttribute('aria-busy')).toBe(false);
        expect(onScanFinished.mock.calls.at(-1)).toEqual([{contentScan: true, refocus: false}]); // a stale completion never moves focus to the new page

        await assistant.contentScan(button);
        expect(String(fetchMock.mock.calls[0][1].body)).toBe('pageUid=42&languageUid=0');
        expect(String(fetchMock.mock.calls[1][1].body)).toBe('pageUid=43&languageUid=0');
        expect(status.textContent).toBe('Done');
    });

    it('positive: the same page shows its own completion and stays the next target', async () => {
        const {assistant, button, status} = setup();
        const fetchMock = vi.fn().mockResolvedValue(ok());
        vi.stubGlobal('fetch', fetchMock);
        assistant.setContentScanOffer(42, 0);
        button.focus();
        await assistant.contentScan(button);
        assistant.setContentScanOffer(42, 0);
        expect(status.textContent).toBe('Done');
        expect(button.ownerDocument.activeElement).toBe(button);
        await assistant.contentScan(button);
        expect(fetchMock.mock.calls.map((call) => String(call[1].body))).toEqual(['pageUid=42&languageUid=0', 'pageUid=42&languageUid=0']);
    });

    it('control: a refused scan (no Scan now / edit access on the server) shows the permission error for that page', async () => {
        const {assistant, button, status} = setup();
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ok: false, status: 403, json: async () => ({success: false, code: 'forbidden'})}));
        assistant.setContentScanOffer(42, 0);
        await assistant.contentScan(button);
        expect(status.textContent).toBe('No permission');
        expect(status.className).toContain('aqt-scan__action-status--error');
        expect(button.hasAttribute('aria-busy')).toBe(false);
    });

    it('a page that is no longer offered is not scanned', async () => {
        const {assistant, button} = setup();
        const fetchMock = vi.fn().mockResolvedValue(ok());
        vi.stubGlobal('fetch', fetchMock);
        assistant.setContentScanOffer(42, 0);
        assistant.setContentScanOffer(0, 0);
        await assistant.contentScan(button);
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
