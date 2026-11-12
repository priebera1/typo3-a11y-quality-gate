import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Viewport from '@typo3/backend/viewport.js';
import { ModuleStateStorage } from '@typo3/backend/storage/module-state-storage.js';
import { ToolbarAssistant } from '@priebera/a11y-quality-gate/toolbar/a11y-toolbar-assistant.js';

let A11yToolbarSelector;
(function (selector) {
    selector.element = '#priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem';
    selector.icon = '#priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem .toolbar-item-icon .t3js-icon';
    selector.menu = '#priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem .dropdown-menu';
    selector.dropdownToggle = '#priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem .toolbar-item-link.dropdown-toggle';
    selector.data = '[data-a11y-toolbar-data]';
    selector.status = '[data-aqt-status]';
    selector.assistant = '#priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem [data-aqt-assistant]';
})(A11yToolbarSelector || (A11yToolbarSelector = {}));


const THEME_DATASET_KEYS = [
    'colorScheme',
    'bsTheme',
    'theme',
    'typo3Theme',
];

/**
 * The backend colour scheme as TYPO3 13/14 express it: an explicit choice is a `data-color-scheme` attribute on the
 * root element; the default "auto" scheme renders no attribute (or "auto") and follows the OS preference. Only the
 * document's own root and body are read: a light or dark component somewhere inside the page says nothing about
 * the backend scheme (TYPO3 14 kept the toolbar in its light theme that way).
 */
/**
 * The page selected in the page tree ('' when none), the toolbar's context in modules without a page.
 */
export function selectedTreePage(storage = ModuleStateStorage) {
    try {
        const identifier = String(storage.current('web')?.identifier ?? '');
        return /^[1-9][0-9]*$/.test(identifier) ? identifier : '';
    } catch {
        return '';
    }
}

/**
 * The toolbar describes what the backend shows: the page, site and language of the current module, else — in modules
 * without a page (Dashboard, Scheduler, …) — the page selected in the page tree. Without either the server falls back
 * to the site of the last Content scan. An `id` that is not a page uid (the File list's folder, `1:/user_upload/`) is
 * not a page context.
 */
export function withToolbarContext(renderUrl, moduleUrl, treePage = '') {
    ['id', 'site', 'pageUid', 'language', 'languageUid', 'L', 'sys_language_uid'].forEach((key) => {
        const value = moduleUrl.searchParams.get(key);
        if (value === null || value === '' || ((key === 'id' || key === 'pageUid') && !/^[1-9][0-9]*$/.test(value))) {
            return;
        }
        renderUrl.searchParams.set(key, value);
    });
    if (treePage !== '' && !renderUrl.searchParams.has('id') && !renderUrl.searchParams.has('pageUid') && !renderUrl.searchParams.has('site')) {
        renderUrl.searchParams.set('id', treePage);
    }

    return renderUrl;
}

const renderedMenuHtml = new WeakMap();
const pendingMenuHtml = new WeakMap();

function applyMenuHtml(menu, html) {
    menu.innerHTML = html;
    renderedMenuHtml.set(menu, html);
    pendingMenuHtml.delete(menu);
}

/** Replaces a part at once (the caller restores focus): the result of the user's own scan request. */
export function forceMenuContent(menu, html) {
    applyMenuHtml(menu, html);
}

/**
 * Puts a refreshed dropdown into the menu without disturbing keyboard or screen-reader users. The status refresh runs
 * every 15 s, also while the dropdown is open. Unchanged markup is left alone. While focus is inside the menu the
 * markup is not replaced at all (no focus loss, no re-announcement on every poll of a running scan): the newest
 * markup waits and goes in as soon as focus leaves the menu, or with the next refresh after that. The toolbar icon is
 * updated by the caller either way.
 *
 * @param {Function} [onApplied] called after deferred markup went in (the caller re-syncs the theme)
 * @returns {boolean} whether the menu content was replaced now
 */
export function replaceMenuContent(menu, html, onApplied = () => {}) {
    if (renderedMenuHtml.get(menu) === html) {
        pendingMenuHtml.delete(menu);
        return false;
    }

    const active = menu.ownerDocument.activeElement;
    if (active !== null && active !== menu.ownerDocument.body && menu.contains(active)) {
        const isWaiting = pendingMenuHtml.has(menu);
        pendingMenuHtml.set(menu, html);
        if (!isWaiting) {
            const applyWhenFocusLeaves = (event) => {
                if (event.relatedTarget instanceof Node && menu.contains(event.relatedTarget)) {
                    return;
                }
                menu.removeEventListener('focusout', applyWhenFocusLeaves);
                if (pendingMenuHtml.has(menu)) {
                    applyMenuHtml(menu, pendingMenuHtml.get(menu));
                    onApplied();
                }
            };
            menu.addEventListener('focusout', applyWhenFocusLeaves);
        }

        return false;
    }

    applyMenuHtml(menu, html);

    return true;
}

export function documentUsesDarkTheme(doc, prefersDark = () => window.matchMedia?.('(prefers-color-scheme: dark)').matches === true) {
    if (!doc?.documentElement) {
        return false;
    }

    for (const element of [doc.documentElement, doc.body]) {
        if (!element) {
            continue;
        }
        const values = THEME_DATASET_KEYS.map((key) => String(element.dataset[key] || '').trim().toLowerCase());
        if (values.includes('dark')) {
            return true;
        }
        if (values.includes('light')) {
            return false;
        }
    }

    return prefersDark();
}

/**
 * The part of a refreshed dropdown the 15 s status refresh may replace: the status container when both the menu and
 * the answer have one (the assistant next to it keeps its state and focus), else the whole menu (older markup).
 */
export function statusUpdateTarget(menu, parsed) {
    return statusUpdateTargets(menu, parsed)[0];
}

/**
 * Every part a refresh may replace: each [data-aqt-refresh] the menu and the answer both have (header, scan
 * sections), else the status container, else the whole menu (older markup).
 */
export function statusUpdateTargets(menu, parsed) {
    const parts = Array.from(menu.querySelectorAll('[data-aqt-refresh]'))
        .map((element) => ({element, next: parsed.querySelector(`[data-aqt-refresh="${element.getAttribute('data-aqt-refresh')}"]`)}))
        .filter(({next}) => next !== null)
        .map(({element, next}) => ({element, html: next.innerHTML}));
    if (parts.length > 0) {
        return parts;
    }
    return [legacyStatusUpdateTarget(menu, parsed)];
}

function legacyStatusUpdateTarget(menu, parsed) {
    const current = menu.querySelector(A11yToolbarSelector.status);
    const next = parsed.querySelector(A11yToolbarSelector.status);
    if (current && next) {
        return {element: current, html: next.innerHTML};
    }

    return {element: menu, html: parsed.innerHTML};
}

/**
 * Opens or closes a scan section: the heading's button carries aria-expanded, its panel the hidden attribute.
 */
export function setDisclosure(button, expanded, doc = document) {
    const panel = doc.getElementById(button.getAttribute('aria-controls') ?? '');
    button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    if (panel) {
        panel.hidden = !expanded;
    }
}

/** Re-applies the sections the user opened after a refresh replaced their markup (all are collapsed by default). */
export function restoreDisclosures(root, expanded, doc = document) {
    root.querySelectorAll('[data-aqt-disclosure]').forEach((button) => {
        setDisclosure(button, expanded.has(button.getAttribute('data-aqt-disclosure')), doc);
    });
}

/** The state of each scan section, for announcing a finished scan once. */
export function sectionStates(root) {
    const states = {};
    root?.querySelectorAll('[data-aqt-source]').forEach((section) => {
        states[section.getAttribute('data-aqt-source')] = {
            state: section.getAttribute('data-aqt-state') ?? '',
            text: [
                section.querySelector('.aqt-scan__name')?.textContent?.trim() ?? '',
                section.getAttribute('data-aqt-status-label') ?? '',
                section.querySelector('.aqt-scan__summary')?.textContent?.replace(/^\s*,\s*/, '').trim() ?? '',
            ].filter((part) => part !== '').join(', '),
        };
    });
    return states;
}

/**
 * The announcement for sections whose scan stopped running between two refreshes ('' when none did): one message
 * per transition, nothing on routine refreshes, so screen readers do not hear the same status every 15 s.
 */
export function finishedAnnouncement(before, after) {
    return Object.entries(after)
        .filter(([key, value]) => ['running', 'queued'].includes(before[key]?.state) && !['running', 'queued'].includes(value.state))
        .map(([, value]) => value.text)
        .join('. ');
}

class A11yScanToolbarMenu {
    constructor() {
        this.syncTheme();
        this.observeThemeChanges();
        this.assistant = new ToolbarAssistant({
            slotSelector: A11yToolbarSelector.assistant,
            ajaxUrls: () => TYPO3.settings?.ajaxUrls ?? {},
            treePage: () => selectedTreePage(),
            navigate: (href, withReturnUrl) => this.navigate(href, withReturnUrl),
            onScanFinished: (options = {}) => this.updateMenu(false, options),
        });
        this.prepareToggle();
        // Scan sections the user opened in this backend window; collapsed by default and kept across refreshes.
        this.expanded = new Set();

        document.addEventListener('click', (event) => {
            const toggle = event.target instanceof Element ? event.target.closest(`${A11yToolbarSelector.element} [data-aqt-disclosure]`) : null;
            if (!toggle) {
                return;
            }
            event.preventDefault();
            const key = toggle.getAttribute('data-aqt-disclosure');
            const expanded = toggle.getAttribute('aria-expanded') !== 'true';
            if (expanded) {
                this.expanded.add(key);
            } else {
                this.expanded.delete(key);
            }
            setDisclosure(toggle, expanded);
        });

        document.querySelector(A11yToolbarSelector.dropdownToggle)?.addEventListener('click', () => {
            this.updateMenu();
        });
        // The assistant answers for what is open right now: loaded each time the dropdown opens, never by the refresh.
        // TYPO3 13 opens toolbar menus with Bootstrap (shown.bs.dropdown); TYPO3 14 with the Popover API. Its "toggle"
        // event does not bubble and TYPO3 converts a menu to a popover only when it is first clicked (also after a
        // toolbar re-render), so it is caught on the document in the capture phase, like TYPO3's own dropdown.js.
        document.addEventListener('shown.bs.dropdown', (event) => {
            if (event.target instanceof Element && event.target.matches(A11yToolbarSelector.dropdownToggle)) {
                this.assistant.load();
            }
        });
        document.addEventListener('toggle', (event) => {
            if (event.newState === 'open' && event.target instanceof Element && event.target.matches(A11yToolbarSelector.menu)) {
                this.assistant.load();
            }
        }, true);
        document.addEventListener('click', (event) => {
            const link = event.target instanceof Element ? event.target.closest(`${A11yToolbarSelector.element} a[data-aqt-navigate]`) : null;
            if (link && event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey) {
                event.preventDefault();
                this.navigate(link.getAttribute('href') ?? '', link.hasAttribute('data-aqt-return'));
            }
        });

        Viewport.Topbar.Toolbar.registerEvent(() => {
            this.prepareToggle();
            this.updateMenu();
        });

        window.setInterval(() => {
            if (!document.hidden) {
                this.updateMenu(false);
            }
        }, 15000);

        window.setInterval(() => {
            if (!document.hidden) {
                this.syncTheme();
            }
        }, 1000);

        window.setTimeout(() => this.updateMenu(false), 0);
    }

    getThemeDocuments() {
        const documents = [];
        const addDocument = (doc) => {
            if (doc?.documentElement && !documents.includes(doc)) {
                documents.push(doc);
            }
        };

        addDocument(document);

        try {
            addDocument(window.top?.document);
        } catch {
            // Ignore cross-frame restrictions.
        }

        ['#typo3-contentIframe', 'iframe[name="content"]', 'iframe[name="list_frame"]', 'iframe.module-body'].forEach((selector) => {
            document.querySelectorAll(selector).forEach((frame) => {
                try {
                    addDocument(frame.contentDocument || frame.contentWindow?.document);
                } catch {
                    // Ignore inaccessible frames.
                }
            });
        });

        return documents;
    }

    syncTheme() {
        // The toolbar lives in the top frame, which carries the backend colour scheme.
        const isDark = documentUsesDarkTheme(document);
        const themeName = isDark ? 'dark' : 'light';
        const root = document.querySelector(A11yToolbarSelector.element);
        root?.classList.toggle('aqt-toolbar--theme-dark', isDark);
        root?.classList.toggle('aqt-toolbar--theme-light', !isDark);
        root?.setAttribute('data-aqt-theme', themeName);

        const menu = document.querySelector(A11yToolbarSelector.menu);
        menu?.classList.toggle('aqt-dropdown--theme-dark', isDark);
        menu?.classList.toggle('aqt-dropdown--theme-light', !isDark);
        menu?.setAttribute('data-aqt-theme', themeName);

        document.querySelectorAll(`${A11yToolbarSelector.element} .aqt-pop`).forEach((panel) => {
            panel.classList.toggle('aqt-pop--theme-dark', isDark);
            panel.classList.toggle('aqt-pop--theme-light', !isDark);
            panel.setAttribute('data-aqt-theme', themeName);
        });
    }

    observeThemeChanges() {
        try {
            const observer = new MutationObserver(() => this.syncTheme());

            this.getThemeDocuments().forEach((doc) => {
                observer.observe(doc.documentElement, {
                    attributes: true,
                    attributeFilter: ['class', 'style', 'data-theme', 'data-bs-theme', 'data-color-scheme', 'data-typo3-theme', 'data-theme-mode', 'data-user-theme', 'data-appearance'],
                });
                if (doc.body) {
                    observer.observe(doc.body, {
                        attributes: true,
                        attributeFilter: ['class', 'style', 'data-theme', 'data-bs-theme', 'data-color-scheme', 'data-typo3-theme', 'data-theme-mode', 'data-user-theme', 'data-appearance'],
                    });
                }
            });
        } catch {
            // Theme observation is a progressive enhancement only.
        }

        try {
            window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener('change', () => this.syncTheme());
        } catch {
            // noop
        }
    }

    /**
     * Buttons inside the assistant must not close the dropdown: Bootstrap (TYPO3 13) closes it on any inside click by
     * default; TYPO3 14's popover menu only closes on outside clicks and focus leaving it. Called again whenever TYPO3
     * re-renders the toolbar.
     */
    prepareToggle() {
        document.querySelector(A11yToolbarSelector.dropdownToggle)?.setAttribute('data-bs-auto-close', 'outside');
        // The dropdown holds a status, advice, a form field and buttons, not menu items: TYPO3's role="menu" would make
        // screen readers expect menuitems and skip the text.
        document.querySelector(A11yToolbarSelector.menu)?.removeAttribute('role');
    }

    isMenuOpen() {
        const menu = document.querySelector(A11yToolbarSelector.menu);
        try {
            if (menu?.matches(':popover-open')) {
                return true;
            }
        } catch {
            // No Popover API: Bootstrap state below.
        }

        return document.querySelector(A11yToolbarSelector.dropdownToggle)?.getAttribute('aria-expanded') === 'true';
    }

    closeMenu() {
        const menu = document.querySelector(A11yToolbarSelector.menu);
        if (menu && menu.hasAttribute('popover') && typeof menu.hidePopover === 'function') {
            try {
                menu.hidePopover();
            } catch {
                // Already closed.
            }
            return;
        }
        const toggle = document.querySelector(A11yToolbarSelector.dropdownToggle);
        if (toggle?.getAttribute('aria-expanded') === 'true') {
            toggle.click();
        }
    }

    /** Opens a backend URL in the module content frame (with the current view as return URL) and closes the dropdown. */
    navigate(href, withReturnUrl = false) {
        if (this.isMenuOpen()) {
            this.closeMenu();
        }
        if (!href) {
            return;
        }
        let target = href;
        if (withReturnUrl) {
            try {
                const current = new URL(this.resolveCurrentBackendModuleUrl().toString());
                const url = new URL(href, window.location.origin);
                url.searchParams.set('returnUrl', current.pathname + current.search);
                target = url.pathname + url.search;
            } catch {
                target = href;
            }
        }
        const container = window.TYPO3?.Backend?.ContentContainer;
        if (typeof container?.setUrl === 'function') {
            container.setUrl(target);
        } else {
            window.location.href = target;
        }
    }

    /**
     * @param {boolean} showLoading
     * @param {{contentScan?: boolean, refocus?: boolean}} options after the user's own "Check saved page now" the new
     *   result goes in at once (focus is moved back to the re-rendered button), not only when focus leaves the menu
     */
    async updateMenu(showLoading = true, options = {}) {
        this.setIconLoading(showLoading);

        try {
            const url = withToolbarContext(
                new URL(TYPO3.settings.ajaxUrls.a11y_toolbar_render, window.location.origin),
                this.resolveCurrentBackendModuleUrl(),
                selectedTreePage()
            );

            const response = await new AjaxRequest(url.toString()).get();
            const html = await response.resolve();
            const parsed = document.createElement('div');
            parsed.innerHTML = html;

            const stateElement = parsed.querySelector(A11yToolbarSelector.data);
            if (stateElement) {
                this.applyIconState(stateElement);
            }

            const menu = document.querySelector(A11yToolbarSelector.menu);
            if (menu) {
                const before = sectionStates(menu);
                statusUpdateTargets(menu, parsed).forEach(({element, html: partHtml}) => {
                    const applied = (refocus = false) => {
                        restoreDisclosures(element, this.expanded);
                        this.syncTheme();
                        this.assistant.syncContentScan({refocus});
                    };
                    if (options.contentScan === true && element.querySelector('[data-aqt-content-scan]')) {
                        forceMenuContent(element, partHtml);
                        applied(options.refocus === true);
                    } else if (replaceMenuContent(element, partHtml, applied)) {
                        applied();
                    }
                });
                const message = finishedAnnouncement(before, sectionStates(menu));
                const live = menu.querySelector('[data-aqt-scan-live]');
                if (message !== '' && live && this.isMenuOpen()) {
                    live.textContent = message;
                }
            }
        } catch (error) {
            console.warn('[A11Y] Toolbar menu refresh failed', error);
        } finally {
            this.setIconLoading(false);
        }
    }

    resolveCurrentBackendModuleUrl() {
        const iframeSelectors = [
            '#typo3-contentIframe',
            'iframe[name="content"]',
            'iframe[name="list_frame"]',
            'iframe.module-body',
        ];

        for (const selector of iframeSelectors) {
            const frame = document.querySelector(selector);
            const candidates = [
                frame?.contentWindow?.location?.href || '',
                frame?.getAttribute('src') || '',
            ];

            for (const candidate of candidates) {
                if (candidate === '') {
                    continue;
                }

                try {
                    const url = new URL(candidate, window.location.origin);
                    if (url.searchParams.has('id') || url.searchParams.has('language') || url.searchParams.has('languageUid')) {
                        return url;
                    }
                } catch (error) {
                    // Ignore inaccessible or invalid frame URLs and fall back to the current window URL.
                }
            }
        }

        try {
            if (window.parent && window.parent !== window && window.parent.location?.href) {
                return new URL(window.parent.location.href);
            }
        } catch (error) {
            // Cross-frame access can be blocked by the browser. The current URL is still safe.
        }

        return new URL(window.location.href);
    }

    setIconLoading(isLoading) {
        const iconElement = document.querySelector(A11yToolbarSelector.icon);
        iconElement?.classList.toggle('aqt-toolbar-trigger--loading', isLoading);
    }

    applyIconState(stateElement) {
        const iconElement = document.querySelector(A11yToolbarSelector.icon);
        if (!iconElement) {
            return;
        }

        const state = stateElement.dataset.a11yState || 'ok';
        const tone = stateElement.dataset.a11yTone || 'ok';
        const count = Number.parseInt(stateElement.dataset.a11yCount || '0', 10);
        const label = stateElement.dataset.a11yLabel || '';

        Array.from(iconElement.classList)
            .filter((className) => className.startsWith('aqt-toolbar-trigger--'))
            .forEach((className) => iconElement.classList.remove(className));

        iconElement.classList.add(`aqt-toolbar-trigger--${state}`);

        if (label) {
            iconElement.setAttribute('aria-label', label);
        }

        const iconWrapper = iconElement.querySelector('.aqt-toolbar-icon');
        if (!iconWrapper) {
            return;
        }

        iconWrapper.querySelector('.aqt-toolbar-dot, .aqt-toolbar-badge')?.remove();

        const dot = document.createElement('span');
        dot.className = `aqt-toolbar-dot aqt-toolbar-dot--${state === 'running' ? 'running' : tone}`;
        if (count > 0) {
            dot.setAttribute('data-aqt-count', String(count));
        }
        iconWrapper.appendChild(dot);
    }
}

export default typeof window !== 'undefined' && window.TYPO3 ? new A11yScanToolbarMenu() : null;
