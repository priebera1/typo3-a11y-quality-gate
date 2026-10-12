import { FREE_SELECTORS } from '@priebera/a11y-quality-gate/backend/core/constants.js';
import { notificationSeverity } from '@priebera/a11y-quality-gate/backend/core/notification-severity.js';
import { moduleUrlWithTarget, showScanNotification, withoutSelectedScan } from '@priebera/a11y-quality-gate/backend/core/scan-notification.js';

/** Original child nodes of buttons in their loading state, so icon-only buttons get their icon back. */
const loadingContent = new WeakMap();

/** Scan actions keep their label while busy: the scan status block says what is running, not the button. */
const SCAN_ACTIONS = '[data-action="a11y-rescan"], [data-action="a11y-scan-all"], [data-action="a11y-pro-scan-page"], [data-action="a11y-pro-scan-site"]';

export class A11yBaseModule {
    constructor() {
        this.statusPollTimer = null;
        this.translations = TYPO3?.lang || {};

        this.initLanguageDropdowns();
        this.initDisclosures();
    }

    initNewTabLinks() {
        document.querySelectorAll('[data-open-new-tab="1"]').forEach((link) => {
            link.setAttribute('target', '_blank');
            link.setAttribute('rel', 'noopener noreferrer');
        });
    }



    initLanguageDropdowns() {
        document.addEventListener('click', (event) => {
            const clickedDropdown = event.target.closest('details.aqg-lang--dropdown');

            document.querySelectorAll('details.aqg-lang--dropdown[open]').forEach((dropdown) => {
                if (clickedDropdown !== dropdown) {
                    dropdown.open = false;
                }
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            document.querySelectorAll('details.aqg-lang--dropdown[open]').forEach((dropdown) => {
                dropdown.open = false;
            });
        });
    }

    setLoadingState(button, isLoading) {
        // Icon buttons keep their size and icon: a spinner replaces the icon and the row says what is happening.
        if (button.dataset.aqgLoading === 'spinner') {
            this.setSpinnerLoadingState(button, isLoading);
            return;
        }

        if (button.matches(SCAN_ACTIONS)) {
            button.disabled = isLoading;
            if (isLoading) {
                button.setAttribute('aria-busy', 'true');
            } else {
                button.removeAttribute('aria-busy');
            }
            return;
        }

        button.disabled = isLoading;

        if (isLoading) {
            if (!button.dataset.originalText) {
                button.dataset.originalText = button.textContent.trim();
            }
            // Icon buttons have no text to restore: keep the original nodes (icon, visually hidden name) instead.
            if (!loadingContent.has(button)) {
                loadingContent.set(button, Array.from(button.childNodes));
            }

            button.textContent = button.dataset.loadingText || this.translate('action.scanning', 'Scanning...');
            return;
        }

        const originalContent = loadingContent.get(button);
        if (originalContent) {
            loadingContent.delete(button);
            button.replaceChildren(...originalContent);
            return;
        }

        button.textContent = button.dataset.originalText || button.textContent;
    }

    /**
     * An icon-only scan button stays focusable while busy (aria-disabled, not disabled), so a keyboard user keeps
     * their place. Its name and the row's meta line say that this page is being scanned.
     */
    setSpinnerLoadingState(button, isLoading) {
        const row = button.closest('[data-a11y-page-row]');
        const state = row?.querySelector('[data-a11y-row-state]');
        const pageTitle = String(button.dataset.pageTitle || row?.dataset.a11yPageTitle || '').trim();

        if (isLoading) {
            if (button.dataset.originalLabel === undefined) {
                button.dataset.originalLabel = button.getAttribute('aria-label') || '';
            }
            button.classList.add('is-busy');
            button.setAttribute('aria-disabled', 'true');
            button.setAttribute(
                'aria-label',
                this.format(this.translate('overview.localScan.rowScanningLabel', 'Scanning %s'), pageTitle)
            );
            if (row) {
                row.classList.add('is-running');
                row.setAttribute('aria-busy', 'true');
            }
            if (state) {
                const time = this.formatClockTime(new Date());
                state.textContent = this.format(
                    this.translate('overview.localScan.rowRunning', 'Scanning · started %s'),
                    time
                );
                // The counts of the row stay in place, dimmed: say that they are the previous result.
                const previous = document.createElement('span');
                previous.className = 'visually-hidden';
                previous.textContent = ` ${this.translate('overview.localScan.rowPreviousCounts', 'The counts show the previous result.')}`;
                state.appendChild(previous);
                state.hidden = false;
            }
            return;
        }

        button.classList.remove('is-busy');
        button.removeAttribute('aria-disabled');
        if (button.dataset.originalLabel !== undefined) {
            button.setAttribute('aria-label', button.dataset.originalLabel);
            delete button.dataset.originalLabel;
        }
        if (row) {
            row.classList.remove('is-running');
            row.removeAttribute('aria-busy');
        }
        if (state) {
            state.textContent = '';
            state.hidden = true;
        }
    }

    /**
     * Enables or disables a scan control. Icon buttons in table rows use aria-disabled so they stay in the tab order
     * and keep focus; text buttons use the native disabled state.
     */
    setScanControlAvailable(button, available, reason = '') {
        if (button.dataset.aqgLoading === 'spinner') {
            if (button.classList.contains('is-busy')) {
                return;
            }
            if (available) {
                button.removeAttribute('aria-disabled');
                if (button.dataset.availableTitle !== undefined) {
                    if (button.dataset.availableTitle === '') {
                        button.removeAttribute('title');
                    } else {
                        button.setAttribute('title', button.dataset.availableTitle);
                    }
                    delete button.dataset.availableTitle;
                }
            } else {
                button.setAttribute('aria-disabled', 'true');
                if (reason !== '') {
                    if (button.dataset.availableTitle === undefined) {
                        button.dataset.availableTitle = button.getAttribute('title') || '';
                    }
                    button.setAttribute('title', reason);
                }
            }
            return;
        }

        button.disabled = !available;
    }

    /**
     * One polite live region per module: scan start, end and failure are announced without moving focus.
     */
    announce(message) {
        const text = String(message || '').trim();
        if (text === '') {
            return;
        }

        let region = document.querySelector('[data-aqg-live-region="true"]');
        if (!region) {
            region = document.createElement('div');
            region.className = 'visually-hidden';
            region.setAttribute('data-aqg-live-region', 'true');
            region.setAttribute('role', 'status');
            region.setAttribute('aria-live', 'polite');
            (document.querySelector('.aqg-module') || document.body).appendChild(region);
        }

        region.textContent = '';
        window.setTimeout(() => {
            region.textContent = text;
        }, 50);
    }

    /** 24-hour "HH:MM", as the backend prints scan times. */
    formatClockTime(date) {
        const pad = (value) => String(value).padStart(2, '0');
        return `${pad(date.getHours())}:${pad(date.getMinutes())}`;
    }

    /** The state word of a scan status block: Running, Completed, Failed or Cancelled. */
    describeScanState(status) {
        const time = this.formatClockTime(new Date());
        switch (String(status || '')) {
            case 'completed':
                return this.translate('overview.scan.stateCompleted', 'Completed');
            case 'failed':
                return `${this.translate('overview.scan.stateFailed', 'Failed')} · ${time}`;
            case 'cancelled':
                return this.translate('overview.scan.stateCancelled', 'Cancelled');
            default:
                return this.translate('overview.scan.stateRunning', 'Running');
        }
    }

    /**
     * After a failed scan the results below are older: label them as the last completed scan.
     */
    setLastCompletedLabel(source, visible) {
        document.querySelectorAll(`[data-aqg-last-completed="${source}"]`).forEach((label) => {
            label.hidden = !visible;
        });
    }

    /**
     * Disclosures of the scan views: a button with aria-expanded and aria-controls shows or hides the element it names.
     * `data-aqg-toggle-all` opens or closes every rule toggle of its scope, links with `data-aqg-reveal` and the URL
     * fragment open whatever hides their target first.
     */
    initDisclosures() {
        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            if (!target) {
                return;
            }

            const toggle = target.closest('[data-aqg-toggle]');
            if (toggle) {
                event.preventDefault();
                this.setDisclosure(toggle, toggle.getAttribute('aria-expanded') !== 'true', true);
                return;
            }

            const toggleAll = target.closest('[data-aqg-toggle-all]');
            if (toggleAll) {
                event.preventDefault();
                this.toggleAllDisclosures(toggleAll);
                return;
            }

            const reveal = target.closest('a[data-aqg-reveal][href^="#"]');
            if (reveal) {
                this.revealTarget(decodeURIComponent(reveal.getAttribute('href').slice(1)));
            }
        });

        window.addEventListener('hashchange', () => {
            this.revealTarget(decodeURIComponent(window.location.hash.slice(1)));
        });

        if (window.location.hash.length > 1) {
            this.revealTarget(decodeURIComponent(window.location.hash.slice(1)));
        }

        document.querySelectorAll('[data-aqg-toggle-all]').forEach((button) => this.syncToggleAllLabel(button));
    }

    setDisclosure(toggle, open, moveFocus = false) {
        const panel = document.getElementById(toggle.getAttribute('aria-controls') || '');
        if (!panel) {
            return;
        }

        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        panel.hidden = !open;

        const label = open ? toggle.dataset.labelOpen : toggle.dataset.labelClosed;
        if (label) {
            toggle.textContent = label;
        }

        // "Show more": continue with the first item that just appeared.
        const focusSelector = toggle.dataset.aqgFocusFirst;
        if (open && moveFocus && focusSelector) {
            const first = panel.querySelector(focusSelector);
            if (first instanceof HTMLElement) {
                if (!first.hasAttribute('tabindex')) {
                    first.setAttribute('tabindex', '-1');
                }
                first.focus();
            }
        }

        document.querySelectorAll('[data-aqg-toggle-all]').forEach((button) => this.syncToggleAllLabel(button));
    }

    toggleAllDisclosures(button) {
        const toggles = this.toggleAllTargets(button);
        const open = toggles.some((toggle) => toggle.getAttribute('aria-expanded') !== 'true');
        toggles.forEach((toggle) => this.setDisclosure(toggle, open));
        this.syncToggleAllLabel(button);
    }

    toggleAllTargets(button) {
        const scope = document.querySelector(button.dataset.aqgToggleScope || 'body') || document.body;

        return Array.from(scope.querySelectorAll(button.dataset.aqgToggleAll || '[data-aqg-rule-toggle]'));
    }

    syncToggleAllLabel(button) {
        const toggles = this.toggleAllTargets(button);
        const anyClosed = toggles.some((toggle) => toggle.getAttribute('aria-expanded') !== 'true');
        const label = anyClosed ? button.dataset.labelExpand : button.dataset.labelCollapse;
        if (label) {
            button.textContent = label;
        }
    }

    revealTarget(id) {
        if (!id) {
            return;
        }

        const target = document.getElementById(id);
        if (!target) {
            return;
        }

        const ownDisclosure = target.matches('details') ? target : target.querySelector(':scope > details');
        if (ownDisclosure instanceof HTMLDetailsElement) {
            ownDisclosure.open = true;
            // Opened from a notification action or a link: keyboard and screen reader users land on it.
            ownDisclosure.querySelector(':scope > summary')?.focus();
        }

        let details = target.parentElement?.closest('details');
        while (details) {
            details.open = true;
            details = details.parentElement?.closest('details');
        }

        let hidden = target.closest('[hidden]');
        while (hidden && hidden.id) {
            const hiddenId = hidden.id;
            const toggle = Array.from(document.querySelectorAll('[data-aqg-toggle][aria-controls]'))
                .find((candidate) => candidate.getAttribute('aria-controls') === hiddenId);
            if (!toggle) {
                break;
            }
            this.setDisclosure(toggle, true);
            hidden = hidden.parentElement?.closest('[hidden]');
        }

        // A rule card opens its own body when the link points at the card.
        const ruleToggle = target.matches('[data-aqg-rule-card]')
            ? target.querySelector('[data-aqg-rule-toggle][aria-expanded="false"]')
            : null;
        if (ruleToggle) {
            this.setDisclosure(ruleToggle, true);
        }
    }

    /**
     * The one notification of a scan (core/scan-notification.js), also announced. `detailsTarget` is the element of the
     * reloaded view the toast's action opens: the rendered coverage or the failed pages list.
     */
    showScanResult(notification, { detailsTarget = '', detailsLabel = '', announce = true } = {}) {
        // The action opens the scan's own report (server `reportUrl`), at the skipped pages when checks were skipped.
        const reportBase = String(notification.reportUrl || '') || window.location.href;
        const detailsUrl = notification.details && detailsTarget !== '' ? moduleUrlWithTarget(reportBase, detailsTarget) : '';
        const shown = showScanNotification(notification, {
            detailsUrl,
            detailsLabel: detailsLabel || this.translate('notification.scanResult.action.details', 'View affected pages'),
            reportLabel: this.translate('notification.scanResult.action.report', 'Open report'),
        });
        if (!shown) {
            this.showNotification([notification.title, notification.message].filter(Boolean).join(': '), notification.severity);
        }
        if (announce) {
            this.announce([notification.title, notification.message].filter(Boolean).join('. '));
        }
    }

    /** The translate callback the scan notification builders take: a label key, its English fallback and %d values. */
    scanLabel() {
        return (key, fallback, ...values) => this.format(this.translate(key, fallback), ...values);
    }

    showNotification(message, type = 'info') {
        const notificationApi = window.top?.TYPO3?.Notification;
        const title = this.translate('notification.title', 'Accessibility');

        if (notificationApi) {
            notificationApi.showMessage(title, message, notificationSeverity(type), 5);
            return;
        }

        const container = document.querySelector(FREE_SELECTORS.notificationContainer);
        if (!container) {
            return;
        }

        const alertType = type === 'error' ? 'danger' : type;
        const banner = document.createElement('div');
        banner.className = `alert alert-${alertType}`;
        banner.setAttribute('role', 'alert');
        banner.textContent = message;

        container.prepend(banner);

        window.setTimeout(() => {
            banner.remove();
        }, 5000);
    }

    translate(key, fallback = '') {
        return this.translations?.[key] || fallback;
    }

    format(template, ...values) {
        let result = template;

        values.forEach((value) => {
            result = result.replace(/%[ds]/, String(value));
        });

        return result;
    }


    /** Reloads the module view; after a completed scan (`showLatestScan`) with the fresh result (withoutSelectedScan()). */
    reloadCurrentModule(delay = 0, { showLatestScan = false } = {}) {
        const run = () => {
            try {
                const url = new URL(showLatestScan ? withoutSelectedScan(window.location.href) : window.location.href);
                url.searchParams.set('_aqgReload', String(Date.now()));
                window.location.replace(url.toString());
                return;
            } catch {
                // fall back to a normal iframe reload if URL handling is not available
            }

            try {
                window.location.reload();
            } catch {
                // noop
            }
        };

        if (delay > 0) {
            window.setTimeout(run, delay);
            return;
        }

        run();
    }

    normalizeNullableNumber(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }

        const normalized = Number(value);

        return Number.isFinite(normalized) ? normalized : null;
    }
}