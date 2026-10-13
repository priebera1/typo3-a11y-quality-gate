import {describe, expect, it} from 'vitest';
import {selectedTreePage, withToolbarContext} from '../../Resources/Public/JavaScript/toolbar/a11y-scan-toolbar-menu.js';

// Regression (2.0.1): opened from the Dashboard the toolbar request carried no page, so the Frontend card said
// "Not scanned yet" for a site with frontend scans. Modules without a page now pass the page selected in the tree.
const renderUrl = () => new URL('https://example.org/typo3/ajax/a11y/toolbar/render');
const moduleUrl = (query = '') => new URL('https://example.org/typo3/module/dashboard' + query);
const storage = (identifier) => ({current: (key) => (key === 'web' ? {identifier} : {identifier: ''})});

describe('toolbar context', () => {
    it('keeps the page, site and language of the current module', () => {
        const url = withToolbarContext(renderUrl(), moduleUrl('?id=42&language=1'), '7');

        expect(url.searchParams.get('id')).toBe('42');
        expect(url.searchParams.get('language')).toBe('1');
    });

    it('does not replace a site the module names', () => {
        expect(withToolbarContext(renderUrl(), moduleUrl('?site=main'), '7').searchParams.has('id')).toBe(false);
    });

    it('uses the page selected in the page tree in modules without a page', () => {
        expect(withToolbarContext(renderUrl(), moduleUrl(), '7').searchParams.get('id')).toBe('7');
    });

    it('does not read the File list folder as a page', () => {
        const url = withToolbarContext(renderUrl(), moduleUrl('?id=1%3A%2Fuser_upload%2F'), '7');

        expect(url.searchParams.get('id')).toBe('7');
    });

    it('sends no page when nothing is selected', () => {
        expect(withToolbarContext(renderUrl(), moduleUrl(), '').searchParams.has('id')).toBe(false);
    });

    it('reads only a real page uid from the page tree state', () => {
        expect(selectedTreePage(storage('728'))).toBe('728');
        expect(selectedTreePage(storage('0'))).toBe('');
        expect(selectedTreePage(storage(''))).toBe('');
        expect(selectedTreePage(storage('728"><x'))).toBe('');
        expect(selectedTreePage({current: () => { throw new Error('no storage'); }})).toBe('');
        expect(selectedTreePage({current: () => null})).toBe('');
    });
});
