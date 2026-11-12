/**
 * Settings navigation: one row of tab links that scrolls horizontally when the module is too narrow for it.
 * Keeps the active tab in view on load and marks which edge is clipped (data-aqg-overflow-start/-end) so the CSS can
 * fade it. Links stay plain links: keyboard focus scrolls them into view natively.
 */
export function updateOverflow(nav) {
    const max = nav.scrollWidth - nav.clientWidth;
    const start = nav.scrollLeft > 1;
    const end = max - nav.scrollLeft > 1;
    nav.dataset.aqgOverflowStart = start ? 'true' : 'false';
    nav.dataset.aqgOverflowEnd = end ? 'true' : 'false';
    return {start, end};
}

export function revealActive(nav, target = null) {
    const active = target ?? nav.querySelector('[aria-current="page"], .aqg-tab.is-active');
    if (!(active instanceof HTMLElement) || nav.scrollWidth <= nav.clientWidth) {
        return;
    }
    // The nav is position: relative, so offsetLeft is measured inside the scrolling row.
    const left = active.offsetLeft;
    const right = left + active.offsetWidth;
    // Leave room for the 40px edge fade so the active tab is never drawn under it.
    if (right > nav.scrollLeft + nav.clientWidth - 40) {
        nav.scrollLeft = right - nav.clientWidth + 48;
    } else if (left < nav.scrollLeft + 40) {
        nav.scrollLeft = Math.max(0, left - 48);
    }
}

export function initSettingsTabs(root = document) {
    root.querySelectorAll('[data-aqg-settings-nav]').forEach((nav) => {
        if (!(nav instanceof HTMLElement) || nav.dataset.aqgTabsReady === '1') {
            return;
        }
        nav.dataset.aqgTabsReady = '1';
        // Layout can settle after this module runs (stylesheet, fonts, icons, a resized frame), so every layout change
        // keeps the active tab in view until the person scrolls the row themselves.
        let userMoved = false;
        const settle = () => {
            if (!userMoved) {
                revealActive(nav);
            }
            updateOverflow(nav);
        };
        settle();
        for (const type of ['wheel', 'touchstart', 'pointerdown', 'keydown']) {
            nav.addEventListener(type, () => { userMoved = true; }, {passive: true});
        }
        nav.addEventListener('scroll', () => updateOverflow(nav), {passive: true});
        // A tab reached with the keyboard is moved clear of the edge fade, not only into the clipped edge.
        nav.addEventListener('focusin', (event) => {
            if (event.target instanceof HTMLElement && event.target.matches('.aqg-tab')) {
                revealActive(nav, event.target);
                updateOverflow(nav);
            }
        });
        if (typeof ResizeObserver === 'function') {
            new ResizeObserver(settle).observe(nav);
        }
        window.addEventListener('load', settle, {once: true});
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initSettingsTabs());
} else {
    initSettingsTabs();
}
