import { FREE_SELECTORS } from './constants.js';

export class A11yBaseModule {
    constructor() {
        this.statusPollTimer = null;
        this.translations = TYPO3?.lang || {};

        this.initLanguageDropdowns();
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
        button.disabled = isLoading;

        if (isLoading) {
            if (!button.dataset.originalText) {
                button.dataset.originalText = button.textContent.trim();
            }

            button.textContent = button.dataset.loadingText || this.translate('action.scanning', 'Scanning...');
            return;
        }

        button.textContent = button.dataset.originalText || button.textContent;
    }

    showNotification(message, type = 'info') {
        const notificationApi = window.top?.TYPO3?.Notification;
        const title = this.translate('notification.title', 'Accessibility');

        if (notificationApi) {
            const severityMap = {
                info: 0,
                success: 1,
                warning: -1,
                error: -2,
            };

            notificationApi.showMessage(title, message, severityMap[type] ?? 0, 5);
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


    reloadCurrentModule(delay = 0) {
        const run = () => {
            try {
                const url = new URL(window.location.href);
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