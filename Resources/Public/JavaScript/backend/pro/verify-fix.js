import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

const POLL_INTERVAL_MS = 3000;
const MAX_POLLS = 200;
const TERMINAL_SCAN_STATES = new Set(['completed', 'failed', 'cancelled']);

const label = (key, fallback) => {
    const value = window.TYPO3?.lang?.[key];
    return typeof value === 'string' && value !== '' ? value : fallback;
};

const readError = async (error, fallback) => {
    const candidates = [error?.responseJSON, error?.response?.responseJSON];
    for (const candidate of candidates) {
        if (candidate && typeof candidate === 'object') {
            return {code: String(candidate.code || ''), message: String(candidate.message || candidate.error || fallback)};
        }
    }
    const response = error?.response;
    if (response && typeof response.clone === 'function') {
        try {
            const data = await response.clone().json();
            if (data && typeof data === 'object') {
                return {code: String(data.code || ''), message: String(data.message || data.error || fallback)};
            }
        } catch {
            // Not a JSON body; fall back to the generic message.
        }
    }
    return {code: '', message: fallback};
};

const getJson = async (endpoint, params) => {
    const url = new URL(endpoint, window.location.origin);
    Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, String(value)));
    const response = await new AjaxRequest(url.toString()).get();
    return response.resolve();
};

/**
 * Requests a verification scan for one finding, follows it until it is stored and returns the outcome.
 * The finding is identified by its id only; the server derives URL, site and page from it.
 */
export const runVerifyFix = async ({findingId, ajaxUrls, wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms)), onProgress = () => {}}) => {
    const submit = await new AjaxRequest(ajaxUrls.a11y_pro_verify_fix).post({findingId});
    const started = await submit.resolve();
    const verificationUid = Number(started.verificationUid || 0);
    const jobId = String(started.jobId || '');
    const siteIdentifier = String(started.siteIdentifier || '');
    if (verificationUid <= 0 || jobId === '' || siteIdentifier === '') {
        throw new Error(label('verifyFix.error.requestFailed', 'The verification scan could not be started.'));
    }

    return followVerifyFix({verificationUid, jobId, siteIdentifier, ajaxUrls, wait, onProgress});
};

/**
 * Follows a requested verification until its scan is stored and decided — also one requested before the page
 * was reloaded, which the page renders as pending with its verification, job and site.
 */
export const followVerifyFix = async ({verificationUid, jobId, siteIdentifier, ajaxUrls, wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms)), onProgress = () => {}}) => {
    onProgress(label('verifyFix.message.pending', 'A fresh scan of this page is running.'));

    let scanStatus = '';
    for (let attempt = 0; attempt < MAX_POLLS && !TERMINAL_SCAN_STATES.has(scanStatus); attempt++) {
        if (attempt > 0) {
            await wait(POLL_INTERVAL_MS);
        }
        const status = await getJson(ajaxUrls.a11y_pro_crawl_status, {jobId, siteIdentifier});
        scanStatus = String(status.status || '');
        if (scanStatus === 'completed' && !status.persisted) {
            await getJson(ajaxUrls.a11y_pro_crawl_summary, {jobId, siteIdentifier});
        }
    }

    // The result is only decided on the stored scan; a completed scan may need a moment to be saved.
    let result = {outcome: 'pending'};
    for (let attempt = 0; attempt < 10 && result.outcome === 'pending'; attempt++) {
        if (attempt > 0) {
            await wait(POLL_INTERVAL_MS);
        }
        result = await getJson(ajaxUrls.a11y_pro_verify_fix_result, {verificationUid});
    }

    return result;
};

const renderResult = (target, result) => {
    if (!(target instanceof HTMLElement)) {
        return;
    }
    target.replaceChildren();
    target.dataset.aqgVerifyFixOutcome = String(result.outcome || 'pending');

    const strong = document.createElement('strong');
    strong.textContent = String(result.label || '');
    target.append(strong, document.createTextNode(' ' + String(result.message || '')));

    if (result.verificationPageUrl) {
        const link = document.createElement('a');
        link.href = String(result.verificationPageUrl);
        link.textContent = label('verifyFix.openScan', 'Open verification scan');
        target.append(document.createTextNode(' '), link);
    }
};

export const initializeVerifyFix = (root = document) => {
    if (!(root instanceof Document || root instanceof HTMLElement) || root.dataset?.aqgVerifyFixInitialized === '1') {
        return false;
    }
    if (root instanceof HTMLElement) {
        root.dataset.aqgVerifyFixInitialized = '1';
    }

    resumePendingVerifications(root);

    root.addEventListener('click', async (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-action="a11y-verify-fix"]') : null;
        if (!(button instanceof HTMLButtonElement) || button.disabled) {
            return;
        }
        event.preventDefault();

        const findingId = Number.parseInt(button.dataset.findingId || '0', 10);
        const target = document.querySelector(`[data-aqg-verify-fix-result="${findingId}"]`);
        const ajaxUrls = window.TYPO3?.settings?.ajaxUrls ?? {};
        if (findingId <= 0 || !ajaxUrls.a11y_pro_verify_fix) {
            return;
        }

        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        try {
            const result = await runVerifyFix({
                findingId,
                ajaxUrls,
                onProgress: (message) => renderResult(target, {outcome: 'pending', label: label('verifyFix.outcome.pending', 'Checking…'), message}),
            });
            renderResult(target, result);
        } catch (error) {
            const {code, message} = await readError(error, label('verifyFix.error.requestFailed', 'The verification scan could not be started.'));
            renderResult(target, {
                outcome: 'error',
                label: label('verifyFix.outcome.notVerified', 'Not verified'),
                message: code === 'remote_scan_already_active'
                    ? label('verifyFix.error.scanActive', 'Another frontend scan is running for this site. Verify the fix when it has finished.')
                    : message,
            });
        } finally {
            button.disabled = false;
            button.setAttribute('aria-busy', 'false');
        }
    });

    return true;
};

/**
 * A verification still running when the page was (re)loaded keeps being followed, so its verdict appears
 * without asking for another scan.
 */
const resumePendingVerifications = (root) => {
    const ajaxUrls = window.TYPO3?.settings?.ajaxUrls ?? {};
    if (!ajaxUrls.a11y_pro_verify_fix_result) {
        return;
    }

    root.querySelectorAll('[data-aqg-verify-fix-result][data-aqg-verify-fix-outcome="pending"]').forEach(async (target) => {
        const verificationUid = Number.parseInt(target.dataset.aqgVerifyFixVerification || '0', 10);
        const jobId = String(target.dataset.aqgVerifyFixJob || '');
        const siteIdentifier = String(target.dataset.aqgVerifyFixSite || '');
        if (verificationUid <= 0 || jobId === '' || siteIdentifier === '') {
            return;
        }
        const button = root.querySelector(`[data-action="a11y-verify-fix"][data-finding-id="${target.dataset.aqgVerifyFixResult}"]`);
        if (button instanceof HTMLButtonElement) {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
        }
        try {
            renderResult(target, await followVerifyFix({
                verificationUid,
                jobId,
                siteIdentifier,
                ajaxUrls,
                onProgress: (message) => renderResult(target, {outcome: 'pending', label: label('verifyFix.outcome.pending', 'Checking…'), message}),
            }));
        } catch (error) {
            const {message} = await readError(error, label('verifyFix.error.requestFailed', 'The verification scan could not be started.'));
            renderResult(target, {outcome: 'error', label: label('verifyFix.outcome.notVerified', 'Not verified'), message});
        } finally {
            if (button instanceof HTMLButtonElement) {
                button.disabled = false;
                button.setAttribute('aria-busy', 'false');
            }
        }
    });
};

initializeVerifyFix(document.querySelector('[data-aqg-verify-fix-root]') ?? document.createElement('div'));
