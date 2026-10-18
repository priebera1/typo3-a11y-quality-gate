// @vitest-environment jsdom

import {afterEach, describe, expect, it, vi} from 'vitest';
import {A11yBaseModule} from '../../Resources/Public/JavaScript/backend/core/base-module.js';
import {A11yFreeBackendModule} from '../../Resources/Public/JavaScript/backend/free/free-module.js';
import {
    resetAjaxPostHandler,
    setAjaxPostHandler,
} from './stubs/ajax-request.js';

const createButton = () => {
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.action = 'a11y-rescan';
    button.dataset.pageUid = '42';
    button.dataset.languageUid = '3';
    button.dataset.loadingText = 'Scanning...';
    button.textContent = 'Scan this page';
    document.body.append(button);
    return button;
};

const createModule = () => ({
    translations: {},
    localScanCancellationRequested: false,
    setLoadingState(button, loading) {
        A11yBaseModule.prototype.setLoadingState.call(this, button, loading);
    },
    updateLocalScanProgress: vi.fn(),
    yieldForPaint: vi.fn().mockResolvedValue(undefined),
    isLocalScanCancelledResponse: vi.fn().mockReturnValue(false),
    showNotification: vi.fn(),
    translate(_key, fallback) {
        return fallback;
    },
    format(template, ...values) {
        return A11yBaseModule.prototype.format.call(this, template, ...values);
    },
    showScanResult(notification, options) {
        return A11yBaseModule.prototype.showScanResult.call(this, notification, options);
    },
    scanLabel() {
        return A11yBaseModule.prototype.scanLabel.call(this);
    },
    reloadCurrentModule: vi.fn(),
    announce: vi.fn(),
    setLastCompletedLabel: vi.fn(),
    setLocalScanControlsBusy: vi.fn(),
    setLocalScanTitle: vi.fn(),
    localScanRunningMessage() {
        return A11yFreeBackendModule.prototype.localScanRunningMessage.call(this);
    },
    describeLocalScanTarget(button) {
        return A11yFreeBackendModule.prototype.describeLocalScanTarget.call(this, button);
    },
    describeLocalScanStart(button) {
        return A11yFreeBackendModule.prototype.describeLocalScanStart.call(this, button);
    },
    showLocalScanFailure(error) {
        return A11yFreeBackendModule.prototype.showLocalScanFailure.call(this, error);
    },
    readableLocalScanError(error) {
        return A11yFreeBackendModule.prototype.readableLocalScanError.call(this, error);
    },
    isSessionExpiredError(error) {
        return A11yFreeBackendModule.prototype.isSessionExpiredError.call(this, error);
    },
    sessionExpiredMessage() {
        return A11yFreeBackendModule.prototype.sessionExpiredMessage.call(this);
    },
});

afterEach(() => {
    resetAjaxPostHandler();
    document.body.innerHTML = '';
    delete globalThis.TYPO3;
});

describe('local page scan request', () => {
    it('sends the production endpoint payload and exposes loading and success states', async () => {
        globalThis.TYPO3 = {
            settings: {
                ajaxUrls: {
                    a11y_scan_page: '/typo3/ajax/a11y/scan/page',
                },
            },
        };
        const button = createButton();
        const module = createModule();
        let resolveResponse;
        const responsePromise = new Promise((resolve) => {
            resolveResponse = resolve;
        });
        const post = vi.fn().mockReturnValue({
            resolve: vi.fn().mockReturnValue(responsePromise),
        });
        setAjaxPostHandler(post);

        const request = A11yFreeBackendModule.prototype.handleRescan.call(module, button);

        await vi.waitFor(() => {
            expect(post).toHaveBeenCalledWith('/typo3/ajax/a11y/scan/page', {
                pageUid: 42,
                languageUid: 3,
            });
        });
        // The button keeps its label; the running status block says what runs.
        expect(button.disabled).toBe(true);
        expect(button.getAttribute('aria-busy')).toBe('true');
        expect(button.textContent).toBe('Scan this page');
        expect(module.updateLocalScanProgress).toHaveBeenCalledWith(
            true,
            'This usually takes a moment.',
            expect.any(Date),
        );
        expect(module.setLocalScanTitle).toHaveBeenCalledWith('Content scan is running');
        expect(module.setLastCompletedLabel).toHaveBeenCalledWith('local', true);

        expect(module.setLocalScanControlsBusy).toHaveBeenCalledWith(true);
        expect(module.announce).toHaveBeenCalledWith('Content scan started.');

        resolveResponse({issuesNew: 2, issuesResolved: 1, warnings: [], openIssues: {critical: 3, warning: 2, info: 0, needs_review: 0, total: 5}});
        await request;

        // One notification: the title says what ended, the message what it found (fallback banner without TYPO3).
        expect(module.showNotification).toHaveBeenCalledWith(
            'Content scan completed: 5 open issues (2 new, 3 already known); 1 resolved.',
            'info',
        );
        expect(module.announce).toHaveBeenLastCalledWith('Content scan completed. 5 open issues (2 new, 3 already known); 1 resolved.');
        expect(module.updateLocalScanProgress).toHaveBeenLastCalledWith(
            true,
            'Scan completed. Reloading results...',
            null,
            'completed',
        );
        expect(module.reloadCurrentModule).toHaveBeenCalledWith(2600);
    });

    it('uses the module language when a scan button carries none, never language 0', async () => {
        // Regression: Overview table rows and the Content page empty states had no data-language-uid, so a scan
        // started in a German view ran in the default language (2026-10, TYPO3 13 and 14).
        globalThis.TYPO3 = {settings: {ajaxUrls: {a11y_scan_page: '/typo3/ajax/a11y/scan/page'}}};
        const root = document.createElement('div');
        root.dataset.a11yLanguageUid = '1';
        document.body.append(root);
        const button = createButton();
        delete button.dataset.languageUid;
        root.append(button);
        const post = vi.fn().mockReturnValue({resolve: vi.fn().mockResolvedValue({issuesNew: 0, issuesResolved: 0})});
        setAjaxPostHandler(post);

        await A11yFreeBackendModule.prototype.handleRescan.call(createModule(), button);

        expect(post).toHaveBeenCalledWith('/typo3/ajax/a11y/scan/page', {pageUid: 42, languageUid: 1});
    });

    it('turns the status block into the failed state and keeps the toast short', async () => {
        globalThis.TYPO3 = {
            settings: {
                ajaxUrls: {
                    a11y_scan_page: '/typo3/ajax/a11y/scan/page',
                },
            },
        };
        const button = createButton();
        const module = createModule();
        const post = vi.fn().mockReturnValue({
            resolve: vi.fn().mockRejectedValue(new Error('Backend failed')),
        });
        setAjaxPostHandler(post);

        await A11yFreeBackendModule.prototype.handleRescan.call(module, button);

        // The reason lives in the failed status block; the toast does not repeat it.
        expect(module.updateLocalScanProgress).toHaveBeenLastCalledWith(true, 'Backend failed', null, 'failed');
        expect(module.showNotification).toHaveBeenCalledWith(
            'Content scan failed: The reason is shown in the scan status.',
            'error',
        );
        expect(module.showNotification).not.toHaveBeenCalledWith(expect.stringContaining('Backend failed'), 'error');
        expect(module.setLastCompletedLabel).toHaveBeenLastCalledWith('local', true);
        expect(module.announce).toHaveBeenLastCalledWith('Content scan failed: Backend failed');
        expect(module.setLocalScanControlsBusy).toHaveBeenLastCalledWith(false);
        expect(button.disabled).toBe(false);
    });

    it('shows the reason an HTTP error answer of the scan endpoint gives', async () => {
        globalThis.TYPO3 = {settings: {ajaxUrls: {a11y_scan_page: '/typo3/ajax/a11y/scan/page'}}};
        const button = createButton();
        const module = createModule();
        const answer = {error: 'The content scan failed. Details are in the TYPO3 log.'};
        const post = vi.fn().mockReturnValue({
            resolve: vi.fn().mockRejectedValue({response: {clone: () => ({json: async () => answer})}}),
        });
        setAjaxPostHandler(post);

        await A11yFreeBackendModule.prototype.handleRescan.call(module, button);

        expect(module.updateLocalScanProgress).toHaveBeenLastCalledWith(
            true,
            'The content scan failed. Details are in the TYPO3 log.',
            null,
            'failed',
        );
        expect(button.textContent).toBe('Scan this page');
        expect(module.reloadCurrentModule).not.toHaveBeenCalled();
    });
});
