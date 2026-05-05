export const FREE_SELECTORS = {
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
    licenceKeyInput: '[data-a11y-licence-key-input="true"]',
    licenceToggleButton: '[data-action="a11y-toggle-licence-key"]',
    licenceValidateButton: '[data-action="a11y-validate-licence"]',
    licenceValidateResult: '[data-a11y-licence-validate-result="true"]',
};

export const PRO_SELECTORS = {
    proScanSiteButton: '[data-action="a11y-pro-scan-site"]',
    overviewSourceTrigger: '[data-a11y-overview-source-trigger]',
    overviewSourcePanel: '[data-a11y-overview-panel]',
    highlightNode: '[data-action="a11y-highlight-node"]',
    screenshotModalTrigger: '[data-action="a11y-open-screenshot-modal"]',
    proScanPageButton: '[data-action="a11y-pro-scan-page"]',
    remoteScanProgressBox: '[data-a11y-remote-scan-progress="true"]',
    remoteScanProgressStatus: '[data-a11y-remote-scan-status="true"]',
    remoteScanProgressMessage: '[data-a11y-remote-scan-message="true"]',
    remoteScanProgressSpinner: '[data-a11y-remote-scan-progress="true"] .spinner-border',
    overviewRoot: '.a11y-overview',
    remotePageRoot: '.a11y-page-detail',
};

export const LS_SOURCE_KEY = 'a11y-overview-source';