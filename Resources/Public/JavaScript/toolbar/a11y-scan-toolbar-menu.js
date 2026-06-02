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


const DARK_THEME_SELECTORS = [
    '[data-bs-theme="dark"]',
    '[data-color-scheme="dark"]',
    '[data-theme="dark"]',
    '[data-typo3-theme="dark"]',
    '[data-theme-mode="dark"]',
    '[data-user-theme="dark"]',
    '[data-appearance="dark"]',
    '.typo3-backend-dark',
    '.typo3-theme-dark',
    '.theme-dark',
    '.t3js-theme-dark',
    '.aqi-theme-dark',
];

const LIGHT_THEME_SELECTORS = [
    '[data-bs-theme="light"]',
    '[data-color-scheme="light"]',
    '[data-theme="light"]',
    '[data-typo3-theme="light"]',
    '[data-theme-mode="light"]',
    '[data-user-theme="light"]',
    '[data-appearance="light"]',
    '.typo3-backend-light',
    '.typo3-theme-light',
    '.theme-light',
    '.t3js-theme-light',
];

const THEME_DATASET_KEYS = [
    'bsTheme',
    'colorScheme',
    'theme',
    'typo3Theme',
    'themeMode',
    'userTheme',
    'appearance',
];

const THEME_COLOR_VARIABLES = [
    '--typo3-component-bg',
    '--typo3-surface-container-lowest',
    '--typo3-surface-container-base',
    '--typo3-scaffold-content-bg',
    '--bs-body-bg',
    '--bs-dropdown-bg',
];

function parseRgbColor(value) {
    const match = String(value || '').match(/rgba?\(([^)]+)\)/i);
    if (!match) {
        return null;
    }

    const parts = match[1].split(',').map((part) => part.trim());
    if (parts.length < 3) {
        return null;
    }

    const toChannel = (part) => {
        if (part.endsWith('%')) {
            return Math.round(Number.parseFloat(part) * 2.55);
        }
        return Number.parseFloat(part);
    };

    const rgb = parts.slice(0, 3).map(toChannel);
    if (rgb.some((channel) => !Number.isFinite(channel))) {
        return null;
    }

    const alpha = parts[3] === undefined ? 1 : Number.parseFloat(parts[3]);
    return { r: rgb[0], g: rgb[1], b: rgb[2], a: Number.isFinite(alpha) ? alpha : 1 };
}

function resolveCssColor(doc, value) {
    const raw = String(value || '').trim();
    if (raw === '' || raw === 'transparent') {
        return null;
    }

    const probe = doc.createElement('span');
    probe.style.position = 'absolute';
    probe.style.visibility = 'hidden';
    probe.style.pointerEvents = 'none';
    probe.style.color = raw;
    (doc.body || doc.documentElement).appendChild(probe);

    const resolved = doc.defaultView?.getComputedStyle(probe).color || '';
    probe.remove();

    return parseRgbColor(resolved);
}

function luminance(color) {
    const transform = (channel) => {
        const value = channel / 255;
        return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * transform(color.r) + 0.7152 * transform(color.g) + 0.0722 * transform(color.b);
}

function colorLooksDark(color) {
    return color.a > 0.2 && luminance(color) < 0.28;
}

function computedStylesUseDarkTheme(doc) {
    const view = doc.defaultView || window;
    const rootStyle = view.getComputedStyle(doc.documentElement);
    const bodyStyle = doc.body ? view.getComputedStyle(doc.body) : null;

    const colorScheme = [rootStyle.colorScheme, bodyStyle?.colorScheme]
        .filter(Boolean)
        .map((value) => String(value).trim().toLowerCase());

    if (colorScheme.some((value) => value === 'dark')) {
        return true;
    }

    const colorCandidates = [];
    THEME_COLOR_VARIABLES.forEach((name) => {
        colorCandidates.push(rootStyle.getPropertyValue(name));
        if (bodyStyle) {
            colorCandidates.push(bodyStyle.getPropertyValue(name));
        }
    });

    const surfaceSelectors = [
        '.module',
        '.module-body',
        '.module-docheader',
        '.t3js-module-body',
        '.typo3-module',
        'main',
    ];

    surfaceSelectors.forEach((selector) => {
        const element = doc.querySelector(selector);
        if (!element) {
            return;
        }

        const style = view.getComputedStyle(element);
        colorCandidates.push(style.backgroundColor);
    });

    return colorCandidates.some((candidate) => {
        const color = resolveCssColor(doc, candidate);
        return color !== null && colorLooksDark(color);
    });
}

function documentUsesDarkTheme(doc) {
    if (!doc?.documentElement) {
        return false;
    }

    const datasetValues = THEME_DATASET_KEYS.map((key) => String(doc.documentElement.dataset[key] || '').trim().toLowerCase());

    if (datasetValues.includes('dark')) {
        return true;
    }

    if (datasetValues.includes('light')) {
        return false;
    }

    if (datasetValues.includes('auto')) {
        return window.matchMedia?.('(prefers-color-scheme: dark)').matches === true;
    }

    const candidates = [doc.documentElement, doc.body].filter(Boolean);
    const hasDarkSelector = candidates.some((element) => DARK_THEME_SELECTORS.some((selector) => {
        try {
            return element.matches(selector) || Boolean(element.querySelector(selector));
        } catch {
            return false;
        }
    }));

    if (hasDarkSelector) {
        return true;
    }

    const hasLightSelector = candidates.some((element) => LIGHT_THEME_SELECTORS.some((selector) => {
        try {
            return element.matches(selector) || Boolean(element.querySelector(selector));
        } catch {
            return false;
        }
    }));

    if (hasLightSelector) {
        return false;
    }

    return computedStylesUseDarkTheme(doc);
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
        const isDark = this.getThemeDocuments().some((doc) => documentUsesDarkTheme(doc));
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

export default new A11yScanToolbarMenu();
