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
                'Remote scan request is still being started. Please wait a moment before leaving this page.'
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

        const stored = localStorage.getItem(LS_SOURCE_KEY) || 'local';
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
        const maxPages = Number.parseInt(button.dataset.maxPages || '20', 10);

        if (!submitEndpoint || rootPid <= 0) {
            this.showNotification(
                this.translate('notification.proScan.missingRootPid', 'Remote crawler scan failed: missing endpoint or root PID.'),
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
                maxPages,
                followLinks: true,
                axeLocale: 'en',
            });

            const submitData = await submitResponse.resolve();
            await this.handleProSubmitPayload(button, submitData, {
                fallbackScope: 'site',
                fallbackPageUid: null,
            });
        } catch (error) {
            const errorPayload = await this.extractAjaxErrorData(error);

            if (errorPayload && errorPayload.code === 'remote_scan_already_active') {
                await this.handleProSubmitPayload(button, errorPayload, {
                    fallbackScope: 'site',
                    fallbackPageUid: null,
                });
                return;
            }

            const message = this.extractReadableRemoteError(
                errorPayload,
                error,
                this.translate('notification.proScan.failed', 'Remote crawler scan failed.')
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
                axeLocale: 'en',
            });

            const submitData = await submitResponse.resolve();
            await this.handleProSubmitPayload(button, submitData, {
                fallbackScope: 'page',
                fallbackPageUid: pageUid,
            });
        } catch (error) {
            const errorPayload = await this.extractAjaxErrorData(error);

            if (errorPayload && errorPayload.code === 'remote_scan_already_active') {
                await this.handleProSubmitPayload(button, errorPayload, {
                    fallbackScope: 'page',
                    fallbackPageUid: pageUid,
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

    async handleProSubmitPayload(button, submitData, { fallbackScope, fallbackPageUid }) {
        this.remoteSubmitInProgress = false;
        if (submitData.success === false && submitData.code === 'remote_scan_already_active') {
            const restoredJobId = String(submitData.jobId || '').trim();
            const restoredSiteIdentifier = String(submitData.siteIdentifier || '').trim();

            if (!restoredJobId || !restoredSiteIdentifier) {
                throw new Error(submitData.error || 'Remote scan is already active, but restore data is missing.');
            }

            this.updateRemoteScanDomState({
                jobId: restoredJobId,
                siteIdentifier: restoredSiteIdentifier,
                scope: String(submitData.scanScope || fallbackScope),
                pageUid: String(submitData.pageUid || fallbackPageUid || ''),
                status: String(submitData.status || 'queued'),
                pagesScanned: String(submitData.pagesScanned || 0),
                pagesTotal: submitData.pagesTotal ? String(submitData.pagesTotal) : '',
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
                this.translate('notification.proScan.alreadyRunning', 'A remote scan is already running. Restoring progress.'),
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
                ? this.translate('notification.proScan.pageStarted', 'Remote page scan started.')
                : this.translate('notification.proScan.started', 'Remote crawler scan started.'),
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
                'Remote scan access was lost. Please start a new scan.'
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
            return this.translate('notification.proScan.ownershipErrorTitle', 'Remote scan access lost');
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

        if ((statusData.status || '') !== 'completed') {
            throw new Error(
                this.extractReadableRemoteError(
                    statusData,
                    null,
                    this.translate(
                        'notification.proScan.didNotComplete',
                        'Remote crawler scan did not complete successfully.'
                    )
                )
            );
        }

        const summaryData = await this.fetchRemoteSummary(scanState.jobId, scanState.siteIdentifier);

        if (summaryData.alreadyPersisted === true) {
            this.updateRemoteScanUi({
                visible: false,
                status: '',
                message: '',
                pagesScanned: null,
                pagesTotal: null,
            });

            try {
                localStorage.setItem(LS_SOURCE_KEY, 'remote');
            } catch {
                // noop
            }

            this.resetRemoteScanDomState();
            this.setScanInProgress(false);
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
                this.translate('notification.proScan.completed', 'Remote crawler complete — %d new, %d resolved.'),
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
        window.setTimeout(() => window.location.reload(), 1200);
    }

    async pollRemoteCrawlerJob(jobId, siteIdentifier) {
        const ajaxUrls = TYPO3?.settings?.ajaxUrls ?? {};
        const statusEndpoint = ajaxUrls.a11y_pro_crawl_status || '';

        if (!statusEndpoint || !jobId || !siteIdentifier) {
            throw new Error(
                this.translate(
                    'notification.proScan.statusMissing',
                    'Missing remote crawler status endpoint, job ID or site identifier.'
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
            const intervalMs = 3000;

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

                    if (status === 'completed' || status === 'failed') {
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
                            'Remote crawler status request failed.'
                        )
                    );

                    if (message.includes('429') || status === 429) {
                        await new Promise((resolve) => window.setTimeout(resolve, 5000));
                        continue;
                    }

                    if (code === 'forbidden_resource') {
                        throw new Error(
                            this.translate(
                                'notification.proScan.ownershipError',
                                'Remote scan access was lost. Please start a new scan.'
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
                'Remote crawler status polling timed out.'
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
                    'Missing remote crawler summary endpoint, job ID or site identifier.'
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
                        'Remote crawler summary request failed.'
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
                            'Remote scan access was lost. Please start a new scan.'
                        )
                    );
                }

                throw new Error(message);
            }
        }

        throw new Error(
            this.translate(
                'notification.proScan.summaryFailed',
                'Remote crawler summary request failed.'
            )
        );
    }

    initRemoteScanProgress() {
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
                : this.translate('notification.proScan.restoreFailed', 'Remote scan restore failed.');

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
        });

        document.querySelectorAll(PRO_SELECTORS.proScanSiteButton).forEach((button) => {
            button.disabled = isActive;
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

    updateRemoteScanUi({ visible, status, message, pagesScanned = null, pagesTotal = null }) {
        const box = document.querySelector(PRO_SELECTORS.remoteScanProgressBox);
        const statusEl = document.querySelector(PRO_SELECTORS.remoteScanProgressStatus);
        const messageEl = document.querySelector(PRO_SELECTORS.remoteScanProgressMessage);
        const spinnerEl = document.querySelector(PRO_SELECTORS.remoteScanProgressSpinner);

        if (!box) {
            return;
        }

        box.classList.toggle('d-none', !visible);

        if (spinnerEl) {
            const showSpinner = visible && !['completed', 'failed'].includes(String(status || ''));
            spinnerEl.classList.toggle('d-none', !showSpinner);
        }

        if (statusEl) {
            statusEl.textContent = status || '';
            statusEl.className = 'ms-2 badge bg-secondary';

            if (status === 'completed') {
                statusEl.className = 'ms-2 badge bg-success';
            } else if (status === 'failed') {
                statusEl.className = 'ms-2 badge bg-danger';
            } else if (status === 'waiting' || status === 'queued' || status === 'running' || status === 'active') {
                statusEl.className = 'ms-2 badge bg-warning text-dark';
            }
        }

        if (messageEl) {
            const resolvedMessage = message || this.buildRemoteProgressMessage(pagesScanned, pagesTotal);
            messageEl.textContent = resolvedMessage;
        }
    }

    buildRemoteProgressMessage(pagesScanned = null, pagesTotal = null) {
        if (pagesScanned !== null && pagesTotal !== null && pagesTotal > 0) {
            return this.format(
                this.translate(
                    'notification.proScan.progress.withTotal',
                    'Remote scan is running in background. %d/%d pages processed so far.'
                ),
                pagesScanned,
                pagesTotal
            );
        }

        if (pagesScanned !== null && pagesScanned > 0) {
            return this.format(
                this.translate(
                    'notification.proScan.progress.withCount',
                    'Remote scan is running in background. %d pages processed so far.'
                ),
                pagesScanned
            );
        }

        return this.translate(
            'notification.proScan.progress.starting',
            'Remote scan is running in background. Progress will appear shortly.'
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
            };
        }

        const remotePageRoot = document.querySelector(PRO_SELECTORS.remotePageRoot);
        if (remotePageRoot) {
            return {
                type: 'remotePage',
                siteIdentifier: String(remotePageRoot.dataset.a11ySiteIdentifier || '').trim(),
                remotePageUid: Number.parseInt(remotePageRoot.dataset.a11yRemotePageUid || '0', 10) || null,
            };
        }

        return {
            type: '',
            siteIdentifier: '',
            remotePageUid: null,
        };
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

        if (!jobId || !siteIdentifier || !['waiting', 'queued', 'active', 'running'].includes(status)) {
            return null;
        }

        const context = this.getCurrentRemoteContext();
        if (context.siteIdentifier !== siteIdentifier) {
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
        };

        Object.entries(partialState).forEach(([key, value]) => {
            const datasetKey = datasetMap[key];
            if (!datasetKey) {
                return;
            }

            box.dataset[datasetKey] = value === null || value === undefined ? '' : String(value);
        });
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
    }
}