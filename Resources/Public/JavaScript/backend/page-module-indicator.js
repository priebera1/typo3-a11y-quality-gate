import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

const SELECTOR_PANEL = '[data-aqg-page-module-indicator="true"]';
const SELECTOR_SCAN = '[data-aqg-indicator-scan="true"]';

const STATUS_ICON = `
<svg class="aqg-page-module-indicator__icon aqg-page-module-indicator__icon--spin" aria-hidden="true" viewBox="0 0 14 14" focusable="false">
  <circle cx="7" cy="7" r="5.4" fill="none" stroke="currentColor" stroke-opacity="0.25" stroke-width="1.6"></circle>
  <path d="M12.4 7 A5.4 5.4 0 0 0 7 1.6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"></path>
</svg>`;

const COMPLETED_ICON = `
<svg class="aqg-page-module-indicator__icon" aria-hidden="true" viewBox="0 0 14 14" focusable="false">
  <path d="M3 7.2 L6 10 L11 4.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path>
</svg>`;

const DARK_SELECTORS = [
    '[data-bs-theme="dark"]',
    '[data-color-scheme="dark"]',
    '[data-theme="dark"]',
    '.typo3-backend-dark',
    '.theme-dark',
    '.t3js-theme-dark',
    '.aqi-theme-dark',
];

function translate(key, fallback = '') {
    return window.TYPO3?.lang?.[key] || fallback;
}

function parseLanguageUid(value) {
    const raw = String(value ?? '').trim();
    if (!/^\d+$/.test(raw)) {
        return null;
    }

    return Number.parseInt(raw, 10);
}

function resolveLanguageUidForButton(button) {
    const fromButton = parseLanguageUid(button?.dataset?.languageUid);
    if (fromButton !== null) {
        return fromButton;
    }

    const panel = button?.closest?.(SELECTOR_PANEL);
    const fromPanel = parseLanguageUid(panel?.dataset?.languageUid);
    if (fromPanel !== null) {
        return fromPanel;
    }

    const parameters = new URLSearchParams(window.location.search);
    for (const key of ['language', 'languageUid', 'L', 'sys_language_uid']) {
        const value = parseLanguageUid(parameters.get(key));
        if (value !== null) {
            return value;
        }
    }

    return 0;
}

function format(template, ...values) {
    let result = template;

    values.forEach((value) => {
        result = result.replace(/%[ds]/, String(value));
    });

    return result;
}

function reloadCurrentFrame(delay = 0) {
    const run = () => {
        try {
            const url = new URL(window.location.href);
            url.searchParams.set('_aqgReload', String(Date.now()));
            window.location.replace(url.toString());
            return;
        } catch {
            // fall back to normal reload
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

function showNotification(message, type = 'info') {
    const notificationApi = window.top?.TYPO3?.Notification;
    if (!notificationApi) {
        return;
    }

    const severityMap = {
        info: 0,
        success: 1,
        warning: -1,
        error: -2,
    };

    notificationApi.showMessage(
        translate('notification.title', 'Accessibility'),
        message,
        severityMap[type] ?? 0,
        5
    );
}

function documentUsesDarkTheme(doc) {
    if (!doc?.documentElement) {
        return false;
    }

    const colorScheme = String(doc.documentElement.dataset.colorScheme || '').trim();
    const bootstrapTheme = String(doc.documentElement.dataset.bsTheme || '').trim();
    const theme = String(doc.documentElement.dataset.theme || '').trim();
    if (colorScheme === 'dark' || bootstrapTheme === 'dark' || theme === 'dark') {
        return true;
    }

    if (colorScheme === 'light' || bootstrapTheme === 'light' || theme === 'light') {
        return false;
    }

    if (colorScheme === 'auto' || bootstrapTheme === 'auto' || theme === 'auto') {
        return window.matchMedia?.('(prefers-color-scheme: dark)').matches === true;
    }

    const candidates = [doc.documentElement, doc.body].filter(Boolean);
    return candidates.some((element) => DARK_SELECTORS.some((selector) => element.matches(selector) || Boolean(element.querySelector(selector))));
}

function syncPanelTheme(panel) {
    let topDocumentUsesDarkTheme = false;
    try {
        topDocumentUsesDarkTheme = documentUsesDarkTheme(window.top?.document);
    } catch {
    }

    const isDark = documentUsesDarkTheme(document) || topDocumentUsesDarkTheme;
    panel.classList.toggle('aqg-page-module-indicator--theme-dark', isDark);
}

function syncAllPanelThemes() {
    document.querySelectorAll(SELECTOR_PANEL).forEach((panel) => {
        syncPanelTheme(panel);
    });
}

function setPanelStateRunning(panel, scanMode = 'local') {
    ['ok', 'warning', 'error', 'none', 'completed'].forEach((state) => {
        panel.classList.remove(`aqg-page-module-indicator--${state}`);
    });
    panel.classList.add('aqg-page-module-indicator--running');
    panel.setAttribute('aria-busy', 'true');

    const headline = panel.querySelector('.aqg-page-module-indicator__headline');
    if (headline) {
        headline.textContent = panel.dataset.runningHeadline || translate('pageModuleIndicator.runningHeadline', 'Scan running');
    }

    const body = panel.querySelector('.aqg-page-module-indicator__text');
    if (body) {
        body.textContent = panel.dataset.runningBody || translate('pageModuleIndicator.runningBody', 'Checking this page for accessibility issues...');
    }

    const meta = panel.querySelector('.aqg-page-module-indicator__meta');
    if (meta) {
        meta.textContent = panel.dataset.runningMeta || translate('pageModuleIndicator.runningMeta', 'Started just now');
    }

    const statusPill = panel.querySelector('.aqg-page-module-indicator__status-pill');
    if (statusPill) {
        const label = panel.dataset.runningStatus || translate('pageModuleIndicator.runningStatus', 'Scanning');
        statusPill.innerHTML = `${STATUS_ICON}<span>${label}</span>`;
    }

    const rows = Array.from(panel.querySelectorAll('.aqg-page-module-indicator__status-row'));
    const rowsToMark = scanMode === 'combined' ? rows : rows.slice(0, 1);
    rowsToMark.forEach((row) => {
        row.className = 'aqg-page-module-indicator__status-row aqg-page-module-indicator__status-row--running';
        const value = row.querySelector('.aqg-page-module-indicator__status-value');
        if (value) {
            value.innerHTML = `${STATUS_ICON}<span>${panel.dataset.runningStatus || translate('pageModuleIndicator.runningStatus', 'Scanning')}...</span>`;
        }
    });

    let progress = panel.querySelector('.aqg-page-module-indicator__progress');
    if (!progress) {
        progress = document.createElement('div');
        panel.querySelector('.aqg-page-module-indicator__content')?.after(progress);
    }

    progress.className = 'aqg-page-module-indicator__progress is-indeterminate';
    progress.setAttribute('role', 'progressbar');
    progress.setAttribute('aria-valuemin', '0');
    progress.setAttribute('aria-valuemax', '100');
    progress.removeAttribute('aria-valuenow');
    progress.setAttribute('aria-valuetext', panel.dataset.runningStatus || translate('pageModuleIndicator.runningStatus', 'Scanning'));
    progress.innerHTML = '<span class="aqg-page-module-indicator__progress-bar"></span>';
}

function setPanelStateCompleted(panel, scanMode = 'local') {
    ['warning', 'error', 'none', 'running'].forEach((state) => {
        panel.classList.remove(`aqg-page-module-indicator--${state}`);
    });
    panel.classList.add('aqg-page-module-indicator--ok', 'aqg-page-module-indicator--completed');
    panel.setAttribute('aria-busy', 'false');

    const headline = panel.querySelector('.aqg-page-module-indicator__headline');
    if (headline) {
        headline.textContent = translate('pageModuleIndicator.completedHeadline', 'Scan completed');
    }

    const body = panel.querySelector('.aqg-page-module-indicator__text');
    if (body) {
        body.textContent = scanMode === 'combined'
            ? translate('pageModuleIndicator.completedBodyCombined', 'Content and frontend scans completed. Reloading results...')
            : translate('pageModuleIndicator.completedBody', 'Content scan completed. Reloading results...');
    }

    const meta = panel.querySelector('.aqg-page-module-indicator__meta');
    if (meta) {
        meta.textContent = translate('pageModuleIndicator.completedMeta', 'Reloading results...');
    }

    const statusPill = panel.querySelector('.aqg-page-module-indicator__status-pill');
    if (statusPill) {
        statusPill.innerHTML = `${COMPLETED_ICON}<span>${translate('pageModuleIndicator.completedStatus', 'Completed')}</span>`;
    }

    const rows = Array.from(panel.querySelectorAll('.aqg-page-module-indicator__status-row'));
    const rowsToMark = scanMode === 'combined' ? rows : rows.slice(0, 1);
    rowsToMark.forEach((row) => {
        row.className = 'aqg-page-module-indicator__status-row aqg-page-module-indicator__status-row--ok aqg-page-module-indicator__status-row--completed';
        const value = row.querySelector('.aqg-page-module-indicator__status-value');
        if (value) {
            value.innerHTML = `${COMPLETED_ICON}<span>${translate('pageModuleIndicator.completedStatus', 'Completed')}</span>`;
        }
    });

    const progress = panel.querySelector('.aqg-page-module-indicator__progress');
    if (progress) {
        progress.className = 'aqg-page-module-indicator__progress aqg-page-module-indicator__progress--completed';
        progress.setAttribute('aria-valuenow', '100');
        progress.setAttribute('aria-valuetext', translate('pageModuleIndicator.completedStatus', 'Completed'));
        progress.innerHTML = '<span class="aqg-page-module-indicator__progress-bar"></span>';
    }

    panel.querySelectorAll(SELECTOR_SCAN).forEach((button) => {
        button.disabled = true;
        button.classList.add('is-disabled');
        if (button.dataset.originalText) {
            button.textContent = button.dataset.originalText;
        }
    });

    window.setTimeout(() => {
        if (!document.documentElement.contains(panel)) {
            return;
        }

        if (!panel.classList.contains('aqg-page-module-indicator--completed')) {
            return;
        }

        setScanButtonsLoading(panel, false);
    }, 8000);
}

function setScanButtonsLoading(panel, isLoading) {
    panel.querySelectorAll(SELECTOR_SCAN).forEach((button) => {
        button.disabled = isLoading;
        button.classList.toggle('is-disabled', isLoading);

        if (isLoading) {
            if (!button.dataset.originalText) {
                button.dataset.originalText = button.textContent.trim();
            }
            button.textContent = button.dataset.loadingText || translate('action.scanning', 'Scanning...');
            return;
        }

        button.textContent = button.dataset.originalText || button.textContent;
    });
}

async function extractAjaxErrorMessage(error, fallback) {
    const candidates = [
        error?.responseJSON,
        error?.response?.responseJSON,
    ];

    for (const candidate of candidates) {
        if (candidate && typeof candidate === 'object' && !Array.isArray(candidate)) {
            return String(candidate.error || candidate.message || fallback);
        }
    }

    const response = error?.response || error?.xhr || null;
    if (response && typeof response.responseText === 'string' && response.responseText.trim() !== '') {
        try {
            const data = JSON.parse(response.responseText);
            return String(data.error || data.message || fallback);
        } catch {
            return response.responseText.trim();
        }
    }

    return error instanceof Error ? error.message : fallback;
}

async function pollRemoteScan(jobId, siteIdentifier) {
    const statusEndpoint = window.TYPO3?.settings?.ajaxUrls?.a11y_pro_crawl_status || '';
    if (!statusEndpoint || !jobId || !siteIdentifier) {
        throw new Error(translate('notification.proScan.statusMissing', 'Missing frontend scan status endpoint, job ID or site identifier.'));
    }

    for (let attempt = 1; attempt <= 120; attempt++) {
        const url = new URL(statusEndpoint, window.location.origin);
        url.searchParams.set('jobId', String(jobId));
        url.searchParams.set('siteIdentifier', String(siteIdentifier));

        const response = await new AjaxRequest(url.toString()).get();
        const data = await response.resolve();
        const status = String(data.status || '').trim();

        if (status === 'completed') {
            return data;
        }

        if (status === 'failed') {
            throw new Error(String(data.error || data.message || translate('notification.proScan.didNotComplete', 'Frontend scan did not complete successfully.')));
        }

        await new Promise((resolve) => window.setTimeout(resolve, 3000));
    }

    throw new Error(translate('notification.proScan.statusTimeout', 'Frontend scan status polling timed out.'));
}

async function fetchRemoteSummary(jobId, siteIdentifier) {
    const summaryEndpoint = window.TYPO3?.settings?.ajaxUrls?.a11y_pro_crawl_summary || '';
    if (!summaryEndpoint || !jobId || !siteIdentifier) {
        throw new Error(translate('notification.proScan.summaryMissing', 'Missing frontend scan summary endpoint, job ID or site identifier.'));
    }

    const url = new URL(summaryEndpoint, window.location.origin);
    url.searchParams.set('jobId', String(jobId));
    url.searchParams.set('siteIdentifier', String(siteIdentifier));

    const response = await new AjaxRequest(url.toString()).get();
    return response.resolve();
}

async function runRemotePageScan({ pageUid, pageUrl, siteIdentifier, languageUid }) {
    const endpoint = window.TYPO3?.settings?.ajaxUrls?.a11y_pro_crawl_submit_page || '';

    if (!endpoint || pageUid <= 0 || pageUrl === '' || siteIdentifier === '') {
        throw new Error(translate('notification.proScan.missingPageContext', 'Remote page scan failed: missing endpoint, page UID, page URL or site identifier.'));
    }

    const submitResponse = await new AjaxRequest(endpoint).post({
        pageUid,
        pageUrl,
        siteIdentifier,
        languageUid,
        axeLocale: 'en',
    });
    const submitData = await submitResponse.resolve();

    const jobId = String(submitData.jobId || '').trim();
    const responseSiteIdentifier = String(submitData.siteIdentifier || siteIdentifier).trim();
    if (!jobId || !responseSiteIdentifier) {
        throw new Error(String(submitData.error || 'Missing crawler job ID or site identifier'));
    }

    await pollRemoteScan(jobId, responseSiteIdentifier);
    return fetchRemoteSummary(jobId, responseSiteIdentifier);
}

async function scanPage(button) {
    if (button.disabled || button.classList.contains('is-disabled')) {
        return;
    }

    const panel = button.closest(SELECTOR_PANEL);
    const endpoint = window.TYPO3?.settings?.ajaxUrls?.a11y_scan_page || '';
    const pageUid = Number.parseInt(button.dataset.pageUid || panel?.dataset.pageUid || '0', 10);
    const configuredScanMode = String(button.dataset.aqgScanMode || 'local');
    const pageUrl = String(button.dataset.pageUrl || '').trim();
    const siteIdentifier = String(button.dataset.siteIdentifier || panel?.dataset.site || '').trim();
    const remoteScanEnabled = button.dataset.remoteScanEnabled === '1'
        || panel?.dataset?.remoteScanEnabled === '1';
    const scanMode = configuredScanMode === 'combined' && remoteScanEnabled && pageUrl !== '' && siteIdentifier !== ''
        ? 'combined'
        : 'local';
    const languageUid = resolveLanguageUidForButton(button);

    if (!endpoint || pageUid <= 0 || !panel) {
        showNotification(
            translate('notification.scan.missingPageUid', 'Scan failed: missing endpoint or page UID.'),
            'error'
        );
        return;
    }

    const originalClassName = panel.className;
    const originalHtml = panel.innerHTML;
    const originalAriaBusy = panel.getAttribute('aria-busy');

    setPanelStateRunning(panel, scanMode);
    setScanButtonsLoading(panel, true);
    let completed = false;

    try {
        const response = await new AjaxRequest(endpoint).post({ pageUid, languageUid });
        const data = await response.resolve();
        let remoteSummary = null;

        if (scanMode === 'combined') {
            remoteSummary = await runRemotePageScan({ pageUid, pageUrl, siteIdentifier, languageUid });
        }

        if (remoteSummary) {
            showNotification(
                format(
                    translate('notification.scanPageWithFrontend.completed', 'Page scan complete - local %d new, %d resolved; frontend %d new, %d resolved.'),
                    Number(data.issuesNew || 0),
                    Number(data.issuesResolved || 0),
                    Number(remoteSummary.issuesNew || 0),
                    Number(remoteSummary.issuesResolved || 0)
                ),
                'success'
            );
        } else {
            showNotification(
                format(
                    translate('notification.scan.completed', 'Scan complete - %d new, %d resolved.'),
                    Number(data.issuesNew || 0),
                    Number(data.issuesResolved || 0)
                ),
                'success'
            );
        }

        completed = true;
        setPanelStateCompleted(panel, scanMode);
        reloadCurrentFrame(2400);
    } catch (error) {
        const message = await extractAjaxErrorMessage(error, 'Unknown error');
        showNotification(
            format(translate('notification.scan.failed', 'Scan failed: %s'), message),
            'error'
        );
        panel.className = originalClassName;
        panel.innerHTML = originalHtml;
        if (originalAriaBusy === null) {
            panel.removeAttribute('aria-busy');
        } else {
            panel.setAttribute('aria-busy', originalAriaBusy);
        }
    } finally {
        if (!completed) {
            setScanButtonsLoading(panel, false);
        }
    }
}

document.addEventListener('click', (event) => {
    const button = event.target.closest(SELECTOR_SCAN);
    if (button instanceof HTMLButtonElement) {
        event.preventDefault();
        void scanPage(button);
    }
});

syncAllPanelThemes();

document.addEventListener('DOMContentLoaded', syncAllPanelThemes);

try {
    const observer = new MutationObserver(syncAllPanelThemes);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme', 'data-bs-theme', 'data-color-scheme'] });
    if (document.body) {
        observer.observe(document.body, { attributes: true, attributeFilter: ['class', 'data-theme', 'data-bs-theme', 'data-color-scheme'] });
    }
    if (window.top?.document && window.top.document !== document) {
        observer.observe(window.top.document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme', 'data-bs-theme', 'data-color-scheme'] });
        if (window.top.document.body) {
            observer.observe(window.top.document.body, { attributes: true, attributeFilter: ['class', 'data-theme', 'data-bs-theme', 'data-color-scheme'] });
        }
    }
} catch {
}

try {
    window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener('change', syncAllPanelThemes);
} catch {
}
