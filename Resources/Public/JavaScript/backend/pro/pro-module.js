import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';
import { A11yFreeBackendModule } from '../free/free-module.js';
import { FREE_SELECTORS, PRO_SELECTORS, LS_SOURCE_KEY } from '../core/constants.js';

export class A11yProBackendModule extends A11yFreeBackendModule {
    constructor() {
        super();

        this.remotePollInProgress = false;
        this.activeOverviewSource = 'local';
        this.scanInProgress = false;
        this.remoteSubmitInProgress = false;
        this.beforeUnloadHandler = (event) => {
            if (!this.remoteSubmitInProgress) {
                return undefined;
            }

            const message = this.translate(
                'notification.proScan.leaveWarning',
                'Frontend scan request is still being started. Please wait a moment before leaving this page.'
            );

            event.preventDefault();
            event.returnValue = message;

            return message;
        };

        window.addEventListener('beforeunload', this.beforeUnloadHandler);

        this.initOverviewSourceTabs();
        this.initRemoteScanProgress();
        this.restoreRemoteScanStateFromDom();
        this.bindProEvents();
    }

    bindProEvents() {
        document.addEventListener('click', async (event) => {
            const sourceTrigger = event.target.closest(PRO_SELECTORS.overviewSourceTrigger);
            if (sourceTrigger) {
                event.preventDefault();
                const source = String(sourceTrigger.dataset.a11yOverviewSourceTrigger || 'local');
                this.setOverviewSource(source);
                return;
            }

            const highlightButton = event.target.closest(PRO_SELECTORS.highlightNode);
            if (highlightButton) {
                event.preventDefault();
                this.handleHighlightNode(highlightButton);
                return;
            }

            const screenshotTrigger = event.target.closest(PRO_SELECTORS.screenshotModalTrigger);
            if (screenshotTrigger) {
                event.preventDefault();
                this.handleScreenshotModal(screenshotTrigger);
                return;
            }

            const proScanPageButton = event.target.closest(PRO_SELECTORS.proScanPageButton);
            if (proScanPageButton) {
                event.preventDefault();
                await this.handleProScanPage(proScanPageButton);
            }

            const proCancelScanButton = event.target.closest(PRO_SELECTORS.proCancelScanButton);
            if (proCancelScanButton) {
                event.preventDefault();
                await this.handleProCancelScan(proCancelScanButton);
            }
        });

        document.querySelectorAll(PRO_SELECTORS.proScanSiteButton).forEach((button) => {
            button.addEventListener('click', async () => {
                await this.handleProScanSite(button);
            });
        });
    }

    initOverviewSourceTabs() {
        const triggers = Array.from(document.querySelectorAll(PRO_SELECTORS.overviewSourceTrigger));
        if (triggers.length === 0) {
            return;
        }

        const requestedSource = new URLSearchParams(window.location.search).get('aqgSource');
        let stored = requestedSource || 'local';
        if (!requestedSource) {
            try {
                stored = localStorage.getItem(LS_SOURCE_KEY) || 'local';
            } catch {
                stored = 'local';
            }
        }
        this.setOverviewSource(stored);
    }

    setOverviewSource(source) {
        this.activeOverviewSource = source;

        try {
            localStorage.setItem(LS_SOURCE_KEY, source);
        } catch {
            // noop
        }

        document.querySelectorAll(PRO_SELECTORS.overviewSourceTrigger).forEach((trigger) => {
            const triggerSource = String(trigger.dataset.a11yOverviewSourceTrigger || '');
            const isActive = triggerSource === source;
            trigger.classList.toggle('active', isActive);
            trigger.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });

        document.querySelectorAll(PRO_SELECTORS.overviewSourcePanel).forEach((panel) => {
            const panelSource = String(panel.dataset.a11yOverviewPanel || '');
            panel.hidden = panelSource !== source;
        });

        const activePanel = document.querySelector(
            `${PRO_SELECTORS.overviewSourcePanel}[data-a11y-overview-panel="${source}"]`
        );

        if (!activePanel) {
            return;
        }

        const input = activePanel.querySelector(FREE_SELECTORS.overviewSearch);
        if (input) {
            const searchMode = String(input.dataset.a11ySearchMode || 'client').trim().toLowerCase();

            if (searchMode !== 'server') {
                this.applyOverviewSearchForInput(input);
            }
        }
    }

    handleHighlightNode(button) {
        const uid = String(button.dataset.uid || '').trim();
        const url = String(button.dataset.url || '').trim();

        if (!uid || !url) {
            this.showNotification(
                this.translate('notification.highlight.missing', 'Highlight failed: missing uid or page URL.'),
                'warning'
            );
            return;
        }

        const targetUrl = new URL(url, window.location.origin);
        targetUrl.searchParams.set('aqgDebug', '1');
        targetUrl.searchParams.set('aqgh', uid);

        window.open(targetUrl.toString(), '_blank', 'noopener');
    }

    handleScreenshotModal(trigger) {
        const imageUrl = String(trigger.dataset.imageUrl || '').trim();
        const imageTitle = String(trigger.dataset.imageTitle || '').trim()
            || this.translate('module.remotePageDetail.screenshotPreview', 'Screenshot preview');

        if (!imageUrl) {
            this.showNotification(
                this.translate('notification.screenshot.missing', 'Screenshot could not be opened.'),
                'warning'
            );
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'p-2 text-center';

        const image = document.createElement('img');
        image.src = imageUrl;
        image.alt = imageTitle;
        image.style.maxWidth = '100%';
        image.style.height = 'auto';
        image.style.borderRadius = '6px';

        wrapper.appendChild(image);

        Modal.advanced({
            title: imageTitle,
            content: wrapper,
            severity: Severity.info,
            size: Modal.sizes.large,
            staticBackdrop: true,
            buttons: [
                {
                    text: this.translate('action.close', 'Close'),
                    active: true,
                    btnClass: 'btn-default',
                    trigger: () => {
                        Modal.dismiss();
                    },
                },
            ],
        });
    }

    async handleProScanSite(button) {
        const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
        const submitEndpoint = ajaxUrls.a11y_pro_crawl_submit || '';

        const rootPid = Number.parseInt(button.dataset.rootPid || '0', 10);
        const currentPageUid = Number.parseInt(button.dataset.currentPageUid || String(rootPid), 10);
        const maxPages = Number.parseInt(button.dataset.maxPages || '20', 10);
        const siteIdentifier = String(button.dataset.siteIdentifier || '').trim();
        const languageUid = this.resolveScanLanguageUid(button);

        if (!submitEndpoint || rootPid <= 0) {
            this.showNotification(
                this.translate('notification.proScan.missingRootPid', 'Frontend scan failed: missing endpoint or root PID.'),
                'error'
            );
            return;
        }

        this.setLoadingState(button, true);
        this.setScanInProgress(true);
        this.remoteSubmitInProgress = true;

        try {
            const submitResponse = await new AjaxRequest(submitEndpoint).post({
                rootPid,
                pageUid: currentPageUid > 0 ? currentPageUid : rootPid,
                maxPages,
                siteIdentifier,
                languageUid,
                followLinks: true,
                axeLocale: 'en',
            });

            const submitData = await submitResponse.resolve();
            await this.handleProSubmitPayload(button, submitData, {
                fallbackScope: 'site',
                fallbackPageUid: null,
                fallbackLanguageUid: languageUid,
            });
        } catch (error) {
            const errorPayload = await this.extractAjaxErrorData(error);

            if (errorPayload && errorPayload.code === 'remote_scan_already_active') {
                await this.handleProSubmitPayload(button, errorPayload, {
                    fallbackScope: 'site',
                    fallbackPageUid: null,
                    fallbackLanguageUid: languageUid,
                });
                return;
            }

            const message = this.extractReadableRemoteError(
                errorPayload,
                error,
                this.translate('notification.proScan.failed', 'Frontend scan failed.')
            );
            const notificationMessage = this.buildRemoteErrorNotificationText(errorPayload, message);

            this.resetRemoteScanDomState();
            this.updateRemoteScanUi({
                visible: true,
                status: 'failed',
                message,
                pagesScanned: null,
                pagesTotal: null,
            });
            this.showNotification(notificationMessage, 'error');
            this.remoteSubmitInProgress = false;
            this.setScanInProgress(false);
            this.setLoadingState(button, false);
        }
    }

    async handleProScanPage(button) {
        const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
        const submitEndpoint = ajaxUrls.a11y_pro_crawl_submit_page || '';

        const pageUid = Number.parseInt(button.dataset.pageUid || '0', 10);
        const pageUrl = String(button.dataset.pageUrl || '').trim();
        const siteIdentifier = String(button.dataset.siteIdentifier || '').trim();
        const languageUid = this.resolveScanLanguageUid(button);

        if (!submitEndpoint || pageUid <= 0 || pageUrl === '' || siteIdentifier === '') {
            this.showNotification(
                this.translate(
                    'notification.proScan.missingPageContext',
                    'Remote page scan failed: missing endpoint, page UID, page URL or site identifier.'
                ),
                'error'
            );
            return;
        }

        this.setLoadingState(button, true);
        this.setScanInProgress(true);
        this.remoteSubmitInProgress = true;

        try {
            const submitResponse = await new AjaxRequest(submitEndpoint).post({
                pageUid,
                pageUrl,
                siteIdentifier,
                languageUid,
                axeLocale: 'en',
            });

            const submitData = await submitResponse.resolve();
            await this.handleProSubmitPayload(button, submitData, {
                fallbackScope: 'page',
                fallbackPageUid: pageUid,
                fallbackLanguageUid: languageUid,
            });
        } catch (error) {
            const errorPayload = await this.extractAjaxErrorData(error);

            if (errorPayload && errorPayload.code === 'remote_scan_already_active') {
                await this.handleProSubmitPayload(button, errorPayload, {
                    fallbackScope: 'page',
                    fallbackPageUid: pageUid,
                    fallbackLanguageUid: languageUid,
                });
                return;
            }

            const message = this.extractReadableRemoteError(
                errorPayload,
                error,
                this.translate('notification.proScan.pageFailed', 'Remote page scan failed.')
            );
            const notificationMessage = this.buildRemoteErrorNotificationText(errorPayload, message);

            this.resetRemoteScanDomState();
            this.updateRemoteScanUi({
                visible: true,
                status: 'failed',
                message,
                pagesScanned: null,
                pagesTotal: null,
            });

            this.showNotification(notificationMessage, 'error');
            this.remoteSubmitInProgress = false;
            this.setLoadingState(button, false);
            this.setScanInProgress(false);
        }
    }

    async handleProCancelScan(button) {
        const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
        const cancelEndpoint = ajaxUrls.a11y_pro_crawl_cancel || '';
        const state = this.getRestorableRemoteScanState();

        const jobId = String(button.dataset.jobId || state?.jobId || '').trim();
        const siteIdentifier = String(button.dataset.siteIdentifier || state?.siteIdentifier || '').trim();

        if (!cancelEndpoint || !jobId || !siteIdentifier) {
            this.showNotification(
                this.translate(
                    'notification.proScan.cancelMissing',
                    'Remote scan could not be cancelled because the cancel endpoint, job ID or site identifier is missing.'
                ),
                'warning'
            );
            return;
        }

        this.setLoadingState(button, true);
        this.updateRemoteScanUi({
            visible: true,
            status: 'cancelling',
            message: this.translate(
                'notification.proScan.cancelling',
                'Cancelling frontend scan…'
            ),
            pagesScanned: state?.pagesScanned ?? null,
            pagesTotal: state?.pagesTotal ?? null,
        });

        try {
            const response = await new AjaxRequest(cancelEndpoint).post({
                jobId,
                siteIdentifier,
            });
            const data = await response.resolve();
            const status = String(data.status || 'cancelled');

            this.updateRemoteScanDomState({
                status,
            });

            this.updateRemoteScanUi({
                visible: true,
                status,
                message: status === 'cancelled'
                    ? this.translate('notification.proScan.cancelled', 'Frontend scan was cancelled.')
                    : this.translate('notification.proScan.cancelFinished', 'Frontend scan was already finished.'),
                pagesScanned: state?.pagesScanned ?? null,
                pagesTotal: state?.pagesTotal ?? null,
            });

            this.showNotification(
                status === 'cancelled'
                    ? this.translate('notification.proScan.cancelled', 'Frontend scan was cancelled.')
                    : this.translate('notification.proScan.cancelFinished', 'Frontend scan was already finished.'),
                'info'
            );

            this.resetRemoteScanDomState();
            this.setScanInProgress(false);
            this.reloadCurrentModule(900);
        } catch (error) {
            const errorPayload = await this.extractAjaxErrorData(error);
            const message = this.extractReadableRemoteError(
                errorPayload,
                error,
                this.translate('notification.proScan.cancelFailed', 'Frontend scan cancel request failed.')
            );

            this.showNotification(message, 'error');
            this.setLoadingState(button, false);
            this.updateRemoteScanUi({
                visible: true,
                status: state?.status || 'running',
                message: this.buildRemoteProgressMessage(state?.pagesScanned ?? null, state?.pagesTotal ?? null),
                pagesScanned: state?.pagesScanned ?? null,
                pagesTotal: state?.pagesTotal ?? null,
            });
        }
    }

    async handleProSubmitPayload(button, submitData, { fallbackScope, fallbackPageUid, fallbackLanguageUid = null }) {
        this.remoteSubmitInProgress = false;
        if (submitData.success === false && submitData.code === 'remote_scan_already_active') {
            const restoredJobId = String(submitData.jobId || '').trim();
            const restoredSiteIdentifier = String(submitData.siteIdentifier || '').trim();

            if (!restoredJobId || !restoredSiteIdentifier) {
                throw new Error(submitData.error || 'Frontend scan is already active, but restore data is missing.');
            }

            this.updateRemoteScanDomState({
                jobId: restoredJobId,
                siteIdentifier: restoredSiteIdentifier,
                scope: String(submitData.scanScope || fallbackScope),
                pageUid: String(submitData.pageUid || fallbackPageUid || ''),
                status: String(submitData.status || 'queued'),
                pagesScanned: String(submitData.pagesScanned || 0),
                pagesTotal: submitData.pagesTotal ? String(submitData.pagesTotal) : '',
                languageUid: this.resolveSubmittedLanguageUid(submitData, fallbackLanguageUid, button),
            });

            this.updateRemoteScanUi({
                visible: true,
                status: String(submitData.status || 'queued'),
                message: this.buildRemoteProgressMessage(
                    Number(submitData.pagesScanned || 0),
                    submitData.pagesTotal ? Number(submitData.pagesTotal) : null
                ),
                pagesScanned: Number(submitData.pagesScanned || 0),
                pagesTotal: submitData.pagesTotal ? Number(submitData.pagesTotal) : null,
            });

            this.showNotification(
                this.translate('notification.proScan.alreadyRunning', 'A frontend scan is already running. Restoring progress.'),
                'info'
            );

            await this.monitorRemoteScan({
                jobId: restoredJobId,
                siteIdentifier: restoredSiteIdentifier,
                scope: String(submitData.scanScope || fallbackScope),
                pageUid: submitData.pageUid ? Number(submitData.pageUid) : fallbackPageUid,
            });

            return;
        }

        const jobId = String(submitData.jobId || '').trim();
        const siteIdentifier = String(submitData.siteIdentifier || '').trim();

        if (!jobId || !siteIdentifier) {
            throw new Error(submitData.error || 'Missing crawler job ID or site identifier');
        }

        this.updateRemoteScanDomState({
            jobId,
            siteIdentifier,
            scope: fallbackScope,
            pageUid: fallbackPageUid !== null ? String(fallbackPageUid) : '',
            status: String(submitData.status || 'queued'),
            pagesScanned: '0',
            pagesTotal: fallbackScope === 'page' ? '1' : '',
            languageUid: this.resolveSubmittedLanguageUid(submitData, fallbackLanguageUid, button),
        });

        this.updateRemoteScanUi({
            visible: true,
            status: String(submitData.status || 'queued'),
            message: this.buildRemoteProgressMessage(0, fallbackScope === 'page' ? 1 : null),
            pagesScanned: 0,
            pagesTotal: fallbackScope === 'page' ? 1 : null,
        });

        this.showNotification(
            fallbackScope === 'page'
                ? this.translate('notification.proScan.pageStarted', 'Frontend page scan started.')
                : this.translate('notification.proScan.started', 'Frontend scan started.'),
            'info'
        );

        await this.monitorRemoteScan({
            jobId,
            siteIdentifier,
            scope: fallbackScope,
            pageUid: fallbackPageUid,
        });
    }

    async extractAjaxErrorData(error) {
        const objectCandidates = [
            error?.responseJSON,
            error?.response?.responseJSON,
        ];

        for (const candidate of objectCandidates) {
            if (candidate && typeof candidate === 'object' && !Array.isArray(candidate)) {
                return candidate;
            }
        }

        const responseCandidates = [
            error?.response,
            error?.xhr?.response,
        ];

        for (const response of responseCandidates) {
            if (
                response
                && typeof response === 'object'
                && typeof response.json === 'function'
            ) {
                try {
                    const parsed = await response.clone().json();
                    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                        return parsed;
                    }
                } catch {
                    try {
                        const text = await response.clone().text();
                        const parsed = this.parseJsonSafely(text) || this.extractCrawlerBodyJson(text);
                        if (parsed) {
                            return parsed;
                        }
                    } catch {
                        // noop
                    }
                }
            }
        }

        const stringCandidates = [
            error?.response?.responseText,
            error?.responseText,
            error?.xhr?.responseText,
            error?.request?.responseText,
            typeof error?.response === 'string' ? error.response : '',
            error?.message,
        ];

        for (const candidate of stringCandidates) {
            if (typeof candidate !== 'string' || candidate.trim() === '') {
                continue;
            }

            const directJson = this.parseJsonSafely(candidate);
            if (directJson) {
                return directJson;
            }

            const crawlerBodyJson = this.extractCrawlerBodyJson(candidate);
            if (crawlerBodyJson) {
                return crawlerBodyJson;
            }
        }

        return null;
    }

    parseJsonSafely(value) {
        try {
            const parsed = JSON.parse(value);
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : null;
        } catch {
            return null;
        }
    }

    extractCrawlerBodyJson(value) {
        if (typeof value !== 'string' || value.trim() === '') {
            return null;
        }

        const bodyMatch = value.match(/\|\s*body=(\{.*\})\s*$/s);
        if (!bodyMatch || !bodyMatch[1]) {
            return null;
        }

        return this.parseJsonSafely(bodyMatch[1]);
    }

    extractRemoteErrorCode(errorPayload) {
        return typeof (errorPayload?.code ?? errorPayload?.error?.code) === 'string'
            ? String(errorPayload?.code ?? errorPayload?.error?.code).trim()
            : '';
    }

    extractRemoteErrorStatus(errorPayload) {
        const status = Number(
            errorPayload?.status
            ?? errorPayload?.error?.status
            ?? 0
        );

        return Number.isFinite(status) ? status : 0;
    }

    extractRemoteErrorDetails(errorPayload) {
        const details = errorPayload?.details ?? errorPayload?.error?.details ?? null;

        return details && typeof details === 'object' && !Array.isArray(details)
            ? details
            : {};
    }

    extractReadableRemoteError(errorPayload, error, fallbackMessage) {
        const payloadFallback = this.buildFallbackMessageFromErrorPayload(errorPayload);

        const candidates = [
            typeof errorPayload?.message === 'string' ? errorPayload.message : '',
            typeof errorPayload?.error === 'string' ? errorPayload.error : '',
            typeof errorPayload?.title === 'string' ? errorPayload.title : '',
            typeof errorPayload?.error?.message === 'string' ? errorPayload.error.message : '',
            typeof errorPayload?.reasonLabel === 'string' ? errorPayload.reasonLabel : '',
            payloadFallback,
            error instanceof Error ? error.message : '',
        ];

        for (const candidate of candidates) {
            const message = this.normalizeRemoteErrorMessage(candidate);
            if (message !== '') {
                return message;
            }
        }

        return fallbackMessage;
    }

    buildFallbackMessageFromErrorPayload(errorPayload) {
        const code = this.extractRemoteErrorCode(errorPayload);
        const details = this.extractRemoteErrorDetails(errorPayload);

        if (code === 'trial_crawl_limit') {
            const maxJobsPerDay = Number(details.maxJobsPerDay || 0);
            const template = this.translate(
                'notification.proScan.trialLimitMessage',
                'Trial allows %d crawl jobs per 24 hours. Upgrade to PRO for unlimited scanning.'
            );

            if (maxJobsPerDay > 0) {
                return this.format(template, maxJobsPerDay);
            }

            return this.format(template, 5);
        }

        if (code === 'forbidden_resource') {
            return this.translate(
                'notification.proScan.ownershipError',
                'Frontend scan access was lost. Please start a new scan.'
            );
        }

        return '';
    }

    extractReadableRemoteErrorTitle(errorPayload) {
        const directTitle = typeof errorPayload?.title === 'string'
            ? errorPayload.title.trim()
            : '';

        if (directTitle !== '') {
            return directTitle;
        }

        const code = this.extractRemoteErrorCode(errorPayload);

        if (code === 'trial_crawl_limit') {
            return this.translate('notification.proScan.trialLimitTitle', 'Trial limit reached');
        }

        if (code === 'forbidden_resource') {
            return this.translate('notification.proScan.ownershipErrorTitle', 'Frontend scan access lost');
        }

        return '';
    }

    buildRemoteErrorNotificationText(errorPayload, message) {
        const resolvedMessage = (message || '').trim();
        const title = this.extractReadableRemoteErrorTitle(errorPayload).trim();

        if (title === '') {
            return resolvedMessage;
        }

        if (resolvedMessage === '') {
            return title;
        }

        if (resolvedMessage.toLowerCase() === title.toLowerCase()) {
            return title;
        }

        if (resolvedMessage.toLowerCase().startsWith(title.toLowerCase())) {
            return resolvedMessage;
        }

        return `${title}: ${resolvedMessage}`;
    }

    normalizeRemoteErrorMessage(value) {
        if (typeof value !== 'string') {
            return '';
        }

        const raw = value.trim();
        if (raw === '') {
            return '';
        }

        const trialLimitMatch = raw.match(/Trial allows \d+ crawl jobs per 24 hours\.[^|]*/i);
        if (trialLimitMatch) {
            return trialLimitMatch[0].trim();
        }

        const crawlerHttpMatch = raw.match(/AQG crawler HTTP \d+:\s*([^|]+)/i);
        if (crawlerHttpMatch && crawlerHttpMatch[1]) {
            return crawlerHttpMatch[1].trim();
        }

        const jsonMessageMatch = raw.match(/"message":"([^"]+)"/i);
        if (jsonMessageMatch && jsonMessageMatch[1]) {
            return jsonMessageMatch[1].trim();
        }

        const jsonErrorMatch = raw.match(/"error":"([^"]+)"/i);
        if (jsonErrorMatch && jsonErrorMatch[1]) {
            return jsonErrorMatch[1].trim();
        }

        if (raw.includes('|')) {
            return raw.split('|')[0].trim();
        }

        return raw;
    }

    async monitorRemoteScan(scanState) {
        const statusData = await this.pollRemoteCrawlerJob(scanState.jobId, scanState.siteIdentifier);

        if ((statusData.status || '') === 'cancelled') {
            this.updateRemoteScanUi({
                visible: true,
                status: 'cancelled',
                message: this.translate('notification.proScan.cancelled', 'Frontend scan was cancelled.'),
                pagesScanned: Number(statusData.pagesScanned || 0),
                pagesTotal: statusData.pagesTotal ?? null,
            });
            this.resetRemoteScanDomState();
            this.setScanInProgress(false);
            return;
        }

        if ((statusData.status || '') !== 'completed') {
            throw new Error(
                this.extractReadableRemoteError(
                    statusData,
                    null,
                    this.translate(
                        'notification.proScan.didNotComplete',
                        'Frontend scan did not complete successfully.'
                    )
                )
            );
        }

        const summaryData = await this.fetchRemoteSummary(scanState.jobId, scanState.siteIdentifier);

        if (summaryData.alreadyPersisted === true) {
            this.updateRemoteScanUi({
                visible: true,
                status: 'completed',
                message: this.translate(
                    'module.remotePageDetail.scanReloading',
                    'Scan completed. Reloading page with fresh results.'
                ),
                pagesScanned: Number(summaryData.pagesScanned || statusData.pagesScanned || 0),
                pagesTotal: statusData.pagesTotal ?? null,
            });

            try {
                localStorage.setItem(LS_SOURCE_KEY, 'remote');
            } catch {
                // noop
            }

            this.resetRemoteScanDomState();
            this.setScanInProgress(false);
            this.reloadCurrentModule(2600);
            return;
        }

        this.updateRemoteScanUi({
            visible: true,
            status: 'completed',
            message: this.translate(
                'module.remotePageDetail.scanReloading',
                'Scan completed. Reloading page with fresh results.'
            ),
            pagesScanned: Number(summaryData.pagesScanned || statusData.pagesScanned || 0),
            pagesTotal: statusData.pagesTotal ?? null,
        });

        this.showNotification(
            this.format(
                this.translate('notification.proScan.completed', 'Frontend scan complete — %d new, %d resolved.'),
                Number(summaryData.issuesNew || 0),
                Number(summaryData.issuesResolved || 0)
            ),
            'success'
        );

        try {
            localStorage.setItem(LS_SOURCE_KEY, 'remote');
        } catch {
            // noop
        }

        this.resetRemoteScanDomState();
        this.setScanInProgress(false);
        this.reloadCurrentModule(2600);
    }

    async pollRemoteCrawlerJob(jobId, siteIdentifier) {
        const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
        const statusEndpoint = ajaxUrls.a11y_pro_crawl_status || '';

        if (!statusEndpoint || !jobId || !siteIdentifier) {
            throw new Error(
                this.translate(
                    'notification.proScan.statusMissing',
                    'Missing frontend scan status endpoint, job ID or site identifier.'
                )
            );
        }

        if (this.remotePollInProgress) {
            return {
                status: 'active',
                pagesScanned: null,
                pagesTotal: null,
            };
        }

        this.remotePollInProgress = true;

        try {
            const maxAttempts = 300;
            const intervalMs = 6000;

            for (let attempt = 1; attempt <= maxAttempts; attempt++) {
                try {
                    const url = new URL(statusEndpoint, window.location.origin);
                    url.searchParams.set('jobId', String(jobId));
                    url.searchParams.set('siteIdentifier', String(siteIdentifier));

                    const response = await new AjaxRequest(url.toString()).get();
                    const data = await response.resolve();

                    const status = String(data.status || '');
                    const rawPagesScanned = data.pagesScanned ?? data.pages_scanned ?? null;
                    const rawPagesTotal = data.pagesTotal ?? data.pages_total ?? null;

                    const nextPagesScanned = rawPagesScanned === null || rawPagesScanned === undefined
                        ? null
                        : Number(rawPagesScanned);
                    const nextPagesTotal = rawPagesTotal === null || rawPagesTotal === undefined
                        ? null
                        : Number(rawPagesTotal);

                    const currentState = this.getRestorableRemoteScanState();

                    const pagesScanned = nextPagesScanned !== null && nextPagesScanned >= 0
                        ? nextPagesScanned
                        : (currentState?.pagesScanned ?? null);

                    const pagesTotal = nextPagesTotal !== null && nextPagesTotal > 0
                        ? nextPagesTotal
                        : (currentState?.pagesTotal ?? null);

                    this.updateRemoteScanDomState({
                        status,
                        pagesScanned: pagesScanned === null ? '' : String(pagesScanned),
                        pagesTotal: pagesTotal === null ? '' : String(pagesTotal),
                    });

                    if (status === 'waiting' || status === 'queued' || status === 'running' || status === 'active') {
                        this.updateRemoteScanUi({
                            visible: true,
                            status,
                            message: this.buildRemoteProgressMessage(pagesScanned, pagesTotal),
                            pagesScanned,
                            pagesTotal,
                        });
                    }

                    if (status === 'completed' || status === 'failed' || status === 'cancelled') {
                        return data;
                    }
                } catch (error) {
                    const errorPayload = await this.extractAjaxErrorData(error);
                    const code = this.extractRemoteErrorCode(errorPayload);
                    const status = this.extractRemoteErrorStatus(errorPayload);
                    const message = this.extractReadableRemoteError(
                        errorPayload,
                        error,
                        this.translate(
                            'notification.proScan.statusFailed',
                            'Frontend scan status request failed.'
                        )
                    );

                    if (message.includes('429') || status === 429) {
                        await new Promise((resolve) => window.setTimeout(resolve, 10000));
                        continue;
                    }

                    if (code === 'forbidden_resource') {
                        throw new Error(
                            this.translate(
                                'notification.proScan.ownershipError',
                                'Frontend scan access was lost. Please start a new scan.'
                            )
                        );
                    }

                    throw new Error(message);
                }

                await new Promise((resolve) => window.setTimeout(resolve, intervalMs));
            }
        } finally {
            this.remotePollInProgress = false;
        }

        throw new Error(
            this.translate(
                'notification.proScan.statusTimeout',
                'Frontend scan status polling timed out.'
            )
        );
    }

    async fetchRemoteSummary(jobId, siteIdentifier) {
        const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
        const summaryEndpoint = ajaxUrls.a11y_pro_crawl_summary || '';

        if (!summaryEndpoint || !jobId || !siteIdentifier) {
            throw new Error(
                this.translate(
                    'notification.proScan.summaryMissing',
                    'Missing frontend scan summary endpoint, job ID or site identifier.'
                )
            );
        }

        const summaryUrl = new URL(summaryEndpoint, window.location.origin);
        summaryUrl.searchParams.set('jobId', String(jobId));
        summaryUrl.searchParams.set('siteIdentifier', String(siteIdentifier));

        for (let attempt = 1; attempt <= 3; attempt++) {
            try {
                const summaryResponse = await new AjaxRequest(summaryUrl.toString()).get();
                return await summaryResponse.resolve();
            } catch (error) {
                const errorPayload = await this.extractAjaxErrorData(error);
                const code = this.extractRemoteErrorCode(errorPayload);
                const status = this.extractRemoteErrorStatus(errorPayload);
                const message = this.extractReadableRemoteError(
                    errorPayload,
                    error,
                    this.translate(
                        'notification.proScan.summaryFailed',
                        'Frontend scan summary request failed.'
                    )
                );

                if (message.includes('429') || status === 429) {
                    if (attempt === 3) {
                        throw new Error(message);
                    }

                    await new Promise((resolve) => window.setTimeout(resolve, 5000));
                    continue;
                }

                if (code === 'forbidden_resource') {
                    throw new Error(
                        this.translate(
                            'notification.proScan.ownershipError',
                            'Frontend scan access was lost. Please start a new scan.'
                        )
                    );
                }

                throw new Error(message);
            }
        }

        throw new Error(
            this.translate(
                'notification.proScan.summaryFailed',
                'Frontend scan summary request failed.'
            )
        );
    }

    initRemoteScanProgress() {
        const state = this.getRestorableRemoteScanState();

        if (state) {
            this.updateRemoteScanUi({
                visible: true,
                status: state.status,
                message: this.buildRemoteProgressMessage(state.pagesScanned, state.pagesTotal),
                pagesScanned: state.pagesScanned,
                pagesTotal: state.pagesTotal,
            });
            return;
        }

        this.updateRemoteScanUi({
            visible: false,
            status: '',
            message: '',
            pagesScanned: null,
            pagesTotal: null,
        });
    }

    restoreRemoteScanStateFromDom() {
        const state = this.getRestorableRemoteScanState();
        if (!state) {
            return;
        }

        this.setScanInProgress(true);

        this.updateRemoteScanUi({
            visible: true,
            status: state.status,
            message: this.buildRemoteProgressMessage(state.pagesScanned, state.pagesTotal),
            pagesScanned: state.pagesScanned,
            pagesTotal: state.pagesTotal,
        });

        this.monitorRemoteScan(state).catch((error) => {
            const message = error instanceof Error
                ? error.message
                : this.translate('notification.proScan.restoreFailed', 'Frontend scan restore failed.');

            this.resetRemoteScanDomState();
            this.updateRemoteScanUi({
                visible: true,
                status: 'failed',
                message,
                pagesScanned: null,
                pagesTotal: null,
            });
            this.showNotification(message, 'error');
            this.setScanInProgress(false);
        });
    }

    setScanInProgress(isActive) {
        this.scanInProgress = isActive;

        document.querySelectorAll(PRO_SELECTORS.proScanPageButton).forEach((button) => {
            button.disabled = isActive;
            if (!isActive) {
                this.restoreButtonText(button);
            }
        });

        document.querySelectorAll(PRO_SELECTORS.proScanSiteButton).forEach((button) => {
            button.disabled = isActive;
            if (!isActive) {
                this.restoreButtonText(button);
            }
        });

        if (isActive) {
            if (this.statusPollTimer) {
                window.clearInterval(this.statusPollTimer);
                this.statusPollTimer = null;
            }
        } else {
            this.startStatusPolling();
        }
    }


    restoreButtonText(button) {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        if (button.dataset.originalText) {
            button.textContent = button.dataset.originalText;
        }
    }

    updateRemoteScanUi({ visible, status, message, pagesScanned = null, pagesTotal = null }) {
        const box = document.querySelector(PRO_SELECTORS.remoteScanProgressBox);
        const statusEl = document.querySelector(PRO_SELECTORS.remoteScanProgressStatus);
        const messageEl = document.querySelector(PRO_SELECTORS.remoteScanProgressMessage);
        const backgroundHintEl = document.querySelector(PRO_SELECTORS.remoteScanProgressBackgroundHint);
        const spinnerEl = document.querySelector(PRO_SELECTORS.remoteScanProgressSpinner);
        const progressBarEl = document.querySelector(PRO_SELECTORS.remoteScanProgressBar);
        const fillEl = document.querySelector(PRO_SELECTORS.remoteScanProgressFill);
        const cancelButton = document.querySelector(PRO_SELECTORS.proCancelScanButton);

        if (!box) {
            return;
        }

        const normalizedStatus = String(status || '');
        const isCompleted = normalizedStatus === 'completed';
        const isIndeterminate = visible && !['completed', 'failed', 'cancelled'].includes(normalizedStatus);

        box.classList.toggle('d-none', !visible);
        box.classList.toggle('aqg-progress-block--indeterminate', isIndeterminate);
        box.classList.toggle('aqg-progress-block--completed', isCompleted);

        if (spinnerEl) {
            const showSpinner = visible && !['completed', 'failed', 'cancelled'].includes(normalizedStatus);
            spinnerEl.classList.toggle('d-none', !showSpinner);
        }

        if (statusEl) {
            statusEl.textContent = status || '';
            statusEl.className = 'aqg-status-badge';

            if (status === 'completed') {
                statusEl.className = 'aqg-status-badge aqg-status-badge--ok';
            } else if (status === 'failed') {
                statusEl.className = 'aqg-status-badge aqg-status-badge--error';
            } else if (status === 'cancelled') {
                statusEl.className = 'aqg-status-badge aqg-status-badge--neutral';
            } else if (status === 'waiting' || status === 'queued' || status === 'running' || status === 'active' || status === 'cancelling') {
                statusEl.className = 'aqg-status-badge aqg-status-badge--running';
            }
        }

        if (fillEl) {
            const percentage = pagesTotal !== null && pagesTotal > 0
                ? Math.min(100, Math.max(0, (Number(pagesScanned || 0) / Number(pagesTotal)) * 100))
                : visible ? 18 : 0;

            fillEl.style.width = isCompleted ? '100%' : (isIndeterminate ? '35%' : `${percentage}%`);
        }

        if (progressBarEl) {
            progressBarEl.setAttribute('aria-valuenow', pagesScanned === null ? '0' : String(pagesScanned));
        }
        if (pagesTotal !== null && pagesTotal > 0) {
            progressBarEl?.setAttribute('aria-valuemax', String(pagesTotal));
        }

        if (messageEl) {
            const resolvedMessage = message || this.buildRemoteProgressMessage(pagesScanned, pagesTotal);
            messageEl.textContent = resolvedMessage;
        }

        if (backgroundHintEl) {
            const shouldShowBackgroundHint = visible && !['completed', 'failed', 'cancelled'].includes(normalizedStatus);
            backgroundHintEl.classList.toggle('d-none', !shouldShowBackgroundHint);
        }

        if (cancelButton) {
            const canCancel = visible && ['waiting', 'queued', 'active', 'running', 'cancelling'].includes(String(status || ''));
            cancelButton.classList.toggle('d-none', !canCancel);
            cancelButton.disabled = String(status || '') === 'cancelling';
        }
    }

    buildRemoteProgressMessage(pagesScanned = null, pagesTotal = null) {
        if (pagesScanned !== null && pagesTotal !== null && pagesTotal > 0) {
            return this.format(
                this.translate(
                    'notification.proScan.progress.withTotal',
                    'Frontend scan is running in background. %d/%d pages processed so far.'
                ),
                pagesScanned,
                pagesTotal
            );
        }

        if (pagesScanned !== null && pagesScanned > 0) {
            return this.format(
                this.translate(
                    'notification.proScan.progress.withCount',
                    'Frontend scan is running in background. %d pages processed so far.'
                ),
                pagesScanned
            );
        }

        return this.translate(
            'notification.proScan.progress.starting',
            'Frontend scan is running in background. Progress will appear shortly.'
        );
    }

    getCurrentRemoteContext() {
        const overviewRoot = document.querySelector(PRO_SELECTORS.overviewRoot);
        if (overviewRoot) {
            const overviewPageUid = Number.parseInt(overviewRoot.dataset.a11yCurrentPageUid || '0', 10) || null;

            return {
                type: 'overview',
                siteIdentifier: String(overviewRoot.dataset.a11ySiteIdentifier || '').trim(),
                remotePageUid: overviewPageUid,
                languageUid: this.resolveCurrentLanguageUid(),
            };
        }

        const remotePageRoot = document.querySelector(PRO_SELECTORS.remotePageRoot);
        if (remotePageRoot) {
            return {
                type: 'remotePage',
                siteIdentifier: String(remotePageRoot.dataset.a11ySiteIdentifier || '').trim(),
                remotePageUid: Number.parseInt(remotePageRoot.dataset.a11yRemotePageUid || '0', 10) || null,
                languageUid: this.resolveCurrentLanguageUid(),
            };
        }

        return {
            type: '',
            siteIdentifier: '',
            remotePageUid: null,
            languageUid: this.resolveCurrentLanguageUid(),
        };
    }

    resolveScanLanguageUid(button = null) {
        const buttonLanguage = this.parseLanguageUid(button?.dataset?.languageUid);
        if (buttonLanguage !== null) {
            return buttonLanguage;
        }

        const contextLanguage = this.resolveCurrentLanguageUid();
        if (contextLanguage !== null) {
            return contextLanguage;
        }

        const urlLanguage = this.resolveLanguageUidFromUrl();
        return urlLanguage === null ? 0 : urlLanguage;
    }

    resolveSubmittedLanguageUid(submitData, fallbackLanguageUid = null, button = null) {
        for (const key of ['languageUid', 'languageId']) {
            if (submitData[key] !== undefined && submitData[key] !== null && submitData[key] !== '') {
                return String(submitData[key]);
            }
        }

        if (fallbackLanguageUid !== null && fallbackLanguageUid !== undefined && !Number.isNaN(Number(fallbackLanguageUid))) {
            return String(fallbackLanguageUid);
        }

        return String(this.resolveScanLanguageUid(button));
    }

    resolveCurrentLanguageUid() {
        const root = document.querySelector(PRO_SELECTORS.overviewRoot) || document.querySelector(PRO_SELECTORS.remotePageRoot);
        const datasetLanguage = this.parseLanguageUid(root?.dataset?.a11yLanguageUid);
        if (datasetLanguage !== null) {
            return datasetLanguage;
        }

        return this.resolveLanguageUidFromUrl();
    }

    resolveLanguageUidFromUrl() {
        const parameters = new URLSearchParams(window.location.search);
        for (const key of ['language', 'languageUid', 'L', 'sys_language_uid']) {
            const value = this.parseLanguageUid(parameters.get(key));
            if (value !== null) {
                return value;
            }
        }

        return null;
    }

    parseLanguageUid(value) {
        const raw = String(value ?? '').trim();
        if (!/^\d+$/.test(raw)) {
            return null;
        }

        return Number.parseInt(raw, 10);
    }

    getRestorableRemoteScanState() {
        const box = document.querySelector(PRO_SELECTORS.remoteScanProgressBox);
        if (!box) {
            return null;
        }

        const jobId = String(box.dataset.a11yRemoteJobId || '').trim();
        const siteIdentifier = String(box.dataset.a11yRemoteSiteIdentifier || '').trim();
        const scope = String(box.dataset.a11yRemoteScope || '').trim();
        const status = String(box.dataset.a11yRemoteStatus || '').trim();
        const pageUid = Number.parseInt(String(box.dataset.a11yRemotePageUid || '0'), 10) || null;
        const pagesScanned = this.normalizeNullableNumber(box.dataset.a11yRemotePagesScanned);
        const pagesTotal = this.normalizeNullableNumber(box.dataset.a11yRemotePagesTotal);
        const languageUid = Number.parseInt(String(box.dataset.a11yRemoteLanguageUid || '-1'), 10);

        if (!jobId || !siteIdentifier || !['waiting', 'queued', 'active', 'running'].includes(status)) {
            return null;
        }

        const context = this.getCurrentRemoteContext();
        if (context.siteIdentifier !== siteIdentifier) {
            return null;
        }

        if (context.languageUid !== null && languageUid >= 0 && context.languageUid !== languageUid) {
            return null;
        }

        if (scope === 'page') {
            if (context.remotePageUid === null || context.remotePageUid !== pageUid) {
                return null;
            }
        }

        return {
            jobId,
            siteIdentifier,
            scope: scope || 'site',
            pageUid,
            status,
            pagesScanned,
            pagesTotal,
            languageUid,
        };
    }

    updateRemoteScanDomState(partialState) {
        const box = document.querySelector(PRO_SELECTORS.remoteScanProgressBox);
        if (!box) {
            return;
        }

        const datasetMap = {
            jobId: 'a11yRemoteJobId',
            siteIdentifier: 'a11yRemoteSiteIdentifier',
            scope: 'a11yRemoteScope',
            pageUid: 'a11yRemotePageUid',
            status: 'a11yRemoteStatus',
            pagesScanned: 'a11yRemotePagesScanned',
            pagesTotal: 'a11yRemotePagesTotal',
            languageUid: 'a11yRemoteLanguageUid',
        };

        Object.entries(partialState).forEach(([key, value]) => {
            const datasetKey = datasetMap[key];
            if (!datasetKey) {
                return;
            }

            box.dataset[datasetKey] = value === null || value === undefined ? '' : String(value);
        });

        const cancelButton = document.querySelector(PRO_SELECTORS.proCancelScanButton);
        if (cancelButton instanceof HTMLButtonElement) {
            if (partialState.jobId !== undefined) {
                cancelButton.dataset.jobId = partialState.jobId === null || partialState.jobId === undefined ? '' : String(partialState.jobId);
            }
            if (partialState.siteIdentifier !== undefined) {
                cancelButton.dataset.siteIdentifier = partialState.siteIdentifier === null || partialState.siteIdentifier === undefined ? '' : String(partialState.siteIdentifier);
            }
        }
    }

    resetRemoteScanDomState() {
        const box = document.querySelector(PRO_SELECTORS.remoteScanProgressBox);
        if (!box) {
            return;
        }

        box.dataset.a11yRemoteJobId = '';
        box.dataset.a11yRemoteSiteIdentifier = '';
        box.dataset.a11yRemoteScope = '';
        box.dataset.a11yRemotePageUid = '';
        box.dataset.a11yRemoteStatus = '';
        box.dataset.a11yRemotePagesScanned = '';
        box.dataset.a11yRemotePagesTotal = '';
        box.dataset.a11yRemoteLanguageUid = '';
    }
}
