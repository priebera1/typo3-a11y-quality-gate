// @vitest-environment jsdom
import {describe, expect, it} from 'vitest';
import {classifySelection, isBulkEligible, outcomeOf, retryable, summarize} from '@priebera/a11y-quality-gate/remediation/remediation-bulk.js';
import {findStagedIssue, stagedItemId} from '@priebera/a11y-quality-gate/remediation/editor-staging.js';
import {el, format, relativeAge} from '@priebera/a11y-quality-gate/remediation/remediation-api.js';

const imageItem = (overrides = {}) => ({uid: 1, status: 'pending_review', applyMode: 'server', seen: true, actions: {approve: true, retry: false}, ...overrides});

describe('bulk review', () => {
    it('applies directly only when every selected item is a seen image text', () => {
        expect(classifySelection([imageItem(), imageItem({uid: 2})]).label).toBe('apply');
        expect(classifySelection([imageItem(), imageItem({uid: 2, seen: false})]).label).toBe('review');
        const mixed = classifySelection([imageItem(), imageItem({uid: 3, applyMode: 'editor'})]);
        expect(mixed.label).toBe('review');
        expect(mixed.excluded.map((item) => item.uid)).toEqual([3]);
    });

    it('never offers items without an approve permission or in other states', () => {
        expect(isBulkEligible(imageItem({actions: {approve: false}}))).toBe(false);
        expect(isBulkEligible(imageItem({status: 'conflict', actions: {approve: false}}))).toBe(false);
        expect(isBulkEligible(imageItem({status: 'failed', actions: {approve: true, retry: true}}))).toBe(true);
    });

    it('summarizes honestly: verified counts as saved, not verified is saved but not verified, skips are not failures', () => {
        const results = [
            {item: {status: 'verified'}},
            {item: {status: 'saved'}},
            {item: {status: 'not_verified'}},
            {item: {status: 'conflict'}},
            {item: {status: 'failed', actions: {retry: true}}},
            {error: 'changed_concurrently'},
            {error: 'network'},
        ];
        expect(summarize(results)).toEqual({saved: 3, verified: 1, notVerified: 1, conflict: 1, failed: 2, skipped: 1});
        expect(outcomeOf({error: 'not_reviewed'})).toBe('skipped');
        expect(retryable(results)).toHaveLength(2);
        expect(retryable([{item: {status: 'conflict'}}])).toEqual([]);
    });
});

describe('editor staging', () => {
    const staged = {ruleId: 'rte.non_descriptive_link', target: {tag: 'a', occurrence: 1, total: 2, currentValue: 'click here', attributes: {href: 'https://typo3.org/'}}};
    const live = (overrides = {}, attributes = {href: 'https://typo3.org/'}) => ({ruleId: 'rte.non_descriptive_link', aiTarget: {tag: 'a', occurrence: 1, total: 2, currentValue: 'click here', unavailableReason: '', element: {attributes}, ...overrides}});

    it('reads only a positive item id from the editor URL', () => {
        expect(stagedItemId('?edit[tt_content][12]=edit&aqgRemediation=31')).toBe(31);
        expect(stagedItemId('?aqgRemediation=-3')).toBe(0);
        expect(stagedItemId('?aqgRemediation=abc')).toBe(0);
    });

    it('finds the element after the editor normalized the HTML (positions counted in the stored HTML shift)', () => {
        // Real case (Anthropic E2E): CKEditor dropped an empty <a></a>, so the editor sees 1 link where the stored HTML has 2.
        expect(findStagedIssue([live({occurrence: 0, total: 1})], staged)).not.toBeNull();
    });

    it('never adopts another element: other value, other attributes, or an unavailable target', () => {
        expect(findStagedIssue([live({currentValue: 'here'})], staged)).toBeNull();
        expect(findStagedIssue([live({}, {href: 'https://example.org/'})], staged)).toBeNull();
        expect(findStagedIssue([live({unavailableReason: 'nested_markup'})], staged)).toBeNull();
    });

    it('tells identical candidates apart by the stored position, and adopts nothing when that is ambiguous', () => {
        const first = live({occurrence: 0, total: 2});
        const second = live({occurrence: 1, total: 2});
        expect(findStagedIssue([first, second], staged)).toBe(second);
        expect(findStagedIssue([live({occurrence: 0, total: 3}), live({occurrence: 2, total: 3})], staged)).toBeNull();
    });
});

describe('rendering helpers', () => {
    it('inserts model text as text, never as markup', () => {
        const node = el('span', {text: '<img src=x onerror=alert(1)>'});
        expect(node.innerHTML).toBe('&lt;img src=x onerror=alert(1)&gt;');
        expect(node.querySelector('img')).toBeNull();
    });

    it('formats XLIFF placeholders and relative ages', () => {
        expect(format('%1$d of %2$d', 2, 9)).toBe('2 of 9');
        expect(format('%d items', 4)).toBe('4 items');
        expect(relativeAge(1000, {'age.minutes': '%d min'}, 1000 + 125)).toBe('2 min');
    });
});

describe('a staged queue change in the editor session', async () => {
    const {EditorAiSession} = await import('@priebera/a11y-quality-gate/editor-ai/editor-ai-session.js');
    const {vi} = await import('vitest');
    const issue = {
        fingerprint: 'live:1',
        ruleId: 'rte.non_descriptive_link',
        aiTarget: {ruleId: 'rte.non_descriptive_link', kind: 'link_text', operation: 'text', attribute: '', tag: 'a', occurrence: 1, total: 2, contextPath: 'p[2] > a', currentValue: 'click here', unavailableReason: '', element: {tag: 'a', attributes: {href: '/k'}, text: 'click here', hasChildElements: false}},
    };
    const adapter = () => ({
        type: 'ckeditor',
        lock: vi.fn(() => ({ok: true})),
        isStale: vi.fn(() => false),
        preview: (value) => ({kind: 'text', before: 'click here', after: value}),
        apply: vi.fn(() => ({ok: true, expected: {tag: 'a', attributes: {href: '/k'}, text: 'x'}})),
        undo: vi.fn(() => true),
        release: vi.fn(),
        describeTarget: () => 'This link',
    });

    it('becomes a reviewable suggestion that is applied only by Apply and reports its item', () => {
        const applied = vi.fn();
        const editor = adapter();
        const session = new EditorAiSession({issue, adapter: editor, config: {state: 'ready', rules: ['rte.non_descriptive_link']}, label: (key, fallback) => fallback, onApplied: applied});
        const container = document.createElement('div');
        session.mount(container);

        expect(session.adoptStaged({itemUid: 31, value: 'Download the tariff sheet', serverTime: 100, model: 'm'})).toBe(true);
        expect(session.state).toBe('ready');
        expect(editor.apply).not.toHaveBeenCalled();
        expect(container.textContent).toContain('Staged from AI fixes');

        container.querySelector('[data-aqg-ai="apply"]').click();
        expect(editor.apply).toHaveBeenCalledWith('Download the tariff sheet');
        expect(applied.mock.calls[0][0].suggestion.stagedItem).toBe(31);
        expect(applied.mock.calls[0][0].suggestion.serverTime).toBe(100);
    });

    it('refuses an empty staged value and a rule-based finding', () => {
        const session = new EditorAiSession({issue, adapter: adapter(), config: {state: 'ready', rules: []}, label: (key, fallback) => fallback});
        expect(session.adoptStaged({itemUid: 1, value: '  '})).toBe(false);
    });
});
