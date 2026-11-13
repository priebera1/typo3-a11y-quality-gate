// @vitest-environment jsdom
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {EditorAiSession} from '@priebera/a11y-quality-gate/editor-ai/editor-ai-session.js';
import {EditorAiReviewDialog} from '@priebera/a11y-quality-gate/editor-ai/editor-ai-review-dialog.js';

const issue = (overrides = {}) => ({
    fingerprint: 'live:1',
    ruleId: 'rte.non_descriptive_link',
    severity: 'warning',
    message: 'Link text "more" is not descriptive.',
    aiTarget: {
        ruleId: 'rte.non_descriptive_link', kind: 'link_text', operation: 'text', attribute: '', tag: 'a', occurrence: 1, total: 2,
        contextPath: 'p[2] > a', currentValue: 'more', unavailableReason: '', headingLevel: 0, targetHeadingLevel: 0,
        element: {tag: 'a', attributes: {href: '/k'}, text: 'more', hasChildElements: false},
    },
    ...overrides,
});

const config = {state: 'ready', rules: ['rte.non_descriptive_link', 'rte.img_alt_missing', 'rte.heading_hierarchy_jump'], provider: 'openai', providerLabel: 'OpenAI', model: 'gpt-5.4-mini', allowImages: true, linkText: true};

const adapter = (overrides = {}) => ({
    type: 'ckeditor',
    requestPayload: () => ({recordUid: 1, fieldName: 'bodytext', html: '<p>x</p>', ruleId: 'rte.non_descriptive_link', contextPath: 'p[2] > a', occurrence: 1}),
    lock: vi.fn(() => ({ok: true})),
    isStale: vi.fn(() => false),
    preview: (value) => ({kind: 'text', before: 'more', after: value}),
    apply: vi.fn(() => ({ok: true, expected: {tag: 'a', attributes: {href: '/k'}, text: 'x'}})),
    undo: vi.fn(() => true),
    release: vi.fn(),
    canRuleBasedFix: () => true,
    describeTarget: () => 'This link: “more”',
    ...overrides,
});

const respond = (status, body) => vi.fn(async () => new Response(JSON.stringify(body), {status, headers: {'Content-Type': 'application/json'}}));

const mount = (session) => {
    const container = document.createElement('div');
    document.body.appendChild(container);
    session.mount(container);
    return container;
};

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeEach(() => {
    globalThis.TYPO3 = {settings: {ajaxUrls: {a11y_ai_editor_fix: '/fix', a11y_ai_editor_explain: '/explain', a11y_ai_editor_verify: '/verify'}}};
    window.sessionStorage.clear();
});

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('editor AI session', () => {
    it('shows the wand only where a fix is offered', () => {
        expect(EditorAiSession.offersFix(issue(), config)).toBe(true);
        expect(EditorAiSession.offersFix(issue(), {...config, linkText: false})).toBe(false);
        expect(EditorAiSession.offersFix(issue(), {...config, state: 'not_configured'})).toBe(false);
        expect(EditorAiSession.offersFix({...issue(), aiTarget: {...issue().aiTarget, unavailableReason: 'nested_markup'}}, config)).toBe(false);
        expect(EditorAiSession.offersFix({...issue(), aiTarget: null}, config)).toBe(false);
    });

    it('renders model output as text, never as markup', async () => {
        globalThis.fetch = respond(200, {success: true, status: 'suggestion', value: '<img src=x onerror="window.pwned=1">', reason: '<b>why</b>', sent: ['linkText'], notSent: [], usage: {reported: true, inputTokens: 1, outputTokens: 2}});
        const session = new EditorAiSession({issue: issue(), adapter: adapter(), config, label: (k, f) => f});
        const container = mount(session);
        container.querySelector('[data-aqg-ai="fix"]').click();
        await vi.waitFor(() => expect(container.dataset.state).toBe('ready'));

        expect(container.querySelector('img')).toBeNull();
        expect(container.querySelector('b')).toBeNull();
        expect(container.querySelector('[data-aqg-ai-value]').value).toBe('<img src=x onerror="window.pwned=1">');
        expect(window.pwned).toBeUndefined();
        expect(container.textContent).toContain('Usage: 3 tokens');
    });

    it('keeps the editor untouched until Apply and offers Undo afterwards', async () => {
        globalThis.fetch = respond(200, {success: true, status: 'suggestion', value: 'Contact', reason: 'r', sent: [], notSent: []});
        const editorAdapter = adapter();
        const applied = vi.fn();
        const session = new EditorAiSession({issue: issue(), adapter: editorAdapter, config, label: (k, f) => f, onApplied: applied});
        const container = mount(session);
        container.querySelector('[data-aqg-ai="fix"]').click();
        await vi.waitFor(() => expect(container.dataset.state).toBe('ready'));
        expect(editorAdapter.apply).not.toHaveBeenCalled();

        const input = container.querySelector('[data-aqg-ai-value]');
        input.value = 'Contact our service team';
        input.dispatchEvent(new Event('input', {bubbles: true}));
        container.querySelector('[data-aqg-ai="apply"]').click();

        expect(editorAdapter.apply).toHaveBeenCalledWith('Contact our service team');
        expect(applied).toHaveBeenCalledOnce();
        expect(container.dataset.state).toBe('applied');
        expect(container.textContent).toContain('not saved yet');
        container.querySelector('[data-aqg-ai="undo"]').click();
        expect(editorAdapter.undo).toHaveBeenCalledOnce();
        expect(container.dataset.state).toBe('undone');
    });

    it('shows needs review without any Apply', async () => {
        globalThis.fetch = respond(200, {success: true, status: 'needs_review', value: '', reason: 'The destination is unknown.'});
        const session = new EditorAiSession({issue: issue(), adapter: adapter(), config, label: (k, f) => f});
        const container = mount(session);
        container.querySelector('[data-aqg-ai="fix"]').click();
        await vi.waitFor(() => expect(container.dataset.state).toBe('needs_review'));

        expect(container.querySelector('[data-aqg-ai="apply"]')).toBeNull();
        expect(container.textContent).toContain('The destination is unknown.');
    });

    it('outdates a suggestion when its target was edited and never applies it', async () => {
        globalThis.fetch = respond(200, {success: true, status: 'suggestion', value: 'Contact', reason: 'r'});
        let stale = false;
        const editorAdapter = adapter({isStale: vi.fn(() => stale)});
        const session = new EditorAiSession({issue: issue(), adapter: editorAdapter, config, label: (k, f) => f});
        const container = mount(session);
        container.querySelector('[data-aqg-ai="fix"]').click();
        await vi.waitFor(() => expect(container.dataset.state).toBe('ready'));

        stale = true;
        session.checkStale();
        expect(container.dataset.state).toBe('stale');
        expect(container.querySelector('[data-aqg-ai="apply"]').disabled).toBe(true);
        session.apply();
        expect(editorAdapter.apply).not.toHaveBeenCalled();
    });

    it('refuses a suggestion the server made for another element', async () => {
        globalThis.fetch = respond(200, {success: true, status: 'suggestion', value: 'Contact', reason: 'r', target: {...issue().aiTarget, occurrence: 0}});
        const editorAdapter = adapter();
        const session = new EditorAiSession({issue: issue(), adapter: editorAdapter, config, label: (k, f) => f});
        const container = mount(session);
        container.querySelector('[data-aqg-ai="fix"]').click();
        await vi.waitFor(() => expect(container.dataset.state).toBe('unavailable'));
        expect(container.querySelector('[data-aqg-ai="apply"]')).toBeNull();
    });

    it('outdates a shown suggestion when its finding now describes another element', async () => {
        globalThis.fetch = respond(200, {success: true, status: 'suggestion', value: 'Contact', reason: 'r', target: issue().aiTarget});
        const session = new EditorAiSession({issue: issue(), adapter: adapter(), config, label: (k, f) => f});
        const container = mount(session);
        container.querySelector('[data-aqg-ai="fix"]').click();
        await vi.waitFor(() => expect(container.dataset.state).toBe('ready'));

        // An identical link was inserted before it: the same finding key now names occurrence 1 of 3.
        session.updateIssue(issue({aiTarget: {...issue().aiTarget, total: 3}}));
        expect(container.dataset.state).toBe('stale');
        expect(container.querySelector('[data-aqg-ai="apply"]').disabled).toBe(true);
    });

    it('reports a target it cannot prove instead of requesting a suggestion', () => {
        globalThis.fetch = vi.fn();
        const session = new EditorAiSession({issue: issue(), adapter: adapter({lock: () => ({ok: false, reason: 'count_mismatch'})}), config, label: (k, f) => f});
        const container = mount(session);
        container.querySelector('[data-aqg-ai="fix"]').click();

        expect(globalThis.fetch).not.toHaveBeenCalled();
        expect(container.dataset.state).toBe('unavailable');
    });

    it('counts down a rate limit before Try again', async () => {
        globalThis.fetch = respond(429, {success: false, code: 'rate_limited', retryAfter: 40});
        const session = new EditorAiSession({issue: issue(), adapter: adapter(), config, label: (k, f) => f});
        const container = mount(session);
        container.querySelector('[data-aqg-ai="fix"]').click();
        await vi.waitFor(() => expect(container.dataset.state).toBe('error'));

        expect(container.textContent).toMatch(/in (39|40) seconds/);
        expect(container.querySelector('[data-aqg-ai="regenerate"]')).toBeNull();
        session.destroy();
    });

    it('shows admins the admin hint and editors no settings link when AI is paused', async () => {
        globalThis.fetch = respond(422, {success: false, code: 'paused'});
        const session = new EditorAiSession({issue: issue(), adapter: adapter(), config: {...config, isAdmin: false}, label: (k, f) => f});
        const container = mount(session);
        container.querySelector('[data-aqg-ai="fix"]').click();
        await vi.waitFor(() => expect(container.dataset.state).toBe('error'));
        expect(container.textContent).toContain('confirmed again by an administrator');
        expect(container.querySelector('a')).toBeNull();
    });

    it('offers the rule-based heading fix without an AI request', () => {
        globalThis.fetch = vi.fn();
        const heading = issue({ruleId: 'rte.heading_hierarchy_jump', aiTarget: {...issue().aiTarget, ruleId: 'rte.heading_hierarchy_jump', kind: 'heading_level', operation: 'heading_level', tag: 'h4', headingLevel: 4, targetHeadingLevel: 3}});
        const editorAdapter = adapter();
        const session = new EditorAiSession({issue: heading, adapter: editorAdapter, config, label: (k, f) => f});
        const container = mount(session);

        expect(container.textContent).toContain('Change to Heading 3');
        expect(container.textContent).toContain('No AI');
        container.querySelector('[data-aqg-ai="rule-fix"]').click();
        expect(globalThis.fetch).not.toHaveBeenCalled();
        expect(editorAdapter.apply).toHaveBeenCalledWith('');
    });

    it('says the plain HTML editor has no rule-based level change instead of blaming a missing heading level', () => {
        // Lead check after the Atlas pass: the plain HTML panel claimed "This heading level is not available in this editor".
        const heading = issue({ruleId: 'rte.heading_hierarchy_jump', aiTarget: {...issue().aiTarget, ruleId: 'rte.heading_hierarchy_jump', kind: 'heading_level', operation: 'heading_level', tag: 'h4', headingLevel: 4, targetHeadingLevel: 3}});
        const plainAdapter = adapter({canRuleBasedFix: () => false, ruleBasedFixSupported: false});
        const session = new EditorAiSession({issue: heading, adapter: plainAdapter, config, label: (k, f) => f});
        const container = mount(session);

        expect(session.unavailableReason()).toBe('no_rule_based_fix');
        expect(container.textContent).toContain('No rule-based level change is possible here.');
        expect(container.textContent).not.toContain('not available in this editor');
        expect(container.querySelector('[data-aqg-ai="rule-fix"]')).toBeNull();
    });
});

describe('review dialog', () => {
    beforeEach(() => {
        HTMLDialogElement.prototype.showModal ??= function showModal() {
            this.open = true;
        };
        HTMLDialogElement.prototype.close ??= function close() {
            this.open = false;
        };
    });

    it('starts AI items undecided, rule-based items accepted, and applies only accepted items', async () => {
        globalThis.fetch = respond(200, {success: true, status: 'suggestion', value: 'Contact', reason: 'r', serverTime: 5});
        const linkAdapter = adapter();
        const otherAdapter = adapter();
        const headingAdapter = adapter();
        const heading = issue({ruleId: 'rte.heading_hierarchy_jump', aiTarget: {...issue().aiTarget, kind: 'heading_level', operation: 'heading_level', tag: 'h4', headingLevel: 4, targetHeadingLevel: 3}});
        const applied = vi.fn();
        const dialog = new EditorAiReviewDialog({
            entries: [{issue: issue(), adapter: linkAdapter}, {issue: issue({fingerprint: 'live:2'}), adapter: otherAdapter}, {issue: heading, adapter: headingAdapter}],
            config, label: (k, f) => f, editorType: 'ckeditor', onApplied: applied,
        });
        dialog.open();
        await vi.waitFor(() => expect(document.querySelectorAll('.aqg-ai-dialog__item--ready')).toHaveLength(3));

        expect(document.querySelector('.aqg-ai-dialog__foot').textContent).toContain('2 undecided');
        expect(document.querySelector('.aqg-ai-dialog [data-aqg-review="apply"]').textContent).toContain('Apply 1 accepted');
        document.querySelector('.aqg-ai-dialog [data-aqg-review="accept"][data-index="0"]').click();
        document.querySelector('.aqg-ai-dialog [data-aqg-review="apply"]').click();

        expect(linkAdapter.apply).toHaveBeenCalledWith('Contact');
        expect(otherAdapter.apply).not.toHaveBeenCalled();
        expect(headingAdapter.apply).toHaveBeenCalledWith('');
        expect(applied).toHaveBeenCalledTimes(2);
        expect(otherAdapter.release).not.toHaveBeenCalled();
        document.querySelector('.aqg-ai-dialog [data-aqg-review="close"]').click();
        expect(otherAdapter.release).toHaveBeenCalled();
    });

    it('skips outdated items and keeps the failed ones manual', async () => {
        globalThis.fetch = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({success: true, status: 'suggestion', value: 'A'})))
            .mockResolvedValueOnce(new Response(JSON.stringify({success: false, code: 'provider_timeout'}), {status: 502}));
        const outdated = adapter({isStale: () => true});
        const dialog = new EditorAiReviewDialog({
            entries: [{issue: issue(), adapter: outdated}, {issue: issue({fingerprint: 'live:2'}), adapter: adapter()}],
            config, label: (k, f) => f, editorType: 'html',
        });
        dialog.open();
        await vi.waitFor(() => expect(document.querySelector('.aqg-ai-dialog__item--failed')).not.toBeNull());
        document.querySelector('.aqg-ai-dialog [data-aqg-review="accept"][data-index="0"]').click();
        document.querySelector('.aqg-ai-dialog [data-aqg-review="apply"]').click();

        expect(outdated.apply).not.toHaveBeenCalled();
        expect(document.querySelector('.aqg-ai-dialog__item--outdated')).not.toBeNull();
        expect(document.querySelector('.aqg-ai-dialog__title').textContent).toContain('HTML field');
        dialog.close();
        await settle();
    });
});
