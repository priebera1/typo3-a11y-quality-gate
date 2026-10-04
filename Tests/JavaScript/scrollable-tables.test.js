// @vitest-environment jsdom

import {afterEach, describe, expect, it} from 'vitest';
import {initializeScrollableTables, updateScrollableTable} from '../../Resources/Public/JavaScript/backend/core/scrollable-tables.js';

// jsdom has no layout: the wrapper's scroll geometry is set per test.
const renderTable = ({clientWidth, scrollWidth, scrollLeft = 0}) => {
    document.body.innerHTML = `
        <div class="aqg-toolbar-row__title" id="a11y-overview-local-pages-title">Pages with open issues</div>
        <div class="aqg-table-wrap" data-aqg-scroll-labelledby="a11y-overview-local-pages-title"><table></table></div>
    `;
    const wrapper = document.querySelector('.aqg-table-wrap');
    Object.defineProperty(wrapper, 'clientWidth', {configurable: true, value: clientWidth});
    Object.defineProperty(wrapper, 'scrollWidth', {configurable: true, value: scrollWidth});
    wrapper.scrollLeft = scrollLeft;
    return wrapper;
};

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Overview table scrolling', () => {
    it('adds no tab stop to a table that fits the module', () => {
        const wrapper = renderTable({clientWidth: 793, scrollWidth: 793});

        initializeScrollableTables();

        expect(wrapper.hasAttribute('tabindex')).toBe(false);
        expect(wrapper.hasAttribute('role')).toBe(false);
        expect(wrapper.dataset.aqgScrollable).toBeUndefined();
    });

    it('makes a table that must scroll a named region the keyboard can focus', () => {
        const wrapper = renderTable({clientWidth: 401, scrollWidth: 659});

        initializeScrollableTables();

        expect(wrapper.getAttribute('role')).toBe('region');
        expect(wrapper.getAttribute('aria-labelledby')).toBe('a11y-overview-local-pages-title');
        expect(wrapper.tabIndex).toBe(0);
        expect(wrapper.dataset.aqgScrollable).toBe('true');
        expect(wrapper.dataset.aqgScrollEnd).toBe('false');
    });

    it('marks the scroll end so the pinned column drops its edge shadow, and undoes it all once the table fits', () => {
        const wrapper = renderTable({clientWidth: 401, scrollWidth: 659, scrollLeft: 258});

        updateScrollableTable(wrapper);
        expect(wrapper.dataset.aqgScrollEnd).toBe('true');

        Object.defineProperty(wrapper, 'clientWidth', {configurable: true, value: 700});
        updateScrollableTable(wrapper);
        expect(wrapper.hasAttribute('tabindex')).toBe(false);
        expect(wrapper.hasAttribute('role')).toBe(false);
        expect(wrapper.hasAttribute('aria-labelledby')).toBe(false);
        expect(wrapper.dataset.aqgScrollEnd).toBeUndefined();
    });
});
