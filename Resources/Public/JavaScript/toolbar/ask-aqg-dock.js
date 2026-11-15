import {el} from '@priebera/a11y-quality-gate/remediation/remediation-api.js';

/**
 * Ask AQG docked beside the TYPO3 workspace (AQG 3.0.0): a column on the RIGHT of the backend's content area that takes
 * its width from the workspace instead of covering it, so the page tree, the module, record forms, CKEditor and their
 * Save/Close buttons stay visible and usable next to the assistant.
 *
 * TYPO3 has no public API for a docked panel. Both 13.4 and 14.x render the area below the top bar as
 * `.scaffold-content` (13: a flex row of page tree, switcher and module; 14: one <typo3-backend-content-navigation>
 * holding tree and module). The dock is appended as the last child of that element and the element becomes a flex
 * row while the dock is open: the core grid (sidebar | content) is not touched, nothing is positioned over TYPO3, and
 * removing the dock restores the original layout exactly. The room is measured on the TYPO3 *module* column (the page
 * tree is not counted: TYPO3 13 keeps it inside the same row, 14 inside its content navigation), so the editor keeps
 * MIN_WORKSPACE next to the dock whatever the tree's width or state. Where that does not leave the dock its minimum
 * width, the dock is not offered and Ask AQG uses its dialog instead (dock.available(), onNoRoom).
 *
 * The dock only hosts the assistant's one DOM root: the conversation, pending requests and plans are the toolbar's
 * (ToolbarAssistant). Width (a number) and open/minimised state are kept per browser tab, under a key of the server's
 * opaque principal (user + login session), so another user or login never inherits them.
 */
export const DOCK_DEFAULT_WIDTH = 420;
export const DOCK_MIN_WIDTH = 320;
export const DOCK_MAX_WIDTH = 720;
/** The TYPO3 module column (record form, Page module; the page tree not included) never gets narrower than this. */
export const MIN_WORKSPACE = 560;
/** Below this viewport width the dock is not offered at all: TYPO3 itself switches to its mobile layout. */
export const MIN_VIEWPORT = 1024;
const KEY_STEP = 16;
const KEY_STEP_LARGE = 64;
const STATE_KEY = 'aqg-ask-dock:';

/** The workspace element the dock shares (both TYPO3 13.4 and 14.x), or null. */
export function dockContainer(doc) {
    return doc.querySelector('.scaffold .scaffold-content') ?? doc.querySelector('.t3js-scaffold-content');
}

/** The element of the TYPO3 module inside the workspace container (both 13.4 and 14.x), or null. */
export function moduleElement(container) {
    return container?.querySelector('typo3-iframe-module') ?? container?.querySelector('.scaffold-content-module') ?? null;
}

/**
 * Clamps a width into what the layout allows now: `sharedWidth` is the width the dock and the TYPO3 module share
 * (module column + dock, the page tree excluded); the module keeps MIN_WORKSPACE of it.
 */
export function clampWidth(width, sharedWidth) {
    const room = Math.max(0, Math.floor(sharedWidth) - MIN_WORKSPACE);
    const max = Math.max(DOCK_MIN_WIDTH, Math.min(DOCK_MAX_WIDTH, room));
    const value = Number.isFinite(Number(width)) ? Math.round(Number(width)) : DOCK_DEFAULT_WIDTH;

    return Math.min(max, Math.max(DOCK_MIN_WIDTH, value));
}

export class AskAqgDock {
    /**
     * @param {{win?: Window, label?: (key: string, fallback: string) => string, onClose?: () => void, onMinimize?: (minimized: boolean) => void, onNavigate?: (href: string, withReturnUrl: boolean) => void}} options
     */
    constructor({win = window, label = (key, fallback) => fallback, onClose = () => {}, onMinimize = () => {}, onNavigate = () => {}, onNoRoom = () => {}} = {}) {
        this.win = win;
        this.doc = win.document;
        this.label = label;
        this.onClose = onClose;
        this.onMinimize = onMinimize;
        this.onNavigate = onNavigate;
        this.onNoRoom = onNoRoom;
        this.element = null;
        this.body = null;
        this.minimized = false;
        this.principal = '';
        this.width = DOCK_DEFAULT_WIDTH;
        // The width the person chose (resizer) or the default; `width` is what the layout allows of it now, so a
        // temporarily narrow window or an opened page tree never overwrites the preference.
        this.preferred = DOCK_DEFAULT_WIDTH;
        this.onResize = () => this.fit();
        this.observer = null;
        this.observed = null;
    }

    /**
     * The width the dock and the TYPO3 module share now: the module column plus the dock (open or as its rail). The page
     * tree keeps its own width (expanded, collapsed, resized by the person or shown as TYPO3 14's overlay). 0 without a
     * layout (jsdom).
     */
    sharedWidth() {
        const container = dockContainer(this.doc);
        const module = moduleElement(container);
        const moduleWidth = module ? module.getBoundingClientRect().width : 0;
        if (!container || moduleWidth === 0) {
            return container ? container.getBoundingClientRect().width : 0;
        }
        return moduleWidth + (this.isOpen() ? this.element.getBoundingClientRect().width : 0);
    }

    /** Whether the dock fits next to the TYPO3 module now (else the caller uses its dialog). */
    available() {
        if (!dockContainer(this.doc) || this.win.innerWidth < MIN_VIEWPORT) {
            return false;
        }
        return this.fits();
    }

    fits() {
        const shared = this.sharedWidth();
        // jsdom (tests) has no layout: then the viewport decides.
        return (shared === 0 ? this.win.innerWidth : shared) - MIN_WORKSPACE >= DOCK_MIN_WIDTH;
    }

    isOpen() {
        return this.element !== null && this.element.isConnected;
    }

    /** The state kept for this principal in this tab: {open, minimized, width}. */
    stored(principal = this.principal) {
        try {
            const data = JSON.parse(this.win.sessionStorage.getItem(STATE_KEY + principal) ?? 'null');
            return data && typeof data === 'object' ? {open: data.open === true, minimized: data.minimized === true, width: Number(data.width) || DOCK_DEFAULT_WIDTH} : {open: false, minimized: false, width: DOCK_DEFAULT_WIDTH};
        } catch {
            return {open: false, minimized: false, width: DOCK_DEFAULT_WIDTH};
        }
    }

    remember() {
        if (!/^[a-f0-9]{16,128}$/.test(this.principal)) {
            return;
        }
        try {
            this.win.sessionStorage.setItem(STATE_KEY + this.principal, JSON.stringify({open: this.isOpen(), minimized: this.minimized, width: this.preferred}));
        } catch {
            // Nothing kept: the dock opens with the default width next time.
        }
    }

    /** Opens the dock (or keeps it open) for the given principal; returns its body to host the assistant root. */
    open(principal = '') {
        if (/^[a-f0-9]{16,128}$/.test(principal) && principal !== this.principal) {
            this.principal = principal;
            this.preferred = this.stored().width;
            this.width = this.preferred;
        }
        const container = dockContainer(this.doc);
        if (!container) {
            return null;
        }
        if (!this.element) {
            this.build();
        }
        if (this.element.parentElement !== container) {
            container.append(this.element);
        }
        container.classList.add('aqt-docked');
        this.doc.documentElement.classList.add('aqt-dock-open');
        this.win.addEventListener('resize', this.onResize);
        this.observe(container);
        this.setMinimized(false, {silent: true});
        this.fit();
        this.remember();

        return this.body;
    }

    /** Removes the dock; TYPO3's layout returns to exactly what it was. The caller moves the assistant root out first. */
    close() {
        const container = this.element?.parentElement ?? dockContainer(this.doc);
        container?.classList.remove('aqt-docked', 'aqt-docked--minimized', 'aqt-dock-resizing');
        container?.style.removeProperty('--aqt-dock-width');
        this.doc.documentElement.classList.remove('aqt-dock-open');
        this.win.removeEventListener('resize', this.onResize);
        this.observer?.disconnect();
        this.observed = null;
        this.element?.remove();
        this.minimized = false;
        this.remember();
    }

    setMinimized(minimized, {silent = false} = {}) {
        this.minimized = minimized;
        const container = this.element?.parentElement;
        container?.classList.toggle('aqt-docked--minimized', minimized);
        this.element?.classList.toggle('aqt-dock--minimized', minimized);
        if (this.body) {
            // Hidden, not removed: pending requests and plans keep running and are there again on restore.
            this.body.hidden = minimized;
        }
        if (this.rail) {
            this.rail.hidden = !minimized;
        }
        this.fit();
        this.remember();
        if (!silent) {
            this.onMinimize(minimized);
        }
    }

    /**
     * The window is not the only thing that changes the module's width: opening, collapsing or resizing the page tree
     * (and TYPO3 14 switching it to an overlay) does too. The module column is observed, so the dock gives way at once.
     */
    observe(container) {
        const module = moduleElement(container);
        const Observer = this.win.ResizeObserver;
        if (!module || typeof Observer !== 'function' || this.observed === module) {
            return;
        }
        this.observer?.disconnect();
        this.observer = new Observer(() => this.fit());
        this.observer.observe(module);
        this.observed = module;
    }

    currentWidth() {
        return this.minimized ? 44 : this.width;
    }

    /** Applies the width the viewport allows now; without room for both the dock minimises itself. */
    fit() {
        const container = this.element?.parentElement;
        if (!container) {
            return;
        }
        this.observe(container);
        const shared = this.sharedWidth();
        const layout = moduleElement(container) !== null && shared > 0;
        if (layout && !this.minimized && shared - MIN_WORKSPACE < DOCK_MIN_WIDTH) {
            // No room for the editor and the dock: the dock gives way (rail); the conversation keeps running.
            this.setMinimized(true);
            return;
        }
        this.width = layout ? clampWidth(this.preferred, shared) : clampWidth(this.preferred, Number.MAX_SAFE_INTEGER);
        const value = `${this.currentWidth()}px`;
        if (container.style.getPropertyValue('--aqt-dock-width') !== value) {
            container.style.setProperty('--aqt-dock-width', value);
        }
        this.syncSeparator(layout ? shared : 0);
    }

    resizeTo(width) {
        const container = this.element?.parentElement;
        const shared = moduleElement(container) !== null ? this.sharedWidth() : 0;
        this.width = shared > 0 ? clampWidth(width, shared) : Math.min(DOCK_MAX_WIDTH, Math.max(DOCK_MIN_WIDTH, Math.round(width)));
        this.preferred = this.width;
        container?.style.setProperty('--aqt-dock-width', `${this.width}px`);
        this.syncSeparator(shared);
        this.remember();
    }

    syncSeparator(total) {
        if (!this.separator) {
            return;
        }
        const max = total > 0 ? clampWidth(DOCK_MAX_WIDTH, total) : DOCK_MAX_WIDTH;
        this.separator.setAttribute('aria-valuemin', String(DOCK_MIN_WIDTH));
        this.separator.setAttribute('aria-valuemax', String(max));
        this.separator.setAttribute('aria-valuenow', String(this.width));
        this.separator.setAttribute('aria-valuetext', `${this.width} px`);
    }

    build() {
        const label = this.label;
        this.separator = el('div', {
            class: 'aqt-dock__resizer',
            role: 'separator',
            'aria-orientation': 'vertical',
            'aria-label': label('dockResize', 'Resize Ask AQG panel'),
            tabindex: '0',
            'data-aqt-dock-resizer': 'true',
        });
        this.body = el('div', {class: 'aqt-dock__body', 'data-aqt-dock-body': 'true'});
        // Panel chrome as icon buttons beside the title (their names stay for screen readers and as tooltips), so the
        // panel's first row is the assistant's own header, not a separate bar of words.
        const control = (key, fallback, data, icon) => el('button', {type: 'button', class: `aqt-btn aqt-btn--icon aqt-dock__control aqt-dock__control--${icon}`, [data]: 'true', title: label(key, fallback)},
            el('span', {class: 'visually-hidden', text: label(key, fallback)}));
        const minimize = control('dockMinimize', 'Minimize', 'data-aqt-dock-minimize', 'minimize');
        const close = control('dockClose', 'Close panel', 'data-aqt-dock-close', 'close');
        this.controls = el('div', {class: 'aqt-dock__controls', role: 'group', 'aria-label': label('dockControls', 'Ask AQG panel')}, minimize, close);
        this.rail = el('button', {type: 'button', class: 'aqt-dock__rail', 'data-aqt-dock-restore': 'true', hidden: true,
            'aria-label': label('dockRestore', 'Show Ask AQG')}, el('span', {class: 'aqt-dock__rail-text', 'aria-hidden': 'true', text: 'Ask AQG'}));
        this.element = el('aside', {class: 'aqt-dock', 'data-aqt-dock': 'true', 'aria-labelledby': 'aqt-assistant-title'},
            this.separator, this.rail, el('div', {class: 'aqt-dock__frame'}, this.controls, this.body));

        minimize.addEventListener('click', () => {
            this.setMinimized(true);
            this.rail.focus({preventScroll: true});
        });
        this.rail.addEventListener('click', () => {
            if (moduleElement(this.element.parentElement) !== null && this.sharedWidth() > 0 && !this.fits()) {
                // Restoring would squeeze the editor below its minimum: the caller shows the assistant in its dialog.
                this.onNoRoom();
                return;
            }
            this.setMinimized(false);
            (this.body.querySelector('[data-aqt-ask-input]') ?? minimize).focus({preventScroll: true});
        });
        close.addEventListener('click', () => this.onClose());
        this.element.addEventListener('click', (event) => {
            const link = event.target instanceof this.win.Element ? event.target.closest('a[data-aqt-navigate]') : null;
            if (link && event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey) {
                // Outside the toolbar its link handler cannot see the click; the dock stays open next to the target.
                event.preventDefault();
                this.onNavigate(link.getAttribute('href') ?? '', link.hasAttribute('data-aqt-return'));
            }
        });
        this.bindResizer();
    }

    bindResizer() {
        const separator = this.separator;
        separator.addEventListener('keydown', (event) => {
            const step = event.shiftKey ? KEY_STEP_LARGE : KEY_STEP;
            // The dock grows to the left: ArrowLeft makes it wider, ArrowRight narrower.
            const next = {
                ArrowLeft: this.width + step,
                ArrowRight: this.width - step,
                Home: DOCK_MIN_WIDTH,
                End: DOCK_MAX_WIDTH,
            }[event.key];
            if (next === undefined) {
                return;
            }
            event.preventDefault();
            this.resizeTo(next);
        });
        separator.addEventListener('dblclick', () => this.resizeTo(DOCK_DEFAULT_WIDTH));
        separator.addEventListener('pointerdown', (event) => {
            if (event.button !== 0) {
                return;
            }
            event.preventDefault();
            const container = this.element.parentElement;
            const startX = event.clientX;
            const startWidth = this.width;
            // The module iframe would swallow the pointer while it is over it: it ignores pointers during the drag.
            container?.classList.add('aqt-dock-resizing');
            try {
                separator.setPointerCapture?.(event.pointerId);
            } catch {
                // No capture: the document listeners below still follow the pointer.
            }
            const move = (moveEvent) => this.resizeTo(startWidth + (startX - moveEvent.clientX));
            const end = () => {
                container?.classList.remove('aqt-dock-resizing');
                this.doc.removeEventListener('pointermove', move);
                this.doc.removeEventListener('pointerup', end);
                this.doc.removeEventListener('pointercancel', end);
                this.win.removeEventListener('blur', end);
            };
            this.doc.addEventListener('pointermove', move);
            this.doc.addEventListener('pointerup', end);
            this.doc.addEventListener('pointercancel', end);
            this.win.addEventListener('blur', end);
        });
    }

    /** Applies the backend colour scheme the toolbar resolved (same classes as the dialog). */
    setTheme(dark, light) {
        this.element?.classList.toggle('aqt-dialog--theme-dark', dark);
        this.element?.classList.toggle('aqt-dialog--theme-light', light);
    }
}
