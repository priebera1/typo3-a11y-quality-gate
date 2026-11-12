// @vitest-environment jsdom

import {describe, expect, it} from 'vitest';
import {countElementsBefore} from '../../Resources/Public/JavaScript/editor-ai/ckeditor-target.js';

// Regression (3.0.0 release QA 2026-11-12, TYPO3 13.4 / CKEditor 47.6): "Fix with AI" did nothing for a link in the
// first paragraph. Proving the target serialized the range [root start → link start] with getSelectedContent(), and
// CKEditor throws "model-createpositionat-offset-required" for a range whose start path is a strict prefix of its end
// path ([0] → [0, 61]): getCommonPath() is the whole start path, so the next path segment is undefined.

/**
 * A model with two top-level paragraphs, serialized like editor.getData():
 *   <p>Report as PDF. <a href="a.pdf">click here</a></p><p>See <a href="b.pdf">also</a>.</p>
 * getSelectedContent() fails exactly like CKEditor's for a strict-prefix range.
 */
const fakeEditor = () => {
    const data = '<p>Report as PDF. <a href="a.pdf">click here</a></p><p>See <a href="b.pdf">also</a>.</p>';
    const root = {name: '$root', childCount: 2};
    const position = (path) => ({path, root});
    const key = (range) => `${range.start.path.join(',')}-${range.end.path.join(',')}`;
    const serialized = {
        // Content after the first link's start, through the end of the root.
        '0,15-2': '<p><a href="a.pdf">click here</a></p><p>See <a href="b.pdf">also</a>.</p>',
        // Root start to the second paragraph's link (start path [0] is no prefix of [1, 4]).
        '0-1,4': '<p>Report as PDF. <a href="a.pdf">click here</a></p><p>See </p>',
    };
    const model = {
        document: {getRoot: () => root},
        createPositionAt: (parent, offset) => position(offset === 'end' ? [parent.childCount] : [offset]),
        createRange: (start, end) => ({start, end}),
        createSelection: (range) => range,
        getSelectedContent(range) {
            const {start, end} = range;
            if (start.path.length < end.path.length && start.path.every((value, index) => value === end.path[index])) {
                throw new Error('model-createpositionat-offset-required');
            }
            if (!(key(range) in serialized)) {
                throw new Error(`unexpected range ${key(range)}`);
            }
            return key(range);
        },
    };
    return {
        editor: {model, data: {stringify: (fragment) => serialized[fragment]}, getData: () => data},
        position,
    };
};

describe('editor AI target in the first top-level block', () => {
    it('counts the elements before a link in the first paragraph without the failing prefix range', () => {
        const {editor, position} = fakeEditor();

        // Before the fix this threw model-createpositionat-offset-required and "Fix with AI" stopped silently.
        expect(() => countElementsBefore(editor, position([0, 15]), 'a')).not.toThrow();
        expect(countElementsBefore(editor, position([0, 15]), 'a')).toBe(0);
    });

    it('refuses (-1) instead of guessing occurrence 0 when the counts disagree', () => {
        const {editor, position} = fakeEditor();
        editor.getData = () => '<p>no links</p>';

        expect(countElementsBefore(editor, position([0, 15]), 'a')).toBe(-1);
    });

    it('keeps counting through the range before the position everywhere else', () => {
        const {editor, position} = fakeEditor();

        expect(countElementsBefore(editor, position([1, 4]), 'a')).toBe(1);
    });
});
