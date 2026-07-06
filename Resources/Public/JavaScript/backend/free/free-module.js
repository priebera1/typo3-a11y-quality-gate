import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import { A11yBaseModule } from '../core/base-module.js';
import { FREE_SELECTORS, PRO_SELECTORS } from '../core/constants.js';

export class A11yFreeBackendModule extends A11yBaseModule {
    constructor() {
        super();

        this.localScanCancellationRequested = false;
        this.batchSelection = new Set();

        this.bindDocumentClickEvents();
        this.bindBatchIgnoreEvents();
        this.initOverviewSearch();
        this.initSettingsTree();
        this.initRulesTab();
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

            const batchToggleIssue = event.target.closest(FREE_SELECTORS.batchToggleIssue);
            if (batchToggleIssue) {
                event.preventDefault();
                this.toggleBatchIssue(batchToggleIssue);
                return;
            }

            const batchToggleGroup = event.target.closest(FREE_SELECTORS.batchToggleGroup);
            if (batchToggleGroup) {
                event.preventDefault();
                this.toggleBatchGroup(batchToggleGroup);
                return;
            }

            const batchSelectRule = event.target.closest(FREE_SELECTORS.batchSelectRule);
            if (batchSelectRule) {
                event.preventDefault();
                this.selectBatchRule(batchSelectRule);
                return;
            }

            const batchOpenRulePage = event.target.closest(FREE_SELECTORS.batchOpenRulePage);
            if (batchOpenRulePage) {
                event.preventDefault();
                this.openBatchRuleConfirm(batchOpenRulePage, 'page');
                return;
            }

            const batchOpenRuleSite = event.target.closest(FREE_SELECTORS.batchOpenRuleSite);
            if (batchOpenRuleSite) {
                event.preventDefault();
                this.openBatchRuleConfirm(batchOpenRuleSite, 'site');
                return;
            }

            const batchOpenConfirm = event.target.closest(FREE_SELECTORS.batchOpenConfirm);
            if (batchOpenConfirm) {
                event.preventDefault();
                this.openBatchConfirm();
                return;
            }

            const batchCloseConfirm = event.target.closest(FREE_SELECTORS.batchCloseConfirm);
            if (batchCloseConfirm) {
                event.preventDefault();
                this.closeBatchConfirm();
                return;
            }

            const batchClear = event.target.closest(FREE_SELECTORS.batchClear);
            if (batchClear) {
                event.preventDefault();
                this.clearBatchSelection();
                return;
            }

            const detailToggle = event.target.closest(FREE_SELECTORS.detailToggle);
            if (detailToggle) {
                event.preventDefault();
                this.handleDetailToggle(detailToggle);
                return;
            }

            const scanAllButton = event.target.closest(FREE_SELECTORS.scanAllButton);
            if (scanAllButton) {
                event.preventDefault();
                await this.handleScanAll(scanAllButton);
                return;
            }

            const localCancelButton = event.target.closest(FREE_SELECTORS.localCancelScanButton);
            if (localCancelButton) {
                event.preventDefault();
                await this.handleLocalScanCancel(localCancelButton);
            }
        });
    }

    bindBatchIgnoreEvents() {
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && this.batchSelection?.size > 0) {
                this.clearBatchSelection();
            }
        });

        document.addEventListener('change', (event) => {
            const input = event.target.closest?.(`${FREE_SELECTORS.expiryPicker} input[name="ignoreExpiry"], ${FREE_SELECTORS.expiryPicker} input[name="ignoreUntilDate"]`);
            if (!input) {
                return;
            }

            const picker = input.closest(FREE_SELECTORS.expiryPicker);
            if (picker) {
                this.updateExpiryPicker(picker);
            }
        });

        document.querySelectorAll(FREE_SELECTORS.expiryPicker).forEach((picker) => this.updateExpiryPicker(picker));

        document.addEventListener('submit', (event) => {
            const form = event.target.closest?.(FREE_SELECTORS.batchConfirm);
            if (!form) {
                return;
            }

            const reasonInput = form.querySelector(FREE_SELECTORS.batchReason);
            const error = form.querySelector(FREE_SELECTORS.batchError);
            const reason = String(reasonInput?.value || '').trim();

            if (reason === '') {
                event.preventDefault();
                if (error) {
                    error.hidden = false;
                }
                reasonInput?.focus();
                return;
            }

            if (!this.validateExpiryForForm(form)) {
                event.preventDefault();
                return;
            }

            error && (error.hidden = true);
            const submit = form.querySelector('[data-a11y-bulk-submit="true"]');
            if (submit) {
                submit.disabled = true;
                submit.textContent = 'Ignoring...';
            }
        });

        document.addEventListener('submit', (event) => {
            const form = event.target.closest?.('.aqg-ignore-form form');
            if (!form) {
                return;
            }

            if (!this.validateExpiryForForm(form)) {
                event.preventDefault();
            }
        });
    }


    updateExpiryPicker(picker) {
        const checked = picker.querySelector('input[name="ignoreExpiry"]:checked');
        const mode = String(checked?.value || 'never');
        const custom = picker.querySelector(FREE_SELECTORS.expiryCustom);
        const preview = picker.querySelector(FREE_SELECTORS.expiryPreview);
        const error = picker.querySelector(FREE_SELECTORS.expiryError);

        picker.querySelectorAll('.aqg-expiry__chip').forEach((chip) => {
            const input = chip.querySelector('input[name="ignoreExpiry"]');
            chip.classList.toggle('is-active', input?.checked === true);
        });

        if (custom) {
            custom.hidden = mode !== 'custom';
            custom.classList.remove('is-error');
        }

        if (error) {
            error.hidden = true;
        }

        if (!preview) {
            return;
        }

        if (mode === '7d' || mode === '30d' || mode === '90d') {
            const days = Number.parseInt(mode, 10);
            preview.textContent = `Reopens automatically in ${days} days.`;
            return;
        }

        if (mode === 'custom') {
            const date = picker.querySelector('input[name="ignoreUntilDate"]')?.value || '';
            preview.textContent = date !== ''
                ? `Reopens automatically on ${date}.`
                : 'Select a future date when AQG should reopen the issue.';
            return;
        }

        preview.textContent = 'The issue stays ignored until someone unignores it manually.';
    }

    validateExpiryForForm(form) {
        const picker = form.querySelector(FREE_SELECTORS.expiryPicker);
        if (!picker) {
            return true;
        }

        const mode = String(picker.querySelector('input[name="ignoreExpiry"]:checked')?.value || 'never');
        if (mode !== 'custom') {
            return true;
        }

        const input = picker.querySelector('input[name="ignoreUntilDate"]');
        const custom = picker.querySelector(FREE_SELECTORS.expiryCustom);
        const error = picker.querySelector(FREE_SELECTORS.expiryError);
        const value = String(input?.value || '').trim();
        const selected = value !== '' ? new Date(`${value}T00:00:00`) : null;
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const isValid = selected instanceof Date && !Number.isNaN(selected.getTime()) && selected.getTime() > today.getTime();

        custom?.classList.toggle('is-error', !isValid);
        if (error) {
            error.hidden = isValid;
        }

        if (!isValid) {
            input?.focus();
        }

        return isValid;
    }

    toggleBatchIssue(button) {
        const issueUid = String(button.dataset.issueUid || '').trim();
        if (!issueUid) {
            return;
        }

        if (this.batchSelection.has(issueUid)) {
            this.batchSelection.delete(issueUid);
        } else {
            this.batchSelection.add(issueUid);
        }

        this.closeBatchConfirm(false);
        this.updateBatchUi();
    }

    toggleBatchGroup(button) {
        const groupKey = String(button.dataset.groupKey || '').trim();
        if (!groupKey) {
            return;
        }

        const buttons = this.getBatchIssueButtons().filter((item) => item.dataset.groupKey === groupKey);
        const allSelected = buttons.length > 0 && buttons.every((item) => this.batchSelection.has(String(item.dataset.issueUid || '')));

        buttons.forEach((item) => {
            const issueUid = String(item.dataset.issueUid || '').trim();
            if (!issueUid) {
                return;
            }

            if (allSelected) {
                this.batchSelection.delete(issueUid);
            } else {
                this.batchSelection.add(issueUid);
            }
        });

        this.closeBatchConfirm(false);
        this.updateBatchUi();
    }

    selectBatchRule(button) {
        const ruleId = String(button.dataset.ruleId || '').trim();
        if (!ruleId) {
            return;
        }

        this.getBatchIssueButtons().forEach((item) => {
            if (String(item.dataset.ruleId || '').trim() === ruleId) {
                const issueUid = String(item.dataset.issueUid || '').trim();
                if (issueUid) {
                    this.batchSelection.add(issueUid);
                }
            }
        });

        this.closeBatchConfirm(false);
        this.updateBatchUi();
    }

    openBatchConfirm() {
        if (!this.batchSelection || this.batchSelection.size === 0) {
            return;
        }

        const form = document.querySelector(FREE_SELECTORS.batchConfirm);
        if (!form) {
            return;
        }

        this.prepareBatchForm(form, 'selected');
        form.hidden = false;
        form.querySelector(FREE_SELECTORS.batchReason)?.focus();
    }

    openBatchRuleConfirm(button, scope) {
        const form = document.querySelector(FREE_SELECTORS.batchConfirm);
        const ruleId = String(button.dataset.ruleId || '').trim();
        const count = Number(button.dataset.ruleCount || 0);

        if (!form || !ruleId || count <= 0) {
            return;
        }

        this.prepareBatchForm(form, scope, ruleId, count);
        form.hidden = false;
        form.querySelector(FREE_SELECTORS.batchReason)?.focus();
    }

    closeBatchConfirm(clearMode = true) {
        const form = document.querySelector(FREE_SELECTORS.batchConfirm);
        if (!form) {
            return;
        }

        form.hidden = true;
        const error = form.querySelector(FREE_SELECTORS.batchError);
        if (error) {
            error.hidden = true;
        }

        if (clearMode) {
            form.action = form.dataset.batchUrl || form.action;
            const ruleInput = form.querySelector(FREE_SELECTORS.batchRuleIdInput);
            if (ruleInput) {
                ruleInput.disabled = true;
                ruleInput.value = '';
            }
        }
    }

    clearBatchSelection() {
        this.batchSelection.clear();
        this.closeBatchConfirm();
        this.updateBatchUi();
    }

    updateBatchUi() {
        const selectedCount = this.batchSelection?.size || 0;
        const bar = document.querySelector(FREE_SELECTORS.batchBar);
        const count = document.querySelector(FREE_SELECTORS.batchCount);
        const countLabel = document.querySelector(FREE_SELECTORS.batchCountLabel);

        if (bar) {
            bar.hidden = selectedCount === 0;
        }

        if (count) {
            count.textContent = String(selectedCount);
        }

        if (countLabel) {
            countLabel.textContent = selectedCount === 1 ? 'issue selected' : 'issues selected';
        }

        document.querySelectorAll(FREE_SELECTORS.batchIssueCard).forEach((card) => {
            const issueUid = String(card.dataset.issueUid || '').trim();
            const selected = this.batchSelection.has(issueUid);
            card.classList.toggle('is-selected', selected);
        });

        this.getBatchIssueButtons().forEach((button) => {
            const issueUid = String(button.dataset.issueUid || '').trim();
            const selected = this.batchSelection.has(issueUid);
            button.classList.toggle('is-checked', selected);
            button.setAttribute('aria-checked', selected ? 'true' : 'false');
        });

        document.querySelectorAll(FREE_SELECTORS.batchToggleGroup).forEach((button) => {
            const groupKey = String(button.dataset.groupKey || '').trim();
            const groupButtons = this.getBatchIssueButtons().filter((item) => item.dataset.groupKey === groupKey);
            const selectedInGroup = groupButtons.filter((item) => this.batchSelection.has(String(item.dataset.issueUid || ''))).length;
            const allSelected = groupButtons.length > 0 && selectedInGroup === groupButtons.length;

            button.classList.toggle('is-on', allSelected);
            button.setAttribute('aria-pressed', allSelected ? 'true' : 'false');
            button.textContent = allSelected
                ? 'Selected'
                : (selectedInGroup > 0 ? 'Select remaining' : 'Select all in group');
        });
    }

    prepareBatchForm(form, mode, ruleId = '', ruleCount = 0) {
        const inputs = form.querySelector(FREE_SELECTORS.batchSelectedInputs);
        const title = form.querySelector(FREE_SELECTORS.batchConfirmTitle);
        const sub = form.querySelector(FREE_SELECTORS.batchConfirmSub);
        const summary = form.querySelector(FREE_SELECTORS.batchSummary);
        const ruleInput = form.querySelector(FREE_SELECTORS.batchRuleIdInput);
        const reason = form.querySelector(FREE_SELECTORS.batchReason);
        const error = form.querySelector(FREE_SELECTORS.batchError);

        inputs && (inputs.innerHTML = '');
        error && (error.hidden = true);
        reason && (reason.value = '');
        form.querySelectorAll(FREE_SELECTORS.expiryPicker).forEach((picker) => {
            const never = picker.querySelector('input[name="ignoreExpiry"][value="never"]');
            if (never) {
                never.checked = true;
            }
            const date = picker.querySelector('input[name="ignoreUntilDate"]');
            if (date) {
                date.value = '';
            }
            this.updateExpiryPicker(picker);
        });

        if (mode === 'page' || mode === 'site') {
            form.action = mode === 'site'
                ? (form.dataset.ruleSiteUrl || form.action)
                : (form.dataset.rulePageUrl || form.action);

            if (ruleInput) {
                ruleInput.disabled = false;
                ruleInput.value = ruleId;
            }

            const scopeLabel = mode === 'site' ? 'site' : 'this page';
            title && (title.textContent = `Ignore all ${ruleId} issues on ${scopeLabel}`);
            sub && (sub.textContent = `This will mark ${ruleCount} open issues with this rule as ignored. They stay visible in the report but no longer block the quality gate.`);
            summary && (summary.innerHTML = this.buildBatchSummaryHtml([{ ruleId, count: ruleCount }]));
            return;
        }

        form.action = form.dataset.batchUrl || form.action;
        if (ruleInput) {
            ruleInput.disabled = true;
            ruleInput.value = '';
        }

        const selected = this.getSelectedIssueData();
        selected.forEach((issue) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'issueUids[]';
            input.value = issue.uid;
            inputs?.appendChild(input);
        });

        const count = selected.length;
        title && (title.textContent = `Ignore ${count} selected ${count === 1 ? 'issue' : 'issues'}`);
        sub && (sub.textContent = 'These issues will be marked as ignored. They stay visible in the report but no longer block the quality gate.');
        summary && (summary.innerHTML = this.buildBatchSummaryHtml(this.groupSelectedByRule(selected)));
    }

    getBatchIssueButtons() {
        return Array.from(document.querySelectorAll(FREE_SELECTORS.batchToggleIssue));
    }

    getSelectedIssueData() {
        return Array.from(document.querySelectorAll(FREE_SELECTORS.batchIssueCard))
            .filter((card) => this.batchSelection.has(String(card.dataset.issueUid || '')))
            .map((card) => ({
                uid: String(card.dataset.issueUid || ''),
                ruleId: String(card.dataset.ruleId || 'unknown'),
            }))
            .filter((item) => item.uid !== '');
    }

    groupSelectedByRule(selected) {
        const map = new Map();
        selected.forEach((issue) => {
            const current = map.get(issue.ruleId) || 0;
            map.set(issue.ruleId, current + 1);
        });

        return Array.from(map.entries()).map(([ruleId, count]) => ({ ruleId, count }));
    }

    buildBatchSummaryHtml(items) {
        if (!items || items.length === 0) {
            return '';
        }

        const rows = items
            .map((item) => `
                <li class="aqg-bulkconfirm__summary-row">
                    <span class="aqg-bulkconfirm__summary-rule"></span>
                    <span>${this.escapeHtml(item.ruleId)}</span>
                    <span class="aqg-bulkconfirm__summary-count">${Number(item.count || 0)}×</span>
                </li>
            `)
            .join('');

        return `
            <div class="aqg-bulkconfirm__summary-title">Selection breakdown</div>
            <ul class="aqg-bulkconfirm__summary-list">${rows}</ul>
        `;
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


    initRulesTab() {
        document.querySelectorAll('[data-a11y-defaults="true"]').forEach((defaults) => {
            const toggle = defaults.querySelector('[data-a11y-defaults-toggle="true"]');
            const panel = defaults.querySelector('[data-a11y-defaults-panel="true"]');
            const label = defaults.querySelector('[data-a11y-defaults-toggle-label="true"]');

            if (!toggle || !panel || !label) {
                return;
            }

            const updateToggle = (expanded) => {
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                panel.hidden = !expanded;
                label.textContent = expanded ? 'Hide' : 'Show';
            };

            updateToggle(toggle.getAttribute('aria-expanded') !== 'false' && !panel.hidden);

            toggle.addEventListener('click', () => {
                updateToggle(toggle.getAttribute('aria-expanded') !== 'true');
            });
        });

        document.querySelectorAll('[data-a11y-rules-form="true"]').forEach((form) => {
            const actionbar = document.querySelector('[data-a11y-rules-actionbar="true"]');
            const discardButton = form.querySelector('[data-a11y-rules-discard="true"]')
                || document.querySelector('[data-a11y-rules-discard="true"]');
            const saveButton = form.querySelector('[data-a11y-rules-save="true"]')
                || document.querySelector('[data-a11y-rules-save="true"]');
            const metaLabel = form.querySelector('[data-a11y-rules-meta-label="true"]')
                || document.querySelector('[data-a11y-rules-meta-label="true"]');
            const searchInput = form.querySelector('[data-a11y-rules-search="true"]');
            const filterButtons = Array.from(form.querySelectorAll('[data-a11y-rules-filter]'));
            const ruleRows = Array.from(form.querySelectorAll('[data-a11y-rule-row="true"]'));
            const groups = Array.from(form.querySelectorAll('[data-a11y-rule-group="true"]'));
            const visibleCount = form.querySelector('[data-a11y-rules-visible-count="true"]');
            const emptyState = form.querySelector('[data-a11y-rules-empty="true"]');
            const emptyQuery = form.querySelector('[data-a11y-rules-empty-query="true"]');
            const resetPanel = form.querySelector('[data-a11y-rules-reset-panel="true"]');
            const resetOpen = form.querySelector('[data-a11y-rules-reset-open="true"]');
            const resetCancel = form.querySelector('[data-a11y-rules-reset-cancel="true"]');
            const resetConfirm = form.querySelector('[data-a11y-rules-reset-confirm="true"]');
            const fields = Array.from(form.querySelectorAll('[data-a11y-rules-input="true"]'));

            if (fields.length === 0) {
                return;
            }

            const setInitialValue = (field) => {
                if (field instanceof HTMLInputElement && field.type === 'checkbox') {
                    field.dataset.initialChecked = field.checked ? '1' : '0';
                    return;
                }

                field.dataset.initialValue = field.value;
            };

            const isFieldDirty = (field) => {
                if (field instanceof HTMLInputElement && field.type === 'checkbox') {
                    return (field.checked ? '1' : '0') !== (field.dataset.initialChecked || '0');
                }

                return field.value !== (field.dataset.initialValue || '');
            };

            const restoreField = (field) => {
                if (field instanceof HTMLInputElement && field.type === 'checkbox') {
                    field.checked = (field.dataset.initialChecked || '0') === '1';
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                    return;
                }

                field.value = field.dataset.initialValue || '';
                field.dispatchEvent(new Event('input', { bubbles: true }));
            };

            const updateActionbar = () => {
                const dirtyFields = fields.filter(isFieldDirty);
                const isDirty = dirtyFields.length > 0;

                actionbar?.classList.toggle('is-dirty', isDirty);

                if (discardButton) {
                    discardButton.hidden = !isDirty;
                }

                if (saveButton) {
                    saveButton.disabled = !isDirty;
                }

                if (metaLabel && actionbar) {
                    metaLabel.textContent = isDirty
                        ? `${actionbar.dataset.a11yRulesDirtyMeta || 'Unsaved changes'} — ${dirtyFields.length} change${dirtyFields.length === 1 ? '' : 's'}`
                        : (actionbar.dataset.a11yRulesSavedMeta || 'All changes saved');
                }
            };

            const updateRuleRow = (checkbox) => {
                const row = checkbox.closest('[data-a11y-rule-row="true"]');
                if (!row) {
                    return;
                }

                const enabled = checkbox.checked;
                const dirty = isFieldDirty(checkbox);
                const offTag = row.querySelector('[data-a11y-rule-off-tag="true"]');
                const dirtyMarker = row.querySelector('[data-a11y-rule-dirty="true"]');
                const status = row.querySelector('[data-a11y-rule-status="true"]');

                row.classList.toggle('is-disabled', !enabled);
                row.classList.toggle('is-dirty', dirty);
                row.dataset.ruleEnabled = enabled ? '1' : '0';

                if (offTag) {
                    offTag.hidden = enabled;
                    offTag.classList.toggle('aqg-is-hidden', enabled);
                }
                if (dirtyMarker) {
                    dirtyMarker.hidden = !dirty;
                }
                if (status) {
                    status.textContent = enabled ? 'Reported in scans' : 'Not reported in scans';
                }
            };

            const updateGroupCounts = () => {
                groups.forEach((group) => {
                    const rows = Array.from(group.querySelectorAll('[data-a11y-rule-row="true"]'));
                    const enabledRows = rows.filter((row) => row.dataset.ruleEnabled === '1');
                    const countNode = group.querySelector('[data-a11y-rule-group-count="true"]');
                    const toggle = group.querySelector('[data-a11y-rule-group-toggle="true"]');
                    const enabledCount = enabledRows.length;
                    const totalCount = rows.length;

                    if (countNode) {
                        countNode.innerHTML = `<strong>${enabledCount}</strong> / ${totalCount} on`;
                        countNode.classList.toggle('aqg-rgroup__count--all', enabledCount === totalCount && totalCount > 0);
                        countNode.classList.toggle('aqg-rgroup__count--none', enabledCount === 0 && totalCount > 0);
                        countNode.classList.toggle('aqg-rgroup__count--partial', enabledCount > 0 && enabledCount < totalCount);
                    }

                    if (toggle) {
                        toggle.textContent = enabledCount === totalCount ? 'Disable all in group' : 'Enable all in group';
                    }
                });
            };

            let activeFilter = 'all';

            const updateRuleFilter = () => {
                const query = (searchInput?.value || '').trim().toLowerCase();
                let shownRows = 0;

                ruleRows.forEach((row) => {
                    const matchesQuery = query === '' || (row.dataset.ruleSearch || '').includes(query);
                    const matchesFilter = activeFilter === 'all'
                        || (activeFilter === 'enabled' && row.dataset.ruleEnabled === '1')
                        || (activeFilter === 'disabled' && row.dataset.ruleEnabled !== '1');
                    const visible = matchesQuery && matchesFilter;

                    row.hidden = !visible;
                    if (visible) {
                        shownRows++;
                    }
                });

                groups.forEach((group) => {
                    const hasVisibleRow = Array.from(group.querySelectorAll('[data-a11y-rule-row="true"]'))
                        .some((row) => !row.hidden);
                    group.hidden = !hasVisibleRow;
                });

                if (visibleCount) {
                    visibleCount.textContent = String(shownRows);
                }
                if (emptyState) {
                    emptyState.hidden = shownRows > 0;
                }
                if (emptyQuery) {
                    emptyQuery.textContent = query || activeFilter;
                }
            };

            fields.forEach((field) => {
                setInitialValue(field);

                field.addEventListener('input', () => {
                    updateActionbar();
                    updateRuleFilter();
                });

                field.addEventListener('change', () => {
                    if (field instanceof HTMLInputElement && field.type === 'checkbox') {
                        updateRuleRow(field);
                        updateGroupCounts();
                    }

                    updateActionbar();
                    updateRuleFilter();
                });
            });

            filterButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    activeFilter = button.dataset.a11yRulesFilter || 'all';
                    filterButtons.forEach((item) => {
                        const isActive = item === button;
                        item.classList.toggle('is-active', isActive);
                        item.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                    });
                    updateRuleFilter();
                });
            });

            groups.forEach((group) => {
                const toggle = group.querySelector('[data-a11y-rule-group-toggle="true"]');
                toggle?.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();

                    const checkboxes = Array.from(group.querySelectorAll('[data-a11y-rule-toggle="true"]'));
                    const shouldEnable = checkboxes.some((checkbox) => checkbox instanceof HTMLInputElement && !checkbox.checked);

                    checkboxes.forEach((checkbox) => {
                        if (!(checkbox instanceof HTMLInputElement)) {
                            return;
                        }

                        checkbox.checked = shouldEnable;
                        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                });
            });

            resetOpen?.addEventListener('click', (event) => {
                event.preventDefault();
                if (resetPanel) {
                    resetPanel.hidden = false;
                }
            });

            resetCancel?.addEventListener('click', (event) => {
                event.preventDefault();
                if (resetPanel) {
                    resetPanel.hidden = true;
                }
            });

            resetConfirm?.addEventListener('click', (event) => {
                event.preventDefault();
                form.querySelectorAll('[data-a11y-rule-toggle="true"]').forEach((checkbox) => {
                    if (checkbox instanceof HTMLInputElement) {
                        checkbox.checked = true;
                        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
                if (resetPanel) {
                    resetPanel.hidden = true;
                }
            });

            searchInput?.addEventListener('input', updateRuleFilter);

            discardButton?.addEventListener('click', (event) => {
                event.preventDefault();

                fields.forEach(restoreField);

                if (resetPanel) {
                    resetPanel.hidden = true;
                }

                updateGroupCounts();
                updateActionbar();
                updateRuleFilter();
            });

            form.addEventListener('submit', () => {
                fields.forEach(setInitialValue);
                updateActionbar();
            });

            form.querySelectorAll('[data-a11y-rule-toggle="true"]').forEach((checkbox) => {
                if (checkbox instanceof HTMLInputElement) {
                    updateRuleRow(checkbox);
                }
            });

            updateGroupCounts();
            updateActionbar();
            updateRuleFilter();
        });
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
                    <span class="aqg-validation tone-error">
                        ${this.translate('settings.licence.validation.emptyKey', 'Please enter a licence key first.')}
                    </span>
                `;
                return;
            }

            if (endpoint === '') {
                resultBox.innerHTML = `
                    <span class="aqg-validation tone-error">
                        ${this.translate('settings.licence.validation.unreachable', 'Validation failed because the API could not be reached.')}
                    </span>
                `;
                return;
            }

            const originalText = validateButton.textContent.trim();
            validateButton.disabled = true;
            validateButton.textContent = this.translate('settings.licence.validating', 'Validating...');

            resultBox.innerHTML = `
                <span class="aqg-validation tone-running">
                    <svg class="aqi-spin" width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" focusable="false">
                        <circle cx="6" cy="6" r="4.5" fill="none" stroke="currentColor" stroke-width="1.4" opacity=".35"></circle>
                        <path d="M10.5 6 A4.5 4.5 0 0 0 6 1.5" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"></path>
                    </svg>
                    <span>${this.translate('settings.licence.validation.contacting', 'Contacting licence server...')}</span>
                </span>
            `;

            try {
                const response = await new AjaxRequest(endpoint).post({
                    licenceKey,
                });

                const data = await response.resolve();

                if (data.valid) {
                    const validationParts = [
                        this.escapeHtml(this.translate('settings.licence.validation.validated', 'Validated')),
                    ];

                    const planLabel = data.isTrial
                        ? this.translate('settings.licence.plan.trialShort', 'TRIAL')
                        : this.formatPlanLabel(data.plan || 'PRO');

                    if (planLabel) {
                        validationParts.push(this.escapeHtml(String(planLabel)));
                    }

                    if (data.domain) {
                        validationParts.push(`${this.escapeHtml(this.translate('settings.licence.boundTo', 'bound to'))} <strong>${this.escapeHtml(String(data.domain))}</strong>`);
                    }

                    resultBox.innerHTML = `
                        <span class="aqg-validation tone-ok">
                            <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" focusable="false">
                                <path d="M2.5 6.2 L5 8.4 L9.5 3.4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                            <span>${validationParts.join(' · ')}</span>
                        </span>
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
                        <span class="aqg-validation tone-error">
                            <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" focusable="false">
                                <circle cx="6" cy="6" r="4.7" fill="none" stroke="currentColor" stroke-width="1.4"></circle>
                                <path d="M4.2 4.2 L7.8 7.8 M7.8 4.2 L4.2 7.8" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"></path>
                            </svg>
                            <span><strong>${this.escapeHtml(invalidTitle)}</strong> · ${this.escapeHtml(invalidMessage)}</span>
                        </span>
                    `;
                }
            } catch {
                resultBox.innerHTML = `
                    <span class="aqg-validation tone-error">
                        <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" focusable="false">
                            <circle cx="6" cy="6" r="4.7" fill="none" stroke="currentColor" stroke-width="1.4"></circle>
                            <path d="M6 3.3 V6.3" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"></path>
                            <circle cx="6" cy="8.5" r=".65" fill="currentColor"></circle>
                        </svg>
                        <span>${this.translate('settings.licence.validation.unreachable', 'Validation failed because the API could not be reached.')}</span>
                    </span>
                `;
            } finally {
                validateButton.disabled = false;
                validateButton.textContent = originalText;
            }
        });
    }

    escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
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
            const remotePageUid = String(row.dataset.a11yRemotePageUid || '').toLowerCase();
            const remoteScanUid = String(row.dataset.a11yRemoteScanUid || '').toLowerCase();
            const haystack = `${title} ${uid} ${url} ${remotePageUid} ${remoteScanUid}`;
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

        const updateAll = () => {
            groups.forEach((group) => this.updateSettingsGroupState(group));
            this.updateSettingsSummary();
        };

        groups.forEach((group) => {
            group.querySelectorAll(FREE_SELECTORS.settingsCheckboxes).forEach((checkbox) => {
                checkbox.dataset.initialChecked = checkbox.checked ? '1' : '0';

                checkbox.addEventListener('change', updateAll);
                checkbox.addEventListener('input', updateAll);
            });
        });

        document.querySelectorAll(FREE_SELECTORS.settingsEnableAll).forEach((button) => {
            button.addEventListener('click', () => {
                document.querySelectorAll(FREE_SELECTORS.settingsCheckboxes).forEach((checkbox) => {
                    checkbox.checked = true;
                });
                updateAll();
            });
        });

        document.querySelectorAll(FREE_SELECTORS.settingsEnableGroup).forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                const group = button.closest(FREE_SELECTORS.settingsGroups);
                if (!group) {
                    return;
                }
                group.querySelectorAll(FREE_SELECTORS.settingsCheckboxes).forEach((checkbox) => {
                    checkbox.checked = true;
                });
                updateAll();
            });
        });

        document.querySelectorAll('form').forEach((form) => {
            form.addEventListener('reset', () => {
                window.setTimeout(updateAll, 0);
            });
        });

        updateAll();
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

        countElement.dataset.enabledCount = String(checkedCount);
        countElement.innerHTML = `<strong>${checkedCount}</strong><span> / ${totalCount} ${this.escapeHtml(countElement.dataset.enabledLabel || 'enabled')}</span>`;
        countElement.classList.toggle('aqg-fgroup__count--all', checkedCount === totalCount);
        countElement.classList.toggle('aqg-fgroup__count--partial', checkedCount > 0 && checkedCount < totalCount);
        countElement.classList.toggle('aqg-fgroup__count--none', checkedCount === 0);

        checkboxes.forEach((checkbox) => {
            const item = checkbox.closest(FREE_SELECTORS.settingsItem);
            if (!item) {
                return;
            }

            item.classList.toggle('is-unchecked', !checkbox.checked);
            item.classList.toggle('is-checked', checkbox.checked);
        });
    }

    updateSettingsSummary() {
        const groups = Array.from(document.querySelectorAll(FREE_SELECTORS.settingsGroups));
        if (groups.length === 0) {
            return;
        }

        let enabledCount = 0;
        let totalCount = 0;
        let isDirty = false;

        groups.forEach((group) => {
            const checkboxes = Array.from(group.querySelectorAll(FREE_SELECTORS.settingsCheckboxes));
            enabledCount += checkboxes.filter((checkbox) => checkbox.checked).length;
            totalCount += checkboxes.length;
            isDirty = isDirty || checkboxes.some((checkbox) => (checkbox.checked ? '1' : '0') !== checkbox.dataset.initialChecked);
        });

        document.querySelectorAll(FREE_SELECTORS.settingsSummaryEnabled).forEach((element) => {
            element.textContent = String(enabledCount);
        });
        document.querySelectorAll(FREE_SELECTORS.settingsSummaryTotal).forEach((element) => {
            element.textContent = String(totalCount);
        });
        document.querySelectorAll(FREE_SELECTORS.settingsSummaryGroups).forEach((element) => {
            element.textContent = String(groups.length);
        });
        document.querySelectorAll(FREE_SELECTORS.settingsDirty).forEach((element) => {
            element.hidden = !isDirty;
        });
        document.querySelectorAll(FREE_SELECTORS.settingsActionbar).forEach((element) => {
            element.classList.toggle('is-dirty', isDirty);
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

        const reasonInput = form.querySelector('textarea[name="reason"], input[name="reason"]');
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

    isLocalScanCancelledResponse(data) {
        return data && data.success === false && data.code === 'local_scan_cancelled';
    }

    async handleLocalScanCancel(button) {
        const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
        const endpoint = ajaxUrls.a11y_scan_cancel || '';

        if (!endpoint) {
            this.showNotification(
                this.translate('notification.scan.cancelMissing', 'Content scan could not be cancelled because the cancel endpoint is missing.'),
                'error'
            );
            return;
        }

        this.localScanCancellationRequested = true;
        this.updateLocalScanProgress(
            true,
            this.translate('overview.localScan.cancelling', 'Cancelling content scan...')
        );
        this.updateLocalCancelButtons(true);

        try {
            const response = await new AjaxRequest(endpoint).post({});
            const data = await response.resolve();
            const status = data.status || {};

            if (status.running === false) {
                this.localScanCancellationRequested = false;
                this.updateLocalScanProgress(false, '');
                this.updateLocalCancelButtons(false);
            } else {
                this.pollLocalScanCancellationUntilStopped();
            }

            this.showNotification(
                this.translate('notification.scan.cancelRequested', 'Content scan cancellation requested.'),
                'info'
            );
        } catch (error) {
            this.localScanCancellationRequested = false;
            const message = error instanceof Error ? error.message : 'Unknown error';
            this.updateLocalCancelButtons(false);
            this.showNotification(
                this.format(this.translate('notification.scan.cancelFailed', 'Content scan cancel request failed: %s'), message),
                'error'
            );
        }
    }

    async pollLocalScanCancellationUntilStopped() {
        const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
        const endpoint = ajaxUrls.a11y_scan_status || '';

        if (!endpoint) {
            return;
        }

        for (let attempt = 0; attempt < 30; attempt++) {
            await new Promise((resolve) => window.setTimeout(resolve, 1000));

            try {
                const response = await new AjaxRequest(endpoint).get();
                const data = await response.resolve();
                const status = data.status || {};

                if (status.running === false) {
                    this.localScanCancellationRequested = false;
                    this.updateLocalScanProgress(false, '');
                    this.updateLocalCancelButtons(false);
                    return;
                }
            } catch {
                return;
            }
        }
    }

    async handleRescan(button) {
        const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
        const endpoint = ajaxUrls.a11y_scan_page || '';
        const pageUid = Number.parseInt(button.dataset.pageUid || '0', 10);
        const languageUid = Number.parseInt(button.dataset.languageUid || '0', 10);

        if (!endpoint || pageUid <= 0) {
            this.showNotification(
                this.translate('notification.scan.missingPageUid', 'Scan failed: missing endpoint or page UID.'),
                'error'
            );
            return;
        }

        this.setLoadingState(button, true);
        this.updateLocalScanProgress(
            true,
            this.translate('overview.localScan.runningEta', 'This usually takes a moment. You can view progress while it runs.'),
            new Date()
        );
        await this.yieldForPaint();

        try {
            const response = await new AjaxRequest(endpoint).post({ pageUid, languageUid });
            const data = await response.resolve();

            if (this.isLocalScanCancelledResponse(data)) {
                this.localScanCancellationRequested = false;
                this.showNotification(
                    this.translate('notification.scan.cancelled', 'Content scan was cancelled.'),
                    'info'
                );
                this.updateLocalScanProgress(false, '');
                this.setLoadingState(button, false);
                return;
            }

            this.localScanCancellationRequested = false;

            this.showNotification(
                this.format(
                    this.translate('notification.scan.completed', 'Scan complete — %d new, %d resolved.'),
                    Number(data.issuesNew || 0),
                    Number(data.issuesResolved || 0)
                ),
                'success'
            );
            this.showScanWarnings(data);

            this.updateLocalScanProgress(
                true,
                this.translate('overview.localScan.completedReloading', 'Scan completed. Reloading results...'),
                null,
                'completed'
            );
            this.reloadCurrentModule(2600);
        } catch (error) {
            this.localScanCancellationRequested = false;
            const message = error instanceof Error ? error.message : 'Unknown error';
            this.showNotification(
                this.format(this.translate('notification.scan.failed', 'Scan failed: %s'), message),
                'error'
            );
            this.updateLocalScanProgress(false, '');
            this.setLoadingState(button, false);
        }
    }

    async yieldForPaint() {
        await new Promise((resolve) => window.requestAnimationFrame(() => resolve()));
        await new Promise((resolve) => window.requestAnimationFrame(() => resolve()));
    }

    showScanWarnings(data) {
        const warnings = Array.isArray(data?.warnings) ? data.warnings : [];
        warnings.forEach((warning) => {
            const message = String(warning?.message || '').trim();
            if (message === '') {
                return;
            }

            this.showNotification(
                this.format(
                    this.translate('notification.scan.warning', 'Scan completed with a warning: %s'),
                    message
                ),
                'warning'
            );
        });
    }


    formatLocalScanStartedAt(date = new Date()) {
        return `${this.translate('overview.progress.started', 'Started')} ${date.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
        })} · `;
    }

    updateLocalScanProgress(visible, message = '', startedAt = null, status = 'active') {
        const normalizedStatus = String(status || 'active');
        const boxes = Array.from(document.querySelectorAll(PRO_SELECTORS.localScanProgressBox));
        const messageElements = Array.from(document.querySelectorAll(PRO_SELECTORS.localScanProgressMessage));
        const titleElements = Array.from(document.querySelectorAll(PRO_SELECTORS.localScanProgressTitle));
        const startedElements = Array.from(document.querySelectorAll(PRO_SELECTORS.localScanProgressStarted));
        const statusElements = Array.from(document.querySelectorAll(PRO_SELECTORS.localScanProgressStatus));
        const staticBlocks = Array.from(document.querySelectorAll(PRO_SELECTORS.localScanStaticContent || ''));

        boxes.forEach((box) => {
            const isCompleted = normalizedStatus === 'completed';
            const isIndeterminate = visible && !['completed', 'failed', 'cancelled'].includes(normalizedStatus);
            const spinner = box.querySelector('.spinner-border');
            const fill = box.querySelector('.aqg-progress-block__fill');
            const cancelButton = box.querySelector(FREE_SELECTORS.localCancelScanButton);

            box.classList.toggle('d-none', !visible);
            box.classList.toggle('aqg-progress-block--indeterminate', isIndeterminate);
            box.classList.toggle('aqg-progress-block--completed', isCompleted);

            if (spinner) {
                spinner.classList.toggle('d-none', !isIndeterminate);
            }

            if (fill && isCompleted) {
                fill.style.width = '100%';
            } else if (fill && !visible) {
                fill.style.width = '';
            }

            if (cancelButton) {
                cancelButton.classList.toggle('d-none', isCompleted || !visible);
            }
        });

        staticBlocks.forEach((block) => {
            block.classList.toggle('d-none', visible);
        });

        if (message) {
            messageElements.forEach((messageEl) => {
                messageEl.textContent = message;
            });
        }

        const titleText = normalizedStatus === 'completed'
            ? this.translate('overview.localScan.completedTitle', 'Content scan completed')
            : this.translate('overview.localScan.runningTitle', 'Content scan is running');
        titleElements.forEach((titleEl) => {
            titleEl.textContent = titleText;
        });

        const statusText = normalizedStatus === 'completed'
            ? this.translate('overview.scan.completedShort', 'completed')
            : this.translate('overview.localScan.activeShort', 'active');
        statusElements.forEach((statusEl) => {
            statusEl.textContent = statusText;
            statusEl.className = 'aqg-status-badge';

            if (normalizedStatus === 'completed') {
                statusEl.classList.add('aqg-status-badge--ok');
            } else if (normalizedStatus === 'failed') {
                statusEl.classList.add('aqg-status-badge--error');
            } else if (normalizedStatus === 'cancelled') {
                statusEl.classList.add('aqg-status-badge--neutral');
            } else {
                statusEl.classList.add('aqg-status-badge--running');
            }
        });

        this.updateLocalCancelButtons(visible && this.localScanCancellationRequested);

        startedElements.forEach((startedEl) => {
            if (!visible) {
                startedEl.textContent = '';
                return;
            }

            if (normalizedStatus === 'completed') {
                startedEl.textContent = '';
                return;
            }

            if (startedEl.textContent.trim() !== '') {
                return;
            }

            startedEl.textContent = this.formatLocalScanStartedAt(startedAt instanceof Date ? startedAt : new Date());
        });
    }

    updateLocalCancelButtons(isCancelling) {
        document.querySelectorAll(FREE_SELECTORS.localCancelScanButton).forEach((button) => {
            if (!button.dataset.originalText) {
                button.dataset.originalText = button.textContent.trim();
            }

            button.disabled = isCancelling;
            button.textContent = isCancelling
                ? (button.dataset.loadingText || this.translate('overview.localScan.cancellingShort', 'Cancelling'))
                : button.dataset.originalText;
        });
    }

    async handleScanAll(button) {
        const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
        const endpoint = ajaxUrls.a11y_scan_site || '';
        const rootPid = Number.parseInt(button.dataset.rootPid || '0', 10);
        const languageUid = Number.parseInt(button.dataset.languageUid || '0', 10);

        if (!endpoint || rootPid <= 0) {
            this.showNotification(
                this.translate('notification.scan.missingRootPid', 'Scan failed: missing endpoint or root PID.'),
                'error'
            );
            return;
        }

        this.setLoadingState(button, true);
        this.updateLocalScanProgress(
            true,
            this.translate('overview.localScan.runningEta', 'This usually takes a moment. You can view progress while it runs.'),
            new Date()
        );
        await this.yieldForPaint();

        try {
            const response = await new AjaxRequest(endpoint).post({ rootPid, languageUid });
            const data = await response.resolve();

            if (this.isLocalScanCancelledResponse(data)) {
                this.localScanCancellationRequested = false;
                this.showNotification(
                    this.translate('notification.scan.cancelled', 'Content scan was cancelled.'),
                    'info'
                );
                this.updateLocalScanProgress(false, '');
                this.setLoadingState(button, false);
                return;
            }

            this.localScanCancellationRequested = false;

            this.showNotification(
                this.format(
                    this.translate('notification.scanAll.completed', 'Full scan complete — %d new, %d resolved.'),
                    Number(data.issuesNew || 0),
                    Number(data.issuesResolved || 0)
                ),
                'success'
            );
            this.showScanWarnings(data);

            this.updateLocalScanProgress(
                true,
                this.translate('overview.localScan.completedReloading', 'Scan completed. Reloading results...'),
                null,
                'completed'
            );
            this.reloadCurrentModule(2600);
        } catch (error) {
            this.localScanCancellationRequested = false;
            const message = error instanceof Error ? error.message : 'Unknown error';
            this.showNotification(
                this.format(this.translate('notification.scan.failed', 'Scan failed: %s'), message),
                'error'
            );
            this.updateLocalScanProgress(false, '');
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

                this.localScanCancellationRequested = Boolean(status.cancelRequested);

                if (running) {
                    const startedAt = Number(status.startedAt || 0) > 0
                        ? new Date(Number(status.startedAt) * 1000)
                        : new Date();

                    this.updateLocalScanProgress(
                        true,
                        this.localScanCancellationRequested
                            ? this.translate('overview.localScan.cancelling', 'Cancelling content scan...')
                            : this.translate('overview.localScan.runningEta', 'This usually takes a moment. You can view progress while it runs.'),
                        startedAt
                    );
                } else if (document.querySelector(`${PRO_SELECTORS.localScanProgressBox}:not(.d-none)`)) {
                    this.localScanCancellationRequested = false;
                    this.updateLocalScanProgress(
                        true,
                        this.translate('overview.localScan.completedReloading', 'Scan completed. Reloading results...'),
                        null,
                        'completed'
                    );
                    this.reloadCurrentModule(2600);
                    return;
                }

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
