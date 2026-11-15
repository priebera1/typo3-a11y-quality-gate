// @vitest-environment jsdom

import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {ToolbarAssistant, collectAssistantContext} from '../../Resources/Public/JavaScript/toolbar/a11y-toolbar-assistant.js';
import {clampWidth, DOCK_MAX_WIDTH, DOCK_MIN_WIDTH, MIN_WORKSPACE} from '../../Resources/Public/JavaScript/toolbar/ask-aqg-dock.js';

/**
 * Ask AQG docked beside the TYPO3 workspace: the same assistant root moves into a column inside .scaffold-content
 * (no overlay, no second conversation, no request), follows TYPO3 navigation with each context's own conversation,
 * resizes by keyboard, minimises without stopping anything, and falls back to the dialog where it does not fit.
 */
describe('Ask AQG dock', () => {
    let fetchMock;
    const limits = JSON.stringify({maxQuestionChars: 400, minQuestionChars: 3, maxHistoryTurns: 3, maxHistoryAnswerChars: 700, maxHistoryChars: 2400, maxSecondsPerQuestion: 45});
    const principal = 'a1'.repeat(20);
    const scopeA = 'c3'.repeat(20);
    const scopeB = 'd4'.repeat(20);
    const html = (scope) => `<section data-aqt-assistant-root="true" data-label-dock-minimize="Minimize" data-label-dock-close="Close panel" data-label-dock-resize="Resize Ask AQG panel">
        <h2 id="aqt-assistant-title">Ask AQG</h2><button type="button" data-aqt-expand="true">Expand</button>
        <section data-aqt-ask="true" data-ai="1" data-limits='${limits}' data-principal="${principal}" data-scope="${scope}">
            <div data-aqt-ask-log="true" role="log" hidden></div>
            <form data-aqt-ask-form="true"><input type="hidden" name="aqt-ask-scope" value="page">
                <textarea data-aqt-ask-input="true"></textarea><button type="submit" data-aqt-ask-submit="true">Ask</button>
                <button type="button" data-aqt-ask-clear="true" hidden>New conversation</button><p data-aqt-ask-usage="true" hidden></p><p data-aqt-ask-status="true"></p>
            </form>
        </section>
    </section>`;
    const json = (status, body) => ({ok: status < 400, status, redirected: false, headers: {get: () => 'application/json'}, json: async () => body});
    const plan = () => ({success: true, status: 'answer', source: 'aqg', intent: 'repair', question: 'What problems does this page have?', answer: '', conversation: {id: 'v2.c'},
        repair: {mode: 'list', target: 'page', targetLabel: 'Page: Kontakt', notices: [], alternatives: [], counts: {found: 1, manual: 1}, headline: '1 problem on this page.',
            freshness: {}, ai: {ready: false}, items: [{id: 5, number: 1, title: 'Heading levels are not sequential', state: 'manual', stateLabel: 'Needs manual work', tone: 'warning'}], prepareIds: [], selection: 's1.page.1.5.' + 'a'.repeat(64)}});
    const urls = {a11y_toolbar_assistant: '/typo3/ajax/a11y/toolbar/assistant', a11y_toolbar_ask: '/typo3/ajax/a11y/toolbar/ask?token=t'};

    beforeEach(() => {
        document.body.innerHTML = `<div class="scaffold"><div class="scaffold-content t3js-scaffold-content"><div class="scaffold-content-module"><div class="module-placeholder"></div></div></div></div>
            <div id="priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem"><button class="dropdown-toggle">AQG</button><div class="aqt-pop">
            <div id="slot" data-aqt-assistant data-label-dock-open-here="Ask AQG is open in the panel on the right." data-label-dock-show="Show panel" data-label-dock-back="Back to toolbar"></div></div></div>`;
        fetchMock = vi.fn();
        globalThis.fetch = fetchMock;
        window.sessionStorage.clear();
        window.innerWidth = 1440;
        vi.useFakeTimers({shouldAdvanceTime: true});
    });

    const instances = [];
    afterEach(() => {
        instances.splice(0).forEach((instance) => instance.dispose());
        vi.useRealTimers();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    const loaded = async (scope = scopeA) => {
        fetchMock.mockResolvedValueOnce({ok: true, text: async () => html(scope)});
        const instance = new ToolbarAssistant({win: window, slotSelector: '#slot', ajaxUrls: () => urls, navigate: vi.fn()});
        instances.push(instance);
        await instance.load();
        return instance;
    };
    const askList = async () => {
        fetchMock.mockResolvedValueOnce(json(200, plan()));
        document.querySelector('[data-aqt-ask-input]').value = 'What problems does this page have?';
        document.querySelector('[data-aqt-ask-form]').dispatchEvent(new Event('submit', {cancelable: true}));
        await vi.waitFor(() => expect(document.querySelectorAll('[data-aqt-repair]')).toHaveLength(1));
    };

    it('moves the one conversation into a column of the workspace, without a request or an overlay', async () => {
        const instance = await loaded();
        await askList();
        const root = document.querySelector('[data-aqt-assistant-root]');
        const log = root.querySelector('[data-aqt-ask-log]');
        const calls = fetchMock.mock.calls.length;

        root.querySelector('[data-aqt-expand]').click();

        const container = document.querySelector('.scaffold-content');
        const dock = container.lastElementChild;
        expect(dock.matches('aside.aqt-dock')).toBe(true);
        expect(container.classList.contains('aqt-docked')).toBe(true);
        expect(dock.contains(root)).toBe(true);
        expect(root.querySelector('[data-aqt-ask-log]')).toBe(log);
        expect(document.querySelector('dialog[open]')).toBeNull();
        expect(instance.presentation).toBe('dock');
        expect(root.querySelector('[data-aqt-expand]').hidden).toBe(true);
        expect(fetchMock.mock.calls.length).toBe(calls);
        // The dropdown names where the assistant is instead of rendering a second copy.
        expect(document.querySelector('#slot [data-aqt-docked-note]').textContent).toContain('open in the panel on the right');
        await instance.load();
        expect(fetchMock.mock.calls.length).toBe(calls);
    });

    it('follows TYPO3 navigation with the context\'s own conversation and keeps the same context\'s live plan', async () => {
        const instance = await loaded();
        await askList();
        document.querySelector('[data-aqt-expand]').click();
        const log = document.querySelector('.aqt-dock [data-aqt-ask-log]');

        // A Save of the same record: same scope, the live conversation node stays.
        fetchMock.mockResolvedValueOnce({ok: true, text: async () => html(scopeA)});
        document.dispatchEvent(new CustomEvent('typo3-module-loaded'));
        await vi.advanceTimersByTimeAsync(400);
        await vi.waitFor(() => expect(fetchMock.mock.calls.at(-1)[0]).toContain('/typo3/ajax/a11y/toolbar/assistant'));
        await vi.waitFor(() => expect(document.querySelector('.aqt-dock [data-aqt-ask-log]')).toBe(log), {timeout: 2000});
        expect(log.querySelectorAll('[data-aqt-repair]')).toHaveLength(1);

        // Another page: its own (empty) conversation; the previous page's plan is not on screen.
        fetchMock.mockResolvedValueOnce({ok: true, text: async () => html(scopeB)});
        document.dispatchEvent(new CustomEvent('typo3-module-loaded'));
        await vi.advanceTimersByTimeAsync(400);
        await vi.waitFor(() => expect(document.querySelector('.aqt-dock [data-aqt-ask]').dataset.scope).toBe(scopeB));
        expect(document.querySelectorAll('.aqt-dock [data-aqt-repair]')).toHaveLength(0);
        expect(instance.presentation).toBe('dock');
    });

    it('resizes by keyboard within the limits, minimises without removing anything and closes back to the toolbar', async () => {
        const instance = await loaded();
        await askList();
        document.querySelector('[data-aqt-expand]').click();
        const separator = document.querySelector('[data-aqt-dock-resizer]');
        expect(separator.getAttribute('role')).toBe('separator');
        const start = Number(separator.getAttribute('aria-valuenow'));
        separator.dispatchEvent(new KeyboardEvent('keydown', {key: 'ArrowLeft', bubbles: true}));
        expect(Number(separator.getAttribute('aria-valuenow'))).toBe(start + 16);
        separator.dispatchEvent(new KeyboardEvent('keydown', {key: 'Home', bubbles: true}));
        expect(Number(separator.getAttribute('aria-valuenow'))).toBe(DOCK_MIN_WIDTH);
        expect(document.querySelector('.scaffold-content').style.getPropertyValue('--aqt-dock-width')).toBe(`${DOCK_MIN_WIDTH}px`);
        // Remembered per principal (user + login session) only.
        expect(JSON.parse(window.sessionStorage.getItem('aqg-ask-dock:' + principal)).width).toBe(DOCK_MIN_WIDTH);

        document.querySelector('[data-aqt-dock-minimize]').click();
        expect(document.querySelector('.aqt-dock__body').hidden).toBe(true);
        expect(document.querySelectorAll('.aqt-dock [data-aqt-repair]')).toHaveLength(1);
        expect(document.activeElement).toBe(document.querySelector('[data-aqt-dock-restore]'));
        document.querySelector('[data-aqt-dock-restore]').click();
        expect(document.querySelector('.aqt-dock__body').hidden).toBe(false);

        document.querySelector('[data-aqt-dock-close]').click();
        expect(document.querySelector('.aqt-dock')).toBeNull();
        expect(document.querySelector('.scaffold-content').classList.contains('aqt-docked')).toBe(false);
        expect(document.querySelector('#slot [data-aqt-assistant-root] [data-aqt-repair]')).not.toBeNull();
        expect(instance.presentation).toBe('toolbar');
        expect(document.activeElement).toBe(document.querySelector('.dropdown-toggle'));
    });

    it('uses the dialog where the dock does not fit', async () => {
        if (!HTMLDialogElement.prototype.showModal) {
            HTMLDialogElement.prototype.showModal = function showModal() { this.setAttribute('open', ''); };
        }
        window.innerWidth = 900;
        const instance = await loaded();
        document.querySelector('[data-aqt-expand]').click();
        expect(document.querySelector('.aqt-dock')).toBeNull();
        expect(document.querySelector('dialog[data-aqt-dialog]').hasAttribute('open')).toBe(true);
        expect(instance.presentation).toBe('toolbar');
    });

    it('a dropdown refresh still on its way when the assistant moves into the dock never takes the conversation away from it', async () => {
        const instance = await loaded();
        await askList();
        // The dropdown opens again: its refresh is on its way while the current assistant stays clickable.
        let answer;
        fetchMock.mockImplementationOnce(() => new Promise((resolve) => { answer = resolve; }));
        const refresh = instance.load();
        await vi.advanceTimersByTimeAsync(100);
        document.querySelector('[data-aqt-expand]').click();
        expect(instance.presentation).toBe('dock');
        answer({ok: true, text: async () => html(scopeA)});
        await refresh;
        await vi.advanceTimersByTimeAsync(50);

        expect(document.querySelectorAll('[data-aqt-assistant-root]')).toHaveLength(1);
        const docked = document.querySelector('.aqt-dock [data-aqt-assistant-root]');
        expect(instance.root).toBe(docked);
        expect(docked.querySelectorAll('[data-aqt-repair]')).toHaveLength(1);
        expect(document.querySelector('#slot [data-aqt-docked-note]')).not.toBeNull();
    });

    it('the same holds for the dialog fallback', async () => {
        if (!HTMLDialogElement.prototype.showModal) {
            HTMLDialogElement.prototype.showModal = function showModal() { this.setAttribute('open', ''); };
        }
        window.innerWidth = 900;
        const instance = await loaded();
        await askList();
        let answer;
        fetchMock.mockImplementationOnce(() => new Promise((resolve) => { answer = resolve; }));
        const refresh = instance.load();
        await vi.advanceTimersByTimeAsync(100);
        document.querySelector('[data-aqt-expand]').click();
        answer({ok: true, text: async () => html(scopeA)});
        await refresh;
        await vi.advanceTimersByTimeAsync(50);

        expect(document.querySelectorAll('[data-aqt-assistant-root]')).toHaveLength(1);
        expect(instance.root).toBe(document.querySelector('dialog[data-aqt-dialog] [data-aqt-assistant-root]'));
        expect(instance.root.querySelectorAll('[data-aqt-repair]')).toHaveLength(1);
    });

    it('keeps the docked layout (scrolling conversation, composer in the column) after a TYPO3 navigation', async () => {
        await loaded();
        document.querySelector('[data-aqt-expand]').click();
        fetchMock.mockResolvedValueOnce({ok: true, text: async () => html(scopeB)});
        document.dispatchEvent(new CustomEvent('typo3-module-loaded'));
        await vi.advanceTimersByTimeAsync(400);
        await vi.waitFor(() => expect(document.querySelector('.aqt-dock [data-aqt-ask]').dataset.scope).toBe(scopeB));
        const root = document.querySelector('.aqt-dock [data-aqt-assistant-root]');
        expect(root.classList.contains('aqt-assistant--docked')).toBe(true);
        expect(root.classList.contains('aqt-assistant--expanded')).toBe(true);
    });

    describe('room for the TYPO3 module', () => {
        // Layout stub: the page tree is not part of the room; only the module column (+ the dock) is.
        const layout = ({module, dock = null}) => {
            const rect = (width) => ({width, height: 800, top: 0, left: 0, right: width, bottom: 800, x: 0, y: 0});
            const original = Element.prototype.getBoundingClientRect;
            vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function measure() {
                if (this.matches('.scaffold-content-module')) {
                    return rect(typeof module === 'function' ? module() : module);
                }
                if (this.matches('.aqt-dock')) {
                    return rect(typeof dock === 'function' ? dock() : (dock ?? 0));
                }
                if (this.matches('.scaffold-content')) {
                    return rect(1200);
                }
                return original.call(this);
            });
        };
        const showModal = () => {
            if (!HTMLDialogElement.prototype.showModal) {
                HTMLDialogElement.prototype.showModal = function showModal() { this.setAttribute('open', ''); };
            }
        };

        it('measures the module column, not tree + module: a wide window with an open page tree falls back to the dialog', async () => {
            showModal();
            // 1280 px, 240 px module menu, 300 px page tree: the module column has 740 px.
            window.innerWidth = 1280;
            layout({module: 740});
            const instance = await loaded();
            document.querySelector('[data-aqt-expand]').click();
            expect(document.querySelector('.aqt-dock')).toBeNull();
            expect(document.querySelector('dialog[data-aqt-dialog]').hasAttribute('open')).toBe(true);
            expect(instance.presentation).toBe('toolbar');
        });

        it('docks beside a module column that keeps MIN_WORKSPACE and clamps the dock to the rest', async () => {
            window.innerWidth = 1440;
            // 1440 px with the page tree open: 900 px for module + dock.
            let dockWidth = 0;
            layout({module: () => 900 - dockWidth, dock: () => dockWidth});
            await loaded();
            document.querySelector('[data-aqt-expand]').click();
            const container = document.querySelector('.scaffold-content');
            expect(document.querySelector('.aqt-dock')).not.toBeNull();
            const applied = parseInt(container.style.getPropertyValue('--aqt-dock-width'), 10);
            expect(applied).toBe(900 - MIN_WORKSPACE);
            dockWidth = applied;
            // The person's preferred width is kept for a wider layout later.
            expect(JSON.parse(window.sessionStorage.getItem('aqg-ask-dock:' + principal)).width).toBe(420);
        });

        it('gives way when the page tree takes the room, and the rail opens the dialog instead of a squeezed panel', async () => {
            showModal();
            window.innerWidth = 1600;
            let shared = 1060;
            let dockWidth = 0;
            layout({module: () => shared - dockWidth, dock: () => dockWidth});
            const instance = await loaded();
            await askList();
            document.querySelector('[data-aqt-expand]').click();
            dockWidth = 420;
            expect(document.querySelector('.aqt-dock--minimized')).toBeNull();

            // The page tree is widened (no window resize): only 760 px remain for module + dock.
            shared = 760;
            instance.dock.fit();
            expect(document.querySelector('.aqt-dock--minimized')).not.toBeNull();
            dockWidth = 44;
            expect(document.querySelectorAll('.aqt-dock [data-aqt-repair]')).toHaveLength(1);

            document.querySelector('[data-aqt-dock-restore]').click();
            expect(document.querySelector('.aqt-dock')).toBeNull();
            const dialog = document.querySelector('dialog[data-aqt-dialog]');
            expect(dialog.hasAttribute('open')).toBe(true);
            // The same conversation, not a new one.
            expect(dialog.querySelectorAll('[data-aqt-repair]')).toHaveLength(1);
        });
    });

    it('never gives TYPO3 less than its minimum workspace', () => {
        expect(clampWidth(9999, 1440 - 240)).toBe(Math.min(DOCK_MAX_WIDTH, 1200 - MIN_WORKSPACE));
        expect(clampWidth(100, 1200)).toBe(DOCK_MIN_WIDTH);
        expect(clampWidth('x', 1200)).toBe(420);
    });
    it('reads the Page module language of TYPO3 14 (languages[]) and asks the server for the kept one when the URL has none', () => {
        const frame = (href) => ({location: {href}, document: document.implementation.createHTMLDocument('')});
        expect(collectAssistantContext(frame('https://example.org/typo3/module/web/layout?token=x&id=3&languages%5B0%5D=1'))).toEqual({id: '3', language: '1'});
        expect(collectAssistantContext(frame('https://example.org/typo3/module/web/layout?token=x&id=3&languages%5B0%5D=0&languages%5B1%5D=2'))).toEqual({id: '3', language: '2'});
        expect(collectAssistantContext(frame('https://example.org/typo3/module/web/layout?token=x&id=3&languages%5B0%5D=1&languages%5B1%5D=2'))).toEqual({id: '3', language: '0'});
        expect(collectAssistantContext(frame('https://example.org/typo3/module/web/layout?token=x&id=3'))).toEqual({id: '3', module: 'web_layout'});
        // An explicit language wins, and other modules never send the hint.
        expect(collectAssistantContext(frame('https://example.org/typo3/module/web/layout?token=x&id=3&language=0'))).toEqual({id: '3', language: '0'});
        expect(collectAssistantContext(frame('https://example.org/typo3/module/web/list?token=x&id=3'))).toEqual({id: '3'});
    });

    it('reads the page of a repeated id as TYPO3 does (the last one), so a docked plan never names the previous page', () => {
        const frame = {location: {href: 'https://example.org/typo3/module/web/layout?token=x&id=4&id=16&language=0&language=1'}, document: document.implementation.createHTMLDocument('')};
        expect(collectAssistantContext(frame)).toEqual({id: '16', language: '1'});
    });
});
