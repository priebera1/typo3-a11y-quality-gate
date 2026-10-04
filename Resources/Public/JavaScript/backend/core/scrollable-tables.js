/**
 * Overview page tables fit the module where they can. When they cannot (a narrow module frame, the page tree
 * open), the wrapper scrolls horizontally; only then is it a named region that keyboard users can focus and
 * scroll with the arrow keys, and its state drives the pinned Actions column's edge shadow (see _overview.scss).
 * A table that fits gets no extra tab stop.
 */
const WRAPPER_SELECTOR = '.aqg-table-wrap[data-aqg-scroll-labelledby]';

export function updateScrollableTable(wrapper) {
    const scrollable = wrapper.scrollWidth > wrapper.clientWidth + 1;

    if (scrollable) {
        wrapper.dataset.aqgScrollable = 'true';
        wrapper.setAttribute('role', 'region');
        wrapper.setAttribute('aria-labelledby', wrapper.dataset.aqgScrollLabelledby);
        wrapper.tabIndex = 0;
        wrapper.dataset.aqgScrollEnd = String(wrapper.scrollLeft + wrapper.clientWidth >= wrapper.scrollWidth - 1);
        return;
    }

    delete wrapper.dataset.aqgScrollable;
    delete wrapper.dataset.aqgScrollEnd;
    wrapper.removeAttribute('role');
    wrapper.removeAttribute('aria-labelledby');
    wrapper.removeAttribute('tabindex');
}

export function initializeScrollableTables(root = document) {
    const wrappers = Array.from(root.querySelectorAll(WRAPPER_SELECTOR));
    if (wrappers.length === 0) {
        return;
    }

    // Watches the wrapper (a hidden source panel that becomes visible grows from zero width) and the table
    // (the page search hides rows and can change the table's width).
    const observer = typeof ResizeObserver === 'function'
        ? new ResizeObserver((entries) => entries.forEach((entry) => {
            const wrapper = entry.target.closest(WRAPPER_SELECTOR);
            if (wrapper) {
                updateScrollableTable(wrapper);
            }
        }))
        : null;

    wrappers.forEach((wrapper) => {
        updateScrollableTable(wrapper);
        observer?.observe(wrapper);
        const table = wrapper.querySelector('table');
        if (table) {
            observer?.observe(table);
        }
        wrapper.addEventListener('scroll', () => updateScrollableTable(wrapper), { passive: true });
    });
}
