/**
 * Page scan history tabs (Atlas D9): "Preview scans" and "Public scans" are two histories of one URL. A real tablist:
 * one tab in the tab order, arrow keys / Home / End move between tabs, each tab shows its own panel.
 */
const select = (tabs, tab, focus) => {
    tabs.forEach((candidate) => {
        const isActive = candidate === tab;
        candidate.setAttribute('aria-selected', isActive ? 'true' : 'false');
        candidate.tabIndex = isActive ? 0 : -1;
        const panel = candidate.ownerDocument.getElementById(candidate.getAttribute('aria-controls') || '');
        if (panel) {
            panel.hidden = !isActive;
        }
    });
    if (focus) {
        tab.focus();
    }
};

export function initHistoryTabs(root = document) {
    root.querySelectorAll('[data-aqg-history-tabs]').forEach((tablist) => {
        if (tablist.dataset.aqgHistoryTabsReady === '1') {
            return;
        }
        tablist.dataset.aqgHistoryTabsReady = '1';
        const tabs = Array.from(tablist.querySelectorAll('[role="tab"]'));
        if (tabs.length === 0) {
            return;
        }

        tabs.forEach((tab) => tab.addEventListener('click', () => select(tabs, tab, false)));
        tablist.addEventListener('keydown', (event) => {
            const index = tabs.indexOf(event.target instanceof Element ? event.target.closest('[role="tab"]') : null);
            if (index < 0) {
                return;
            }
            const targets = {
                ArrowRight: (index + 1) % tabs.length,
                ArrowLeft: (index - 1 + tabs.length) % tabs.length,
                Home: 0,
                End: tabs.length - 1,
            };
            if (!(event.key in targets)) {
                return;
            }
            event.preventDefault();
            select(tabs, tabs[targets[event.key]], true);
        });

        select(tabs, tabs.find((tab) => tab.getAttribute('aria-selected') === 'true') || tabs[0], false);
    });
}

if (typeof document !== 'undefined') {
    initHistoryTabs(document);
}
