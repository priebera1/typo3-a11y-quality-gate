import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

const SELECTORS = {
    ignoreTrigger: '.a11y-ignore-trigger',
    ignoreCancel: '.a11y-ignore-cancel',
    rescanButton: '[data-action="a11y-rescan"]',
    scanAllButton: '[data-action="a11y-scan-all"]',
    detailToggle: '[data-action="a11y-toggle-detail"]',
    overviewSearch: '[data-a11y-overview-search="true"]',
    overviewRows: '[data-a11y-page-row="true"]',
    overviewNoResults: '[data-a11y-no-results="true"]',
    notificationContainer: '.module-body, .a11y-page-detail, .a11y-overview',
    settingsGroups: '[data-a11y-settings-group="true"]',
    settingsCheckboxes: '[data-a11y-settings-checkbox="true"]',
    settingsCount: '[data-a11y-settings-count="true"]',
    settingsItem: '[data-a11y-settings-item="true"]',
};

class A11yBackendModule {
    constructor() {
        this.statusPollTimer = null;
        this.translations = TYPO3?.lang || {};
        this.bindDocumentClickEvents();
        this.initOverviewSearch();
        this.initSettingsTree();
        this.startStatusPolling();
    }

    bindDocumentClickEvents() {
        document.addEventListener('click', async (event) => {
            const ignoreTrigger = event.target.closest(SELECTORS.ignoreTrigger);
            if (ignoreTrigger) {
                event.preventDefault();
                this.handleIgnoreTrigger(ignoreTrigger);
                return;
            }

            const ignoreCancel = event.target.closest(SELECTORS.ignoreCancel);
            if (ignoreCancel) {
                event.preventDefault();
                this.handleIgnoreCancel(ignoreCancel);
                return;
            }

            const detailToggle = event.target.closest(SELECTORS.detailToggle);
            if (detailToggle) {
                event.preventDefault();
                this.handleDetailToggle(detailToggle);
                return;
            }

            const rescanButton = event.target.closest(SELECTORS.rescanButton);
            if (rescanButton) {
                event.preventDefault();
                await this.handleRescan(rescanButton);
                return;
            }

            const scanAllButton = event.target.closest(SELECTORS.scanAllButton);
            if (scanAllButton) {
                event.preventDefault();
                await this.handleScanAll(scanAllButton);
                return;
            }
        });
    }

    initOverviewSearch() {
        const input = document.querySelector(SELECTORS.overviewSearch);
        if (!input) {
            return;
        }

        const rows = Array.from(document.querySelectorAll(SELECTORS.overviewRows));
        const noResultsRow = document.querySelector(SELECTORS.overviewNoResults);

        if (rows.length === 0) {
            return;
        }

        const applyFilter = () => {
            const query = String(input.value || '').trim().toLowerCase();
            let visibleCount = 0;

            rows.forEach((row) => {
                const title = String(row.dataset.a11yPageTitle || '').toLowerCase();
                const uid = String(row.dataset.a11yPageUid || '').toLowerCase();
                const haystack = `${title} ${uid}`;
                const matches = query === '' || haystack.includes(query);

                row.style.display = matches ? '' : 'none';

                if (matches) {
                    visibleCount++;
                }
            });

            if (noResultsRow) {
                noResultsRow.hidden = !(visibleCount === 0 && query !== '');
            }
        };

        input.addEventListener('input', applyFilter);
        input.addEventListener('search', applyFilter);

        applyFilter();
    }

    initSettingsTree() {
        const groups = document.querySelectorAll(SELECTORS.settingsGroups);
        if (groups.length === 0) {
            return;
        }

        groups.forEach((group) => {
            this.updateSettingsGroupState(group);

            group.querySelectorAll(SELECTORS.settingsCheckboxes).forEach((checkbox) => {
                checkbox.addEventListener('change', () => {
                    this.updateSettingsGroupState(group);
                });

                checkbox.addEventListener('input', () => {
                    this.updateSettingsGroupState(group);
                });
            });
        });
    }

    updateSettingsGroupState(group) {
        const checkboxes = Array.from(group.querySelectorAll(SELECTORS.settingsCheckboxes));
        const countElement = group.querySelector(SELECTORS.settingsCount);

        if (checkboxes.length === 0 || !countElement) {
            return;
        }

        const checkedCount = checkboxes.filter((checkbox) => checkbox.checked).length;
        const totalCount = Number.parseInt(
            countElement.dataset.totalCount || String(checkboxes.length),
            10
        );

        countElement.textContent = `${checkedCount} / ${totalCount} ${this.translate('settings.enabledFieldsCount', 'enabled')}`;

        checkboxes.forEach((checkbox) => {
            const item = checkbox.closest(SELECTORS.settingsItem);
            if (!item) {
                return;
            }

            item.classList.toggle('is-disabled', !checkbox.checked);
        });
    }

    handleIgnoreTrigger(button) {
        const issueUid = button.dataset.issueUid;
        if (!issueUid) {
            return;
        }

        const form = document.getElementById(`a11y-ignore-${issueUid}`)
            || document.getElementById(`ignore-form-${issueUid}`);

        if (!form) {
            console.warn('[A11Y] Ignore form not found for issue', issueUid);
            return;
        }

        form.hidden = false;
        button.hidden = true;

        const reasonInput = form.querySelector('input[name="reason"]');
        reasonInput?.focus();
    }

    handleIgnoreCancel(button) {
        const targetId = button.dataset.target;
        if (!targetId) {
            return;
        }

        const form = document.getElementById(targetId);
        if (form) {
            form.hidden = true;
        }

        const issueUid = targetId.replace(/^(a11y-ignore-|ignore-form-)/, '');
        const trigger = document.querySelector(
            `${SELECTORS.ignoreTrigger}[data-issue-uid="${issueUid}"]`
        );

        if (trigger) {
            trigger.hidden = false;
        }
    }

    handleDetailToggle(toggle) {
        const targetId = toggle.dataset.target;
        if (!targetId) {
            return;
        }

        const panel = document.getElementById(targetId);
        if (!panel) {
            return;
        }

        const isExpanded = panel.getAttribute('aria-expanded') === 'true';
        panel.setAttribute('aria-expanded', String(!isExpanded));
        panel.hidden = isExpanded;

        toggle.querySelector('.a11y-toggle-icon')?.classList.toggle('rotated', !isExpanded);
    }

    async handleRescan(button) {
        const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
        const endpoint = ajaxUrls.a11y_scan_page || '';
        const pageUid = Number.parseInt(button.dataset.pageUid || '0', 10);

        if (!endpoint || pageUid <= 0) {
            console.warn('[A11Y] Missing rescan configuration', { endpoint, pageUid });
            this.showNotification(
                this.translate('notification.scan.missingPageUid', 'Scan failed: missing endpoint or page UID.'),
                'error'
            );
            return;
        }

        this.setLoadingState(button, true);

        try {
            const response = await new AjaxRequest(endpoint).post({
                pageUid,
            });

            const data = await response.resolve();
            const issuesNew = Number(data.issuesNew || 0);
            const issuesResolved = Number(data.issuesResolved || 0);

            this.showNotification(
                this.format(
                    this.translate('notification.scan.completed', 'Scan complete — %d new, %d resolved.'),
                    issuesNew,
                    issuesResolved
                ),
                'success'
            );

            window.setTimeout(() => {
                window.location.reload();
            }, 1200);
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Unknown error';
            console.warn('[A11Y] Rescan failed', error);
            this.showNotification(
                this.format(
                    this.translate('notification.scan.failed', 'Scan failed: %s'),
                    message
                ),
                'error'
            );
            this.setLoadingState(button, false);
        }
    }

    async handleScanAll(button) {
        const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
        const endpoint = ajaxUrls.a11y_scan_site || '';
        const rootPid = Number.parseInt(button.dataset.rootPid || '0', 10);

        if (!endpoint || rootPid <= 0) {
            console.warn('[A11Y] Missing scan-all configuration', { endpoint, rootPid });
            this.showNotification(
                this.translate('notification.scan.missingRootPid', 'Scan failed: missing endpoint or root PID.'),
                'error'
            );
            return;
        }

        this.setLoadingState(button, true);

        try {
            const response = await new AjaxRequest(endpoint).post({
                rootPid,
            });

            const data = await response.resolve();
            const issuesNew = Number(data.issuesNew || 0);
            const issuesResolved = Number(data.issuesResolved || 0);

            this.showNotification(
                this.format(
                    this.translate('notification.scanAll.completed', 'Full scan complete — %d new, %d resolved.'),
                    issuesNew,
                    issuesResolved
                ),
                'success'
            );

            window.setTimeout(() => {
                window.location.reload();
            }, 1500);
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Unknown error';
            console.warn('[A11Y] Scan all failed', error);
            this.showNotification(
                this.format(
                    this.translate('notification.scan.failed', 'Scan failed: %s'),
                    message
                ),
                'error'
            );
            this.setLoadingState(button, false);
        }
    }

    startStatusPolling() {
        const hasRelevantButtons =
            document.querySelector(SELECTORS.scanAllButton)
            || document.querySelector(SELECTORS.rescanButton);

        if (!hasRelevantButtons) {
            return;
        }

        if (this.statusPollTimer) {
            window.clearInterval(this.statusPollTimer);
        }

        this.statusPollTimer = window.setInterval(async () => {
            try {
                const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
                const endpoint = ajaxUrls.a11y_scan_status || '';
                if (!endpoint) {
                    return;
                }

                const response = await new AjaxRequest(endpoint).get();
                const data = await response.resolve();
                const status = data.status || {};
                const running = Boolean(status.running);

                document.querySelectorAll(SELECTORS.scanAllButton).forEach((button) => {
                    button.disabled = running;
                });

                document.querySelectorAll(SELECTORS.rescanButton).forEach((button) => {
                    button.disabled = running;
                });
            } catch (error) {
                // noop
            }
        }, 3000);
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

            notificationApi.showMessage(
                title,
                message,
                severityMap[type] ?? 0,
                5
            );
            return;
        }

        const container = document.querySelector(SELECTORS.notificationContainer);
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
}

const bootstrapA11yBackendModule = () => {
    new A11yBackendModule();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapA11yBackendModule, { once: true });
} else {
    bootstrapA11yBackendModule();
}