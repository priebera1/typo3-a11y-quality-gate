import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Viewport from '@typo3/backend/viewport.js';

let A11yToolbarSelector;
(function (selector) {
    selector.element = '#priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem';
    selector.icon = '#priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem .toolbar-item-icon .t3js-icon';
    selector.menu = '#priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem .dropdown-menu';
    selector.dropdownToggle = '#priebera-a11yqualitygate-backend-toolbaritems-a11yscantoolbaritem .toolbar-item-link.dropdown-toggle';
    selector.data = '[data-a11y-toolbar-data]';
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

class A11yScanToolbarMenu {
    constructor() {
        this.syncTheme();
        this.observeThemeChanges();

        document.querySelector(A11yToolbarSelector.dropdownToggle)?.addEventListener('click', () => {
            this.updateMenu();
        });

        Viewport.Topbar.Toolbar.registerEvent(() => {
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

    async updateMenu(showLoading = true) {
        this.setIconLoading(showLoading);

        try {
            const url = new URL(TYPO3.settings.ajaxUrls.a11y_toolbar_render, window.location.origin);
            const currentUrl = this.resolveCurrentBackendModuleUrl();

            ['id', 'site', 'pageUid', 'language', 'languageUid', 'L', 'sys_language_uid'].forEach((key) => {
                const value = currentUrl.searchParams.get(key);
                if (value !== null && value !== '') {
                    url.searchParams.set(key, value);
                }
            });

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
                menu.innerHTML = html;
                this.syncTheme();
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
