// @vitest-environment jsdom
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {SHEET_BREAKPOINT, computePopoverPlacement, sheetScrollDelta} from '@priebera/a11y-quality-gate/editor-ai/popover-placement.js';
import {EditorAiSession, ladderHtml, modelDisplayName} from '@priebera/a11y-quality-gate/editor-ai/editor-ai-session.js';
import {EditorAiReviewDialog} from '@priebera/a11y-quality-gate/editor-ai/editor-ai-review-dialog.js';

// Atlas correction pass (audit F-03, F-10, F-11, F-22, F-37, F-38): the editor popover keeps its actions in view, a
// narrow editor gets a bottom sheet, titles are plain rule names, provenance shows the model's display name and an
// applied change keeps its Before/After under the shared outcome ladder.

const rect = (top, height, width = 900) => ({top, bottom: top + height, height, width});
const host = rect(100, 1600, 900);
const viewportHeight = 1000;

/** Bottom of the action row of a popover placed at `placement` (it sits at the popover's bottom edge). */
const actionRowBottom = (placement, popoverHeight) => host.top + placement.top + Math.min(popoverHeight, placement.maxHeight ?? popoverHeight);

describe('editor popover placement', () => {
    it('opens below the marker when the whole popover fits there', () => {
        const placement = computePopoverPlacement({anchorRect: rect(200, 20), hostRect: host, popoverHeight: 400, viewportHeight});
        expect(placement.mode).toBe('below');
        expect(host.top + placement.top).toBe(224);
        expect(placement.maxHeight).toBeNull();
    });

    it('flips above a marker in the lower half instead of clamping the actions out of view', () => {
        // The audit case: marker near the bottom, a 534px suggestion, 322px left below.
        const anchorRect = rect(780, 20);
        const placement = computePopoverPlacement({anchorRect, hostRect: host, popoverHeight: 534, viewportHeight});
        expect(placement.mode).toBe('above');
        expect(placement.maxHeight).toBeNull();
        expect(actionRowBottom(placement, 534)).toBeLessThanOrEqual(anchorRect.top);
        expect(actionRowBottom(placement, 534)).toBeLessThanOrEqual(viewportHeight);
    });

    it('shifts into the window when neither side has room, keeping the action row visible', () => {
        const placement = computePopoverPlacement({anchorRect: rect(450, 20), hostRect: host, popoverHeight: 700, viewportHeight});
        expect(placement.mode).toBe('shift');
        expect(host.top + placement.top).toBeGreaterThanOrEqual(12);
        expect(actionRowBottom(placement, 700)).toBeLessThanOrEqual(viewportHeight - 12);
    });

    it('scrolls inside only when the popover is taller than the window, below the sticky header', () => {
        const placement = computePopoverPlacement({anchorRect: rect(500, 20), hostRect: host, popoverHeight: 1400, viewportHeight, topLimit: 90});
        expect(placement.maxHeight).toBe(viewportHeight - 90 - 24);
        expect(host.top + placement.top).toBeGreaterThanOrEqual(90 + 12);
        expect(actionRowBottom(placement, 1400)).toBeLessThanOrEqual(viewportHeight - 12);
    });

    it('docks as a bottom sheet in a narrow editor and keeps the marked text above it', () => {
        const placement = computePopoverPlacement({anchorRect: rect(700, 20), hostRect: rect(100, 900, SHEET_BREAKPOINT - 1), popoverHeight: 500, viewportHeight: 800});
        expect(placement.mode).toBe('sheet');
        expect(placement.maxHeight).toBe(Math.round(800 * 0.64));

        // The marked heading (700-720) would sit under a 512px sheet: scroll it up to end above the sheet.
        const delta = sheetScrollDelta({targetRect: rect(700, 20), viewportHeight: 800, sheetHeight: 512});
        expect(720 - delta).toBeLessThanOrEqual(800 - 512 - 12);
        expect(sheetScrollDelta({targetRect: rect(100, 20), viewportHeight: 800, sheetHeight: 512})).toBe(0);
    });

    it('opens "Show issues" at the top of the editing area when it fits', () => {
        const placement = computePopoverPlacement({anchorRect: rect(100, 30), hostRect: host, popoverHeight: 300, viewportHeight, fromSummary: true});
        expect(placement).toEqual({mode: 'below', top: 4, maxHeight: null});
    });
});

const headingIssue = (overrides = {}) => ({
    fingerprint: 'live:h',
    ruleId: 'rte.empty_heading',
    ruleTitle: 'Remove empty headings',
    severity: 'critical',
    message: '<h3> element has no visible text.',
    aiTarget: {
        ruleId: 'rte.empty_heading', kind: 'heading_text', operation: 'text', attribute: '', tag: 'h3', occurrence: 0, total: 1,
        contextPath: 'h3', currentValue: '', unavailableReason: '', headingLevel: 3, targetHeadingLevel: 0,
        element: {tag: 'h3', attributes: {}, text: '', hasChildElements: false},
    },
    ...overrides,
});

const config = {state: 'ready', rules: ['rte.empty_heading'], provider: 'anthropic', providerLabel: 'Anthropic Claude', model: 'claude-haiku-5-5', modelLabel: 'Claude Haiku 5.5', allowImages: true, linkText: true};

const adapter = (overrides = {}) => ({
    type: 'ckeditor',
    requestPayload: () => ({recordUid: 1, fieldName: 'bodytext', html: '<h3></h3>', ruleId: 'rte.empty_heading', contextPath: 'h3', occurrence: 0}),
    lock: vi.fn(() => ({ok: true})),
    isStale: vi.fn(() => false),
    // After Apply the element holds the new value: the Before/After must come from before the change.
    preview: vi.fn((value) => ({kind: 'text', before: '', after: value})),
    apply: vi.fn(() => ({ok: true, expected: {tag: 'h3', attributes: {}, text: 'Prices'}})),
    undo: vi.fn(() => true),
    release: vi.fn(),
    canRuleBasedFix: () => true,
    describeTarget: () => 'This empty heading (H3)',
    ...overrides,
});

const suggestion = {success: true, status: 'suggestion', value: 'Prices', reason: 'r', provider: 'anthropic', providerLabel: 'Anthropic Claude', model: 'claude-haiku-5-5', modelLabel: 'Claude Haiku 5.5', sent: ['headingLevel'], notSent: ['otherFields'], usage: {reported: true, inputTokens: 1, outputTokens: 2}};
const respond = (body) => vi.fn(async () => new Response(JSON.stringify(body), {status: 200, headers: {'Content-Type': 'application/json'}}));

beforeEach(() => {
    globalThis.TYPO3 = {settings: {ajaxUrls: {a11y_ai_editor_fix: '/fix', a11y_ai_editor_explain: '/explain', a11y_ai_editor_verify: '/verify'}}};
    if (!HTMLDialogElement.prototype.showModal) {
        HTMLDialogElement.prototype.showModal = function showModal() { this.setAttribute('open', ''); };
        HTMLDialogElement.prototype.close = function close() { this.removeAttribute('open'); };
    }
});

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('editor AI provenance and outcome', () => {
    it('names the model by its display name, never only by the technical id', () => {
        expect(modelDisplayName({model: 'claude-haiku-5-5', modelLabel: 'Claude Haiku 5.5'}, config)).toBe('Claude Haiku 5.5');
        // Older answers and staged changes carry only the id of the configured model.
        expect(modelDisplayName({model: 'claude-haiku-5-5'}, config)).toBe('Claude Haiku 5.5');
        expect(modelDisplayName(null, config)).toBe('Claude Haiku 5.5');
        expect(modelDisplayName({model: 'gpt-5.4-mini'}, config)).toBe('gpt-5.4-mini');
    });

    it('keeps Before/After under the shared ladder after Apply and never marks Saved or Verified', async () => {
        globalThis.fetch = respond(suggestion);
        const session = new EditorAiSession({issue: headingIssue(), adapter: adapter(), config, label: (k, f) => f});
        const container = document.createElement('div');
        document.body.append(container);
        session.mount(container);
        container.querySelector('[data-aqg-ai="fix"]').click();
        await vi.waitFor(() => expect(container.dataset.state).toBe('ready'));
        expect(container.querySelector('.aqg-ai__model').textContent).toBe('Claude Haiku 5.5 · Anthropic Claude');
        expect(container.textContent).not.toContain('claude-haiku-5-5');

        container.querySelector('[data-aqg-ai="apply"]').click();
        expect(container.dataset.state).toBe('applied');
        const steps = [...container.querySelectorAll('ol.aqg-ladder > li')];
        expect(steps.map((step) => step.textContent)).toEqual(['Suggested', 'In text, unsaved', 'Saved', 'Verified']);
        expect(steps.map((step) => step.className)).toEqual(['is-done', 'is-current', '', '']);
        expect(container.querySelectorAll('.aqg-ladder__dot')).toHaveLength(4);
        expect(container.querySelector('.aqg-ai__preview--applied .aqg-ai__diff-row--after dd').textContent).toBe('Prices');
        expect(container.querySelector('.aqg-ai__preview--applied .aqg-ai__diff-row--before em')).not.toBeNull();
        expect(container.querySelector('.aqg-ai__ladder')).toBeNull();
    });

    it('marks Verified as current only on request, in the success state', () => {
        const html = ladderHtml('verified', 'html', (k, f) => f);
        const box = document.createElement('div');
        box.innerHTML = html;
        expect([...box.querySelectorAll('li')].map((li) => li.textContent)).toEqual(['Suggested', 'In source, unsaved', 'Saved', 'Verified']);
        expect(box.querySelector('li.is-current').classList.contains('is-verified')).toBe(true);
        expect(html).not.toMatch(/[✓✔]/);
    });
});

describe('editor review dialog', () => {
    it('titles items with the plain rule name and decides in a segmented control in the item header', async () => {
        globalThis.fetch = respond(suggestion);
        const dialog = new EditorAiReviewDialog({
            entries: [
                {issue: headingIssue(), adapter: adapter()},
                {issue: headingIssue({fingerprint: 'live:h2', ruleTitle: '', message: '<h2> element has no visible text.'}), adapter: adapter()},
            ],
            config,
            label: (k, f) => f,
            editorType: 'ckeditor',
        });
        dialog.open();
        await vi.waitFor(() => expect(document.querySelectorAll('.aqg-ai-dialog__item--ready')).toHaveLength(2));

        const titles = [...document.querySelectorAll('.aqg-ai-dialog__item-title')].map((element) => element.textContent);
        // The rule name, never the stored message; without a rule name the message is the fallback.
        expect(titles).toEqual(['Remove empty headings', '<h2> element has no visible text.']);
        const head = document.querySelector('.aqg-ai-dialog__item-head');
        expect(head.querySelector('.aqg-ai-dialog__decision [data-aqg-review="accept"]')).not.toBeNull();
        expect(head.querySelector('.aqg-ai-dialog__decision [data-aqg-review="skip"]')).not.toBeNull();

        head.querySelector('[data-aqg-review="accept"]').click();
        expect(document.querySelector('.aqg-ai-dialog__item').classList.contains('is-accepted')).toBe(true);
        expect(document.querySelector('.aqg-ai-dialog__item [data-aqg-review="accept"]').getAttribute('aria-pressed')).toBe('true');

        // Provenance once, with the display name and "What was sent?".
        const provenance = document.querySelector('.aqg-ai-dialog__provenance');
        expect(provenance.querySelector('.aqg-ai__model').textContent).toBe('Claude Haiku 5.5 · Anthropic Claude');
        expect(provenance.querySelector('details.aqg-ai__sent > summary').textContent).toBe('What was sent?');
        dialog.close();
    });
});
