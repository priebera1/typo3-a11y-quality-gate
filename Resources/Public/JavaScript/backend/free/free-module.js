import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import { A11yBaseModule } from '../core/base-module.js';
import { FREE_SELECTORS, PRO_SELECTORS } from '../core/constants.js';

export class A11yFreeBackendModule extends A11yBaseModule {
    constructor() {
        super();

        this.bindDocumentClickEvents();
        this.initOverviewSearch();
        this.initSettingsTree();
        this.initLicenceControls();
        this.startStatusPolling();
        this.initNewTabLinks();
    }

    bindDocumentClickEvents() {
        document.addEventListener('click', async (event) => {
            const ignoreTrigger = event.target.closest(FREE_SELECTORS.ignoreTrigger);
            if (ignoreTrigger) {
                event.preventDefault();
                this.handleIgnoreTrigger(ignoreTrigger);
                return;
            }

            const ignoreCancel = event.target.closest(FREE_SELECTORS.ignoreCancel);
            if (ignoreCancel) {
                event.preventDefault();
                this.handleIgnoreCancel(ignoreCancel);
                return;
            }

            const detailToggle = event.target.closest(FREE_SELECTORS.detailToggle);
            if (detailToggle) {
                event.preventDefault();
                this.handleDetailToggle(detailToggle);
                return;
            }

            const rescanButton = event.target.closest(FREE_SELECTORS.rescanButton);
            if (rescanButton) {
                event.preventDefault();
                await this.handleRescan(rescanButton);
                return;
            }

            const scanAllButton = event.target.closest(FREE_SELECTORS.scanAllButton);
            if (scanAllButton) {
                event.preventDefault();
                await this.handleScanAll(scanAllButton);
            }
        });
    }

    initOverviewSearch() {
        this.overviewSearchTimers ??= new WeakMap();
        this.overviewSearchAbortControllers ??= new WeakMap();

        const inputs = Array.from(document.querySelectorAll(FREE_SELECTORS.overviewSearch));
        if (inputs.length === 0) {
            return;
        }

        inputs.forEach((input) => {
            const searchMode = String(input.dataset.a11ySearchMode || 'client').trim().toLowerCase();

            if (searchMode === 'server') {
                const form = input.closest('form');
                if (!form) {
                    return;
                }

                form.addEventListener('submit', (event) => {
                    event.preventDefault();
                });

                input.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                    }
                });

                input.addEventListener('input', () => {
                    const existingTimer = this.overviewSearchTimers.get(input);
                    if (existingTimer) {
                        window.clearTimeout(existingTimer);
                    }

                    const timer = window.setTimeout(() => {
                        this.runServerOverviewSearch(form, input);
                    }, 350);

                    this.overviewSearchTimers.set(input, timer);
                });

                return;
            }

            input.addEventListener('input', () => this.applyOverviewSearchForInput(input));
            input.addEventListener('search', () => this.applyOverviewSearchForInput(input));
            this.applyOverviewSearchForInput(input);
        });
    }

    async runServerOverviewSearch(form, input) {
        const url = new URL(window.location.href);
        const formData = new FormData(form);

        formData.forEach((value, key) => {
            const normalizedValue = String(value || '').trim();

            if (normalizedValue === '') {
                url.searchParams.delete(key);
                return;
            }

            url.searchParams.set(key, normalizedValue);
        });

        const inputName = String(input.name || '').trim();

        if (inputName === 'localQuery') {
            url.searchParams.delete('localPage');
        }

        if (inputName === 'remoteQuery') {
            url.searchParams.delete('remotePage');
        }

        if (inputName === 'remoteFailedQuery') {
            url.searchParams.delete('remoteFailedPage');
        }

        const previousController = this.overviewSearchAbortControllers.get(input);
        if (previousController) {
            previousController.abort();
        }

        const controller = new AbortController();
        this.overviewSearchAbortControllers.set(input, controller);

        try {
            const response = await fetch(url.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controller.signal,
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const html = await response.text();
            const parser = new DOMParser();
            const documentFragment = parser.parseFromString(html, 'text/html');

            const currentOverview = document.querySelector(PRO_SELECTORS.overviewRoot);
            const nextOverview = documentFragment.querySelector(PRO_SELECTORS.overviewRoot);

            if (!currentOverview || !nextOverview) {
                window.location.assign(url.toString());
                return;
            }

            currentOverview.replaceWith(nextOverview);
            window.history.replaceState({}, '', url.toString());

            this.reinitializeOverviewAfterSearch();
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }

            console.error('[AQG] Overview search failed', error);
        }
    }

    reinitializeOverviewAfterSearch() {
        this.initOverviewSearch();
        this.initNewTabLinks();

        if (typeof this.initOverviewSourceTabs === 'function') {
            this.initOverviewSourceTabs();
        }

        if (typeof this.initRemoteScanProgress === 'function') {
            this.initRemoteScanProgress();
        }
    }

    initLicenceControls() {
        const input = document.querySelector(FREE_SELECTORS.licenceKeyInput);
        const toggleButton = document.querySelector(FREE_SELECTORS.licenceToggleButton);
        const validateButton = document.querySelector(FREE_SELECTORS.licenceValidateButton);
        const resultBox = document.querySelector(FREE_SELECTORS.licenceValidateResult);

        if (!input || !validateButton || !resultBox) {
            return;
        }

        if (toggleButton) {
            const updateToggleButtonText = () => {
                toggleButton.textContent = input.type === 'password'
                    ? this.translate('settings.licence.show', 'Show')
                    : this.translate('settings.licence.hide', 'Hide');
            };

            updateToggleButtonText();

            toggleButton.addEventListener('click', () => {
                input.type = input.type === 'password' ? 'text' : 'password';
                updateToggleButtonText();
            });
        }

        validateButton.addEventListener('click', async () => {
            const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
            const endpoint = ajaxUrls.a11y_validate_licence || '';
            const licenceKey = String(input.value || '').trim();

            if (licenceKey === '') {
                resultBox.innerHTML = `
                    <div class="alert alert-warning py-2 mb-0">
                        ${this.translate('settings.licence.validation.emptyKey', 'Please enter a licence key first.')}
                    </div>
                `;
                return;
            }

            if (endpoint === '') {
                resultBox.innerHTML = `
                    <div class="alert alert-danger py-2 mb-0">
                        ${this.translate('settings.licence.validation.unreachable', 'Validation failed because the API could not be reached.')}
                    </div>
                `;
                return;
            }

            const originalText = validateButton.textContent.trim();
            validateButton.disabled = true;
            validateButton.textContent = this.translate('settings.licence.validating', 'Validating...');

            resultBox.innerHTML = `
                <div class="alert alert-secondary py-2 mb-0">
                    ${this.translate('settings.licence.validating', 'Validating...')}
                </div>
            `;

            try {
                const response = await new AjaxRequest(endpoint).post({
                    licenceKey,
                });

                const data = await response.resolve();
                const formattedExpiresAt = this.formatDisplayDate(data.trialExpiresAt || data.expiresAt);

                if (data.valid) {
                    const parts = [];

                    if (data.plan) {
                        parts.push(`${this.translate('settings.licence.plan', 'Plan')}: ${this.formatPlanLabel(data.plan)}`);
                    }

                    if (data.domain) {
                        parts.push(`${this.translate('settings.licence.domain', 'Domain')}: ${data.domain}`);
                    }

                    const formattedExpiresAt = this.formatDisplayDate(
                        data.trialExpiresAt
                        || data.expiresAt
                        || data.expires_at
                    );

                    const expiresLabel = this.translate(
                        data.isTrial ? 'settings.licence.trialExpires' : 'settings.licence.expires',
                        data.isTrial ? 'Trial expires' : 'Expires'
                    );

                    if (formattedExpiresAt) {
                        parts.push(`${expiresLabel}: ${formattedExpiresAt}`);
                    }

                    if (data.isTrial && !formattedExpiresAt) {
                        parts.push(
                            this.translate(
                                'settings.licence.trialStartsOnFirstValidation',
                                'Timer starts on first production validation.'
                            )
                        );
                    }

                    const trialMeta = [];

                    const maxJobsPerDay = Number(
                        data.maxJobsPerDay
                        ?? data.details?.maxJobsPerDay
                        ?? 0
                    );

                    if (data.isTrial && maxJobsPerDay > 0) {
                        trialMeta.push(
                            this.format(
                                this.translate(
                                    'settings.licence.trialDailyLimit',
                                    'Trial includes %d remote crawl jobs per 24 hours.'
                                ),
                                maxJobsPerDay
                            )
                        );
                    }

                    const remainingHours = Number(data.remainingHours ?? 0);
                    const remainingDays = Number(data.remainingDays ?? 0);

                    if (data.isTrial && remainingDays > 0) {
                        trialMeta.push(
                            this.format(
                                this.translate(
                                    'settings.licence.trialRemainingDays',
                                    '%d day(s) remaining in this trial.'
                                ),
                                remainingDays
                            )
                        );
                    } else if (data.isTrial && remainingHours > 0) {
                        trialMeta.push(
                            this.format(
                                this.translate(
                                    'settings.licence.trialRemainingHours',
                                    '%d hour(s) remaining in this trial.'
                                ),
                                remainingHours
                            )
                        );
                    }

                    resultBox.innerHTML = `
                        <div class="alert alert-success py-2 mb-0">
                            <div class="fw-semibold mb-1">
                                ${this.translate(
                                        data.isTrial
                                            ? 'settings.licence.validation.trialValid'
                                            : 'settings.licence.validation.valid',
                                        data.isTrial ? 'Trial licence is valid.' : 'Licence is valid.'
                                    )}
                            </div>
                
                            ${parts.length > 0 ? `<div class="small">${parts.join('<br>')}</div>` : ''}
                
                            ${trialMeta.length > 0 ? `<div class="small mt-2">${trialMeta.join('<br>')}</div>` : ''}
                
                            <div class="small mt-2 text-muted">
                                ${this.translate(
                                        'settings.licence.validation.saveToApply',
                                        'Save settings to apply this licence to the current TYPO3 instance.'
                                    )}
                            </div>
                        </div>
                    `;
                } else {
                    const invalidMessage = String(
                        data.message
                        || data.error
                        || data.reasonLabel
                        || data.reason
                        || this.translate('settings.licence.validation.invalidFallback', 'The licence could not be validated.')
                    );

                    const invalidTitle = String(
                        data.title
                        || this.translate('settings.licence.validation.invalid', 'Licence is not valid.')
                    );

                    resultBox.innerHTML = `
                        <div class="alert alert-danger py-2 mb-0">
                            <div class="fw-semibold mb-1">
                                ${invalidTitle}
                            </div>
                            <div class="small">
                                ${invalidMessage}
                            </div>
                        </div>
                    `;
                }
            } catch {
                resultBox.innerHTML = `
                    <div class="alert alert-danger py-2 mb-0">
                        ${this.translate('settings.licence.validation.unreachable', 'Validation failed because the API could not be reached.')}
                    </div>
                `;
            } finally {
                validateButton.disabled = false;
                validateButton.textContent = originalText;
            }
        });
    }

    formatDisplayDate(value) {
        if (!value || typeof value !== 'string') {
            return '';
        }

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return value;
        }

        const day = String(date.getUTCDate()).padStart(2, '0');
        const month = String(date.getUTCMonth() + 1).padStart(2, '0');
        const year = String(date.getUTCFullYear());

        return `${day}.${month}.${year}`;
    }

    formatPlanLabel(value) {
        const normalized = String(value || '').trim().toLowerCase();

        if (normalized === 'free') {
            return 'FREE';
        }

        if (normalized === 'pro') {
            return 'PRO';
        }

        if (normalized === 'agency') {
            return 'Agency';
        }

        if (normalized === 'trial') {
            return 'Trial';
        }

        return String(value || '').trim();
    }

    applyOverviewSearchForInput(input) {
        const container = input.closest('.a11y-overview-panel, .a11y-overview, .a11y-page-detail, .module-body') || document;
        const rows = Array.from(container.querySelectorAll(FREE_SELECTORS.overviewRows));
        const noResultsRow = container.querySelector(FREE_SELECTORS.overviewNoResults);
        const query = String(input.value || '').trim().toLowerCase();

        let visibleCount = 0;

        rows.forEach((row) => {
            const title = String(row.dataset.a11yPageTitle || '').toLowerCase();
            const uid = String(row.dataset.a11yPageUid || '').toLowerCase();
            const url = String(row.dataset.a11yPageUrl || '').toLowerCase();
            const haystack = `${title} ${uid} ${url}`;
            const matches = query === '' || haystack.includes(query);

            row.style.display = matches ? '' : 'none';

            if (matches) {
                visibleCount++;
            }
        });

        if (noResultsRow) {
            noResultsRow.hidden = !(visibleCount === 0 && query !== '');
        }
    }

    initSettingsTree() {
        const groups = document.querySelectorAll(FREE_SELECTORS.settingsGroups);
        if (groups.length === 0) {
            return;
        }

        groups.forEach((group) => {
            this.updateSettingsGroupState(group);

            group.querySelectorAll(FREE_SELECTORS.settingsCheckboxes).forEach((checkbox) => {
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
        const checkboxes = Array.from(group.querySelectorAll(FREE_SELECTORS.settingsCheckboxes));
        const countElement = group.querySelector(FREE_SELECTORS.settingsCount);

        if (checkboxes.length === 0 || !countElement) {
            return;
        }

        const checkedCount = checkboxes.filter((checkbox) => checkbox.checked).length;
        const totalCount = Number.parseInt(
            countElement.dataset.totalCount || String(checkboxes.length),
            10
        );

        countElement.textContent = `${checkedCount} ${this.translate('settings.enabledFieldsCount', 'enabled')} / ${totalCount}`;

        checkboxes.forEach((checkbox) => {
            const item = checkbox.closest(FREE_SELECTORS.settingsItem);
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
            `${FREE_SELECTORS.ignoreTrigger}[data-issue-uid="${issueUid}"]`
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
            this.showNotification(
                this.translate('notification.scan.missingPageUid', 'Scan failed: missing endpoint or page UID.'),
                'error'
            );
            return;
        }

        this.setLoadingState(button, true);

        try {
            const response = await new AjaxRequest(endpoint).post({ pageUid });
            const data = await response.resolve();

            this.showNotification(
                this.format(
                    this.translate('notification.scan.completed', 'Scan complete — %d new, %d resolved.'),
                    Number(data.issuesNew || 0),
                    Number(data.issuesResolved || 0)
                ),
                'success'
            );

            window.setTimeout(() => window.location.reload(), 1200);
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Unknown error';
            this.showNotification(
                this.format(this.translate('notification.scan.failed', 'Scan failed: %s'), message),
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
            this.showNotification(
                this.translate('notification.scan.missingRootPid', 'Scan failed: missing endpoint or root PID.'),
                'error'
            );
            return;
        }

        this.setLoadingState(button, true);

        try {
            const response = await new AjaxRequest(endpoint).post({ rootPid });
            const data = await response.resolve();

            this.showNotification(
                this.format(
                    this.translate('notification.scanAll.completed', 'Full scan complete — %d new, %d resolved.'),
                    Number(data.issuesNew || 0),
                    Number(data.issuesResolved || 0)
                ),
                'success'
            );

            window.setTimeout(() => window.location.reload(), 1500);
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Unknown error';
            this.showNotification(
                this.format(this.translate('notification.scan.failed', 'Scan failed: %s'), message),
                'error'
            );
            this.setLoadingState(button, false);
        }
    }

    startStatusPolling() {
        if (this.scanInProgress) {
            return;
        }

        const hasRelevantButtons =
            document.querySelector(FREE_SELECTORS.scanAllButton)
            || document.querySelector(FREE_SELECTORS.rescanButton);

        if (!hasRelevantButtons) {
            return;
        }

        if (this.statusPollTimer) {
            window.clearInterval(this.statusPollTimer);
        }

        this.statusPollTimer = window.setInterval(async () => {
            try {
                if (this.scanInProgress || this.getRestorableRemoteScanState?.()) {
                    return;
                }

                const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
                const endpoint = ajaxUrls.a11y_scan_status || '';
                if (!endpoint) {
                    return;
                }

                const statusUrl = new URL(endpoint, window.location.origin);
                const currentUrl = new URL(window.location.href);

                ['id', 'site', 'pageUid'].forEach((key) => {
                    const value = currentUrl.searchParams.get(key);
                    if (value) {
                        statusUrl.searchParams.set(key, value);
                    }
                });

                const response = await new AjaxRequest(statusUrl.toString()).get();
                const data = await response.resolve();
                const status = data.status || {};
                const remoteStatus = data.remoteStatus || {};
                const running = Boolean(status.running);
                const remoteRunning = ['waiting', 'queued', 'running', 'active'].includes(String(remoteStatus.status || '').trim());

                document.querySelectorAll(FREE_SELECTORS.scanAllButton).forEach((button) => {
                    button.disabled = running || remoteRunning;
                });

                document.querySelectorAll(FREE_SELECTORS.rescanButton).forEach((button) => {
                    button.disabled = running || remoteRunning;
                });

                document.querySelectorAll(PRO_SELECTORS.proScanSiteButton).forEach((button) => {
                    button.disabled = running || remoteRunning;
                });

                document.querySelectorAll(PRO_SELECTORS.proScanPageButton).forEach((button) => {
                    button.disabled = running || remoteRunning;
                });
            } catch {
                // noop
            }
        }, 3000);
    }
}