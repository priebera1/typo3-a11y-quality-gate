// @vitest-environment jsdom

import {describe, expect, it} from 'vitest';
import {documentUsesDarkTheme} from '../../Resources/Public/JavaScript/toolbar/a11y-scan-toolbar-menu.js';

// Regression (audit C11): on TYPO3 14 in dark mode the toolbar dropdown kept `aqt-toolbar--theme-light`, so its status
// pills stayed in light colours on a dark card. The scheme was read from any light component inside the page; it now
// comes from the root's own attribute, and without one (TYPO3's "auto") from the OS preference.
const doc = (attributes = '', inner = '') => new DOMParser().parseFromString(
    `<html ${attributes}><body>${inner}</body></html>`,
    'text/html'
);

describe('toolbar colour scheme', () => {
    it('follows an explicit TYPO3 choice', () => {
        expect(documentUsesDarkTheme(doc('data-color-scheme="dark"'), () => false)).toBe(true);
        expect(documentUsesDarkTheme(doc('data-color-scheme="light"'), () => true)).toBe(false);
    });

    it('follows the OS preference for the default auto scheme', () => {
        expect(documentUsesDarkTheme(doc(), () => true)).toBe(true);
        expect(documentUsesDarkTheme(doc('data-color-scheme="auto"'), () => true)).toBe(true);
        expect(documentUsesDarkTheme(doc(), () => false)).toBe(false);
    });

    it('ignores light components inside the page', () => {
        const page = doc('', '<div data-bs-theme="light" class="theme-light"></div>');

        expect(documentUsesDarkTheme(page, () => true)).toBe(true);
    });
});
