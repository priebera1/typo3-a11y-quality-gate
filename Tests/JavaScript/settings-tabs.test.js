// @vitest-environment jsdom
import {afterEach, describe, expect, it} from 'vitest';
import {initSettingsTabs, revealActive, updateOverflow} from '@priebera/a11y-quality-gate/backend/settings-tabs.js';

/** Settings navigation (owner UX pass 3.0.0): one scrolling row, active tab kept in view, clipped edges marked. */
const nav = ({scrollWidth, clientWidth, scrollLeft = 0, activeLeft = 0, activeWidth = 100}) => {
    document.body.innerHTML = `<nav class="aqg-tabs" data-aqg-settings-nav="true"><ul class="aqg-tabs__list">
        <li><a class="aqg-tab" href="#a">Licence</a></li><li><a class="aqg-tab is-active" aria-current="page" href="#b">Statement</a></li></ul></nav>`;
    const node = document.querySelector('nav');
    Object.defineProperty(node, 'scrollWidth', {configurable: true, value: scrollWidth});
    Object.defineProperty(node, 'clientWidth', {configurable: true, value: clientWidth});
    node.scrollLeft = scrollLeft;
    let left = scrollLeft;
    Object.defineProperty(node, 'scrollLeft', {configurable: true, get: () => left, set: (value) => { left = value; }});
    const active = node.querySelector('[aria-current="page"]');
    Object.defineProperty(active, 'offsetLeft', {configurable: true, value: activeLeft});
    Object.defineProperty(active, 'offsetWidth', {configurable: true, value: activeWidth});
    return node;
};

describe('settings tabs', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('marks no clipped edge when every tab fits', () => {
        const node = nav({scrollWidth: 700, clientWidth: 800});
        expect(updateOverflow(node)).toEqual({start: false, end: false});
        expect(node.dataset.aqgOverflowEnd).toBe('false');
    });

    it('marks the clipped end, then both edges after scrolling', () => {
        const node = nav({scrollWidth: 1000, clientWidth: 800});
        expect(updateOverflow(node)).toEqual({start: false, end: true});
        node.scrollLeft = 100;
        expect(updateOverflow(node)).toEqual({start: true, end: true});
        node.scrollLeft = 200;
        expect(updateOverflow(node)).toEqual({start: true, end: false});
    });

    it('scrolls a clipped active tab into view, clear of the edge fade', () => {
        const node = nav({scrollWidth: 1000, clientWidth: 800, activeLeft: 880, activeWidth: 90});
        revealActive(node);
        expect(node.scrollLeft).toBe(880 + 90 - 800 + 48);
    });

    it('leaves the row alone when nothing overflows and initialises once', () => {
        const node = nav({scrollWidth: 700, clientWidth: 800, activeLeft: 500});
        initSettingsTabs();
        initSettingsTabs();
        expect(node.scrollLeft).toBe(0);
        expect(node.dataset.aqgTabsReady).toBe('1');
    });
});
